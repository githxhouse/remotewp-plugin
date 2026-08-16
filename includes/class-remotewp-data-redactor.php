<?php
/**
 * Redacts credentials and secret values before they leave the site.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Data_Redactor {

	const VERSION = 2;

	/**
	 * Redact secrets from text while preserving the surrounding source format.
	 *
	 * @param string $value Text to redact.
	 * @return array{value:string,redacted:bool}
	 */
	public static function redact( $value ) {
		if ( ! is_string( $value ) || '' === $value || false !== strpos( $value, "\0" ) ) {
			return array( 'value' => $value, 'redacted' => false );
		}

		$redacted = $value;
		$patterns = array(
			'/-----BEGIN ([A-Z0-9 ]+ PRIVATE KEY)-----.*?-----END \1-----/s' => '-----BEGIN $1-----[REDACTED]-----END $1-----',
			'/\b(Bearer\s+)[A-Za-z0-9._~+\/-]{20,}=*/i' => '$1[REDACTED]',
			'/\b(?:sk|rk|pk)-[A-Za-z0-9_-]{16,}\b/i' => '[REDACTED_API_KEY]',
			'/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9_]{20,}\b/' => '[REDACTED_GITHUB_TOKEN]',
			'/\bAKIA[0-9A-Z]{16}\b/' => '[REDACTED_AWS_KEY]',
			'/([\'\"]?(?:api[_-]?key|secret(?:[_-]?key)?|access[_-]?token|auth[_-]?token|password|passwd|client[_-]?secret|private[_-]?key)[\'\"]?\s*[:=]\s*[\'\"]?)([^\'\"\s,;}\r\n]+)([\'\"]?)/i' => '$1[REDACTED]$3',
			'/\beyJ[A-Za-z0-9_-]{30,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/' => '[REDACTED_JWT]',
		);

		$extra_keys = self::extra_keys();
		if ( ! empty( $extra_keys ) ) {
			$quoted_keys = array_map( 'preg_quote', $extra_keys );
			$patterns[ '/([\'\"]?(?:' . implode( '|', $quoted_keys ) . ')[\'\"]?\s*[:=]\s*[\'\"]?)([^\'\"\s,;}\r\n]+)([\'\"]?)/i' ] = '$1[REDACTED]$3';
		}

		foreach ( $patterns as $pattern => $replacement ) {
			$redacted = preg_replace( $pattern, $replacement, $redacted );
		}

		return array(
			'value'    => $redacted,
			'redacted' => $redacted !== $value,
		);
	}

	/**
	 * Return the active redaction policy version.
	 *
	 * @return int
	 */
	public static function version() {
		return self::VERSION;
	}

	/**
	 * Read additional key names without allowing user-supplied regex.
	 *
	 * @return string[]
	 */
	private static function extra_keys() {
		$raw = function_exists( 'get_option' ) ? get_option( 'remotewp_redaction_extra_keys', '' ) : '';
		$raw = is_string( $raw ) ? $raw : '';
		$keys = preg_split( '/[\r\n,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$keys = array_map(
			function ( $key ) {
				$key = function_exists( 'sanitize_key' ) ? sanitize_key( $key ) : strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $key ) );
				return strlen( $key ) <= 64 ? $key : '';
			},
			$keys
		);
		return array_values( array_unique( array_filter( $keys ) ) );
	}

	/**
	 * Return only the redacted text value.
	 *
	 * @param mixed $value Value to redact.
	 * @return mixed
	 */
	public static function text( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$result = self::redact( $value );
		return $result['value'];
	}
}
