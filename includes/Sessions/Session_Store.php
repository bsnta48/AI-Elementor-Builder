<?php
/**
 * Conversation session storage (custom post type).
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Sessions;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Persists per-user chat sessions as a private `aieb_session` CPT. Each session
 * post holds the running conversation, the accumulated design brief, and the last
 * generated template, JSON-encoded in post_content — so a user can leave and
 * resume a planning conversation across page loads and devices.
 */
class Session_Store {

	const POST_TYPE = 'aieb_session';

	/**
	 * Hook CPT registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Register the private session CPT (no public UI/queries).
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'           => array( 'name' => __( 'AI Builder Sessions', 'ai-elementor-builder' ) ),
				'public'           => false,
				'show_ui'          => false,
				'show_in_menu'     => false,
				'show_in_rest'     => false,
				'rewrite'          => false,
				'query_var'        => false,
				'can_export'       => false,
				'delete_with_user' => true,
				'supports'         => array( 'title', 'author' ),
			)
		);
	}

	/**
	 * Create an empty session for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $title   Optional initial title.
	 * @return int|null New post ID, or null on failure.
	 */
	public function create( int $user_id, string $title = '' ): ?int {
		if ( $user_id <= 0 ) {
			return null;
		}
		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_author'  => $user_id,
				'post_title'   => '' !== $title ? $title : __( 'New chat', 'ai-elementor-builder' ),
				'post_content' => wp_slash( (string) wp_json_encode( $this->empty_data() ) ),
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return null;
		}
		return (int) $post_id;
	}

	/**
	 * Fetch a session's full data.
	 *
	 * @param int $id Session post ID.
	 * @return array|null { id, title, updated, data fields… } or null when missing.
	 */
	public function get( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$data = json_decode( (string) $post->post_content, true );
		if ( ! is_array( $data ) ) {
			$data = $this->empty_data();
		}

		return array(
			'id'        => (int) $post->ID,
			'title'     => $post->post_title,
			'updated'   => (int) get_post_timestamp( $post, 'modified' ),
			'messages'  => isset( $data['messages'] ) && is_array( $data['messages'] ) ? $data['messages'] : array(),
			'brief'     => isset( $data['brief'] ) ? (string) $data['brief'] : '',
			'template'  => isset( $data['template'] ) ? $data['template'] : null,
			'provider'  => isset( $data['provider'] ) ? (string) $data['provider'] : '',
			'scope'     => isset( $data['scope'] ) ? (string) $data['scope'] : '',
			'reference' => isset( $data['reference'] ) ? (string) $data['reference'] : '',
		);
	}

	/**
	 * Lightweight session list for a user (newest first).
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array> [ { id, title, updated, message_count } ]
	 */
	public function list_for( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'author'         => $user_id,
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$out = array();
		foreach ( $posts as $post ) {
			$data  = json_decode( (string) $post->post_content, true );
			$count = ( is_array( $data ) && isset( $data['messages'] ) && is_array( $data['messages'] ) ) ? count( $data['messages'] ) : 0;
			$out[] = array(
				'id'            => (int) $post->ID,
				'title'         => $post->post_title,
				'updated'       => (int) get_post_timestamp( $post, 'modified' ),
				'message_count' => $count,
			);
		}
		return $out;
	}

	/**
	 * Update a session's data and/or title.
	 *
	 * @param int   $id    Session post ID.
	 * @param array $data  Partial data to merge (messages/brief/template/provider/scope/reference).
	 * @param string|null $title New title, or null to leave unchanged.
	 * @return bool
	 */
	public function save( int $id, array $data, ?string $title = null ): bool {
		$existing = $this->get( $id );
		if ( null === $existing ) {
			return false;
		}

		$merged = array(
			'messages'  => array_key_exists( 'messages', $data ) && is_array( $data['messages'] ) ? $data['messages'] : $existing['messages'],
			'brief'     => array_key_exists( 'brief', $data ) ? (string) $data['brief'] : $existing['brief'],
			'template'  => array_key_exists( 'template', $data ) ? $data['template'] : $existing['template'],
			'provider'  => array_key_exists( 'provider', $data ) ? (string) $data['provider'] : $existing['provider'],
			'scope'     => array_key_exists( 'scope', $data ) ? (string) $data['scope'] : $existing['scope'],
			'reference' => array_key_exists( 'reference', $data ) ? (string) $data['reference'] : $existing['reference'],
		);

		$update = array(
			'ID'           => $id,
			'post_content' => wp_slash( (string) wp_json_encode( $merged ) ),
		);
		if ( null !== $title && '' !== trim( $title ) ) {
			$update['post_title'] = $title;
		}

		$result = wp_update_post( $update, true );
		return ! is_wp_error( $result );
	}

	/**
	 * Delete a session.
	 *
	 * @param int $id Session post ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}
		return (bool) wp_delete_post( $id, true );
	}

	/**
	 * Whether a user owns a session.
	 *
	 * @param int $id      Session post ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function owns( int $id, int $user_id ): bool {
		$post = get_post( $id );
		return $post instanceof WP_Post
			&& self::POST_TYPE === $post->post_type
			&& (int) $post->post_author === $user_id
			&& $user_id > 0;
	}

	/**
	 * The empty session payload shape.
	 *
	 * @return array
	 */
	private function empty_data(): array {
		return array(
			'messages'  => array(),
			'brief'     => '',
			'template'  => null,
			'provider'  => '',
			'scope'     => '',
			'reference' => '',
		);
	}
}
