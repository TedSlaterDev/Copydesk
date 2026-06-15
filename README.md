# Editorial QA (AI Typos)

**Contributors:** Orchard Grove Media, LLC
**Tags:** editorial, proofreading, typos, openai, ai, qa
**Requires at least:** 6.0
**Tested up to:** 6.5
**Requires PHP:** 8.0
**Stable tag:** 1.2.0
**License:** GPL-2.0-or-later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Automatically scans posts for obvious, objective typos using OpenAI and surfaces the results inside wp-admin — no copy/paste into a chatbot required.

## Description

Editorial QA is a lightweight proofreading assistant for WordPress. When a post is **scheduled** (and, optionally, each time a scheduled post is re-saved), the plugin sends the title and body to OpenAI and asks for a tightly-scoped list of *objective* technical typos. Results appear as a badge and a numbered list in three places: a dedicated admin page, the post editor's side meta box, and a sortable "QA" column on the Posts list.

It is intentionally conservative. It flags **only**:

- **Misspellings**
- **Duplicated words** (e.g. "the the")
- **Broken capitalization** (e.g. "thE", random mid-word caps) — it does *not* enforce title case or headline style
- **Broken punctuation** (e.g. "??", "!!", "..")

It deliberately **ignores**:

- Quotation marks and apostrophes (curly or straight)
- Tone, style, grammar preferences, and political/editorial judgment
- Anything inside `<blockquote>` (quoted source material is removed before scanning, so you don't get "corrections" to other people's words)

The model is constrained with an OpenAI structured-output (JSON schema, strict mode) contract, and the human-readable report is rebuilt server-side from the validated results only — the model cannot inject free-form commentary into what editors see.

### Where results show up

1. **Editorial QA admin page** — a filterable table (Scheduled / Drafts / Pending / Published / All) with issue counts, last-scan time, and per-row Rescan/View actions.
2. **Post editor meta box** ("Editorial QA (Typos)") — a Clean/Issues badge, the numbered typo list, last-scan time, and a Rescan button.
3. **Posts list "QA" column** — an at-a-glance badge per post, sortable by issue count, with an inline Rescan button.

### Bring your own key (BYOK)

Editorial QA uses **your** OpenAI API key and bills to **your** OpenAI account. Each scan is a single API request. Unchanged content is hashed and skipped, so re-saving a post that hasn't changed does not spend tokens.

## Installation

1. Upload the `editorial-qa` folder to `/wp-content/plugins/`, or install the ZIP via **Plugins → Add New → Upload**.
2. Activate **Editorial QA (AI Typos)**.
3. Go to **Editorial QA → Settings** and provide your OpenAI API key (see below), choose a model, and select which post types to scan.

### Providing the API key

You have two options:

- **Settings field** — paste the key into **Editorial QA → Settings**. It is stored in `wp_options`.
- **wp-config.php constant (recommended)** — keep the secret out of the database:

  ```php
  define('OGM_EQA_OPENAI_API_KEY', 'sk-...');
  ```

  When this constant is defined, it takes precedence and the settings field is ignored at runtime.

### Recommended environment

For reliable background processing, install [Action Scheduler](https://actionscheduler.org/) (bundled with WooCommerce and many other plugins). If it is present, scans run through it; otherwise the plugin falls back to WP-Cron.

## Frequently Asked Questions

### When does a scan run automatically?

When a post transitions into the **scheduled** (`future`) status, and — if enabled — each time an already-scheduled post is updated. A post published directly (draft → publish) without being scheduled is **not** scanned automatically; use the **Rescan** button for those.

### Does it change my content?

No. Editorial QA never edits your posts. It only reports suspected typos for a human to act on.

### Why did it flag something that isn't a typo?

AI is not perfect. The meta box notes this explicitly. Treat the list as suggestions, not corrections.

### Does it cost money?

Yes — it uses your OpenAI API key and bills to your account. Costs depend on the model and post length. Unchanged content is skipped via a content hash, so you are not charged to re-scan posts that haven't changed.

### Which model should I use?

The default is `gpt-4o-mini`, which is inexpensive and accurate enough for typo detection. Any model that supports the OpenAI Responses API with strict structured outputs will work.

### Where is my data sent?

The post title and body (with shortcodes, blockquotes, and HTML removed) are sent to the OpenAI API. Nothing else leaves your site.

## Screenshots

1. The Editorial QA admin page with status filters and per-post issue counts.
2. The post editor meta box showing a typo report.
3. The sortable "QA" column on the Posts list.

## Changelog

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

### 1.2.0
Maintenance release: correct asset versioning, site-timezone timestamps, strict structured output, and removal of the runtime asset-writer. No settings or data migration required.
