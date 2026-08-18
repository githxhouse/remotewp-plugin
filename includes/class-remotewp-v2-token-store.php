<?php
/**
 * Hash-only token records for the additive RemoteWP v2 API.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_V2_Token_Store {
	const OPTION = 'remotewp_v2_token_records';

	/**
	 * Return the stable, non-secret identity of the current WordPress site.
	 *
	 * Keeping this calculation local avoids storing the raw domain in token
	 * records while still preventing a token record from being replayed on a
	 * different site after an option/database migration.
	 *
	 * @return string
	 */
	private static function current_site_id() {
		$site_url = function_exists( 'home_url' ) ? home_url( '/' ) : (string) get_option( 'siteurl', '' );
		return hash( 'sha256', strtolower( untrailingslashit( (string) $site_url ) ) );
	}

	public static function issue( $scopes, $label = '', $ttl = 2592000 ) {
		$allowed = array( 'files:read', 'files:list', 'files:search', 'files:patch', 'files:write', 'files:mkdir', 'files:delete', 'files:rename', 'files:restore' );
		$scopes  = array_values( array_intersect( array_unique( (array) $scopes ), $allowed ) );
		if ( empty( $scopes ) ) {
			return new WP_Error( 'invalid_scopes', __( 'At least one valid v2 scope is required.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$ttl = max( 300, min( 31536000, (int) $ttl ) );
		try {
			$token = 'rwp2_' . bin2hex( random_bytes( 32 ) );
		} catch ( Exception $exception ) {
			return new WP_Error( 'token_error', __( 'Could not generate a secure v2 token.', 'remotewp' ), array( 'status' => 500 ) );
		}
		$token_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'rwp2_', true );
		$records  = get_option( self::OPTION, array() );
		$records  = is_array( $records ) ? $records : array();
		$record   = array(
			'token_id'          => $token_id,
			'token_hash'        => hash( 'sha256', $token ),
			'token_prefix'      => substr( $token, 0, 12 ),
			'site_id'           => self::current_site_id(),
			'label'             => sanitize_text_field( $label ),
			'scopes'            => $scopes,
			'permission_profile'=> class_exists( 'RemoteWP_FS_API_Pro' ) ? 'full' : 'read-only',
			'created_by'        => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'created_at'        => time(),
			'last_used_at'      => 0,
			'expires_at'        => time() + $ttl,
			'revoked_at'        => 0,
		);
		$records[ $token_id ] = $record;
		update_option( self::OPTION, $records, false );

		return array(
			'token'      => $token,
			'token_id'   => $token_id,
			'scopes'     => $scopes,
			'created_at' => $record['created_at'],
			'expires_at' => $record['expires_at'],
			'warning'    => 'Store this token now. It will not be shown again.',
		);
	}

	public static function validate( $token, $required_scope = '' ) {
		if ( ! is_string( $token ) || 0 !== strpos( $token, 'rwp2_' ) ) {
			return new WP_Error( 'unauthorized', __( 'Invalid v2 token.', 'remotewp' ), array( 'status' => 401 ) );
		}
		$hash    = hash( 'sha256', $token );
		$records = get_option( self::OPTION, array() );
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			if ( ! is_array( $record ) || empty( $record['token_hash'] ) || ! hash_equals( $record['token_hash'], $hash ) ) {
				continue;
			}
			// Records created before site binding was added remain usable on the
			// site where they already exist. New validations backfill the binding;
			// a record copied to another site is rejected deterministically.
			$current_site_id = self::current_site_id();
			if ( ! empty( $record['site_id'] ) && ! hash_equals( (string) $record['site_id'], $current_site_id ) ) {
				return new WP_Error( 'token_site_mismatch', __( 'v2 token is not valid for this site.', 'remotewp' ), array( 'status' => 401 ) );
			}
			if ( ! empty( $record['revoked_at'] ) ) {
				return new WP_Error( 'token_revoked', __( 'v2 token has been revoked.', 'remotewp' ), array( 'status' => 401 ) );
			}
			if ( ! empty( $record['expires_at'] ) && time() >= (int) $record['expires_at'] ) {
				return new WP_Error( 'token_expired', __( 'v2 token has expired.', 'remotewp' ), array( 'status' => 401 ) );
			}
			if ( $required_scope && ! in_array( $required_scope, (array) $record['scopes'], true ) ) {
				return new WP_Error( 'scope_denied', __( 'v2 token does not include the required scope.', 'remotewp' ), array( 'status' => 403 ) );
			}
			$now                  = time();
			$record['site_id']    = $current_site_id;
			$record['token_prefix'] = ! empty( $record['token_prefix'] ) ? $record['token_prefix'] : substr( $token, 0, 12 );
			$record['permission_profile'] = ! empty( $record['permission_profile'] ) ? $record['permission_profile'] : ( class_exists( 'RemoteWP_FS_API_Pro' ) ? 'full' : 'read-only' );
			$record['created_by']         = isset( $record['created_by'] ) ? (int) $record['created_by'] : 0;
			$last_used_at                 = isset( $record['last_used_at'] ) ? (int) $record['last_used_at'] : 0;
			if ( $now - $last_used_at >= 60 || empty( $record['last_used_at'] ) ) {
				$record['last_used_at'] = $now;
				$records[ $record['token_id'] ] = $record;
				update_option( self::OPTION, $records, false );
			}
			return $record;
		}
		return new WP_Error( 'unauthorized', __( 'Invalid v2 token.', 'remotewp' ), array( 'status' => 401 ) );
	}

	public static function revoke( $token_id ) {
		$records = get_option( self::OPTION, array() );
		if ( ! is_array( $records ) || empty( $records[ $token_id ] ) ) {
			return new WP_Error( 'not_found', __( 'v2 token record not found.', 'remotewp' ), array( 'status' => 404 ) );
		}
		$records[ $token_id ]['revoked_at'] = time();
		update_option( self::OPTION, $records, false );
		return array( 'token_id' => $token_id, 'revoked_at' => $records[ $token_id ]['revoked_at'] );
	}

	public static function list_public() {
		$records = get_option( self::OPTION, array() );
		$result  = array();
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			$result[] = array(
				'token_id'           => $record['token_id'],
				'token_prefix'       => isset( $record['token_prefix'] ) ? $record['token_prefix'] : '',
				'site_id'            => isset( $record['site_id'] ) ? $record['site_id'] : '',
				'label'              => $record['label'],
				'scopes'             => $record['scopes'],
				'permission_profile' => isset( $record['permission_profile'] ) ? $record['permission_profile'] : '',
				'created_by'         => isset( $record['created_by'] ) ? (int) $record['created_by'] : 0,
				'created_at'         => (int) $record['created_at'],
				'last_used_at'       => isset( $record['last_used_at'] ) ? (int) $record['last_used_at'] : 0,
				'expires_at'         => (int) $record['expires_at'],
				'revoked_at'         => (int) $record['revoked_at'],
			);
		}
		return $result;
	}
}
