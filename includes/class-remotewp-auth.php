<?php
/**
 * RemoteWP Authentication
 *
 * Handles API token validation, HTTPS enforcement,
 * IP whitelist checking, and integrates with rate limiter.
 *
 * @package RemoteWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Auth {

	/**
	 * @var RemoteWP_Rate_Limiter
	 */
	private $rate_limiter;

	/**
	 * @var RemoteWP_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param RemoteWP_Rate_Limiter $rate_limiter Rate limiter instance.
	 * @param RemoteWP_Logger       $logger       Logger instance.
	 */
	public function __construct( RemoteWP_Rate_Limiter $rate_limiter, RemoteWP_Logger $logger ) {
		$this->rate_limiter = $rate_limiter;
		$this->logger       = $logger;
	}

	/**
	 * Validate an incoming REST API request.
	 *
	 * This is used as the permission_callback for all API routes.
	 * Checks: HTTPS → IP Whitelist → Rate Limit → Token.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return true|WP_Error True if authorized, WP_Error if not.
	 */
	public function validate_request( WP_REST_Request $request ) {
		$ip = $this->get_client_ip();

		// 1. Enforce HTTPS (except localhost)
		$https_check = $this->check_https();
		if ( is_wp_error( $https_check ) ) {
			$this->logger->log( 'AUTH_FAIL', '', 'HTTPS required', 'error' );
			return $https_check;
		}

		// 2. Check IP Whitelist
		$ip_check = $this->check_ip_whitelist( $ip );
		if ( is_wp_error( $ip_check ) ) {
			$this->logger->log( 'AUTH_FAIL', '', 'IP not whitelisted: ' . $ip, 'error' );
			return $ip_check;
		}

		// 3. Check Rate Limit
		$rate_check = $this->rate_limiter->check( $ip );
		if ( is_wp_error( $rate_check ) ) {
			$this->logger->log( 'RATE_LIMITED', '', 'IP: ' . $ip, 'error' );
			return $rate_check;
		}

		// 4. Validate Token
		$provided_token = $request->get_header( 'x_remotewp_token' );

		// Fallback: also accept the old header name for backward compatibility
		if ( empty( $provided_token ) ) {
			$provided_token = $request->get_header( 'x_house_token' );
		}

		$expected_token = get_option( 'remotewp_api_token' );

		if ( empty( $provided_token ) ) {
			$this->rate_limiter->record_failure( $ip );
			$this->logger->log( 'AUTH_FAIL', '', 'Missing token header', 'error' );
			return new WP_Error(
				'unauthorized',
				__( 'Missing authentication token. Send it via the X-RemoteWP-Token header.', 'remotewp' ),
				array( 'status' => 401 )
			);
		}

		if ( ! hash_equals( $expected_token, $provided_token ) ) {
			$this->rate_limiter->record_failure( $ip );
			$this->logger->log( 'AUTH_FAIL', '', 'Invalid token from IP: ' . $ip, 'error' );
			return new WP_Error(
				'unauthorized',
				__( 'Invalid authentication token.', 'remotewp' ),
				array( 'status' => 401 )
			);
		}

		// 5. Check Token Expiry (TTL)
		$token_ttl = (int) get_option( 'remotewp_token_ttl', 0 ); // 0 = never expire (backward compatible)
		if ( $token_ttl > 0 ) {
			$token_created = (int) get_option( 'remotewp_token_created_at', 0 );
			if ( $token_created > 0 && ( time() - $token_created ) > $token_ttl ) {
				$this->logger->log( 'AUTH_FAIL', '', 'Token expired (TTL: ' . $token_ttl . 's)', 'error' );
				$admin_url = admin_url( 'admin.php?page=remotewp' );
				return new WP_Error(
					'token_expired',
					sprintf(
						/* translators: %s: admin URL */
						__( 'API token has expired. Please generate a new one from WP Admin → RemoteWP (%s).', 'remotewp' ),
						$admin_url
					),
					array( 'status' => 401 )
				);
			}
		}

		// Auth successful — reset failure counter
		$this->rate_limiter->reset_failures( $ip );

		return true;
	}

	/**
	 * Get the current API token.
	 *
	 * @return string
	 */
	public function get_token() {
		return get_option( 'remotewp_api_token', '' );
	}

	/**
	 * Generate and save a new API token.
	 *
	 * @return string The new token.
	 */
	public function regenerate_token() {
		$token = bin2hex( random_bytes( 32 ) );
		update_option( 'remotewp_api_token', $token );
		update_option( 'remotewp_token_created_at', time() );
		$this->logger->log( 'TOKEN_REGENERATED', '', 'New token generated via admin' );
		return $token;
	}

	/**
	 * Get token expiry information.
	 *
	 * @return array {
	 *     @type int    $created_at Unix timestamp when token was created.
	 *     @type int    $ttl        Token lifetime in seconds (0 = never).
	 *     @type int    $expires_at Unix timestamp when token expires (0 = never).
	 *     @type int    $remaining  Seconds remaining until expiry (-1 = never).
	 *     @type string $status     'active', 'warning', 'expired', or 'permanent'.
	 * }
	 */
	public function get_token_expiry_info() {
		$ttl        = (int) get_option( 'remotewp_token_ttl', 0 );
		$created_at = (int) get_option( 'remotewp_token_created_at', 0 );

		if ( 0 === $ttl ) {
			return array(
				'created_at' => $created_at,
				'ttl'        => 0,
				'expires_at' => 0,
				'remaining'  => -1,
				'status'     => 'permanent',
			);
		}

		$expires_at = $created_at + $ttl;
		$remaining  = $expires_at - time();

		if ( $remaining <= 0 ) {
			$status = 'expired';
		} elseif ( $remaining < 7200 ) { // < 2 hours
			$status = 'warning';
		} else {
			$status = 'active';
		}

		return array(
			'created_at' => $created_at,
			'ttl'        => $ttl,
			'expires_at' => $expires_at,
			'remaining'  => max( 0, $remaining ),
			'status'     => $status,
		);
	}

	/**
	 * Check if the request is over HTTPS.
	 *
	 * @return true|WP_Error
	 */
	private function check_https() {
		// Use REMOTE_ADDR instead of SERVER_NAME to prevent Host header spoofing
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$is_localhost = in_array( $remote_addr, array( '127.0.0.1', '::1' ), true );

		if ( ! is_ssl() && ! $is_localhost ) {
			return new WP_Error(
				'https_required',
				__( 'RemoteWP API requires HTTPS for security.', 'remotewp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check if client IP is in the whitelist (if configured).
	 *
	 * @param string $ip Client IP address.
	 * @return true|WP_Error
	 */
	private function check_ip_whitelist( $ip ) {
		$whitelist = get_option( 'remotewp_ip_whitelist', '' );

		if ( empty( trim( $whitelist ) ) ) {
			return true; // No whitelist configured, allow all
		}

		$allowed_ips = array_filter( array_map( 'trim', explode( "\n", $whitelist ) ) );

		if ( empty( $allowed_ips ) ) {
			return true;
		}

		foreach ( $allowed_ips as $allowed_ip ) {
			// Support CIDR notation (e.g., 192.168.1.0/24)
			if ( strpos( $allowed_ip, '/' ) !== false ) {
				if ( $this->ip_in_cidr( $ip, $allowed_ip ) ) {
					return true;
				}
			} elseif ( $ip === $allowed_ip ) {
				return true;
			}
		}

		return new WP_Error(
			'ip_blocked',
			__( 'Your IP address is not in the allowed list.', 'remotewp' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Check if an IP is within a CIDR range.
	 *
	 * @param string $ip   The IP to check.
	 * @param string $cidr The CIDR range (e.g., 192.168.1.0/24).
	 * @return bool
	 */
	private function ip_in_cidr( $ip, $cidr ) {
		list( $subnet, $mask ) = explode( '/', $cidr );
		$subnet = ip2long( $subnet );
		$ip     = ip2long( $ip );
		$mask   = -1 << ( 32 - (int) $mask );

		return ( $ip & $mask ) === ( $subnet & $mask );
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$trust_proxy = get_option( 'remotewp_trust_proxy', false );

		if ( $trust_proxy ) {
			// Only trust forwarded headers if REMOTE_ADDR is a known proxy/loopback
			$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$is_trusted_proxy = $this->is_trusted_proxy_ip( $remote_addr );

			if ( $is_trusted_proxy ) {
				$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
			} else {
				// Direct connection bypassing proxy — ignore forwarded headers
				$headers = array( 'REMOTE_ADDR' );
			}
		} else {
			// Direct connection — only trust REMOTE_ADDR
			$headers = array( 'REMOTE_ADDR' );
		}

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				// Validate IP format
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return 'unknown';
	}

	/**
	 * Check if an IP is a trusted proxy (loopback or private network).
	 *
	 * @param string $ip The REMOTE_ADDR to check.
	 * @return bool
	 */
	private function is_trusted_proxy_ip( $ip ) {
		// Loopback
		if ( in_array( $ip, array( '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		// Private/reserved ranges (typical proxy/load balancer IPs)
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
			return true; // IP is private/reserved, likely a proxy
		}
		// Custom trusted proxies from settings
		$trusted_proxies = get_option( 'remotewp_trusted_proxies', '' );
		if ( ! empty( $trusted_proxies ) ) {
			$proxies = array_filter( array_map( 'trim', explode( "\n", $trusted_proxies ) ) );
			if ( in_array( $ip, $proxies, true ) ) {
				return true;
			}
		}
		return false;
	}
}
