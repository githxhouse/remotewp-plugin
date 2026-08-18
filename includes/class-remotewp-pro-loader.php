<?php
/**
 * RemoteWP Pro Module Loader
 *
 * Downloads, stores, decrypts, and includes the Pro module
 * from the RemoteWP license server. The Pro code is never
 * shipped in the plugin ZIP — it is fetched at runtime
 * after license activation and cached locally (encrypted).
 *
 * Security model:
 *   - Module is encrypted with AES-256-CBC
 *   - Key is derived from license_key + domain via PBKDF2
 *   - Only matching license+domain can decrypt
 *   - Daily cron verifies license; 3 consecutive failures = module deleted
 *
 * @package RemoteWP
 * @since   3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Pro_Loader {

	/**
	 * Directory where encrypted module is stored.
	 */
	const MODULE_DIR = 'remotewp-pro';

	/**
	 * Filename for the encrypted module.
	 */
	const MODULE_FILE = 'module.enc';

	/**
	 * Filename for the module hash (version tracking).
	 */
	const HASH_FILE = 'module.hash';

	/** Filename storing which server-issued material decrypts the module. */
	const KEY_MODE_OPTION = 'remotewp_pro_module_key_mode';

	/**
	 * Option key for consecutive verification failures.
	 */
	const OPT_FAIL_COUNT = 'remotewp_pro_verify_fails';

	/**
	 * Max consecutive failures before module deletion.
	 */
	const MAX_FAILURES = 3;

	/**
	 * API endpoint for fetching the Pro module.
	 */
	private $api_url = 'https://remotewp.dev/wp-json/remotewp-license/v1/pro-module';

	/** Server-authoritative delivery path for connected sites. */
	private $site_token_api_url = 'https://remotewp.dev/wp-json/remotewp-license/v1/pro-module/site-token';

	/**
	 * @var RemoteWP_License
	 */
	private $license;

	/**
	 * Constructor.
	 *
	 * @param RemoteWP_License $license License manager instance.
	 */
	public function __construct( RemoteWP_License $license ) {
		$this->license = $license;
	}

	/**
	 * Get the full path to the module storage directory.
	 *
	 * @return string
	 */
	private function get_module_dir() {
		return WP_CONTENT_DIR . '/' . self::MODULE_DIR;
	}

	/**
	 * Get the full path to the encrypted module file.
	 *
	 * @return string
	 */
	private function get_module_path() {
		return $this->get_module_dir() . '/' . self::MODULE_FILE;
	}

	/**
	 * Get the full path to the hash file.
	 *
	 * @return string
	 */
	private function get_hash_path() {
		return $this->get_module_dir() . '/' . self::HASH_FILE;
	}

	/**
	 * Check if a Pro module is available locally.
	 *
	 * @return bool
	 */
	public function has_module() {
		return file_exists( $this->get_module_path() );
	}

	/**
	 * Derive the decryption key from license_key + domain.
	 * Must match the server-side derivation exactly.
	 *
	 * @return string|false 32-byte binary key, or false if no license.
	 */
	private function derive_key() {
		$key_mode    = (string) get_option( self::KEY_MODE_OPTION, 'license_key' );
		$license_key = 'site_token' === $key_mode ? (string) get_option( 'remotewp_api_token', '' ) : $this->license->get_license_key();
		if ( empty( $license_key ) && 'site_token' !== $key_mode ) {
			$license_key = (string) get_option( 'remotewp_api_token', '' );
		}
		$domain      = $this->get_site_domain();

		if ( empty( $license_key ) || empty( $domain ) ) {
			return false;
		}

		$material = $license_key . '::' . $domain . '::remotewp_pro_2026';
		return hash_pbkdf2( 'sha256', $material, 'remotewp_salt_v1', 100000, 32, true );
	}

	/**
	 * Get the normalized site domain.
	 *
	 * @return string
	 */
	private function get_site_domain() {
		$url    = home_url();
		$parsed = wp_parse_url( $url );
		$host   = $parsed['host'] ?? $url;
		// Strip www. to match server-side normalizeDomain()
		$host = preg_replace( '/^www\./i', '', $host );
		return strtolower( $host );
	}

	/**
	 * Fetch and store the Pro module from the license server.
	 * Called after successful license activation.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function fetch_module() {
		$license_key = $this->license->get_license_key();
		$domain      = $this->get_site_domain();
		$site_token  = (string) get_option( 'remotewp_api_token', '' );
		$requests    = array();

		if ( ! empty( $license_key ) ) {
			$requests[] = array(
				'url'     => $this->api_url,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => array( 'license_key' => $license_key, 'domain' => $domain, 'plugin_version' => REMOTEWP_VERSION ),
				'key_mode'=> 'license_key',
			);
		}
		if ( ! empty( $site_token ) ) {
			$requests[] = array(
				'url'     => $this->site_token_api_url,
				'headers' => array( 'Content-Type' => 'application/json', 'X-RemoteWP-Token' => $site_token ),
				'body'    => array( 'domain' => $domain, 'plugin_version' => REMOTEWP_VERSION ),
				'key_mode'=> 'site_token',
			);
		}

		if ( empty( $requests ) ) {
			return new WP_Error( 'site_not_connected', __( 'No connected RemoteWP site token is available.', 'remotewp' ) );
		}

		$response = false;
		$body    = array();
		$key_mode = 'license_key';
		foreach ( $requests as $request ) {
			$response = wp_remote_post( $request['url'], array(
				'timeout' => 30,
				'body'    => wp_json_encode( $request['body'] ),
				'headers' => $request['headers'],
			) );
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 === wp_remote_retrieve_response_code( $response ) && ! empty( $body['success'] ) && ! empty( $body['module'] ) ) {
				$key_mode = $request['key_mode'];
				break;
			}
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'connection_failed', __( 'Could not connect to the RemoteWP server.', 'remotewp' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code || empty( $body['success'] ) || empty( $body['module'] ) ) {
			$message = $body['message'] ?? __( 'Failed to fetch Pro module.', 'remotewp' );
			return new WP_Error( 'fetch_failed', $message );
		}

		// Ensure directory exists with restricted permissions
		$dir = $this->get_module_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Write .htaccess to prevent direct access
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Order Deny,Allow\nDeny from all\n" );
		}

		// Write index.php to prevent directory listing
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		// Store encrypted module
		file_put_contents( $this->get_module_path(), $body['module'] );

		// Store hash for version tracking
		if ( ! empty( $body['hash'] ) ) {
			file_put_contents( $this->get_hash_path(), $body['hash'] );
		}
		update_option( self::KEY_MODE_OPTION, sanitize_key( $body['key_mode'] ?? $key_mode ) );
		if ( ! empty( $body['tier'] ) ) {
			update_option( 'remotewp_license_status', 'active' );
			update_option( 'remotewp_license_tier', sanitize_key( $body['tier'] ) );
		}
		if ( isset( $body['trial_expires'] ) ) {
			update_option( 'remotewp_license_trial_expires', sanitize_text_field( $body['trial_expires'] ) );
		}

		// Reset failure counter
		update_option( self::OPT_FAIL_COUNT, 0 );

		return true;
	}

	/**
	 * Load and execute the Pro module.
	 * Decrypts the stored module and includes it.
	 *
	 * @return bool True if module loaded successfully, false otherwise.
	 */
	public function load_module() {
		if ( ! $this->has_module() ) {
			return false;
		}

		$key = $this->derive_key();
		if ( false === $key ) {
			return false;
		}

		$encrypted_b64 = file_get_contents( $this->get_module_path() );
		if ( empty( $encrypted_b64 ) ) {
			return false;
		}

		$payload = base64_decode( $encrypted_b64 );
		if ( false === $payload || strlen( $payload ) < 17 ) {
			return false;
		}

		// Extract IV (first 16 bytes) and ciphertext
		$iv         = substr( $payload, 0, 16 );
		$ciphertext = substr( $payload, 16 );

		$php_code = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $php_code ) {
			// Decryption failed — key mismatch (pirated copy on wrong domain?)
			return false;
		}

		// Write to a temporary file and include it (safer than eval)
		$tmp_file = $this->get_module_dir() . '/module.tmp.php';
		file_put_contents( $tmp_file, $php_code );
		// Use include rather than include_once so a bounded refresh in the same
		// request can load the newly fetched module after a stale-cache failure.
		include $tmp_file;
		@unlink( $tmp_file );

		return true;
	}

	/**
	 * Load the cached module, or refresh it once when the cache is missing or
	 * cannot produce the Pro filesystem class. This is intentionally bounded to
	 * one refresh attempt per request so a broken license server cannot create a
	 * request loop. Active Pro/Lifetime/trial sites must recover automatically;
	 * users must not be asked for WooCommerce credentials or hidden settings.
	 *
	 * @return bool True when the Pro module and its filesystem class are loaded.
	 */
	public function load_or_refresh_module() {
		$plugin_version = defined( 'REMOTEWP_VERSION' ) ? REMOTEWP_VERSION : 'unknown';
		$refresh_marker = (string) get_option( 'remotewp_pro_module_refresh_version', '' );
		if ( $plugin_version !== $refresh_marker ) {
			$version_refresh = $this->fetch_module();
			if ( ! is_wp_error( $version_refresh ) ) {
				update_option( 'remotewp_pro_module_refresh_version', $plugin_version );
			}
		}

		$loaded = $this->load_module();
		if ( $loaded && class_exists( 'RemoteWP_FS_API_Pro' ) ) {
			return true;
		}

		$refresh = $this->fetch_module();
		if ( is_wp_error( $refresh ) ) {
			error_log( '[RemoteWP] Pro module refresh failed: ' . $refresh->get_error_code() );
			return false;
		}

		$loaded = $this->load_module();
		return $loaded && class_exists( 'RemoteWP_FS_API_Pro' );
	}

	/**
	 * Delete the stored Pro module.
	 * Called on license deactivation or after too many verification failures.
	 */
	public function delete_module() {
		$dir = $this->get_module_dir();

		$files = array(
			$this->get_module_path(),
			$this->get_hash_path(),
			$dir . '/module.tmp.php',
		);

		foreach ( $files as $file ) {
			if ( file_exists( $file ) ) {
				@unlink( $file );
			}
		}

		delete_option( self::OPT_FAIL_COUNT );
		delete_option( self::KEY_MODE_OPTION );
	}

	/**
	 * Handle daily verification result.
	 * If verification fails for MAX_FAILURES consecutive days, delete the module.
	 *
	 * @param bool $is_valid Whether the license verification succeeded.
	 */
	public function handle_verification_result( $is_valid ) {
		if ( $is_valid ) {
			update_option( self::OPT_FAIL_COUNT, 0 );

			// Check for module updates
			$this->check_for_updates();
			return;
		}

		// Lifetime licenses: infinite grace period — never delete the module
		// on verification failure (server offline, network issues, etc.)
		// Module is only deleted on explicit license deactivation.
		$tier = get_option( 'remotewp_license_tier', 'free' );
		if ( 'lifetime' === $tier ) {
			return;
		}

		$fails = (int) get_option( self::OPT_FAIL_COUNT, 0 );
		$fails++;
		update_option( self::OPT_FAIL_COUNT, $fails );

		if ( $fails >= self::MAX_FAILURES ) {
			$this->delete_module();
		}
	}

	/**
	 * Check if a newer version of the Pro module is available.
	 * Downloads it if the hash differs.
	 */
	private function check_for_updates() {
		$local_hash = '';
		$hash_path  = $this->get_hash_path();

		if ( file_exists( $hash_path ) ) {
			$local_hash = trim( file_get_contents( $hash_path ) );
		}

		// Fetch fresh module (will have new hash if updated)
		$result = $this->fetch_module();

		if ( is_wp_error( $result ) ) {
			// Silently fail — module stays at current version
			return;
		}
	}
}
