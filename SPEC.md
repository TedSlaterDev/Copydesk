# Editorial QA (AI Typos) — Technical Specification

**Plugin:** Editorial QA (AI Typos)
**Author:** Orchard Grove Media, LLC
**Version of record:** 1.5.0
**Requires:** WordPress 6.0+, PHP 8.0+
**Providers:** OpenAI (Responses API) or Anthropic (Messages API) — selectable per site

This document is the specification of record for the plugin's current behavior. It reflects the shipping code in `editorial-qa.php`, `ogm-eqa-admin.js`, and `ogm-eqa-admin.css`.

---

## 1. Purpose & scope

Editorial QA detects **objective, technical typos** in WordPress posts and surfaces them in wp-admin so a human can fix them before publication. It is a proofreading *assistant*, not an auto-corrector — it never modifies post content.

### In scope (the only things it flags)

| Type (`type` enum) | Examples |
|---|---|
| `spelling` | misspelled words |
| `duplicate_word` | "the the", "and and" |
| `broken_capitalization` | "thE", random mid-word caps |
| `broken_punctuation` | "??", "!!", ".." |

### Explicitly out of scope (never flagged)

- Quotation marks and apostrophes — curly or straight (the model is instructed not to mention `"` `'` `“` `”` `‘` `’`).
- Title case / headline style.
- Tone, grammar preference, style, political/editorial judgment.
- Anything inside a `<blockquote>` — quoted source material is stripped before scanning.

---

## 2. High-level architecture

Single-file plugin (`editorial-qa.php`) implemented as a static class `OGM_Editorial_QA`, plus an admin-only `OGM_EQA_List_Table` (extends `WP_List_Table`) and two admin assets.

```
schedule / re-save post ──► transition_post_status hook
                                    │
                                    ▼
                            queue_scan(post_id, user_id, force)
                                    │
              ┌─────────────────────┴─────────────────────┐
       Action Scheduler                              WP-Cron fallback
   (as_enqueue_async_action,                   (wp_schedule_single_event,
    group "ogm_editorial_qa")                        +10s)
              └─────────────────────┬─────────────────────┘
                                    ▼
                            scan_post_job(post_id, user_id, force)
                                    │
            prepare_text_for_scan ──► sha256 hash ──► (skip if unchanged & !force)
                                    │
                            call_openai_for_typos ──► OpenAI Responses API
                                    │
                       validated issues[] ──► numbered text report
                                    │
                            persist to post meta
                                    │
              ┌─────────────────────┼─────────────────────┐
        Admin page              Meta box            Posts list column
   (OGM_EQA_List_Table)   (editor sidebar)        (sortable "QA")
```

Manual **Rescan** (any UI surface) calls `wp_ajax_ogm_eqa_rescan`, which queues a scan with `force = true`.

---

## 3. Triggers

Registered on `transition_post_status` (`on_transition_post_status`):

1. **On schedule** — `old_status !== 'future' && new_status === 'future'`. Gated by the `scan_on_schedule` setting.
2. **On update-while-scheduled** — `old_status === 'future' && new_status === 'future'`. Gated by both `scan_on_schedule` and `scan_on_update_while_scheduled`.
3. **On publish** (opt-in) — `new_status === 'publish' && old_status !== 'publish'`. Gated by `scan_on_publish` (default off). Catches posts published directly without scheduling; the `future → publish` case is included but harmless (content-hash dedup against the scheduling-time scan).
4. **Manual** — the Rescan button (AJAX), always forced, works on any post status.

A post must belong to an enabled post type (`post_types` setting) to trigger. A post that goes draft → publish without passing through `future` is **not** auto-scanned by design; Rescan covers it.

---

## 4. Processing pipeline

### 4.1 Text preparation (`prepare_text_for_scan`)

1. `strip_shortcodes()` on the body.
2. Remove all `<blockquote>…</blockquote>` content (regex, dot-all, case-insensitive).
3. Optionally `wp_strip_all_tags()` (the `strip_html` setting; on by default).
4. `html_entity_decode()` (ENT_QUOTES | ENT_HTML5) on title and body.
5. Unicode NFC normalization (`normalizer_normalize`) when `intl` is available.
6. Strip zero-width / invisible characters (`U+200B–200D`, `U+FEFF`).
7. Collapse runs of spaces/tabs; collapse 3+ newlines to 2; trim.
8. Emit a single payload string: `"TITLE:\n{title}\n\nBODY:\n{body}\n"`.

### 4.2 Content-hash deduplication

`hash('sha256', $prepared)` is compared (via `hash_equals`) against the stored `_ogm_eqa_scanned_hash`. If equal and the scan is not forced, the job returns without calling the AI provider. On success the new hash is stored. **Effect:** re-saving unchanged content costs nothing.

### 4.2b Provider dispatch

`scan_post_job` resolves the active provider, key, and model via `active_provider()` / `active_api_key()` / `active_model()`, then calls `call_anthropic_for_typos()` or `call_openai_for_typos()`. Both return `['report' => string]` (or a `WP_Error`); both build that string through the shared `build_report_from_issues()` so the output format is identical regardless of provider.

### 4.3 OpenAI request (`call_openai_for_typos`)

- **Endpoint:** `POST https://api.openai.com/v1/responses`
- **Auth:** `Authorization: Bearer <api_key>`
- **Timeout:** 45s, via `wp_remote_post`.
- **Body:**
  - `model` — from settings (default `gpt-4o-mini`; empty falls back to `gpt-4o-mini`).
  - `instructions` — the short system prompt enumerating in-scope/out-of-scope rules.
  - `input` — the prepared TITLE/BODY payload.
  - `max_output_tokens` — `max(300, min(2000, setting))`.
  - `text.format` — strict JSON-schema structured output:

```jsonc
{
  "type": "json_schema",
  "name": "typo_report",
  "strict": true,
  "schema": {
    "type": "object",
    "additionalProperties": false,
    "required": ["issues"],
    "properties": {
      "issues": {
        "type": "array",
        "items": {
          "type": "object",
          "additionalProperties": false,
          "required": ["location", "type", "quote", "bad", "good"],
          "properties": {
            "location": { "type": "string", "enum": ["TITLE", "BODY"] },
            "type":     { "type": "string",
                          "enum": ["spelling", "duplicate_word",
                                   "broken_capitalization", "broken_punctuation"] },
            "bad":      { "type": "string", "maxLength": 80 },
            "good":     { "type": "string", "maxLength": 80 }
          }
        }
      }
    }
  }
}
```

Both providers' HTTP POSTs go through `request_json()`, which retries up to 3 times with a 1s→2s backoff on transient statuses (`429, 500, 502, 503, 529`) and on transport errors; other statuses return immediately for the caller to handle.

### 4.3b Anthropic request (`call_anthropic_for_typos`)

- **Endpoint:** `POST https://api.anthropic.com/v1/messages`
- **Auth:** `x-api-key: <api_key>` + `anthropic-version: 2023-06-01`.
- **Timeout:** 45s, via `wp_remote_post`.
- **Body:**
  - `model` — from settings (default `claude-haiku-4-5`; empty falls back to it).
  - `system` — the same shared system prompt (`typo_instructions()`).
  - `messages` — a single user turn carrying the prepared TITLE/BODY payload.
  - `max_tokens` — `max(300, min(2000, setting))`.
  - `output_config.format` — a `json_schema` structured output with the **same** `issues[]` shape as OpenAI, **minus the `maxLength` constraints** (an unsupported keyword the official SDKs strip client-side; the plugin uses raw HTTP so it omits them). No `strict` / `name` keys — Anthropic's format doesn't take them. GA on Haiku 4.5 / Sonnet 4.6 / Opus 4.8, so no beta header.

### 4.4 Response handling

**OpenAI:**
- Non-JSON body → `WP_Error`; HTTP ≥ 300 → `WP_Error` carrying `error.message`.
- The structured payload is extracted defensively via `extract_structured_payload_fallback`, which handles several Responses API shapes: `output_json.json`, `output_json.data`, `output_text` containing JSON, top-level `output_text`, and a message-walk fallback.

**Anthropic:**
- Non-JSON body / HTTP ≥ 300 → `WP_Error`; `stop_reason: "refusal"` → `WP_Error`.
- The payload is the first `text` content block, JSON-decoded.

Either provider: missing/!array `issues` → `WP_Error` with a short snippet (full response is **not** stored, for privacy).

### 4.5 Report construction

The human-readable report is built **server-side from validated issues only** by the shared `build_report_from_issues()` (the model cannot inject commentary). For each issue with non-empty, differing `bad`/`good` and a valid `location`:

```
N. LOCATION: change 'bad' to 'good'
```

If no actionable issues remain, the report is the literal `"No obvious typos detected."`. Issue count is derived by `count_numbered_issues` (counts lines matching `^\d+[.)]\s`); a report containing "no obvious typos detected" forces count `0`.

---

## 5. Data model

### 5.1 Post meta

| Key | Meaning |
|---|---|
| `_ogm_eqa_report` | Numbered text report (or "No obvious typos detected.") |
| `_ogm_eqa_issue_count` | Integer issue count (used for the badge & sorting) |
| `_ogm_eqa_last_scanned_gmt` | Last scan time, `Y-m-d H:i:s` **GMT** |
| `_ogm_eqa_scanned_hash` | SHA-256 of last-scanned prepared text |
| `_ogm_eqa_model` | Model used for the last scan |
| `_ogm_eqa_error` | Last error message (cleared at the start of each scan) |
| `_ogm_eqa_last_trigger_user_id` | User who last triggered a scan |

Timestamps are stored in GMT and **displayed** converted to the site's configured timezone (`get_date_from_gmt`).

### 5.2 Settings (`ogm_eqa_settings` option, array)

| Key | Default | Notes |
|---|---|---|
| `provider` | `openai` | `openai` or `anthropic` — which API to scan with |
| `openai_api_key` | `''` | Overridden by `OGM_EQA_OPENAI_API_KEY` constant at runtime |
| `openai_model` | `gpt-4o-mini` | OpenAI model |
| `anthropic_api_key` | `''` | Overridden by `OGM_EQA_ANTHROPIC_API_KEY` constant at runtime |
| `anthropic_model` | `claude-haiku-4-5` | Anthropic model |
| `max_output_tokens` | `350` | Clamped to `MIN_TOKENS`–`MAX_TOKENS` (300–2000) consistently at save **and** call time |
| `post_types` | `['post']` | Which public post types are scanned/decorated |
| `strip_html` | `1` | Strip HTML before scanning |
| `scan_on_schedule` | `1` | Master switch for the schedule/update triggers |
| `scan_on_update_while_scheduled` | `1` | Rescan on future → future |
| `scan_on_publish` | `0` | Opt-in: also scan on transition to `publish` |

**Legacy migration:** installs from ≤ 1.3.0 stored a single `api_key` / `model`
(OpenAI). `settings()` maps these to `openai_api_key` / `openai_model` on read,
so upgrades are seamless.

Each provider's model is chosen from a dropdown populated by
`get_model_choices($provider)`: the cached list (or the provider's
`SEED_MODELS_*` seed list if never refreshed), always including the
currently-saved value. The cache is **per-provider**, in a separate option:

| Option | Shape |
|---|---|
| `ogm_eqa_models` | `['openai' => ['ids'=>string[],'updated'=>int], 'anthropic' => ['ids'=>string[],'updated'=>int]]` |

### 5.3 Constants

- `OGM_EQA_VERSION` — `'1.4.0'`; used as the asset cache-buster.
- `OGM_EQA_OPENAI_API_KEY` — optional `wp-config.php` constant; takes precedence over the stored OpenAI key.
- `OGM_EQA_ANTHROPIC_API_KEY` — optional `wp-config.php` constant; takes precedence over the stored Anthropic key.

### 5.4 Hooks / identifiers

- Cron/job hook: `ogm_eqa_scan_post` → `scan_post_job($post_id, $user_id, $force)`.
- Action Scheduler group: `ogm_editorial_qa`.
- AJAX action: `ogm_eqa_rescan` (nonce action `ogm_eqa_rescan`).
- admin-post action: `ogm_eqa_refresh_models` → `handle_refresh_models()` (nonce `ogm_eqa_refresh_models`, cap `manage_options`).

---

## 6. Admin UI surfaces

Both admin screens share a branded header (`render_admin_header()`): an icon "mark", title + tagline, version pill, and a two-tab nav (**Reports** / **Settings**), styled with the Orchard Grove Media design system in `ogm-eqa-admin.css` (white rounded cards, green accent `#5b8c3e`, green focus rings). The CSS declares `dashicons` as a dependency.

1. **Editorial QA page** (`admin.php?page=ogm-editorial-qa`, cap `edit_others_posts`) — branded header + an intro line + a card wrapping the `OGM_EQA_List_Table` (status views Scheduled / Drafts / Pending / Published / All via `eqa_status`, search, 20/page pagination, columns Title / Status / Date / Issues / Last scan / Actions). Scheduled view sorts ascending by date; others descending.
2. **Settings** (`page=ogm-editorial-qa-settings`, cap `manage_options`) — a card+sidebar layout. The **main card** holds the form: an **AI provider** selector, a per-provider section for **OpenAI** and for **Anthropic** (each via `render_provider_section()`: API key + model dropdown + **Refresh models** button), then max tokens, post types, and behavior toggles (including **Also scan on publish**). The **sidebar** (`render_settings_sidebar()`) shows an at-a-glance card (active provider / model / key-configured dot / auto-scan dot) and a links card (provider key pages + the wp-config-constant tip). Both provider sections are always shown; the selector decides which is used at scan time. A per-section note appears when that provider's key is supplied by its wp-config constant. Each **Refresh models** button re-targets the settings form at `admin-post.php` (via `formaction`, carrying `name="provider"`) to call `handle_refresh_models()`, which fetches that provider's `/v1/models` (`fetch_openai_models` / `fetch_anthropic_models`), ranks/dedupes/caps the result (≤12), caches it under `ogm_eqa_models[<provider>]`, and redirects back with an `eqa_models=ok|error` + `eqa_models_provider` notice. The fetch uses the resolved key (settings or wp-config constant). Clicking Refresh does **not** save other settings changes — Save Settings is separate.
3. **Meta box** "Editorial QA (Typos)" — registered on each enabled post type, side/high. Shows Clean/Issues badge, last-scan time (site tz), error (if any), the numbered list, and a Rescan button.
4. **Posts list "QA" column** — inserted after Title, sortable by `_ogm_eqa_issue_count`. Shows a numeric/`!`/`—` badge and an inline Rescan button.

Assets (`ogm-eqa-admin.js`, `ogm-eqa-admin.css`) load only on the plugin pages, post-edit screens, and `edit.php` for enabled post types. The JS wires `.ogm-eqa-rescan` clicks to the AJAX endpoint and reports status inline.

---

## 7. Security model

- **Capabilities:** viewing reports and rescanning require `edit_others_posts`; settings require `manage_options`.
- **AJAX:** capability check + nonce (`check_ajax_referer`) before queuing.
- **Output:** all dynamic output is escaped (`esc_html`/`esc_attr`/`esc_url`); the report is rebuilt from validated fields, never echoed raw from the model.
- **Secrets:** the API key can be kept entirely out of the database via the wp-config constant. Full API responses are never persisted; only short snippets accompany error messages.

---

## 8. Cost & performance

- One provider request per scan; no scan when the content hash is unchanged and the scan is not forced.
- Background execution (Action Scheduler preferred, WP-Cron fallback) keeps the editor responsive.
- BYOK: all usage bills to the site owner's account with the selected provider (OpenAI or Anthropic).

---

## 9. Known issues & roadmap

Tracked items not addressed in 1.5.0:

1. **`Tested up to`** header is a placeholder and should track the current WP release (set to whatever version the site actually runs).
2. **No retry on transport-level partials.** `request_json()` retries on transient HTTP statuses and `WP_Error` transport failures, but a 200 with a truncated/garbled body is treated as a parse error (stored, user Rescans). *Roadmap: optional re-request on parse failure.*

**Resolved in 1.5.0:**
- Uninstall cleanup — `uninstall.php` deletes both options and all `_ogm_eqa_*` post meta (was item 1).
- Dropped the unused `quote` schema field (was item 2).
- `max_output_tokens` bounds aligned to `MIN_TOKENS`–`MAX_TOKENS` (300–2000) at save and call (was item 3).
- Retry/backoff via `request_json()` (was item 4).
- Opt-in `scan_on_publish` trigger (was item 5).

**Resolved in 1.4.0:** dual-provider support (OpenAI **or** Anthropic).

---

## 10. File inventory

| File | Role |
|---|---|
| `editorial-qa.php` | All plugin logic (class, list table, hooks, OpenAI + Anthropic clients) |
| `uninstall.php` | Deletes options + post meta when the plugin is deleted |
| `ogm-eqa-admin.js` | Rescan button → AJAX wiring |
| `ogm-eqa-admin.css` | OGM design system: header/tabs/cards/sidebar, badges, meta-box list |
| `README.md` | User/installer documentation (WordPress-readme style) |
| `SPEC.md` | This document |
