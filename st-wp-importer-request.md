# ST WP Importer — Agent Instructions

This document is the **prompt-ready plan/workflow** for an agent (Codex) to implement a temporary WordPress plugin importer:

- **Source**: WordPress **Multisite**, but you will import only **Site 1** (main site).
- **Destination**: WordPress (single site).
- **Importer**: Temporary WP plugin running in destination WP admin context + WP Cron runner + manual “Run 1 batch now”.
- **Goal**: Import selected post types + their media (featured + in content/meta), preserving uploads folder structure (`uploads/YYYY/MM/`), while logging everything and maintaining a **source→destination ID map**.

> **Hard constraints**
> - Must use **WordPress APIs** for inserting posts/terms/media (no direct writes to destination DB).
> - Must be **batch-based** and **interruptible** (stop flag checked each batch).
> - Must be **idempotent** (safe to re-run without duplicating).
> - Must log verbosely and persistently.
> - Must assume **WP Cron** (no real system cron available locally).

---

## 0) What the agent must do first (analysis tasks)

### Task 1 — Analyze the plugin boilerplate
Inspect the existing boilerplate at:

- `wp-content/plugins/st-wp-importer/`

Write a short report with:

- Entry plugin file(s), namespaces/autoloading pattern (if any)
- Existing admin menu page patterns
- Existing settings storage patterns
- Any existing helper classes (logger, db, etc.)
- Any build tooling (composer, npm), and whether it should be ignored for speed

**Output**: `wp-content/plugins/st-wp-importer/agent-notes/boilerplate-analysis.md`

### Task 2 — Create the plugin dashboard + settings + runtime controls
#### 2.1 Admin page
Add a WP admin page (top-level menu or Tools submenu) with capability manage_options.

#### 2.2 Settings storage
Implement stwi_settings option (single array) and validation/sanitization for:
- source DB host / name / user / password / port
- source site URL
- source table prefix (default wp_)
- source blog id (default 1)
- post types CSV
- posts_per_run
- run_interval_minutes
- dry_run (optional, but recommended)

#### 2.3 Connection tester
Add “Test Source DB Connection” button:
- Creates external wpdb
- Runs a safe query like SELECT COUNT(*) FROM wp_posts
- Shows success/error in the UI and logs it

#### 2.4 Runtime state + controls
Implement stwi_state option (running/stop/cursor/stats) and add buttons:
- Start Import: sets running=true, stop_requested=false, schedules WP-Cron event
- Run 1 Batch Now: triggers the batch runner once via AJAX (useful locally)
- Stop Import: sets stop_requested=true immediately (cron runner checks this at batch start)

#### 2.5 Logs panel
Add a log viewer area (tail last ~100 lines from log file).

### Task 3 — Analyze the source theme for CPTs and “import-relevant” metadata
Inspect:

- `wp-content/themes/gpstrategies-2023/`

Find and note:

- All `register_post_type()` calls (CPT slugs)
- Any custom taxonomies (`register_taxonomy()`)
- Any special post statuses
- Any custom meta keys or data patterns that affect rendering (Yoast, ACF, etc.)
- Any custom content patterns for images (blocks/HTML)

**Output**: `wp-content/plugins/st-wp-importer/agent-notes/theme-analysis.md`

> **Note**: This importer is “temporary”, but the CPT list must match reality to avoid missing or mis-importing.

---

## 1) Import scope (what to import)

### Exclude
- `page` (always excluded)

### Include (from dashboard CSV)
These are the types you want to import for this project:

- `post`
- `solutions-cpt`
- `events-cpt`
- `news-cpt`
- `podcasts-cpt`
- `webinars-cpt`
- `resource`
- `case-study-cpt`
- `training-course`
- `acab-cpt`

Dashboard field is comma-separated. Normalize inputs:
- If user types `posts`, convert to `post`.
- Trim whitespace, `sanitize_key()` each type.

### Plugin MetaData
We will add to this near the end, as we analyze what we have and what we are missing. But basically, the source-site has plugins like "YoastSEO", "Permalink Manager Pro", "Redirect", etc. All these plugin data needs to be imported.

The workflow will be:
- Check Plugin requirement on source
- Fresh-install plugin on current import site
- Analyze fields on source
- update st-wp-importer plugin code to also call those data from source_db into importer site

The plugins will be added through checkbox, to enable/disable them.
For now, add option for "YoastSEO" - which we will definitely need to import the data for.


---

## 2) Source is multisite — but only Site 1 is used

### Source DB prefix and blog
- `source_table_prefix`: `wp_`
- `source_blog_id`: `1`

For blog_id = 1, there is **no extra blog table prefix** (you said it’s standard), so:

- posts table: `wp_posts`
- postmeta table: `wp_postmeta`
- terms: `wp_terms`
- term taxonomy: `wp_term_taxonomy`
- term rel: `wp_term_relationships`

Still implement the prefix/blog logic (tiny effort, avoids future confusion), but default to blog 1 behavior.

---

## 3) Settings, state, mapping (data model)

### 3.1 Settings option
Store all settings in **one** options row:

- `stwi_settings` (array):
    - `source_db_host` (default: `localhost`)
    - `source_db_port` (default: `3306`)
    - `source_db_name` (default: `gpstrategies-source`)
    - `source_db_user` (default: `root`)
    - `source_db_pass` (default: `` empty)
    - `source_site_url` (default: `https://www.gpstrategies.com/`)
    - `source_table_prefix` (default: `wp_`)
    - `source_blog_id` (default: `1`)
    - `post_types_csv` (default: the list above joined by comma)
    - `posts_per_run` (default: `5`)
    - `run_interval_minutes` (default: `1`)
    - `dry_run` (default: `0` or `false`)

Validation:
- URL: `esc_url_raw`
- ints: `absint`
- CSV: split → trim → normalize → `sanitize_key`
- password: store as-is (temporary plugin), but do not print it back in UI

### 3.2 Runtime state option
Store running state in one option:

- `stwi_state` (array):
    - `running` (bool)
    - `stop_requested` (bool)
    - `last_run_at` (unix timestamp)
    - `next_run_at` (unix timestamp, optional)
    - `active_post_types` (array)
    - `cursor` (dict: `{ post_type: last_source_id }`)
    - `stats` (counts: posts imported/updated/skipped, attachments imported/skipped, errors)
    - `last_error` (string, optional)

### 3.3 Mapping table (recommended)
Create a small destination table to map old→new IDs:

Table: `{$wpdb->prefix}stwi_map`
Note that plugin is already installed and activated. Make sure the table is created / checked correctly. Maybe I can "deactivate" and "reactivate" plugin, so the change to configure database with the new table "runs".

Columns:
- `id` BIGINT unsigned PK auto_increment
- `source_blog_id` INT not null
- `source_object_type` VARCHAR(32) not null  *(post|attachment|term)*
- `source_id` BIGINT unsigned not null
- `dest_id` BIGINT unsigned not null
- `created_at` DATETIME
- `updated_at` DATETIME

Indexes:
- UNIQUE KEY (`source_blog_id`, `source_object_type`, `source_id`)
- KEY (`dest_id`)

> This makes the import **idempotent** and enables reliable block ID rewriting.

---

## 4) Plugin structure (files/classes)

Adapt to your boilerplate; suggested structure:

- `st-wp-importer.php` *(bootstrap)*
- `includes/`
    - `class-stwi-admin.php` *(menu page, forms, AJAX actions)*
    - `class-stwi-settings.php` *(get/set/validate settings)*
    - `class-stwi-logger.php` *(file logger + tail reader)*
    - `class-stwi-source-db.php` *(external WPDB reader)*
    - `class-stwi-importer.php` *(batch orchestration)*
    - `class-stwi-media.php` *(attachment import, upload_dir override)*
    - `class-stwi-content.php` *(content parsing + media rewriting)*
    - `class-stwi-cron.php` *(schedule + runner)*
    - `class-stwi-map.php` *(mapping table CRUD)*
- `admin/`
    - `views/page-dashboard.php`
    - `assets/admin.js` *(Start/Stop/Run Batch + polling status)*
- `agent-notes/` *(analysis outputs mentioned above)*

Adapt new files as required to the existing boilerplate of `st-wp-importer` which has been generated using standard WordPress requirements.

---

## 5) Admin dashboard (UI requirements)

Create a WP admin dashboard page (either under **Tools** or top-level; either is fine).

### 5.1 Settings form fields
- Source DB Host
- Source DB Name
- Source DB Username
- Source DB Password
- Source DB Port
- Source Site URL
- Source Table Prefix (default `wp_`)
- Source Blog ID (default `1`)
- Post Types (CSV)
- Posts per run (default `5`)
- Run interval (minutes) (default `1`)
- Dry run (checkbox)

Include a “Test connection” button:
- Connect to source DB and run a simple query: `SELECT COUNT(*) FROM wp_posts`
- Show success/error message.

### 5.2 Controls
- **Start Import**:
    - sets `stwi_state.running = true`
    - sets `stwi_state.stop_requested = false`
    - initializes cursor for all active post types if missing
    - schedules WP Cron event
- **Run 1 batch now**:
    - runs one batch immediately via AJAX handler
    - respects `stop_requested`
- **Stop Import**:
    - sets `stop_requested = true` immediately
    - does not rely on clearing cron to stop (cron clearing is optional)

### 5.3 Status panel
Show:
- Running / Stopped
- Stop requested: Yes/No
- Last run time
- Next run (if known)
- Cursor per post type
- Stats (posts imported/updated/skipped, attachments imported/skipped, errors)
- **Log tail**: last ~100 lines

---

## 6) Cron strategy (WP native cron)

Implement WP Cron schedule based on `run_interval_minutes`.

- Add custom schedule via `cron_schedules` filter:
    - name: `stwi_every_{N}_minutes`
    - interval: `N * 60`

Hook:
- `stwi_run_batch`

On Start:
- schedule event if not already scheduled: `wp_schedule_event(time()+10, schedule_name, 'stwi_run_batch')`

On Stop:
- set stop flag; optionally also `wp_clear_scheduled_hook('stwi_run_batch')`

> **Important**: Since WP Cron is traffic-driven, the manual “Run 1 batch” button matters locally.

---

## 7) Batch importer logic (core)

### 7.1 Hard stop check (interrupt safety)
At the very beginning of every batch:
- Load `stwi_state`
- If `stop_requested === true`:
    - set `running=false`
    - log “Stop requested — halting”
    - return immediately

Also check stop flag **between posts** if needed.

### 7.2 Selecting posts (source DB query)
For each batch, process **up to `posts_per_run` total** across all active post types.

Round-robin / fair strategy:
- Maintain a pointer or just iterate post types in order each run
- For each type, fetch remaining count using cursor

Query per post type:
- `SELECT * FROM wp_posts WHERE post_type = %s AND ID > %d AND post_status NOT IN ('auto-draft') ORDER BY ID ASC LIMIT %d`

> Always exclude `page` regardless of settings.

Update cursor:
- After each successful import, set cursor[post_type] = source_post.ID

### 7.3 Upsert behavior (idempotent)
For each source post:
- Look up mapping table: (`source_object_type='post'`, `source_id=ID`)
- If exists:
    - update destination post using `wp_update_post()`
- Else:
    - create destination post using `wp_insert_post()`
    - insert mapping entry

Always store these meta on destination post:
- `_stwi_source_blog_id`
- `_stwi_source_post_id`
- `_stwi_source_post_type`

Log mapping:
- `old_post_id => new_post_id`

### 7.4 Taxonomies (recommended but can be Phase 2)
If implementing now:
- Pull term relationships from source:
    - join `wp_term_relationships` + `wp_term_taxonomy` + `wp_terms`
- Ensure terms exist on destination by slug + taxonomy
- Assign using `wp_set_object_terms()`
- Map terms in mapping table if desired (optional for rush)

---

## 8) Media importer (Featured + content)

### 8.1 Featured image import
From source `wp_postmeta`:
- key: `_thumbnail_id` → source attachment ID

Then import attachment:
- `old_attachment_id => new_attachment_id`
- call `set_post_thumbnail($dest_post_id, $new_attachment_id)`

Log:
- old thumbnail id, new thumbnail id

### 8.2 Attachment import (stable URL construction)

**Preferred**: Use `_wp_attached_file` meta when present:

- source meta key: `_wp_attached_file` → e.g. `2025/12/Diagram-Blog-....jpg`
- Construct URL:
    - `{source_site_url}/wp-content/uploads/{attached_file}`

Fallback order:
1) `_wp_attached_file`
2) `guid` if it contains `/wp-content/uploads/`
3) last resort: skip and log warning

Do not use the `url` field that redirects.

### 8.3 Download + sideload into matching `uploads/YYYY/MM`
Download using WP native:
- `download_url($source_url)`

Sideload using:
- `media_handle_sideload($file_array, 0)` *(or attach to post ID if desired)*

**Preserve original subdir (`YYYY/MM`)**:
- Temporarily filter `upload_dir` for this single import so that:
    - `subdir = '/YYYY/MM'` based on the `_wp_attached_file` path
- Remove the filter after sideload.

Store mapping:
- in `stwi_map` with `source_object_type='attachment'`

Also store meta on destination attachment:
- `_stwi_source_attachment_id`
- `_stwi_source_attached_file` (optional)

Log mapping:
- `old attachment_id => new attachment_id`
- `source_url => dest_file_path`

### 8.4 Content rewriting (blocks + HTML)
Goal: ensure images render on destination and block IDs are updated.

#### Step A — Parse blocks if possible
Use:
- `parse_blocks($content)` → recursive walk

For blocks like `core/image`, `core/gallery`, `core/media-text`:
- if `attrs['id']` exists and is a numeric old attachment ID:
    - import that attachment (if not already mapped)
    - replace `attrs['id']` with new attachment ID

Also update common patterns:
- class `wp-image-OLD` → `wp-image-NEW`

Serialize back:
- `serialize_blocks($blocks)`

#### Step B — HTML `<img>` fallback
For any `<img src="https://www.gpstrategies.com/wp-content/uploads/YYYY/MM/file.jpg">`:
- Extract the uploads-relative path `YYYY/MM/file.jpg`
- Download + sideload if not present
- Replace src with destination URL (from `wp_get_attachment_url()` if mapped, otherwise build from uploads)

#### Step C — JSON comment fallback (targeted regex)
For Gutenberg comments like:
- `<!-- wp:image {"id":68319, ...} -->`

Replace `"id":68319` with `"id":NEWID` **only inside wp:image (and similar) comment JSON**, not globally.

> If parsing/serialization is too risky for “rush mode”, implement regex-based replacements with strict patterns and heavy logging.

---

## 9) Logging (must be extremely verbose)

### 9.1 File logger
Implement a logger that writes to:

- `wp-content/uploads/stwi-logs/stwi.log`

Format:
- `[STWI] [LEVEL] [context] message`

### 9.2 Log the mapping explicitly
Every mapping creation must be logged:

- Posts:
    - `old post_id => new post_id`
- Attachments:
    - `old attachment_id => new attachment_id`
- (Optional) Terms:
    - `old term_id => new term_id`

### 9.3 Admin log tail
Dashboard should show last 100 lines (tail), with a “Refresh logs” button or auto-refresh.

---

## 10) Safety and performance defaults

- `posts_per_run`: default 5
- `run_interval_minutes`: default 1
- Use short DB queries, limit results, avoid huge joins if possible
- Always check stop flag between posts and before any bulk media import loop
- Implement `dry_run` early:
    - If enabled: do everything except `wp_insert_post`, `wp_update_post`, `media_handle_sideload`, and mapping inserts
    - Still log what WOULD happen

---

## 11) “Definition of Done” checklist

### Admin
- [ ] Dashboard page exists, saves settings
- [ ] Test connection works
- [ ] Start Import sets running + schedules cron
- [ ] Stop sets stop flag immediately
- [ ] Run 1 batch now executes one batch without timeouts
- [ ] Status panel displays state/cursors/stats
- [ ] Logs tail shows last lines

### Import
- [ ] Imports selected post types (excluding pages)
- [ ] Creates mapping rows for posts
- [ ] Imports featured images correctly
- [ ] Imports content images (block IDs and/or HTML src)
- [ ] Preserves `uploads/YYYY/MM` structure for sideloaded media
- [ ] Idempotent reruns do not duplicate content
- [ ] Stop flag halts within next batch

---

## 12) Implementation notes (agent guidance)

### Use WP APIs on destination
- Posts: `wp_insert_post()`, `wp_update_post()`
- Meta: `update_post_meta()`
- Terms: `wp_insert_term()`, `wp_set_object_terms()`
- Media: `download_url()`, `media_handle_sideload()`, `wp_get_attachment_url()`
- Upload dir override: filter `upload_dir` temporarily

### External DB
Use a separate `wpdb` instance with source credentials:
- Ensure errors are logged clearly
- Never write to source DB

### Keep it “temporary”, but keep it reliable
This plugin can be ugly internally, but must be:
- resumable
- interruptible
- logged
- safe to re-run

---

## 13) Quick-start defaults (your local setup)

Set default settings on plugin activation (only if option not present):

- Host: `localhost`
- DB: `gpstrategies-source`
- User: `root`
- Pass: `` (empty)
- Port: `3306`
- Source URL: `https://www.gpstrategies.com/`
- Prefix: `wp_`
- Blog ID: `1`
- Post types: `post, solutions-cpt, events-cpt, news-cpt, podcasts-cpt, webinars-cpt, resource, case-study-cpt, training-course, acab-cpt`
- Posts per run: `5`
- Interval: `1`
- Dry run: `1` *(recommended for first run)*

---

## 14) Codex execution order (must follow)

1) Run Task 1 + Task 3 analyses and write the two markdown notes.
2) Implement mapping table on activation.
3) Implement logger + dashboard + settings.
4) Implement cron runner + start/stop + run-now.
5) Implement source DB connector + post importer (no media yet).
6) Implement featured image importer.
7) Implement content media importer + rewriting.
8) Add dry-run checks across all write operations.
9) Final pass: heavy logging everywhere and UI status.

---

## Appendix — Why attachment URL redirects are ignored

Some attachment “URL” fields redirect to post-like pages and are not reliable for downloading the raw file.

We will always build the raw URL from:

- `_wp_attached_file` → `{source_site_url}/wp-content/uploads/{path}`

Example:
- `_wp_attached_file = 2025/12/Diagram-....jpg`
- URL becomes:
    - `https://www.gpstrategies.com/wp-content/uploads/2025/12/Diagram-....jpg`

This is stable and matches how WordPress stores attachments.
