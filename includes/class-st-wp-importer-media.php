<?php

/**
 * Media importer helper.
 */
class St_Wp_Importer_Media {

	/**
	 * @var St_Wp_Importer_Settings
	 */
	private $settings;

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

	public function __construct(
		St_Wp_Importer_Settings $settings,
		St_Wp_Importer_Logger $logger,
		St_Wp_Importer_Map $map,
		St_Wp_Importer_Source_DB $source_db
	) {
		$this->settings  = $settings;
		$this->logger    = $logger;
		$this->map       = $map;
		$this->source_db = $source_db;
	}

	/**
	 * Import an attachment by source ID.
	 *
	 * @param int   $source_attachment_id
	 * @param array $settings
	 * @param bool  $dry_run
	 * @return int|null Destination attachment ID or null on failure.
	 */
	public function import_attachment_by_id( int $source_attachment_id, array $settings, bool $dry_run = false ): ?int {
		$existing = $this->map->get_destination_id( (int) $settings['source_blog_id'], 'attachment', $source_attachment_id );
		if ( $existing ) {
			$this->logger->log(
				'INFO',
				'Attachment already mapped',
				array(
					'source_id' => $source_attachment_id,
					'dest_id'   => $existing,
				)
			);
			return $existing;
		}

		$attachment = $this->source_db->get_post_with_meta( $source_attachment_id, $settings );
		if ( empty( $attachment['post'] ) ) {
			$this->logger->log( 'ERROR', 'Attachment not found in source DB', array( 'attachment_id' => $source_attachment_id ) );
			return null;
		}

		return $this->import_attachment_record( $source_attachment_id, $attachment['post'], $attachment['meta'], $settings, $dry_run );
	}

	/**
	 * Import attachment record using source post + meta.
	 *
	 * @param int   $source_id
	 * @param array $post_row
	 * @param array $meta_rows
	 * @param array $settings
	 * @param bool  $dry_run
	 * @return int|null
	 */
	public function import_attachment_record( int $source_id, array $post_row, array $meta_rows, array $settings, bool $dry_run = false ): ?int {
		try {
			// Ensure media/side-load functions are available (cron context may miss admin includes).
			if ( ! function_exists( '\media_handle_sideload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			$source_blog_id = (int) $settings['source_blog_id'];
			if ( $source_id > 0 ) {
				$existing = $this->map->get_destination_id( $source_blog_id, 'attachment', $source_id );
				if ( $existing ) {
					return $existing;
			}
		}

		$attached_file = '';
		$meta_map      = array();
		foreach ( $meta_rows as $meta_row ) {
			$key                = $meta_row['meta_key'];
			$meta_map[ $key ][] = $meta_row['meta_value'];
			if ( '_wp_attached_file' === $key ) {
				$attached_file = $meta_row['meta_value'];
			}
		}

		if ( empty( $attached_file ) && ! empty( $post_row['guid'] ) && strpos( $post_row['guid'], '/wp-content/uploads/' ) !== false ) {
			$parts = explode( '/wp-content/uploads/', $post_row['guid'] );
			if ( ! empty( $parts[1] ) ) {
				$attached_file = $parts[1];
			}
		}

		if ( empty( $attached_file ) ) {
			$this->logger->log(
				'ERROR',
				'Could not determine attached file path for attachment',
				array(
					'source_id' => $source_id,
					'guid'      => $post_row['guid'] ?? '',
				)
			);
			return null;
		}

		$source_url = rtrim( $settings['source_site_url'], '/' ) . '/wp-content/uploads/' . ltrim( $attached_file, '/' );
		$subdir     = $this->extract_subdir( $attached_file );

		if ( $dry_run ) {
			$this->logger->log(
				'INFO',
				'[DRY RUN] Would import attachment',
				array(
					'source_id'   => $source_id,
					'source_url'  => $source_url,
					'subdir'      => $subdir,
					'post_title'  => $post_row['post_title'] ?? '',
				)
			);
			return null;
		}

		$this->logger->log(
			'INFO',
			'Downloading attachment',
			array(
				'source_id'  => $source_id,
				'source_url' => $source_url,
				'subdir'     => $subdir,
			)
		);
		$tmp = $this->download_with_retry( $source_url, 3, 2 );

		if ( is_wp_error( $tmp ) ) {
			$this->logger->log(
				'ERROR',
				'Failed to download attachment after retries',
				array(
					'source_id'  => $source_id,
					'source_url' => $source_url,
					'error'      => $tmp->get_error_message(),
				)
			);
			return null;
		}

		$this->logger->log(
			'INFO',
			'Attachment sideload starting',
			array(
				'source_id'  => $source_id,
				'source_url' => $source_url,
				'tmp'        => $tmp,
				'size'       => file_exists( $tmp ) ? filesize( $tmp ) : 0,
				'subdir'     => $subdir,
			)
		);

		$file_array = array(
			'name'     => basename( $attached_file ),
			'tmp_name' => $tmp,
		);

		$upload_filter = function ( $dirs ) use ( $subdir ) {
			if ( ! empty( $subdir ) ) {
				$dirs['subdir'] = $subdir;
				$dirs['path']  .= $subdir;
				$dirs['url']   .= $subdir;
			}
			return $dirs;
		};

		add_filter( 'upload_dir', $upload_filter );
		$attachment_id = media_handle_sideload( $file_array, 0, $post_row['post_title'] ?? '' );
		remove_filter( 'upload_dir', $upload_filter );

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				@unlink( $tmp ); // phpcs:ignore
			}
			$this->logger->log(
				'ERROR',
				'Failed to sideload attachment',
				array(
					'source_id'  => $source_id,
					'source_url' => $source_url,
					'error'      => $attachment_id->get_error_message(),
				)
			);
			return null;
		}

		// Map and meta.
		if ( $source_id > 0 ) {
			$this->map->upsert( $source_blog_id, 'attachment', $source_id, (int) $attachment_id );
			update_post_meta( $attachment_id, '_stwi_source_attachment_id', $source_id );
		} else {
			$hash = abs( crc32( $attached_file ) );
			$this->map->upsert( $source_blog_id, 'attachment_url', $hash, (int) $attachment_id );
		}
		update_post_meta( $attachment_id, '_stwi_source_attached_file', $attached_file );

		$attached_path = get_attached_file( $attachment_id );
		$final_size    = ( $attached_path && file_exists( $attached_path ) ) ? filesize( $attached_path ) : 0;

		$this->logger->log(
			'INFO',
			'Imported attachment',
			array(
				'source_id'   => $source_id,
				'dest_id'     => $attachment_id,
				'source_url'  => $source_url,
				'subdir'      => $subdir,
				'post_title'  => $post_row['post_title'] ?? '',
				'size'        => $final_size,
				'attached'    => $attached_path,
			)
		);

		return (int) $attachment_id;
		} catch ( \Throwable $e ) {
			$this->logger->log(
				'ERROR',
				'Attachment import threw exception',
				array(
					'source_id'  => $source_id,
					'source_url' => $source_url ?? '',
					'message'    => $e->getMessage(),
					'file'       => $e->getFile(),
					'line'       => $e->getLine(),
				)
			);
			return null;
		}
	}

	/**
	 * Import an attachment from a source URL (uploads), mapping by file path if possible.
	 *
	 * @param string $source_url
	 * @param array  $settings
	 * @param bool   $dry_run
	 * @return int|null
	 */
	public function import_attachment_from_url( string $source_url, array $settings, bool $dry_run = false ): ?int {
		$base = rtrim( $settings['source_site_url'], '/' ) . '/wp-content/uploads/';
		if ( strpos( $source_url, $base ) !== 0 ) {
			$this->logger->log(
				'INFO',
				'Skipped attachment import (external URL)',
				array( 'source_url' => $source_url )
			);
			return null;
		}

		$attached_file = ltrim( str_replace( $base, '', $source_url ), '/' );
		$found_id      = $this->source_db->find_attachment_id_by_file( $attached_file, $settings );
		if ( $found_id ) {
			return $this->import_attachment_by_id( $found_id, $settings, $dry_run );
		}

		// Fallback: import directly from URL with fake meta.
		$fake_post = array(
			'post_title' => basename( $attached_file ),
			'guid'       => $source_url,
		);
		$meta_rows = array(
			array( 'meta_key' => '_wp_attached_file', 'meta_value' => $attached_file ),
		);

		return $this->import_attachment_record( 0, $fake_post, $meta_rows, $settings, $dry_run );
	}

	/**
	 * Extract subdir (/YYYY/MM) from attached file path.
	 *
	 * @param string $attached_file
	 * @return string
	 */
	private function extract_subdir( string $attached_file ): string {
		if ( preg_match( '#(\d{4}/\d{2})#', $attached_file, $m ) ) {
			return '/' . $m[1];
		}
		return '';
	}

	/**
	 * Download with retries.
	 *
	 * @param string $url
	 * @param int    $max_attempts
	 * @param int    $sleep_seconds
	 * @return string|\WP_Error
	 */
	private function download_with_retry( string $url, int $max_attempts = 3, int $sleep_seconds = 2 ) {
		$attempt = 0;
		$is_cron = function_exists( 'wp_doing_cron' ) && wp_doing_cron();

		try {
			do {
				$attempt++;

				$this->logger->log(
					'INFO',
					'Attachment download attempt',
					array(
						'url'     => $url,
						'attempt' => $attempt,
						'cron'    => $is_cron ? 'yes' : 'no',
						'timeout' => 30,
					)
				);

				$tmp = $this->stream_download( $url, $is_cron );

				if ( is_wp_error( $tmp ) ) {
					$this->logger->log(
						'ERROR',
						'Attachment download attempt result',
						array(
							'url'        => $url,
							'attempt'    => $attempt,
							'result'     => 'error',
							'error_code' => $tmp->get_error_code(),
							'error'      => $tmp->get_error_message(),
							'error_data' => $tmp->get_error_data(),
							'cron'       => $is_cron ? 'yes' : 'no',
						)
					);
				} else {
					$this->logger->log(
						'INFO',
						'Attachment download attempt result',
						array(
							'url'     => $url,
							'attempt' => $attempt,
							'result'  => 'ok',
							'file'    => $tmp,
							'cron'    => $is_cron ? 'yes' : 'no',
						)
					);
				}

				if ( ! is_wp_error( $tmp ) ) {
					if ( $attempt > 1 ) {
						$this->logger->log(
							'INFO',
							'Attachment download succeeded after retry',
							array( 'url' => $url, 'attempt' => $attempt )
						);
					}
					$this->logger->log(
						'INFO',
						'Attachment download success',
						array(
							'url'     => $url,
							'attempt' => $attempt,
							'file'    => $tmp,
							'cron'    => $is_cron ? 'yes' : 'no',
						)
					);
					return $tmp;
				}

				$error_data = $tmp->get_error_data();
				$this->logger->log(
					'ERROR',
					'Attachment download failed',
					array(
						'url'        => $url,
						'attempt'    => $attempt,
						'error_code' => $tmp->get_error_code(),
						'error'      => $tmp->get_error_message(),
						'error_data' => $error_data,
						'cron'       => $is_cron ? 'yes' : 'no',
					)
				);

				$this->logger->log(
					'ERROR',
					'Attachment download will retry (if attempts remain)',
					array( 'url' => $url, 'attempt' => $attempt, 'max_attempts' => $max_attempts )
				);
				if ( $attempt < $max_attempts ) {
					sleep( $sleep_seconds );
				}
			} while ( $attempt < $max_attempts );

			return $tmp;
		} catch ( \Throwable $e ) {
			$this->logger->log(
				'ERROR',
				'Attachment download threw exception',
				array(
					'url'     => $url,
					'cron'    => $is_cron ? 'yes' : 'no',
					'message' => $e->getMessage(),
					'line'    => $e->getLine(),
					'file'    => $e->getFile(),
				)
			);
			return new \WP_Error( 'stwi_download_exception', $e->getMessage() );
		}
	}

	/**
	 * Stream download to a temp file with explicit HTTP args (helps when cron vs manual differ).
	 *
	 * @param string $url
	 * @param bool   $is_cron
	 * @return string|\WP_Error
	 */
	private function stream_download( string $url, bool $is_cron ) {
		$this->logger->log(
			'INFO',
			'Attachment stream start',
			array(
				'url'  => $url,
				'cron' => $is_cron ? 'yes' : 'no',
			)
		);

		$tmp = function_exists( 'wp_tempnam' ) ? wp_tempnam( $url ) : tempnam( sys_get_temp_dir(), 'stwi_' );
		if ( ! $tmp ) {
			return new \WP_Error( 'stwi_tmp_fail', 'Could not create temp file for download' );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 3,
				'user-agent'  => 'st-wp-importer',
				'stream'      => true,
				'filename'    => $tmp,
			)
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp ); // phpcs:ignore
			$this->logger->log(
				'ERROR',
				'Attachment stream request failed',
				array(
					'url'        => $url,
					'cron'       => $is_cron ? 'yes' : 'no',
					'error'      => $response->get_error_message(),
					'error_code' => $response->get_error_code(),
					'error_data' => $response->get_error_data(),
				)
			);
			return $response;
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$headers = wp_remote_retrieve_headers( $response );
		$headers_out = array();
		if ( is_array( $headers ) ) {
			$headers_out = $headers;
		} elseif ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers_out = $headers->getAll();
		}
		$message = wp_remote_retrieve_response_message( $response );

		$this->logger->log(
			'INFO',
			'Attachment response (stream)',
			array(
				'url'         => $url,
				'status_code' => $code,
				'message'     => $message,
				'headers'     => $headers_out,
				'cron'        => $is_cron ? 'yes' : 'no',
				'file'        => $tmp,
				'filesize'    => file_exists( $tmp ) ? filesize( $tmp ) : 0,
			)
		);

		if ( $code < 200 || $code >= 300 ) {
			@unlink( $tmp ); // phpcs:ignore
			return new \WP_Error( 'stwi_http_status', 'Non-200 response when downloading attachment', array( 'status' => $code, 'message' => $message ) );
		}

		if ( ! file_exists( $tmp ) || filesize( $tmp ) === 0 ) {
			@unlink( $tmp ); // phpcs:ignore
			return new \WP_Error( 'stwi_empty_file', 'Downloaded file is empty', array( 'status' => $code ) );
		}

		return $tmp;
	}
}
