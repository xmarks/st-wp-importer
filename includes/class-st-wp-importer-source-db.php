<?php

/**
 * Source DB connector helper.
 */
class St_Wp_Importer_Source_DB {

	/**
	 * @var wpdb|null
	 */
	private $external_db;

	/**
	 * @var St_Wp_Importer_Logger
	 */
	private $logger;

	public function __construct( St_Wp_Importer_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Build a wpdb instance for the source DB.
	 *
	 * @param array $settings Settings array.
	 * @return wpdb
	 */
	public function connect( array $settings ): wpdb {
		$db_user = $settings['source_db_user'] ?? '';
		$db_pass = $settings['source_db_pass'] ?? '';
		$db_name = $settings['source_db_name'] ?? '';
		$db_host = $settings['source_db_host'] ?? 'localhost';
		$db_port = $settings['source_db_port'] ?? 3306;

		$host = $db_host;
		if ( ! empty( $db_port ) ) {
			$host .= ':' . (int) $db_port;
		}

		$this->external_db = new wpdb( $db_user, $db_pass, $db_name, $host );
		$this->external_db->show_errors( false );

		return $this->external_db;
	}

	/**
	 * Test connection.
	 *
	 * @param array $settings Settings array.
	 * @return array { success: bool, message: string, count?: int }
	 */
	public function test_connection( array $settings ): array {
		$db = $this->connect( $settings );

		if ( ! empty( $db->error ) ) {
			$this->logger->log( 'ERROR', 'Source DB connection failed', array( 'error' => $db->error ) );
			return array(
				'success' => false,
				'message' => 'Connection failed: ' . $db->error,
			);
		}

		$prefix = $settings['source_table_prefix'] ?? 'wp_';
		$table  = $prefix . 'posts';
		$count  = $db->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( null === $count ) {
			$error = $db->last_error ?: 'Unknown error during COUNT query.';
			$this->logger->log( 'ERROR', 'Source DB test query failed', array( 'error' => $error ) );
			return array(
				'success' => false,
				'message' => 'Query failed: ' . $error,
			);
		}

		$this->logger->log( 'INFO', 'Source DB connection successful', array( 'count' => (int) $count ) );

		return array(
			'success' => true,
			'message' => sprintf( 'Connection OK. wp_posts count: %d', (int) $count ),
			'count'   => (int) $count,
		);
	}
}
