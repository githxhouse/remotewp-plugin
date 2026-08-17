<?php
/**
 * OpenAPI contract for the additive RemoteWP v2 API.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_V2_Contract {

	public static function schema() {
		return array(
			'openapi' => '3.0.3',
			'info'    => array(
				'title'       => 'RemoteWP API v2',
				'version'     => defined( 'REMOTEWP_VERSION' ) ? REMOTEWP_VERSION : '2.0.0',
				'description' => 'Additive, authenticated RemoteWP read and patch contract. v1 remains supported separately.',
			),
			'paths'   => array(
				'/wp-json/remotewp/v2/read' => array(
					'get' => array(
						'summary'     => 'Read file metadata and issue a short-lived content handle',
						'security'    => self::token_security(),
						'parameters'  => array( self::path_parameter() ),
						'responses'   => array( '200' => self::success_response( '#/components/schemas/ReadData' ), '400' => self::error_response(), '401' => self::error_response(), '404' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/fs/list' => array(
					'post' => array(
						'summary'     => 'List a directory using the centralized path policy',
						'security'    => self::token_security(),
						'parameters'  => array( self::path_parameter() ),
						'responses'   => array( '200' => self::success_response( '#/components/schemas/ListData' ), '400' => self::error_response(), '401' => self::error_response(), '403' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/fs/read' => array(
					'post' => array(
						'summary'     => 'Read file metadata and issue a short-lived content handle',
						'security'    => self::token_security(),
						'parameters'  => array( self::path_parameter() ),
						'responses'   => array( '200' => self::success_response( '#/components/schemas/ReadData' ), '400' => self::error_response(), '401' => self::error_response(), '404' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/fs/search' => array(
					'post' => array(
						'summary'     => 'Search approved filesystem content',
						'security'    => self::token_security(),
						'responses'   => array( '200' => self::success_response( '#/components/schemas/SearchData' ), '401' => self::error_response(), '403' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/fs/validate' => array(
					'post' => array(
						'summary'    => 'Validate a path against the read policy without exposing an absolute path',
						'security'   => self::token_security(),
						'responses'  => array( '200' => self::success_response( '#/components/schemas/PathValidation' ), '400' => self::error_response(), '401' => self::error_response(), '403' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/fs/patch' => array(
					'post' => array(
						'summary'     => 'Alias for the exact patch mutation endpoint',
						'security'    => self::token_security(),
						'requestBody' => array( 'required' => true, 'content' => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/PatchRequest' ) ) ) ),
						'responses'   => array( '200' => self::success_response( '#/components/schemas/PatchData' ), '400' => self::error_response(), '401' => self::error_response(), '409' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/fs/write' => array(
					'post' => array(
						'summary'     => 'Create or replace a file with mandatory optimistic concurrency for existing files',
						'security'    => self::token_security(),
						'requestBody' => array( 'required' => true, 'content' => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/WriteRequest' ) ) ) ),
						'responses'   => array( '200' => self::success_response( '#/components/schemas/MutationData' ), '400' => self::error_response(), '401' => self::error_response(), '409' => self::error_response(), '428' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/fs/mkdir' => array(
					'post' => array(
						'summary'     => 'Create a directory through the centralized path policy',
						'security'    => self::token_security(),
						'requestBody' => array( 'required' => true, 'content' => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/MkdirRequest' ) ) ) ),
						'responses'   => array( '200' => self::success_response( '#/components/schemas/MutationData' ), '400' => self::error_response(), '401' => self::error_response(), '403' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/fs/rename' => array(
					'post' => array(
						'summary'     => 'Rename a file or directory with optimistic concurrency for existing files',
						'security'    => self::token_security(),
						'requestBody' => array( 'required' => true, 'content' => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/RenameRequest' ) ) ) ),
						'responses'   => array( '200' => self::success_response( '#/components/schemas/MutationData' ), '400' => self::error_response(), '401' => self::error_response(), '409' => self::error_response(), '428' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/fs/delete' => array(
					'post' => array(
						'summary'     => 'Delete a file or empty directory with optimistic concurrency for existing files',
						'security'    => self::token_security(),
						'requestBody' => array( 'required' => true, 'content' => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/DeleteRequest' ) ) ) ),
						'responses'   => array( '200' => self::success_response( '#/components/schemas/MutationData' ), '401' => self::error_response(), '404' => self::error_response(), '409' => self::error_response(), '428' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/fs/restore' => array(
					'post' => array(
						'summary'     => 'Restore a verified backup with optimistic concurrency for existing files',
						'security'    => self::token_security(),
						'requestBody' => array( 'required' => true, 'content' => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/RestoreRequest' ) ) ) ),
						'responses'   => array( '200' => self::success_response( '#/components/schemas/MutationData' ), '401' => self::error_response(), '404' => self::error_response(), '409' => self::error_response(), '428' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/content/{handle}' => array(
					'get' => array(
						'summary'    => 'Resolve an authenticated short-lived content handle',
						'security'   => self::token_security(),
						'parameters' => array( array( 'name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'string' ) ) ),
						'responses'  => array( '200' => self::success_response( '#/components/schemas/ContentData' ), '401' => self::error_response(), '404' => self::error_response(), '410' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/patch' => array(
					'post' => array(
						'summary'     => 'Apply exact find/replace operations with optimistic concurrency',
						'security'    => self::token_security(),
						'requestBody' => array( 'required' => true, 'content' => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/PatchRequest' ) ) ) ),
						'responses'   => array( '200' => self::success_response( '#/components/schemas/PatchData' ), '400' => self::error_response(), '401' => self::error_response(), '409' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/operations/{operation_id}' => array(
					'get' => array(
						'summary'    => 'Read the phase history and verification status of a mutation',
						'security'   => self::token_security(),
						'parameters' => array( array( 'name' => 'operation_id', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9._:-]{1,128}$' ) ) ),
						'responses'  => array( '200' => self::success_response( '#/components/schemas/OperationStatus' ), '401' => self::error_response(), '404' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/health' => array(
					'get' => array(
						'summary'   => 'Run authenticated deterministic health checks',
						'security'  => self::token_security(),
						'responses' => array( '200' => self::success_response( '#/components/schemas/HealthData' ), '401' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/status' => array(
					'get' => array(
						'summary'   => 'Return authenticated plugin and server status',
						'security'  => self::token_security(),
						'responses' => array( '200' => self::success_response( '#/components/schemas/StatusData' ), '401' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/wp/info' => array(
					'get' => array(
						'summary'   => 'Return safe WordPress site information',
						'security'  => self::token_security(),
						'responses' => array( '200' => self::success_response( '#/components/schemas/WPInfoData' ), '401' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/context' => array(
					'get' => array(
						'summary'    => 'Return site identity, authorization scopes and capabilities',
						'security'   => self::token_security(),
						'responses'  => array( '200' => self::success_response( '#/components/schemas/ConnectionContext' ), '401' => self::error_response() ),
					),
				),
				'/wp-json/remotewp/v2/tokens' => array(
					'get'  => array( 'summary' => 'List token metadata (administrator only)', 'security' => array( array( 'wordpressCookie' => array() ) ), 'responses' => array( '200' => self::success_response( '#/components/schemas/TokenList' ), '403' => self::error_response() ) ),
					'post' => array( 'summary' => 'Issue a v2 token (administrator only)', 'security' => array( array( 'wordpressCookie' => array() ) ), 'responses' => array( '200' => self::success_response( '#/components/schemas/IssuedToken' ), '403' => self::error_response() ) ),
				),
				'/wp-json/remotewp/v2/tokens/{token_id}' => array(
					'delete' => array( 'summary' => 'Revoke a v2 token (administrator only)', 'security' => array( array( 'wordpressCookie' => array() ) ), 'parameters' => array( array( 'name' => 'token_id', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'string' ) ) ), 'responses' => array( '200' => self::success_response( '#/components/schemas/RevokedToken' ), '403' => self::error_response(), '404' => self::error_response() ) ),
				),
			),
			'components' => array(
				'securitySchemes' => array(
					'remoteWpToken'   => array( 'type' => 'apiKey', 'in' => 'header', 'name' => 'X-RemoteWP-Token', 'description' => 'Legacy compatibility token header.' ),
					'remoteWpV2Token' => array( 'type' => 'apiKey', 'in' => 'header', 'name' => 'X-RemoteWP-V2-Token', 'description' => 'Hash-only site-bound v2 token header.' ),
					'wordpressCookie' => array( 'type' => 'apiKey', 'in' => 'cookie', 'name' => 'wordpress_logged_in' ),
				),
				'schemas' => array(
					'Envelope' => array( 'type' => 'object', 'required' => array( 'ok', 'request_id', 'context', 'data', 'warnings' ), 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'request_id' => array( 'type' => 'string' ), 'context' => array( '$ref' => '#/components/schemas/ConnectionContext' ), 'data' => array( 'nullable' => true ), 'warnings' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ) ),
					'ConnectionContext' => array( 'type' => 'object', 'properties' => array( 'site' => array( 'type' => 'object' ), 'connection' => array( 'type' => 'object' ), 'authorization' => array( 'type' => 'object' ), 'capabilities' => array( 'type' => 'object' ) ) ),
					'ReadData' => array( 'type' => 'object', 'properties' => array( 'path' => array( 'type' => 'string' ), 'size' => array( 'type' => 'integer' ), 'sha256' => array( 'type' => 'string' ), 'content_handle' => array( 'type' => 'string' ), 'handle_expires_at' => array( 'type' => 'integer' ), 'redacted' => array( 'type' => 'boolean' ), 'redaction_version' => array( 'type' => 'integer' ) ) ),
					'ListData' => array( 'type' => 'object', 'properties' => array( 'path' => array( 'type' => 'string' ), 'count' => array( 'type' => 'integer' ), 'files' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
					'SearchData' => array( 'type' => 'object', 'properties' => array( 'results' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ), 'count' => array( 'type' => 'integer' ) ) ),
					'PathValidation' => array( 'type' => 'object', 'properties' => array( 'path' => array( 'type' => 'string' ), 'canonical' => array( 'type' => 'string' ), 'exists' => array( 'type' => 'boolean' ), 'type' => array( 'type' => 'string' ), 'readable' => array( 'type' => 'boolean' ) ) ),
					'ContentData' => array( 'type' => 'object', 'properties' => array( 'path' => array( 'type' => 'string' ), 'content' => array( 'type' => 'string' ), 'redacted' => array( 'type' => 'boolean' ), 'expires_at' => array( 'type' => 'integer' ) ) ),
					'PatchRequest' => array( 'type' => 'object', 'required' => array( 'path', 'expected_sha256', 'patch' ), 'properties' => array( 'path' => array( 'type' => 'string' ), 'expected_sha256' => array( 'type' => 'string', 'pattern' => '^[a-fA-F0-9]{64}$' ), 'idempotency_key' => array( 'type' => 'string' ), 'patch' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => array( 'type' => 'object', 'required' => array( 'find', 'replace' ), 'properties' => array( 'find' => array( 'type' => 'string' ), 'replace' => array( 'type' => 'string' ) ) ) ) ) ),
					'WriteRequest' => array( 'type' => 'object', 'required' => array( 'path', 'content' ), 'properties' => array( 'path' => array( 'type' => 'string' ), 'content' => array( 'type' => 'string' ), 'expected_sha256' => array( 'type' => 'string', 'pattern' => '^[a-fA-F0-9]{64}$', 'description' => 'Required when path already points to a regular file.' ), 'idempotency_key' => array( 'type' => 'string' ), 'base64' => array( 'type' => 'boolean' ), 'allow_executable' => array( 'type' => 'boolean', 'description' => 'Legacy explicit approval for executable file writes.' ), 'approval_request_id' => array( 'type' => 'string', 'description' => 'Returned by the first 428 approval-required response.' ), 'dangerous_operation_approved' => array( 'type' => 'boolean', 'description' => 'Set true only after the site owner explicitly approves an executable/sensitive file change.' ), 'approval_note' => array( 'type' => 'string', 'description' => 'Human-readable approval context required for v2 executable/sensitive file changes.' ) ) ),
					'MkdirRequest' => array( 'type' => 'object', 'required' => array( 'path' ), 'properties' => array( 'path' => array( 'type' => 'string' ), 'idempotency_key' => array( 'type' => 'string' ) ) ),
					'RenameRequest' => array( 'type' => 'object', 'required' => array( 'path', 'new_name' ), 'properties' => array( 'path' => array( 'type' => 'string' ), 'new_name' => array( 'type' => 'string' ), 'expected_sha256' => array( 'type' => 'string', 'pattern' => '^[a-fA-F0-9]{64}$', 'description' => 'Required when path points to a regular file.' ), 'idempotency_key' => array( 'type' => 'string' ), 'approval_request_id' => array( 'type' => 'string' ), 'dangerous_operation_approved' => array( 'type' => 'boolean' ), 'approval_note' => array( 'type' => 'string' ) ) ),
					'DeleteRequest' => array( 'type' => 'object', 'required' => array( 'path' ), 'properties' => array( 'path' => array( 'type' => 'string' ), 'expected_sha256' => array( 'type' => 'string', 'pattern' => '^[a-fA-F0-9]{64}$', 'description' => 'Required when path points to a regular file.' ), 'idempotency_key' => array( 'type' => 'string' ), 'approval_request_id' => array( 'type' => 'string' ), 'dangerous_operation_approved' => array( 'type' => 'boolean' ), 'approval_note' => array( 'type' => 'string' ) ) ),
					'RestoreRequest' => array( 'type' => 'object', 'required' => array( 'path' ), 'properties' => array( 'path' => array( 'type' => 'string' ), 'backup_id' => array( 'type' => 'string' ), 'backup_file' => array( 'type' => 'string', 'description' => 'Legacy compatibility field; prefer backup_id.' ), 'expected_sha256' => array( 'type' => 'string', 'pattern' => '^[a-fA-F0-9]{64}$', 'description' => 'Required when path points to a regular file.' ), 'idempotency_key' => array( 'type' => 'string' ), 'approval_request_id' => array( 'type' => 'string' ), 'dangerous_operation_approved' => array( 'type' => 'boolean' ), 'approval_note' => array( 'type' => 'string' ) ) ),
					'MutationData' => array( 'type' => 'object', 'properties' => array( 'success' => array( 'type' => 'boolean' ), 'operation_id' => array( 'type' => 'string' ), 'idempotency_key' => array( 'type' => 'string' ), 'path' => array( 'type' => 'string' ), 'old_path' => array( 'type' => 'string' ), 'new_name' => array( 'type' => 'string' ), 'bytes' => array( 'type' => 'integer' ), 'backup' => array( 'type' => 'string', 'nullable' => true ), 'backup_id' => array( 'type' => 'string', 'nullable' => true ), 'backup_record' => array( 'type' => 'object', 'nullable' => true ), 'current_backup' => array( 'type' => 'object', 'nullable' => true ), 'sha256' => array( 'type' => 'string' ), 'verification' => array( '$ref' => '#/components/schemas/VerificationManifest' ) ) ),
					'PatchData' => array( 'type' => 'object', 'properties' => array( 'path' => array( 'type' => 'string' ), 'operations' => array( 'type' => 'integer' ), 'changed_bytes' => array( 'type' => 'integer' ), 'sha256' => array( 'type' => 'string' ), 'verification' => array( '$ref' => '#/components/schemas/VerificationManifest' ) ) ),
					'VerificationManifest' => array( 'type' => 'object', 'properties' => array( 'status' => array( 'type' => 'string' ), 'bytes' => array( 'type' => 'integer' ), 'sha256' => array( 'type' => 'string' ), 'checks' => array( 'type' => 'object' ), 'warnings' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ) ),
					'OperationStatus' => array( 'type' => 'object', 'properties' => array( 'operation_id' => array( 'type' => 'string' ), 'operation' => array( 'type' => 'string' ), 'state' => array( 'type' => 'string' ), 'phase' => array( 'type' => 'string' ), 'status' => array( 'type' => 'integer', 'nullable' => true ), 'backup_id' => array( 'type' => 'string', 'nullable' => true ), 'verification_status' => array( 'type' => 'string', 'nullable' => true ), 'state_history' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ), 'data' => array( 'nullable' => true ) ) ),
					'HealthData' => array( 'type' => 'object', 'properties' => array( 'status' => array( 'type' => 'string', 'enum' => array( 'ok', 'degraded' ) ), 'checked_at' => array( 'type' => 'string' ), 'plugin_version' => array( 'type' => 'string' ), 'wp_version' => array( 'type' => 'string' ), 'php_version' => array( 'type' => 'string' ), 'checks' => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'boolean' ) ), 'backup_inventory' => array( 'type' => 'object', 'description' => 'Non-secret backup integrity and bounded retention-review metadata.' ), 'rollout' => array( 'type' => 'object' ) ) ),
					'StatusData' => array( 'type' => 'object', 'properties' => array( 'status' => array( 'type' => 'string' ), 'plugin_version' => array( 'type' => 'string' ), 'wp_version' => array( 'type' => 'string' ), 'php_version' => array( 'type' => 'string' ), 'permission_level' => array( 'type' => 'string' ), 'license_tier' => array( 'type' => 'string' ), 'is_pro' => array( 'type' => 'boolean' ), 'is_trial' => array( 'type' => 'boolean' ) ) ),
					'WPInfoData' => array( 'type' => 'object', 'properties' => array( 'site' => array( 'type' => 'object' ), 'wordpress' => array( 'type' => 'object' ), 'server' => array( 'type' => 'object' ), 'theme' => array( 'type' => 'object' ), 'remotewp' => array( 'type' => 'object' ) ) ),
					'IssuedToken' => array( 'type' => 'object', 'properties' => array( 'token' => array( 'type' => 'string' ), 'token_id' => array( 'type' => 'string' ), 'scopes' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'expires_at' => array( 'type' => 'integer' ), 'warning' => array( 'type' => 'string' ) ) ),
					'TokenList' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'RevokedToken' => array( 'type' => 'object', 'properties' => array( 'token_id' => array( 'type' => 'string' ), 'revoked_at' => array( 'type' => 'integer' ) ) ),
				),
			),
		);
	}

	private static function path_parameter() {
		return array( 'name' => 'path', 'in' => 'query', 'required' => true, 'schema' => array( 'type' => 'string' ) );
	}

	private static function token_security() {
		return array(
			array( 'remoteWpV2Token' => array() ),
			array( 'remoteWpToken' => array() ),
		);
	}

	private static function success_response( $schema ) {
		return array( 'description' => 'Successful response', 'content' => array( 'application/json' => array( 'schema' => array( 'allOf' => array( array( '$ref' => '#/components/schemas/Envelope' ) ), 'properties' => array( 'data' => array( '$ref' => $schema ) ) ) ) ) );
	}

	private static function error_response() {
		return array( 'description' => 'Error response', 'content' => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/Envelope' ) ) ) );
	}
}
