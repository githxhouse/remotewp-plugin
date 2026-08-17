<?php
/**
 * Central path policy for RemoteWP filesystem operations.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Path_Policy {

	public static function resolve( $path, $must_exist = true, $is_write = false ) {
		$base = realpath( ABSPATH );
		if ( ! $base || DIRECTORY_SEPARATOR === $base ) {
			return new WP_Error( 'invalid_base_path', 'Invalid WordPress base path.', array( 'status' => 500 ) );
		}

		if ( ! is_string( $path ) || '' === $path || preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
			return new WP_Error( 'invalid_path', 'Invalid path.', array( 'status' => 400 ) );
		}
		$has_windows_drive_prefix = strlen( $path ) >= 3 && ctype_alpha( $path[0] ) && ':' === $path[1] && in_array( $path[2], array( '/', '\\' ), true );
		if ( preg_match( '/%(?:2f|2F|5c|2e|2E)/', $path ) || $has_windows_drive_prefix ) {
			return new WP_Error( 'invalid_path', 'Encoded or absolute paths are not allowed.', array( 'status' => 400 ) );
		}

		$relative = ltrim( str_replace( '\\', '/', $path ), '/' );
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '..' === $segment ) {
				return new WP_Error( 'path_traversal', 'Parent path traversal is not allowed.', array( 'status' => 400 ) );
			}
		}
		if ( '' === $relative ) {
			return $is_write ? self::write_error( $base, $base ) : $base;
		}

		$input = $base . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		$exists = file_exists( $input ) || is_link( $input );
		if ( $exists ) {
			$resolved = realpath( $input );
			if ( false === $resolved || ! self::within( $resolved, $base ) ) {
				return new WP_Error( 'path_outside_root', 'Path resolves outside WordPress.', array( 'status' => 403 ) );
			}
			$symlink_check = self::check_symlinks( $base, $relative );
			if ( is_wp_error( $symlink_check ) ) {
				return $symlink_check;
			}
			$policy_check = self::protected_check( $resolved, $base );
			if ( is_wp_error( $policy_check ) ) {
				return $policy_check;
			}
			$restriction_check = self::restriction_check( $resolved, $base );
			if ( is_wp_error( $restriction_check ) ) {
				return $restriction_check;
			}
			return $is_write ? self::write_error( $resolved, $base ) : $resolved;
		}

		if ( $must_exist ) {
			return new WP_Error( 'not_found', 'Path does not exist.', array( 'status' => 404 ) );
		}
		$parent = realpath( dirname( $input ) );
		if ( false === $parent || ! self::within( $parent, $base ) ) {
			return new WP_Error( 'path_outside_root', 'Parent path resolves outside WordPress.', array( 'status' => 403 ) );
		}
		$parent_relative = ltrim( str_replace( '\\', '/', substr( $parent, strlen( $base ) ) ), '/' );
		$symlink_check = self::check_symlinks( $base, $parent_relative );
		if ( is_wp_error( $symlink_check ) ) {
			return $symlink_check;
		}
		$candidate = $parent . DIRECTORY_SEPARATOR . basename( $input );
		$policy_check = self::protected_check( $candidate, $base );
		if ( is_wp_error( $policy_check ) ) {
			return $policy_check;
		}
		$restriction_check = self::restriction_check( $candidate, $base );
		if ( is_wp_error( $restriction_check ) ) {
			return $restriction_check;
		}
		return $is_write ? self::write_error( $candidate, $base ) : $candidate;
	}

	public static function is_protected( $path ) {
		$base = realpath( ABSPATH );
		return ! $base || is_wp_error( self::protected_check( $path, $base ) );
	}

	private static function protected_check( $path, $base ) {
		$relative = ltrim( str_replace( '\\', '/', $path ), '/' );
		$base_normalized = rtrim( str_replace( '\\', '/', $base ), '/' );
		if ( 0 === strpos( $relative, $base_normalized . '/' ) ) {
			$relative = substr( $relative, strlen( $base_normalized ) + 1 );
		}
		foreach ( array_filter( explode( '/', $relative ), 'strlen' ) as $segment ) {
			if ( '.' === $segment[0] ) {
				return new WP_Error( 'protected_file', 'Hidden files and directories are protected.', array( 'status' => 403 ) );
			}
		}
		$basename = strtolower( basename( $relative ) );
		$protected = array( 'wp-config.php', 'wp-config-sample.php', '.env', '.env.local', '.env.production', '.env.staging', '.env.deploy', '.htaccess', '.htpasswd', '.user.ini', 'php.ini', 'web.config' );
		if ( in_array( $basename, $protected, true ) ) {
			return new WP_Error( 'protected_file', 'Protected configuration file.', array( 'status' => 403 ) );
		}
		$lower = strtolower( $relative );
		if ( 'wp-admin' === $lower || 0 === strpos( $lower, 'wp-admin/' ) || 'wp-includes' === $lower || 0 === strpos( $lower, 'wp-includes/' ) ) {
			return new WP_Error( 'core_modification_blocked', 'WordPress core directories are protected.', array( 'status' => 403 ) );
		}
		$plugin_dir = defined( 'REMOTEWP_PLUGIN_DIR' ) ? realpath( REMOTEWP_PLUGIN_DIR ) : false;
		if ( $plugin_dir && self::within( $path, $plugin_dir ) ) {
			return new WP_Error( 'self_protection', 'RemoteWP plugin files are protected.', array( 'status' => 403 ) );
		}
		if ( 0 === strpos( $lower, 'wp-content/remotewp-pro' ) ) {
			return new WP_Error( 'self_protection', 'RemoteWP Pro files are protected.', array( 'status' => 403 ) );
		}
		// Keep audit logs, operation state and verified backups outside the
		// filesystem API. The directory key is randomized during storage setup;
		// when it exists, protect both the directory itself and new descendants.
		$storage_key = function_exists( 'get_option' ) ? get_option( 'remotewp_storage_dir_key', '' ) : '';
		if ( ! empty( $storage_key ) && function_exists( 'wp_upload_dir' ) ) {
			$upload_dir  = wp_upload_dir();
			$storage_dir = ! empty( $upload_dir['basedir'] ) ? realpath( trailingslashit( $upload_dir['basedir'] ) . sanitize_file_name( $storage_key ) ) : false;
			if ( $storage_dir && self::within( $path, $storage_dir ) ) {
				return new WP_Error( 'self_protection', 'RemoteWP internal storage is protected.', array( 'status' => 403 ) );
			}
		}
		return true;
	}

	private static function restriction_check( $path, $base ) {
		$restrictions = get_option( 'remotewp_path_restrictions', '' );
		if ( is_array( $restrictions ) ) {
			$allowed_paths = isset( $restrictions['allowed_paths'] ) ? (array) $restrictions['allowed_paths'] : array();
		} else {
			$allowed_paths = array_filter( array_map( 'trim', explode( "\n", (string) $restrictions ) ) );
		}
		if ( empty( $allowed_paths ) ) {
			return true;
		}
		foreach ( $allowed_paths as $allowed ) {
			$allowed_path = realpath( $base . DIRECTORY_SEPARATOR . ltrim( str_replace( '\\', '/', $allowed ), '/' ) );
			if ( $allowed_path && self::within( $path, $allowed_path ) ) {
				return true;
			}
		}
		return new WP_Error( 'path_restricted', 'Path is outside the configured allowed paths.', array( 'status' => 403 ) );
	}

	private static function write_error( $path, $base ) {
		$path_normalized = strtolower( rtrim( str_replace( '\\', '/', $path ), '/' ) );
		$base_normalized = strtolower( rtrim( str_replace( '\\', '/', $base ), '/' ) );
		$wp_content_dir  = defined( 'WP_CONTENT_DIR' ) ? realpath( WP_CONTENT_DIR ) : false;
		if ( ! $wp_content_dir ) {
			$wp_content_dir = realpath( $base . DIRECTORY_SEPARATOR . 'wp-content' );
		}

		if ( $wp_content_dir ) {
			$wp_content_normalized = strtolower( rtrim( str_replace( '\\', '/', $wp_content_dir ), '/' ) );
			if ( $path_normalized === $wp_content_normalized || 0 === strpos( $path_normalized, $wp_content_normalized . '/' ) ) {
				$wp_content_relative = ltrim( substr( $path_normalized, strlen( $wp_content_normalized ) ), '/' );
				if ( 0 === strpos( $wp_content_relative, 'plugins/remotewp' ) || 0 === strpos( $wp_content_relative, 'remotewp-pro' ) ) {
					return new WP_Error( 'self_protection', 'RemoteWP files are protected.', array( 'status' => 403 ) );
				}
				return true;
			}
		}

		$relative = ltrim( $path_normalized, '/' );
		if ( 0 === strpos( $relative, $base_normalized . '/' ) ) {
			$relative = substr( $relative, strlen( $base_normalized ) + 1 );
		}

		if ( in_array( $relative, array( 'llms.txt', 'llms-full.txt' ), true ) ) {
			return true;
		}
		return new WP_Error( 'core_modification_blocked', 'Writes are limited to wp-content and approved root documentation files.', array( 'status' => 403 ) );
	}

	private static function check_symlinks( $base, $relative ) {
		$current = rtrim( $base, DIRECTORY_SEPARATOR );
		foreach ( array_filter( explode( '/', str_replace( '\\', '/', $relative ) ), 'strlen' ) as $segment ) {
			$current .= DIRECTORY_SEPARATOR . $segment;
			if ( is_link( $current ) ) {
				return new WP_Error( 'symlink_blocked', 'Symlink path segments are not allowed.', array( 'status' => 403 ) );
			}
		}
		return true;
	}

	private static function within( $path, $base ) {
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		$base = rtrim( str_replace( '\\', '/', $base ), '/' );
		return $path === $base || 0 === strpos( $path, $base . '/' );
	}
}
