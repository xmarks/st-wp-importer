<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://www.snailtheme.com/
 * @since      1.0.0
 *
 * @package    St_Wp_Importer
 * @subpackage St_Wp_Importer/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * Extended to provide dashboard, settings, controls, and log viewer.
 *
 * @package    St_Wp_Importer
 * @subpackage St_Wp_Importer/admin
 * @author     SnailTheme <webmaster@snailtheme.com>
 */
class St_Wp_Importer_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * @var St_Wp_Importer_Settings
	 */
	private $settings;

	/**
	 * @var St_Wp_Importer_State
	 */
	private $state;

	/**
	 * @var St_Wp_Importer_Logger
	 */
	private $logger;

	/**
	 * @var St_Wp_Importer_Map
	 */
	private $map;

	/**
	 * @var St_Wp_Importer_Source_DB
	 */
	private $source_db;

	/**
	 * @var St_Wp_Importer_Cron
	 */
	private $cron;

	/**
	 * @var St_Wp_Importer_Importer
	 */
	private $importer;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version, $settings, $state, $logger, $map, $source_db, $cron, $importer ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;
		$this->settings = $settings;
		$this->state    = $state;
		$this->logger   = $logger;
		$this->map      = $map;
		$this->source_db= $source_db;
		$this->cron     = $cron;
		$this->importer = $importer;

	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/st-wp-importer-admin.css', array(), $this->version, 'all' );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/st-wp-importer-admin.js', array( 'jquery' ), $this->version, true );
		wp_localize_script(
			$this->plugin_name,
			'stwiAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'stwi_admin_nonce' ),
			)
		);

	}

	/**
	 * Ensure table and defaults exist.
	 */
	public function maybe_setup() {
		$this->map->maybe_create_table();

		if ( ! get_option( St_Wp_Importer_Settings::OPTION_KEY ) ) {
			$this->settings->save( $this->settings->defaults() );
		}

		$settings = $this->settings->get();
		// Sync logger enabled flag with saved settings.
		$this->logger->set_enabled( (bool) ( $settings['enable_logging'] ?? true ) );
	}

	/**
	 * Register Tools submenu.
	 */
	public function register_menu() {
		add_management_page(
			'ST WP Importer',
			'ST WP Importer',
			'manage_options',
			'st-wp-importer',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render dashboard page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->settings->get();
		$state    = $this->state->get();
		$log_tail = $this->logger->tail( 100 );
		$log_path = $this->logger->file_path();

		require plugin_dir_path( __FILE__ ) . 'partials/st-wp-importer-admin-display.php';
	}

	/**
	 * Handle settings form submission.
	 */
	public function handle_settings_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'st-wp-importer' ) );
		}

		check_admin_referer( 'stwi_save_settings' );

		$sanitized = $this->settings->save( $_POST );
		$this->logger->set_enabled( (bool) ( $sanitized['enable_logging'] ?? true ) );
		$this->logger->log( 'INFO', 'Settings saved' );

		wp_safe_redirect( add_query_arg( array( 'page' => 'st-wp-importer', 'updated' => 'true' ), admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * AJAX: Test DB connection.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'stwi_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$settings = $this->settings->get();
		$result   = $this->source_db->test_connection( $settings );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}

	/**
	 * AJAX: Start import (sets state + schedules cron).
	 */
	public function ajax_start_import() {
		check_ajax_referer( 'stwi_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$settings     = $this->settings->get();
		$post_types   = wp_list_pluck( $settings['import_scope'], 'post_type' );
		$this->state->mark_start( $post_types );
		$this->cron->ensure_scheduled();
		$this->logger->log( 'INFO', 'Import started', array( 'post_types' => $post_types ) );

		wp_send_json_success( array( 'message' => 'Import started' ) );
	}

	/**
	 * AJAX: Stop import (flag + clear cron).
	 */
	public function ajax_stop_import() {
		check_ajax_referer( 'stwi_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$this->state->update(
			array(
				'stop_requested' => true,
				'running'        => false,
			)
		);
		$this->cron->clear_schedule();
		$this->logger->log( 'INFO', 'Stop requested' );

		wp_send_json_success( array( 'message' => 'Stop requested' ) );
	}

	/**
	 * AJAX: Run one batch now.
	 */
	public function ajax_run_batch_now() {
		check_ajax_referer( 'stwi_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$result = $this->importer->run_batch();
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}

	/**
	 * AJAX: Fetch log tail.
	 */
	public function ajax_fetch_logs() {
		check_ajax_referer( 'stwi_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		wp_send_json_success(
			array(
				'log' => $this->logger->tail( 100 ),
			)
		);
	}

}
