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

		if ( self::enabled( self::KILL_SWITCH_OPTION, false ) ) {
			return new WP_Error( 'safety_mode', __( 'RemoteWP mutations are temporarily disabled by the safety switch.', 'remotewp' ), array( 'status' => 503 ) );
		}

		if ( ! self::enabled( self::MUTATIONS_OPTION, true ) ) {
			return new WP_Error( 'safety_mode', __( 'RemoteWP v2 mutations are not enabled on this site.', 'remotewp' ), array( 'status' => 503 ) );
		}

		$allowlist = self::allowlist();
		if ( empty( $allowlist ) || in_array( '*', $allowlist, true ) ) {
			return true;
		}

		$site_id = self::site_id();
		$host    = self::host();
		if ( in_array( $site_id, $allowlist, true ) || in_array( $host, $allowlist, true ) ) {
			return true;
		}

		return new WP_Error( 'site_not_allowlisted', __( 'This site is not allowlisted for RemoteWP v2 mutations.', 'remotewp' ), array( 'status' => 403 ) );
	}

	/**
	 * Return non-secret rollout state for ConnectionContext/status responses.
	 *
	 * @return array
	 */
	public static function status() {
		$allowlist = self::allowlist();
		return array(
			'kill_switch'       => self::enabled( self::KILL_SWITCH_OPTION, false ),
			'mutations_enabled' => self::enabled( self::MUTATIONS_OPTION, true ),
			'allowlisted'       => empty( $allowlist ) || in_array( '*', $allowlist, true ) || in_array( self::site_id(), $allowlist, true ) || in_array( self::host(), $allowlist, true ),
			'allowlist_configured' => ! empty( $allowlist ),
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
