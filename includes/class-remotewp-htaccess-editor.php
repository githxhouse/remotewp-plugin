<?php
/**
 * Managed .htaccess block ("# BEGIN RemoteWP Performance").
 *
 * Pure string logic. The plugin only ever reads, returns or replaces the text
 * between its own markers; the remainder of the file is carried through
 * byte-for-byte and is never exposed to the agent.
 *
 * Every directive is validated against a strict grammar (allowlist, not a
 * denylist) and must sit inside an <IfModule> for the module it belongs to,
 * so a host without that module ignores it instead of answering 500.
 *
 * @package RemoteWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Htaccess_Editor {

	const MARKER_NAME = 'RemoteWP Performance';
	const MAX_LINES   = 120;
	const MAX_LENGTH  = 300;

	/**
	 * Directives that are rejected with an explicit reason.
	 *
	 * @var string[]
	 */
	const FORBIDDEN = array(
		'rewriterule', 'rewritecond', 'rewriteengine', 'rewritebase', 'rewritemap', 'redirect', 'redirectmatch',
		'redirectpermanent', 'redirecttemp', 'order', 'allow', 'deny', 'require', 'satisfy', 'addhandler',
		'addtype', 'addoutputfilter', 'php_value', 'php_flag', 'php_admin_value', 'php_admin_flag', 'sethandler',
		'options', 'authtype', 'authuserfile', 'errordocument', 'include', 'includeoptional', 'action',
		'setenv', 'setenvif', 'directoryindex', 'files', 'directory', 'location',
	);

	const MODULES = array(
		'mod_deflate.c' => 'deflate',
		'mod_gzip.c'    => 'gzip',
		'mod_expires.c' => 'expires',
		'mod_headers.c' => 'headers',
	);

	private static function begin() {
		return '# BEGIN ' . self::MARKER_NAME;
	}

	private static function end() {
		return '# END ' . self::MARKER_NAME;
	}

	/**
	 * Validate the agent supplied directives.
	 *
	 * @param mixed $directives Array of single-line strings.
	 * @return string[]|WP_Error Normalised lines (without indentation).
	 */
	public static function validate( $directives ) {
		if ( ! is_array( $directives ) || empty( $directives ) ) {
			return self::error( 'directives_required', __( 'directives must be a non-empty array of strings. Use DELETE to remove the block.', 'remotewp' ) );
		}
		if ( count( $directives ) > self::MAX_LINES ) {
			return self::error( 'too_many_directives', __( 'Too many directives.', 'remotewp' ) );
		}

		$clean = array();
		$stack = array(); // Entries: array( kind, module ).
		foreach ( array_values( $directives ) as $index => $raw ) {
			if ( ! is_string( $raw ) ) {
				return self::line_error( $index, 'directive_invalid', __( 'each directive must be a string', 'remotewp' ) );
			}
			if ( strlen( $raw ) > self::MAX_LENGTH || 1 === preg_match( '/[^\x20-\x7E\t]/', $raw ) ) {
				return self::line_error( $index, 'directive_invalid', __( 'only single-line printable ASCII is allowed', 'remotewp' ) );
			}
			$line = trim( $raw );
			if ( '' === $line ) {
				continue;
			}
			if ( '\\' === substr( $line, -1 ) ) {
				return self::line_error( $index, 'directive_invalid', __( 'line continuation is not allowed', 'remotewp' ) );
			}
			if ( false !== stripos( $line, self::MARKER_NAME ) || '#' === $line[0] ) {
				return self::line_error( $index, 'directive_not_allowed', __( 'comments and marker lines are not allowed', 'remotewp' ) );
			}

			$first = strtolower( strtok( $line, " \t>" ) );
			$first = ltrim( $first, '<' );
			if ( in_array( $first, self::FORBIDDEN, true ) ) {
				return self::line_error( $index, 'directive_forbidden', __( 'this directive is explicitly forbidden', 'remotewp' ) );
			}

			$result = self::check_line( $line, $stack );
			if ( true !== $result ) {
				return self::line_error( $index, 'directive_not_allowed', $result );
			}
			$clean[] = $line;
		}

		if ( ! empty( $stack ) ) {
			return self::error( 'directive_unbalanced', __( 'Unclosed container in directives.', 'remotewp' ) );
		}
		if ( empty( $clean ) ) {
			return self::error( 'directives_required', __( 'directives must contain at least one directive.', 'remotewp' ) );
		}
		return $clean;
	}

	/**
	 * Return the text between the markers, or null when there is no block.
	 *
	 * @param string $contents Whole .htaccess contents (never returned).
	 * @return string|null|WP_Error
	 */
	public static function extract_block( $contents ) {
		$located = self::locate( $contents );
		if ( is_wp_error( $located ) ) {
			return $located;
		}
		return null === $located ? null : $located['inner'];
	}

	/**
	 * Replace or append the managed block.
	 *
	 * @param string   $contents Current file contents.
	 * @param string[] $lines    Validated directives.
	 * @return string|WP_Error
	 */
	public static function set_block( $contents, array $lines ) {
		$located = self::locate( $contents );
		if ( is_wp_error( $located ) ) {
			return $located;
		}

		$eol   = self::eol( $contents );
		$block = self::begin() . $eol . self::indent_lines( $lines, $eol ) . self::end() . $eol;

		if ( null !== $located ) {
			return substr( $contents, 0, $located['start'] ) . $block . substr( $contents, $located['end'] );
		}

		$prefix = ( '' === $contents || self::ends_with_eol( $contents ) ) ? '' : $eol;
		return $contents . $prefix . $block;
	}

	/**
	 * Remove the managed block; everything else stays byte-identical.
	 *
	 * @param string $contents Current file contents.
	 * @return string|null|WP_Error Null when there is no block to remove.
	 */
	public static function remove_block( $contents ) {
		$located = self::locate( $contents );
		if ( is_wp_error( $located ) ) {
			return $located;
		}
		if ( null === $located ) {
			return null;
		}
		return substr( $contents, 0, $located['start'] ) . substr( $contents, $located['end'] );
	}

	/**
	 * Post-write verification: the block is what we intended and the rest of the
	 * file is unchanged.
	 *
	 * @param string      $original        Contents before the write.
	 * @param string      $updated         Contents after the write.
	 * @param string|null $expected_inner  Expected block text, or null when it should be gone.
	 * @return true|WP_Error
	 */
	public static function verify( $original, $updated, $expected_inner ) {
		$inner = self::extract_block( $updated );
		if ( is_wp_error( $inner ) ) {
			return $inner;
		}
		if ( $inner !== $expected_inner ) {
			return self::error( 'htaccess_block_mismatch', __( 'The managed block could not be confirmed.', 'remotewp' ), 500 );
		}
		$rest_before = self::without_block( $original );
		$rest_after  = self::without_block( $updated );
		if ( $rest_before !== $rest_after ) {
			return self::error( 'htaccess_collateral_change', __( 'The write changed content outside the managed block.', 'remotewp' ), 500 );
		}
		return true;
	}

	/**
	 * Block text normalised for comparison with extract_block().
	 *
	 * @param string[] $lines Validated lines.
	 * @param string   $eol   Line ending in use.
	 * @return string
	 */
	public static function inner_text( array $lines, $eol ) {
		return self::indent_lines( $lines, $eol );
	}

	/**
	 * Contents with the managed block removed (no validation errors surfaced).
	 */
	private static function without_block( $contents ) {
		$removed = self::remove_block( $contents );
		return ( null === $removed || is_wp_error( $removed ) ) ? $contents : $removed;
	}

	private static function locate( $contents ) {
		$begin = preg_match_all( '/^' . preg_quote( self::begin(), '/' ) . '[ \t]*\r?$/m', $contents, $b, PREG_OFFSET_CAPTURE );
		$end   = preg_match_all( '/^' . preg_quote( self::end(), '/' ) . '[ \t]*\r?$/m', $contents, $e, PREG_OFFSET_CAPTURE );

		if ( 0 === $begin && 0 === $end ) {
			return null;
		}
		if ( 1 !== $begin || 1 !== $end || $e[0][0][1] < $b[0][0][1] ) {
			return self::error( 'htaccess_markers_corrupt', __( 'The managed block markers are inconsistent. Fix them manually.', 'remotewp' ), 409 );
		}

		$start       = $b[0][0][1];
		$inner_start = $start + strlen( $b[0][0][0] );
		$inner_start = self::skip_eol( $contents, $inner_start );
		$end_start   = $e[0][0][1];
		$end_after   = self::skip_eol( $contents, $end_start + strlen( $e[0][0][0] ) );

		return array(
			'start' => $start,
			'end'   => $end_after,
			'inner' => substr( $contents, $inner_start, $end_start - $inner_start ),
		);
	}

	private static function skip_eol( $contents, $offset ) {
		if ( "\r\n" === substr( $contents, $offset, 2 ) ) {
			return $offset + 2;
		}
		if ( "\n" === substr( $contents, $offset, 1 ) ) {
			return $offset + 1;
		}
		return $offset;
	}

	private static function eol( $contents ) {
		return false !== strpos( $contents, "\r\n" ) ? "\r\n" : "\n";
	}

	private static function ends_with_eol( $contents ) {
		return "\n" === substr( $contents, -1 );
	}

	private static function indent_lines( array $lines, $eol ) {
		$out   = '';
		$depth = 0;
		foreach ( $lines as $line ) {
			if ( 0 === strpos( $line, '</' ) ) {
				$depth = max( 0, $depth - 1 );
			}
			$out .= str_repeat( '  ', $depth ) . $line . $eol;
			if ( 1 === preg_match( '/^<(IfModule|FilesMatch)\b/', $line ) ) {
				++$depth;
			}
		}
		return $out;
	}

	/**
	 * Check one line against the grammar, updating the container stack.
	 *
	 * @return true|string True when valid, otherwise a reason.
	 */
	private static function check_line( $line, array &$stack ) {
		// Containers.
		if ( 1 === preg_match( '/^<IfModule (mod_[a-z]+\.c)>$/', $line, $m ) ) {
			if ( ! isset( self::MODULES[ $m[1] ] ) || count( $stack ) >= 3 ) {
				return __( 'IfModule is only allowed for mod_deflate, mod_gzip, mod_expires and mod_headers', 'remotewp' );
			}
			$stack[] = array( 'ifmodule', self::MODULES[ $m[1] ] );
			return true;
		}
		if ( 1 === preg_match( '/^<FilesMatch "\\\\\.\(([a-z0-9]{1,8}(?:\|[a-z0-9]{1,8}){0,30})\)\$">$/', $line ) ) {
			if ( empty( $stack ) || count( $stack ) >= 3 ) {
				return __( 'FilesMatch must be nested inside an IfModule', 'remotewp' );
			}
			$stack[] = array( 'filesmatch', self::active_module( $stack ) );
			return true;
		}
		if ( '</IfModule>' === $line || '</FilesMatch>' === $line ) {
			$kind = '</IfModule>' === $line ? 'ifmodule' : 'filesmatch';
			$top  = array_pop( $stack );
			if ( null === $top || $top[0] !== $kind ) {
				return __( 'unbalanced closing tag', 'remotewp' );
			}
			return true;
		}

		$module = self::active_module( $stack );
		if ( null === $module ) {
			return __( 'directives must be inside an IfModule for their module', 'remotewp' );
		}

		$mime = '[a-z]+\/[a-z0-9.+-]+';
		$pat  = '[A-Za-z0-9_.\\\\*+?|()$^\/-]{1,120}';

		switch ( $module ) {
			case 'deflate':
				if ( 1 === preg_match( '/^AddOutputFilterByType DEFLATE ' . $mime . '(?: ' . $mime . '){0,40}$/', $line ) ) {
					return true;
				}
				return __( 'only "AddOutputFilterByType DEFLATE <mime types>" is allowed in mod_deflate', 'remotewp' );

			case 'gzip':
				if ( 1 === preg_match( '/^mod_gzip_(?:on (?:Yes|No)|dechunk (?:Yes|No)|item_(?:include|exclude) (?:file|mime|handler|reqheader|rspheader) ' . $pat . ')$/', $line ) ) {
					return true;
				}
				return __( 'unsupported mod_gzip directive', 'remotewp' );

			case 'expires':
				if ( 1 === preg_match( '/^ExpiresActive (?:On|Off)$/', $line ) ) {
					return true;
				}
				if ( 1 === preg_match( '/^ExpiresByType ' . $mime . ' "access plus [1-9][0-9]{0,3} (?:seconds?|minutes?|hours?|days?|weeks?|months?|years?)"$/', $line ) ) {
					return true;
				}
				return __( 'only ExpiresActive and ExpiresByType "access plus N unit" are allowed in mod_expires', 'remotewp' );

			case 'headers':
				if ( 1 === preg_match( '/^Header set Cache-Control "([^"]+)"$/', $line, $m ) ) {
					return self::valid_cache_control( $m[1] ) ? true : __( 'invalid Cache-Control value', 'remotewp' );
				}
				if ( 1 === preg_match( '/^Header (?:append|merge|set) Vary "?(Accept-Encoding|Accept|Origin)"?$/', $line ) ) {
					return true;
				}
				return __( 'only "Header set Cache-Control" and Vary headers are allowed in mod_headers', 'remotewp' );
		}
		return __( 'unsupported directive', 'remotewp' );
	}

	private static function active_module( array $stack ) {
		for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
			if ( 'ifmodule' === $stack[ $i ][0] ) {
				return $stack[ $i ][1];
			}
		}
		return null;
	}

	private static function valid_cache_control( $value ) {
		$parts = array_map( 'trim', explode( ',', $value ) );
		foreach ( $parts as $part ) {
			if ( 1 !== preg_match( '/^(?:public|private|no-cache|no-store|no-transform|must-revalidate|proxy-revalidate|immutable|(?:max-age|s-maxage|stale-while-revalidate|stale-if-error)=\d{1,10})$/', $part ) ) {
				return false;
			}
		}
		return true;
	}

	private static function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private static function line_error( $index, $code, $reason ) {
		return new WP_Error(
			$code,
			sprintf(
				/* translators: 1: 1-based directive position, 2: reason */
				__( 'Directive #%1$d rejected: %2$s.', 'remotewp' ),
				$index + 1,
				$reason
			),
			array( 'status' => 400 )
		);
	}
}
