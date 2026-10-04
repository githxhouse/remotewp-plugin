<?php
/**
 * Generic 428 approval gate for named (non-file) mutations.
 *
 * Same contract as the existing file-approval flow (approval_request_id,
 * dangerous_operation_approved, approval_note, 30 minute TTL), but the
 * fingerprint is bound to the complete, canonicalised payload of the operation
 * so an approval can never be replayed for a different value.
 *
 * @package RemoteWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Approval {

	const TTL = 1800;

	/**
	 * Gate a mutation behind explicit operator approval.
	 *
	 * @param WP_REST_Request $request   Incoming request.
	 * @param RemoteWP_Logger $logger    Logger.
	 * @param string          $operation Operation name (e.g. constants_set).
	 * @param array           $payload   Exact mutation payload that is being approved.
	 * @param array           $context   Safe, human-readable data echoed in the 428 response.
	 * @return array|WP_Error Approval record on success, 428/409 WP_Error otherwise.
	 */
	public static function gate( $request, $logger, $operation, array $payload, array $context = array() ) {
		$operation   = sanitize_key( $operation );
		$fingerprint = self::fingerprint( $operation, $payload );
		$request_id  = trim( (string) $request->get_param( 'approval_request_id' ) );
		$approved    = filter_var( $request->get_param( 'dangerous_operation_approved' ), FILTER_VALIDATE_BOOLEAN );
		$note        = trim( (string) $request->get_param( 'approval_note' ) );

		if ( ! $approved || '' === $request_id ) {
			$record = self::create( $operation, $fingerprint );
			$logger->log( strtoupper( $operation ) . '_APPROVAL_REQUESTED', '', 'Approval request: ' . $record['approval_request_id'], 'warning' );

			return new WP_Error(
				'approval_required',
				__( 'This change requires explicit approval. Explain the exact change to the site owner and resend the identical request with dangerous_operation_approved=true, approval_request_id and approval_note.', 'remotewp' ),
				array(
					'status'              => 428,
					'approval_required'   => true,
					'approval_param'      => 'dangerous_operation_approved',
					'approval_note_param' => 'approval_note',
					'approval_request_id' => $record['approval_request_id'],
					'approval_expires_at' => $record['expires_at'],
					'operation'           => $operation,
					'context'             => $context,
				)
			);
		}

		$stored = get_transient( self::transient_key( $request_id ) );
		if ( ! is_array( $stored ) ) {
			return new WP_Error(
				'approval_request_expired',
				__( 'The approval request expired or was not found. Ask the site owner for approval again.', 'remotewp' ),
				array( 'status' => 428, 'approval_required' => true )
			);
		}

		if ( empty( $stored['fingerprint'] ) || ! hash_equals( (string) $stored['fingerprint'], $fingerprint ) ) {
			return new WP_Error(
				'approval_request_mismatch',
				__( 'The approved request does not match this mutation. Ask the site owner for approval again for the exact change.', 'remotewp' ),
				array( 'status' => 409, 'approval_required' => true )
			);
		}

		if ( strlen( $note ) < 8 ) {
			return new WP_Error(
				'approval_note_required',
				__( 'approval_note must describe the site owner approval (minimum 8 characters).', 'remotewp' ),
				array( 'status' => 428, 'approval_required' => true, 'approval_note_param' => 'approval_note' )
			);
		}

		delete_transient( self::transient_key( $request_id ) );
		$logger->log( strtoupper( $operation ) . '_APPROVAL_CONFIRMED', '', 'Approval confirmed: ' . $request_id . ' | ' . sanitize_text_field( $note ) );

		return array(
			'approval_request_id' => $request_id,
			'approved_at'         => current_time( 'c' ),
			'approval_note'       => sanitize_text_field( $note ),
		);
	}

	/**
	 * Canonical fingerprint of an operation and its payload.
	 *
	 * @param string $operation Operation name.
	 * @param array  $payload   Payload.
	 * @return string
	 */
	public static function fingerprint( $operation, array $payload ) {
		return hash( 'sha256', $operation . '|' . self::canonical_json( $payload ) );
	}

	private static function canonical_json( $value ) {
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $is_list ) {
				ksort( $value );
			}
			foreach ( $value as $k => $v ) {
				$value[ $k ] = json_decode( self::canonical_json( $v ), true );
			}
		}
		return json_encode( $value );
	}

	private static function create( $operation, $fingerprint ) {
		$request_id = 'rwa_' . substr( hash( 'sha256', wp_generate_uuid4() . '|' . microtime( true ) . '|' . wp_rand() ), 0, 32 );
		$record     = array(
			'approval_request_id' => $request_id,
			'operation'           => $operation,
			'fingerprint'         => $fingerprint,
			'created_at'          => current_time( 'c' ),
			'expires_at'          => gmdate( 'c', time() + self::TTL ),
		);
		set_transient( self::transient_key( $request_id ), $record, self::TTL );
		return $record;
	}

	private static function transient_key( $request_id ) {
		return 'remotewp_approval_' . hash( 'sha256', $request_id );
	}
}
