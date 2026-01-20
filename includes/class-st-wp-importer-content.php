<?php

/**
 * Content rewrite helper.
 */
class St_Wp_Importer_Content {

	/**
	 * @var St_Wp_Importer_Logger
	 */
	private $logger;

	/**
	 * @var St_Wp_Importer_Media
	 */
	private $media;

	/**
	 * @var St_Wp_Importer_Source_DB
	 */
	private $source_db;

	/**
	 * @var St_Wp_Importer_Settings
	 */
	private $settings;

	public function __construct(
		St_Wp_Importer_Logger $logger,
		St_Wp_Importer_Media $media,
		St_Wp_Importer_Source_DB $source_db,
		St_Wp_Importer_Settings $settings
	) {
		$this->logger    = $logger;
		$this->media     = $media;
		$this->source_db = $source_db;
		$this->settings  = $settings;
	}

	/**
	 * Rewrite content to new attachment URLs/IDs.
	 *
	 * @param string $content
	 * @param array  $settings
	 * @param bool   $dry_run
	 * @return array { content: string, attachments_imported: int }
	 */
	public function rewrite( string $content, array $settings, bool $dry_run = false ): array {
		$imported = 0;
		$updated_content = $content;

		// Replace wp-image-ID class.
		$updated_content = preg_replace_callback(
			'/wp-image-(\d+)/',
			function ( $matches ) use ( &$imported, $settings, $dry_run ) {
				$old_id = (int) $matches[1];
				$new_id = $this->media->import_attachment_by_id( $old_id, $settings, $dry_run );
				if ( $new_id ) {
					$imported++;
					$this->logger->log(
						'INFO',
						'Updated wp-image class',
						array( 'old_id' => $old_id, 'new_id' => $new_id )
					);
					return 'wp-image-' . $new_id;
				}
				$this->logger->log(
					'ERROR',
					'Failed to update wp-image class (attachment missing)',
					array( 'old_id' => $old_id )
				);
				return $matches[0];
			},
			$updated_content
		);

		// Replace Gutenberg comment JSON ids for wp:image/gallery/media-text.
		$updated_content = preg_replace_callback(
			'/("id":\s?)(\d+)/',
			function ( $matches ) use ( &$imported, $settings, $dry_run ) {
				$old_id = (int) $matches[2];
				$new_id = $this->media->import_attachment_by_id( $old_id, $settings, $dry_run );
				if ( $new_id ) {
					$imported++;
					$this->logger->log(
						'INFO',
						'Updated Gutenberg block id',
						array( 'old_id' => $old_id, 'new_id' => $new_id )
					);
					return $matches[1] . $new_id;
				}
				$this->logger->log(
					'ERROR',
					'Failed to update Gutenberg block id (attachment missing)',
					array( 'old_id' => $old_id )
				);
				return $matches[0];
			},
			$updated_content
		);

		// Replace direct upload URLs.
		$source_base = rtrim( $settings['source_site_url'], '/' ) . '/wp-content/uploads/';
		$updated_content = preg_replace_callback(
			'#' . preg_quote( $source_base, '#' ) . '([^\s"\'<>]+)#',
			function ( $matches ) use ( &$imported, $settings, $dry_run, $source_base ) {
				$path = $matches[1];
				$attached_file = ltrim( $path, '/' );
				$source_id     = $this->source_db->find_attachment_id_by_file( $attached_file, $settings );
				$new_id        = null;
				if ( $source_id ) {
					$new_id = $this->media->import_attachment_by_id( $source_id, $settings, $dry_run );
				} else {
					// Fallback: try to import via URL without mapping.
					$fake_post = array( 'post_title' => basename( $attached_file ), 'guid' => $source_base . $attached_file );
					$meta_rows = array( array( 'meta_key' => '_wp_attached_file', 'meta_value' => $attached_file ) );
					$new_id    = $this->media->import_attachment_record( 0, $fake_post, $meta_rows, $settings, $dry_run );
				}

				if ( $new_id ) {
					$imported++;
					$new_url = wp_get_attachment_url( $new_id );
					$this->logger->log(
						'INFO',
						'Rewrote upload URL',
						array(
							'old_url' => $matches[0],
							'new_url' => $new_url,
							'path'    => $attached_file,
						)
					);
					return $new_url ?: $matches[0];
				}

				$this->logger->log(
					'ERROR',
					'Failed to rewrite upload URL (attachment missing)',
					array(
						'old_url' => $matches[0],
						'path'    => $attached_file,
					)
				);
				return $matches[0];
			},
			$updated_content
		);

		return array(
			'content'               => $updated_content,
			'attachments_imported'  => $imported,
		);
	}
}
