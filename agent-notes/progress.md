# ST WP Importer – Progress & Remaining Work

## Completed
- Boilerplate and theme analyses captured (`boilerplate-analysis.md`, `theme-analysis.md`).
- Admin dashboard under Tools with settings, connection test, controls (start/stop/run-now), log tail, danger-zone delete/reset.
- Settings/state/mapping/logger/source-db/media/content/importer helpers implemented; mapping table on activation.
- Batch importer: round-robin post import, idempotent upsert, content media rewrite, featured image import, taxonomy assignment, dry-run support, verbose logging, stop flag.
- Author handling: resolves by existing mapping → username (login) → email → creates user (dry-run logs only). Maps `user` entries for reuse.
- Dry runs no longer advance cursors/stats/last_run.
- Danger zone now deletes mapped content and, when empty, resets mapping/state/log.
- Yoast SEO toggle added; Yoast meta imported only when enabled, with verbose logging. Other plugin toggles (Hreflang, Permalink Manager Pro, Redirection) added (placeholders for now).
- ACF toggle added (default on); ACF meta included unless disabled (we already copy all meta except edit locks/thumbnail/Yoast when off).
- Permalink Manager Pro meta handled when toggle on (copies meta keys prefixed with `permalink_manager` / `_permalink_manager` with verbose logging).
- PublishPress Authors note: plugin currently only present to avoid theme errors; no author box migration yet. Authors were not deleted there previously; consider cleanup/mapping if kept enabled.
- Redirection: excluded from migration scope; checkbox removed; if needed later, import redirects via JSON export or DB, map as `redirect` for cleanup.

## Outstanding / Next
- Plugin metadata migrations:
  - **Yoast SEO**: copy post meta (`_yoast_wpseo_*` including title, desc, canonical, primary topic/industry, focus kw, opengraph/twitter).
  - **ACF**: safest is to carry all postmeta; relies on plugin being present to use it.
  - **Permalink Manager Pro**: migrate custom permalinks/meta keys (identify exact meta keys, e.g., `_permalink_manager_...`).
  - **Redirection**: likely table-based; plan export/import of redirects separately or via API.
  - (Optional) hreflang manager meta (`hreflang-*`), Relevanssi (`_relevanssi_*`), PublishPress statuses (`_pp_*`, `_rvy_*`), AIOSEO meta if needed.
- Term meta migrations (Yoast primary terms etc.) if required.
- Additional ACF file/image fields auditing (ACF JSON present).
- Optional “reset state only” control (without deleting content).

## CSV (Posts-Export-2026-January-20-0953.csv) meta highlights
- Yoast: `_yoast_wpseo_focuskw`, `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_primary_topic`, `_yoast_wpseo_primary_industry`, `_yoast_wpseo_canonical`, `_yoast_wpseo_content_score`, `_yoast_wpseo_linkdex`, `_yoast_wpseo_focuskeywords`, `_yoast_wpseo_keywordsynonyms`, `_yoast_wpseo_wordproof_timestamp`.
- AIOSEO: `_aioseo_title`, `_aioseo_description`, `_aioseo_keywords`, `_aioseo_og_*`, `_aioseo_twitter_*`.
- Hreflang: `hreflang-*` columns (likely hreflang manager).
- Relevanssi: `_relevanssi_noindex_reason`, `_relevanssi_related_posts`.
- PublishPress/Revisionary: `_pp_*`, `_rvy_*`, `_pp_statuses_*`, `_pp_is_autodraft`, `_rvy_has_revisions`.
- Duplicate/Page status: `_dp_original`, `_wp_old_slug`, `_wp_old_date`.
- Custom link/icon repeaters: `_link_items_*`.
- Misc style/meta: `_phylax_ppsccss_*`, `_at_widget`, `_thumbnail_id`, `_wp_page_template`.
- Authors: `Author ID/Username/Email` columns present; reinforces login-based uniqueness.
