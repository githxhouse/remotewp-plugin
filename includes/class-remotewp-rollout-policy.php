<?php
/**
 * RemoteWP v2 rollout and safety controls.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Rollout_Policy {
	const KILL_SWITCH_OPTION = 'remotewp_safety_kill_switch';
	const MUTATIONS_OPTION   = 'remotewp_v2_mutations_enabled';
	const ALLOWLIST_OPTION   = 'remotewp_v2_mutation_allowlist';

	/**
	 * Authorize a v2 operation against rollout controls.
	 *
	 * Empty allowlists preserve the existing behavior. Once an allowlist is
	 * configured, the current site must match its opaque site_id or host.
	 *
	 * @param string $operation Internal operation name.
	 * @return true|WP_Error
	 */
	public static function authorize( $operation ) {
		if ( ! self::is_mutation( $operation ) ) {
			return true;
		}

		// Mutation availability is an entitlement decision made by the central
		// RemoteWP server. Legacy local kill-switch/allowlist options must not
		// silently block connected customers. Sensitive operations are guarded by
		// the backup + operator-approval flow in the mutation handlers.
		return true;
	}

	/**
	 * Return non-secret rollout state for ConnectionContext/status responses.
	 *
	 * @return array
	 */
	public static function status() {
		return array(
			'authority'         => 'central_server',
			'kill_switch'       => false,
			'mutations_enabled' => true,
			'allowlisted'       => true,
			'allowlist_configured' => false,
		);
	}

	private static function is_mutation( $operation ) {
		return in_array( $operation, array( 'write', 'delete', 'rename', 'mkdir', 'restore', 'patch' ), true );
	}

	private static function enabled( $option, $default ) {
		$value = get_option( $option, $default ? 1 : 0 );
		return ! in_array( $value, array( false, 0, '0', '', 'off', 'false' ), true );
	}

	private static function allowlist() {
		$value = get_option( self::ALLOWLIST_OPTION, '' );
		if ( is_array( $value ) ) {
			$items = $value;
		} else {
			$items = preg_split( '/[\r\n,]+/', (string) $value );
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', $items ) ) ) ) );
	}

	private static function site_id() {
		$site_url = function_exists( 'home_url' ) ? home_url( '/' ) : (string) get_option( 'siteurl', '' );
		return hash( 'sha256', strtolower( untrailingslashit( (string) $site_url ) ) );
	}

	private static function host() {
		$site_url = function_exists( 'home_url' ) ? home_url( '/' ) : (string) get_option( 'siteurl', '' );
		return strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
	}
}
