<?php
/**
 * Named database maintenance operations.
 *
 * No SQL ever comes from the agent. Operations are a closed list; their
 * parameters are validated integers. Where WordPress offers a function it is
 * used (wp_delete_post_revision, wp_delete_comment, delete_expired_transients).
 * Orphaned post meta and table optimisation have no WordPress API, so they use
 * fixed statements with no user input.
 *
 * Responses never contain table names with the site prefix, raw SQL or
 * database error text.
 *
 * @package RemoteWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Maintenance {

	const OPERATIONS = array(
		'delete_revisions',
		'delete_expired_transients',
		'delete_orphaned_postmeta',
		'delete_spam_comments',
		'optimize_tables',
	);

	/** Seconds an executing request may spend deleting before it stops and reports what remains. */
	const TIME_BUDGET = 20;

	/**
	 * Validate and normalise operation parameters.
	 *
	 * @param string $operation Operation name.
	 * @param mixed  $params    Raw params.
	 * @return array|WP_Error Normalised params.
	 */
	public static function validate( $operation, $params ) {
		if ( ! is_string( $operation ) || ! in_array( $operation, self::OPERATIONS, true ) ) {
			return new WP_Error( 'unknown_operation', sprintf( /* translators: %s: list */ __( 'operation must be one of: %s.', 'remotewp' ), implode( ', ', self::OPERATIONS ) ), array( 'status' => 400 ) );
		}
		if ( null === $params ) {
			$params = array();
		}
		if ( ! is_array( $params ) ) {
			return new WP_Error( 'invalid_params', __( 'params must be an object.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$spec = 'delete_revisions' === $operation
			? array( 'older_than_days' => array( 0, 3650 ), 'keep_last' => array( 0, 50 ) )
			: array();

		$unknown = array_diff( array_keys( $params ), array_keys( $spec ) );
		if ( ! empty( $unknown ) ) {
			return new WP_Error( 'invalid_params', sprintf( /* translators: %s: operation */ __( 'Unknown parameters for %s.', 'remotewp' ), $operation ), array( 'status' => 400 ) );
		}

		$clean = array();
		foreach ( $spec as $key => $range ) {
			if ( ! array_key_exists( $key, $params ) ) {
				return new WP_Error( 'invalid_params', sprintf( /* translators: %s: parameter */ __( 'Parameter %s is required.', 'remotewp' ), $key ), array( 'status' => 400 ) );
			}
			$raw = $params[ $key ];
			$int = is_int( $raw ) ? $raw : ( is_string( $raw ) && 1 === preg_match( '/^\d{1,4}$/', $raw ) ? (int) $raw : null );
			if ( null === $int || $int < $range[0] || $int > $range[1] ) {
				return new WP_Error( 'invalid_params', sprintf( /* translators: 1: parameter 2: min 3: max */ __( '%1$s must be an integer between %2$d and %3$d.', 'remotewp' ), $key, $range[0], $range[1] ), array( 'status' => 400 ) );
			}
			$clean[ $key ] = $int;
		}
		return $clean;
	}

	/**
	 * Count what an operation would affect. Modifies nothing.
	 *
	 * @param string $operation Operation.
	 * @param array  $params    Normalised params.
	 * @return array Result: count + details.
	 */
	public static function count( $operation, array $params ) {
		switch ( $operation ) {
			case 'delete_revisions':
				$ids = self::eligible_revisions( $params['older_than_days'], $params['keep_last'] );
				return array( 'count' => count( $ids ), 'unit' => 'revisions' );

			case 'delete_expired_transients':
				return array( 'count' => self::count_expired_transients(), 'unit' => 'expired transient entries' );

			case 'delete_orphaned_postmeta':
				return array( 'count' => self::count_orphaned_postmeta(), 'unit' => 'orphaned postmeta rows' );

			case 'delete_spam_comments':
				return array( 'count' => (int) get_comments( array( 'status' => 'spam', 'count' => true ) ), 'unit' => 'spam comments' );

			case 'optimize_tables':
				$status = self::table_status();
				return array(
					'count'             => count( $status['tables'] ),
					'unit'              => 'tables',
					'reclaimable_bytes' => $status['data_free'],
					'tables'            => $status['tables'],
				);
		}
		return array( 'count' => 0, 'unit' => '' );
	}

	/**
	 * Execute an operation.
	 *
	 * @param string $operation Operation.
	 * @param array  $params    Normalised params.
	 * @return array|WP_Error
	 */
	public static function run( $operation, array $params ) {
		@set_time_limit( 60 );
		$deadline = microtime( true ) + self::TIME_BUDGET;

		switch ( $operation ) {
			case 'delete_revisions':
				$ids     = self::eligible_revisions( $params['older_than_days'], $params['keep_last'] );
				$deleted = 0;
				foreach ( $ids as $id ) {
					if ( microtime( true ) > $deadline ) {
						break;
					}
					if ( wp_delete_post_revision( $id ) ) {
						++$deleted;
					}
				}
				return self::progress( $deleted, count( $ids ) - $deleted );

			case 'delete_spam_comments':
				$deleted = 0;
				$left    = 0;
				do {
					$ids = get_comments( array( 'status' => 'spam', 'fields' => 'ids', 'number' => 500 ) );
					foreach ( $ids as $id ) {
						if ( microtime( true ) > $deadline ) {
							break 2;
						}
						if ( wp_delete_comment( (int) $id, true ) ) {
							++$deleted;
						}
					}
				} while ( ! empty( $ids ) );
				$left = (int) get_comments( array( 'status' => 'spam', 'count' => true ) );
				return self::progress( $deleted, $left );

			case 'delete_expired_transients':
				$before = self::count_expired_transients();
				if ( function_exists( 'delete_expired_transients' ) ) {
					delete_expired_transients( true );
				} else {
					self::delete_expired_transients_fallback();
				}
				$left = self::count_expired_transients();
				return self::progress( max( 0, $before - $left ), $left );

			case 'delete_orphaned_postmeta':
				global $wpdb;
				$deleted = $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed statement, table names are WordPress-owned.
				if ( false === $deleted ) {
					return new WP_Error( 'maintenance_failed', __( 'The database operation failed.', 'remotewp' ), array( 'status' => 500 ) );
				}
				return self::progress( (int) $deleted, self::count_orphaned_postmeta() );

			case 'optimize_tables':
				global $wpdb;
				$status = self::table_status( true );
				$done   = 0;
				foreach ( $status['raw'] as $table ) {
					if ( microtime( true ) > $deadline ) {
						break;
					}
					if ( false !== $wpdb->query( 'OPTIMIZE TABLE `' . str_replace( '`', '', $table ) . '`' ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- names come from $wpdb->tables().
						++$done;
					}
				}
				return self::progress( $done, count( $status['raw'] ) - $done );
		}

		return new WP_Error( 'unknown_operation', __( 'Unknown operation.', 'remotewp' ), array( 'status' => 400 ) );
	}

	/**
	 * Text attached to every dry-run and execution response.
	 *
	 * @return string
	 */
	public static function warning() {
		return 'IRREVERSIBLE: this operation cannot be undone and RemoteWP does not back up the database. The site operator must hold a current database backup from the hosting provider before approving it.';
	}

	private static function progress( $done, $remaining ) {
		return array(
			'processed' => (int) $done,
			'remaining' => max( 0, (int) $remaining ),
			'completed' => $remaining <= 0,
		);
	}

	/**
	 * Revisions older than the cutoff, excluding the newest $keep per parent.
	 *
	 * @return int[] Revision IDs.
	 */
	private static function eligible_revisions( $older_than_days, $keep ) {
		$cutoff    = time() - ( $older_than_days * DAY_IN_SECONDS );
		$by_parent = array();
		$offset    = 0;
		$page      = 1000;

		do {
			$posts = get_posts(
				array(
					'post_type'              => 'revision',
					'post_status'            => 'inherit',
					'posts_per_page'         => $page,
					'offset'                 => $offset,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'suppress_filters'       => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $posts as $post ) {
				$by_parent[ (int) $post->post_parent ][] = array( (int) $post->ID, strtotime( $post->post_date_gmt . ' UTC' ) );
			}
			$offset += $page;
		} while ( count( $posts ) === $page );

		$eligible = array();
		foreach ( $by_parent as $revisions ) {
			usort(
				$revisions,
				static function ( $a, $b ) {
					return $b[1] === $a[1] ? $b[0] - $a[0] : $b[1] - $a[1];
				}
			);
			foreach ( array_slice( $revisions, $keep ) as $revision ) {
				if ( $revision[1] < $cutoff ) {
					$eligible[] = $revision[0];
				}
			}
		}
		return $eligible;
	}

	private static function count_expired_transients() {
		global $wpdb;
		$like_a = $wpdb->esc_like( '_transient_timeout_' ) . '%';
		$like_b = $wpdb->esc_like( '_site_transient_timeout_' ) . '%';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_value < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$like_a,
				$like_b,
				time()
			)
		);
	}

	private static function delete_expired_transients_fallback() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b WHERE a.option_name LIKE %s AND a.option_name NOT LIKE %s AND b.option_name = CONCAT( %s, SUBSTRING( a.option_name, CHAR_LENGTH( %s ) + 1 ) ) AND b.option_value < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				'_transient_timeout_',
				'_transient_',
				time()
			)
		);
	}

	private static function count_orphaned_postmeta() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Core tables with their reclaimable space. Names are returned without the
	 * database prefix; the raw names stay server-side.
	 *
	 * @param bool $with_raw Include the raw (prefixed) names for execution.
	 * @return array
	 */
	private static function table_status( $with_raw = false ) {
		global $wpdb;
		$core   = array_values( $wpdb->tables( 'all' ) );
		$rows   = (array) $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		$tables = array();
		$raw    = array();
		$free   = 0;

		foreach ( $rows as $row ) {
			if ( empty( $row['Name'] ) || ! in_array( $row['Name'], $core, true ) ) {
				continue;
			}
			$raw[]    = $row['Name'];
			$tables[] = self::logical_name( $row['Name'] );
			$free    += (int) ( isset( $row['Data_free'] ) ? $row['Data_free'] : 0 );
		}

		$out = array( 'tables' => $tables, 'data_free' => $free );
		if ( $with_raw ) {
			$out['raw'] = $raw;
		}
		return $out;
	}

	private static function logical_name( $table ) {
		global $wpdb;
		$prefix = (string) $wpdb->base_prefix;
		return ( '' !== $prefix && 0 === strpos( $table, $prefix ) ) ? substr( $table, strlen( $prefix ) ) : '[table]';
	}
}
