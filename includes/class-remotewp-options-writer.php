<?php
/**
 * Allowlisted, credential-free writes to WordPress options.
 *
 * This is the $writable_options counterpart of RemoteWP_WP_API::$readable_options.
 * Only performance related options are writable (core image sizes and the
 * well-known cache plugins). Anything that looks like a credential is refused
 * by name, inside array values, and is masked when an old value is returned.
 *
 * @package RemoteWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Options_Writer {

	/**
	 * Exact option names => integer range.
	 *
	 * @var array
	 */
	const WRITABLE_EXACT = array(
		'thumbnail_size_w' => array( 0, 10000 ),
		'thumbnail_size_h' => array( 0, 10000 ),
		'medium_size_w'    => array( 0, 10000 ),
		'medium_size_h'    => array( 0, 10000 ),
		'large_size_w'     => array( 0, 10000 ),
		'large_size_h'     => array( 0, 10000 ),
		'wp_rocket_settings' => null,
		'perfmatters_options' => null,
	);

	/**
	 * Prefix families of the known cache plugins.
	 *
	 * @var string[]
	 */
	const WRITABLE_PREFIXES = array( 'litespeed.conf.', 'w3tc_', 'autoptimize_' );

	const MAX_STRING  = 2000;
	const MAX_ENTRIES = 200;
	const MAX_DEPTH   = 3;

	/**
	 * Names or array keys containing any of these are never written, and are
	 * masked on output. Deliberately broad: a false positive costs a manual
	 * edit, a false negative leaks a credential.
	 */
	const DENY_PATTERN = '/(secret|passw|pswd|passwd|pwd|token|api[-_.]?key|apikey|licen[sc]e|credential|salt|private|auth|e-?mail|username|login|oauth|bearer)/i';

	const DENY_TOKENS = array( 'key', 'keys', 'api', 'user', 'users', 'pass', 'mail', 'cookie', 'cookies', 'zone', 'account', 'cf', 'sid', 'pin' );

	/**
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_writable_name( $name ) {
		if ( ! is_string( $name ) || strlen( $name ) > 120 || 1 !== preg_match( '/^[A-Za-z0-9_.\-]+$/', $name ) ) {
			return false;
		}
		if ( self::is_denied( $name ) ) {
			return false;
		}
		if ( array_key_exists( $name, self::WRITABLE_EXACT ) ) {
			return true;
		}
		foreach ( self::WRITABLE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) && strlen( $name ) > strlen( $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Human readable description of the allowlist, for error messages.
	 *
	 * @return string
	 */
	public static function describe_allowlist() {
		return 'litespeed.conf.*, wp_rocket_settings, w3tc_*, autoptimize_*, perfmatters_options, thumbnail_size_w/h, medium_size_w/h, large_size_w/h (credential-like options and sub-keys are always refused)';
	}

	/**
	 * Validate a new value before anything is written.
	 *
	 * @param string $name  Option name (already allowlisted).
	 * @param mixed  $value Proposed value.
	 * @return mixed|WP_Error The value to store.
	 */
	public static function validate_value( $name, $value ) {
		if ( isset( self::WRITABLE_EXACT[ $name ] ) && is_array( self::WRITABLE_EXACT[ $name ] ) ) {
			$range = self::WRITABLE_EXACT[ $name ];
			$int   = is_int( $value ) ? $value : ( is_string( $value ) && 1 === preg_match( '/^\d{1,5}$/', $value ) ? (int) $value : null );
			if ( null === $int || $int < $range[0] || $int > $range[1] ) {
				return new WP_Error( 'invalid_value', sprintf( /* translators: 1: min 2: max */ __( 'Value must be an integer between %1$d and %2$d.', 'remotewp' ), $range[0], $range[1] ), array( 'status' => 400 ) );
			}
			return $int;
		}

		$budget = self::MAX_ENTRIES;
		return self::validate_tree( $value, 0, $budget );
	}

	/**
	 * Merge a validated value into the stored one.
	 *
	 * Arrays are merged by sub-key so a partial update of a serialized settings
	 * array cannot drop sub-keys that the agent is not allowed to see.
	 *
	 * @param mixed $current Stored value.
	 * @param mixed $new     Validated new value.
	 * @return mixed|WP_Error
	 */
	public static function merge( $current, $new ) {
		if ( is_object( $current ) ) {
			return new WP_Error( 'unsupported_type', __( 'This option holds an object and cannot be edited.', 'remotewp' ), array( 'status' => 409 ) );
		}
		if ( is_array( $current ) !== is_array( $new ) ) {
			return new WP_Error( 'type_mismatch', __( 'The new value must have the same shape (scalar or array) as the stored one.', 'remotewp' ), array( 'status' => 400 ) );
		}
		if ( is_array( $current ) ) {
			return array_replace_recursive( $current, $new );
		}
		return $new;
	}

	/**
	 * Mask credential-like entries before a value is returned to the agent.
	 *
	 * @param mixed $value Stored or proposed value.
	 * @return mixed
	 */
	public static function redact( $value ) {
		if ( is_object( $value ) ) {
			return '[object]';
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = array();
		foreach ( $value as $k => $v ) {
			$out[ $k ] = ( is_string( $k ) && self::is_denied( $k ) ) ? '[redacted]' : self::redact( $v );
		}
		return $out;
	}

	/**
	 * @param string $name Option name or array key.
	 * @return bool
	 */
	public static function is_denied( $name ) {
		$name = (string) $name;
		if ( 1 === preg_match( self::DENY_PATTERN, $name ) ) {
			return true;
		}
		foreach ( preg_split( '/[-_.\s]+/', strtolower( $name ) ) as $token ) {
			if ( in_array( $token, self::DENY_TOKENS, true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function validate_tree( $value, $depth, &$budget ) {
		if ( $depth > self::MAX_DEPTH ) {
			return new WP_Error( 'invalid_value', __( 'The value is nested too deeply.', 'remotewp' ), array( 'status' => 400 ) );
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > self::MAX_STRING || false !== strpos( $value, "\0" ) ) {
				return new WP_Error( 'invalid_value', __( 'The string value is too long or contains invalid characters.', 'remotewp' ), array( 'status' => 400 ) );
			}
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_value', __( 'Unsupported value type.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$out = array();
		foreach ( $value as $k => $v ) {
			if ( --$budget < 0 ) {
				return new WP_Error( 'invalid_value', __( 'The value has too many entries.', 'remotewp' ), array( 'status' => 400 ) );
			}
			if ( is_string( $k ) && ( self::is_denied( $k ) || 1 !== preg_match( '/^[A-Za-z0-9_.\-]{1,80}$/', $k ) ) ) {
				return new WP_Error( 'option_key_denied', __( 'The value contains a sub-key that may not be written (credential-like or invalid name).', 'remotewp' ), array( 'status' => 400 ) );
			}
			$checked = self::validate_tree( $v, $depth + 1, $budget );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
			$out[ $k ] = $checked;
		}
		return $out;
	}
}
