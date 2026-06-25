<?php
/**
 * RemoteWP Filesystem API — Free Tier
 *
 * REST API endpoints available in the free version.
 * Pro endpoints (write, delete, rename, mkdir, restore, search)
 * are in pro/class-remotewp-fs-api-pro.php and physically absent
 * from the free build.
 *
 * Free Endpoints:
 *   GET  /remotewp/v1/list         — List directory contents
 *   GET  /remotewp/v1/read         — Read file content
 *   GET  /remotewp/v1/status       — Plugin & server status
 *   GET  /remotewp/v1/instructions — AI agent instructions
 *   GET  /remotewp/v1/wp/info      — Basic site information (free)
 *
 * @package RemoteWP
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_FS_API {

	/**
	 * @var RemoteWP_Auth
	 */
	private $auth;

	/**
	 * @var RemoteWP_Permissions
	 */
	private $permissions;

	/**
	 * @var RemoteWP_Logger
	 */
	private $logger;

	/**
	 * @var RemoteWP_License
	 */
	private $license;

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	private $namespace = 'remotewp/v1';

	/**
	 * Constructor.
	 *
	 * @param RemoteWP_Auth        $auth        Auth handler.
	 * @param RemoteWP_Permissions $permissions Permissions handler.
	 * @param RemoteWP_Logger      $logger      Logger.
	 * @param RemoteWP_License     $license     License handler.
	 */
	public function __construct( RemoteWP_Auth $auth, RemoteWP_Permissions $permissions, RemoteWP_Logger $logger, RemoteWP_License $license ) {
		$this->auth        = $auth;
		$this->permissions = $permissions;
		$this->logger      = $logger;
		$this->license     = $license;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register free filesystem REST routes.
	 */
	public function register_routes() {
		$auth_callback = array( $this->auth, 'validate_request' );

		$routes = array(
			array( '/list',         'GET', 'list_dir' ),
			array( '/read',         'GET', 'read_file' ),
			array( '/status',       'GET', 'get_status' ),
			array( '/instructions', 'GET', 'get_instructions' ),
			array( '/skill',        'GET', 'get_skill' ),
		);

		foreach ( $routes as $route ) {
			register_rest_route(
				$this->namespace,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( $this, $route[2] ),
					'permission_callback' => $auth_callback,
				)
			);
		}

		// Basic wp/info is free (only if Pro WP API is not loaded)
		if ( ! defined( 'REMOTEWP_IS_PRO' ) || ! REMOTEWP_IS_PRO ) {
			register_rest_route(
				$this->namespace,
				'/wp/info',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_wp_info_basic' ),
					'permission_callback' => $auth_callback,
				)
			);
		}
	}

	/**
	 * GET /list — List directory contents.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_dir( $request ) {
		$can = $this->permissions->can( 'list' );
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		$path      = $request->get_param( 'path' );
		$real_path = $this->permissions->sanitize_path( $path );

		if ( is_wp_error( $real_path ) ) {
			return $real_path;
		}

		if ( ! is_dir( $real_path ) ) {
			return new WP_Error( 'not_a_directory', __( 'Path is not a directory.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$files  = scandir( $real_path );
		$result = array();

		foreach ( $files as $file ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}

			$file_path = $real_path . DIRECTORY_SEPARATOR . $file;
			$is_dir    = is_dir( $file_path );

			$entry = array(
				'name'     => $file,
				'type'     => $is_dir ? 'directory' : 'file',
				'size'     => $is_dir ? null : filesize( $file_path ),
				'perms'    => substr( sprintf( '%o', fileperms( $file_path ) ), -4 ),
				'modified' => gmdate( 'c', filemtime( $file_path ) ),
			);

			if ( ! $is_dir ) {
				$entry['extension'] = pathinfo( $file, PATHINFO_EXTENSION );
			}

			$result[] = $entry;
		}

		$this->logger->log( 'LIST', $path ?: '/' );

		return rest_ensure_response( array(
			'path'  => $path ?: '/',
			'count' => count( $result ),
			'files' => $result,
		) );
	}

	/**
	 * GET /read — Read file content.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function read_file( $request ) {
		$can = $this->permissions->can( 'read' );
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		$path = $request->get_param( 'path' );
		if ( empty( $path ) ) {
			return new WP_Error( 'missing_path', __( 'Path parameter is required.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$real_path = $this->permissions->sanitize_path( $path );
		if ( is_wp_error( $real_path ) ) {
			return $real_path;
		}

		if ( ! is_file( $real_path ) ) {
			return new WP_Error( 'not_a_file', __( 'Path is not a file.', 'remotewp' ), array( 'status' => 400 ) );
		}

		// Safety: limit file size to 5MB
		$size = filesize( $real_path );
		if ( $size > 5 * 1024 * 1024 ) {
			return new WP_Error( 'file_too_large', __( 'File exceeds the 5MB read limit.', 'remotewp' ), array( 'status' => 413 ) );
		}

		$content = file_get_contents( $real_path );

		$this->logger->log( 'READ', $path );

		return rest_ensure_response( array(
			'path'      => $path,
			'size'      => $size,
			'modified'  => gmdate( 'c', filemtime( $real_path ) ),
			'content'   => $content,
		) );
	}

	/**
	 * GET /status — Get plugin and server status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_status( $request ) {
		$permission_level = get_option( 'remotewp_permission_level', 'full' );

		return rest_ensure_response( array(
			'status'           => 'ok',
			'plugin_version'   => REMOTEWP_VERSION,
			'wp_version'       => get_bloginfo( 'version' ),
			'php_version'      => phpversion(),
			'server_software'  => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown',
			'abspath_writable' => is_writable( ABSPATH ),
			'permission_level' => $permission_level,
			'rate_limit'       => (int) get_option( 'remotewp_rate_limit', 60 ),
			'max_upload_size'  => wp_max_upload_size(),
			'timezone'         => wp_timezone_string(),
			'is_pro'           => defined( 'REMOTEWP_IS_PRO' ) && REMOTEWP_IS_PRO,
		) );
	}

	/**
	 * GET /instructions — Get AI agent instructions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_instructions( $request ) {
		$instructions_file = REMOTEWP_PLUGIN_DIR . 'instructions.md';
		$markdown = '';

		if ( file_exists( $instructions_file ) ) {
			$markdown = file_get_contents( $instructions_file );
		} else {
			return new WP_Error( 'instructions_not_found', __( 'Instructions file not found.', 'remotewp' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array(
			'success'      => true,
			'format'       => 'markdown',
			'instructions' => $markdown,
		) );
	}

	/**
	 * GET /wp/info — Basic site information (free tier).
	 * Simplified version — full version is in pro/class-remotewp-wp-api.php.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_wp_info_basic( $request ) {
		$theme = wp_get_theme();

		$this->logger->log( 'WP_INFO', '', 'Basic site info (free tier)' );

		return rest_ensure_response( array(
			'site'      => array(
				'name'        => get_bloginfo( 'name' ),
				'description' => get_bloginfo( 'description' ),
				'url'         => home_url(),
				'language'    => get_locale(),
				'timezone'    => wp_timezone_string(),
			),
			'wordpress' => array(
				'version' => get_bloginfo( 'version' ),
			),
			'server'    => array(
				'php_version' => phpversion(),
			),
			'theme'     => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
			),
			'remotewp'  => array(
				'version' => REMOTEWP_VERSION,
				'tier'    => 'free',
				'upgrade' => 'https://remotewp.dev/pricing',
			),
		) );
	}

	/**
	 * GET /skill — Serve the SKILL.md agent skill pack with dynamic site variables.
	 *
	 * Returns the complete RemoteWP agent skill as markdown with site-specific
	 * values substituted for template placeholders.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_skill( $request ) {
		$skill_file = REMOTEWP_PLUGIN_DIR . 'skills/remotewp-bridge/SKILL.md';

		if ( ! file_exists( $skill_file ) ) {
			return new WP_Error( 'skill_not_found', __( 'Skill pack file not found.', 'remotewp' ), array( 'status' => 404 ) );
		}

		$content = file_get_contents( $skill_file );

		// Replace dynamic placeholders
		$api_base = rest_url( 'remotewp/v1/' );
		$tier     = defined( 'REMOTEWP_IS_PRO' ) && REMOTEWP_IS_PRO ? 'pro' : 'free';

		$content = str_replace(
			array( '{{API_BASE}}', '{{SITE_URL}}', '{{TIER}}' ),
			array( $api_base, home_url(), $tier ),
			$content
		);

		$this->logger->log( 'SKILL', '', 'Agent skill pack served' );

		return rest_ensure_response( array(
			'success' => true,
			'format'  => 'markdown',
			'version' => REMOTEWP_VERSION,
			'tier'    => $tier,
			'skill'   => $content,
		) );
	}
}
