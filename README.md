# Editorial QA (AI Typos)

**Contributors:** Orchard Grove Media, LLC
**Tags:** editorial, proofreading, typos, openai, anthropic, claude, ai, qa
**Requires at least:** 6.0
**Tested up to:** 6.5
**Requires PHP:** 8.0
**Stable tag:** 1.5.0
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

When a post transitions into the **scheduled** (`future`) status, and — if enabled — each time an already-scheduled post is updated. There's also an optional **Also scan on publish** setting that catches posts published directly (draft → publish) without being scheduled. With that off, use the **Rescan** button for directly-published posts.

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

### 1.5.0
Refreshed admin UI, an optional "scan on publish" trigger, uninstall cleanup, and automatic retry on transient provider errors. No reconfiguration required.

### 1.4.0
Adds Anthropic (Claude) as an alternative AI provider, selectable on the Settings page. Your existing OpenAI configuration is migrated automatically and remains the default.

### 1.3.0
Adds a refreshable model dropdown on the Settings page. Your existing model selection is preserved. No data migration required.

### 1.2.0
Maintenance release: correct asset versioning, site-timezone timestamps, strict structured output, and removal of the runtime asset-writer. No settings or data migration required.
