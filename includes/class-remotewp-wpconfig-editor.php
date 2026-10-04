<?php
/**
 * Surgical, allowlist-only editor for wp-config.php constants.
 *
 * The agent never sees file contents. This class works on a string held in
 * memory by the plugin and only ever extracts or replaces the value of one
 * explicitly allowlisted constant. Any other constant (DB_*, *_KEY, *_SALT,
 * $table_prefix, ...) is never looked up, never parsed into a result and never
 * mentioned in an error message.
 *
 * @package RemoteWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Wpconfig_Editor {

	/**
	 * Strict allowlist: constant name => value type.
	 *
	 * @var array
	 */
	const ALLOWED = array(
		'WP_MEMORY_LIMIT'     => 'memory',
		'WP_MAX_MEMORY_LIMIT' => 'memory',
		'WP_CACHE'            => 'bool',
		'WP_POST_REVISIONS'   => 'revisions',
		'EMPTY_TRASH_DAYS'    => 'int',
		'AUTOSAVE_INTERVAL'   => 'int',
		'WP_DEBUG'            => 'bool',
		'WP_DEBUG_LOG'        => 'bool',
		'WP_DEBUG_DISPLAY'    => 'bool',
		'CONCATENATE_SCRIPTS' => 'bool',
		'COMPRESS_CSS'        => 'bool',
		'COMPRESS_SCRIPTS'    => 'bool',
		'DISALLOW_FILE_EDIT'  => 'bool',
	);

	/**
	 * @param string $name Constant name.
	 * @return bool
	 */
	public static function is_allowed( $name ) {
		return is_string( $name ) && isset( self::ALLOWED[ $name ] );
	}

	/**
	 * Resolve wp-config.php using the same rule WordPress uses on boot.
	 *
	 * @return string|false Absolute path or false. Never returned to agents.
	 */
	public static function locate() {
		$root = rtrim( ABSPATH, '/\\' );
		if ( is_file( $root . '/wp-config.php' ) ) {
			return $root . '/wp-config.php';
		}
		$parent = dirname( $root );
		if ( is_file( $parent . '/wp-config.php' ) && ! is_file( $parent . '/wp-settings.php' ) ) {
			return $parent . '/wp-config.php';
		}
		return false;
	}

	/**
	 * Validate and normalise a user supplied value for an allowlisted constant.
	 *
	 * Nothing is written when this fails.
	 *
	 * @param string $name Constant name.
	 * @param mixed  $raw  Raw value from the request.
	 * @return array|WP_Error array( 'php' => literal source, 'value' => typed value )
	 */
	public static function normalize_value( $name, $raw ) {
		if ( ! self::is_allowed( $name ) ) {
			return new WP_Error( 'constant_not_allowed', __( 'This constant is not on the allowlist.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$type = self::ALLOWED[ $name ];
		$bool = self::as_bool( $raw );
		$int  = self::as_int( $raw );

		if ( 'bool' === $type && null !== $bool ) {
			return array( 'php' => $bool ? 'true' : 'false', 'value' => $bool );
		}
		if ( 'int' === $type && null !== $int ) {
			return array( 'php' => (string) $int, 'value' => $int );
		}
		if ( 'revisions' === $type ) {
			if ( null !== $int ) {
				return array( 'php' => (string) $int, 'value' => $int );
			}
			if ( null !== $bool ) {
				return array( 'php' => $bool ? 'true' : 'false', 'value' => $bool );
			}
		}
		if ( 'memory' === $type && is_string( $raw ) && 1 === preg_match( '/^\d{1,6}[MG]$/', $raw ) ) {
			return array( 'php' => "'" . $raw . "'", 'value' => $raw );
		}

		$expect = array(
			'bool'      => 'true or false',
			'int'       => 'a non-negative integer (digits only)',
			'revisions' => 'a non-negative integer, true or false',
			'memory'    => 'a size like 256M or 1G',
		);
		return new WP_Error(
			'invalid_value',
			sprintf(
				/* translators: 1: constant name, 2: expected format */
				__( 'Invalid value for %1$s: expected %2$s.', 'remotewp' ),
				$name,
				$expect[ $type ]
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Inspect the state of one allowlisted constant inside the file contents.
	 *
	 * @param string $contents wp-config.php contents (kept in memory only).
	 * @param string $name     Allowlisted constant name.
	 * @return array|WP_Error array( 'state' => absent|literal|non_literal, 'value' => mixed )
	 */
	public static function inspect( $contents, $name ) {
		if ( ! self::is_allowed( $name ) ) {
			return new WP_Error( 'constant_not_allowed', __( 'This constant is not on the allowlist.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$code_refs = self::count_code_references( $contents, $name );
		if ( null === $code_refs ) {
			return new WP_Error( 'config_unparseable', __( 'The configuration file could not be parsed safely.', 'remotewp' ), array( 'status' => 409 ) );
		}

		$matches = self::match_lines( $contents, $name );

		if ( 0 === $code_refs && 0 === count( $matches ) ) {
			return array( 'state' => 'absent', 'value' => null );
		}

		// Exactly one plain, single-line define() and nothing else referring to the name.
		if ( 1 !== $code_refs || 1 !== count( $matches ) ) {
			return new WP_Error(
				'unsupported_definition',
				sprintf(
					/* translators: %s: constant name */
					__( '%s is defined in a form that cannot be edited safely (conditional, duplicated or multi-line). Edit it manually.', 'remotewp' ),
					$name
				),
				array( 'status' => 409 )
			);
		}

		$parsed = self::parse_literal( $matches[0][4] );
		if ( null === $parsed ) {
			return array( 'state' => 'non_literal', 'value' => null );
		}
		return array( 'state' => 'literal', 'value' => $parsed[0] );
	}

	/**
	 * Set one allowlisted constant and return the new contents.
	 *
	 * @param string $contents    Current contents.
	 * @param string $name        Allowlisted constant.
	 * @param string $php_literal Already normalised PHP literal (see normalize_value()).
	 * @return string|WP_Error
	 */
	public static function apply( $contents, $name, $php_literal ) {
		$state = self::inspect( $contents, $name );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		if ( 'non_literal' === $state['state'] ) {
			return new WP_Error(
				'non_literal_definition',
				sprintf(
					/* translators: %s: constant name */
					__( '%s is defined by an expression, not a plain value. Edit it manually.', 'remotewp' ),
					$name
				),
				array( 'status' => 409 )
			);
		}

		if ( 'literal' === $state['state'] ) {
			$updated = preg_replace_callback(
				self::line_pattern( $name ),
				static function ( $m ) use ( $name, $php_literal ) {
					return $m[1] . $m[2] . $name . $m[2] . $m[3] . $php_literal . $m[5] . $m[6];
				},
				$contents,
				1
			);
			return null === $updated ? new WP_Error( 'config_edit_failed', __( 'The configuration edit failed.', 'remotewp' ), array( 'status' => 500 ) ) : $updated;
		}

		$eol    = false !== strpos( $contents, "\r\n" ) ? "\r\n" : "\n";
		$insert = "define( '" . $name . "', " . $php_literal . ' );' . $eol;

		$anchors = array(
			'/^[ \t]*\/\*\s*That\'s all, stop editing!/m',
			'/^[ \t]*require_once\s+ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]/m',
		);
		foreach ( $anchors as $anchor ) {
			if ( 1 === preg_match( $anchor, $contents, $m, PREG_OFFSET_CAPTURE ) ) {
				return substr( $contents, 0, $m[0][1] ) . $insert . substr( $contents, $m[0][1] );
			}
		}

		return new WP_Error(
			'insert_anchor_missing',
			__( 'The insertion point in the configuration file was not found.', 'remotewp' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Verify an edited file: it must parse as PHP, differ from the original only
	 * on the target line, and express the expected value.
	 *
	 * @param string $original Contents before the edit.
	 * @param string $updated  Contents after the edit.
	 * @param string $name     Target constant.
	 * @param mixed  $expected Expected typed value.
	 * @return true|WP_Error
	 */
	public static function verify( $original, $updated, $name, $expected ) {
		if ( ! self::parses_as_php( $updated ) ) {
			return new WP_Error( 'config_invalid_php', __( 'The edited configuration is not valid PHP.', 'remotewp' ), array( 'status' => 500 ) );
		}
		if ( self::strip_target( $original, $name ) !== self::strip_target( $updated, $name ) ) {
			return new WP_Error( 'config_collateral_change', __( 'The edit changed more than the target constant.', 'remotewp' ), array( 'status' => 500 ) );
		}
		$state = self::inspect( $updated, $name );
		if ( is_wp_error( $state ) || 'literal' !== $state['state'] || $state['value'] !== $expected ) {
			return new WP_Error( 'config_value_mismatch', __( 'The edited value could not be confirmed.', 'remotewp' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * @param string $contents PHP source.
	 * @return bool
	 */
	public static function parses_as_php( $contents ) {
		try {
			token_get_all( $contents, TOKEN_PARSE );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Remove every line defining the target so two versions can be compared.
	 *
	 * @param string $contents Contents.
	 * @param string $name     Constant name.
	 * @return string
	 */
	public static function strip_target( $contents, $name ) {
		$pattern = '/^' . substr( self::line_pattern( $name ), 2, -2 ) . '(?:\r?\n|\z)/m';
		return (string) preg_replace( $pattern, '', $contents );
	}

	/**
	 * Parse a PHP scalar literal.
	 *
	 * @param string $source Source text of the value.
	 * @return array|null array( value ) or null when it is not a plain literal.
	 */
	public static function parse_literal( $source ) {
		$source = trim( $source );
		if ( 1 === preg_match( '/^true$/i', $source ) ) {
			return array( true );
		}
		if ( 1 === preg_match( '/^false$/i', $source ) ) {
			return array( false );
		}
		if ( 1 === preg_match( '/^\d{1,9}$/', $source ) ) {
			return array( (int) $source );
		}
		if ( 1 === preg_match( '/^([\'"])([^\'"\\\\$]*)\1$/', $source, $m ) ) {
			return array( $m[2] );
		}
		return null;
	}

	/**
	 * Mask any value that is not a plain bool, int or memory-size string, so a
	 * value of an unexpected shape can never leak through a response.
	 *
	 * @param mixed $value Value read from the file or runtime.
	 * @return mixed
	 */
	public static function safe_output( $value ) {
		if ( null === $value || is_bool( $value ) || is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d{1,6}[MG]$/', $value ) ) {
			return $value;
		}
		return '[non-standard value]';
	}

	/**
	 * @param string $name Constant name.
	 * @return string Regex matching one single-line define() of the constant.
	 */
	private static function line_pattern( $name ) {
		return '/^([ \t]*define\s*\(\s*)([\'"])' . preg_quote( $name, '/' ) . '\2(\s*,\s*)(.*?)(\s*\)\s*;)([ \t]*(?:(?:\/\/|#)[^\r\n]*|\/\*[^\r\n]*?\*\/[ \t]*)?)(?=\r?\n|\r?\z)/m';
	}

	private static function match_lines( $contents, $name ) {
		$found = array();
		if ( preg_match_all( self::line_pattern( $name ), $contents, $all, PREG_SET_ORDER ) ) {
			$found = $all;
		}
		return $found;
	}

	/**
	 * Count code (not comment) string tokens equal to the constant name.
	 *
	 * @return int|null Null when the file cannot be tokenised.
	 */
	private static function count_code_references( $contents, $name ) {
		try {
			$tokens = token_get_all( $contents );
		} catch ( \Throwable $e ) {
			return null;
		}
		$count = 0;
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] && trim( $token[1], '\'"' ) === $name ) {
				++$count;
			}
		}
		return $count;
	}

	private static function as_bool( $raw ) {
		if ( is_bool( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) ) {
			$l = strtolower( $raw );
			if ( 'true' === $l ) {
				return true;
			}
			if ( 'false' === $l ) {
				return false;
			}
		}
		return null;
	}

	private static function as_int( $raw ) {
		if ( is_int( $raw ) && $raw >= 0 && $raw <= 999999999 ) {
			return $raw;
		}
		if ( is_string( $raw ) && 1 === preg_match( '/^\d{1,9}$/', $raw ) ) {
			return (int) $raw;
		}
		return null;
	}
}
