<?php
/**
 * Deterministic post-mutation verification helpers.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Verification_Manifest {
	/**
	 * Validate content before a v2 mutation and return a safe verification manifest.
	 *
	 * JSON receives syntax validation. PHP syntax validation is intentionally not
	 * claimed here because invoking a shell binary from a WordPress request is
	 * unsafe and not portable; the health endpoint remains the HTTP check.
	 *
	 * @param string $path             Target path.
	 * @param string $content          Candidate content.
	 * @param bool   $require_review   Whether v2 sensitive-content review is required.
	 * @param bool   $review_approved  Whether the caller explicitly approved the review.
	 * @return array|WP_Error
	 */
	public static function for_content( $path, $content, $require_review = false, $review_approved = false ) {
		$content  = (string) $content;
		$redacted = RemoteWP_Data_Redactor::redact( $content );
		$manifest = array(
			'status' => 'passed',
			'bytes'  => strlen( $content ),
			'sha256' => hash( 'sha256', $content ),
			'checks' => array(
				'content_hash' => 'passed',
				'sensitive_content' => $redacted['redacted'] ? 'detected' : 'not_detected',
			),
		);
		if ( $redacted['redacted'] && $require_review && ! $review_approved ) {
			return new WP_Error( 'sensitive_content_review_required', __( 'Sensitive content was detected. Review the mutation and resend with audit_approved=true.', 'remotewp' ), array( 'status' => 428 ) );
		}
		if ( $redacted['redacted'] && $review_approved ) {
			$manifest['checks']['sensitive_content'] = 'approved';
			$manifest['warnings'] = array( 'Sensitive content review was explicitly approved; content is excluded from audit details.' );
		}

		$extension = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );
		if ( 'json' === $extension ) {
			json_decode( $content, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'json_validation_failed', __( 'The resulting JSON is invalid and was not written.', 'remotewp' ), array( 'status' => 422, 'json_error' => json_last_error_msg() ) );
			}
			$manifest['checks']['json_syntax'] = 'passed';
		} elseif ( 'php' === $extension || preg_match( '/\.(phtml|php[0-9]*)$/i', (string) $path ) ) {
			if ( ! self::php_lexical_structure_is_balanced( $content ) ) {
				return new WP_Error( 'php_validation_failed', __( 'The resulting PHP has unbalanced syntax delimiters and was not written.', 'remotewp' ), array( 'status' => 422 ) );
			}
			$manifest['checks']['php_lexical_structure'] = 'passed';
			$manifest['checks']['php_syntax']           = 'external_required';
			$manifest['warnings']                       = array( 'PHP syntax verification requires an external trusted verification step.' );
		}

		return $manifest;
	}

	/**
	 * Perform a deterministic, non-executing delimiter check for PHP content.
	 *
	 * This is intentionally narrower than php -l and never claims full syntax
	 * validation. Strings, comments and heredoc bodies are tokenized and ignored
	 * so only structural delimiters in PHP code are compared.
	 *
	 * @param string $content Candidate PHP content.
	 * @return bool
	 */
	private static function php_lexical_structure_is_balanced( $content ) {
		$tokens = token_get_all( $content );
		$stack  = array();
		$pairs  = array( ')' => '(', ']' => '[', '}' => '{' );
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				continue;
			}
			if ( isset( $pairs[ $token ] ) ) {
				if ( empty( $stack ) || end( $stack ) !== $pairs[ $token ] ) {
					return false;
				}
				array_pop( $stack );
			} elseif ( in_array( $token, array( '(', '[', '{' ), true ) ) {
				$stack[] = $token;
			}
		}
		return empty( $stack );
	}
}
