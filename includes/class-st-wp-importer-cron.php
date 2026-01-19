<?php

/**
 * Cron scheduling helper.
 */
class St_Wp_Importer_Cron {

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
	 * @var St_Wp_Importer_Importer
	 */
	private $importer;

	public function __construct(
		St_Wp_Importer_Settings $settings,
		St_Wp_Importer_State $state,
		St_Wp_Importer_Logger $logger,
		St_Wp_Importer_Importer $importer
	) {
		$this->settings = $settings;
		$this->state    = $state;
		$this->logger   = $logger;
		$this->importer = $importer;
	}

	/**
	 * Add custom schedule.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public function add_schedule( array $schedules ): array {
		$options = $this->settings->get();
		$minutes = max( 1, (int) ( $options['run_interval_minutes'] ?? 1 ) );
		$hook    = $this->get_schedule_name( $minutes );

		$schedules[ $hook ] = array(
			'interval' => $minutes * 60,
			'display'  => sprintf( 'Every %d minutes (STWI)', $minutes ),
		);

		return $schedules;
	}

	/**
	 * Hook handler for cron batch.
	 */
	public function handle_scheduled_batch(): void {
		$this->importer->run_batch();
	}

	/**
	 * Schedule event if missing.
	 */
	public function ensure_scheduled(): void {
		$options  = $this->settings->get();
		$minutes  = max( 1, (int) ( $options['run_interval_minutes'] ?? 1 ) );
		$schedule = $this->get_schedule_name( $minutes );

		if ( ! wp_next_scheduled( 'stwi_run_batch' ) ) {
			wp_schedule_event( time() + 10, $schedule, 'stwi_run_batch' );
			$this->logger->log( 'INFO', 'Cron scheduled', array( 'schedule' => $schedule ) );
		}
	}

	/**
	 * Clear scheduled hook.
	 */
	public function clear_schedule(): void {
		wp_clear_scheduled_hook( 'stwi_run_batch' );
		$this->logger->log( 'INFO', 'Cron cleared' );
	}

	/**
	 * Build schedule name.
	 *
	 * @param int $minutes
	 * @return string
	 */
	public function get_schedule_name( int $minutes ): string {
		return 'stwi_every_' . $minutes . '_minutes';
	}
}
