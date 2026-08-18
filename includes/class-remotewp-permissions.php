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
		// Capability entitlement is resolved by the RemoteWP central server and
		// represented locally only by the presence of the validated module. A
		// WordPress option must not be able to downgrade or unlock the bridge.
		$is_pro = class_exists( 'RemoteWP_FS_API_Pro' ) || ( defined( 'REMOTEWP_IS_FULL' ) && REMOTEWP_IS_FULL );
		$level  = $is_pro ? 'full' : 'read-only';
		$allowed = $is_pro ? $this->profiles['full'] : $this->profiles['read-only'];

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
		return RemoteWP_Path_Policy::resolve( $path, $must_exist, $is_write );
	}

	/**
	 * Check if a file is in the protected list.
	 *
	 * @param string $path File path to check.
	 * @return bool
	 */
	public function is_protected_file( $path ) {
		return RemoteWP_Path_Policy::is_protected( $path );
	}

	/**
	 * Legacy compatibility hook. Path access is centrally managed; local
	 * editable allowlists are intentionally ignored. Built-in protected-path
	 * and WordPress safety checks remain enforced by RemoteWP_Path_Policy.
	 *
	 * @param string $real_path The absolute path to check.
	 * @param string $real_base The ABSPATH.
	 * @return true|WP_Error True if allowed, WP_Error if restricted.
	 */
	private function check_path_restrictions( $real_path, $real_base ) {
		return true;
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

		// Exception: Allow writing llms.txt or llms-full.txt in the WordPress root directory
		$basename = strtolower( basename( $real_path ) );
		if ( in_array( $basename, array( 'llms.txt', 'llms-full.txt' ), true ) ) {
			$real_base = realpath( ABSPATH );
			if ( dirname( $real_path ) === $real_base ) {
				return true;
			}
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
