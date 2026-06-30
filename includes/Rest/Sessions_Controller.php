<?php
/**
 * REST: /ai-elementor/v1/sessions
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Rest;

use AI_Elementor_Builder\Sessions\Session_Store;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for per-user planning sessions (list / read / create / update / delete).
 * Every per-id route additionally verifies the caller owns the session.
 */
class Sessions_Controller {

	const NAMESPACE = 'ai-elementor/v1';

	/**
	 * @var Session_Store
	 */
	private $store;

	/**
	 * @param Session_Store $store Session storage.
	 */
	public function __construct( Session_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/sessions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_sessions' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_session' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sessions/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_session' ),
					'permission_callback' => array( $this, 'owns_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_session' ),
					'permission_callback' => array( $this, 'owns_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_session' ),
					'permission_callback' => array( $this, 'owns_check' ),
				),
			)
		);
	}

	/**
	 * Base gate: caller must be a logged-in editor.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_check() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'aieb_forbidden',
				__( 'You are not allowed to manage sessions.', 'ai-elementor-builder' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Per-id gate: caller must own the requested session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function owns_check( WP_REST_Request $request ) {
		$base = $this->permission_check();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$id = (int) $request['id'];
		if ( ! $this->store->owns( $id, get_current_user_id() ) ) {
			return new WP_Error(
				'aieb_forbidden',
				__( 'This session does not belong to you.', 'ai-elementor-builder' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * GET /sessions
	 *
	 * @return WP_REST_Response
	 */
	public function list_sessions() {
		return new WP_REST_Response( $this->store->list_for( get_current_user_id() ), 200 );
	}

	/**
	 * POST /sessions
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_session( WP_REST_Request $request ) {
		$title = (string) $request->get_param( 'title' );
		$id    = $this->store->create( get_current_user_id(), sanitize_text_field( $title ) );
		if ( null === $id ) {
			return new WP_Error( 'aieb_session_failed', __( 'Could not create the session.', 'ai-elementor-builder' ), array( 'status' => 500 ) );
		}
		return new WP_REST_Response( $this->store->get( $id ), 201 );
	}

	/**
	 * GET /sessions/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_session( WP_REST_Request $request ) {
		$session = $this->store->get( (int) $request['id'] );
		if ( null === $session ) {
			return new WP_Error( 'aieb_session_missing', __( 'Session not found.', 'ai-elementor-builder' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( $session, 200 );
	}

	/**
	 * POST /sessions/{id} — merge in supplied fields.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_session( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$data = array();

		$messages = $request->get_param( 'messages' );
		if ( is_array( $messages ) ) {
			$data['messages'] = $this->sanitize_messages( $messages );
		}
		if ( null !== $request->get_param( 'brief' ) ) {
			$data['brief'] = (string) $request->get_param( 'brief' );
		}
		if ( null !== $request->get_param( 'template' ) ) {
			$data['template'] = $request->get_param( 'template' );
		}
		if ( null !== $request->get_param( 'provider' ) ) {
			$data['provider'] = sanitize_key( (string) $request->get_param( 'provider' ) );
		}
		if ( null !== $request->get_param( 'scope' ) ) {
			$data['scope'] = sanitize_key( (string) $request->get_param( 'scope' ) );
		}
		if ( null !== $request->get_param( 'reference' ) ) {
			$data['reference'] = sanitize_key( (string) $request->get_param( 'reference' ) );
		}

		$title = $request->get_param( 'title' );
		$title = null !== $title ? sanitize_text_field( (string) $title ) : null;

		if ( ! $this->store->save( $id, $data, $title ) ) {
			return new WP_Error( 'aieb_session_failed', __( 'Could not save the session.', 'ai-elementor-builder' ), array( 'status' => 500 ) );
		}
		return new WP_REST_Response( $this->store->get( $id ), 200 );
	}

	/**
	 * DELETE /sessions/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_session( WP_REST_Request $request ) {
		if ( ! $this->store->delete( (int) $request['id'] ) ) {
			return new WP_Error( 'aieb_session_failed', __( 'Could not delete the session.', 'ai-elementor-builder' ), array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Sanitize an incoming messages array to { role, content } entries.
	 *
	 * @param array $messages Raw messages.
	 * @return array
	 */
	private function sanitize_messages( array $messages ): array {
		$clean = array();
		foreach ( $messages as $m ) {
			if ( ! is_array( $m ) || ! isset( $m['content'] ) ) {
				continue;
			}
			$role      = ( isset( $m['role'] ) && 'assistant' === $m['role'] ) ? 'assistant' : 'user';
			$clean[]   = array(
				'role'    => $role,
				'content' => sanitize_textarea_field( (string) $m['content'] ),
			);
		}
		// Cap to keep the stored payload bounded.
		return array_slice( $clean, -200 );
	}
}
