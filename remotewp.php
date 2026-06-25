<?php
/**
 * Plugin Name: RemoteWP
 * Plugin URI:  https://remotewp.dev
 * Description: The AI-Ready WordPress Bridge. Let AI agents manage your WordPress site remotely through a secure REST API — no SSH or FTP needed.
 * Version:     3.4.0
 * Author:      X-HOUSE SRL
 * Author URI:  https://xhouse.ro
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: remotewp
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'REMOTEWP_VERSION', '3.4.0' );
define( 'REMOTEWP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'REMOTEWP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'REMOTEWP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Open Core: detect if Pro module is installed
define( 'REMOTEWP_IS_PRO', file_exists( REMOTEWP_PLUGIN_DIR . 'pro/class-remotewp-fs-api-pro.php' ) );
define( 'REMOTEWP_IS_FULL', file_exists( REMOTEWP_PLUGIN_DIR . 'pro/full.txt' ) );

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
	require_once $includes . 'class-remotewp-auth.php';
	require_once $includes . 'class-remotewp-fs-api.php';
	require_once $includes . 'class-remotewp-admin.php';
	require_once $includes . 'class-remotewp-updater.php';

	// Pro classes (only if pro/ folder exists)
	if ( REMOTEWP_IS_PRO ) {
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

	$logger       = new RemoteWP_Logger();
	$rate_limiter = new RemoteWP_Rate_Limiter();
	$permissions  = new RemoteWP_Permissions();
	$license      = new RemoteWP_License();
	$auth         = new RemoteWP_Auth( $rate_limiter, $logger );

	// Core free endpoints (always active)
	new RemoteWP_FS_API( $auth, $permissions, $logger, $license );
	new RemoteWP_Admin( $auth, $permissions, $logger, $license );

	// Auto-updater (Pro builds with active license)
	new RemoteWP_Updater();

	// Pro endpoints (only if pro/ folder exists)
	if ( REMOTEWP_IS_PRO ) {
		new RemoteWP_FS_API_Pro( $auth, $permissions, $logger, $license );
		new RemoteWP_WP_API( $auth, $permissions, $logger, $license );
		new RemoteWP_Admin_Pro( $auth, $permissions, $logger, $license );
	}
}
add_action( 'plugins_loaded', 'remotewp_init' );

/**
 * Activation: generate initial token and set default options.
 */
function remotewp_activate() {
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

	// Schedule daily license verification (only for Pro builds)
	if ( REMOTEWP_IS_PRO && ! wp_next_scheduled( 'remotewp_daily_license_check' ) ) {
		wp_schedule_event( time(), 'daily', 'remotewp_daily_license_check' );
	}

	flush_rewrite_rules();
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

	flush_rewrite_rules();
}

/**
 * Daily license verification cron callback.
 */
function remotewp_cron_verify_license() {
	$license = new RemoteWP_License();
	$license->verify();
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
