# ST WP Importer – Boilerplate Analysis

- **Entry point:** `st-wp-importer.php` defines `ST_WP_IMPORTER_VERSION`, registers activation/deactivation callbacks, requires `includes/class-st-wp-importer.php`, and immediately instantiates/runs `St_Wp_Importer`.
- **Autoload/namespaces:** No namespaces or autoloader. Classes use `St_Wp_Importer_*` prefixes and are required manually in the core class.
- **Core orchestration:** `includes/class-st-wp-importer.php` loads:
  - `includes/class-st-wp-importer-loader.php` (simple hook registrar for actions/filters)
  - `includes/class-st-wp-importer-i18n.php` (loads textdomain on `plugins_loaded`)
  - `admin/class-st-wp-importer-admin.php` (admin-only hooks)
  - `public/class-st-wp-importer-public.php` (frontend hooks)
- **Admin pattern:** No admin menu or settings yet. Admin class only enqueues `admin/css/st-wp-importer-admin.css` and `admin/js/st-wp-importer-admin.js` on `admin_enqueue_scripts`. View partial `admin/partials/st-wp-importer-admin-display.php` is empty.
- **Public pattern:** Frontend class only enqueues `public/css/...` and `public/js/...` on `wp_enqueue_scripts`.
- **Activation/deactivation:** `includes/class-st-wp-importer-activator.php` and `...-deactivator.php` are empty stubs (no table/option setup). `uninstall.php` exists but empty.
- **Settings/storage/helpers:** None implemented. No options, custom tables, or helper utilities (logger, DB, importer) yet.
- **Build/tooling:** No Composer/NPM tooling present; nothing to build. Static assets are placeholders.
