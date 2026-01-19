# Theme Analysis – gpstrategies-2023

## Custom Post Types (init via `inc/init-cpt.php`)
- `solutions-cpt` (slug rewrite `solutions`, hierarchical, supports title/editor/excerpt/thumbnail/page-attributes/revisions); taxonomy: `solutions-category` (hierarchical). Also uses global `industry` taxonomy.
- `events-cpt` (rewrite `events`, hierarchical, supports title/editor/excerpt/thumbnail/page-attributes/revisions); taxonomy: `event-type`; also uses global `topic` + `industry`.
- `news-cpt` (rewrite `news`, hierarchical, supports title/editor/excerpt/thumbnail/page-attributes/revisions); taxonomy: `news-category`; also uses global `industry`.
- `podcasts-cpt` (rewrite true/default, non-hierarchical, supports title/editor/excerpt/thumbnail/page-attributes/revisions); taxonomy: `podcasts-host`; also uses global `topic` + `industry`.
- `webinars-cpt` (rewrite `webinars`, hierarchical, supports title/editor/excerpt/thumbnail/page-attributes/revisions); no local taxonomy, but global `topic` + `industry` apply.
- `resource` (rewrite `resources`, hierarchical, supports title/editor/excerpt/thumbnail/page-attributes/revisions); taxonomy: `resource-type`; also uses global `topic` + `industry`.
- `case-study-cpt` (rewrite `our-work`, hierarchical, supports title/editor/excerpt/thumbnail/page-attributes/revisions); taxonomy: `case-study-category`; also uses global `industry`.
- `training-course` (rewrite `training-courses`, hierarchical, supports title/editor/excerpt/thumbnail/page-attributes/revisions); taxonomies: `modality`, `leadership-level`, `course-type`; also uses global `topic`.
- `acab-cpt` (rewrite `customer-advisory-board`, hierarchical, supports title/editor/excerpt/thumbnail/page-attributes/revisions); taxonomy: `acab-leadership-category`.

## Global Taxonomies
- `topic` (hierarchical, slug `topic`, show_in_rest) attached to `post`, `resource`, `training-course`, `podcasts-cpt`, `events-cpt`, `webinars-cpt`.
- `industry` (hierarchical, slug `industry`, show_in_rest) attached to `post`, `resource`, `podcasts-cpt`, `events-cpt`, `solutions-cpt`, `news-cpt`, `case-study-cpt`, `webinars-cpt`.
- `resource-type` registered on `resource` (and a dormant `resources_type-taxonomy.php` for `post` is present but not loaded).

## Notable Meta/ACF Usage (render-impacting)
- **Resources:** `resource_asset_type` (custom/file), `resource_custom_url`, `resource_file` (file array), `exclude_gated` flag (affects Eloqua gating), relies on `resource-type` terms (e.g., webinar/ebook/research-report/brochure) to decide gating paths.
- **Events/Webinars:** Date/time fields `start_date`, `end_date`, `start_time`, `end_time`, `time_zone`, `event_duration`, `presenters`, `is_external_link`, `external_link`/`event_link` used in listings; event filters via `event_listing_type`, `select_event_type`. `event-type` taxonomy drives filtering.
- **Case studies:** `company_logo` (image), `border_radius`, `image_style`, `show_list_of_content` (toggles table of contents partial).
- **ACAB:** `title` (job title) meta.
- **Posts/general:** Styling fields such as `align_content`, `overlay_color`, `text_color`, `background_image`, `image_as_pattern`, `pattern_position`, `dots_color`; author meta `job_title` on users (`user_{ID}`).
- **Podcasts:** Uses option-level `background_image`, `podcast_background_color`, `podcast_text_color`; content embeds `[powerpress]`.
- **Options/Globals:** Multiple option fields drive gating/form embeds (`hbspt_*`), navigation buttons (`contact_button_link`), Google Maps (`google_api_key`), social links, etc. ACF local JSON exists in `acf-json/` for field definitions.

## Content/Media Patterns
- Templates and custom blocks (`gp-blocks/*/block-render.php`) pull many ACF images/files (e.g., `background_image`, `logo_list`, `video_file`, `pdf_file`, `source_link`, `featured_image`) and inline them as `<img>` or CSS `background-image` URLs. Media import should account for attachments referenced in ACF image/file fields and HTML content.
- Featured images are used across single templates and hero sections (`get_the_post_thumbnail_url`).
- Resource gating uses conditional template loading (`content-webinar.php`) with redirects based on ACF file/custom URL fields.

## Special Notes
- No custom post statuses detected.
- Rewrite flushing calls are present in some CPT files; imports should preserve slugs and taxonomies to keep URLs stable.
