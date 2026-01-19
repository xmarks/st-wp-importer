<?php

/**
 * Batch importer (stub for now).
 *
 * Future work: implement real post/media import. Currently logs and returns early
 * to keep cron/controls wired without breaking the UI.
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

	public function __construct(
		St_Wp_Importer_Settings $settings,
		St_Wp_Importer_State $state,
		St_Wp_Importer_Logger $logger,
		St_Wp_Importer_Map $map,
		St_Wp_Importer_Source_DB $source_db
	) {
		$this->settings  = $settings;
		$this->state     = $state;
		$this->logger    = $logger;
		$this->map       = $map;
		$this->source_db = $source_db;
	}

	/**
	 * Run a batch (placeholder).
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

		$settings = $this->settings->get();
		$this->logger->log(
			'INFO',
			'Batch runner placeholder executed (import logic pending).',
			array(
				'post_types' => wp_list_pluck( $settings['import_scope'], 'post_type' ),
				'dry_run'    => (bool) $settings['dry_run'],
			)
		);

		$now = time();
		$this->state->update(
			array(
				'last_run_at' => $now,
			)
		);

		return array(
			'success' => true,
			'message' => 'Batch executed (stub).',
		);
	}
}
