<?php
/**
 * RemoteWP Rate Limiter
 *
 * Implements per-IP rate limiting and brute force lockout
 * using WordPress transients (no database tables needed).
 *
 * @package RemoteWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Rate_Limiter {

	/**
	 * Check if the current request should be rate-limited.
	 *
	 * @param string $ip Client IP address.
	 * @return true|WP_Error True if allowed, WP_Error if rate limited.
	 */
	public function check( $ip ) {
		// Whitelisted IPs bypass all rate limiting and lockout
		if ( $this->is_whitelisted( $ip ) ) {
			return true;
		}

		// Check lockout first
		$lockout = $this->is_locked_out( $ip );
		if ( is_wp_error( $lockout ) ) {
			return $lockout;
		}

		// Check rate limit
		$limit = (int) get_option( 'remotewp_rate_limit', 60 );
		if ( $limit <= 0 ) {
			return true; // Rate limiting disabled
		}

		$key  = 'remotewp_rate_' . md5( $ip );
		$data = get_transient( $key );

		if ( ! is_array( $data ) ) {
			// Initialize new window
			$data = array(
				'count'      => 1,
				'expires_at' => time() + 60,
			);
			set_transient( $key, $data, 60 );
		} else {
			$remaining = $data['expires_at'] - time();
			if ( $remaining <= 0 ) {
				// Window expired
				$data = array(
					'count'      => 1,
					'expires_at' => time() + 60,
				);
				set_transient( $key, $data, 60 );
			} else {
				if ( $data['count'] >= $limit ) {
					return new WP_Error(
						'rate_limited',
						sprintf(
							/* translators: %d: rate limit per minute */
							__( 'Rate limit exceeded. Maximum %d requests per minute.', 'remotewp' ),
							$limit
						),
						array( 'status' => 429 )
					);
				}
				$data['count']++;
				set_transient( $key, $data, $remaining );
			}
		}

		return true;
	}

	/**
	 * Record a failed authentication attempt and possibly trigger lockout.
	 *
	 * @param string $ip Client IP address.
	 */
	public function record_failure( $ip ) {
		$key      = 'remotewp_fails_' . md5( $ip );
		$failures = (int) get_transient( $key );
		$threshold = (int) get_option( 'remotewp_lockout_threshold', 5 );
		$duration  = (int) get_option( 'remotewp_lockout_duration', 15 );

		$failures++;

		if ( $failures >= $threshold ) {
			// Lock out the IP
			set_transient( 'remotewp_lockout_' . md5( $ip ), true, $duration * MINUTE_IN_SECONDS );
			delete_transient( $key );

			// Store IP-to-hash mapping for admin display
			$ip_map = get_option( 'remotewp_lockout_ip_map', array() );
			$ip_map[ md5( $ip ) ] = $ip;
			update_option( 'remotewp_lockout_ip_map', $ip_map, false );
		} else {
			set_transient( $key, $failures, $duration * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Reset failure counter for an IP (on successful auth).
	 *
	 * @param string $ip Client IP address.
	 */
	public function reset_failures( $ip ) {
		delete_transient( 'remotewp_fails_' . md5( $ip ) );
	}

	/**
	 * Check if an IP is currently locked out.
	 *
	 * @param string $ip Client IP address.
	 * @return true|WP_Error True if not locked out, WP_Error if locked out.
	 */
	private function is_locked_out( $ip ) {
		$lockout = get_transient( 'remotewp_lockout_' . md5( $ip ) );

		if ( $lockout ) {
			$duration = (int) get_option( 'remotewp_lockout_duration', 15 );
			return new WP_Error(
				'locked_out',
				sprintf(
					/* translators: %d: lockout duration in minutes */
					__( 'Too many failed attempts. Locked out for %d minutes.', 'remotewp' ),
					$duration
				),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check if an IP is in the whitelist.
	 *
	 * @param string $ip Client IP address.
	 * @return bool True if whitelisted.
	 */
	private function is_whitelisted( $ip ) {
		$whitelist = get_option( 'remotewp_ip_whitelist', '' );
		if ( empty( $whitelist ) ) {
			return false;
		}

		$entries = array_filter( array_map( 'trim', explode( "\n", $whitelist ) ) );
		foreach ( $entries as $entry ) {
			if ( $entry === $ip ) {
				return true;
			}
			// CIDR support
			if ( strpos( $entry, '/' ) !== false && $this->ip_in_cidr( $ip, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if an IP is within a CIDR range.
	 *
	 * @param string $ip   IP address.
	 * @param string $cidr CIDR notation (e.g., 192.168.1.0/24).
	 * @return bool
	 */
	private function ip_in_cidr( $ip, $cidr ) {
		list( $subnet, $bits ) = explode( '/', $cidr );
		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );
		$mask        = -1 << ( 32 - (int) $bits );
		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
	}
}
