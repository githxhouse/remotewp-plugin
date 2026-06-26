<?php
/**
 * RemoteWP License Manager
 *
 * Handles license key activation, deactivation, and verification.
 * In the Open Core model, feature gating is done by physical file
 * presence (pro/ folder), not runtime checks.
 *
 * Tiers:
 *   - free:      Read-only endpoints (no pro/ folder)
 *   - developer: All features, 10 sites ($79/yr)
 *   - agency:    All features, unlimited sites ($149/yr)
 *   - lifetime:  All features, unlimited sites, no expiry ($349)
 *
 * @package RemoteWP
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_License {

	/**
	 * Remote license API base URL.
	 *
	 * External service: remotewp.dev (operated by X-HOUSE SRL, Arad, Romania)
	 * Endpoints used:
	 *   - /activate   — sends license_key, site domain, plugin_version (on license activation)
	 *   - /deactivate — sends license_key, site domain (on license deactivation)
	 *   - /verify     — sends license_key, site domain, plugin_version (daily WP-Cron check)
	 * Only contacts the server if the user has entered a license key.
	 * Privacy policy: https://remotewp.dev/privacy-policy.html
	 *
	 * @var string
	 */
	private $api_url = 'https://remotewp.dev/wp-json/remotewp-license/v1';

	/**
	 * Option keys.
	 */
	const OPT_KEY     = 'remotewp_license_key';
	const OPT_STATUS  = 'remotewp_license_status';
	const OPT_TIER    = 'remotewp_license_tier';
	const OPT_EXPIRES = 'remotewp_license_expires';

	/**
	 * Get the current license tier.
	 *
	 * @return string 'free', 'developer', 'agency', or 'lifetime'
	 */
	public function get_tier() {
		// If full files are present, it is always full
		if ( defined( 'REMOTEWP_IS_FULL' ) && REMOTEWP_IS_FULL ) {
			return 'full';
		}

		// If pro files are not present, always free
		if ( ! defined( 'REMOTEWP_IS_PRO' ) || ! REMOTEWP_IS_PRO ) {
			return 'free';
		}

		$status = get_option( self::OPT_STATUS, 'inactive' );

		if ( 'active' !== $status ) {
			return 'free';
		}

		// Check expiration for non-lifetime
		$tier    = get_option( self::OPT_TIER, 'free' );
		$expires = get_option( self::OPT_EXPIRES, '' );

		if ( 'lifetime' !== $tier && ! empty( $expires ) && strtotime( $expires ) < time() ) {
			// License expired
			update_option( self::OPT_STATUS, 'expired' );
			return 'free';
		}

		return $tier;
	}

	/**
	 * Check if the current installation is a Pro build with active license.
	 *
	 * @return bool
	 */
	public function is_pro() {
		return defined( 'REMOTEWP_IS_PRO' ) && REMOTEWP_IS_PRO;
	}

	/**
	 * Activate a license key.
	 *
	 * @param string $key The license key to activate.
	 * @return array|WP_Error Activation result or error.
	 */
	public function activate( $key ) {
		$key = sanitize_text_field( trim( $key ) );

		if ( empty( $key ) ) {
			return new WP_Error( 'empty_key', __( 'Please enter a license key.', 'remotewp' ) );
		}

		$response = wp_remote_post( $this->api_url . '/activate', array(
			'timeout' => 15,
			'body'    => array(
				'license_key' => $key,
				'domain'      => $this->get_site_domain(),
				'plugin_version' => REMOTEWP_VERSION,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'connection_failed',
				__( 'Could not connect to the license server. Please check your internet connection and try again.', 'remotewp' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['success'] ) ) {
			$message = $body['message'] ?? __( 'License activation failed.', 'remotewp' );
			return new WP_Error( 'activation_failed', $message );
		}

		// Save license data
		update_option( self::OPT_KEY, $key );
		update_option( self::OPT_STATUS, 'active' );
		update_option( self::OPT_TIER, sanitize_key( $body['tier'] ?? 'developer' ) );
		update_option( self::OPT_EXPIRES, sanitize_text_field( $body['expires'] ?? '' ) );

		return array(
			'success' => true,
			'tier'    => $body['tier'] ?? 'developer',
			'expires' => $body['expires'] ?? '',
			'message' => $body['message'] ?? __( 'License activated successfully!', 'remotewp' ),
		);
	}

	/**
	 * Deactivate the current license.
	 *
	 * @return array|WP_Error
	 */
	public function deactivate() {
		$key = get_option( self::OPT_KEY, '' );

		if ( empty( $key ) ) {
			return new WP_Error( 'no_license', __( 'No license key is currently active.', 'remotewp' ) );
		}

		// Notify remote server
		wp_remote_post( $this->api_url . '/deactivate', array(
			'timeout' => 10,
			'body'    => array(
				'license_key' => $key,
				'domain'      => $this->get_site_domain(),
			),
		) );

		// Clear local data regardless of server response
		delete_option( self::OPT_KEY );
		update_option( self::OPT_STATUS, 'inactive' );
		update_option( self::OPT_TIER, 'free' );
		delete_option( self::OPT_EXPIRES );

		return array(
			'success' => true,
			'message' => __( 'License deactivated. This site is now on the free tier.', 'remotewp' ),
		);
	}

	/**
	 * Verify the current license with the remote server.
	 * Called periodically (daily via WP Cron).
	 *
	 * @return bool True if valid, false if invalid/expired.
	 */
	public function verify() {
		$key = get_option( self::OPT_KEY, '' );

		if ( empty( $key ) ) {
			return false;
		}

		$response = wp_remote_post( $this->api_url . '/verify', array(
			'timeout' => 10,
			'body'    => array(
				'license_key' => $key,
				'domain'      => $this->get_site_domain(),
				'plugin_version' => REMOTEWP_VERSION,
			),
		) );

		if ( is_wp_error( $response ) ) {
			// Network failure — don't deactivate (be generous)
			return true;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['valid'] ) ) {
			update_option( self::OPT_STATUS, 'invalid' );
			return false;
		}

		// Update tier/expiry from server (source of truth)
		update_option( self::OPT_STATUS, 'active' );

		if ( ! empty( $body['tier'] ) ) {
			update_option( self::OPT_TIER, sanitize_key( $body['tier'] ) );
		}

		if ( ! empty( $body['expires'] ) ) {
			update_option( self::OPT_EXPIRES, sanitize_text_field( $body['expires'] ) );
		}

		return true;
	}

	/**
	 * Get license info for display.
	 *
	 * @return array
	 */
	public function get_info() {
		$tier = $this->get_tier();

		return array(
			'key'        => $this->get_masked_key(),
			'status'     => get_option( self::OPT_STATUS, 'inactive' ),
			'tier'       => $tier,
			'tier_label' => $this->get_tier_label( $tier ),
			'expires'    => get_option( self::OPT_EXPIRES, '' ),
			'is_pro'     => $this->is_pro(),
		);
	}

	/**
	 * Get a human-readable tier label.
	 *
	 * @param string $tier Tier slug.
	 * @return string
	 */
	public function get_tier_label( $tier ) {
		$labels = array(
			'free'      => __( 'Free', 'remotewp' ),
			'developer' => __( 'Developer', 'remotewp' ),
			'agency'    => __( 'Agency', 'remotewp' ),
			'lifetime'  => __( 'Lifetime', 'remotewp' ),
			'full'      => __( 'Full (Admin)', 'remotewp' ),
		);

		return $labels[ $tier ] ?? $labels['free'];
	}

	/**
	 * Get masked license key for display (show first 8 + last 4 chars).
	 *
	 * @return string
	 */
	private function get_masked_key() {
		$key = get_option( self::OPT_KEY, '' );

		if ( empty( $key ) || strlen( $key ) < 16 ) {
			return '';
		}

		return substr( $key, 0, 8 ) . str_repeat( '•', strlen( $key ) - 12 ) . substr( $key, -4 );
	}

	/**
	 * Get the current site domain (normalized).
	 *
	 * @return string
	 */
	private function get_site_domain() {
		$url = home_url();
		$parsed = wp_parse_url( $url );
		return $parsed['host'] ?? $url;
	}
}
