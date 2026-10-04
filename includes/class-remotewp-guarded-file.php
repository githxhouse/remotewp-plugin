<?php
/**
 * Backup-first, verify-after, roll-back-on-failure file writer.
 *
 * Used only for the two named file operations (wp-config constants and the
 * managed .htaccess block). It reuses RemoteWP_Operation_Safety, i.e. the same
 * backup mechanism and manifest as /write, and returns the same backup_id.
 *
 * @package RemoteWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Guarded_File {

	/**
	 * Replace the contents of a file with automatic rollback.
	 *
	 * @param string   $path         Absolute target path (may not exist yet).
	 * @param string   $original     Contents the edit was computed from ('' when the file is new).
	 * @param string   $updated      New contents.
	 * @param string   $operation    Operation name for the backup manifest.
	 * @param string   $backup_dir   Backup directory.
	 * @param callable $verify       function ( string $written ): true|WP_Error, run on the re-read file.
	 * @return array|WP_Error array( 'backup_id' => string|null, 'bytes' => int )
	 */
	public static function write( $path, $original, $updated, $operation, $backup_dir, callable $verify ) {
		$existed = file_exists( $path );

		// 1. Concurrency guard: the file must still be what we edited.
		if ( $existed ) {
			$current = RemoteWP_Operation_Safety::sha256( $path );
			if ( false === $current || ! hash_equals( hash( 'sha256', $original ), $current ) ) {
				return new WP_Error( 'file_changed', __( 'The file changed while the edit was prepared. Retry the request.', 'remotewp' ), array( 'status' => 409 ) );
			}
		}

		// 2. Mandatory backup through the existing mechanism. No backup, no write.
		$record = null;
		if ( $existed ) {
			$record = RemoteWP_Operation_Safety::create_backup_record( $path, $backup_dir, $operation, wp_generate_uuid4() );
			if ( is_wp_error( $record ) || ! is_array( $record ) || empty( $record['backup_id'] ) ) {
				return new WP_Error( 'backup_failed', __( 'The mandatory backup could not be created. Nothing was written.', 'remotewp' ), array( 'status' => 500 ) );
			}
		}
		$backup_id = $record ? $record['backup_id'] : null;

		// 3. Write.
		$written = self::put( $path, $updated );
		if ( is_wp_error( $written ) ) {
			$restored = self::rollback( $path, $original, $existed, $record, $backup_dir );
			return self::failure( 'write_failed', __( 'The write failed.', 'remotewp' ), $backup_id, $restored );
		}

		// 4. Verify the re-read file; on any failure restore automatically.
		$on_disk = file_get_contents( $path );
		$check   = false === $on_disk
			? new WP_Error( 'verify_read_failed', __( 'The written file could not be re-read.', 'remotewp' ) )
			: $verify( $on_disk );
		if ( true !== $check || ! hash_equals( hash( 'sha256', $updated ), hash( 'sha256', (string) $on_disk ) ) ) {
			$restored = self::rollback( $path, $original, $existed, $record, $backup_dir );
			return self::failure( 'verification_failed', __( 'The result failed verification and the previous version was restored.', 'remotewp' ), $backup_id, $restored );
		}

		return array( 'backup_id' => $backup_id, 'bytes' => (int) $written );
	}

	/**
	 * Restore the pre-write state. Returns true when the original is back in place.
	 */
	public static function rollback( $path, $original, $existed, $record, $backup_dir ) {
		if ( ! $existed ) {
			@unlink( $path );
			return ! file_exists( $path );
		}

		if ( is_array( $record ) && ! empty( $record['backup_file'] ) ) {
			$restored = RemoteWP_Operation_Safety::restore_from_backup( $backup_dir, $record['backup_file'], $path );
			if ( true === $restored ) {
				return true;
			}
		}

		// Last resort: put the in-memory original back.
		$fallback = self::put( $path, $original );
		return ! is_wp_error( $fallback ) && hash_equals( hash( 'sha256', $original ), (string) RemoteWP_Operation_Safety::sha256( $path ) );
	}

	/**
	 * Atomic replace where the directory allows it, in-place otherwise.
	 *
	 * @return int|WP_Error Bytes written.
	 */
	private static function put( $path, $content ) {
		if ( is_writable( dirname( $path ) ) ) {
			return RemoteWP_Operation_Safety::atomic_write( $path, $content );
		}

		$bytes = file_put_contents( $path, $content, LOCK_EX );
		if ( false === $bytes ) {
			return new WP_Error( 'write_error', __( 'Could not write the file.', 'remotewp' ), array( 'status' => 500 ) );
		}
		return $bytes;
	}

	private static function failure( $code, $message, $backup_id, $restored ) {
		return new WP_Error(
			$code,
			$message,
			array(
				'status'    => 500,
				'backup_id' => $backup_id,
				'restored'  => (bool) $restored,
			)
		);
	}
}
