<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://www.snailtheme.com/
 * @since      1.0.0
 *
 * @package    St_Wp_Importer
 * @subpackage St_Wp_Importer/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    St_Wp_Importer
 * @subpackage St_Wp_Importer/includes
 * @author     SnailTheme <webmaster@snailtheme.com>
 */
class St_Wp_Importer {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      St_Wp_Importer_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'ST_WP_IMPORTER_VERSION' ) ) {
			$this->version = ST_WP_IMPORTER_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'st-wp-importer';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - St_Wp_Importer_Loader. Orchestrates the hooks of the plugin.
	 * - St_Wp_Importer_i18n. Defines internationalization functionality.
	 * - St_Wp_Importer_Admin. Defines all hooks for the admin area.
	 * - St_Wp_Importer_Public. Defines all hooks for the public side of the site.
	 * - Settings / State / Logger / Cron / Importer helpers.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-st-wp-importer-loader.php';

		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/trait-st-wp-importer-media-helpers.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-st-wp-importer-settings.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-st-wp-importer-state.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-st-wp-importer-logger.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-st-wp-importer-map.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-st-wp-importer-source-db.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-st-wp-importer-media.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-st-wp-importer-content.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-st-wp-importer-importer.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-st-wp-importer-cron.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-st-wp-importer-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-st-wp-importer-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-st-wp-importer-public.php';

		$this->loader = new St_Wp_Importer_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the St_Wp_Importer_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new St_Wp_Importer_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$settings = new St_Wp_Importer_Settings();
		$state    = new St_Wp_Importer_State();
		$logger   = new St_Wp_Importer_Logger( $settings );
		$map      = new St_Wp_Importer_Map( $logger );
		$source   = new St_Wp_Importer_Source_DB( $logger );
		$media    = new St_Wp_Importer_Media( $settings, $logger, $map, $source );
		$content  = new St_Wp_Importer_Content( $logger, $media, $source, $settings );
		$importer = new St_Wp_Importer_Importer( $settings, $state, $logger, $map, $source, $media, $content );
		$cron     = new St_Wp_Importer_Cron( $settings, $state, $logger, $importer );

		$plugin_admin = new St_Wp_Importer_Admin(
			$this->get_plugin_name(),
			$this->get_version(),
			$settings,
			$state,
			$logger,
			$map,
			$source,
			$cron,
			$importer
		);

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'register_menu' );
		$this->loader->add_action( 'admin_init', $plugin_admin, 'maybe_setup' );
		$this->loader->add_action( 'admin_post_stwi_save_settings', $plugin_admin, 'handle_settings_save' );
		$this->loader->add_action( 'wp_ajax_stwi_test_connection', $plugin_admin, 'ajax_test_connection' );
		$this->loader->add_action( 'wp_ajax_stwi_start_import', $plugin_admin, 'ajax_start_import' );
		$this->loader->add_action( 'wp_ajax_stwi_stop_import', $plugin_admin, 'ajax_stop_import' );
		$this->loader->add_action( 'wp_ajax_stwi_run_batch_now', $plugin_admin, 'ajax_run_batch_now' );
		$this->loader->add_action( 'wp_ajax_stwi_fetch_logs', $plugin_admin, 'ajax_fetch_logs' );
		$this->loader->add_action( 'wp_ajax_stwi_delete_imported', $plugin_admin, 'ajax_delete_imported' );

		// Cron hook for batch runner.
		$this->loader->add_action( 'stwi_run_batch', $cron, 'handle_scheduled_batch' );
		$this->loader->add_filter( 'cron_schedules', $cron, 'add_schedule' );

	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new St_Wp_Importer_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    St_Wp_Importer_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
