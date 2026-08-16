<?php
/**
 * Small, deterministic patch engine for the additive v2 mutation route.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Patch_Engine {

	/**
	 * Apply exact find/replace operations without accepting a full-file body.
	 *
	 * @param string $content Original content.
	 * @param array  $patch   Array of find/replace operations.
	 * @return array|WP_Error
	 */
	public static function apply( $content, $patch ) {
		if ( ! is_array( $patch ) || empty( $patch ) || count( $patch ) > 50 ) {
			return new WP_Error( 'invalid_patch', __( 'Patch must contain between 1 and 50 operations.', 'remotewp' ), array( 'status' => 400 ) );
		}

		$result = $content;
		foreach ( $patch as $operation ) {
			if ( ! is_array( $operation ) || ! isset( $operation['find'] ) || ! array_key_exists( 'replace', $operation ) ) {
				return new WP_Error( 'invalid_patch', __( 'Each patch operation requires find and replace.', 'remotewp' ), array( 'status' => 400 ) );
			}
			$find    = (string) $operation['find'];
			$replace = (string) $operation['replace'];
			if ( '' === $find || false !== strpos( $find, "\0" ) || false !== strpos( $replace, "\0" ) || strlen( $find ) > 1024 * 1024 || strlen( $replace ) > 1024 * 1024 ) {
				return new WP_Error( 'invalid_patch', __( 'Patch values are empty, binary or too large.', 'remotewp' ), array( 'status' => 400 ) );
			}
			if ( $find === $result ) {
				return new WP_Error( 'full_replace_blocked', __( 'Patch must not replace the complete file body.', 'remotewp' ), array( 'status' => 403 ) );
			}
			$count = 0;
			$result = str_replace( $find, $replace, $result, $count );
			if ( 0 === $count ) {
				return new WP_Error( 'patch_target_not_found', __( 'A patch target was not found; no write was performed.', 'remotewp' ), array( 'status' => 409 ) );
			}
		}

		return array(
			'content'       => $result,
			'operations'    => count( $patch ),
			'changed_bytes'  => strlen( $result ) - strlen( $content ),
		);
	}
}
