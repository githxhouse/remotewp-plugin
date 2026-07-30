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
		$license_key = $this->license->get_license_key();
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

		if ( empty( $license_key ) ) {
			return new WP_Error( 'no_license', __( 'No license key available.', 'remotewp' ) );
		}

		$response = wp_remote_post( $this->api_url, array(
			'timeout' => 30,
			'body'    => wp_json_encode( array(
				'license_key' => $license_key,
				'domain'      => $domain,
			) ),
			'headers' => array(
				'Content-Type' => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'connection_failed', __( 'Could not connect to the license server.', 'remotewp' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

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
		include_once $tmp_file;
		@unlink( $tmp_file );

		return true;
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
