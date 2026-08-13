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
	const OPT_TRIAL_EXPIRES = 'remotewp_license_trial_expires';

	/**
	 * Get the current license tier.
	 *
	 * @return string 'free', 'developer', 'agency', or 'lifetime'
	 */
	public function get_tier() {
		// Full Admin build is pre-activated with unlimited lifetime privileges
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

		// A trial is represented as a developer entitlement while active.
		// Once it expires, remove the cached encrypted module and fall back to
		// the Free tier on the next WordPress request.
		$trial_expires = get_option( self::OPT_TRIAL_EXPIRES, '' );
		if ( ! empty( $trial_expires ) && strtotime( $trial_expires ) <= time() ) {
			if ( class_exists( 'RemoteWP_Pro_Loader' ) ) {
				( new RemoteWP_Pro_Loader( $this ) )->delete_module();
			}
			update_option( self::OPT_TIER, 'free' );
			return 'free';
		}

		// Check expiration for non-lifetime
		$tier    = get_option( self::OPT_TIER, 'free' );
		$expires = get_option( self::OPT_EXPIRES, '' );

		if ( 'lifetime' !== $tier && ! empty( $expires ) && strtotime( $expires ) < time() ) {
			// License expired
			update_option( self::OPT_STATUS, 'expired' );
			if ( class_exists( 'RemoteWP_Pro_Loader' ) ) {
				( new RemoteWP_Pro_Loader( $this ) )->delete_module();
			}
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
		return defined( 'REMOTEWP_IS_PRO' ) && REMOTEWP_IS_PRO && 'free' !== $this->get_tier();
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
		update_option( self::OPT_KEY, $this->encrypt( $key ) );
		update_option( self::OPT_STATUS, 'active' );
		update_option( self::OPT_TIER, sanitize_key( $body['tier'] ?? 'developer' ) );
		update_option( self::OPT_EXPIRES, sanitize_text_field( $body['expires'] ?? '' ) );
		update_option( self::OPT_TRIAL_EXPIRES, sanitize_text_field( $body['trial_expires'] ?? '' ) );

		// Fetch Pro module from server (server-side code delivery)
		$tier = sanitize_key( $body['tier'] ?? 'developer' );
		// Trial licenses are returned as developer while active, so they must
		// fetch the encrypted module exactly like paid Pro licenses.
		if ( ( ! empty( $body['is_pro'] ) || 'free' !== $tier ) && class_exists( 'RemoteWP_Pro_Loader' ) ) {
			$loader = new RemoteWP_Pro_Loader( $this );
			$loader->fetch_module();
		}

		return array(
			'success' => true,
			'tier'    => $body['tier'] ?? 'developer',
			'expires' => $body['expires'] ?? '',
			'message' => $body['message'] ?? __( 'License activated successfully!', 'remotewp' ),
		);
	}

	/**
	 * Auto-activate license silently (for Full/internal builds).
	 * Registers the domain with the server, stores key locally.
	 * Does NOT fetch Pro module (Full has pro/ folder directly).
	 *
	 * @param string $key License key.
	 */
	public function auto_activate( $key ) {
		$key = sanitize_text_field( trim( $key ) );
		if ( empty( $key ) ) {
			return;
		}

		$response = wp_remote_post( $this->api_url . '/activate', array(
			'timeout' => 15,
			'body'    => array(
				'license_key'    => $key,
				'domain'         => $this->get_site_domain(),
				'plugin_version' => REMOTEWP_VERSION,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['success'] ) ) {
			return;
		}

		update_option( self::OPT_KEY, $this->encrypt( $key ) );
		update_option( self::OPT_STATUS, 'active' );
		update_option( self::OPT_TIER, sanitize_key( $body['tier'] ?? 'lifetime' ) );
		update_option( self::OPT_EXPIRES, sanitize_text_field( $body['expires'] ?? '' ) );
	}

	/**
	 * Deactivate the current license.
	 *
	 * @return array|WP_Error
	 */
	public function deactivate() {
		$key = $this->get_license_key();

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
		delete_option( self::OPT_TRIAL_EXPIRES );

		// Delete Pro module (server-side code delivery)
		if ( class_exists( 'RemoteWP_Pro_Loader' ) ) {
			$loader = new RemoteWP_Pro_Loader( $this );
			$loader->delete_module();
		}

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
		$key = $this->get_license_key();

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
		if ( ! empty( $body['trial_expires'] ) ) {
			update_option( self::OPT_TRIAL_EXPIRES, sanitize_text_field( $body['trial_expires'] ) );
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
		$key    = $this->get_license_key();
		$is_pro = defined( 'REMOTEWP_IS_PRO' ) && REMOTEWP_IS_PRO;

		$trial_expires = get_option( self::OPT_TRIAL_EXPIRES, '' );
		$is_trial = ! empty( $trial_expires ) && strtotime( $trial_expires ) > time();

		if ( $is_pro && ( strpos( $key, 'RWFREE' ) === 0 || $is_trial ) ) {
			return __( 'Free (PRO Trial 48h Active)', 'remotewp' );
		}

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
		$key = $this->get_license_key();

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
		$host = $parsed['host'] ?? $url;
		// Strip www. to match server-side normalizeDomain()
		$host = preg_replace( '/^www\./i', '', $host );
		return strtolower( $host );
	}

	/**
	 * Get and decrypt the license key.
	 *
	 * @return string
	 */
	public function get_license_key() {
		return $this->decrypt( get_option( self::OPT_KEY, '' ) );
	}

	/**
	 * Encrypt the license key.
	 *
	 * @param string $key The plain text key.
	 * @return string Encrypted hex/base64 string.
	 */
	private function encrypt( $key ) {
		$secret = 'xhouse_remotewp_crypt_secret_2026';
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv = '1234567890123456';
			$encrypted = openssl_encrypt( $key, 'AES-256-CBC', $secret, 0, $iv );
			return 'aes:' . base64_encode( $encrypted );
		}
		// Fallback: simple XOR
		$out = '';
		for ( $i = 0; $i < strlen( $key ); $i++ ) {
			$out .= $key[$i] ^ $secret[$i % strlen($secret)];
		}
		return 'xor:' . base64_encode( $out );
	}

	/**
	 * Decrypt the license key.
	 *
	 * @param string $encrypted Encrypted key.
	 * @return string Decrypted key.
	 */
	private function decrypt( $encrypted ) {
		if ( empty( $encrypted ) ) {
			return '';
		}
		$secret = 'xhouse_remotewp_crypt_secret_2026';
		if ( strpos( $encrypted, 'aes:' ) === 0 ) {
			$data = base64_decode( substr( $encrypted, 4 ) );
			$iv = '1234567890123456';
			return openssl_decrypt( $data, 'AES-256-CBC', $secret, 0, $iv );
		}
		if ( strpos( $encrypted, 'xor:' ) === 0 ) {
			$data = base64_decode( substr( $encrypted, 4 ) );
			$out = '';
			for ( $i = 0; $i < strlen( $data ); $i++ ) {
				$out .= $data[$i] ^ $secret[$i % strlen($secret)];
			}
			return $out;
		}
		// Unencrypted fallback (for backward compatibility)
		return $encrypted;
	}
}
