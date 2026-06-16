<?php
/**
 * REST: POST /ai-elementor/v1/push
 *
 * Writes a generated Elementor template into a target page's Elementor data.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Rest;

use AI_Elementor_Builder\Validator\Elementor_Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Pushes an Elementor template to a page's `_elementor_data` and flips the page
 * into Elementor builder mode.
 */
class Push_Controller {

	const NAMESPACE = 'ai-elementor/v1';
	const ROUTE     = '/push';

	/**
	 * @var Elementor_Validator
	 */
	private $validator;

	/**
	 * @param Elementor_Validator $validator Elementor JSON validator.
	 */
	public function __construct( Elementor_Validator $validator ) {
		$this->validator = $validator;
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
	 * Register the REST route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'page_id'       => array(
						'type'     => 'integer',
						'required' => true,
					),
					'elementor_json' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Capability gate: caller must be able to edit the target page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function permission_check( WP_REST_Request $request ) {
		$page_id = (int) $request->get_param( 'page_id' );

		if ( $page_id <= 0 || ! current_user_can( 'edit_post', $page_id ) ) {
			return new WP_Error(
				'aieb_forbidden',
				__( 'You are not allowed to edit this page.', 'ai-elementor-builder' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Handle the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$page_id        = (int) $request->get_param( 'page_id' );
		$elementor_json = $request->get_param( 'elementor_json' );

		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error(
				'aieb_invalid_page',
				__( 'Target page not found.', 'ai-elementor-builder' ),
				array( 'status' => 404 )
			);
		}

		// Re-validate + normalize the incoming JSON before persisting.
		$validated = $this->validator->validate( (string) wp_json_encode( $elementor_json ) );
		if ( empty( $validated['valid'] ) ) {
			return new WP_Error(
				'aieb_invalid_template',
				$validated['error'],
				array( 'status' => 422 )
			);
		}

		// Elementor stores the elements tree (not the document envelope) as a
		// JSON-encoded string. wp_slash() guards the slashes update_post_meta strips.
		$content = $validated['data']['content'];
		update_post_meta( $page_id, '_elementor_data', wp_slash( (string) wp_json_encode( $content ) ) );
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
		if ( '' === (string) get_post_meta( $page_id, '_elementor_version', true ) ) {
			update_post_meta( $page_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );
		}

		// Flush Elementor's cached CSS for this page so the new layout renders.
		delete_post_meta( $page_id, '_elementor_css' );
		delete_option( '_elementor_css_' . $page_id );
		delete_post_meta( $page_id, '_elementor_inline_css' );

		return new WP_REST_Response(
			array(
				'page_id'  => $page_id,
				'edit_url' => admin_url( 'post.php?post=' . $page_id . '&action=elementor' ),
				'view_url' => get_permalink( $page_id ),
			),
			200
		);
	}
}
