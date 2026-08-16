<?php
/**
 * RemoteWP operation safety helpers.
 *
 * These helpers are intentionally compatible with the v1 API. Callers may
 * provide an expected SHA-256 now; v2 will make it mandatory for mutations.
 *
 * @package RemoteWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Operation_Safety {

	/**
	 * Return the stable opaque identity of the current WordPress site.
	 *
	 * @return string
	 */
	private static function current_site_id() {
		$site_url = function_exists( 'home_url' ) ? home_url( '/' ) : (string) get_option( 'siteurl', '' );
		return hash( 'sha256', strtolower( untrailingslashit( (string) $site_url ) ) );
	}

	/**
	 * Return the SHA-256 digest of a regular file.
	 *
	 * @param string $path Absolute file path.
	 * @return string|false
	 */
	public static function sha256( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		$hash = hash_file( 'sha256', $path );
		return false === $hash ? false : strtolower( $hash );
	}

	/**
	 * Validate an optional optimistic-concurrency precondition.
	 *
	 * @param string $path Absolute file path.
	 * @param string $expected Expected SHA-256 digest.
	 * @param bool   $required Whether the digest must be supplied.
	 * @return true|WP_Error
	 */
	public static function check_expected_sha256( $path, $expected = '', $required = false ) {
		$expected = strtolower( trim( (string) $expected ) );

		if ( empty( $expected ) ) {
			if ( $required ) {
				return new WP_Error(
					'expected_sha256_required',
					__( 'expected_sha256 is required for this mutation.', 'remotewp' ),
					array( 'status' => 428 )
				);
			}

			return true;
		}

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) ) {
			return new WP_Error(
				'invalid_sha256',
				__( 'expected_sha256 must be a valid SHA-256 digest.', 'remotewp' ),
				array( 'status' => 400 )
			);
		}

		$current = self::sha256( $path );
		if ( false === $current ) {
			return new WP_Error(
				'file_changed',
				__( 'The target file is no longer available.', 'remotewp' ),
				array( 'status' => 409 )
			);
		}

		if ( ! hash_equals( $expected, $current ) ) {
			return new WP_Error(
				'file_changed',
				__( 'The target file changed after it was read.', 'remotewp' ),
				array(
					'status'         => 409,
					'current_sha256' => $current,
				)
			);
		}

		return true;
	}

	/**
	 * Atomically replace a file in the same directory.
	 *
	 * @param string $path Absolute target path.
	 * @param string $content New contents.
	 * @return int|WP_Error Number of bytes written or error.
	 */
	public static function atomic_write( $path, $content ) {
		$directory = dirname( $path );
		$temp_path = tempnam( $directory, '.remotewp-' );
		$expected  = hash( 'sha256', (string) $content );

		if ( false === $temp_path ) {
			return new WP_Error( 'write_error', __( 'Could not create a temporary file.', 'remotewp' ), array( 'status' => 500 ) );
		}

		$bytes = file_put_contents( $temp_path, $content, LOCK_EX );
		if ( false === $bytes ) {
			@unlink( $temp_path );
			return new WP_Error( 'write_error', __( 'Could not write the temporary file.', 'remotewp' ), array( 'status' => 500 ) );
		}
		$temp_hash = self::sha256( $temp_path );
		if ( false === $temp_hash || ! hash_equals( $expected, $temp_hash ) ) {
			@unlink( $temp_path );
			return new WP_Error( 'verification_failed', __( 'Temporary file failed its integrity check.', 'remotewp' ), array( 'status' => 500 ) );
		}

		if ( file_exists( $path ) ) {
			@chmod( $temp_path, fileperms( $path ) & 0777 );
		}

		if ( ! @rename( $temp_path, $path ) ) {
			@unlink( $temp_path );
			return new WP_Error( 'write_error', __( 'Could not commit the atomic file replacement.', 'remotewp' ), array( 'status' => 500 ) );
		}
		$final_hash = self::sha256( $path );
		if ( false === $final_hash || ! hash_equals( $expected, $final_hash ) ) {
			return new WP_Error( 'verification_failed', __( 'The committed file failed its integrity check.', 'remotewp' ), array( 'status' => 500 ) );
		}

		return $bytes;
	}

	/**
	 * Verify an existing file against an expected SHA-256 digest.
	 *
	 * @param string $path     Absolute file path.
	 * @param string $expected Expected SHA-256 digest.
	 * @return true|WP_Error
	 */
	public static function verify_sha256( $path, $expected ) {
		$expected = strtolower( trim( (string) $expected ) );
		$current  = self::sha256( $path );
		if ( false === $current || empty( $expected ) || ! hash_equals( $expected, $current ) ) {
			return new WP_Error(
				'verification_failed',
				__( 'The file failed its post-mutation integrity check.', 'remotewp' ),
				array( 'status' => 500, 'expected_sha256' => $expected, 'current_sha256' => $current )
			);
		}
		return true;
	}

	/**
	 * Verify whether a path exists after a filesystem mutation.
	 *
	 * @param string $path            Absolute path.
	 * @param bool   $expected_exists Whether the path should exist.
	 * @return true|WP_Error
	 */
	public static function verify_path_state( $path, $expected_exists = true ) {
		$actual_exists = file_exists( $path );
		if ( (bool) $expected_exists === $actual_exists ) {
			return true;
		}

		return new WP_Error(
			'path_verification_failed',
			__( 'The filesystem path failed its post-mutation state check.', 'remotewp' ),
			array(
				'status'         => 500,
				'expected_exists' => (bool) $expected_exists,
				'actual_exists'   => $actual_exists,
			)
		);
	}

	/**
	 * Create a uniquely named backup and an adjacent integrity manifest.
	 *
	 * @param string $path         Absolute target path.
	 * @param string $backup_dir   Absolute backup directory.
	 * @param string $operation    Operation name.
	 * @param string $operation_id Correlation identifier.
	 * @return array|WP_Error|false
	 */
	public static function create_backup_record( $path, $backup_dir, $operation = '', $operation_id = '' ) {
		if ( ! file_exists( $path ) ) {
			return false;
		}

		if ( ! is_dir( $backup_dir ) && ! wp_mkdir_p( $backup_dir ) ) {
			return new WP_Error( 'backup_error', __( 'Could not create the backup directory.', 'remotewp' ), array( 'status' => 500 ) );
		}

		$stamp     = gmdate( 'Ymd_His' );
		$unique    = substr( hash( 'sha256', $path . '|' . microtime( true ) . '|' . wp_rand() ), 0, 12 );
		$backup_id = 'rwb_' . substr( hash( 'sha256', $path . '|' . microtime( true ) . '|' . wp_rand() ), 0, 24 );
		$basename  = sanitize_file_name( basename( $path ) );
		$record    = array(
			'backup_id'       => $backup_id,
			'site_id'         => self::current_site_id(),
			'operation'       => sanitize_key( $operation ),
			'operation_id'    => sanitize_text_field( $operation_id ),
			'created_at'      => current_time( 'c' ),
			'target_path'     => self::relative_path( $path ),
			'type'             => is_dir( $path ) ? 'directory' : 'file',
			'original_sha256' => self::sha256( $path ),
			'bytes'           => is_file( $path ) ? (int) filesize( $path ) : 0,
		);

		if ( is_file( $path ) ) {
			$record['backup_file'] = $basename . '_' . $stamp . '_' . $unique . '.bak';
			$backup_path           = trailingslashit( $backup_dir ) . $record['backup_file'];
			if ( ! copy( $path, $backup_path ) ) {
				return new WP_Error( 'backup_error', __( 'Could not create a backup copy.', 'remotewp' ), array( 'status' => 500 ) );
			}
			@chmod( $backup_path, 0444 );
			$record['backup_sha256'] = self::sha256( $backup_path );
		} else {
			$record['backup_file'] = null;
		}

		$record['manifest_file'] = $basename . '_' . $stamp . '_' . $unique . '.json';
		$record['integrity_sha256'] = self::record_integrity( $record );
		$manifest_path            = trailingslashit( $backup_dir ) . $record['manifest_file'];
		$manifest_content         = self::encode_json( $record );
		$manifest_result = self::atomic_write( $manifest_path, $manifest_content . PHP_EOL );
		if ( is_wp_error( $manifest_result ) ) {
			if ( ! empty( $record['backup_file'] ) ) {
				@unlink( trailingslashit( $backup_dir ) . $record['backup_file'] );
			}
			return new WP_Error( 'backup_error', __( 'Could not write the backup manifest.', 'remotewp' ), array( 'status' => 500 ) );
		}
		@chmod( $manifest_path, 0444 );

		return $record;
	}

	/**
	 * Inspect backup manifests without returning internal file paths.
	 *
	 * @param string $backup_dir     Absolute backup directory.
	 * @param int    $retention_days Mark records older than this many days as eligible; 0 disables age eligibility.
	 * @param int    $max_records    Keep this many newest records; 0 disables count eligibility.
	 * @return array
	 */
	public static function inspect_backup_directory( $backup_dir, $retention_days = 0, $max_records = 0 ) {
		$root = realpath( $backup_dir );
		$inventory = array(
			'manifest_count' => 0,
			'valid_count'    => 0,
			'invalid_count'  => 0,
			'eligible_count' => 0,
			'oldest_at'     => null,
			'newest_at'     => null,
		);
		if ( ! $root ) {
			return $inventory;
		}

		$manifest_files = glob( trailingslashit( $root ) . '*.json' );
		$valid_records  = array();
		foreach ( is_array( $manifest_files ) ? $manifest_files : array() as $manifest_path ) {
			$inventory['manifest_count']++;
			$content = @file_get_contents( $manifest_path );
			$record  = false === $content ? null : json_decode( $content, true );
			if ( ! is_array( $record ) || is_wp_error( self::verify_backup_record( $root, $record ) ) ) {
				$inventory['invalid_count']++;
				continue;
			}
			$inventory['valid_count']++;
			$created_at = ! empty( $record['created_at'] ) ? strtotime( $record['created_at'] ) : 0;
			if ( $created_at ) {
				$inventory['oldest_at'] = null === $inventory['oldest_at'] ? $created_at : min( $inventory['oldest_at'], $created_at );
				$inventory['newest_at'] = null === $inventory['newest_at'] ? $created_at : max( $inventory['newest_at'], $created_at );
			}
			$valid_records[] = array( 'created_at' => (int) $created_at );
		}

		usort(
			$valid_records,
			function ( $left, $right ) {
				return $right['created_at'] <=> $left['created_at'];
			}
		);
		$cutoff = $retention_days > 0 ? time() - ( $retention_days * 86400 ) : 0;
		foreach ( $valid_records as $index => $record ) {
			$too_old    = $cutoff > 0 && $record['created_at'] > 0 && $record['created_at'] < $cutoff;
			$over_limit = $max_records > 0 && $index >= $max_records;
			if ( $too_old || $over_limit ) {
				$inventory['eligible_count']++;
			}
		}
		return $inventory;
	}

	/**
	 * Return a bounded, read-only review list for eligible backups.
	 *
	 * The review deliberately omits absolute paths and backup contents. It is
	 * suitable for authenticated health/reporting responses and never deletes
	 * or changes backup files.
	 *
	 * @param string $backup_dir     Absolute backup directory.
	 * @param int    $retention_days Mark records older than this many days as eligible.
	 * @param int    $max_records    Keep this many newest records; older ones are eligible.
	 * @param int    $limit          Maximum records returned.
	 * @return array
	 */
	public static function get_backup_review( $backup_dir, $retention_days = 0, $max_records = 0, $limit = 50 ) {
		$root = realpath( $backup_dir );
		$result = array(
			'records'   => array(),
			'truncated' => false,
			'valid_count' => 0,
			'invalid_count' => 0,
		);
		if ( ! $root ) {
			return $result;
		}

		$limit = max( 1, min( 100, (int) $limit ) );
		$records = array();
		$manifest_files = glob( trailingslashit( $root ) . '*.json' );
		foreach ( is_array( $manifest_files ) ? $manifest_files : array() as $manifest_path ) {
			$content = @file_get_contents( $manifest_path );
			$record  = false === $content ? null : json_decode( $content, true );
			if ( ! is_array( $record ) || is_wp_error( self::verify_backup_record( $root, $record ) ) ) {
				$result['invalid_count']++;
				continue;
			}
			$result['valid_count']++;
			$created_at = ! empty( $record['created_at'] ) ? strtotime( $record['created_at'] ) : 0;
			$records[] = array(
				'backup_id' => isset( $record['backup_id'] ) ? (string) $record['backup_id'] : null,
				'operation' => isset( $record['operation'] ) ? sanitize_key( $record['operation'] ) : '',
				'created_at' => isset( $record['created_at'] ) ? (string) $record['created_at'] : null,
				'type' => isset( $record['type'] ) ? sanitize_key( $record['type'] ) : 'file',
				'bytes' => isset( $record['bytes'] ) ? (int) $record['bytes'] : 0,
				'_created_timestamp' => (int) $created_at,
			);
		}

		usort(
			$records,
			function ( $left, $right ) {
				return $right['_created_timestamp'] <=> $left['_created_timestamp'];
			}
		);
		$cutoff = $retention_days > 0 ? time() - ( (int) $retention_days * 86400 ) : 0;
		foreach ( $records as $index => &$record ) {
			$reasons = array();
			if ( $cutoff > 0 && $record['_created_timestamp'] > 0 && $record['_created_timestamp'] < $cutoff ) {
				$reasons[] = 'retention_age';
			}
			if ( $max_records > 0 && $index >= (int) $max_records ) {
				$reasons[] = 'max_records';
			}
			$record['eligible'] = ! empty( $reasons );
			$record['eligibility_reasons'] = $reasons;
			unset( $record['_created_timestamp'] );
		}
		unset( $record );

		$eligible = array_values(
			array_filter(
				$records,
				function ( $record ) {
					return ! empty( $record['eligible'] );
				}
			)
		);
		$result['truncated'] = count( $eligible ) > $limit;
		$result['records'] = array_slice( $eligible, 0, $limit );
		return $result;
	}

	/**
	 * Remove one eligible, manifest-backed backup after an explicit approval.
	 *
	 * This method is intentionally not called by health checks, cron jobs or
	 * filesystem API routes. Callers must provide a backup_id returned by the
	 * bounded review list, and eligibility is re-evaluated immediately.
	 *
	 * @param string $backup_dir     Absolute backup directory.
	 * @param string $backup_id      Opaque manifest-backed backup ID.
	 * @param int    $retention_days Current age threshold.
	 * @param int    $max_records    Current count threshold.
	 * @return array|WP_Error
	 */
	public static function purge_eligible_backup( $backup_dir, $backup_id, $retention_days = 0, $max_records = 0 ) {
		$backup_id = is_string( $backup_id ) ? trim( $backup_id ) : '';
		if ( empty( $backup_id ) || ! preg_match( '/^rwb_[a-f0-9]{8,64}$/', $backup_id ) ) {
			return new WP_Error( 'invalid_backup_id', __( 'Only a valid opaque backup ID can be cleaned up.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$review = self::get_backup_review( $backup_dir, $retention_days, $max_records, 100 );
		$eligible = false;
		foreach ( $review['records'] as $record ) {
			if ( isset( $record['backup_id'] ) && hash_equals( (string) $record['backup_id'], $backup_id ) ) {
				$eligible = ! empty( $record['eligible'] );
				break;
			}
		}
		if ( ! $eligible ) {
			return new WP_Error( 'backup_not_eligible', __( 'The backup is not currently eligible for manual cleanup.', 'remotewp' ), array( 'status' => 409 ) );
		}

		$root = realpath( $backup_dir );
		$record = self::find_backup_record( $backup_dir, $backup_id );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		if ( ! $root || empty( $record['_manifest_path'] ) ) {
			return new WP_Error( 'cleanup_requires_manifest', __( 'Only manifest-backed backups can be cleaned up.', 'remotewp' ), array( 'status' => 409 ) );
		}
		if ( ! is_writable( $root ) ) {
			return new WP_Error( 'cleanup_not_writable', __( 'The backup directory is not writable for manual cleanup.', 'remotewp' ), array( 'status' => 500 ) );
		}

		$manifest_path = realpath( $record['_manifest_path'] );
		$root_prefix   = trailingslashit( rtrim( $root, DIRECTORY_SEPARATOR ) );
		if ( ! $manifest_path || 0 !== strpos( $manifest_path, $root_prefix ) || ! is_file( $manifest_path ) ) {
			return new WP_Error( 'invalid_backup_manifest', __( 'The backup manifest is outside the protected backup directory.', 'remotewp' ), array( 'status' => 409 ) );
		}

		$backup_path = null;
		if ( ! empty( $record['backup_file'] ) ) {
			$backup_path = realpath( trailingslashit( $root ) . basename( $record['backup_file'] ) );
			if ( ! $backup_path || 0 !== strpos( $backup_path, $root_prefix ) || ! is_file( $backup_path ) ) {
				return new WP_Error( 'invalid_backup_file', __( 'The backup file is outside the protected backup directory.', 'remotewp' ), array( 'status' => 409 ) );
			}
		}

		// The manifest was integrity-checked by find_backup_record above. Remove
		// the manifest last so a failed backup-file removal leaves an auditable
		// manifest rather than silently losing the record.
		if ( $backup_path && ! @unlink( $backup_path ) ) {
			return new WP_Error( 'cleanup_failed', __( 'Could not remove the verified backup file.', 'remotewp' ), array( 'status' => 500 ) );
		}
		if ( ! @unlink( $manifest_path ) ) {
			return new WP_Error( 'cleanup_partial', __( 'The backup file was removed, but its manifest could not be removed.', 'remotewp' ), array( 'status' => 500 ) );
		}

		return array(
			'backup_id' => $backup_id,
			'backup_removed' => (bool) $backup_path,
			'manifest_removed' => true,
		);
	}

	/**
	 * Resolve a backup filename or backup_id to its verified manifest record.
	 *
	 * Legacy backups without a manifest remain resolvable by filename, but new
	 * backups always use the manifest integrity and backup hash checks below.
	 *
	 * @param string $backup_dir Absolute backup directory.
	 * @param string $reference  backup_id or legacy backup filename.
	 * @return array|WP_Error
	 */
	public static function find_backup_record( $backup_dir, $reference ) {
		$backup_root = realpath( $backup_dir );
		$reference  = is_string( $reference ) ? trim( $reference ) : '';
		if ( ! $backup_root || empty( $reference ) || basename( $reference ) !== $reference ) {
			return new WP_Error( 'invalid_backup', __( 'Invalid backup reference.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$manifest_files = glob( trailingslashit( $backup_root ) . '*.json' );
		if ( is_array( $manifest_files ) ) {
			foreach ( $manifest_files as $manifest_path ) {
				$manifest_content = @file_get_contents( $manifest_path );
				$record           = false === $manifest_content ? null : json_decode( $manifest_content, true );
				if ( ! is_array( $record ) ) {
					continue;
				}
				if ( ( isset( $record['backup_id'] ) && hash_equals( (string) $record['backup_id'], $reference ) ) || ( isset( $record['backup_file'] ) && (string) $record['backup_file'] === $reference ) ) {
					$verified = self::verify_backup_record( $backup_root, $record );
					if ( is_wp_error( $verified ) ) {
						return $verified;
					}
					$record['_manifest_path'] = $manifest_path;
					return $record;
				}
			}
		}

		$backup_path = realpath( trailingslashit( $backup_root ) . $reference );
		if ( ! $backup_path || 0 !== strpos( $backup_path, rtrim( $backup_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) || ! is_file( $backup_path ) ) {
			return new WP_Error( 'not_found', __( 'Backup file not found.', 'remotewp' ), array( 'status' => 404 ) );
		}

		// Pre-manifest backups are accepted for backward compatibility, but have
		// no integrity metadata beyond the protected backup directory boundary.
		return array(
			'backup_file'  => basename( $backup_path ),
			'_backup_path' => $backup_path,
		);
	}

	/**
	 * Restore a file from a backup directory using an atomic replacement.
	 *
	 * @param string $backup_dir  Absolute backup directory.
	 * @param string $backup_file Backup filename.
	 * @param string $target_path Absolute target path.
	 * @return true|WP_Error
	 */
	public static function restore_from_backup( $backup_dir, $backup_file, $target_path ) {
		$backup_root = realpath( $backup_dir );
		$record      = self::find_backup_record( $backup_dir, $backup_file );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		$backup_path = isset( $record['_backup_path'] ) ? $record['_backup_path'] : realpath( trailingslashit( $backup_root ) . $record['backup_file'] );
		if ( ! $backup_path || ! is_file( $backup_path ) ) {
			return new WP_Error( 'not_found', __( 'Backup file not found.', 'remotewp' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $record['target_path'] ) && self::relative_path( $target_path ) !== $record['target_path'] ) {
			return new WP_Error( 'backup_target_mismatch', __( 'The backup belongs to a different target path.', 'remotewp' ), array( 'status' => 409 ) );
		}

		$content = file_get_contents( $backup_path );
		if ( false === $content ) {
			return new WP_Error( 'restore_error', __( 'Could not read the backup file.', 'remotewp' ), array( 'status' => 500 ) );
		}

		$result = self::atomic_write( $target_path, $content );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $record['original_sha256'] ) ) {
			$restored_sha256 = self::sha256( $target_path );
			if ( false === $restored_sha256 || ! hash_equals( strtolower( (string) $record['original_sha256'] ), $restored_sha256 ) ) {
				return new WP_Error( 'restore_integrity_failed', __( 'The restored file failed its integrity check.', 'remotewp' ), array( 'status' => 500 ) );
			}
		}
		return true;
	}

	private static function verify_backup_record( $backup_root, $record ) {
		if ( ! empty( $record['site_id'] ) && ! hash_equals( (string) $record['site_id'], self::current_site_id() ) ) {
			return new WP_Error( 'backup_site_mismatch', __( 'The backup belongs to a different WordPress site.', 'remotewp' ), array( 'status' => 409 ) );
		}
		if ( ! empty( $record['integrity_sha256'] ) ) {
			$integrity = $record['integrity_sha256'];
			unset( $record['integrity_sha256'] );
			$expected = self::record_integrity( $record );
			if ( ! hash_equals( (string) $integrity, $expected ) ) {
				return new WP_Error( 'backup_integrity_failed', __( 'The backup manifest integrity check failed.', 'remotewp' ), array( 'status' => 409 ) );
			}
		}
		if ( ! empty( $record['backup_file'] ) ) {
			$backup_path = realpath( trailingslashit( $backup_root ) . basename( $record['backup_file'] ) );
			if ( ! $backup_path || ! is_file( $backup_path ) ) {
				return new WP_Error( 'not_found', __( 'Backup file not found.', 'remotewp' ), array( 'status' => 404 ) );
			}
			if ( ! empty( $record['backup_sha256'] ) && ! hash_equals( strtolower( (string) $record['backup_sha256'] ), (string) self::sha256( $backup_path ) ) ) {
				return new WP_Error( 'backup_integrity_failed', __( 'The backup file integrity check failed.', 'remotewp' ), array( 'status' => 409 ) );
			}
		}
		return true;
	}

	private static function record_integrity( $record ) {
		unset( $record['integrity_sha256'], $record['_manifest_path'], $record['_backup_path'] );
		return hash( 'sha256', self::encode_json( $record ) );
	}

	private static function encode_json( $data ) {
		return function_exists( 'wp_json_encode' )
			? wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
			: json_encode( $data );
	}

	private static function relative_path( $path ) {
		$base = realpath( ABSPATH );
		if ( $base && 0 === strpos( $path, rtrim( $base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
			return ltrim( str_replace( '\\', '/', substr( $path, strlen( $base ) ) ), '/' );
		}
		return basename( $path );
	}
}
