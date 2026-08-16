<?php
/**
 * Coordinates idempotent RemoteWP mutations and per-resource locks.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Operation_Coordinator {

	/**
	 * Claim an operation and its resource lock.
	 *
	 * Idempotency is enforced when the caller supplies an idempotency key. A
	 * generated request ID is still returned for tracing when the key is absent.
	 *
	 * @param string           $storage_dir Storage directory.
	 * @param WP_REST_Request  $request     Request object.
	 * @param string           $operation   Operation name.
	 * @param string           $resource    Canonical resource identifier.
	 * @return array|WP_Error Context, or a replay response in `replay`.
	 */
	public static function begin( $storage_dir, $request, $operation, $resource ) {
		$key       = self::request_value( $request, 'idempotency_key', 'X-RemoteWP-Idempotency-Key' );
		$request_id = self::request_value( $request, 'operation_id', 'X-RemoteWP-Operation-ID' );
		if ( empty( $request_id ) ) {
			$request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'remotewp_', true );
		}
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $request_id ) ) {
			return new WP_Error( 'invalid_operation_id', __( 'operation_id contains invalid characters or is too long.', 'remotewp' ), array( 'status' => 400 ) );
		}

		if ( ! empty( $key ) && ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $key ) ) {
			return new WP_Error( 'invalid_idempotency_key', __( 'idempotency_key contains invalid characters or is too long.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$lock_dir = trailingslashit( $storage_dir ) . 'locks';
		if ( ! is_dir( $lock_dir ) && ! wp_mkdir_p( $lock_dir ) ) {
			return new WP_Error( 'lock_error', __( 'Could not create the operation lock directory.', 'remotewp' ), array( 'status' => 500 ) );
		}
		$operations_dir = trailingslashit( $storage_dir ) . 'operations';
		if ( ! is_dir( $operations_dir ) && ! wp_mkdir_p( $operations_dir ) ) {
			return new WP_Error( 'operation_status_error', __( 'Could not create the operation status directory.', 'remotewp' ), array( 'status' => 500 ) );
		}
		$status_path = trailingslashit( $operations_dir ) . hash( 'sha256', (string) $request_id ) . '.json';

		$idempotency_lock = null;
		$state_path       = null;
		if ( ! empty( $key ) ) {
			$idempotency_dir = trailingslashit( $storage_dir ) . 'idempotency';
			if ( ! is_dir( $idempotency_dir ) && ! wp_mkdir_p( $idempotency_dir ) ) {
				return new WP_Error( 'idempotency_error', __( 'Could not create the idempotency store.', 'remotewp' ), array( 'status' => 500 ) );
			}
			$key_hash          = hash( 'sha256', strtolower( $operation ) . '|' . $key );
			$state_path        = trailingslashit( $idempotency_dir ) . $key_hash . '.json';
			$idempotency_lock  = self::open_lock( $state_path . '.lock' );
			if ( false === $idempotency_lock ) {
				return new WP_Error( 'operation_in_progress', __( 'This idempotency key is already being processed.', 'remotewp' ), array( 'status' => 409 ) );
			}

			$state = self::read_json( $state_path );
			if ( is_array( $state ) && in_array( $state['state'] ?? '', array( 'completed', 'committed' ), true ) ) {
				self::close_lock( $idempotency_lock );
				return array(
					'replay'     => true,
					'response'   => new WP_REST_Response( $state['data'], (int) $state['status'] ),
					'operation_id' => isset( $state['operation_id'] ) ? $state['operation_id'] : $request_id,
				);
			}
		}

		$resources = is_array( $resource ) ? array_values( $resource ) : array( $resource );
		sort( $resources, SORT_STRING );
		$resource_locks = array();
		foreach ( $resources as $resource_item ) {
			$resource_hash = hash( 'sha256', (string) $resource_item );
			$resource_lock = self::open_lock( trailingslashit( $lock_dir ) . $resource_hash . '.lock' );
			if ( false === $resource_lock ) {
				foreach ( $resource_locks as $held_lock ) {
					self::close_lock( $held_lock );
				}
				self::close_lock( $idempotency_lock );
				return new WP_Error( 'resource_locked', __( 'The target resource is currently being modified.', 'remotewp' ), array( 'status' => 409 ) );
			}
			$resource_locks[] = $resource_lock;
		}
		if ( empty( $resource_locks ) ) {
			self::close_lock( $idempotency_lock );
			return new WP_Error( 'invalid_resource', __( 'A resource is required for the operation lock.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$context = array(
			'operation_id'      => sanitize_text_field( $request_id ),
			'idempotency_key'   => sanitize_text_field( $key ),
			'resource'          => $resources,
			'resource_locks'    => $resource_locks,
			'idempotency_lock'  => $idempotency_lock,
			'state_path'        => $state_path,
			'status_path'       => $status_path,
			'storage_dir'       => $storage_dir,
			'operation'         => sanitize_key( $operation ),
		);

		$state = array(
			'state'        => 'in_progress',
			'phase'        => 'locked',
			'operation'    => $context['operation'],
			'operation_id' => $context['operation_id'],
			'created_at'   => current_time( 'c' ),
			'updated_at'   => current_time( 'c' ),
			'state_history'=> array( array( 'state' => 'locked', 'at' => current_time( 'c' ) ) ),
		);
		if ( $state_path ) {
			$write_result = self::write_json( $state_path, $state );
			if ( is_wp_error( $write_result ) ) {
				foreach ( $resource_locks as $held_lock ) {
					self::close_lock( $held_lock );
				}
				self::close_lock( $idempotency_lock );
				return $write_result;
			}
		}
		$status_result = self::write_json( $status_path, $state );
		if ( is_wp_error( $status_result ) ) {
			if ( $state_path ) {
				@unlink( $state_path );
			}
			foreach ( $resource_locks as $held_lock ) {
				self::close_lock( $held_lock );
			}
			self::close_lock( $idempotency_lock );
			return $status_result;
		}
		self::close_lock( $idempotency_lock );
		$context['idempotency_lock'] = null;

		return $context;
	}

	/**
	 * Mark a claimed operation as completed and release its lock.
	 *
	 * @param array                 $context Context from begin().
	 * @param WP_REST_Response|mixed $response Response to cache.
	 * @return void
	 */
	public static function complete( $context, $response ) {
		$data   = $response instanceof WP_REST_Response ? $response->get_data() : $response;
		$status = $response instanceof WP_REST_Response ? $response->get_status() : 200;
		$previous = ! empty( $context['status_path'] ) ? self::read_json( $context['status_path'] ) : null;
		$history  = self::history_with( $context, 'committed' );
		$state  = array(
			'state'        => 'completed',
			'phase'        => 'committed',
			'operation'    => $context['operation'],
			'operation_id' => $context['operation_id'],
			'completed_at' => current_time( 'c' ),
			'updated_at'   => current_time( 'c' ),
			'status'       => (int) $status,
			'data'         => $data,
			'state_history'=> $history,
		);
		foreach ( array( 'backup_id', 'verification_status' ) as $metadata_key ) {
			if ( is_array( $previous ) && isset( $previous[ $metadata_key ] ) ) {
				$state[ $metadata_key ] = $previous[ $metadata_key ];
			}
		}
		if ( ! empty( $context['state_path'] ) ) {
			self::write_json( $context['state_path'], $state );
		}
		self::write_json( $context['status_path'], $state );
		self::release( $context );
	}

	/**
	 * Abandon a claimed operation after a failed mutation.
	 *
	 * @param array $context Context from begin().
	 * @return void
	 */
	public static function abort( $context ) {
		$state = array(
			'state'         => 'failed',
			'phase'         => 'failed',
			'operation'     => $context['operation'],
			'operation_id'  => $context['operation_id'],
			'updated_at'    => current_time( 'c' ),
			'state_history' => self::history_with( $context, 'failed' ),
		);
		self::write_json( $context['status_path'], $state );
		if ( ! empty( $context['state_path'] ) ) {
			@unlink( $context['state_path'] );
		}
		self::release( $context );
	}

	/**
	 * Append an explicit operation phase without changing the v1 state field.
	 *
	 * @param array  $context Operation context.
	 * @param string $phase   received|authorized|locked|validated|backed_up|applied|verifying|committed|rejected|conflict|failed|rolled_back.
	 * @param array  $extra   Safe metadata to persist with the phase.
	 * @return true|WP_Error
	 */
	public static function transition( $context, $phase, $extra = array() ) {
		$allowed = array( 'received', 'authorized', 'locked', 'validated', 'backed_up', 'applied', 'verifying', 'committed', 'rejected', 'conflict', 'failed', 'rolled_back' );
		if ( ! in_array( $phase, $allowed, true ) ) {
			return new WP_Error( 'invalid_operation_state', __( 'Invalid operation state.', 'remotewp' ), array( 'status' => 400 ) );
		}
		$state = self::read_json( $context['status_path'] );
		$state = is_array( $state ) ? $state : array(
			'state'        => 'in_progress',
			'operation'    => $context['operation'],
			'operation_id' => $context['operation_id'],
		);
		$state['phase']      = $phase;
		$state['updated_at'] = current_time( 'c' );
		$state['state_history'] = self::append_history( isset( $state['state_history'] ) ? $state['state_history'] : array(), $phase, $extra );
		foreach ( (array) $extra as $key => $value ) {
			if ( in_array( $key, array( 'backup_id', 'verification_status', 'error_code' ), true ) ) {
				$state[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $value;
			}
		}
		$status_result = self::write_json( $context['status_path'], $state );
		if ( is_wp_error( $status_result ) ) {
			return $status_result;
		}
		if ( ! empty( $context['state_path'] ) ) {
			self::write_json( $context['state_path'], $state );
		}
		return true;
	}

	/**
	 * Read the status for an operation ID.
	 *
	 * @param string          $storage_dir Storage directory.
	 * @param WP_REST_Request $request     Request object.
	 * @return array|WP_Error
	 */
	public static function get_status( $storage_dir, $request ) {
		$operation_id = self::request_value( $request, 'operation_id', 'X-RemoteWP-Operation-ID' );
		if ( empty( $operation_id ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $operation_id ) ) {
			return new WP_Error( 'invalid_operation_id', __( 'A valid operation_id is required.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$path  = trailingslashit( $storage_dir ) . 'operations/' . hash( 'sha256', $operation_id ) . '.json';
		$state = self::read_json( $path );
		if ( ! is_array( $state ) || ! isset( $state['operation_id'] ) || ! hash_equals( $operation_id, (string) $state['operation_id'] ) ) {
			return new WP_Error( 'not_found', __( 'Operation status not found.', 'remotewp' ), array( 'status' => 404 ) );
		}

		return array(
			'operation_id' => $state['operation_id'],
			'operation'    => isset( $state['operation'] ) ? $state['operation'] : '',
			'state'        => isset( $state['state'] ) ? $state['state'] : 'unknown',
			'phase'        => isset( $state['phase'] ) ? $state['phase'] : ( isset( $state['state'] ) ? $state['state'] : 'unknown' ),
			'created_at'   => isset( $state['created_at'] ) ? $state['created_at'] : null,
			'completed_at' => isset( $state['completed_at'] ) ? $state['completed_at'] : null,
			'status'       => isset( $state['status'] ) ? (int) $state['status'] : null,
			'data'         => isset( $state['data'] ) ? $state['data'] : null,
			'backup_id'    => isset( $state['backup_id'] ) ? $state['backup_id'] : null,
			'verification_status' => isset( $state['verification_status'] ) ? $state['verification_status'] : null,
			'state_history'=> isset( $state['state_history'] ) ? $state['state_history'] : array(),
		);
	}

	private static function history_with( $context, $phase ) {
		$state = ! empty( $context['status_path'] ) ? self::read_json( $context['status_path'] ) : null;
		$history = is_array( $state ) && isset( $state['state_history'] ) ? $state['state_history'] : array();
		return self::append_history( $history, $phase, array() );
	}

	private static function append_history( $history, $phase, $extra = array() ) {
		$entry = array( 'state' => $phase, 'at' => current_time( 'c' ) );
		foreach ( (array) $extra as $key => $value ) {
			if ( in_array( $key, array( 'backup_id', 'verification_status', 'error_code' ), true ) && is_scalar( $value ) ) {
				$entry[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		$history   = is_array( $history ) ? $history : array();
		$history[] = $entry;
		return array_slice( $history, -20 );
	}

	private static function release( $context ) {
		if ( ! empty( $context['resource_locks'] ) ) {
			foreach ( $context['resource_locks'] as $resource_lock ) {
				self::close_lock( $resource_lock );
			}
		} elseif ( ! empty( $context['resource_lock'] ) ) {
			self::close_lock( $context['resource_lock'] );
		}
		if ( ! empty( $context['idempotency_lock'] ) ) {
			self::close_lock( $context['idempotency_lock'] );
		}
	}

	private static function request_value( $request, $param, $header ) {
		$value = $request->get_param( $param );
		if ( empty( $value ) && method_exists( $request, 'get_header' ) ) {
			$value = $request->get_header( $header );
		}
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private static function open_lock( $path ) {
		$handle = @fopen( $path, 'c' );
		if ( false === $handle || ! @flock( $handle, LOCK_EX | LOCK_NB ) ) {
			if ( is_resource( $handle ) ) {
				@fclose( $handle );
			}
			return false;
		}
		return $handle;
	}

	private static function close_lock( $handle ) {
		if ( is_resource( $handle ) ) {
			@flock( $handle, LOCK_UN );
			@fclose( $handle );
		}
	}

	private static function read_json( $path ) {
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$content = file_get_contents( $path );
		$data    = false === $content ? null : json_decode( $content, true );
		return is_array( $data ) ? $data : null;
	}

	private static function write_json( $path, $data ) {
		$content = function_exists( 'wp_json_encode' )
			? wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
			: json_encode( $data );
		$result = RemoteWP_Operation_Safety::atomic_write( $path, $content . PHP_EOL );
		return is_wp_error( $result ) ? $result : true;
	}
}
