<?php
/**
 * Plugin Name: RemoteWP
 * Plugin URI:  https://remotewp.dev
 * Description: The AI-Ready WordPress Bridge. Let AI agents manage your WordPress site remotely through a secure REST API — no SSH or FTP needed.
 * Version:     3.8.0
 * Author:      X-HOUSE SRL
 * Author URI:  https://xhouse.ro
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: remotewp
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- WordPress version compatibility check ---
define( 'REMOTEWP_MIN_WP_VERSION', '5.8' );

if ( version_compare( get_bloginfo( 'version' ), REMOTEWP_MIN_WP_VERSION, '<' ) ) {
	add_action( 'admin_notices', function () {
		$current = get_bloginfo( 'version' );
		echo '<div class="notice notice-error" style="border-left-color:#dc3545;padding:12px 16px;">';
		echo '<p style="font-size:14px;margin:0 0 6px;"><strong>⚠️ RemoteWP — WordPress Update Required</strong></p>';
		echo '<p style="margin:0;">RemoteWP requires <strong>WordPress ' . esc_html( REMOTEWP_MIN_WP_VERSION ) . '+</strong>. ';
		echo 'Your current version is <strong>' . esc_html( $current ) . '</strong>.</p>';
		echo '<p style="margin:8px 0 0;"><a href="' . esc_url( admin_url( 'update-core.php' ) ) . '" class="button button-primary" style="margin-right:8px;">Update WordPress Now</a>';
		echo '<a href="https://remotewp.dev/docs/requirements" target="_blank" rel="noopener" style="text-decoration:underline;">Learn more</a></p>';
		echo '</div>';
	} );



	return; // Stop loading the rest of the plugin
}

// Plugin constants
define( 'REMOTEWP_VERSION', '3.8.0' );
define( 'REMOTEWP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'REMOTEWP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'REMOTEWP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'REMOTEWP_API_NAMESPACE', 'helper/v1' );
define( 'REMOTEWP_API_V2_NAMESPACE', 'remotewp/v2' );


// Open Core: detect if Pro module is installed
// Full build: pro/ folder exists directly (internal/admin use)
// Pro build: module downloaded from server (stored encrypted in wp-content/remotewp-pro/)
define( 'REMOTEWP_IS_FULL', file_exists( REMOTEWP_PLUGIN_DIR . 'pro/full.txt' ) );
define( 'REMOTEWP_HAS_LOCAL_PRO', file_exists( REMOTEWP_PLUGIN_DIR . 'pro/class-remotewp-fs-api-pro.php' ) );
define( 'REMOTEWP_IS_PRO', REMOTEWP_HAS_LOCAL_PRO || file_exists( WP_CONTENT_DIR . '/remotewp-pro/module.enc' ) );

/**
 * Resolve an internal license key only when a private build injects it.
 * Public/Core packages never contain a key or an obfuscation secret.
 *
 * @return string Decoded key or empty string.
 */
function remotewp_decode_internal_key() {
	return defined( 'REMOTEWP_INTERNAL_LICENSE_KEY' )
		? (string) REMOTEWP_INTERNAL_LICENSE_KEY
		: '';
}

/**
 * Load plugin textdomain for translations.
 */
function remotewp_load_textdomain() {
	load_plugin_textdomain( 'remotewp', false, dirname( REMOTEWP_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'init', 'remotewp_load_textdomain' );

/**
 * Load all required class files.
 */
function remotewp_load_classes() {
	$includes = REMOTEWP_PLUGIN_DIR . 'includes/';

	// Core classes (always loaded)
	require_once $includes . 'class-remotewp-logger.php';
	require_once $includes . 'class-remotewp-rate-limiter.php';
	require_once $includes . 'class-remotewp-permissions.php';
	require_once $includes . 'class-remotewp-license.php';
	require_once $includes . 'class-remotewp-operation-safety.php';
	require_once $includes . 'class-remotewp-operation-coordinator.php';
	require_once $includes . 'class-remotewp-data-redactor.php';
	require_once $includes . 'class-remotewp-content-handles.php';
	require_once $includes . 'class-remotewp-patch-engine.php';
	require_once $includes . 'class-remotewp-v2-response.php';
	require_once $includes . 'class-remotewp-v2-contract.php';
	require_once $includes . 'class-remotewp-connection-context.php';
	require_once $includes . 'class-remotewp-v2-token-store.php';
	require_once $includes . 'class-remotewp-rollout-policy.php';
	require_once $includes . 'class-remotewp-verification-manifest.php';
	require_once $includes . 'class-remotewp-path-policy.php';
	require_once $includes . 'class-remotewp-auth.php';
	require_once $includes . 'class-remotewp-fs-api.php';
	require_once $includes . 'class-remotewp-handoff-api.php';
	require_once $includes . 'class-remotewp-admin.php';
	require_once $includes . 'class-remotewp-updater.php';

	// Pro loader (always loaded — handles module lifecycle)
	require_once $includes . 'class-remotewp-pro-loader.php';

	// Pro classes loading
	if ( REMOTEWP_HAS_LOCAL_PRO ) {
		// Full/internal build: load directly from pro/ folder
		$pro = REMOTEWP_PLUGIN_DIR . 'pro/';
		require_once $pro . 'class-remotewp-fs-api-pro.php';
		require_once $pro . 'class-remotewp-wp-api.php';
		require_once $pro . 'class-remotewp-admin-pro.php';
	}
}

/**
 * Initialize the plugin after all classes are loaded.
 */
function remotewp_init() {
	remotewp_load_classes();

	// Correlate every RemoteWP REST response with the caller's request. This
	// also covers authentication/permission failures that happen before a
	// route handler can build the v2 response envelope.
	add_filter( 'rest_post_dispatch', 'remotewp_attach_request_id_header', 10, 3 );

	$logger       = new RemoteWP_Logger();
	$rate_limiter = new RemoteWP_Rate_Limiter();
	$permissions  = new RemoteWP_Permissions();
	$license      = new RemoteWP_License();
	$auth         = new RemoteWP_Auth( $rate_limiter, $logger, $permissions );

	// Core free endpoints (always active)
	new RemoteWP_FS_API( $auth, $permissions, $logger, $license );
	new RemoteWP_Handoff_API( $auth, $license );
	new RemoteWP_Admin( $auth, $permissions, $logger, $license );

	// Auto-updater (Pro builds with active license)
	new RemoteWP_Updater();

	// Pro endpoints loading
	$pro_loaded = false;

	if ( REMOTEWP_HAS_LOCAL_PRO ) {
		// Full/internal build: classes already loaded directly
		$pro_loaded = true;
	} elseif ( class_exists( 'RemoteWP_Pro_Loader' ) ) {
		// Server-delivered Pro: decrypt cached module if present locally.
		// Never block general page requests with synchronous network fetches.
		$loader = new RemoteWP_Pro_Loader( $license );
		if ( $loader->has_module() ) {
			$pro_loaded = $loader->load_module();
		}
	}

	if ( $pro_loaded && class_exists( 'RemoteWP_FS_API_Pro' ) ) {
		new RemoteWP_FS_API_Pro( $auth, $permissions, $logger, $license );
		new RemoteWP_WP_API( $auth, $permissions, $logger, $license );
		new RemoteWP_Admin_Pro( $auth, $permissions, $logger, $license );
	}
}
add_action( 'plugins_loaded', 'remotewp_init' );

/**
 * Add a safe correlation header to RemoteWP REST responses.
 *
 * A web application firewall may reject a request before WordPress runs; in
 * that case no PHP response header is possible and the WAF audit log remains
 * the source of truth. If WordPress receives the request, this header gives
 * the agent one value to use when matching site logs and the central log.
 *
 * @param WP_HTTP_Response|WP_Error $response REST response.
 * @param WP_REST_Server            $server   REST server.
 * @param WP_REST_Request           $request  Incoming request.
 * @return WP_HTTP_Response|WP_Error
 */
function remotewp_attach_request_id_header( $response, $server, $request ) {
	if ( ! $request instanceof WP_REST_Request ) {
		return $response;
	}

	$route    = (string) $request->get_route();
	$prefixes = array(
		'/' . trim( REMOTEWP_API_NAMESPACE, '/' ),
		'/' . trim( REMOTEWP_API_V2_NAMESPACE, '/' ),
	);
	$is_remote_wp_route = false;
	foreach ( $prefixes as $prefix ) {
		if ( $route === $prefix || 0 === strpos( $route, $prefix . '/' ) ) {
			$is_remote_wp_route = true;
			break;
		}
	}

	if ( ! $is_remote_wp_route ) {
		return $response;
	}

	return RemoteWP_V2_Response::attach_request_id_header( $response, $request );
}

/**
 * Activation: generate initial token and set default options.
 */
function remotewp_activate() {
	// Load core classes to prevent Class 'RemoteWP_License' not found fatal error during activation
	remotewp_load_classes();

	// Generate token if not exists
	if ( ! get_option( 'remotewp_api_token' ) ) {
		$token = '';
		if ( function_exists( 'random_bytes' ) ) {
			try {
				$token = bin2hex( random_bytes( 32 ) );
			} catch ( Exception $e ) {
				$token = '';
			}
		}
		
		if ( empty( $token ) ) {
			// Fallback to a safe WordPress random generator
			$token = wp_generate_password( 64, false );
		}
		
		update_option( 'remotewp_api_token', $token );
		update_option( 'remotewp_token_created_at', time() );
	}

	// Default settings
	$defaults = array(
		'remotewp_rate_limit'          => 60,
		'remotewp_ip_whitelist'        => '',
		'remotewp_permission_level'    => 'full',
		'remotewp_path_restrictions'   => '',
		'remotewp_lockout_threshold'   => 5,
		'remotewp_lockout_duration'    => 15,
		'remotewp_trust_proxy'         => 0,
		'remotewp_token_ttl'           => 0,  // 0 = never expire (backward compatible)
		'remotewp_token_created_at'    => time(),
		'remotewp_master_password'     => '',  // Owner lock: empty = disabled (Full build only)
		'remotewp_safety_kill_switch'  => 0,
		'remotewp_v2_mutations_enabled'=> 1,
		'remotewp_v2_mutation_allowlist' => '',
		'remotewp_redaction_extra_keys' => '',
		'remotewp_backup_retention_days' => 0,
		'remotewp_backup_max_records' => 0,
	);

	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( $key ) ) {
			add_option( $key, $value );
		}
	}

	// License defaults
	if ( false === get_option( 'remotewp_license_status' ) ) {
		add_option( 'remotewp_license_status', 'inactive' );
		add_option( 'remotewp_license_tier', 'free' );
	}

	// Full build: auto-activate with internal key (no manual entry needed)
	$internal_key = remotewp_decode_internal_key();
	if ( REMOTEWP_IS_FULL && ! empty( $internal_key ) && 'active' !== get_option( 'remotewp_license_status' ) ) {
		$license = new RemoteWP_License();
		$license->auto_activate( $internal_key );
	}

	// Firewall configuration remains administrator-owned. RemoteWP exposes
	// request IDs and safe diagnostics, but never changes Wordfence/ModSecurity
	// rules automatically during activation.

	// Schedule daily license verification (only for Pro builds)
	if ( REMOTEWP_IS_PRO && ! wp_next_scheduled( 'remotewp_daily_license_check' ) ) {
		wp_schedule_event( time(), 'daily', 'remotewp_daily_license_check' );
	}
}
register_activation_hook( __FILE__, 'remotewp_activate' );

/**
 * Deactivation: cleanup transients.
 */
function remotewp_deactivate() {
	// Clean rate limit transients
	global $wpdb;
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_remotewp_%' OR option_name LIKE '_transient_timeout_remotewp_%'"
	);

	// Remove cron
	wp_clear_scheduled_hook( 'remotewp_daily_license_check' );
}

/**
 * Daily license verification cron callback.
 */
function remotewp_cron_verify_license() {
	$license  = new RemoteWP_License();
	$is_valid = $license->verify();

	// Forward result to Pro loader for grace period tracking
	if ( class_exists( 'RemoteWP_Pro_Loader' ) ) {
		$loader = new RemoteWP_Pro_Loader( $license );
		$loader->handle_verification_result( $is_valid );
	}
}
add_action( 'remotewp_daily_license_check', 'remotewp_cron_verify_license' );
register_deactivation_hook( __FILE__, 'remotewp_deactivate' );

/**
 * Add settings link on plugins page.
 */
function remotewp_plugin_action_links( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		admin_url( 'admin.php?page=remotewp' ),
		__( 'Settings', 'remotewp' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . REMOTEWP_PLUGIN_BASENAME, 'remotewp_plugin_action_links' );
