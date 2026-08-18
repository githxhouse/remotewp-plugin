<?php
/**
 * RemoteWP Handoff Relay API.
 *
 * Accepts an authenticated site-token request and lets the installed plugin
 * submit the redacted handoff through its encrypted license connection. The
 * Master/Agency key never leaves the plugin/server transport and is never
 * exposed to the AI agent.
 *
 * @package RemoteWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Handoff_API {
	/** @var RemoteWP_Auth */
	private $auth;

	/** @var RemoteWP_License */
	private $license;

	public function __construct( RemoteWP_Auth $auth, RemoteWP_License $license ) {
		$this->auth    = $auth;
		$this->license = $license;
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			REMOTEWP_API_V2_NAMESPACE,
			'/handoff/context',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'context' ),
				'permission_callback' => array( $this->auth, 'validate_request' ),
			)
		);
		register_rest_route(
			REMOTEWP_API_V2_NAMESPACE,
			'/handoff/log',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => array( $this->auth, 'validate_request' ),
			)
		);
	}

	public function context() {
		$result = $this->license->get_handoff_context();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( array( 'ok' => true, 'success' => true, 'data' => $result ), 200 );
	}

	public function submit( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'invalid_handoff_payload', __( 'A JSON handoff payload is required.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$allowed = array( 'agent_identity_id', 'agent_name', 'task_title', 'client_summary', 'technical_log', 'status' );
		$handoff = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				$handoff[ $key ] = $payload[ $key ];
			}
		}

		$result = $this->license->submit_handoff_log( $handoff );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'success' => true,
				'message' => __( 'Handoff accepted by RemoteWP Central.', 'remotewp' ),
			),
			201
		);
	}
}
