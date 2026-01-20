<?php

/**
 * Batch importer.
 */
class St_Wp_Importer_Importer {

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
	 * @var St_Wp_Importer_Media
	 */
	private $media;

	/**
	 * @var St_Wp_Importer_Content
	 */
	private $content;

	public function __construct(
		St_Wp_Importer_Settings $settings,
		St_Wp_Importer_State $state,
		St_Wp_Importer_Logger $logger,
		St_Wp_Importer_Map $map,
		St_Wp_Importer_Source_DB $source_db,
		St_Wp_Importer_Media $media,
		St_Wp_Importer_Content $content
	) {
		$this->settings  = $settings;
		$this->state     = $state;
		$this->logger    = $logger;
		$this->map       = $map;
		$this->source_db = $source_db;
		$this->media     = $media;
		$this->content   = $content;
	}

	/**
	 * Run a batch.
	 *
	 * @return array
	 */
	public function run_batch(): array {
		$state = $this->state->get();
		if ( ! empty( $state['stop_requested'] ) ) {
			$this->logger->log( 'INFO', 'Stop requested; batch aborted.' );
			$this->state->mark_stopped();
			return array(
				'success' => false,
				'message' => 'Stop requested. Halting.',
			);
		}

		$settings   = $this->settings->get();
		$dry_run    = (bool) $settings['dry_run'];
		$post_types = wp_list_pluck( $settings['import_scope'], 'post_type' );
		$post_types = array_filter( $post_types, function ( $pt ) {
			return 'page' !== $pt;
		} );

		$batch_limit        = max( 1, (int) $settings['posts_per_run'] );
		$processed          = 0;
		$persistable_stats  = $state['stats'] ?? $this->state->defaults()['stats'];
		$persistable_cursor = $state['cursor'] ?? array();
		$run_stats          = $persistable_stats;
		$run_cursor         = $persistable_cursor;

		$this->logger->log(
			'INFO',
			'Batch start',
			array(
				'post_types'   => $post_types,
				'batch_limit'  => $batch_limit,
				'cursor'       => $run_cursor,
				'dry_run'      => $dry_run,
			)
		);

		foreach ( $post_types as $post_type ) {
			if ( $processed >= $batch_limit ) {
				break;
			}
			$remaining = $batch_limit - $processed;
			$cursor    = isset( $run_cursor[ $post_type ] ) ? (int) $run_cursor[ $post_type ] : 0;
			$rows      = $this->source_db->fetch_posts( $post_type, $cursor, $remaining, $settings );

			$this->logger->log(
				'INFO',
				'Fetched posts for type',
				array(
					'post_type' => $post_type,
					'from_id'   => $cursor,
					'limit'     => $remaining,
					'found'     => count( $rows ),
				)
			);

			foreach ( $rows as $row ) {
				$stop_state = $this->state->get();
				if ( ! empty( $stop_state['stop_requested'] ) ) {
					$this->logger->log( 'INFO', 'Stop requested mid-batch; halting.' );
					$this->state->mark_stopped();
					return array( 'success' => false, 'message' => 'Stop requested mid-batch.' );
				}

				$result = $this->import_single_post( $row, $settings, $dry_run );
				if ( $result['status'] === 'imported' ) {
					$run_stats['posts_imported']++;
				} elseif ( $result['status'] === 'updated' ) {
					$run_stats['posts_updated']++;
				} else {
					$run_stats['posts_skipped']++;
				}
				$run_stats['attachments_imported'] += $result['attachments_imported'];
				$run_cursor[ $post_type ]         = (int) $row['ID'];
				$processed++;

				if ( $processed >= $batch_limit ) {
					break 2;
				}
			}
		}

		if ( ! $dry_run ) {
			$persistable_stats  = $run_stats;
			$persistable_cursor = $run_cursor;
			$this->state->update(
				array(
					'cursor'      => $persistable_cursor,
					'stats'       => $persistable_stats,
					'last_run_at' => time(),
				)
			);
		}

		$this->logger->log(
			'INFO',
			'Batch completed',
			array(
				'processed'  => $processed,
				'dry_run'    => $dry_run,
				'post_types' => $post_types,
				'cursor'     => $run_cursor,
			)
		);

		return array(
			'success' => true,
			'message' => sprintf( 'Processed %d posts', $processed ),
		);
	}

	/**
	 * Import or update a single post.
	 *
	 * @param array $row
	 * @param array $settings
	 * @param bool  $dry_run
	 * @return array
	 */
	private function import_single_post( array $row, array $settings, bool $dry_run ): array {
		$source_blog_id = (int) $settings['source_blog_id'];
		$source_id      = (int) $row['ID'];
		$post_type      = $row['post_type'];

		// Skip pages (enforced earlier, but double-check).
		if ( 'page' === $post_type ) {
			return array( 'status' => 'skipped', 'attachments_imported' => 0 );
		}

		$dest_id = $this->map->get_destination_id( $source_blog_id, 'post', $source_id );
		$meta    = $this->source_db->fetch_meta( $source_id, $settings );

		// Rewrite content/media.
		$rewrite = $this->content->rewrite( $row['post_content'], $settings, $dry_run );
		$row['post_content'] = $rewrite['content'];

		$author_id = $this->resolve_author( (int) $row['post_author'], $settings, $dry_run );
		$this->logger->log(
			'INFO',
			'Author resolved for post',
			array(
				'source_post_id'  => (int) $row['ID'],
				'source_author'   => (int) $row['post_author'],
				'dest_author'     => $author_id,
				'dry_run'         => $dry_run,
			)
		);

		$base_post = array(
			'post_type'      => $post_type,
			'post_status'    => $row['post_status'],
			'post_title'     => $row['post_title'],
			'post_content'   => $row['post_content'],
			'post_excerpt'   => $row['post_excerpt'],
			'post_name'      => $row['post_name'],
			'comment_status' => $row['comment_status'],
			'ping_status'    => $row['ping_status'],
			'menu_order'     => $row['menu_order'],
			'post_date'      => $row['post_date'],
			'post_date_gmt'  => $row['post_date_gmt'],
			'post_modified'  => current_time( 'mysql' ),
			'post_modified_gmt' => current_time( 'mysql', 1 ),
			'post_author'    => (int) $author_id,
		);

		$status = 'skipped';
		if ( $dry_run ) {
			$this->logger->log(
				'INFO',
				'[DRY RUN] Would upsert post',
				array(
					'source_id' => $source_id,
					'post_type' => $post_type,
					'update'    => (bool) $dest_id,
				)
			);
		} else {
			$this->logger->log(
				'INFO',
				'Upserting post',
				array(
					'source_id' => $source_id,
					'post_type' => $post_type,
					'update'    => (bool) $dest_id,
				)
			);
			if ( $dest_id ) {
				$base_post['ID'] = $dest_id;
				$result          = wp_update_post( $base_post, true );
				$status          = 'updated';
				$dest_id         = is_wp_error( $result ) ? 0 : (int) $result;
			} else {
				$dest_id = wp_insert_post( $base_post, true );
				$status  = 'imported';
			}

			if ( is_wp_error( $dest_id ) ) {
				$this->logger->log(
					'ERROR',
					'Failed to upsert post',
					array(
						'source_id' => $source_id,
						'error'     => $dest_id->get_error_message(),
					)
				);
				return array( 'status' => 'skipped', 'attachments_imported' => $rewrite['attachments_imported'] );
			}

			// Mapping meta and table entry.
			update_post_meta( $dest_id, '_stwi_source_blog_id', $source_blog_id );
			update_post_meta( $dest_id, '_stwi_source_post_id', $source_id );
			update_post_meta( $dest_id, '_stwi_source_post_type', $post_type );
			$this->map->upsert( $source_blog_id, 'post', $source_id, $dest_id );

			// Meta import (excluding thumbnail here).
			$this->import_meta( $dest_id, $meta, $settings, $dry_run );

			// Featured image.
			$rewrite['attachments_imported'] += $this->maybe_set_featured_image( $dest_id, $meta, $settings, $dry_run );

			// Taxonomies.
			$this->import_taxonomies( $dest_id, $source_id, $post_type, $settings );

			// Enforce author after insert/update (some hooks may override).
			$current_author = (int) get_post_field( 'post_author', $dest_id );
			if ( $current_author !== $author_id ) {
				$author_result = wp_update_post(
					array(
						'ID'          => $dest_id,
						'post_author' => $author_id,
					),
					true
				);
				$this->logger->log(
					'INFO',
					'Post author enforced',
					array(
						'dest_id'        => $dest_id,
						'was_author'     => $current_author,
						'expected_author'=> $author_id,
						'result'         => is_wp_error( $author_result ) ? $author_result->get_error_message() : 'ok',
					)
				);
			}
		}

		$this->logger->log(
			'INFO',
			'Processed post',
			array(
				'source_id' => $source_id,
				'dest_id'   => $dest_id,
				'post_type' => $post_type,
				'status'    => $status,
				'meta_count'=> count( $meta ),
				'attachments_imported' => $rewrite['attachments_imported'],
			)
		);

		return array( 'status' => $status, 'attachments_imported' => $rewrite['attachments_imported'] );
	}

	/**
	 * Import meta for post.
	 *
	 * @param int   $dest_id
	 * @param array $meta_rows
	 * @param array $settings
	 * @param bool  $dry_run
	 */
	private function import_meta( int $dest_id, array $meta_rows, array $settings, bool $dry_run ): void {
		$yoast_enabled = ! empty( $settings['plugin_yoastseo'] );
		$acf_enabled   = ! empty( $settings['plugin_acf'] );
		$permalink_enabled = ! empty( $settings['plugin_permalink_manager'] );
		$yoast_keys    = array();
		$permalink_keys= array();
		foreach ( $meta_rows as $meta_row ) {
			if ( strpos( $meta_row['meta_key'], '_yoast_wpseo_' ) === 0 ) {
				$yoast_keys[] = $meta_row;
			}
			if ( $this->is_permalink_manager_meta( $meta_row['meta_key'] ) ) {
				$permalink_keys[] = $meta_row;
			}
		}

		foreach ( $meta_rows as $meta_row ) {
			$key   = $meta_row['meta_key'];
			$value = maybe_unserialize( $meta_row['meta_value'] );

			if ( in_array( $key, array( '_edit_lock', '_edit_last', '_thumbnail_id' ), true ) ) {
				continue;
			}

			// Skip Yoast in general meta flow if plugin toggle controls it.
			if ( $yoast_enabled && strpos( $key, '_yoast_wpseo_' ) === 0 ) {
				continue;
			}
			// Skip Permalink Manager meta if toggle is off.
			if ( ! $permalink_enabled && $this->is_permalink_manager_meta( $key ) ) {
				continue;
			}
			// Skip ACF meta if toggle is off.
			if ( ! $acf_enabled && $this->is_acf_meta( $key, $value ) ) {
				continue;
			}

			if ( $dry_run ) {
				$this->logger->log(
					'INFO',
					'[DRY RUN] Would import post meta',
					array( 'dest_id' => $dest_id, 'meta_key' => $key )
				);
				continue;
			}

			update_post_meta( $dest_id, $key, $value );
			$this->logger->log(
				'INFO',
				'Imported post meta',
				array( 'dest_id' => $dest_id, 'meta_key' => $key )
			);
		}

		if ( $yoast_enabled ) {
			$this->import_yoast_meta( $dest_id, $yoast_keys, $settings, $dry_run );
		}
		if ( $permalink_enabled ) {
			$this->import_permalink_meta( $dest_id, $permalink_keys, $settings, $dry_run );
		}
	}

	/**
	 * Set featured image if available.
	 *
	 * @param int   $dest_id
	 * @param array $meta_rows
	 * @param array $settings
	 * @param bool  $dry_run
	 */
	private function maybe_set_featured_image( int $dest_id, array $meta_rows, array $settings, bool $dry_run ): int {
		$thumb_id = null;
		foreach ( $meta_rows as $meta_row ) {
			if ( '_thumbnail_id' === $meta_row['meta_key'] ) {
				$thumb_id = (int) $meta_row['meta_value'];
				break;
			}
		}
		if ( ! $thumb_id ) {
			return 0;
		}

		$prior  = $this->map->get_destination_id( (int) $settings['source_blog_id'], 'attachment', $thumb_id );
		$new_id = $this->media->import_attachment_by_id( $thumb_id, $settings, $dry_run );
		if ( ! $new_id ) {
			return 0;
		}

		$imported = 0;
		if ( $dry_run ) {
			$this->logger->log(
				'INFO',
				'[DRY RUN] Would set featured image',
				array( 'dest_id' => $dest_id, 'attachment_id' => $new_id )
			);
			return 0;
		}

		set_post_thumbnail( $dest_id, $new_id );
		if ( ! $prior ) {
			$imported = 1;
		}
		return $imported;
	}

	/**
	 * Import and assign taxonomies.
	 *
	 * @param int    $dest_id
	 * @param int    $source_id
	 * @param string $post_type
	 * @param array  $settings
	 */
	private function import_taxonomies( int $dest_id, int $source_id, string $post_type, array $settings ): void {
		$scope = $settings['import_scope'];
		$taxes = array();
		foreach ( $scope as $row ) {
			if ( $row['post_type'] === $post_type ) {
				$taxes = $row['taxonomies'];
				break;
			}
		}
		if ( empty( $taxes ) ) {
			return;
		}

		$terms = $this->source_db->fetch_terms_for_post( $source_id, $taxes, $settings );
		if ( empty( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			$taxonomy = $term['taxonomy'];
			$slug     = $term['slug'];
			$name     = $term['name'];

			$dest_term = get_term_by( 'slug', $slug, $taxonomy );
			if ( ! $dest_term ) {
				$inserted = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
				if ( is_wp_error( $inserted ) ) {
					$this->logger->log(
						'ERROR',
						'Failed to insert term',
						array(
							'taxonomy' => $taxonomy,
							'slug'     => $slug,
							'error'    => $inserted->get_error_message(),
						)
					);
					continue;
				}
				$dest_term = get_term( $inserted['term_id'], $taxonomy );
			}

			if ( $dest_term && ! is_wp_error( $dest_term ) ) {
				wp_set_object_terms( $dest_id, array( (int) $dest_term->term_id ), $taxonomy, true );
				// Map newly created terms for cleanup later.
				if ( $dest_term && isset( $inserted ) && ! is_wp_error( $inserted ) && isset( $inserted['term_id'] ) ) {
					$this->map->upsert( (int) $settings['source_blog_id'], 'term', (int) $term['term_id'], (int) $dest_term->term_id );
				}
			}
		}
	}

	/**
	 * Resolve destination author for a source user ID.
	 *
	 * @param int   $source_user_id
	 * @param array $settings
	 * @param bool  $dry_run
	 * @return int
	 */
	private function resolve_author( int $source_user_id, array $settings, bool $dry_run ): int {
		$fallback = get_current_user_id() ?: 1;
		if ( $source_user_id <= 0 ) {
			$this->logger->log( 'ERROR', 'Source post has no author; using fallback', array( 'fallback' => $fallback ) );
			return $fallback;
		}

		$mapped = $this->map->get_destination_id( (int) $settings['source_blog_id'], 'user', $source_user_id );
		if ( $mapped ) {
			$user = get_user_by( 'id', $mapped );
			if ( $user ) {
				$this->logger->log( 'INFO', 'Resolved author by mapping', array( 'source_user_id' => $source_user_id, 'dest_user_id' => $mapped ) );
				return (int) $mapped;
			}
			$this->logger->log(
				'ERROR',
				'Mapped author missing locally; falling back',
				array( 'mapped_id' => $mapped, 'source_user_id' => $source_user_id )
			);
		}

		$source_user = $this->source_db->get_user_with_meta( $source_user_id, $settings );
		if ( empty( $source_user['user'] ) ) {
			$this->logger->log(
				'ERROR',
				'Source author not found; using fallback',
				array( 'source_user_id' => $source_user_id, 'fallback' => $fallback )
			);
			return $fallback;
		}

		$user_row = $source_user['user'];
		$email    = $user_row['user_email'] ?? '';
		$login    = $user_row['user_login'] ?? '';
		$display  = $user_row['display_name'] ?? '';
		$meta     = $source_user['meta'] ?? array();

		// Try local user by login (unique in WP).
		if ( $login ) {
			$local = get_user_by( 'login', $login );
			if ( $local ) {
				$this->logger->log( 'INFO', 'Resolved author by login match', array( 'login' => $login, 'local_id' => $local->ID ) );
				if ( ! $dry_run ) {
					$this->map->upsert( (int) $settings['source_blog_id'], 'user', $source_user_id, (int) $local->ID );
				}
				return (int) $local->ID;
			}
		}

		// Then try by email (not guaranteed unique).
		if ( $email ) {
			$local = get_user_by( 'email', $email );
			if ( $local ) {
				$this->logger->log( 'INFO', 'Resolved author by email match', array( 'email' => $email, 'local_id' => $local->ID ) );
				if ( ! $dry_run ) {
					$this->map->upsert( (int) $settings['source_blog_id'], 'user', $source_user_id, (int) $local->ID );
				}
				return (int) $local->ID;
			}
		}

		// Would create new user.
		$userdata = array(
			'user_login'   => sanitize_user( $login ?: ( $email ?: 'stwi_author_' . $source_user_id ), true ),
			'user_email'   => $email ?: '',
			'display_name' => $display ?: ( $login ?: 'Imported Author ' . $source_user_id ),
			'user_pass'    => wp_generate_password( 20, true ),
		);

		$first = '';
		$last  = '';
		foreach ( $meta as $m ) {
			if ( 'first_name' === $m['meta_key'] ) {
				$first = $m['meta_value'];
			} elseif ( 'last_name' === $m['meta_key'] ) {
				$last = $m['meta_value'];
			}
		}
		if ( $first ) {
			$userdata['first_name'] = $first;
		}
		if ( $last ) {
			$userdata['last_name'] = $last;
		}

		if ( $dry_run ) {
			$this->logger->log(
				'INFO',
				'[DRY RUN] Would create author user',
				array(
					'source_user_id' => $source_user_id,
					'user_login'     => $userdata['user_login'],
					'user_email'     => $userdata['user_email'],
				)
			);
			return $fallback;
		}

		$new_user_id = wp_insert_user( $userdata );
		if ( is_wp_error( $new_user_id ) ) {
			$this->logger->log(
				'ERROR',
				'Failed to create author; using fallback',
				array(
					'source_user_id' => $source_user_id,
					'error'          => $new_user_id->get_error_message(),
					'fallback'       => $fallback,
				)
			);
			return $fallback;
		}

		$this->map->upsert( (int) $settings['source_blog_id'], 'user', $source_user_id, (int) $new_user_id );
		$this->logger->log(
			'INFO',
			'Created author user',
			array(
				'source_user_id' => $source_user_id,
				'dest_user_id'   => (int) $new_user_id,
				'user_login'     => $userdata['user_login'],
				'user_email'     => $userdata['user_email'],
			)
		);

		return (int) $new_user_id;
	}

	/**
	 * Import Yoast SEO meta keys (controlled by plugin toggle).
	 *
	 * @param int   $dest_id
	 * @param array $meta_rows
	 * @param array $settings
	 * @param bool  $dry_run
	 * @return void
	 */
	private function import_yoast_meta( int $dest_id, array $meta_rows, array $settings, bool $dry_run ): void {
		if ( empty( $meta_rows ) ) {
			return;
		}

		// Allow all Yoast keys; could restrict but safer to copy all prefixed entries.
		foreach ( $meta_rows as $meta_row ) {
			$key   = $meta_row['meta_key'];
			$value = maybe_unserialize( $meta_row['meta_value'] );

			if ( $dry_run ) {
				$this->logger->log(
					'INFO',
					'[DRY RUN] Would import Yoast meta',
					array( 'dest_id' => $dest_id, 'meta_key' => $key )
				);
				continue;
			}

			update_post_meta( $dest_id, $key, $value );
			$this->logger->log(
				'INFO',
				'Imported Yoast meta',
				array( 'dest_id' => $dest_id, 'meta_key' => $key )
			);
		}
	}

	/**
	 * Import Permalink Manager Pro meta keys (controlled by plugin toggle).
	 *
	 * @param int   $dest_id
	 * @param array $meta_rows
	 * @param array $settings
	 * @param bool  $dry_run
	 * @return void
	 */
	private function import_permalink_meta( int $dest_id, array $meta_rows, array $settings, bool $dry_run ): void {
		if ( empty( $meta_rows ) ) {
			return;
		}
		foreach ( $meta_rows as $meta_row ) {
			$key   = $meta_row['meta_key'];
			$value = maybe_unserialize( $meta_row['meta_value'] );

			if ( $dry_run ) {
				$this->logger->log(
					'INFO',
					'[DRY RUN] Would import Permalink Manager meta',
					array( 'dest_id' => $dest_id, 'meta_key' => $key, 'value' => $value )
				);
				continue;
			}

			update_post_meta( $dest_id, $key, $value );
			$this->logger->log(
				'INFO',
				'Imported Permalink Manager meta',
				array( 'dest_id' => $dest_id, 'meta_key' => $key, 'value' => $value )
			);
		}
	}

	/**
	 * Identify ACF meta patterns.
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @return bool
	 */
	private function is_acf_meta( string $key, $value ): bool {
		if ( strpos( $key, 'field_' ) === 0 ) {
			return true;
		}
		if ( strpos( $key, '_' ) === 0 && is_string( $value ) && strpos( $value, 'field_' ) === 0 ) {
			return true;
		}
		return false;
	}

	/**
	 * Identify Permalink Manager Pro meta patterns.
	 *
	 * @param string $key
	 * @return bool
	 */
	private function is_permalink_manager_meta( string $key ): bool {
		if ( strpos( $key, 'permalink_manager' ) === 0 ) {
			return true;
		}
		if ( strpos( $key, '_permalink_manager' ) === 0 ) {
			return true;
		}
		return false;
	}
}
