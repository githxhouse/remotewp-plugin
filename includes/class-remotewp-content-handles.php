<?php
/**
 * Short-lived, authenticated content handles for the additive v2 read flow.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Content_Handles {

	const TTL = 600;

	/**
	 * Store redacted content outside the HTTP response.
	 *
	 * @param string $storage_dir Protected RemoteWP storage directory.
	 * @param string $path        Relative source path.
	 * @param string $content     Content to store.
	 * @param bool   $redacted    Whether content was redacted.
	 * @return array|WP_Error
	 */
	public static function create( $storage_dir, $path, $content, $redacted = false ) {
		$handle_dir = trailingslashit( $storage_dir ) . 'handles';
		if ( ! is_dir( $handle_dir ) && ! wp_mkdir_p( $handle_dir ) ) {
			return new WP_Error( 'handle_error', __( 'Could not create the content handle store.', 'remotewp' ), array( 'status' => 500 ) );
		}

		$handle = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'remotewp_', true );
		$handle = preg_replace( '/[^A-Za-z0-9_-]/', '', $handle );
		$record = array(
			'handle'     => $handle,
			'path'       => sanitize_text_field( $path ),
			'created_at' => time(),
			'expires_at' => time() + self::TTL,
			'redacted'   => (bool) $redacted,
			'content'    => base64_encode( $content ),
		);
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $record ) : json_encode( $record );
		$result  = RemoteWP_Operation_Safety::atomic_write( trailingslashit( $handle_dir ) . hash( 'sha256', $handle ) . '.json', $encoded . PHP_EOL );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'handle'     => $handle,
			'expires_at' => $record['expires_at'],
			'redacted'   => $record['redacted'],
		);
	}

	/**
	 * Resolve a handle after authentication and expiry checks.
	 *
	 * @param string $storage_dir Protected RemoteWP storage directory.
	 * @param string $handle      Opaque handle.
	 * @return array|WP_Error
	 */
	public static function resolve( $storage_dir, $handle ) {
		if ( ! is_string( $handle ) || ! preg_match( '/^[A-Za-z0-9_-]{8,128}$/', $handle ) ) {
			return new WP_Error( 'invalid_handle', __( 'Invalid content handle.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$path = trailingslashit( $storage_dir ) . 'handles/' . hash( 'sha256', $handle ) . '.json';
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'handle_not_found', __( 'Content handle not found.', 'remotewp' ), array( 'status' => 404 ) );
		}
		$record = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $record ) || ! hash_equals( $handle, (string) ( $record['handle'] ?? '' ) ) ) {
			return new WP_Error( 'handle_not_found', __( 'Content handle not found.', 'remotewp' ), array( 'status' => 404 ) );
		}
		if ( time() > (int) ( $record['expires_at'] ?? 0 ) ) {
			@unlink( $path );
			return new WP_Error( 'handle_expired', __( 'Content handle has expired.', 'remotewp' ), array( 'status' => 410 ) );
		}

		$content = base64_decode( (string) ( $record['content'] ?? '' ), true );
		if ( false === $content ) {
			return new WP_Error( 'handle_corrupt', __( 'Content handle is invalid.', 'remotewp' ), array( 'status' => 500 ) );
		}

		return array(
			'path'     => $record['path'],
			'content'  => $content,
			'redacted' => ! empty( $record['redacted'] ),
			'expires_at' => (int) $record['expires_at'],
		);
	}
}
