<?php

/**
 * Mapping table helper.
 */
class St_Wp_Importer_Map {

	/**
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * @var string
	 */
	private $table;

	/**
	 * @var St_Wp_Importer_Logger
	 */
	private $logger;

	public function __construct( St_Wp_Importer_Logger $logger ) {
		global $wpdb;
		$this->wpdb   = $wpdb;
		$this->table  = $this->wpdb->prefix . 'stwi_map';
		$this->logger = $logger;
	}

	/**
	 * Create mapping table if missing.
	 */
	public function maybe_create_table(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $this->wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$this->table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_blog_id int(11) NOT NULL,
			source_object_type varchar(32) NOT NULL,
			source_id bigint(20) unsigned NOT NULL,
			dest_id bigint(20) unsigned NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_source (source_blog_id, source_object_type, source_id),
			KEY dest_idx (dest_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Fetch destination id for a source object.
	 *
	 * @param int    $source_blog_id
	 * @param string $object_type
	 * @param int    $source_id
	 * @return int|null
	 */
	public function get_destination_id( int $source_blog_id, string $object_type, int $source_id ): ?int {
		$sql = $this->wpdb->prepare(
			"SELECT dest_id FROM {$this->table} WHERE source_blog_id = %d AND source_object_type = %s AND source_id = %d",
			$source_blog_id,
			$object_type,
			$source_id
		);
		$dest_id = $this->wpdb->get_var( $sql );
		if ( null === $dest_id ) {
			return null;
		}
		return (int) $dest_id;
	}

	/**
	 * Insert or update mapping.
	 *
	 * @param int    $source_blog_id
	 * @param string $object_type
	 * @param int    $source_id
	 * @param int    $dest_id
	 * @return void
	 */
	public function upsert( int $source_blog_id, string $object_type, int $source_id, int $dest_id ): void {
		$data = array(
			'source_blog_id'    => $source_blog_id,
			'source_object_type'=> $object_type,
			'source_id'         => $source_id,
			'dest_id'           => $dest_id,
		);

		$existing = $this->get_destination_id( $source_blog_id, $object_type, $source_id );

		if ( null === $existing ) {
			$this->wpdb->insert( $this->table, $data );
		} else {
			$this->wpdb->update(
				$this->table,
				array( 'dest_id' => $dest_id ),
				array(
					'source_blog_id'     => $source_blog_id,
					'source_object_type' => $object_type,
					'source_id'          => $source_id,
				)
			);
		}

		$this->logger->log(
			'INFO',
			'Mapping updated',
			array(
				'object_type' => $object_type,
				'source_id'   => $source_id,
				'dest_id'     => $dest_id,
			)
		);
	}

	/**
	 * Fetch mapping rows for deletion in batches.
	 *
	 * @param int $limit
	 * @return array
	 */
	public function get_rows_for_deletion( int $limit = 20 ): array {
		$limit = max( 1, $limit );
		$sql   = $this->wpdb->prepare(
			"SELECT id, source_blog_id, source_object_type, source_id, dest_id FROM {$this->table} ORDER BY id ASC LIMIT %d",
			$limit
		);
		return $this->wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	/**
	 * Delete a mapping row by id.
	 *
	 * @param int $id
	 * @return void
	 */
	public function delete_row( int $id ): void {
		$this->wpdb->delete( $this->table, array( 'id' => $id ) );
	}

	/**
	 * Count remaining mapped objects (post/attachment).
	 *
	 * @return int
	 */
	public function count_remaining(): int {
		$count = $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
		return (int) $count;
	}

	/**
	 * Delete all mappings.
	 */
	public function delete_all(): void {
		$this->wpdb->query( "TRUNCATE TABLE {$this->table}" ); // phpcs:ignore
	}
}
