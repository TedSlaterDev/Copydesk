# Editorial QA (AI Typos)

**Contributors:** Orchard Grove Media, LLC
**Tags:** editorial, proofreading, typos, openai, anthropic, claude, ai, qa
**Requires at least:** 6.0
**Tested up to:** 6.5
**Requires PHP:** 8.0
**Stable tag:** 1.12.0
**License:** GPL-2.0-or-later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Automatically scans posts for obvious, objective typos using OpenAI or Anthropic (Claude) and surfaces the results inside wp-admin — no copy/paste into a chatbot required.

## Description

Editorial QA is a lightweight proofreading assistant for WordPress. When a post is **scheduled** (and, optionally, each time a scheduled post is re-saved), the plugin sends the title and body to your chosen AI provider — **OpenAI** or **Anthropic (Claude)** — and asks for a tightly-scoped list of *objective* technical typos. Results appear as a badge and a numbered list in three places: a dedicated admin page, the post editor's side meta box, and a sortable "QA" column on the Posts list.

It is intentionally conservative. It flags **only**:

- **Misspellings**
- **Duplicated words** (e.g. "the the")
- **Broken capitalization** (e.g. "thE", random mid-word caps) — it does *not* enforce title case or headline style
- **Broken punctuation** (e.g. "??", "!!", "..")

It deliberately **ignores**:

- Quotation marks and apostrophes (curly or straight)
- Tone, style, grammar preferences, and political/editorial judgment
- Anything inside `<blockquote>` (quoted source material is removed before scanning, so you don't get "corrections" to other people's words)

The model is constrained with a structured-output (JSON schema) contract — OpenAI's strict Responses-API format or Anthropic's `output_config.format` — and the human-readable report is rebuilt server-side from the validated results only, so the model cannot inject free-form commentary into what editors see.

### Where results show up

1. **Editorial QA admin page** — a filterable table (Scheduled / Drafts / Pending / Published / All) with issue counts, last-scan time, and per-row Rescan/View actions.
2. **Post editor meta box** ("Editorial QA (Typos)") — a Clean/Issues badge, the numbered typo list, last-scan time, and a Rescan button.
3. **Posts list "QA" column** — an at-a-glance badge per post, sortable by issue count, with an inline Rescan button.

### Bring your own key (BYOK)

Editorial QA uses **your** OpenAI or Anthropic API key and bills to **your** account with that provider. Each scan is a single API request. Unchanged content is hashed and skipped, so re-saving a post that hasn't changed does not spend tokens.

## Installation

1. Upload the `editorial-qa` folder to `/wp-content/plugins/`, or install the ZIP via **Plugins → Add New → Upload**.
2. Activate **Editorial QA (AI Typos)**.
3. Go to **Editorial QA → Settings**, choose your **AI provider** (OpenAI or Anthropic), provide that provider's API key (see below), choose a model, and select which post types to scan.

### Providing the API key

Each provider has its own key field and its own optional wp-config constant. You only need to configure the provider you select.

- **Settings field** — paste the key into the matching field on **Editorial QA → Settings**. It is stored in `wp_options`.
- **wp-config.php constant (recommended)** — keep the secret out of the database:

  ```php
  define('OGM_EQA_OPENAI_API_KEY', 'sk-...');        // OpenAI
  define('OGM_EQA_ANTHROPIC_API_KEY', 'sk-ant-...'); // Anthropic
  ```

  When a constant is defined, it takes precedence and the matching settings field is ignored at runtime.

### Recommended environment

For reliable background processing, install [Action Scheduler](https://actionscheduler.org/) (bundled with WooCommerce and many other plugins). If it is present, scans run through it; otherwise the plugin falls back to WP-Cron.

## Frequently Asked Questions

### When does a scan run automatically?

You choose. On the Settings page, the **Scan triggers** section lists every post status registered on your site — Draft, Pending review, Scheduled, Published, Private, plus any custom editorial-workflow statuses (e.g. "Approved"). A post is scanned whenever it **enters** one of the statuses you've ticked. The default is just **Scheduled**.

Optionally enable **re-scan on update** to also scan when an already-eligible post is edited and saved again. Either way, unchanged content is skipped via a content hash, and the **Rescan** button always works on demand.

### What is "Max output tokens"?

It caps how long the AI's reply can be for a **single** scan — i.e. how many typos it can list for one post. It is **not** a daily or monthly budget or a cost cap over time. The default (350) comfortably holds dozens of findings; only raise it if a very long post has its typo list cut off.

### Does it change my content?

No. Editorial QA never edits your posts. It only reports suspected typos for a human to act on.

### Why did it flag something that isn't a typo?

AI is not perfect. The meta box notes this explicitly. Treat the list as suggestions, not corrections.

### Does it cost money?

Yes — it uses your OpenAI or Anthropic API key and bills to your account with that provider. Costs depend on the model and post length. Unchanged content is skipped via a content hash, so you are not charged to re-scan posts that haven't changed.

### Which provider/model should I use?

Pick whichever provider you already have a key for — typo detection is an easy task and any inexpensive current model handles it well.

- **OpenAI** default is `gpt-4o-mini` (Responses API + strict structured outputs).
- **Anthropic** default is `claude-haiku-4-5` (Messages API + structured outputs) — Claude's cheapest current model, ideal for this task.

Each provider's Model setting is a dropdown with its own **Refresh models** button: click it to populate the list from the models available on your account for that provider (save the matching API key first). Your previously-selected model always stays selectable.

### Where is my data sent?

The post title and body (with shortcodes, blockquotes, and HTML removed) are sent to the API of the provider you selected — OpenAI or Anthropic. Nothing else leaves your site.

## Screenshots

1. The Editorial QA admin page with status filters and per-post issue counts.
2. The post editor meta box showing a typo report.
3. The sortable "QA" column on the Posts list.

## Changelog

### 1.12.0
- **Truncation is now detected and explained.** When a scan hits the Max output tokens limit (both providers), you get an actionable message pointing at the setting instead of a cryptic "could not be parsed" error — and the tokens spent still land in the Costs ledger.
- **Reasoning models handled properly.** OpenAI o-series/GPT-5-family scans now run at low reasoning effort (typo scanning doesn't need deep reasoning), so the token budget goes to the report; other OpenAI models run at temperature 0 for deterministic results. Default Max output tokens raised to 1000 for new installs.
- **Fewer false positives from formatting:** table cells no longer glue into fake misspellings; classic-editor `[caption]` text is now scanned; nested blockquotes are fully removed; soft hyphens and other invisible characters are stripped; over-long posts are cut at a word boundary; NBSPs fold into plain spaces.
- **Fewer dropped real findings:** the verbatim guard now tolerates curly-vs-straight quote/dash differences, and TITLE/BODY mislabels are corrected instead of dropped. Fully-uppercase words (emphasis, headings) are never case-"fixed".
- Stronger default prompt: rules for quoted speech, numbers, word-choice (their/there is out of scope), and truncated-input tails.

### 1.11.0
- **Better report quality.** Identical findings are now de-duplicated (models sometimes return the same typo twice), and a new verbatim-presence guard drops any finding whose flagged text doesn't actually appear in the post — the main source of "just wrong" reports.
- **Editable scan instructions.** Settings → Advanced now shows the exact prompt sent to the AI with every scan. Edit it to tune what gets flagged (with a one-click Reset to default); while unedited, plugin updates keep improving the built-in prompt automatically.
- The default prompt is stronger: the AI must copy flagged text verbatim, report each distinct issue exactly once, and skip anything it isn't confident about.

### 1.10.1
- **Fixed Live Rescan** getting stuck on "Still working — reload to check." The manual Rescan now runs the scan **in-request** and returns the result immediately, instead of waiting on WP‑Cron (which many hosts don't fire promptly). You now always get the result — or a clear error — right away.

### 1.10.0
- **Live Rescan** — clicking Rescan now shows a "Scanning…" state and updates the badge/report in place when the scan lands, no manual reload. This adds **no** usage cost: the scan is the same single API call, and the live update is the browser polling WordPress for the stored result (it never calls the AI provider).
- **Costs tab** — a new Costs screen shows estimated AI spend (month-to-date, last-30-days, avg/scan), a daily-spend chart, and a per-model breakdown, computed from token usage recorded on each scan. Estimates use published list prices (filter `ogm_eqa_model_prices`) — close to, not identical to, your invoice.
- **Monthly budget cap** (optional) — set a dollar cap in Settings → Advanced; scans pause for the rest of the UTC month once estimated spend reaches it.

### 1.9.0
Security & cost hardening:
- **Per-post permission check** — Rescan now requires edit rights on that specific post, not just the broad capability.
- **Less content leaves your site** — password-protected posts are never scanned; HTML comments (incl. block-editor markup) and URL/tracking attributes are stripped; and the body sent per scan is capped (filter `ogm_eqa_max_input_chars`, default 20,000).
- **No raw responses stored** — parse-failure messages are now generic; verbose provider output is logged only when `WP_DEBUG` is on.
- **Fewer duplicate jobs** — the Action Scheduler path skips an already-queued identical scan.

### 1.8.1
- **Security:** an API key supplied via a `wp-config.php` constant is no longer echoed into the settings field or written back into the database — the key field is shown empty and disabled when a constant provides it.
- Fixed a no-JavaScript edge case: both providers' key/model fields now remain reachable when JavaScript is disabled.

### 1.8.0
- Settings are now grouped into panels — **AI provider**, **When to scan**, and **Advanced** — matching the other Orchard Grove Media plugins.
- Only the **selected provider's** key and model fields are shown; the other provider's fields are hidden (but stay saved), and the view updates instantly when you switch the provider dropdown.

### 1.7.2
- Restyled the meta-box "AI isn't perfect" note as a calmer info note (info icon, lighter type) instead of the amber warning box.

### 1.7.1
- **Fewer capitalization false positives.** The scanner no longer suggests lowercasing the first letter of a word, so correctly-capitalized proper nouns, acronyms, and headings (e.g. "White House", "WATCH:") are left alone — both via a stricter prompt and a server-side guard.
- **Better structure handling.** Embedded `<script>`/`<style>` (e.g. video players) are removed before scanning, and paragraph/heading line breaks are preserved, so separate blocks like a "WATCH:" label and the next paragraph are no longer glued into one line (which used to read as a false capitalization error).

### 1.7.0
- **Refresh models now runs over AJAX** — the dropdown updates in place, errors show inline (never a blank page), and pressing Enter in a field saves settings instead of accidentally triggering a refresh.
- Added a **"Create a key"** link beside each provider's API-key field (platform.openai.com / console.anthropic.com).
- The Posts-list column is now labeled **Issues** (it shows each post's issue count and is toggleable from **Screen Options**).

### 1.6.0
- **Configurable scan triggers.** Instead of only scanning scheduled posts, you can now pick any post status(es) that should trigger a scan — Draft, Pending review, Scheduled, Published, Private, or any custom editorial-workflow status registered on your site. Existing settings are migrated automatically (Scheduled stays on by default).
- The **Refresh models** button now shows the exact reason on screen when it fails (missing key, HTTP 401, connectivity, …) instead of a generic/blank error.
- Clarified the **Max output tokens** field with inline help — it's the per-scan reply length, not a spending budget.

### 1.5.0
- **Refreshed admin design.** Both Editorial QA screens now share a branded header with Reports/Settings tabs, a card-based layout, a settings sidebar (at-a-glance status + key links), and the Orchard Grove Media visual style.
- **New "Also scan on publish" option** — optionally scan posts that are published directly, without being scheduled first.
- **Cleaner uninstall** — deleting the plugin now removes its options and post meta (`uninstall.php`).
- **Automatic retry/backoff** on transient provider errors (rate limits, 5xx, overloaded) before surfacing an error.
- Aligned the Max-output-tokens floor (300) between the settings field and the API calls, and dropped an unused schema field to trim each request slightly.

### 1.4.0
- **Added Anthropic (Claude) support.** A new AI-provider selector lets you scan with either OpenAI or Anthropic. The Anthropic path uses the Messages API with structured outputs and defaults to `claude-haiku-4-5`.
- Each provider has its own API-key field, model dropdown, **Refresh models** button, and optional wp-config constant (`OGM_EQA_OPENAI_API_KEY` / `OGM_EQA_ANTHROPIC_API_KEY`).
- Existing OpenAI key/model settings from 1.3.0 are migrated automatically — no reconfiguration needed.

### 1.3.0
- The Model setting is now a dropdown with a **Refresh models** button that queries your OpenAI account's `/v1/models`, filters to chat-capable models, ranks them by capability, and caches the list. The previously-saved model always remains selectable.

### 1.2.0
- Added an `OGM_EQA_VERSION` constant; admin JS/CSS are now versioned from it (previously the script was pinned to a stale `1.0.0`).
- Scan timestamps now display in the site's configured timezone everywhere (meta box and admin table), instead of a hardcoded `America/Chicago`. The admin column is relabeled "Last scan".
- Structured output now uses OpenAI strict mode (`strict: true`) for reliable schema adherence.
- Removed the runtime "self-writing" JS/CSS block; the shipped asset files are now the single source of truth.
- Moved the meta-box list styling out of inline `<style>` and into `ogm-eqa-admin.css`.
- Fixed the plugin `Text Domain` (was `heirloom-seo`).

### 1.1.1
- Structured-output typo scanning via the OpenAI Responses API; admin page, meta box, and Posts list column.

## Upgrade Notice

### 1.10.0
Adds Live Rescan (in-place results, no extra usage cost), a Costs tab, and an optional monthly budget cap. Creates one small database table (`{prefix}ogm_eqa_costs`) for the cost ledger; it's removed on uninstall.

### 1.7.1
Reduces capitalization false positives (proper nouns, headings) and stops embedded scripts / lost line breaks from creating bogus "issues". No settings change required.

### 1.7.0
Fixes the "Refresh models" button (now AJAX-based, with inline errors), adds per-provider key-creation links, and labels the Posts-list column "Issues". No settings migration required.

### 1.6.0
Scan triggers are now per-status and configurable. Your previous schedule/publish settings are migrated automatically, so behavior is unchanged until you adjust them. Also fixes the "Refresh models" error to show a real message.

### 1.5.0
Refreshed admin UI, an optional "scan on publish" trigger, uninstall cleanup, and automatic retry on transient provider errors. No reconfiguration required.

### 1.4.0
Adds Anthropic (Claude) as an alternative AI provider, selectable on the Settings page. Your existing OpenAI configuration is migrated automatically and remains the default.

### 1.3.0
Adds a refreshable model dropdown on the Settings page. Your existing model selection is preserved. No data migration required.

### 1.2.0
Maintenance release: correct asset versioning, site-timezone timestamps, strict structured output, and removal of the runtime asset-writer. No settings or data migration required.
