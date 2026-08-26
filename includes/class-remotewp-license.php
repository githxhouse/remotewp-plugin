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
	 * Check if trial mode is active.
	 *
	 * @return bool
	 */
	public function is_trial_active() {
		$key           = $this->get_license_key();
		$is_pro        = defined( 'REMOTEWP_IS_PRO' ) && REMOTEWP_IS_PRO;
		$trial_expires = get_option( self::OPT_TRIAL_EXPIRES, '' );
		$is_trial      = ! empty( $trial_expires ) && strtotime( $trial_expires ) > time();

		return ( $is_pro && ( strpos( $key, 'RWFREE' ) === 0 || $is_trial ) );
	}

	/**
	 * Check if the current installation is a Pro build with active license.
	 *
	 * @return bool
	 */
	public function is_pro() {
		return defined( 'REMOTEWP_IS_PRO' ) && REMOTEWP_IS_PRO && ( 'free' !== $this->get_tier() || $this->is_trial_active() );
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

		// Site-token connected sites have no local license key.
		// Don't invalidate — the central server manages entitlement.
		if ( empty( $key ) && 'site_token' === get_option( 'remotewp_pro_module_key_mode', 'license_key' ) ) {
			return true;
		}

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
	 * Submit a redacted beneficiary identity claim to the central tenant service.
	 *
	 * The server resolves the agency account from the authenticated Pro license;
	 * this method deliberately never accepts or sends agency_account_id.
	 *
	 * @param array $claim Identity evidence collected by the operating agent.
	 * @return array|WP_Error
	 */
	public function submit_domain_identity_claim( $claim ) {
		if ( ! is_array( $claim ) || ! $this->is_pro() ) {
			return new WP_Error( 'pro_required', __( 'An active Pro license is required for domain identity claims.', 'remotewp' ) );
		}

		$license_key = $this->get_license_key();
		if ( empty( $license_key ) ) {
			return new WP_Error( 'no_license', __( 'No active license key is available.', 'remotewp' ) );
		}

		$payload = $claim;
		unset(
			$payload['agency_account_id'],
			$payload['beneficiary_id'],
			$payload['platform_operator'],
			$payload['executor_name'],
			$payload['client_name'],
			$payload['author']
		);
		$payload['site_id'] = $this->get_site_id();
		$payload['domain']  = $this->get_site_domain();

		$server = defined( 'REMOTEWP_LICENSE_SERVER' ) ? REMOTEWP_LICENSE_SERVER : 'https://remotewp.dev';
		$url    = trailingslashit( $server ) . 'api/v1/tenant/domain-identity-claims';
		$request_id = $this->new_transport_request_id();

		$response = wp_remote_post( $url, array(
			'timeout' => 15,
			'body'    => wp_json_encode( $payload ),
			'headers' => $this->get_tenant_request_headers( $license_key, $request_id ),
		) );

		if ( is_wp_error( $response ) ) {
			return $this->tenant_transport_error(
				'connection_failed',
				__( 'Could not connect to the RemoteWP tenant service. Check the WAF/firewall logs using the RemoteWP request ID.', 'remotewp' ),
				'/api/v1/tenant/domain-identity-claims',
				$request_id
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || empty( $body['success'] ) ) {
			$error_code = ! empty( $body['code'] ) ? sanitize_key( $body['code'] ) : 'identity_claim_failed';
			$message     = ! empty( $body['message'] ) ? sanitize_text_field( $body['message'] ) : __( 'The domain identity claim could not be recorded. Check the WAF/firewall logs using the RemoteWP request ID.', 'remotewp' );
			return $this->tenant_transport_error( $error_code, $message, '/api/v1/tenant/domain-identity-claims', $request_id, $code, $response );
		}

		return $body;
	}

	/**
	 * Submit a redacted technical handoff automatically through the central
	 * RemoteWP service. The authenticated site/license connection is the
	 * authorization; no per-site consent checkbox is required.
	 *
	 * @param array $handoff Handoff payload without platform/account identity.
	 * @return true|WP_Error
	 */
	public function submit_handoff_log( $handoff ) {
		if ( ! is_array( $handoff ) || ! $this->is_pro() ) {
			return new WP_Error( 'pro_required', __( 'An active Pro license is required for external handoff.', 'remotewp' ) );
		}

		$license_key = $this->get_license_key();
		if ( empty( $license_key ) ) {
			return new WP_Error( 'no_license', __( 'No active license key is available.', 'remotewp' ) );
		}

		$payload = $handoff;
		unset( $payload['agency_account_id'], $payload['beneficiary_id'], $payload['platform_operator'] );
		$payload['site_id'] = $this->get_site_id();
		$payload['domain']  = $this->get_site_domain();

		$server = defined( 'REMOTEWP_LICENSE_SERVER' ) ? REMOTEWP_LICENSE_SERVER : 'https://remotewp.dev';
		$url    = trailingslashit( $server ) . 'api/v1/handoff/log';
		$request_id = $this->new_transport_request_id();
		$response = wp_remote_post( $url, array(
			// Handoff delivery must be confirmed; dispatching a background request
			// is not proof that the central server accepted the log.
			'blocking' => true,
			'timeout'  => 15,
			'body'     => wp_json_encode( $payload ),
			'headers'  => $this->get_tenant_request_headers( $license_key, $request_id ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$error_code = is_array( $body ) && ! empty( $body['code'] ) ? sanitize_key( $body['code'] ) : 'handoff_http_error';
			$message = is_array( $body ) && ! empty( $body['message'] ) ? sanitize_text_field( $body['message'] ) : sprintf( 'Central handoff rejected the log with HTTP %d.', $code );
			return new WP_Error( $error_code, $message, array( 'status' => $code, 'request_id' => $request_id ) );
		}

		return true;
	}

	/**
	 * Fetch central handoff context through the plugin's license connection.
	 * The site token remains the only credential visible to the agent.
	 *
	 * @return array|WP_Error
	 */
	public function get_handoff_context() {
		if ( ! $this->is_pro() ) {
			return new WP_Error( 'pro_required', __( 'An active Pro license is required for external handoff.', 'remotewp' ), array( 'status' => 403 ) );
		}
		$license_key = $this->get_license_key();
		if ( empty( $license_key ) ) {
			return new WP_Error( 'no_license', __( 'No active license key is available.', 'remotewp' ), array( 'status' => 403 ) );
		}

		$server     = defined( 'REMOTEWP_LICENSE_SERVER' ) ? REMOTEWP_LICENSE_SERVER : 'https://remotewp.dev';
		$request_id = $this->new_transport_request_id();
		$url        = add_query_arg(
			array(
				'domain' => $this->get_site_domain(),
				'site_id' => $this->get_site_id(),
			),
			trailingslashit( $server ) . 'api/v1/handoff/context'
		);
		$response = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => $this->get_tenant_request_headers( $license_key, $request_id ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			return new WP_Error( 'handoff_context_error', __( 'Central handoff context could not be loaded.', 'remotewp' ), array( 'status' => $code, 'request_id' => $request_id ) );
		}
		return $body;
	}

	/**
	 * Get license info for display.
	 *
	 * @return array
	 */
	public function get_info() {
		$tier = $this->get_tier();

		return array(
			'key'           => $this->get_masked_key(),
			'status'        => get_option( self::OPT_STATUS, 'inactive' ),
			'tier'          => $tier,
			'tier_label'    => $this->get_tier_label( $tier ),
			'expires'       => get_option( self::OPT_EXPIRES, '' ),
			'trial_expires' => get_option( self::OPT_TRIAL_EXPIRES, '' ),
			'is_trial'      => $this->is_trial_active(),
			'is_pro'        => $this->is_pro(),
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
	 * Get the opaque, deterministic identity of the current WordPress site.
	 *
	 * @return string
	 */
	public function get_site_id() {
		return hash( 'sha256', strtolower( untrailingslashit( (string) home_url( '/' ) ) ) );
	}

	/**
	 * Create a safe correlation ID for central/WAF diagnostics.
	 *
	 * @return string
	 */
	private function new_transport_request_id() {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'rwp_', true );
	}

	/**
	 * Build headers shared by central tenant requests.
	 *
	 * @param string $license_key License key.
	 * @param string $request_id  Correlation ID.
	 * @return array
	 */
	private function get_tenant_request_headers( $license_key, $request_id ) {
		return array(
			'Authorization'          => 'Bearer ' . $license_key,
			'Content-Type'            => 'application/json',
			'Accept'                 => 'application/json',
			'X-RemoteWP-Request-ID'  => sanitize_text_field( $request_id ),
			'X-RemoteWP-Client'      => 'remotewp-plugin/' . ( defined( 'REMOTEWP_VERSION' ) ? REMOTEWP_VERSION : 'unknown' ),
		);
	}

	/**
	 * Return a redacted transport error that helps identify WAF/firewall blocks.
	 * Never include request bodies, license keys or response HTML in the error.
	 *
	 * @param string $code       WordPress error code.
	 * @param string $message    Safe user-facing message.
	 * @param string $endpoint   Remote endpoint path.
	 * @param string $request_id Local correlation ID.
	 * @param int    $status     HTTP status.
	 * @param array  $response   Optional WP HTTP response.
	 * @return WP_Error
	 */
	private function tenant_transport_error( $code, $message, $endpoint, $request_id, $status = 0, $response = array() ) {
		$remote_request_id = ! empty( $response ) ? wp_remote_retrieve_header( $response, 'x-remotewp-request-id' ) : '';
		$correlation_id    = $remote_request_id ? sanitize_text_field( $remote_request_id ) : sanitize_text_field( $request_id );
		$diagnostic        = 'transport_error';

		if ( in_array( (int) $status, array( 403, 406, 413, 429, 503 ), true ) ) {
			$diagnostic = 'possible_waf_or_firewall_block';
		}

		return new WP_Error(
			$code,
			$message,
			array(
				'endpoint'        => sanitize_text_field( $endpoint ),
				'http_status'     => (int) $status,
				'request_id'      => $correlation_id,
				'diagnostic'      => $diagnostic,
				'retryable'       => in_array( (int) $status, array( 429, 502, 503, 504 ), true ),
			)
		);
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
		$secret = $this->get_crypto_secret();
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv = $this->get_crypto_iv();
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
		$secret = $this->get_crypto_secret();
		if ( strpos( $encrypted, 'aes:' ) === 0 ) {
			$data = base64_decode( substr( $encrypted, 4 ) );
			$iv = $this->get_crypto_iv();
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

	/**
	 * Derive encryption material from the site's WordPress salts and URL.
	 * No shared platform secret is embedded in the public plugin package.
	 *
	 * @return string
	 */
	private function get_crypto_secret() {
		$auth_salt = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : wp_salt( 'auth' );
		return hash( 'sha256', $auth_salt . '|' . home_url( '/' ) . '|remotewp-license', true );
	}

	/**
	 * Derive a stable per-site initialization vector.
	 *
	 * @return string
	 */
	private function get_crypto_iv() {
		return substr( hash( 'sha256', home_url( '/' ) . '|remotewp-iv', true ), 0, 16 );
	}
}
