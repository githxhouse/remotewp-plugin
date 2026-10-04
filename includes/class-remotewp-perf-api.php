<?php
/**
 * Performance capabilities API.
 *
 *   GET|POST       /wp/constants        Allowlisted wp-config.php constants
 *   GET|POST|DELETE /wp/htaccess-block  RemoteWP-managed .htaccess block
 *   POST           /wp/options          Allowlisted option writes
 *   POST           /wp/maintenance      Named database maintenance operations
 *
 * Every route requires the `write` permission (GET included, so an agent
 * without it receives 403 on all of them) and every mutation passes through
 * the 428 approval flow. The agent never receives raw file contents.
 *
 * @package RemoteWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Perf_API {

	/** @var RemoteWP_Auth */
	private $auth;

	/** @var RemoteWP_Permissions */
	private $permissions;

	/** @var RemoteWP_Logger */
	private $logger;

	/** @var string */
	private $namespace = REMOTEWP_API_NAMESPACE;

	public function __construct( RemoteWP_Auth $auth, RemoteWP_Permissions $permissions, RemoteWP_Logger $logger ) {
		$this->auth        = $auth;
		$this->permissions = $permissions;
		$this->logger      = $logger;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$auth = array( $this->auth, 'validate_request' );

		register_rest_route( $this->namespace, '/wp/constants', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_constants' ), 'permission_callback' => $auth ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'set_constant' ), 'permission_callback' => $auth ),
		) );

		register_rest_route( $this->namespace, '/wp/htaccess-block', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_htaccess_block' ), 'permission_callback' => $auth ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'set_htaccess_block' ), 'permission_callback' => $auth ),
			array( 'methods' => 'DELETE', 'callback' => array( $this, 'delete_htaccess_block' ), 'permission_callback' => $auth ),
		) );

		// Adds POST next to the existing read-only GET /wp/options; GET is untouched.
		register_rest_route( $this->namespace, '/wp/options', array(
			array( 'methods' => 'POST', 'callback' => array( $this, 'set_option' ), 'permission_callback' => $auth ),
		) );

		register_rest_route( $this->namespace, '/wp/maintenance', array(
			array( 'methods' => 'POST', 'callback' => array( $this, 'maintenance' ), 'permission_callback' => $auth ),
		) );
	}

	/* ------------------------------------------------------------------ *
	 * Capability 1 — constants
	 * ------------------------------------------------------------------ */

	public function get_constants( $request ) {
		$can = $this->permissions->can( 'write' );
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		$contents = $this->read_config();
		if ( is_wp_error( $contents ) ) {
			return $contents;
		}

		$constants = array();
		foreach ( RemoteWP_Wpconfig_Editor::ALLOWED as $name => $type ) {
			$state = RemoteWP_Wpconfig_Editor::inspect( $contents, $name );
			$entry = array(
				'type'          => $type,
				'in_config'     => false,
				'config_value'  => null,
				'runtime_value' => defined( $name ) ? RemoteWP_Wpconfig_Editor::safe_output( constant( $name ) ) : null,
				'editable'      => true,
			);
			if ( is_wp_error( $state ) ) {
				$entry['editable'] = false;
				$entry['note']     = $state->get_error_code();
			} elseif ( 'literal' === $state['state'] ) {
				$entry['in_config']    = true;
				$entry['config_value'] = RemoteWP_Wpconfig_Editor::safe_output( $state['value'] );
			} elseif ( 'non_literal' === $state['state'] ) {
				$entry['in_config'] = true;
				$entry['editable']  = false;
				$entry['note']      = 'non_literal_definition';
			}
			$constants[ $name ] = $entry;
		}

		$path = RemoteWP_Wpconfig_Editor::locate();
		$this->logger->log( 'WP_CONSTANTS_READ', '', 'Allowlisted constants read' );

		return rest_ensure_response( array(
			'constants'       => $constants,
			'config_writable' => $path && is_writable( $path ),
			'note'            => 'Changes take effect on the next request. Only allowlisted constants are ever exposed.',
		) );
	}

	public function set_constant( $request ) {
		$can = $this->permissions->can( 'write' );
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		$name = $request->get_param( 'name' );
		if ( ! is_string( $name ) || ! RemoteWP_Wpconfig_Editor::is_allowed( $name ) ) {
			// The submitted name is deliberately not echoed back.
			return new WP_Error( 'constant_not_allowed', __( 'The constant is not on the allowlist. Use GET /wp/constants to list the writable constants.', 'remotewp' ), array( 'status' => 400 ) );
		}

		// Validation first: an invalid value must stop everything before any write.
		$normalized = RemoteWP_Wpconfig_Editor::normalize_value( $name, $request->get_param( 'value' ) );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$path = RemoteWP_Wpconfig_Editor::locate();
		if ( ! $path || ! is_writable( $path ) ) {
			return new WP_Error( 'config_not_writable', __( 'The configuration file is not writable by the web server. The site operator must change the value manually.', 'remotewp' ), array( 'status' => 409 ) );
		}
		$original = $this->read_config();
		if ( is_wp_error( $original ) ) {
			return $original;
		}

		$updated = RemoteWP_Wpconfig_Editor::apply( $original, $name, $normalized['php'] );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$state = RemoteWP_Wpconfig_Editor::inspect( $original, $name );
		$old   = ( ! is_wp_error( $state ) && 'literal' === $state['state'] ) ? $state['value'] : null;
		if ( $updated === $original ) {
			return rest_ensure_response( array( 'success' => true, 'changed' => false, 'name' => $name, 'value' => $normalized['value'], 'backup_id' => null ) );
		}

		$approval = RemoteWP_Approval::gate(
			$request,
			$this->logger,
			'constants_set',
			array( 'name' => $name, 'value' => $normalized['value'] ),
			array( 'name' => $name, 'current_value' => RemoteWP_Wpconfig_Editor::safe_output( $old ), 'new_value' => $normalized['value'] )
		);
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}

		$expected = $normalized['value'];
		$result   = RemoteWP_Guarded_File::write(
			$path,
			$original,
			$updated,
			'constants_set',
			$this->logger->get_backup_dir(),
			static function ( $written ) use ( $original, $name, $expected ) {
				return RemoteWP_Wpconfig_Editor::verify( $original, $written, $name, $expected );
			}
		);
		if ( is_wp_error( $result ) ) {
			$this->logger->log( 'CONSTANT_SET', '', $name . ' failed: ' . $result->get_error_code(), 'error' );
			return $result;
		}

		$this->logger->log( 'CONSTANT_SET', '', $name . ': ' . wp_json_encode( $old ) . ' -> ' . wp_json_encode( $expected ) . ' | backup ' . $result['backup_id'] );

		$warnings = array();
		if ( 'WP_CACHE' === $name && true === $expected ) {
			$warnings[] = 'WP_CACHE=true has no effect without an advanced-cache.php drop-in from a cache plugin.';
		}
		if ( in_array( $name, array( 'WP_DEBUG', 'WP_DEBUG_DISPLAY' ), true ) && true === $expected ) {
			$warnings[] = 'Debug output can expose internal information to visitors. Revert when finished.';
		}

		return rest_ensure_response( array(
			'success'   => true,
			'changed'   => true,
			'name'      => $name,
			'old_value' => RemoteWP_Wpconfig_Editor::safe_output( $old ),
			'new_value' => $expected,
			'backup_id' => $result['backup_id'],
			'warnings'  => $warnings,
		) );
	}

	/* ------------------------------------------------------------------ *
	 * Capability 2 — .htaccess managed block
	 * ------------------------------------------------------------------ */

	public function get_htaccess_block( $request ) {
		$can = $this->permissions->can( 'write' );
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		$contents = $this->read_htaccess();
		if ( is_wp_error( $contents ) ) {
			return $contents;
		}
		$inner = RemoteWP_Htaccess_Editor::extract_block( $contents );
		if ( is_wp_error( $inner ) ) {
			return $inner;
		}

		$this->logger->log( 'HTACCESS_BLOCK_READ', '', 'Managed block read' );
		return rest_ensure_response( array(
			'present'    => null !== $inner,
			'directives' => null === $inner ? array() : $this->lines_of( $inner ),
			'warnings'   => $this->server_warnings(),
		) );
	}

	public function set_htaccess_block( $request ) {
		$can = $this->permissions->can( 'write' );
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		$lines = RemoteWP_Htaccess_Editor::validate( $request->get_param( 'directives' ) );
		if ( is_wp_error( $lines ) ) {
			return $lines;
		}

		$path = $this->htaccess_path();
		$ok   = $this->htaccess_writable( $path );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$original = $this->read_htaccess();
		if ( is_wp_error( $original ) ) {
			return $original;
		}
		$updated = RemoteWP_Htaccess_Editor::set_block( $original, $lines );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		if ( $updated === $original ) {
			return rest_ensure_response( array( 'success' => true, 'changed' => false, 'backup_id' => null, 'directives' => $lines ) );
		}

		$approval = RemoteWP_Approval::gate( $request, $this->logger, 'htaccess_block_set', array( 'directives' => $lines ), array( 'directives' => $lines ) );
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}

		$expected = RemoteWP_Htaccess_Editor::extract_block( $updated );
		return $this->write_htaccess( $path, $original, $updated, 'htaccess_block_set', $expected, array( 'directives' => $lines ) );
	}

	public function delete_htaccess_block( $request ) {
		$can = $this->permissions->can( 'write' );
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		$path     = $this->htaccess_path();
		$original = $this->read_htaccess();
		if ( is_wp_error( $original ) ) {
			return $original;
		}
		$updated = RemoteWP_Htaccess_Editor::remove_block( $original );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		if ( null === $updated ) {
			return rest_ensure_response( array( 'success' => true, 'removed' => false, 'backup_id' => null ) );
		}
		$ok = $this->htaccess_writable( $path );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$approval = RemoteWP_Approval::gate( $request, $this->logger, 'htaccess_block_delete', array( 'action' => 'delete' ), array( 'action' => 'remove the RemoteWP Performance block' ) );
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}

		return $this->write_htaccess( $path, $original, $updated, 'htaccess_block_delete', null, array( 'removed' => true ) );
	}

	private function write_htaccess( $path, $original, $updated, $operation, $expected_inner, array $extra ) {
		// A bad .htaccess answers 500 for the whole site, REST included, so the
		// loopback result is part of verification when the server reads .htaccess.
		$check_loopback = $this->server_reads_htaccess();
		$baseline       = $check_loopback ? $this->loopback_status() : 0;

		$result = RemoteWP_Guarded_File::write(
			$path,
			$original,
			$updated,
			$operation,
			$this->logger->get_backup_dir(),
			function ( $written ) use ( $original, $expected_inner, $check_loopback, $baseline ) {
				$ok = RemoteWP_Htaccess_Editor::verify( $original, $written, $expected_inner );
				if ( true !== $ok ) {
					return $ok;
				}
				if ( $check_loopback && $baseline > 0 && $baseline < 500 ) {
					$after = $this->loopback_status();
					if ( $after >= 500 ) {
						return new WP_Error( 'loopback_failed', __( 'The site answered with a server error after the change.', 'remotewp' ) );
					}
				}
				return true;
			}
		);
		if ( is_wp_error( $result ) ) {
			$this->logger->log( strtoupper( $operation ), '', 'failed: ' . $result->get_error_code(), 'error' );
			return $result;
		}

		$this->logger->log( strtoupper( $operation ), '', 'ok | backup ' . ( $result['backup_id'] ? $result['backup_id'] : 'none (file was new)' ) );
		return rest_ensure_response( array_merge(
			array(
				'success'   => true,
				'changed'   => true,
				'backup_id' => $result['backup_id'],
				'warnings'  => $this->server_warnings(),
			),
			$extra
		) );
	}

	/* ------------------------------------------------------------------ *
	 * Capability 3 — writable options
	 * ------------------------------------------------------------------ */

	public function set_option( $request ) {
		$can = $this->permissions->can( 'write' );
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		$name = $request->get_param( 'name' );
		if ( ! RemoteWP_Options_Writer::is_writable_name( $name ) ) {
			return new WP_Error(
				'option_not_allowed',
				sprintf( /* translators: %s: allowlist */ __( 'This option is not writable. Writable options: %s.', 'remotewp' ), RemoteWP_Options_Writer::describe_allowlist() ),
				array( 'status' => 400 )
			);
		}
		if ( null === $request->get_param( 'value' ) ) {
			return new WP_Error( 'invalid_value', __( 'value is required.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$validated = RemoteWP_Options_Writer::validate_value( $name, $request->get_param( 'value' ) );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$sentinel = new stdClass();
		$current  = get_option( $name, $sentinel );
		if ( $current === $sentinel ) {
			return new WP_Error( 'option_not_found', __( 'The option does not exist on this site. Only existing options can be changed.', 'remotewp' ), array( 'status' => 404 ) );
		}
		$new = RemoteWP_Options_Writer::merge( $current, $validated );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		if ( $new === $current ) {
			return rest_ensure_response( array( 'success' => true, 'changed' => false, 'name' => $name, 'old_value' => RemoteWP_Options_Writer::redact( $current ) ) );
		}

		$approval = RemoteWP_Approval::gate(
			$request,
			$this->logger,
			'option_set',
			array( 'name' => $name, 'value' => $validated ),
			array( 'name' => $name, 'current_value' => RemoteWP_Options_Writer::redact( $current ), 'new_value' => RemoteWP_Options_Writer::redact( $new ) )
		);
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}

		update_option( $name, $new );
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		if ( get_option( $name, $sentinel ) !== $new ) {
			$this->logger->log( 'OPTION_SET', '', $name . ' failed verification', 'error' );
			return new WP_Error( 'verification_failed', __( 'The stored value could not be confirmed.', 'remotewp' ), array( 'status' => 500 ) );
		}

		$old = RemoteWP_Options_Writer::redact( $current );
		$this->logger->log( 'OPTION_SET', '', $name . ' updated | old: ' . wp_json_encode( $old ) );

		return rest_ensure_response( array(
			'success'   => true,
			'changed'   => true,
			'name'      => $name,
			'old_value' => $old,
			'new_value' => RemoteWP_Options_Writer::redact( $new ),
			'note'      => 'Record old_value in the handoff to allow a rollback. Credential-like sub-keys are never returned.',
		) );
	}

	/* ------------------------------------------------------------------ *
	 * Capability 4 — database maintenance
	 * ------------------------------------------------------------------ */

	public function maintenance( $request ) {
		$can = $this->permissions->can( 'write' );
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		$operation = $request->get_param( 'operation' );
		$params    = RemoteWP_Maintenance::validate( $operation, $request->get_param( 'params' ) );
		if ( is_wp_error( $params ) ) {
			return $params;
		}

		// dry_run defaults to true: executing must be an explicit decision.
		$dry_param = $request->get_param( 'dry_run' );
		$dry_run   = null === $dry_param ? true : filter_var( $dry_param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( null === $dry_run ) {
			return new WP_Error( 'invalid_params', __( 'dry_run must be true or false.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$counted = RemoteWP_Maintenance::count( $operation, $params );

		if ( $dry_run ) {
			$this->logger->log( 'MAINTENANCE_DRY_RUN', '', $operation . ': ' . (int) $counted['count'] );
			return rest_ensure_response( array_merge(
				array(
					'success'   => true,
					'dry_run'   => true,
					'operation' => $operation,
					'params'    => $params,
					'modified'  => false,
					'warning'   => RemoteWP_Maintenance::warning(),
				),
				$counted
			) );
		}

		$approval = RemoteWP_Approval::gate(
			$request,
			$this->logger,
			'maintenance_' . $operation,
			array( 'operation' => $operation, 'params' => $params ),
			array_merge( array( 'operation' => $operation, 'params' => $params, 'would_affect' => $counted['count'], 'warning' => RemoteWP_Maintenance::warning() ) )
		);
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}

		$result = RemoteWP_Maintenance::run( $operation, $params );
		if ( is_wp_error( $result ) ) {
			$this->logger->log( 'MAINTENANCE_RUN', '', $operation . ' failed', 'error' );
			return $result;
		}
		$this->logger->log( 'MAINTENANCE_RUN', '', $operation . ': ' . wp_json_encode( $result ) );

		return rest_ensure_response( array_merge(
			array(
				'success'   => true,
				'dry_run'   => false,
				'operation' => $operation,
				'warning'   => RemoteWP_Maintenance::warning(),
			),
			$result
		) );
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function read_config() {
		$path = RemoteWP_Wpconfig_Editor::locate();
		if ( ! $path || ! is_readable( $path ) ) {
			return new WP_Error( 'config_unavailable', __( 'The configuration file could not be located or read.', 'remotewp' ), array( 'status' => 409 ) );
		}
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return new WP_Error( 'config_unavailable', __( 'The configuration file could not be located or read.', 'remotewp' ), array( 'status' => 409 ) );
		}
		return $contents;
	}

	private function htaccess_path() {
		return rtrim( ABSPATH, '/\\' ) . '/.htaccess';
	}

	private function read_htaccess() {
		$path = $this->htaccess_path();
		if ( ! file_exists( $path ) ) {
			return '';
		}
		$contents = is_readable( $path ) ? file_get_contents( $path ) : false;
		if ( false === $contents ) {
			return new WP_Error( 'htaccess_unreadable', __( 'The .htaccess file could not be read.', 'remotewp' ), array( 'status' => 409 ) );
		}
		return $contents;
	}

	private function htaccess_writable( $path ) {
		$writable = file_exists( $path ) ? is_writable( $path ) : is_writable( dirname( $path ) );
		if ( ! $writable ) {
			return new WP_Error( 'htaccess_not_writable', __( 'The .htaccess file is not writable by the web server.', 'remotewp' ), array( 'status' => 409 ) );
		}
		return true;
	}

	private function lines_of( $inner ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\n/', $inner ) ), 'strlen' ) );
	}

	private function server_software() {
		return isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
	}

	private function server_reads_htaccess() {
		$software = $this->server_software();
		return false !== strpos( $software, 'apache' ) || false !== strpos( $software, 'litespeed' );
	}

	private function server_warnings() {
		if ( '' !== $this->server_software() && ! $this->server_reads_htaccess() ) {
			return array( 'The web server does not appear to be Apache or LiteSpeed; .htaccess directives may have no effect.' );
		}
		return array();
	}

	/**
	 * HTTP status of the home page, or 0 when the loopback request is not possible.
	 */
	private function loopback_status() {
		$response = wp_remote_get( home_url( '/' ), array( 'timeout' => 8, 'redirection' => 0, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}
}
