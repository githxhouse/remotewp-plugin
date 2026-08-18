<?php
/**
 * RemoteWP Permissions Manager
 *
 * Handles granular capability checks and path restrictions.
 * In the Open Core model, write/delete/rename operations are
 * physically in the Pro add-on, but permission profiles still
 * govern which API operations are allowed.
 *
 * Profiles:
 *   - read-only:   list, read, status, instructions, info
 *   - read-write:  all read + write, mkdir, restore
 *   - full:        all operations including delete, rename, plugin management
 *
 * @package RemoteWP
 * @since   3.1.0
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
		'read-only' => array(
			'read',
			'list',
			'status',
			'instructions',
			'skill',
			'wp_info',
		),
		'read-write' => array(
			'read',
			'list',
			'status',
			'instructions',
			'skill',
			'wp_info',
			'write',
			'mkdir',
			'restore',
			'search',
			'patch',
		),
		'full' => array(
			'read',
			'list',
			'status',
			'instructions',
			'skill',
			'wp_info',
			'write',
			'mkdir',
			'restore',
			'search',
			'patch',
			'delete',
			'rename',
			'wp_plugins',
			'wp_plugin_toggle',
			'wp_options',
			'wp_cache_clear',
		),
	);

	/**
	 * Check if an operation is allowed under the current permission profile.
	 *
	 * @param string $operation The operation to check (e.g., 'read', 'write', 'delete').
	 * @return true|WP_Error True if allowed, WP_Error with 403 status if denied.
	 */
	public function can( $operation ) {
		$level = get_option( 'remotewp_permission_level', 'full' );

		// Validate level exists, fallback to 'full'
		if ( ! isset( $this->profiles[ $level ] ) ) {
			$level = 'full';
		}

		$allowed = $this->profiles[ $level ];

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
			'patch',
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
