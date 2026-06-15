<?php
 /**
 * Plugin Name:       Editorial QA (AI Typos)
 * Description:       Scans scheduled posts for obvious typos via OpenAI or Anthropic; shows reports in an admin panel + post meta box + Posts list column.
 * Version:           1.7.2
 * Requires PHP:      8.0
 * Requires at least: 6.0
 * Author:            Orchard Grove Media, LLC
 * Plugin URI:        https://orchardgrove.com/
 * Author URI:        https://orchardgrove.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       editorial-qa
 */

if (!defined('ABSPATH')) exit;

define('OGM_EQA_VERSION', '1.7.2');

class OGM_Editorial_QA {
    const OPT_KEY    = 'ogm_eqa_settings';
    // Per-provider model cache:
    // ['openai' => ['ids'=>string[],'updated'=>int], 'anthropic' => ['ids'=>string[],'updated'=>int]]
    const OPT_MODELS = 'ogm_eqa_models';

    const PROVIDERS = ['openai', 'anthropic'];

    // Output-token bounds, aligned across the settings field and the API calls.
    const MIN_TOKENS = 300;
    const MAX_TOKENS = 2000;

    // Provider HTTP statuses worth retrying with backoff.
    const RETRY_STATUSES = [429, 500, 502, 503, 529];

    // Shown in each provider's model dropdown until "Refresh models" is clicked.
    // OpenAI: Responses API + strict structured outputs.
    const SEED_MODELS_OPENAI = ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1', 'o4-mini'];
    // Anthropic: Messages API + structured outputs (output_config.format).
    const SEED_MODELS_ANTHROPIC = ['claude-haiku-4-5', 'claude-sonnet-4-6', 'claude-opus-4-8'];

    const META_REPORT          = '_ogm_eqa_report';
    const META_ISSUE_COUNT     = '_ogm_eqa_issue_count';
    const META_LAST_SCANNED_GM = '_ogm_eqa_last_scanned_gmt';
    const META_HASH            = '_ogm_eqa_scanned_hash';
    const META_MODEL           = '_ogm_eqa_model';
    const META_ERROR           = '_ogm_eqa_error';

    const CRON_HOOK = 'ogm_eqa_scan_post';

    public static function init() {
        add_action('transition_post_status', [__CLASS__, 'on_transition_post_status'], 10, 3);
        add_action(self::CRON_HOOK, [__CLASS__, 'scan_post_job'], 10, 3);

        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);

        add_action('add_meta_boxes', [__CLASS__, 'register_meta_box']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);

        add_action('wp_ajax_ogm_eqa_rescan', [__CLASS__, 'ajax_rescan']);

        // "Refresh models" button on the Settings page (AJAX — updates in place).
        add_action('wp_ajax_ogm_eqa_refresh_models', [__CLASS__, 'ajax_refresh_models']);

        // Add QA column in normal Posts list table
        add_action('admin_init', [__CLASS__, 'register_list_table_column']);
    }

    public static function defaults() {
        return [
            'provider'          => 'openai',
            'openai_api_key'    => '',
            'openai_model'      => 'gpt-4o-mini',
            'anthropic_api_key' => '',
            'anthropic_model'   => 'claude-haiku-4-5',
            'max_output_tokens' => 350,
            'post_types'        => ['post'],
            'strip_html'        => 1,
            // Post statuses whose entry triggers a scan. Default: Scheduled.
            'trigger_statuses'  => ['future'],
            // Also re-scan when a post is saved again while already in a
            // trigger status (content-hash dedup avoids redundant API calls).
            'rescan_on_update'  => 1,
        ];
    }

    public static function settings() {
        $opts = get_option(self::OPT_KEY, []);

        // Migrate single-provider keys from <= 1.3.0 (api_key/model were OpenAI).
        if (isset($opts['api_key']) && !isset($opts['openai_api_key'])) {
            $opts['openai_api_key'] = $opts['api_key'];
        }
        if (isset($opts['model']) && !isset($opts['openai_model'])) {
            $opts['openai_model'] = $opts['model'];
        }

        // Migrate <= 1.5.0 boolean triggers to the trigger_statuses list.
        if (!isset($opts['trigger_statuses'])) {
            $ts = [];
            // scan_on_schedule defaulted on, so treat "unset" as on.
            if (!isset($opts['scan_on_schedule']) || !empty($opts['scan_on_schedule'])) {
                $ts[] = 'future';
            }
            if (!empty($opts['scan_on_publish'])) {
                $ts[] = 'publish';
            }
            $opts['trigger_statuses'] = $ts;
            if (!isset($opts['rescan_on_update']) && isset($opts['scan_on_update_while_scheduled'])) {
                $opts['rescan_on_update'] = $opts['scan_on_update_while_scheduled'] ? 1 : 0;
            }
        }

        $s = wp_parse_args($opts, self::defaults());

        // Prefer wp-config.php constants to keep secrets out of the DB.
        if (defined('OGM_EQA_OPENAI_API_KEY') && OGM_EQA_OPENAI_API_KEY) {
            $s['openai_api_key'] = OGM_EQA_OPENAI_API_KEY;
        }
        if (defined('OGM_EQA_ANTHROPIC_API_KEY') && OGM_EQA_ANTHROPIC_API_KEY) {
            $s['anthropic_api_key'] = OGM_EQA_ANTHROPIC_API_KEY;
        }

        return $s;
    }

    /** Active provider, sanitized to a known value. */
    public static function active_provider($s) {
        $p = (string)($s['provider'] ?? 'openai');
        return in_array($p, self::PROVIDERS, true) ? $p : 'openai';
    }

    /** Resolved API key for the active provider (settings or wp-config constant). */
    private static function active_api_key($s) {
        return self::active_provider($s) === 'anthropic'
            ? trim((string)($s['anthropic_api_key'] ?? ''))
            : trim((string)($s['openai_api_key'] ?? ''));
    }

    /** Selected model for the active provider, with a sane fallback. */
    private static function active_model($s) {
        if (self::active_provider($s) === 'anthropic') {
            $m = trim((string)($s['anthropic_model'] ?? ''));
            return $m !== '' ? $m : 'claude-haiku-4-5';
        }
        $m = trim((string)($s['openai_model'] ?? ''));
        return $m !== '' ? $m : 'gpt-4o-mini';
    }

    /** --------- Triggering scans --------- */

    public static function on_transition_post_status($new_status, $old_status, $post) {
        if (!($post instanceof WP_Post)) return;

        $s = self::settings();

        $post_types = (array)($s['post_types'] ?? []);
        if (!in_array($post->post_type, $post_types, true)) return;

        // Only act when the post's NEW status is one the user chose to scan on.
        $statuses = (array)($s['trigger_statuses'] ?? []);
        if (!in_array($new_status, $statuses, true)) return;

        // Fire on transition INTO a selected status, or — if enabled — on a
        // re-save while already in one. The content hash dedups unchanged
        // re-saves, so this won't waste API calls.
        $entered = ($old_status !== $new_status);
        $resaved = ($old_status === $new_status) && !empty($s['rescan_on_update']);
        if (!$entered && !$resaved) return;

        // Queue scan (async).
        self::queue_scan($post->ID, get_current_user_id(), false);
    }

    public static function queue_scan($post_id, $user_id = 0, $force = false) {
        $post_id = absint($post_id);
        $user_id = absint($user_id);

        if (!$post_id) return;

        // Prefer Action Scheduler if available.
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::CRON_HOOK, [
                'post_id' => $post_id,
                'user_id' => $user_id,
                'force'   => $force ? 1 : 0,
            ], 'ogm_editorial_qa');
            return;
        }

        // WP-Cron fallback
        $args = [$post_id, $user_id, $force ? 1 : 0];
        if (!wp_next_scheduled(self::CRON_HOOK, $args)) {
            wp_schedule_single_event(time() + 10, self::CRON_HOOK, $args);
        }
    }

    public static function scan_post_job($post_id, $user_id = 0, $force = 0) {
        $post_id = absint($post_id);
        $user_id = absint($user_id);
        $force   = (int)$force === 1;

        $post = get_post($post_id);
        if (!$post || !($post instanceof WP_Post)) return;

        $s = self::settings();

        $provider = self::active_provider($s);
        $api_key  = self::active_api_key($s);
        $model    = self::active_model($s);

        if ($api_key === '') {
            $const = $provider === 'anthropic' ? 'OGM_EQA_ANTHROPIC_API_KEY' : 'OGM_EQA_OPENAI_API_KEY';
            self::store_error($post_id, sprintf('Missing %s API key. Configure it in Editorial QA → Settings, or define %s in wp-config.php.', ucfirst($provider), $const));
            return;
        }

        $post_types = (array)($s['post_types'] ?? []);
        if (!in_array($post->post_type, $post_types, true)) return;

        $prepared = self::prepare_text_for_scan($post->post_title, $post->post_content, !empty($s['strip_html']));
        $hash = hash('sha256', $prepared);

        $prev_hash = (string)get_post_meta($post_id, self::META_HASH, true);

        if (!$force && $prev_hash && hash_equals($prev_hash, $hash)) {
            // Content unchanged, skip scan.
            return;
        }

        delete_post_meta($post_id, self::META_ERROR);

        $max_tokens = (int)$s['max_output_tokens'];
        $result = ($provider === 'anthropic')
            ? self::call_anthropic_for_typos($prepared, $api_key, $model, $max_tokens)
            : self::call_openai_for_typos($prepared, $api_key, $model, $max_tokens);

        if (is_wp_error($result)) {
            self::store_error($post_id, $result->get_error_message());
            return;
        }

        $report = trim((string)$result['report']);
        if ($report === '') {
            self::store_error($post_id, 'AI returned an empty report (parsing failure or blocked response).');
            return;
        }

        $issue_count = self::count_numbered_issues($report);
        if (stripos($report, 'no obvious typos detected') !== false) {
            $issue_count = 0;
        }

        update_post_meta($post_id, self::META_REPORT, $report);
        update_post_meta($post_id, self::META_ISSUE_COUNT, $issue_count);
        update_post_meta($post_id, self::META_LAST_SCANNED_GM, gmdate('Y-m-d H:i:s'));
        update_post_meta($post_id, self::META_HASH, $hash);
        update_post_meta($post_id, self::META_MODEL, $model);

        if ($user_id) {
            update_post_meta($post_id, '_ogm_eqa_last_trigger_user_id', $user_id);
        }
    }

    private static function store_error($post_id, $message) {
        update_post_meta($post_id, self::META_ERROR, sanitize_text_field((string)$message));
        update_post_meta($post_id, self::META_LAST_SCANNED_GM, gmdate('Y-m-d H:i:s'));
    }

    /** --------- Text prep + AI call --------- */

    private static function prepare_text_for_scan($title, $content, $strip_html = true) {
        $title = (string)$title;
        $content = (string)$content;

        $content = strip_shortcodes($content);

		// Remove ALL blockquote content (leave other HTML intact for later stripping/decoding)
		$content = preg_replace(
			'#<blockquote\b[^>]*>.*?</blockquote>#is',
			'',
			$content
		);

		// Remove <script>/<style> blocks WITH their contents — embed code
		// (e.g. video players) is never proofreadable text. Always, even when
		// "Strip HTML" is off, so embeds can't leak into the scan.
		$content = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $content);

		// Convert block-level boundaries to newlines BEFORE stripping tags, so
		// separate paragraphs/headings stay on separate lines. Otherwise a
		// heading like "WATCH:" and the next paragraph collapse into one line
		// ("WATCH: Later …") and read as a mid-sentence capitalization error.
		$content = preg_replace('#<br\s*/?>#i', "\n", $content);
		$content = preg_replace('#</(?:p|div|h[1-6]|li|tr|section|article|header|footer|figure|figcaption|blockquote)>#i', "\n", $content);

        if ($strip_html) {
            // false = keep line breaks (the whitespace pass below normalizes them).
            $content = wp_strip_all_tags($content, false);
        }

		$content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
		$title   = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');

		// Normalize Unicode (collapse equivalent forms)
		if (function_exists('normalizer_normalize')) {
			$content = normalizer_normalize($content, Normalizer::FORM_C);
			$title   = normalizer_normalize($title, Normalizer::FORM_C);
		}

		// Remove zero-width / invisible characters
		$content = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $content);
		$title   = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $title);

		// Normalize whitespace
		$content = preg_replace("/[ \t]+/u", " ", $content);
		$content = preg_replace("/\n{3,}/u", "\n\n", $content);
		$content = trim($content);

		$title = preg_replace("/[ \t]+/u", " ", $title);
		$title = trim($title);

        return "TITLE:\n{$title}\n\nBODY:\n{$content}\n";
    }

/**
 * POST JSON with limited retry/backoff on transient provider errors
 * (rate limits, 5xx, overloaded). Returns the wp_remote_post response or a
 * WP_Error. Runs in a background job, so a short blocking backoff is fine.
 */
private static function request_json($endpoint, array $headers, array $body) {
    $args = [
        'timeout' => 45,
        'headers' => $headers,
        'body'    => wp_json_encode($body),
    ];

    $max  = 3;
    $resp = null;
    for ($attempt = 1; $attempt <= $max; $attempt++) {
        $resp = wp_remote_post($endpoint, $args);

        if (!is_wp_error($resp)) {
            $code = (int) wp_remote_retrieve_response_code($resp);
            if (!in_array($code, self::RETRY_STATUSES, true)) {
                return $resp; // success or a non-retryable error — caller handles it
            }
        }

        if ($attempt < $max) {
            sleep($attempt); // 1s, then 2s
        }
    }

    return $resp; // last attempt's response or WP_Error
}

/** Shared system prompt for both providers. Schema is the real enforcement. */
private static function typo_instructions() {
    return <<<SYS
Return ONLY structured JSON that matches the provided schema.
Detect ONLY objective technical typos:
- Misspellings
- Duplicated words ("the the")
- Broken capitalization ONLY when a capital letter appears in the MIDDLE of a word ("thE", "wOrld"). NEVER suggest lowercasing the first letter of a word. Proper nouns (names, places, brands, organizations such as "White House"), acronyms, all-caps labels/headings (e.g. "WATCH:"), and the first word of any sentence or line are correctly capitalized — do NOT flag them. Do NOT enforce title case or headline style. When unsure about capitalization, do not report it.
- Clearly broken punctuation ("??", "!!", "..")
- Do NOT provide any corrections relating to quotation marks or apostrophes, either curly or straight. Do NOT mention any of these characters: " ' “ ” ‘ ’
The text preserves line breaks. A capitalized word at the start of a line begins a new heading or paragraph — never treat it as a mid-sentence error, even if the previous line ended without a period.
No commentary. No "no correction needed". No political/tone/style remarks.
SYS;
}

private static function call_openai_for_typos($text, $api_key, $model, $max_output_tokens) {
    $endpoint = 'https://api.openai.com/v1/responses';

    $instructions = self::typo_instructions();

$body = [
    'model' => $model,
    'instructions' => $instructions,
    'input' => $text,
    'max_output_tokens' => max(self::MIN_TOKENS, min(self::MAX_TOKENS, (int)$max_output_tokens)),

    // Structured output (Responses API: text.format)
    'text' => [
        'format' => [
            'type' => 'json_schema',

            // IMPORTANT: required at this level (not inside json_schema)
            'name' => 'typo_report',

            // Enforce the schema exactly (all properties required +
            // additionalProperties:false below are prerequisites for strict mode).
            'strict' => true,

            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'issues' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'location' => [
                                    'type' => 'string',
                                    'enum' => ['TITLE', 'BODY'],
                                ],
                                'type' => [
                                    'type' => 'string',
                                    'enum' => [
                                        'spelling',
                                        'duplicate_word',
                                        'broken_capitalization',
                                        'broken_punctuation',
                                    ],
                                ],
                                'bad' => [
                                    'type' => 'string',
                                    'maxLength' => 80,
                                ],
                                'good' => [
                                    'type' => 'string',
                                    'maxLength' => 80,
                                ],
                            ],
                            'required' => ['location', 'type', 'bad', 'good'],
                        ],
                    ],
                ],
                'required' => ['issues'],
            ],
        ],
    ],
];

    $resp = self::request_json($endpoint, [
        'Authorization' => 'Bearer ' . trim($api_key),
        'Content-Type'  => 'application/json',
    ], $body);
    if (is_wp_error($resp)) return $resp;

    $code = (int) wp_remote_retrieve_response_code($resp);
    $raw  = (string) wp_remote_retrieve_body($resp);

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return new WP_Error('ogm_eqa_bad_json', 'OpenAI returned non-JSON response.');
    }

    if ($code < 200 || $code >= 300) {
        $msg = $json['error']['message'] ?? ('OpenAI error (HTTP ' . $code . ').');
        return new WP_Error('ogm_eqa_http_error', sanitize_text_field($msg));
    }
    // Extract the structured payload regardless of where the Responses API placed it.
    $payload = self::extract_structured_payload_fallback($json);

    if (!is_array($payload)) {
        // Keep this short; avoid storing the full response for privacy.
        $snippet = mb_substr($raw, 0, 400);
        return new WP_Error('ogm_eqa_empty', 'Model response could not be parsed as structured JSON. Snippet: ' . $snippet);
    }

    if (!isset($payload['issues']) || !is_array($payload['issues'])) {
        $snippet = mb_substr(wp_json_encode($payload), 0, 300);
        return new WP_Error('ogm_eqa_bad_structured', 'Structured payload missing issues[]. Payload snippet: ' . $snippet);
    }

    return ['report' => self::build_report_from_issues($payload['issues'])];
}

/**
 * Build the numbered, human-readable report from validated issues only
 * (no model commentary can leak through). Shared by both providers.
 */
private static function build_report_from_issues($issues) {
    $lines = [];
    $n = 1;

    foreach ((array)$issues as $iss) {
        if (!is_array($iss)) continue;

        $loc  = isset($iss['location']) ? (string)$iss['location'] : '';
        $bad  = isset($iss['bad']) ? trim((string)$iss['bad']) : '';
        $good = isset($iss['good']) ? trim((string)$iss['good']) : '';

        // Only actionable changes.
        if ($bad === '' || $good === '' || $bad === $good) continue;
        if ($loc !== 'TITLE' && $loc !== 'BODY') continue;

        // Drop unhelpful capitalization "fixes" that lowercase a word's first
        // letter (proper nouns, headings, sentence/line starts). The scanner
        // can't tell these from real errors, so they're almost always wrong.
        if (self::is_unwanted_case_change($bad, $good)) continue;

        // Keep output compact and consistent.
        $bad_esc  = str_replace(["\r", "\n"], [' ', ' '], $bad);
        $good_esc = str_replace(["\r", "\n"], [' ', ' '], $good);

        $lines[] = sprintf("%d. %s: change '%s' to '%s'", $n, $loc, $bad_esc, $good_esc);
        $n++;
    }

    return $lines ? implode("\n", $lines) : 'No obvious typos detected.';
}

/**
 * True when `$good` differs from `$bad` only by case AND it lowercases the
 * first letter of a word — i.e. an unwanted downcasing of a proper noun,
 * heading, acronym, or sentence/line start. Mid-word case fixes ("thE"→"the")
 * and capitalizing a word ("paris"→"Paris") are NOT affected.
 */
private static function is_unwanted_case_change($bad, $good) {
    if (mb_strtolower($bad) !== mb_strtolower($good)) {
        return false; // not a pure case change (different letters)
    }
    $bw = preg_split('/\s+/u', trim($bad), -1, PREG_SPLIT_NO_EMPTY);
    $gw = preg_split('/\s+/u', trim($good), -1, PREG_SPLIT_NO_EMPTY);
    if (!$bw || count($bw) !== count($gw)) {
        return true; // odd case-only change — drop to be safe
    }
    foreach ($bw as $i => $bword) {
        $b0 = mb_substr($bword, 0, 1);
        $g0 = mb_substr($gw[$i], 0, 1);
        if ($b0 === $g0) continue;
        // bad's word starts with an uppercase letter that good lowercases.
        if ($b0 === mb_strtoupper($b0) && $b0 !== mb_strtolower($b0) && $g0 === mb_strtolower($b0)) {
            return true;
        }
    }
    return false;
}

/**
 * Anthropic Messages API typo scan. Uses structured outputs
 * (output_config.format json_schema) — GA on Haiku 4.5 / Sonnet 4.6 / Opus 4.8.
 * Note: raw HTTP, so the schema omits maxLength (an unsupported constraint the
 * official SDKs strip client-side).
 */
private static function call_anthropic_for_typos($text, $api_key, $model, $max_output_tokens) {
    $endpoint = 'https://api.anthropic.com/v1/messages';

    $body = [
        'model'      => $model,
        'max_tokens' => max(self::MIN_TOKENS, min(self::MAX_TOKENS, (int)$max_output_tokens)),
        'system'     => self::typo_instructions(),
        'messages'   => [
            ['role' => 'user', 'content' => $text],
        ],
        'output_config' => [
            'format' => [
                'type'   => 'json_schema',
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'issues' => [
                            'type'  => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'location' => ['type' => 'string', 'enum' => ['TITLE', 'BODY']],
                                    'type'     => [
                                        'type' => 'string',
                                        'enum' => ['spelling', 'duplicate_word', 'broken_capitalization', 'broken_punctuation'],
                                    ],
                                    'bad'   => ['type' => 'string'],
                                    'good'  => ['type' => 'string'],
                                ],
                                'required' => ['location', 'type', 'bad', 'good'],
                            ],
                        ],
                    ],
                    'required' => ['issues'],
                ],
            ],
        ],
    ];

    $resp = self::request_json($endpoint, [
        'x-api-key'         => trim($api_key),
        'anthropic-version' => '2023-06-01',
        'content-type'      => 'application/json',
    ], $body);
    if (is_wp_error($resp)) return $resp;

    $code = (int) wp_remote_retrieve_response_code($resp);
    $raw  = (string) wp_remote_retrieve_body($resp);

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return new WP_Error('ogm_eqa_bad_json', 'Anthropic returned non-JSON response.');
    }

    if ($code < 200 || $code >= 300) {
        $msg = $json['error']['message'] ?? ('Anthropic error (HTTP ' . $code . ').');
        return new WP_Error('ogm_eqa_http_error', sanitize_text_field($msg));
    }

    if (($json['stop_reason'] ?? '') === 'refusal') {
        return new WP_Error('ogm_eqa_refusal', 'Anthropic declined to process this content.');
    }

    // Structured output arrives as the first text content block (JSON string).
    $out_text = '';
    if (!empty($json['content']) && is_array($json['content'])) {
        foreach ($json['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $out_text = (string)$block['text'];
                break;
            }
        }
    }

    $payload = json_decode(trim($out_text), true);
    if (!is_array($payload) || !isset($payload['issues']) || !is_array($payload['issues'])) {
        $snippet = mb_substr($raw, 0, 400);
        return new WP_Error('ogm_eqa_bad_structured', 'Anthropic response could not be parsed as structured JSON. Snippet: ' . $snippet);
    }

    return ['report' => self::build_report_from_issues($payload['issues'])];
}

    private static function extract_output_text_fallback(array $json) {
        $out = '';
        if (!empty($json['output']) && is_array($json['output'])) {
            foreach ($json['output'] as $item) {
                if (!is_array($item)) continue;
                if (($item['type'] ?? '') === 'message' && !empty($item['content']) && is_array($item['content'])) {
                    foreach ($item['content'] as $part) {
                        if (is_array($part) && ($part['type'] ?? '') === 'output_text' && isset($part['text'])) {
                            $out .= (string)$part['text'];
                        }
                    }
                }
            }
        }
        return $out;
    }
    private static function extract_structured_payload_fallback(array $json) {
        // 1) Structured JSON may arrive as an output_json content part.
        if (!empty($json['output']) && is_array($json['output'])) {
            foreach ($json['output'] as $item) {
                if (!is_array($item)) continue;

                if (($item['type'] ?? '') === 'message' && !empty($item['content']) && is_array($item['content'])) {
                    foreach ($item['content'] as $part) {
                        if (!is_array($part)) continue;

                        // Most common shape:
                        // { "type": "output_json", "json": { ... } }
                        if (($part['type'] ?? '') === 'output_json' && isset($part['json']) && is_array($part['json'])) {
                            return $part['json'];
                        }

                        // Alternate shape:
                        if (($part['type'] ?? '') === 'output_json' && isset($part['data']) && is_array($part['data'])) {
                            return $part['data'];
                        }

                        // Sometimes still returned as output_text containing JSON
                        if (($part['type'] ?? '') === 'output_text' && isset($part['text']) && is_string($part['text'])) {
                            $maybe = json_decode($part['text'], true);
                            if (is_array($maybe)) return $maybe;
                        }
                    }
                }
            }
        }

        // 2) Fallback: try output_text and decode as JSON.
        $out_text = '';
        if (isset($json['output_text']) && is_string($json['output_text'])) {
            $out_text = $json['output_text'];
        } else {
            $out_text = self::extract_output_text_fallback($json);
        }

        $out_text = trim((string)$out_text);
        if ($out_text === '') return null;

        $maybe = json_decode($out_text, true);
        return is_array($maybe) ? $maybe : null;
    }



    private static function count_numbered_issues($report) {
        $lines = preg_split("/\r\n|\r|\n/u", (string)$report);
        $count = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\d+\s*[\.\)]\s+\S/u', $line)) $count++;
        }
        return $count;
    }

    /** --------- Admin UI --------- */

    public static function admin_menu() {
        $cap = 'edit_others_posts';

        add_menu_page(
            'Editorial QA',
            'Editorial QA',
            $cap,
            'ogm-editorial-qa',
            [__CLASS__, 'render_admin_page'],
            'dashicons-editor-spellcheck',
            60
        );

        add_submenu_page(
            'ogm-editorial-qa',
            'Settings',
            'Settings',
            'manage_options',
            'ogm-editorial-qa-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings() {
        register_setting(self::OPT_KEY, self::OPT_KEY, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
            'default' => self::defaults(),
        ]);
    }

    public static function sanitize_settings($input) {
        $d = self::defaults();
        $out = [];

        $prov = isset($input['provider']) ? sanitize_key((string)$input['provider']) : $d['provider'];
        $out['provider'] = in_array($prov, self::PROVIDERS, true) ? $prov : $d['provider'];

        // API keys are ignored at runtime if the matching wp-config constant is
        // defined, but we still store whatever was entered.
        $out['openai_api_key']    = isset($input['openai_api_key']) ? trim((string)$input['openai_api_key']) : '';
        $out['anthropic_api_key'] = isset($input['anthropic_api_key']) ? trim((string)$input['anthropic_api_key']) : '';

        $out['openai_model']    = isset($input['openai_model']) ? sanitize_text_field((string)$input['openai_model']) : $d['openai_model'];
        $out['anthropic_model'] = isset($input['anthropic_model']) ? sanitize_text_field((string)$input['anthropic_model']) : $d['anthropic_model'];

        $out['max_output_tokens'] = isset($input['max_output_tokens']) ? max(self::MIN_TOKENS, min(self::MAX_TOKENS, absint($input['max_output_tokens']))) : $d['max_output_tokens'];

        $out['strip_html'] = empty($input['strip_html']) ? 0 : 1;
        $out['rescan_on_update'] = empty($input['rescan_on_update']) ? 0 : 1;

        // Trigger statuses: keep only valid, non-internal registered statuses.
        $valid_statuses = array_keys(get_post_stati(['internal' => false]));
        $ts = isset($input['trigger_statuses']) ? (array)$input['trigger_statuses'] : [];
        $ts = array_values(array_intersect(array_map('sanitize_key', $ts), $valid_statuses));
        $out['trigger_statuses'] = $ts;

        $pts = isset($input['post_types']) ? (array)$input['post_types'] : $d['post_types'];
        $pts = array_values(array_filter(array_map('sanitize_key', $pts)));
        if (!$pts) $pts = $d['post_types'];
        $out['post_types'] = $pts;

        return $out;
    }

    public static function enqueue_admin_assets($hook) {
        $load = false;

        // Our pages
        if (strpos((string)$hook, 'ogm-editorial-qa') !== false) {
            $load = true;
        }

        // Post edit screens
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            $load = true;
        }

        // Posts list table (edit.php) — only for enabled post types
        if ($hook === 'edit.php') {
            $s = self::settings();
            $post_types = (array)($s['post_types'] ?? ['post']);
            $typenow = isset($GLOBALS['typenow']) ? (string)$GLOBALS['typenow'] : 'post';
            if (in_array($typenow, $post_types, true)) {
                $load = true;
            }
        }

        if (!$load) return;

        wp_enqueue_script('ogm-eqa-admin', plugin_dir_url(__FILE__) . 'ogm-eqa-admin.js', ['jquery'], OGM_EQA_VERSION, true);

        wp_localize_script('ogm-eqa-admin', 'OGM_EQA', [
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('ogm_eqa_rescan'),
            'refresh_nonce' => wp_create_nonce('ogm_eqa_refresh_models'),
        ]);

        wp_enqueue_style('ogm-eqa-admin-css', plugin_dir_url(__FILE__) . 'ogm-eqa-admin.css', ['dashicons'], OGM_EQA_VERSION);
    }

    /**
     * Shared branded header + tab nav (Reports / Settings) for both screens.
     */
    private static function render_admin_header($current) {
        $tabs = [
            'ogm-editorial-qa'          => ['label' => 'Reports',  'icon' => 'editor-spellcheck'],
            'ogm-editorial-qa-settings' => ['label' => 'Settings', 'icon' => 'admin-settings'],
        ];

        echo '<div class="ogm-eqa-header">';
        echo '<div class="ogm-eqa-brand">';
        echo '<span class="ogm-eqa-mark" aria-hidden="true"><span class="dashicons dashicons-editor-spellcheck"></span></span>';
        echo '<div class="ogm-eqa-brand-text">';
        echo '<h1>Editorial QA</h1>';
        echo '<p class="ogm-eqa-tagline">AI typo checks for scheduled posts — by Orchard Grove Media</p>';
        echo '</div>';
        echo '</div>';
        echo '<span class="ogm-eqa-version">v' . esc_html(OGM_EQA_VERSION) . '</span>';
        echo '</div>';
        echo '<hr class="wp-header-end" />';

        echo '<nav class="ogm-eqa-tabs" aria-label="Editorial QA">';
        foreach ($tabs as $slug => $t) {
            $url    = add_query_arg(['page' => $slug], admin_url('admin.php'));
            $active = ($slug === $current);
            echo '<a href="' . esc_url($url) . '" class="ogm-eqa-tab ' . ($active ? 'is-active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>';
            echo '<span class="dashicons dashicons-' . esc_attr($t['icon']) . '" aria-hidden="true"></span> ' . esc_html($t['label']);
            echo '</a>';
        }
        echo '</nav>';
    }

    public static function render_admin_page() {
        if (!current_user_can('edit_others_posts')) wp_die('Insufficient permissions.');

        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

        $table = new OGM_EQA_List_Table();
        $table->prepare_items();

        echo '<div class="wrap ogm-eqa-screen">';
        self::render_admin_header('ogm-editorial-qa');

        echo '<p class="ogm-eqa-intro">Posts are scanned for obvious typos when scheduled. Use <strong>Rescan</strong> after edits, or to check drafts and published posts on demand.</p>';

        echo '<div class="ogm-eqa-card">';
        $table->views();
        echo '<form method="get">';
        if (!empty($_GET['eqa_status'])) {
            echo '<input type="hidden" name="eqa_status" value="' . esc_attr(sanitize_key($_GET['eqa_status'])) . '" />';
        }
        echo '<input type="hidden" name="page" value="ogm-editorial-qa" />';
        $table->search_box('Search posts', 'ogm-eqa-search');
        $table->display();
        echo '</form>';
        echo '</div>';

        echo '</div>';
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');

        $s = self::settings();
        $all_post_types = get_post_types(['public' => true], 'objects');

        echo '<div class="wrap ogm-eqa-screen">';
        self::render_admin_header('ogm-editorial-qa-settings');

        echo '<div class="ogm-eqa-layout">';
        echo '<div class="ogm-eqa-main"><div class="ogm-eqa-card">';
        echo '<p class="ogm-eqa-intro">Pick a provider, add your API key, and choose a model. Scans run automatically in the background when a post enters one of the statuses you choose below.</p>';

        echo '<form method="post" action="options.php">';
        settings_fields(self::OPT_KEY);

        echo '<table class="form-table" role="presentation">';

        // Provider selector.
        $provider = self::active_provider($s);
        echo '<tr>';
        echo '<th scope="row"><label for="ogm-eqa-provider">AI provider</label></th>';
        echo '<td>';
        echo '<select id="ogm-eqa-provider" name="' . esc_attr(self::OPT_KEY) . '[provider]">';
        echo '<option value="openai"' . selected($provider, 'openai', false) . '>OpenAI</option>';
        echo '<option value="anthropic"' . selected($provider, 'anthropic', false) . '>Anthropic (Claude)</option>';
        echo '</select>';
        echo '<p class="description">Which API to use for scanning. Configure that provider&rsquo;s key and model below.</p>';
        echo '</td>';
        echo '</tr>';

        // Per-provider key + model + refresh.
        self::render_provider_section($s, 'openai');
        self::render_provider_section($s, 'anthropic');

        echo '<tr>';
        echo '<th scope="row"><label for="ogm-eqa-max-tokens">Max output tokens</label></th>';
        echo '<td>';
        echo '<input id="ogm-eqa-max-tokens" name="' . esc_attr(self::OPT_KEY) . '[max_output_tokens]" type="number" min="' . esc_attr(self::MIN_TOKENS) . '" max="' . esc_attr(self::MAX_TOKENS) . '" value="' . esc_attr((int)$s['max_output_tokens']) . '" />';
        echo '<p class="description">Caps how long the AI&rsquo;s reply can be for a <strong>single</strong> scan — i.e. how many typos it can list for one post. It is <strong>not</strong> a daily or monthly budget. The default (350) holds dozens of findings; only raise it if very long posts get cut off.</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Post types</th>';
        echo '<td>';
        foreach ($all_post_types as $pt => $obj) {
            $checked = in_array($pt, (array)$s['post_types'], true) ? 'checked' : '';
            echo '<label style="display:block;margin:2px 0;">';
            echo '<input type="checkbox" name="' . esc_attr(self::OPT_KEY) . '[post_types][]" value="' . esc_attr($pt) . '" ' . $checked . ' /> ';
            echo esc_html($obj->labels->name);
            echo '</label>';
        }
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Scan triggers</th>';
        echo '<td>';
        echo '<p class="description" style="margin:0 0 4px;">Scan a post when it <strong>enters</strong> one of these statuses:</p>';
        $sel_statuses = (array)($s['trigger_statuses'] ?? []);
        foreach (get_post_stati(['internal' => false], 'objects') as $sk => $sobj) {
            $checked = in_array($sk, $sel_statuses, true) ? 'checked' : '';
            echo '<label style="display:block;margin:2px 0;">';
            echo '<input type="checkbox" name="' . esc_attr(self::OPT_KEY) . '[trigger_statuses][]" value="' . esc_attr($sk) . '" ' . $checked . ' /> ';
            echo esc_html($sobj->label) . ' <code>' . esc_html($sk) . '</code>';
            echo '</label>';
        }
        echo '<label style="display:block;margin:10px 0 2px;"><input type="checkbox" name="' . esc_attr(self::OPT_KEY) . '[rescan_on_update]" value="1" ' . checked(1, (int)$s['rescan_on_update'], false) . ' /> Also re-scan when an already-eligible post is edited and saved again</label>';
        echo '<p class="description">Tip: each scan is one API call, but unchanged content is skipped automatically — so re-saves rarely cost anything.</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Behavior</th>';
        echo '<td>';
        echo '<label style="display:block;margin:2px 0;"><input type="checkbox" name="' . esc_attr(self::OPT_KEY) . '[strip_html]" value="1" ' . checked(1, (int)$s['strip_html'], false) . ' /> Strip HTML before scanning</label>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        submit_button('Save Settings');
        echo '</form>';
        echo '</div></div>'; // .ogm-eqa-card, .ogm-eqa-main

        echo '<aside class="ogm-eqa-sidebar">';
        self::render_settings_sidebar($s);
        echo '</aside>';

        echo '</div>'; // .ogm-eqa-layout
        echo '</div>'; // .wrap
    }

    /** Sidebar for the settings screen: at-a-glance status + helpful links. */
    private static function render_settings_sidebar($s) {
        $provider = self::active_provider($s);
        $label    = $provider === 'anthropic' ? 'Anthropic (Claude)' : 'OpenAI';
        $model    = $provider === 'anthropic' ? (string)($s['anthropic_model'] ?? '') : (string)($s['openai_model'] ?? '');
        $key_set  = ($provider === 'anthropic')
            ? trim((string)($s['anthropic_api_key'] ?? '')) !== ''
            : trim((string)($s['openai_api_key'] ?? '')) !== '';

        echo '<div class="ogm-eqa-card ogm-eqa-aside-card">';
        echo '<h2>At a glance</h2>';
        echo '<ul class="ogm-eqa-glance">';
        echo '<li><span class="label">Provider</span><span class="value">' . esc_html($label) . '</span></li>';
        echo '<li><span class="label">Model</span><span class="value"><code>' . esc_html($model ?: '—') . '</code></span></li>';
        echo '<li><span class="ogm-eqa-dot ' . ($key_set ? 'is-on' : '') . '"></span><span class="label">API key</span><span class="value">' . ($key_set ? 'Configured' : 'Not set') . '</span></li>';
        $trigger_count = count((array)($s['trigger_statuses'] ?? []));
        echo '<li><span class="ogm-eqa-dot ' . ($trigger_count ? 'is-on' : '') . '"></span><span class="label">Auto-scan</span><span class="value">' . ($trigger_count ? esc_html(sprintf('%d status%s', $trigger_count, $trigger_count === 1 ? '' : 'es')) : 'Off') . '</span></li>';
        echo '</ul>';
        echo '</div>';

        echo '<div class="ogm-eqa-card ogm-eqa-aside-card">';
        echo '<h2>Get an API key</h2>';
        echo '<ul class="ogm-eqa-links">';
        echo '<li><a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener"><span class="dashicons dashicons-admin-network" aria-hidden="true"></span> OpenAI API keys</a></li>';
        echo '<li><a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener"><span class="dashicons dashicons-admin-network" aria-hidden="true"></span> Anthropic API keys</a></li>';
        echo '</ul>';
        echo '<p class="ogm-eqa-aside-foot">Tip: keep keys out of the database by defining <code>OGM_EQA_OPENAI_API_KEY</code> or <code>OGM_EQA_ANTHROPIC_API_KEY</code> in <code>wp-config.php</code>.</p>';
        echo '</div>';
    }

    /** Render one provider's API key + model dropdown + "Refresh models" button. */
    private static function render_provider_section($s, $provider) {
        $is_anthropic = ($provider === 'anthropic');
        $label     = $is_anthropic ? 'Anthropic' : 'OpenAI';
        $key_field = $is_anthropic ? 'anthropic_api_key' : 'openai_api_key';
        $mdl_field = $is_anthropic ? 'anthropic_model'   : 'openai_model';
        $const     = $is_anthropic ? 'OGM_EQA_ANTHROPIC_API_KEY' : 'OGM_EQA_OPENAI_API_KEY';
        $const_set = defined($const) && constant($const);
        $eg        = $is_anthropic ? 'e.g. claude-haiku-4-5' : 'e.g. gpt-4o-mini';
        $key_url   = $is_anthropic ? 'https://console.anthropic.com/settings/keys' : 'https://platform.openai.com/api-keys';
        $key_host  = $is_anthropic ? 'console.anthropic.com' : 'platform.openai.com';
        $id_key    = 'ogm-eqa-' . $provider . '-key';
        $id_mdl    = 'ogm-eqa-' . $provider . '-model';

        // API key row.
        echo '<tr>';
        echo '<th scope="row"><label for="' . esc_attr($id_key) . '">' . esc_html($label) . ' API key</label></th>';
        echo '<td>';
        echo '<input id="' . esc_attr($id_key) . '" name="' . esc_attr(self::OPT_KEY) . '[' . esc_attr($key_field) . ']" type="password" class="regular-text" value="' . esc_attr((string)($s[$key_field] ?? '')) . '" autocomplete="off" />';
        echo '<p class="description"><a href="' . esc_url($key_url) . '" target="_blank" rel="noopener">Create a ' . esc_html($label) . ' key at ' . esc_html($key_host) . ' <span class="dashicons dashicons-external" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom;"></span></a></p>';
        if ($const_set) {
            echo '<p class="description">Currently provided by <code>' . esc_html($const) . '</code> in wp-config.php — this field is ignored at runtime.</p>';
        } else {
            echo '<p class="description">Stored in wp_options unless you define <code>' . esc_html($const) . '</code> in wp-config.php.</p>';
        }
        echo '</td>';
        echo '</tr>';

        // Model row + refresh button.
        echo '<tr>';
        echo '<th scope="row"><label for="' . esc_attr($id_mdl) . '">' . esc_html($label) . ' model</label></th>';
        echo '<td>';

        $choices = self::get_model_choices($provider);
        $current = (string)($s[$mdl_field] ?? '');
        echo '<select id="' . esc_attr($id_mdl) . '" name="' . esc_attr(self::OPT_KEY) . '[' . esc_attr($mdl_field) . ']">';
        foreach ($choices as $mc) {
            echo '<option value="' . esc_attr($mc) . '"' . selected($current, $mc, false) . '>' . esc_html($mc) . '</option>';
        }
        echo '</select> ';

        // AJAX refresh — type=button so it never submits the form (pressing
        // Enter in a field saves settings instead of triggering a refresh).
        echo '<button type="button" class="button ogm-eqa-refresh" data-provider="' . esc_attr($provider) . '" data-target="' . esc_attr($id_mdl) . '">Refresh ' . esc_html($label) . ' models</button> ';
        echo '<span class="ogm-eqa-refresh-status" aria-live="polite"></span>';

        $updated = self::models_updated($provider);
        if ($updated) {
            echo '<p class="description ogm-eqa-refresh-meta">Model list refreshed ' . esc_html(human_time_diff($updated, time())) . ' ago (' . esc_html($eg) . ').</p>';
        } else {
            echo '<p class="description ogm-eqa-refresh-meta">Showing a default list (' . esc_html($eg) . '). Save the ' . esc_html($label) . ' API key, then click <strong>Refresh ' . esc_html($label) . ' models</strong>.</p>';
        }

        echo '</td>';
        echo '</tr>';
    }

    /** --------- Meta box --------- */

    public static function register_meta_box() {
        $s = self::settings();
        $post_types = (array)($s['post_types'] ?? ['post']);

        foreach ($post_types as $pt) {
            add_meta_box(
                'ogm-eqa-metabox',
                'Editorial QA (Typos)',
                [__CLASS__, 'render_meta_box'],
                $pt,
                'side',
                'high'
            );
        }
    }

    public static function render_meta_box($post) {
        if (!($post instanceof WP_Post)) return;

        $report = (string)get_post_meta($post->ID, self::META_REPORT, true);
        $issues = (int)get_post_meta($post->ID, self::META_ISSUE_COUNT, true);
        $last   = (string)get_post_meta($post->ID, self::META_LAST_SCANNED_GM, true);
        
        if ($last) {
			// Convert the stored GMT timestamp to the site's configured timezone.
			$last = get_date_from_gmt($last, 'Y-m-d H:i:s');
		}

        $model  = (string)get_post_meta($post->ID, self::META_MODEL, true);
        $err    = (string)get_post_meta($post->ID, self::META_ERROR, true);

        $badge = $issues > 0 ? '<span class="ogm-eqa-badge ogm-eqa-badge-bad">Issues: '.esc_html($issues).'</span>'
                             : '<span class="ogm-eqa-badge ogm-eqa-badge-good">Clean</span>';

        echo '<div class="ogm-eqa-metabox">';
        echo $badge;

        if ($last) {
            echo '<p class="description" style="margin-top:6px;">Last scan: '.esc_html($last).'</p>';
        }
        if ($model) {
            echo '<p class="description" style="display:none;">Model: <code>'.esc_html($model).'</code></p>';
        }
        if ($err) {
            echo '<p style="color:#b32d2e;"><strong>Error:</strong> '.esc_html($err).'</p>';
        }

if ($report && stripos($report, 'No obvious typos detected.') === false) {

echo '<p class="ogm-eqa-disclaimer">Heads up — AI isn&rsquo;t perfect. One or more of these may not actually be typos.</p>';
    $lines = preg_split('/\r\n|\r|\n/', $report);

    echo '<ol class="ogm-eqa-list">';

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // Remove leading number like "1. "
        $line = preg_replace('/^\d+\.?\s*/', '', $line);

        echo '<li>' . esc_html($line) . '</li>';
    }

    echo '</ol>';

} else {
    echo '<div class="ogm-eqa-report">' . esc_html($report) . '</div>';
}

        echo '<p style="margin-top:10px;">';
        echo '<button type="button" class="button ogm-eqa-rescan" data-post-id="'.esc_attr($post->ID).'" data-context="metabox">Rescan</button> ';
        echo '<span class="ogm-eqa-rescan-status" style="margin-left:6px;"></span>';
        echo '</p>';

        echo '</div>';
    }

    /** --------- Posts list table column --------- */

    public static function register_list_table_column() {
        $s = self::settings();
        $post_types = (array)($s['post_types'] ?? ['post']);

        foreach ($post_types as $pt) {
            add_filter("manage_{$pt}_posts_columns", [__CLASS__, 'add_posts_list_column'], 20);
            add_action("manage_{$pt}_posts_custom_column", [__CLASS__, 'render_posts_list_column'], 10, 2);
            add_filter("manage_edit-{$pt}_sortable_columns", [__CLASS__, 'sortable_posts_list_column']);
        }

        add_action('pre_get_posts', [__CLASS__, 'handle_posts_list_sorting']);
    }

    public static function add_posts_list_column($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['ogm_eqa'] = 'Issues';
            }
        }
        if (!isset($new['ogm_eqa'])) {
            $new['ogm_eqa'] = 'Issues';
        }
        return $new;
    }

    public static function render_posts_list_column($column, $post_id) {
        if ($column !== 'ogm_eqa') return;

        $issues = (int)get_post_meta($post_id, self::META_ISSUE_COUNT, true);
        $last   = (string)get_post_meta($post_id, self::META_LAST_SCANNED_GM, true);
        $err    = (string)get_post_meta($post_id, self::META_ERROR, true);

        if (!$last && !$err) {
            echo '—';
            return;
        }

        if ($err) {
            echo '<span class="ogm-eqa-badge ogm-eqa-badge-bad" title="'.esc_attr($err).'">!</span>';
            echo '<div style="margin-top:6px;"><button type="button" class="button button-small ogm-eqa-rescan" data-post-id="'.esc_attr($post_id).'" data-context="postslist">Rescan</button></div>';
            echo '<div class="ogm-eqa-rescan-status" style="margin-top:6px;"></div>';
            return;
        }

        if ($issues > 0) {
            echo '<span class="ogm-eqa-badge ogm-eqa-badge-bad" title="Issues found">'.wp_kses_post($issues).'</span>';
        } else {
            echo '<span class="ogm-eqa-badge ogm-eqa-badge-good" title="No obvious typos">0</span>';
        }

        echo '<div style="margin-top:6px;">';
        echo '<button type="button" class="button button-small ogm-eqa-rescan" data-post-id="'.esc_attr($post_id).'" data-context="postslist">Rescan</button>';
        echo '</div>';
        echo '<div class="ogm-eqa-rescan-status" style="margin-top:6px;"></div>';
    }

    public static function sortable_posts_list_column($columns) {
        $columns['ogm_eqa'] = 'ogm_eqa';
        return $columns;
    }

    public static function handle_posts_list_sorting($query) {
        if (!is_admin() || !$query->is_main_query()) return;

        $orderby = $query->get('orderby');
        if ($orderby !== 'ogm_eqa') return;

        $query->set('meta_key', self::META_ISSUE_COUNT);
        $query->set('orderby', 'meta_value_num');
        $query->set('order', ($query->get('order') === 'ASC') ? 'ASC' : 'DESC');
    }

    /** --------- AJAX rescan --------- */

    public static function ajax_rescan() {
        if (!current_user_can('edit_others_posts')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        check_ajax_referer('ogm_eqa_rescan', 'nonce');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error(['message' => 'Missing post_id.'], 400);
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => 'Post not found.'], 404);
        }

        self::queue_scan($post_id, get_current_user_id(), true);

        wp_send_json_success(['message' => 'Rescan queued. Refresh in a few seconds.']);
    }

    /** --------- Model list (dropdown + refresh) --------- */

    /** Seed model list for a provider (shown before any refresh). */
    private static function seed_models($provider) {
        return $provider === 'anthropic' ? self::SEED_MODELS_ANTHROPIC : self::SEED_MODELS_OPENAI;
    }

    /** Cached model entry for a provider: ['ids'=>string[], 'updated'=>int]. */
    private static function models_cache($provider) {
        $all = get_option(self::OPT_MODELS, []);
        $entry = (is_array($all) && isset($all[$provider]) && is_array($all[$provider])) ? $all[$provider] : [];
        return [
            'ids'     => (isset($entry['ids']) && is_array($entry['ids'])) ? array_values(array_filter(array_map('strval', $entry['ids']))) : [],
            'updated' => isset($entry['updated']) ? (int)$entry['updated'] : 0,
        ];
    }

    /** Last-refresh timestamp for a provider (0 if never). */
    private static function models_updated($provider) {
        return self::models_cache($provider)['updated'];
    }

    /**
     * Options for a provider's model dropdown: the cached list (or the seed
     * list if never refreshed), always including the currently-saved model.
     *
     * @return string[]
     */
    private static function get_model_choices($provider) {
        $ids = self::models_cache($provider)['ids'];
        if (!$ids) $ids = self::seed_models($provider);

        // Keep the saved model selectable even if it isn't in the fetched/seed list.
        $s = self::settings();
        $current = trim((string)($provider === 'anthropic' ? ($s['anthropic_model'] ?? '') : ($s['openai_model'] ?? '')));
        if ($current !== '' && !in_array($current, $ids, true)) {
            array_unshift($ids, $current);
        }
        return $ids;
    }

    /**
     * AJAX handler for the "Refresh models" buttons. Fetches the chosen
     * provider's /v1/models, filters + ranks, caches per-provider, and returns
     * the list as JSON so the dropdown updates in place (no page reload, and
     * any error is shown inline rather than as a blank admin-post page).
     */
    public static function ajax_refresh_models() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }
        check_ajax_referer('ogm_eqa_refresh_models', 'nonce');

        $provider = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
        if (!in_array($provider, self::PROVIDERS, true)) {
            wp_send_json_error(['message' => 'Unknown provider.'], 400);
        }

        $s = self::settings();

        try {
            if ($provider === 'anthropic') {
                $api_key = trim((string)($s['anthropic_api_key'] ?? ''));
                $models  = self::rank_anthropic_models(self::fetch_anthropic_models($api_key));
            } else {
                $api_key = trim((string)($s['openai_api_key'] ?? ''));
                $models  = self::rank_openai_models(self::fetch_openai_models($api_key));
            }
            if (!$models) {
                throw new \RuntimeException('No compatible models were returned by the provider.');
            }

            $all = get_option(self::OPT_MODELS, []);
            if (!is_array($all)) $all = [];
            $all[$provider] = ['ids' => $models, 'updated' => time()];
            update_option(self::OPT_MODELS, $all);
        } catch (\Throwable $e) {
            error_log("Editorial QA model refresh failed ($provider): " . $e->getMessage());
            wp_send_json_error(['message' => mb_substr(trim(wp_strip_all_tags($e->getMessage())), 0, 200)]);
        }

        wp_send_json_success([
            'models'  => array_values($models),
            'count'   => count($models),
            'message' => sprintf('Refreshed — %d models.', count($models)),
        ]);
    }

    /**
     * GET https://api.openai.com/v1/models — returns chat-capable model IDs.
     *
     * @return string[]
     * @throws \RuntimeException on missing key / transport / HTTP error.
     */
    private static function fetch_openai_models($api_key) {
        if ($api_key === '') {
            throw new \RuntimeException('OpenAI API key not configured. Save the key first, then refresh.');
        }

        $resp = wp_remote_get('https://api.openai.com/v1/models', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
        ]);
        if (is_wp_error($resp)) {
            throw new \RuntimeException('Transport error: ' . $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("HTTP $code: " . substr($body, 0, 300));
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['data']) || !is_array($data['data'])) {
            throw new \RuntimeException('Unexpected response shape: ' . substr($body, 0, 300));
        }

        // Allowlist chat/Responses-capable families; blocklist non-text and
        // deprecated lines (embeddings, audio, image, fine-tunes, etc.).
        $blocklist = [
            'embedding', 'audio', 'tts', 'whisper', 'image', 'moderation',
            'realtime', 'transcribe', 'instruct', 'davinci', 'curie',
            'babbage', 'ada', 'search', 'similarity', 'edit', '-ft-', ':ft-',
        ];

        $ids = [];
        foreach ($data['data'] as $m) {
            if (empty($m['id'])) continue;
            $id = (string) $m['id'];

            $is_chat = preg_match('/^o\d/i', $id)
                || strpos($id, 'gpt-') === 0
                || strpos($id, 'chatgpt-') === 0;
            if (!$is_chat) continue;

            $skip = false;
            foreach ($blocklist as $needle) {
                if (stripos($id, $needle) !== false) { $skip = true; break; }
            }
            if (!$skip) $ids[] = $id;
        }
        return $ids;
    }

    /**
     * Sort OpenAI chat-model IDs most-capable → least, collapse near-duplicate
     * snapshots to the canonical family head, and cap the list.
     *
     * @param string[] $ids
     * @return string[]
     */
    private static function rank_openai_models(array $ids) {
        $score_for = function ($id) {
            $id = strtolower($id);
            if (preg_match('/^o4(?!-mini)/', $id)) return 105;
            if (preg_match('/^o3(?!-mini)/', $id)) return 100;
            if (preg_match('/^o1(?!-mini)/', $id)) return 90;
            if (preg_match('/^o4-mini/', $id))     return 72;
            if (preg_match('/^o3-mini/', $id))     return 70;
            if (preg_match('/^o1-mini/', $id))     return 65;
            if (strpos($id, 'gpt-5')       === 0) return 95;
            if (strpos($id, 'gpt-4.5')     === 0) return 85;
            if (strpos($id, 'gpt-4.1-mini')=== 0) return 58;
            if (strpos($id, 'gpt-4.1')     === 0) return 82;
            if (strpos($id, 'gpt-4o-mini') === 0) return 55;
            if (strpos($id, 'gpt-4o')      === 0) return 80;
            if (strpos($id, 'gpt-4-turbo') === 0) return 75;
            if (strpos($id, 'gpt-4')       === 0) return 60;
            if (strpos($id, 'gpt-3.5')     === 0) return 40;
            if (strpos($id, 'chatgpt-')    === 0) return 50;
            return 30;
        };

        $variant_penalty = function ($id) {
            $p = 0;
            if (preg_match('/-\d{4}-\d{2}-\d{2}/', $id)) $p += 3; // dated snapshot
            if (stripos($id, 'preview') !== false)       $p += 5;
            if (stripos($id, 'realtime') !== false)      $p += 8;
            if (stripos($id, 'audio') !== false)         $p += 8;
            if (stripos($id, 'search') !== false)        $p += 6;
            return $p;
        };

        $scored = [];
        foreach ($ids as $id) {
            $scored[] = ['id' => $id, 'score' => $score_for($id) - $variant_penalty($id), 'tie' => $id];
        }
        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) return $b['score'] - $a['score'];
            return strcmp($a['tie'], $b['tie']);
        });

        // Collapse dated/preview snapshots to one entry per family head.
        $seen = [];
        $out  = [];
        foreach ($scored as $row) {
            $fam = preg_replace('/-\d{4}.*$/', '', $row['id']);
            $fam = preg_replace('/-preview.*$/i', '', $fam);
            if (isset($seen[$fam])) continue;
            $seen[$fam] = true;
            $out[] = $row['id'];
            if (count($out) >= 12) break;
        }
        return $out;
    }

    /**
     * GET https://api.anthropic.com/v1/models — returns claude-* model IDs.
     *
     * @return string[]
     * @throws \RuntimeException on missing key / transport / HTTP error.
     */
    private static function fetch_anthropic_models($api_key) {
        if ($api_key === '') {
            throw new \RuntimeException('Anthropic API key not configured. Save the key first, then refresh.');
        }

        $resp = wp_remote_get('https://api.anthropic.com/v1/models?limit=100', [
            'timeout' => 15,
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
        ]);
        if (is_wp_error($resp)) {
            throw new \RuntimeException('Transport error: ' . $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("HTTP $code: " . substr($body, 0, 300));
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['data']) || !is_array($data['data'])) {
            throw new \RuntimeException('Unexpected response shape: ' . substr($body, 0, 300));
        }

        $ids = [];
        foreach ($data['data'] as $m) {
            if (!empty($m['id']) && strpos((string)$m['id'], 'claude-') === 0) {
                $ids[] = (string) $m['id'];
            }
        }
        return $ids;
    }

    /**
     * Sort Anthropic model IDs most-capable → least (opus > sonnet > haiku),
     * dedupe, and cap the list. Newer dated snapshots sort first within a family.
     *
     * @param string[] $ids
     * @return string[]
     */
    private static function rank_anthropic_models(array $ids) {
        $family_score = ['opus' => 30, 'sonnet' => 20, 'haiku' => 10];

        $scored = [];
        foreach ($ids as $id) {
            $score = 0;
            foreach ($family_score as $fam => $pts) {
                if (stripos($id, $fam) !== false) { $score += $pts; break; }
            }
            $scored[] = ['id' => $id, 'score' => $score, 'tie' => $id];
        }
        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) return $b['score'] - $a['score'];
            return strcmp($b['tie'], $a['tie']); // newer dated snapshots sort first
        });

        $out = [];
        foreach ($scored as $row) {
            if (!in_array($row['id'], $out, true)) $out[] = $row['id'];
            if (count($out) >= 12) break;
        }
        return $out;
    }
}

/** --------- List Table (Editorial QA page) --------- */
if (is_admin() && !class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

if (is_admin()) {
    class OGM_EQA_List_Table extends WP_List_Table {
        private $status = 'future';

        public function __construct() {
            parent::__construct([
                'singular' => 'post',
                'plural'   => 'posts',
                'ajax'     => false,
            ]);

            $this->status = isset($_GET['eqa_status']) ? sanitize_key((string)$_GET['eqa_status']) : 'future';
            if (!in_array($this->status, ['future','draft','pending','publish','any'], true)) {
                $this->status = 'future';
            }
        }

        public function get_views() {
            $base = admin_url('admin.php?page=ogm-editorial-qa');
            $views = [];
            $statuses = [
                'future'  => 'Scheduled',
                'draft'   => 'Drafts',
                'pending' => 'Pending',
                'publish' => 'Published',
                'any'     => 'All',
            ];
            foreach ($statuses as $k => $label) {
                $url = add_query_arg(['eqa_status' => $k], $base);
                $class = ($this->status === $k) ? ' class="current"' : '';
                $views[$k] = '<a href="'.esc_url($url).'"'.$class.'>'.esc_html($label).'</a>';
            }
            return $views;
        }

        public function get_columns() {
            return [
                'title'    => 'Title',
                'status'   => 'Status',
                'schedule' => 'Date',
                'issues'   => 'Issues',
                'scanned'  => 'Last scan',
                'actions'  => 'Actions',
            ];
        }

        public function prepare_items() {
            $per_page = 20;
            $paged = max(1, (int)($this->get_pagenum()));
            $search = isset($_GET['s']) ? sanitize_text_field((string)$_GET['s']) : '';

            $args = [
                'post_type'      => 'any',
                'post_status'    => $this->status === 'any' ? ['future','draft','pending','publish'] : [$this->status],
                'posts_per_page' => $per_page,
                'paged'          => $paged,
                's'              => $search,
                'orderby'        => 'date',
                'order'          => ($this->status === 'future') ? 'ASC' : 'DESC',
                'fields'         => 'all',
            ];

            $q = new WP_Query($args);

            $this->items = $q->posts;

            $this->set_pagination_args([
                'total_items' => (int)$q->found_posts,
                'per_page'    => $per_page,
                'total_pages' => (int)$q->max_num_pages,
            ]);
            
            $this->_column_headers = [$this->get_columns(), [], []];
        }

        public function column_default($item, $column_name) {
            if (!($item instanceof WP_Post)) return '';

            switch ($column_name) {
                case 'title':
                    $edit = get_edit_post_link($item->ID);
                    return '<a href="'.esc_url($edit).'"><strong>'.esc_html(get_the_title($item)).'</strong></a>';

                case 'status':
                    return esc_html($item->post_status);

                case 'schedule':
                    $d = $item->post_date ? mysql2date('Y-m-d H:i', $item->post_date, false) : '';
                    return esc_html($d);

                case 'issues':
                    $issues = (int)get_post_meta($item->ID, OGM_Editorial_QA::META_ISSUE_COUNT, true);
                    if ($issues > 0) {
                        return '<span class="ogm-eqa-badge ogm-eqa-badge-bad">'.esc_html($issues).'</span>';
                    }
                    $last = (string)get_post_meta($item->ID, OGM_Editorial_QA::META_LAST_SCANNED_GM, true);
                    if (!$last) return '—';
                    return '<span class="ogm-eqa-badge ogm-eqa-badge-good">0</span>';

                case 'scanned':
                    $last = (string)get_post_meta($item->ID, OGM_Editorial_QA::META_LAST_SCANNED_GM, true);
                    if (!$last) return '—';
                    // Show in the site's configured timezone, matching the meta box.
                    return '<code>'.esc_html(get_date_from_gmt($last, 'Y-m-d H:i')).'</code>';

                case 'actions':
                    $btn = '<button type="button" class="button ogm-eqa-rescan" data-post-id="'.esc_attr($item->ID).'" data-context="table">Rescan</button>';
                    $view = '<a class="button" href="'.esc_url(get_edit_post_link($item->ID)).'">View</a>';
                    return $btn . ' ' . $view . '<div class="ogm-eqa-rescan-status" style="margin-top:6px;"></div>';
            }

            return '';
        }
    }
}

OGM_Editorial_QA::init();