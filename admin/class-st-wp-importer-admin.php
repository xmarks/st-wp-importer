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
		$scope        = array_filter(
			$settings['import_scope'],
			function ( $row ) {
				return ! isset( $row['enabled'] ) || (bool) $row['enabled'];
			}
		);
		$post_types   = wp_list_pluck( $scope, 'post_type' );
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

	/**
	 * AJAX: Delete imported content using mapping table.
	 */
	public function ajax_delete_imported() {
		check_ajax_referer( 'stwi_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 100;
		$batch_size = $batch_size > 0 ? min( $batch_size, 200 ) : 100;
		$full_purge = isset( $_POST['full_purge'] ) ? (bool) $_POST['full_purge'] : true;
		$time_budget= $full_purge ? 20 : 8;
		$started    = microtime( true );

		$deleted = array( 'posts' => 0, 'attachments' => 0, 'terms' => 0, 'users' => 0 );
		$errors  = array();
		$options_deleted = array( 'acf_theme' => 0, 'powerpress' => 0 );
		$state = $this->state->get();

		$restore_cache = wp_suspend_cache_invalidation( true );
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		@set_time_limit( 300 ); // phpcs:ignore

		try {
			do {
				$rows = $this->map->get_rows_for_deletion( $batch_size );
				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					$dest_id     = (int) $row['dest_id'];
					$object_type = $row['source_object_type'];
					$context     = array(
						'mapping_id'  => (int) $row['id'],
						'object_type' => $object_type,
						'dest_id'     => $dest_id,
						'source_id'   => (int) $row['source_id'],
					);

					if ( in_array( $object_type, array( 'attachment', 'attachment_url' ), true ) ) {
						$result = wp_delete_attachment( $dest_id, true );
						if ( $result ) {
							$deleted['attachments']++;
							$this->map->delete_row( (int) $row['id'] );
							$this->logger->log( 'INFO', 'Deleted imported attachment', $context );
						} else {
							// If attachment already missing or deletion fails, clear mapping so cleanup can proceed.
							$exists = get_post( $dest_id );
							$this->map->delete_row( (int) $row['id'] );
							if ( $exists ) {
								$this->logger->log(
									'WARNING',
									'Attachment delete failed; mapping removed anyway',
									$context + array(
										'post_status' => $exists->post_status,
										'post_type'   => $exists->post_type,
									)
								);
							} else {
								$this->logger->log(
									'INFO',
									'Attachment already missing; mapping removed',
									$context
								);
							}
						}
						continue;
					}

					if ( 'term' === $object_type ) {
						$term_deleted = wp_delete_term( $dest_id, $this->guess_taxonomy_from_term_id( $dest_id ) );
						if ( ! is_wp_error( $term_deleted ) ) {
							$deleted['terms']++;
							$this->map->delete_row( (int) $row['id'] );
							$this->logger->log( 'INFO', 'Deleted imported term', $context );
						} else {
							$errors[] = $context;
							$this->logger->log( 'ERROR', 'Failed to delete imported term', $context + array( 'error' => $term_deleted->get_error_message() ) );
						}
						continue;
					}

					if ( 'user' === $object_type ) {
						if ( get_current_user_id() === $dest_id ) {
							$this->logger->log( 'ERROR', 'Skipping deletion of current user', $context );
							continue;
						}
						$result = wp_delete_user( $dest_id );
						if ( $result ) {
							$deleted['users']++;
							$this->map->delete_row( (int) $row['id'] );
							$this->logger->log( 'INFO', 'Deleted imported user', $context );
						} else {
							$errors[] = $context;
							$this->logger->log( 'ERROR', 'Failed to delete imported user', $context );
						}
						continue;
					}

					$result = wp_delete_post( $dest_id, true );
					if ( $result ) {
						$deleted['posts']++;
						$this->map->delete_row( (int) $row['id'] );
						$this->logger->log( 'INFO', 'Deleted imported post', $context );
					} else {
						// If the post is already gone, clear mapping to avoid endless failures.
						$exists = get_post( $dest_id );
						if ( ! $exists ) {
							$this->map->delete_row( (int) $row['id'] );
							$this->logger->log(
								'INFO',
								'Imported post already absent; mapping cleared',
								$context
							);
						} else {
							$errors[] = $context;
							$this->logger->log(
								'ERROR',
								'Failed to delete imported post',
								$context + array(
									'post_type' => $exists->post_type,
									'post_status' => $exists->post_status,
								)
							);
						}
					}
				}

				if ( ! $full_purge ) {
					break;
				}
			} while ( ( microtime( true ) - $started ) < $time_budget );

			// One-time option cleanup (outside mapping table).
			global $wpdb;
			$options_table = $wpdb->options;

			// Targeted option cleanup using recorded names; fall back to pattern if none recorded.
			$acf_names = $state['imported_options']['acf'] ?? array();
			if ( ! empty( $acf_names ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $acf_names ), '%s' ) );
				$sql          = $wpdb->prepare(
					"DELETE FROM {$options_table} WHERE option_name IN ($placeholders)",
					$acf_names
				);
				$acf_deleted = $wpdb->query( $sql );
				if ( false !== $acf_deleted ) {
					$options_deleted['acf_theme'] = (int) $acf_deleted;
					if ( $acf_deleted ) {
						$this->logger->log( 'INFO', 'Deleted imported ACF theme options', array( 'count' => $acf_deleted ) );
					}
				}
			}

			$pp_names = $state['imported_options']['powerpress'] ?? array();
			if ( ! empty( $pp_names ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $pp_names ), '%s' ) );
				$sql          = $wpdb->prepare(
					"DELETE FROM {$options_table} WHERE option_name IN ($placeholders)",
					$pp_names
				);
				$pp_deleted = $wpdb->query( $sql );
				if ( false !== $pp_deleted ) {
					$options_deleted['powerpress'] = (int) $pp_deleted;
					if ( $pp_deleted ) {
						$this->logger->log( 'INFO', 'Deleted imported PowerPress options', array( 'count' => $pp_deleted ) );
					}
				}
			}

			// If nothing recorded (older runs), fall back to safe patterns.
			if ( empty( $acf_names ) ) {
				$acf_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$options_table} WHERE option_name LIKE %s", 'acf\\_%' ) );
				if ( false !== $acf_deleted ) {
					$options_deleted['acf_theme'] += (int) $acf_deleted;
					if ( $acf_deleted ) {
						$this->logger->log( 'INFO', 'Deleted ACF theme settings options (pattern fallback)', array( 'count' => $acf_deleted ) );
					}
				}

				$options_deleted['acf_theme'] += (int) $wpdb->query(
					$wpdb->prepare( "DELETE FROM {$options_table} WHERE option_name LIKE %s", 'options\\_%' )
				);
			}

			if ( empty( $pp_names ) ) {
				$pp_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$options_table} WHERE option_name LIKE %s", 'powerpress\\_%' ) );
				if ( false !== $pp_deleted ) {
					$options_deleted['powerpress'] += (int) $pp_deleted;
					if ( $pp_deleted ) {
						$this->logger->log( 'INFO', 'Deleted PowerPress options (pattern fallback)', array( 'count' => $pp_deleted ) );
					}
				}
			}

			// Reset recorded option lists after cleanup.
			$state['imported_options']['acf']        = array();
			$state['imported_options']['powerpress'] = array();
			$this->state->update( array( 'imported_options' => $state['imported_options'] ) );
		} finally {
			wp_suspend_cache_invalidation( $restore_cache );
			wp_defer_term_counting( false );
			wp_defer_comment_counting( false );
		}

		$remaining = $this->map->count_remaining();
		if ( 0 === $remaining ) {
			$this->map->delete_all();
			$this->state->reset();
			$this->logger->truncate();
			$this->logger->log( 'INFO', 'Importer state reset (mapping cleared, log truncated).' );
		}

		if ( ! empty( $errors ) ) {
			wp_send_json_error(
				array(
					'message'    => 'Some items failed to delete. Check logs.',
					'deleted'    => $deleted,
					'remaining'  => $remaining,
					'duration_s' => round( microtime( true ) - $started, 2 ),
					'full_purge' => $full_purge,
				)
			);
		}

		wp_send_json_success(
			array(
				'message'    => sprintf( 'Deleted %d posts, %d attachments, %d terms, %d users. Remaining: %d', $deleted['posts'], $deleted['attachments'], $deleted['terms'], $deleted['users'], $remaining ),
				'deleted'    => $deleted,
				'remaining'  => $remaining,
				'options_deleted' => $options_deleted,
				'duration_s' => round( microtime( true ) - $started, 2 ),
				'full_purge' => $full_purge,
			)
		);
	}

	/**
	 * Attempt to guess taxonomy for a term id.
	 *
	 * @param int $term_id
	 * @return string|null
	 */
	private function guess_taxonomy_from_term_id( int $term_id ): ?string {
		$term = get_term( $term_id );
		if ( $term && ! is_wp_error( $term ) ) {
			return $term->taxonomy;
		}
		return null;
	}

}
