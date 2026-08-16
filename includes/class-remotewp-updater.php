<?php
/**
 * RemoteWP Auto-Updater
 *
 * Integrates with WordPress's native update system to provide
 * 1-click updates for Pro users directly from the WP dashboard.
 *
 * Hooks:
 *   - pre_set_site_transient_update_plugins → inject update info
 *   - plugins_api → provide plugin details for the "View Details" modal
 *   - upgrader_process_complete → clear cache after update
 *
 * The updater only contacts the license server if:
 *   1. Pro files are present (REMOTEWP_IS_PRO)
 *   2. A license key is saved and active
 *
 * @package RemoteWP
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Updater {

	/**
	 * License server update-check endpoint.
	 *
	 * @var string
	 */
	private $api_url = 'https://remotewp.dev/wp-json/remotewp-license/v1/update-check';

	/**
	 * The plugin slug.
	 *
	 * @var string
	 */
	private $slug = 'remotewp';

	/**
	 * Plugin basename (e.g., 'remotewp/remotewp.php').
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Cache key for update check transient.
	 *
	 * @var string
	 */
	private $cache_key = 'remotewp_update_check';

	/**
	 * How long to cache update checks (in seconds).
	 * Default: 12 hours.
	 *
	 * @var int
	 */
	private $cache_ttl = 43200;

	/**
	 * Constructor — register WordPress hooks.
	 */
	public function __construct() {
		// Version-scoped cache prevents an old negative result from hiding a new
		// package for up to the previous cache TTL after a release.
		$this->cache_key = 'remotewp_update_check_' . str_replace( '.', '_', REMOTEWP_VERSION );
		$this->plugin_basename = defined( 'REMOTEWP_PLUGIN_BASENAME' )
			? REMOTEWP_PLUGIN_BASENAME
			: 'remotewp/remotewp.php';

		// Check for updates (always registered for both Free and Pro)

		// Check for updates
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );

		// Plugin details popup (View Details link)
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );

		// Clear cache after update
		add_action( 'upgrader_process_complete', array( $this, 'clear_update_cache' ), 10, 2 );

		// When WordPress runs an update for this plugin, replace the existing
		// destination instead of leaving a stale/partial folder behind.
		add_filter( 'upgrader_package_options', array( $this, 'force_overwrite_remote_package' ) );

		// Clear cache when license is activated/deactivated
		add_action( 'update_option_remotewp_license_key', array( $this, 'clear_update_cache_simple' ) );
		add_action( 'update_option_remotewp_license_status', array( $this, 'clear_update_cache_simple' ) );
	}

	/**
	 * Check the license server for available updates.
	 *
	 * Hooks into WordPress's update check transient. If a newer
	 * version exists, injects update info so WP shows the native
	 * "Update Available" notice.
	 *
	 * @param object $transient The update_plugins transient.
	 * @return object Modified transient.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Get license info (fallback to internal key for FULL builds)
		$license_key = class_exists( 'RemoteWP_License' )
			? ( new RemoteWP_License() )->get_license_key()
			: get_option( 'remotewp_license_key', '' );
		if ( empty( $license_key ) && function_exists( 'remotewp_decode_internal_key' ) ) {
			$license_key = remotewp_decode_internal_key();
		}

		// Check cache first (bypass if forced update check)
		$force = false;
		if ( is_admin() && ( isset( $_GET['force-check'] ) || ( isset( $GLOBALS['pagenow'] ) && 'update-core.php' === $GLOBALS['pagenow'] ) ) ) {
			$force = true;
			delete_transient( $this->cache_key );
		}

		$cached = $force ? false : get_transient( $this->cache_key );
		if ( false !== $cached ) {
			if ( ! empty( $cached['update_available'] ) && ! empty( $cached['response'] ) ) {
				$transient->response[ $this->plugin_basename ] = $cached['response'];
			}
			return $transient;
		}

		// Query the license server
		$remote = $this->fetch_update_info( $license_key );

		if ( is_wp_error( $remote ) || empty( $remote['success'] ) ) {
			// Cache the negative result to avoid hammering the server
			set_transient( $this->cache_key, array( 'update_available' => false ), $this->cache_ttl );
			return $transient;
		}

		if ( ! empty( $remote['update_available'] ) && ! empty( $remote['version'] ) ) {
			$response = (object) array(
				'slug'         => $this->slug,
				'plugin'       => $this->plugin_basename,
				'new_version'  => $remote['version'],
				'url'          => $remote['homepage'] ?? 'https://remotewp.dev',
				'package'      => $remote['download_url'] ?? '',
				'tested'       => $remote['tested'] ?? '',
				'requires'     => $remote['requires'] ?? '',
				'requires_php' => $remote['requires_php'] ?? '',
				'icons'        => array(
					'1x' => 'https://remotewp.dev/logo-remotewp.png',
					'2x' => 'https://remotewp.dev/logo-remotewp.png',
				),
				'banners'      => array(),
			);

			$transient->response[ $this->plugin_basename ] = $response;

			// Cache the positive result
			set_transient( $this->cache_key, array(
				'update_available' => true,
				'response'         => $response,
			), $this->cache_ttl );
		} else {
			// No update available — cache it
			set_transient( $this->cache_key, array( 'update_available' => false ), $this->cache_ttl );
		}

		return $transient;
	}

	/**
	 * Provide plugin details for the "View Details" modal.
	 *
	 * WordPress shows this when you click "View version X.Y.Z details"
	 * on the Plugins page or the Updates page.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $this->slug !== $args->slug ) {
			return $result;
		}

		$license_key = class_exists( 'RemoteWP_License' )
			? ( new RemoteWP_License() )->get_license_key()
			: get_option( 'remotewp_license_key', '' );

		if ( empty( $license_key ) ) {
			return $result;
		}

		$remote = $this->fetch_update_info( $license_key );

		if ( is_wp_error( $remote ) || empty( $remote['success'] ) ) {
			return $result;
		}

		$plugin_info = (object) array(
			'name'          => 'RemoteWP Pro',
			'slug'          => $this->slug,
			'version'       => $remote['version'] ?? REMOTEWP_VERSION,
			'author'        => '<a href="https://xhouse.ro">X-HOUSE SRL</a>',
			'author_profile'=> 'https://xhouse.ro',
			'homepage'      => $remote['homepage'] ?? 'https://remotewp.dev',
			'requires'      => $remote['requires'] ?? '5.8',
			'tested'        => $remote['tested'] ?? '',
			'requires_php'  => $remote['requires_php'] ?? '7.4',
			'download_link' => $remote['download_url'] ?? '',
			'trunk'         => $remote['download_url'] ?? '',
			'sections'      => array(
				'description' => '<p>The AI-Ready WordPress Bridge. Let AI agents manage your WordPress site remotely through a secure REST API — no SSH or FTP needed.</p>',
				'changelog'   => '<p>Visit <a href="' . esc_url( $remote['changelog_url'] ?? 'https://remotewp.dev/changelog' ) . '">remotewp.dev/changelog</a> for the full changelog.</p>',
			),
			'banners'       => array(),
			'icons'         => array(
				'1x' => 'https://remotewp.dev/logo-remotewp.png',
				'2x' => 'https://remotewp.dev/logo-remotewp.png',
			),
		);

		return $plugin_info;
	}

	/**
	 * Clear the update check cache.
	 *
	 * Called after plugin updates complete.
	 *
	 * @param object $upgrader
	 * @param array  $options
	 */
	public function clear_update_cache( $upgrader = null, $options = array() ) {
		if ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
			if ( in_array( $this->plugin_basename, $options['plugins'], true ) ) {
				delete_transient( $this->cache_key );
			}
		}
	}

	/**
	 * Simple cache clear (no args).
	 */
	public function clear_update_cache_simple() {
		delete_transient( $this->cache_key );
	}

	/**
	 * Make RemoteWP updates replace the existing plugin directory.
	 *
	 * This is intentionally scoped to the RemoteWP plugin basename or the
	 * RemoteWP package URL; unrelated plugin uploads keep WordPress defaults.
	 *
	 * @param array $options Package options passed through WordPress upgrader.
	 * @return array
	 */
	public function force_overwrite_remote_package( $options ) {
		$package = isset( $options['package'] ) ? (string) $options['package'] : '';
		$plugin  = $options['hook_extra']['plugin'] ?? '';
		$is_remote = $plugin === $this->plugin_basename || false !== strpos( $package, 'remotewp.dev' );

		if ( ! $is_remote ) {
			return $options;
		}

		$options['clear_destination']        = true;
		$options['abort_if_destination_exists'] = false;
		return $options;
	}

	/**
	 * Fetch update information from the license server.
	 *
	 * @param string $license_key
	 * @return array|WP_Error
	 */
	private function fetch_update_info( $license_key ) {
		$url = home_url();
		$parsed = wp_parse_url( $url );
		$domain = $parsed['host'] ?? $url;

		$request_url = add_query_arg( array(
			'license_key'     => $license_key,
			'domain'          => $domain,
			'current_version' => REMOTEWP_VERSION,
			'plugin_slug'     => $this->slug,
		), $this->api_url );

		$response = wp_remote_get( $request_url, array(
			'timeout' => 15,
			'headers' => array(
				'Accept' => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			return new WP_Error(
				'update_check_failed',
				sprintf( 'Update check failed with HTTP %d', $code )
			);
		}

		return $body;
	}
}
