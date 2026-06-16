<?php
/**
 * Per-user generation history (last N entries) stored in user meta.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\History;

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes the `ai_elementor_history` user meta: a capped list of
 * { prompt, provider, json, timestamp } entries, newest first.
 */
class History {

	const META_KEY = 'ai_elementor_history';

	const LIMIT = 10;

	/**
	 * Get a user's history (newest first).
	 *
	 * @param int $user_id User ID (0 = current user).
	 * @return array<int,array>
	 */
	public static function get( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}

		$history = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $history ) ? array_values( $history ) : array();
	}

	/**
	 * Prepend a generation entry, keeping only the most recent self::LIMIT.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $prompt   The prompt used.
	 * @param string $provider Provider key.
	 * @param array  $json     The generated template.
	 * @return array The new history list (newest first).
	 */
	public static function add( $user_id, $prompt, $provider, array $json ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}

		$entry = array(
			'prompt'    => (string) $prompt,
			'provider'  => (string) $provider,
			'json'      => $json,
			'timestamp' => time(),
		);

		$history = self::get( $user_id );
		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, self::LIMIT );

		update_user_meta( $user_id, self::META_KEY, $history );

		return $history;
	}
}
