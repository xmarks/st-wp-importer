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

	/**
	 * Compute table names based on blog id/prefix.
	 *
	 * @param array $settings
	 * @return array
	 */
	public function get_tables( array $settings ): array {
		$prefix  = $settings['source_table_prefix'] ?? 'wp_';
		$blog_id = (int) ( $settings['source_blog_id'] ?? 1 );
		$base    = $prefix;
		if ( $blog_id > 1 ) {
			$base = $prefix . $blog_id . '_';
		}
		return array(
			'posts'             => $base . 'posts',
			'postmeta'          => $base . 'postmeta',
			'terms'             => $prefix . 'terms',
			'term_taxonomy'     => $prefix . 'term_taxonomy',
			'term_relationships'=> $base . 'term_relationships',
		);
	}

	/**
	 * Get options table name for the source site/blog.
	 *
	 * @param array $settings
	 * @return string
	 */
	public function get_options_table( array $settings ): string {
		$prefix  = $settings['source_table_prefix'] ?? 'wp_';
		$blog_id = (int) ( $settings['source_blog_id'] ?? 1 );
		$base    = $prefix;
		if ( $blog_id > 1 ) {
			$base = $prefix . $blog_id . '_';
		}
		return $base . 'options';
	}

	/**
	 * Fetch posts of a type after cursor.
	 *
	 * @param string $post_type
	 * @param int    $after_id
	 * @param int    $limit
	 * @param array  $settings
	 * @return array
	 */
	public function fetch_posts( string $post_type, int $after_id, int $limit, array $settings ): array {
		$db      = $this->connect( $settings );
		$tables  = $this->get_tables( $settings );
		$limit   = max( 1, $limit );
		$sql     = $db->prepare(
			"SELECT * FROM {$tables['posts']} WHERE post_type = %s AND ID > %d AND post_status NOT IN ('auto-draft','trash') ORDER BY ID ASC LIMIT %d",
			$post_type,
			$after_id,
			$limit
		);
		return $db->get_results( $sql, ARRAY_A ) ?: array();
	}

	/**
	 * Fetch meta for a post.
	 *
	 * @param int   $post_id
	 * @param array $settings
	 * @return array
	 */
	public function fetch_meta( int $post_id, array $settings ): array {
		$db     = $this->connect( $settings );
		$tables = $this->get_tables( $settings );
		$sql    = $db->prepare( "SELECT meta_key, meta_value FROM {$tables['postmeta']} WHERE post_id = %d", $post_id );
		return $db->get_results( $sql, ARRAY_A ) ?: array();
	}

	/**
	 * Fetch post + meta by ID.
	 *
	 * @param int   $post_id
	 * @param array $settings
	 * @return array { post: array|null, meta: array }
	 */
	public function get_post_with_meta( int $post_id, array $settings ): array {
		$db     = $this->connect( $settings );
		$tables = $this->get_tables( $settings );
		$sql    = $db->prepare( "SELECT * FROM {$tables['posts']} WHERE ID = %d", $post_id );
		$post   = $db->get_row( $sql, ARRAY_A );
		$meta   = $this->fetch_meta( $post_id, $settings );
		return array(
			'post' => $post,
			'meta' => $meta,
		);
	}

	/**
	 * Find source attachment id by _wp_attached_file path.
	 *
	 * @param string $attached_file
	 * @param array  $settings
	 * @return int|null
	 */
	public function find_attachment_id_by_file( string $attached_file, array $settings ): ?int {
		$db     = $this->connect( $settings );
		$tables = $this->get_tables( $settings );
		$sql    = $db->prepare(
			"SELECT post_id FROM {$tables['postmeta']} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
			$attached_file
		);
		$id = $db->get_var( $sql );
		if ( $id ) {
			return (int) $id;
		}
		return null;
	}

	/**
	 * Fetch terms for a post across taxonomies.
	 *
	 * @param int   $post_id
	 * @param array $taxonomies
	 * @param array $settings
	 * @return array
	 */
	public function fetch_terms_for_post( int $post_id, array $taxonomies, array $settings ): array {
		if ( empty( $taxonomies ) ) {
			return array();
		}
		$db        = $this->connect( $settings );
		$tables    = $this->get_tables( $settings );
		$in        = implode( "','", array_map( 'esc_sql', $taxonomies ) );
		$sql       = $db->prepare(
			"SELECT t.term_id, t.name, t.slug, tt.taxonomy
			FROM {$tables['term_relationships']} tr
			INNER JOIN {$tables['term_taxonomy']} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$tables['terms']} t ON t.term_id = tt.term_id
			WHERE tr.object_id = %d AND tt.taxonomy IN ('$in')",
			$post_id
		);
		return $db->get_results( $sql, ARRAY_A ) ?: array();
	}

	/**
	 * Fetch user + meta by user ID.
	 *
	 * @param int   $user_id
	 * @param array $settings
	 * @return array { user: array|null, meta: array }
	 */
	public function get_user_with_meta( int $user_id, array $settings ): array {
		$db     = $this->connect( $settings );
		$prefix = $settings['source_table_prefix'] ?? 'wp_';
		$users  = $prefix . 'users';
		$umeta  = $prefix . 'usermeta';

		$sql  = $db->prepare( "SELECT * FROM {$users} WHERE ID = %d", $user_id );
		$user = $db->get_row( $sql, ARRAY_A );

		$sqlm = $db->prepare( "SELECT meta_key, meta_value FROM {$umeta} WHERE user_id = %d", $user_id );
		$meta = $db->get_results( $sqlm, ARRAY_A ) ?: array();

		return array(
			'user' => $user,
			'meta' => $meta,
		);
	}

	/**
	 * Fetch PowerPress-related options from the source site.
	 *
	 * @param array $settings
	 * @return array
	 */
	public function fetch_powerpress_options( array $settings ): array {
		$db     = $this->connect( $settings );
		$table  = $this->get_options_table( $settings );
		$sql    = $db->prepare(
			"SELECT option_name, option_value FROM {$table} WHERE option_name LIKE %s",
			'powerpress_%'
		);
		return $db->get_results( $sql, ARRAY_A ) ?: array();
	}
}
