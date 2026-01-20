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
		$tmp = download_url( $source_url );

		if ( is_wp_error( $tmp ) ) {
			$this->logger->log(
				'ERROR',
				'Failed to download attachment',
				array(
					'source_id'  => $source_id,
					'source_url' => $source_url,
					'error'      => $tmp->get_error_message(),
				)
			);
			return null;
		}

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
			@unlink( $tmp ); // phpcs:ignore
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

		$this->logger->log(
			'INFO',
			'Imported attachment',
			array(
				'source_id'   => $source_id,
				'dest_id'     => $attachment_id,
				'source_url'  => $source_url,
				'subdir'      => $subdir,
				'post_title'  => $post_row['post_title'] ?? '',
			)
		);

		return (int) $attachment_id;
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
}
