<?php

/**
 * Runtime state helper for ST WP Importer.
 */
class St_Wp_Importer_State {

	const OPTION_KEY = 'stwi_state';

	/**
	 * Default state structure.
	 *
	 * @return array
	 */
	public function defaults(): array {
		return array(
			'running'        => false,
			'stop_requested' => false,
			'last_run_at'    => 0,
			'next_run_at'    => 0,
			'active_post_types' => array(),
			'cursor'         => array(),
			'plugin_imports' => array(
				'powerpress_options' => 0,
				'acf_theme_settings' => 0,
			),
			'imported_options' => array(
				'acf'        => array(),
				'powerpress' => array(),
			),
			'stats'          => array(
				'posts_imported'   => 0,
				'posts_updated'    => 0,
				'posts_skipped'    => 0,
				'attachments_imported' => 0,
				'attachments_skipped'  => 0,
				'errors'           => 0,
			),
			'last_error'     => '',
		);
	}

	/**
	 * Retrieve current state merged with defaults.
	 *
	 * @return array
	 */
	public function get(): array {
		$saved    = get_option( self::OPTION_KEY, array() );
		$defaults = $this->defaults();

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_replace_recursive( $defaults, $saved );
	}

	/**
	 * Update state (deep merge).
	 *
	 * @param array $data New state data.
	 * @return array
	 */
	public function update( array $data ): array {
		$current = $this->get();
		$merged  = array_replace_recursive( $current, $data );
		update_option( self::OPTION_KEY, $merged );
		return $merged;
	}

	/**
	 * Reset on start.
	 *
	 * @param array $post_types Active post types.
	 * @return array
	 */
	public function mark_start( array $post_types ): array {
		$state = $this->update(
			array(
				'running'          => true,
				'stop_requested'   => false,
				'active_post_types'=> array_values( $post_types ),
				'last_error'       => '',
			)
		);
		return $state;
	}

	/**
	 * Mark stopped.
	 *
	 * @return array
	 */
	public function mark_stopped(): array {
		return $this->update(
			array(
				'running'        => false,
				'stop_requested' => false,
			)
		);
	}

	/**
	 * Request stop.
	 *
	 * @return array
	 */
	public function request_stop(): array {
		return $this->update(
			array(
				'stop_requested' => true,
			)
		);
	}

	/**
	 * Reset state to defaults.
	 *
	 * @return array
	 */
	public function reset(): array {
		$defaults = $this->defaults();
		update_option( self::OPTION_KEY, $defaults );
		return $defaults;
	}
}
