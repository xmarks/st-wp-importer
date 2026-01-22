<?php

/**
 * File logger for ST WP Importer.
 */
class St_Wp_Importer_Logger {

	/**
	 * @var string
	 */
	private $log_file;

	/**
	 * @var bool
	 */
	private $enabled;

	/**
	 * @var St_Wp_Importer_Settings
	 */
	private $settings;

	public function __construct( St_Wp_Importer_Settings $settings ) {
		$this->settings = $settings;
		$base_dir       = trailingslashit( dirname( __DIR__ ) );
		$this->log_file = $base_dir . 'stwi.log';

		$opts          = $this->settings->get();
		$this->enabled = (bool) ( $opts['enable_logging'] ?? true );
	}

	/**
	 * Enable/disable logging runtime (synced with settings).
	 *
	 * @param bool $enabled
	 */
	public function set_enabled( bool $enabled ): void {
		$this->enabled = $enabled;
	}

	/**
	 * Log a message to stwi.log
	 *
	 * @param string $level   e.g. INFO, ERROR
	 * @param string $message Message to log
	 * @param array  $context Context array (will be json encoded)
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$this->ensure_file();

		$context_str = '';
		if ( ! empty( $context ) ) {
			$context_str = ' ' . wp_json_encode( $context );
		}

		$line = sprintf(
			"[STWI] [%s] %s %s%s",
			strtoupper( $level ),
			wp_date( 'Y-m-d H:i:s' ),
			$message,
			$context_str
		);

		file_put_contents( $this->log_file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Get the last N lines from the log.
	 *
	 * @param int $lines
	 * @return string
	 */
	public function tail( int $lines = 100 ): string {
		if ( ! file_exists( $this->log_file ) ) {
			return '';
		}

		$lines = max( 1, $lines );
		$data  = file( $this->log_file );
		if ( false === $data ) {
			return '';
		}

		$tail = array_slice( $data, -1 * $lines );
		return implode( '', $tail );
	}

	/**
	 * Absolute path to log file.
	 *
	 * @return string
	 */
	public function file_path(): string {
		return $this->log_file;
	}

	/**
	 * Ensure file exists and is writable.
	 */
	private function ensure_file(): void {
		if ( file_exists( $this->log_file ) ) {
			return;
		}

		// Attempt to create the file.
		@touch( $this->log_file ); // phpcs:ignore
	}

	/**
	 * Truncate the log file.
	 */
	public function truncate(): void {
		$this->ensure_file();
		file_put_contents( $this->log_file, '' );
	}
}
