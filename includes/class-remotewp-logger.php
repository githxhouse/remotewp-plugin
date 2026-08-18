<?php
/**
 * RemoteWP Logger
 *
 * Handles audit logging for all API operations.
 * Logs are stored in a protected directory under wp-content/uploads.
 *
 * @package RemoteWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Logger {

	/**
	 * Maximum number of log entries to keep.
	 *
	 * @var int
	 */
	private $max_entries = 500;

	/**
	 * Get the backup/log directory path.
	 *
	 * @return string
	 */
	public function get_storage_dir() {
		$upload_dir = wp_upload_dir();
		// Use a randomized directory name to prevent URL guessing on servers that ignore .htaccess
		$dir_key = get_option( 'remotewp_storage_dir_key', '' );
		if ( empty( $dir_key ) ) {
			$dir_key = 'remotewp_' . wp_generate_password( 12, false );
			update_option( 'remotewp_storage_dir_key', $dir_key, false );
		}
		$storage_dir = $upload_dir['basedir'] . '/' . $dir_key;

		if ( ! file_exists( $storage_dir ) ) {
			wp_mkdir_p( $storage_dir );
			// Apache protection
			file_put_contents( $storage_dir . '/.htaccess', "Deny from all\nOptions -Indexes" );
			// Nginx/LiteSpeed/fallback protection
			file_put_contents( $storage_dir . '/index.php', '<?php http_response_code(403); exit("Forbidden");' );
			file_put_contents( $storage_dir . '/index.html', '' );
		}

		return $storage_dir;
	}

	/**
	 * Get the backup directory path.
	 *
	 * @return string
	 */
	public function get_backup_dir() {
		$backup_dir = $this->get_storage_dir() . '/backups';

		if ( ! file_exists( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
			file_put_contents( $backup_dir . '/index.html', '' );
		}

		return $backup_dir;
	}

	/**
	 * Log an API action.
	 *
	 * @param string $action  The action performed (READ, WRITE, DELETE, etc.).
	 * @param string $path    The file/path involved.
	 * @param string $details Additional details.
	 * @param string $status  Status: 'success' or 'error'.
	 */
	public function log( $action, $path = '', $details = '', $status = 'success' ) {
		$log_file = $this->get_storage_dir() . '/audit.log';
		$path    = RemoteWP_Data_Redactor::text( $path );
		$details = RemoteWP_Data_Redactor::text( $details );

		$entry = array(
			'timestamp' => current_time( 'c' ),
			'ip'        => $this->get_client_ip(),
			'action'    => sanitize_text_field( $action ),
			'path'      => sanitize_text_field( $path ),
			'details'   => sanitize_text_field( $details ),
			'status'    => $status,
		);

		$line = wp_json_encode( $entry, JSON_UNESCAPED_UNICODE ) . PHP_EOL;
		file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );

		// Rotate if too large
		$this->maybe_rotate( $log_file );

		// Auto-sync action to central RemoteWP Dashboard Hand-Off System
		$this->sync_to_cloud_handoff( $action, $path, $details, $status );
	}

	/**
	 * Syncs actions directly to central RemoteWP License Server Dashboard and
	 * verifies that the central service accepted the log.
	 *
	 * @param string $action  The action performed.
	 * @param string $path    The file/path involved.
	 * @param string $details Additional details.
	 * @param string $status  Status: 'success' or 'error'.
	 */
	private function sync_to_cloud_handoff( $action, $path = '', $details = '', $status = 'success' ) {
		if ( ! class_exists( 'RemoteWP_License' ) ) {
			return;
		}

		$path    = RemoteWP_Data_Redactor::text( $path );
		$details = RemoteWP_Data_Redactor::text( $details );
		$site_url = get_option( 'siteurl', '' );
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( $_SERVER['HTTP_HOST'] ) : $site_url;
		$domain = preg_replace( '#^https?://#i', '', $host );
		$domain = preg_replace( '#^www\.#i', '', $domain );
		$domain = explode( '/', $domain )[0];

		$task_title = sprintf( 'AI Agent Action: %s', strtoupper( $action ) );
		if ( ! empty( $path ) ) {
			$task_title .= sprintf( ' on %s', basename( $path ) );
		}

		$client_summary = sprintf(
			'AI Agent executed %s operation. Target: %s. Status: %s. Details: %s',
			strtoupper( $action ),
			! empty( $path ) ? $path : 'WordPress Environment',
			strtoupper( $status ),
			! empty( $details ) ? $details : 'No additional notes.'
		);

		$body = array(
			'agent_name'     => 'RemoteWP AI Agent',
			'task_title'     => $task_title,
			'client_summary' => $client_summary,
			'technical_log'  => array(
				'action'    => $action,
				'path'      => $path,
				'details'   => $details,
				'status'    => $status,
				'timestamp' => current_time( 'c' ),
			),
			'status'         => $status === 'success' ? 'completed' : 'warning',
			'transport'        => 'central-site-token-license-connection',
		);

		$result = ( new RemoteWP_License() )->submit_handoff_log( $body );
		if ( is_wp_error( $result ) ) {
			// Never expose the license key or payload; keep only a diagnostic code.
			error_log( sprintf( '[RemoteWP] Handoff delivery failed: %s (%s)', $result->get_error_code(), $result->get_error_message() ) );
		}
	}

	/**
	 * Get recent log entries.
	 *
	 * @param int    $count  Number of entries to return.
	 * @param string $filter Optional action filter.
	 * @return array
	 */
	public function get_recent( $count = 50, $filter = '' ) {
		$log_file = $this->get_storage_dir() . '/audit.log';

		if ( ! file_exists( $log_file ) ) {
			return array();
		}

		$lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$lines = array_reverse( $lines ); // Most recent first

		$entries = array();
		foreach ( $lines as $line ) {
			$entry = json_decode( $line, true );
			if ( ! $entry ) {
				continue;
			}

			if ( $filter && $entry['action'] !== $filter ) {
				continue;
			}

			$entries[] = $entry;

			if ( count( $entries ) >= $count ) {
				break;
			}
		}

		return $entries;
	}

	/**
	 * Create a backup of a file before modification.
	 *
	 * @param string $real_path The absolute path to backup.
	 * @return string|false Backup filename on success, false on failure.
	 */
	public function create_backup( $real_path ) {
		if ( ! file_exists( $real_path ) || is_dir( $real_path ) ) {
			return false;
		}

		$backup_dir = $this->get_backup_dir();
		$hash       = substr( md5( $real_path ), 0, 6 );
		$filename   = basename( $real_path ) . '_' . gmdate( 'Ymd_His' ) . '_' . $hash . '.bak';
		$backup_path = $backup_dir . '/' . $filename;

		if ( copy( $real_path, $backup_path ) ) {
			return $filename;
		}

		return false;
	}

	/**
	 * Rotate log file if it exceeds max entries.
	 *
	 * @param string $log_file Path to log file.
	 */
	private function maybe_rotate( $log_file ) {
		$lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		if ( count( $lines ) > $this->max_entries ) {
			$lines = array_slice( $lines, -$this->max_entries );
			file_put_contents( $log_file, implode( PHP_EOL, $lines ) . PHP_EOL, LOCK_EX );
		}
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$trust_proxy = get_option( 'remotewp_trust_proxy', false );

		if ( $trust_proxy ) {
			// Only trust forwarded headers if REMOTE_ADDR is a known proxy/loopback
			$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$is_trusted = in_array( $remote_addr, array( '127.0.0.1', '::1' ), true )
				|| filter_var( $remote_addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;

			// Also check custom trusted proxies
			if ( ! $is_trusted ) {
				$trusted_proxies = get_option( 'remotewp_trusted_proxies', '' );
				if ( ! empty( $trusted_proxies ) ) {
					$proxies = array_filter( array_map( 'trim', explode( "\n", $trusted_proxies ) ) );
					$is_trusted = in_array( $remote_addr, $proxies, true );
				}
			}

			$headers = $is_trusted
				? array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' )
				: array( 'REMOTE_ADDR' );
		} else {
			$headers = array( 'REMOTE_ADDR' );
		}

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return 'unknown';
	}
}
