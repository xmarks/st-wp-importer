<?php

/**
 * Content rewrite helper.
 */
class St_Wp_Importer_Content {
	use St_Wp_Importer_Media_Helpers;

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

		// Rewrite ACF block attribute media (e.g., image/file fields stored as IDs in block JSON).
		$acf_rewrite = $this->rewrite_acf_block_media( $updated_content, $settings, $dry_run );
		$updated_content = $acf_rewrite['content'];
		$imported       += $acf_rewrite['attachments_imported'];

		return array(
			'content'               => $updated_content,
			'attachments_imported'  => $imported,
		);
	}

	/**
	 * Parse block markup and rewrite ACF block media fields (image/file/gallery).
	 *
	 * ACF blocks store raw field values in block comment JSON (e.g., "section_image":123).
	 * These IDs are not caught by the generic Gutenberg id/url regex above, so we parse blocks,
	 * detect ACF field types, import the referenced attachments, and swap in destination IDs.
	 */
	private function rewrite_acf_block_media( string $content, array $settings, bool $dry_run ): array {
		if ( strpos( $content, 'acf/' ) === false || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return array( 'content' => $content, 'attachments_imported' => 0 );
		}

		$blocks = parse_blocks( $content );
		if ( empty( $blocks ) ) {
			return array( 'content' => $content, 'attachments_imported' => 0 );
		}

		$modified  = false;
		$imported  = 0;
		$rewritten = $this->walk_acf_blocks( $blocks, $settings, $dry_run, $imported, $modified );

		if ( ! $modified ) {
			return array( 'content' => $content, 'attachments_imported' => 0 );
		}

		return array(
			'content'              => serialize_blocks( $rewritten ),
			'attachments_imported' => $imported,
		);
	}

	/**
	 * Recursively process blocks to rewrite ACF media field values.
	 *
	 * @param array $blocks
	 * @param array $settings
	 * @param bool  $dry_run
	 * @param int   $imported
	 * @param bool  $modified
	 * @return array
	 */
	private function walk_acf_blocks( array $blocks, array $settings, bool $dry_run, int &$imported, bool &$modified ): array {
		foreach ( $blocks as &$block ) {
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->walk_acf_blocks( $block['innerBlocks'], $settings, $dry_run, $imported, $modified );
			}

			if ( empty( $block['blockName'] ) || strpos( $block['blockName'], 'acf/' ) !== 0 ) {
				continue;
			}
			if ( empty( $block['attrs']['data'] ) || ! is_array( $block['attrs']['data'] ) ) {
				continue;
			}

			$data          = $block['attrs']['data'];
			$block_changed = false;

			foreach ( $data as $key => $value ) {
				if ( strpos( $key, '_' ) === 0 ) {
					continue;
				}

				$field_key  = $data[ '_' . $key ] ?? '';
				$field_type = $this->get_acf_field_type( $field_key );

				if ( in_array( $field_type, array( 'image', 'file' ), true )
					|| ( ! $field_type && $this->looks_like_media_meta_key( $key ) ) ) {
					$rewrite = $this->maybe_import_acf_media_value( $key, $value, $settings, $dry_run, array( $key ) );
					if ( $rewrite['imported'] > 0 || $rewrite['value'] !== $value ) {
						$data[ $key ] = $rewrite['value'];
						$imported    += $rewrite['imported'];
						$block_changed = true;
					}
				} elseif ( 'gallery' === $field_type && is_array( $value ) ) {
					$gallery_changed = false;
					$new_gallery     = array();
					foreach ( $value as $item ) {
						if ( is_numeric( $item ) ) {
							$new_id = $this->media->import_attachment_by_id( (int) $item, $settings, $dry_run );
							if ( $new_id ) {
								$new_gallery[]   = $new_id;
								$imported++;
								$gallery_changed = true;
								continue;
							}
						}
						$new_gallery[] = $item;
					}
					if ( $gallery_changed ) {
						$data[ $key ]   = $new_gallery;
						$block_changed  = true;
					}
				}
			}

			if ( $block_changed ) {
				$block['attrs']['data'] = $data;
				$modified               = true;
			}
		}

		return $blocks;
	}

	/**
	 * Resolve ACF field type for a field key (field_XXXX) if available.
	 */
	private function get_acf_field_type( string $field_key ): ?string {
		if ( empty( $field_key ) || ! function_exists( 'acf_get_field' ) ) {
			return null;
		}
		$field = acf_get_field( $field_key );
		if ( $field && is_array( $field ) && ! empty( $field['type'] ) ) {
			return $field['type'];
		}
		return null;
	}
}
