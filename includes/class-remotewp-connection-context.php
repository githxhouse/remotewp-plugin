<?php
/**
 * Stable site identity and capability context for RemoteWP v2 responses.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Connection_Context {

	public static function build() {
		$site_url = function_exists( 'home_url' ) ? home_url( '/' ) : (string) get_option( 'siteurl', '' );
		$host     = (string) wp_parse_url( $site_url, PHP_URL_HOST );
		$profile  = get_option( 'remotewp_permission_level', 'full' );
		$license  = class_exists( 'RemoteWP_License' ) ? new RemoteWP_License() : null;
		$tier     = $license ? $license->get_tier() : get_option( 'remotewp_license_tier', 'free' );
		$is_pro   = $license ? $license->is_pro() : 'free' !== strtolower( (string) $tier );
		$allowed  = self::profile_operations( $profile );

		$scopes = array();
		$pro_operations = array( 'write', 'delete', 'rename', 'restore', 'mkdir', 'search' );
		foreach ( $allowed as $operation ) {
			if ( ! $is_pro && in_array( $operation, $pro_operations, true ) ) {
				continue;
			}
			$scopes[] = 'files:' . $operation;
		}
		if ( $is_pro && in_array( 'write', $allowed, true ) ) {
			$scopes[] = 'files:patch';
		}

		return array(
			'site' => array(
				'site_id'      => hash( 'sha256', strtolower( untrailingslashit( $site_url ) ) ),
				'host'         => $host,
				'is_multisite' => function_exists( 'is_multisite' ) ? (bool) is_multisite() : false,
			),
			'connection' => array(
				'connection_id' => hash( 'sha256', strtolower( untrailingslashit( $site_url ) ) . '|' . (string) get_option( 'remotewp_token_created_at', 0 ) ),
				'token'         => self::token_state(),
			),
			'authorization' => array(
				'profile' => $profile,
				'tier'    => $tier,
				'scopes'  => array_values( array_unique( $scopes ) ),
			),
			'capabilities' => array(
				'read'            => in_array( 'read', $allowed, true ),
				'list'            => in_array( 'list', $allowed, true ),
				'patch'           => $is_pro && in_array( 'write', $allowed, true ),
				'write'           => $is_pro && in_array( 'write', $allowed, true ),
				'mkdir'           => $is_pro && in_array( 'mkdir', $allowed, true ),
				'delete'          => $is_pro && in_array( 'delete', $allowed, true ),
				'rename'          => $is_pro && in_array( 'rename', $allowed, true ),
				'restore'         => $is_pro && in_array( 'restore', $allowed, true ),
				'content_handles' => true,
				'redaction'       => true,
			),
			'rollout' => class_exists( 'RemoteWP_Rollout_Policy' ) ? RemoteWP_Rollout_Policy::status() : array(),
		);
	}

	private static function token_state() {
		$ttl        = (int) get_option( 'remotewp_token_ttl', 0 );
		$created_at = (int) get_option( 'remotewp_token_created_at', 0 );
		$expires_at = $ttl > 0 && $created_at > 0 ? $created_at + $ttl : 0;
		return array(
			'status'     => $expires_at > 0 && $expires_at <= time() ? 'expired' : ( $expires_at > 0 ? 'expiring' : 'permanent' ),
			'created_at' => $created_at,
			'expires_at' => $expires_at,
		);
	}

	private static function profile_operations( $profile ) {
		$profiles = array(
			'read-only'  => array( 'list', 'read', 'status', 'search', 'instructions' ),
			'read-write' => array( 'list', 'read', 'write', 'mkdir', 'status', 'search', 'instructions' ),
			'full'       => array( 'list', 'read', 'write', 'delete', 'rename', 'mkdir', 'restore', 'status', 'search', 'instructions' ),
		);
		return isset( $profiles[ $profile ] ) ? $profiles[ $profile ] : $profiles['read-only'];
	}
}
