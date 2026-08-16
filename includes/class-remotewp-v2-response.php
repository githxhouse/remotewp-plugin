<?php
/**
 * Common response envelope for the additive RemoteWP v2 API.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_V2_Response {

	public static function success( $response, $request ) {
		if ( $response instanceof WP_REST_Response ) {
			$data   = $response->get_data();
			$status = $response->get_status();
		} else {
			$data   = $response;
			$status = 200;
		}

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'request_id' => self::request_id( $request ),
				'context'    => RemoteWP_Connection_Context::build(),
				'data'       => $data,
				'warnings'   => array(),
			),
			$status
		);
	}

	public static function error( $error, $request ) {
		$status = 500;
		$data   = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$status = (int) $data['status'];
		}

		return new WP_REST_Response(
			array(
				'ok'         => false,
				'request_id' => self::request_id( $request ),
				'context'    => RemoteWP_Connection_Context::build(),
				'data'       => null,
				'warnings'   => array(),
				'error'      => array(
					'code'    => $error->get_error_code(),
					'message' => $error->get_error_message(),
					'details' => is_array( $data ) ? $data : null,
				),
			),
			$status
		);
	}

	public static function wrap( $response, $request ) {
		if ( is_wp_error( $response ) ) {
			return self::error( $response, $request );
		}
		return self::success( $response, $request );
	}

	public static function request_id( $request ) {
		$request_id = $request->get_param( 'request_id' );
		if ( empty( $request_id ) && method_exists( $request, 'get_header' ) ) {
			$request_id = $request->get_header( 'X-RemoteWP-Request-ID' );
		}
		if ( ! is_string( $request_id ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $request_id ) ) {
			$request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'remotewp_', true );
		}
		return sanitize_text_field( $request_id );
	}

	/**
	 * Attach the correlation ID to a REST response header.
	 *
	 * The header is deliberately limited to RemoteWP routes and contains no
	 * token, domain, or request payload. It lets an agent correlate a response
	 * with WordPress/Wordfence/cPanel logs without exposing tenant data.
	 *
	 * @param mixed            $response REST response.
	 * @param WP_REST_Request  $request  Incoming request.
	 * @return mixed
	 */
	public static function attach_request_id_header( $response, $request ) {
		if ( $response instanceof WP_HTTP_Response && $request instanceof WP_REST_Request ) {
			$response->header( 'X-RemoteWP-Request-ID', self::request_id( $request ) );
		}

		return $response;
	}
}
