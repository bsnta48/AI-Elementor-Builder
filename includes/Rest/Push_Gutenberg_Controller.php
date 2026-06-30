<?php
/**
 * REST: POST /ai-elementor/v1/push-gutenberg
 *
 * Writes a generated design into a target page's post_content as Gutenberg blocks.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Rest;

use AI_Elementor_Builder\Converter\Blocks_Converter;
use AI_Elementor_Builder\Validator\Elementor_Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Pushes a generated template into a page as block-editor content (post_content),
 * the Gutenberg counterpart to Push_Controller. Re-validates the incoming JSON,
 * converts the Elementor tree to block markup, and turns Elementor edit mode off
 * so the page opens in the block editor.
 */
class Push_Gutenberg_Controller {

	const NAMESPACE = 'ai-elementor/v1';
	const ROUTE     = '/push-gutenberg';

	/**
	 * @var Elementor_Validator
	 */
	private $validator;

	/**
	 * @var Blocks_Converter
	 */
	private $converter;

	/**
	 * @param Elementor_Validator $validator Elementor JSON validator.
	 */
	public function __construct( Elementor_Validator $validator ) {
		$this->validator = $validator;
		$this->converter = new Blocks_Converter();
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
					'page_id'        => array(
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

		// Re-validate + normalize the incoming JSON before converting/persisting.
		$validated = $this->validator->validate( (string) wp_json_encode( $elementor_json ) );
		if ( empty( $validated['valid'] ) ) {
			return new WP_Error(
				'aieb_invalid_template',
				$validated['error'],
				array( 'status' => 422 )
			);
		}

		$markup = $this->converter->convert( $validated['data']['content'] );

		// Write block content. wp_update_post runs the post through kses for users
		// without unfiltered_html; the <style>/markup we emit is block-editor safe.
		$result = wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => $markup,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'aieb_push_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Turn Elementor edit mode off so the page opens in the block editor.
		update_post_meta( $page_id, '_elementor_edit_mode', '' );

		return new WP_REST_Response(
			array(
				'page_id'  => $page_id,
				'edit_url' => admin_url( 'post.php?post=' . $page_id . '&action=edit' ),
				'view_url' => get_permalink( $page_id ),
			),
			200
		);
	}
}
