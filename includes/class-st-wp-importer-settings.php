<?php

/**
 * Settings helper for ST WP Importer.
 *
 * Handles defaults, retrieval, and sanitization for stwi_settings.
 */
class St_Wp_Importer_Settings {

	const OPTION_KEY = 'stwi_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public function defaults(): array {
		return array(
			'source_db_host'      => 'localhost',
			'source_db_port'      => 3306,
			'source_db_name'      => 'gpstrategies-source',
			'source_db_user'      => 'root',
			'source_db_pass'      => '',
			'source_site_url'     => 'https://www.gpstrategies.com/',
			'source_table_prefix' => 'wp_',
			'source_blog_id'      => 1,
			'posts_per_run'       => 5,
			'run_interval_minutes'=> 1,
			'dry_run'             => 1,
			'enable_logging'      => 1,
			'plugin_yoastseo'     => 1,
			'plugin_acf'         => 1,
			'plugin_hreflang'     => 1,
			'plugin_permalink_manager' => 1,
			// Redirection excluded from migration scope.
		'import_scope'        => array(
				array(
					'post_type'  => 'post',
					'taxonomies' => array( 'category', 'post_tag', 'topic', 'industry' ),
				),
				array(
					'post_type'  => 'solutions-cpt',
					'taxonomies' => array( 'solutions-category', 'industry' ),
				),
				array(
					'post_type'  => 'events-cpt',
					'taxonomies' => array( 'event-type', 'topic', 'industry' ),
				),
				array(
					'post_type'  => 'news-cpt',
					'taxonomies' => array( 'news-category', 'industry' ),
				),
				array(
					'post_type'  => 'podcasts-cpt',
					'taxonomies' => array( 'podcasts-host', 'topic', 'industry' ),
				),
				array(
					'post_type'  => 'webinars-cpt',
					'taxonomies' => array( 'topic', 'industry' ),
				),
				array(
					'post_type'  => 'resource',
					'taxonomies' => array( 'resource-type', 'topic', 'industry' ),
				),
				array(
					'post_type'  => 'case-study-cpt',
					'taxonomies' => array( 'case-study-category', 'industry' ),
				),
				array(
					'post_type'  => 'training-course',
					'taxonomies' => array( 'modality', 'leadership-level', 'course-type', 'topic' ),
				),
				array(
					'post_type'  => 'acab-cpt',
					'taxonomies' => array( 'acab-leadership-category' ),
				),
			),
		);
	}

	/**
	 * Get settings merged with defaults.
	 *
	 * @return array
	 */
	public function get(): array {
		$saved    = get_option( self::OPTION_KEY, array() );
		$defaults = $this->defaults();

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$merged = array_replace_recursive( $defaults, $saved );
		$merged['import_scope'] = $this->sanitize_scope( $merged['import_scope'] ?? array() );

		return $merged;
	}

	/**
	 * Save sanitized settings.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized values.
	 */
	public function save( array $input ): array {
		$sanitized = $this->sanitize( $input );
		update_option( self::OPTION_KEY, $sanitized );
		return $sanitized;
	}

	/**
	 * Sanitize input.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( array $input ): array {
		$defaults = $this->defaults();
		$out      = $defaults;

		$out['source_db_host']       = sanitize_text_field( $input['source_db_host'] ?? $defaults['source_db_host'] );
		$out['source_db_port']       = absint( $input['source_db_port'] ?? $defaults['source_db_port'] );
		$out['source_db_name']       = sanitize_text_field( $input['source_db_name'] ?? $defaults['source_db_name'] );
		$out['source_db_user']       = sanitize_text_field( $input['source_db_user'] ?? $defaults['source_db_user'] );
		$out['source_db_pass']       = $input['source_db_pass'] ?? $defaults['source_db_pass']; // keep raw for connection
		$out['source_site_url']      = esc_url_raw( $input['source_site_url'] ?? $defaults['source_site_url'] );
		$out['source_table_prefix']  = sanitize_text_field( $input['source_table_prefix'] ?? $defaults['source_table_prefix'] );
		$out['source_blog_id']       = absint( $input['source_blog_id'] ?? $defaults['source_blog_id'] );
		$out['posts_per_run']        = max( 1, absint( $input['posts_per_run'] ?? $defaults['posts_per_run'] ) );
		$out['run_interval_minutes'] = max( 1, absint( $input['run_interval_minutes'] ?? $defaults['run_interval_minutes'] ) );
		$out['dry_run']              = isset( $input['dry_run'] ) ? 1 : 0;
		$out['enable_logging']       = isset( $input['enable_logging'] ) ? 1 : 0;
		$out['plugin_yoastseo']      = isset( $input['plugin_yoastseo'] ) ? 1 : 0;
		$out['plugin_acf']           = isset( $input['plugin_acf'] ) ? 1 : 0;
		$out['plugin_hreflang']      = isset( $input['plugin_hreflang'] ) ? 1 : 0;
		$out['plugin_permalink_manager'] = isset( $input['plugin_permalink_manager'] ) ? 1 : 0;
		// Redirection intentionally excluded.

		$raw_scope            = $input['import_scope'] ?? array();
		$out['import_scope']  = $this->sanitize_scope( $raw_scope );

		return $out;
	}

	/**
	 * Normalize post types (convert "posts" -> "post").
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	private function normalize_post_type( string $post_type ): string {
		$post_type = sanitize_key( $post_type );
		if ( 'posts' === $post_type ) {
			return 'post';
		}
		return $post_type;
	}

	/**
	 * Sanitize the import scope structure.
	 *
	 * @param mixed $scope Scope input.
	 * @return array
	 */
	private function sanitize_scope( $scope ): array {
		$clean = array();

		if ( ! is_array( $scope ) ) {
			return $clean;
		}

		foreach ( $scope as $row ) {
			if ( empty( $row['post_type'] ) ) {
				continue;
			}
			$post_type = $this->normalize_post_type( (string) $row['post_type'] );
			if ( empty( $post_type ) || 'page' === $post_type ) {
				continue;
			}

			$taxes_in  = $row['taxonomies'] ?? array();
			if ( is_string( $taxes_in ) ) {
				// Accept comma-separated input.
				$taxes_in = array_map( 'trim', explode( ',', $taxes_in ) );
			}
			$taxonomies = array();
			if ( is_array( $taxes_in ) ) {
				foreach ( $taxes_in as $tax ) {
					$tax = sanitize_key( $tax );
					if ( ! empty( $tax ) ) {
						$taxonomies[] = $tax;
					}
				}
			}
			$taxonomies = array_values( array_unique( $taxonomies ) );

			$clean[] = array(
				'post_type'  => $post_type,
				'taxonomies' => $taxonomies,
			);
		}

		// Ensure unique post types (keep first occurrence).
		$unique = array();
		foreach ( $clean as $row ) {
			if ( isset( $unique[ $row['post_type'] ] ) ) {
				continue;
			}
			$unique[ $row['post_type'] ] = $row;
		}

		return array_values( $unique );
	}
}
