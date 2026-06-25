<?php
/**
 * RemoteWP Uninstall
 *
 * Fired when the plugin is deleted from WordPress admin.
 * Cleans up all plugin data from the database.
 *
 * @package RemoteWP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove all plugin options
$options = array(
	'remotewp_api_token',
	'remotewp_rate_limit',
	'remotewp_ip_whitelist',
	'remotewp_permission_level',
	'remotewp_path_restrictions',
	'remotewp_lockout_threshold',
	'remotewp_lockout_duration',
	'remotewp_trust_proxy',
	'remotewp_license_key',
	'remotewp_license_status',
	'remotewp_license_tier',
	'remotewp_license_expires',
	'remotewp_daily_api_calls',
	'remotewp_daily_api_date',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Remove transients
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '%remotewp_%'"
);

// Remove backup directory (optional — leave backups by default for safety)
// Uncomment below to also remove backups on uninstall:
// $upload_dir = wp_upload_dir();
// $backup_dir = $upload_dir['basedir'] . '/remotewp_backups';
// if ( is_dir( $backup_dir ) ) {
//     array_map( 'unlink', glob( "$backup_dir/*" ) );
//     rmdir( $backup_dir );
// }
