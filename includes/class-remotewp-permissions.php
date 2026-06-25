<?php
/**
 * RemoteWP Permissions
 *
 * Handles granular permission control for API operations.
 * Supports three profiles: read-only, read-write, full.
 * Also supports path restrictions to limit access to specific directories.
 *
 * @package RemoteWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Permissions {

	/**
	 * Permission profiles and their allowed operations.
	 *
	 * @var array
	 */
	private $profiles = array(
		'read-only'  => array( 'list', 'read', 'status', 'search', 'wp_info', 'wp_plugins', 'wp_options', 'instructions' ),
		'read-write' => array( 'list', 'read', 'write', 'mkdir', 'status', 'search', 'wp_info', 'wp_plugins', 'wp_options', 'wp_cache_clear', 'instructions' ),
		'full'       => array( 'list', 'read', 'write', 'delete', 'rename', 'mkdir', 'restore', 'status', 'search', 'wp_info', 'wp_plugins', 'wp_plugin_toggle', 'wp_options', 'wp_cache_clear', 'instructions' ),
	);

	/**
	 * Protected files that cannot be read or modified via API.
	 *
	 * @var array
	 */
	private $protected_files = array(
		'wp-config.php',
		'wp-config-sample.php',
		'.env',
		'.env.local',
		'.env.production',
		'.env.staging',
		'.env.deploy',
		'.htaccess',
		'.htpasswd',
		'.user.ini',
		'php.ini',
		'web.config',
	);

	/**
	 * Check if a specific operation is allowed.
	 *
	 * @param string $operation The operation to check (read, write, delete, etc.).
	 * @return true|WP_Error True if allowed, WP_Error if denied.
	 */
	public function can( $operation ) {
		$level   = get_option( 'remotewp_permission_level', 'full' );
		$allowed = $this->profiles[ $level ] ?? $this->profiles['read-only'];

		if ( ! in_array( $operation, $allowed, true ) ) {
			return new WP_Error(
				'permission_denied',
				sprintf(
					/* translators: 1: operation name, 2: permission level */
					__( 'Operation "%1$s" is not allowed under the "%2$s" permission profile.', 'remotewp' ),
					$operation,
					$level
				),
				array( 'status' => 403 )
			);
		}

		// Enforce license verification for Pro operations
		$pro_operations = array(
			'write',
			'delete',
			'rename',
			'mkdir',
			'restore',
			'search',
			'wp_plugins',
			'wp_plugin_toggle',
			'wp_options',
			'wp_cache_clear',
		);
		if ( in_array( $operation, $pro_operations, true ) ) {
			$license = new RemoteWP_License();
			if ( 'free' === $license->get_tier() ) {
				return new WP_Error(
					'license_required',
					__( 'A valid Pro license is required to perform this action.', 'remotewp' ),
					array( 'status' => 402 )
				);
			}
		}

		return true;
	}

	/**
	 * Sanitize and validate a filesystem path.
	 *
	 * Ensures the path stays within ABSPATH and is not a protected file.
	 *
	 * @param string $path       The relative path to validate.
	 * @param bool   $must_exist Whether the path must exist.
	 * @param bool   $is_write   Whether this is a write/modify operation.
	 * @return string|WP_Error Absolute real path on success, WP_Error on failure.
	 */
	public function sanitize_path( $path, $must_exist = true, $is_write = false ) {
		$real_base = realpath( ABSPATH );

		if ( empty( $path ) || '/' === $path ) {
			return $real_base;
		}

		// Normalize path separators
		$path = str_replace( '\\', '/', $path );
		// Remove leading slash
		$path = ltrim( $path, '/' );

		$full_path = ABSPATH . $path;
		$real_path = realpath( $full_path );

		if ( false === $real_path ) {
			if ( ! $must_exist ) {
				// For new files, check parent directory
				$parent_dir  = dirname( $full_path );
				$real_parent = realpath( $parent_dir );

				if ( false !== $real_parent && ( $real_parent === $real_base || 0 === strpos( $real_parent, $real_base . DIRECTORY_SEPARATOR ) ) ) {
					$constructed = $real_parent . DIRECTORY_SEPARATOR . basename( $path );

					if ( $this->is_protected_file( $constructed ) ) {
						return new WP_Error(
							'protected_file',
							__( 'This file is protected and cannot be accessed via the API.', 'remotewp' ),
							array( 'status' => 403 )
						);
					}

					// Check path restrictions
					$path_check = $this->check_path_restrictions( $constructed, $real_base );
					if ( is_wp_error( $path_check ) ) {
						return $path_check;
					}

					// Restrict write/modify operations to wp-content
					if ( $is_write ) {
						$write_check = $this->check_write_restrictions( $constructed );
						if ( is_wp_error( $write_check ) ) {
							return $write_check;
						}
					}

					return $constructed;
				}

				return new WP_Error(
					'invalid_path',
					__( 'Invalid path or parent directory does not exist.', 'remotewp' ),
					array( 'status' => 400 )
				);
			}

			return new WP_Error(
				'not_found',
				__( 'Path does not exist.', 'remotewp' ),
				array( 'status' => 404 )
			);
		}

		// Security: ensure path is within ABSPATH (prevent directory traversal and sibling escape)
		if ( $real_path !== $real_base && 0 !== strpos( $real_path, $real_base . DIRECTORY_SEPARATOR ) ) {
			return new WP_Error(
				'path_traversal',
				__( 'Access denied. Path is outside the allowed directory.', 'remotewp' ),
				array( 'status' => 403 )
			);
		}

		// Check protected files
		if ( $this->is_protected_file( $real_path ) ) {
			return new WP_Error(
				'protected_file',
				__( 'This file is protected and cannot be accessed via the API.', 'remotewp' ),
				array( 'status' => 403 )
			);
		}

		// Check path restrictions
		$path_check = $this->check_path_restrictions( $real_path, $real_base );
		if ( is_wp_error( $path_check ) ) {
			return $path_check;
		}

		// Restrict write/modify operations to wp-content
		if ( $is_write ) {
			$write_check = $this->check_write_restrictions( $real_path );
			if ( is_wp_error( $write_check ) ) {
				return $write_check;
			}
		}

		return $real_path;
	}

	/**
	 * Check if a file is in the protected list.
	 *
	 * @param string $path File path to check.
	 * @return bool
	 */
	public function is_protected_file( $path ) {
		// Normalize path separators
		$normalized_path = str_replace( '\\', '/', $path );
		$real_base       = realpath( ABSPATH );

		// Extract relative path from ABSPATH
		$relative_path = ltrim( str_replace( $real_base, '', $normalized_path ), '/' );
		$segments      = explode( '/', $relative_path );

		// Security: recursively block access to any hidden directories/files (e.g. .git/, .github/, .env, .htaccess)
		foreach ( $segments as $segment ) {
			if ( 0 === strpos( $segment, '.' ) && '.' !== $segment && '..' !== $segment ) {
				return true;
			}
		}

		$basename = strtolower( basename( $path ) );
		return in_array( $basename, $this->protected_files, true );
	}

	/**
	 * Check if a path satisfies configured path restrictions.
	 *
	 * @param string $real_path The absolute path to check.
	 * @param string $real_base The ABSPATH.
	 * @return true|WP_Error True if allowed, WP_Error if restricted.
	 */
	private function check_path_restrictions( $real_path, $real_base ) {
		$restrictions = get_option( 'remotewp_path_restrictions', '' );

		if ( empty( $restrictions ) ) {
			return true; // No restrictions, allow all
		}

		$allowed_paths = array_filter( array_map( 'trim', explode( "\n", $restrictions ) ) );

		if ( empty( $allowed_paths ) ) {
			return true;
		}

		foreach ( $allowed_paths as $allowed ) {
			$allowed_real = realpath( $real_base . '/' . ltrim( $allowed, '/' ) );
			if ( false === $allowed_real ) {
				continue;
			}
			// Exact match (file or dir itself) OR path is inside the allowed directory
			// Append DIRECTORY_SEPARATOR to prevent 'uploads' matching 'uploads2'
			if ( $real_path === $allowed_real || 0 === strpos( $real_path, $allowed_real . DIRECTORY_SEPARATOR ) ) {
				return true;
			}
		}

		return new WP_Error(
			'path_restricted',
			__( 'Access denied. This path is not in the allowed directories list.', 'remotewp' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Verify that write operations are restricted strictly to wp-content.
	 *
	 * @param string $real_path Absolute real path.
	 * @return true|WP_Error
	 */
	private function check_write_restrictions( $real_path ) {
		$wp_content_dir = defined( 'WP_CONTENT_DIR' ) ? realpath( WP_CONTENT_DIR ) : false;
		if ( ! $wp_content_dir ) {
			$wp_content_dir = realpath( ABSPATH . 'wp-content' );
		}
		if ( $wp_content_dir ) {
			$real_path_normalized      = str_replace( '\\', '/', $real_path );
			$wp_content_dir_normalized = str_replace( '\\', '/', $wp_content_dir );

			if ( $real_path_normalized !== $wp_content_dir_normalized && 0 !== strpos( $real_path_normalized, $wp_content_dir_normalized . '/' ) ) {
				return new WP_Error(
					'core_modification_blocked',
					__( 'Access denied. Write operations are restricted strictly to the wp-content directory to protect WordPress core files.', 'remotewp' ),
					array( 'status' => 403 )
				);
			}
		}
		return true;
	}

	/**
	 * Get available permission profiles.
	 *
	 * @return array
	 */
	public function get_profiles() {
		return array(
			'read-only'  => __( 'Read Only — List and read files, view site info', 'remotewp' ),
			'read-write' => __( 'Read & Write — All read operations plus write and create', 'remotewp' ),
			'full'       => __( 'Full Access — All operations including delete, rename, and plugin management', 'remotewp' ),
		);
	}
}
