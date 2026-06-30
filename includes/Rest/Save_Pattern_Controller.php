<?php
/**
 * REST: POST /ai-elementor/v1/save-pattern
 *
 * Saves a generated design as a Gutenberg synced pattern (wp_block post).
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
 * Converts a generated template to block markup and stores it as a reusable
 * Gutenberg pattern (the `wp_block` post type), so it shows up in the editor's
 * Patterns inserter and can be dropped into any page.
 */
class Save_Pattern_Controller {

	const NAMESPACE = 'ai-elementor/v1';
	const ROUTE     = '/save-pattern';
	const POST_TYPE = 'wp_block';

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
					'elementor_json' => array(
						'type'     => 'object',
						'required' => true,
					),
					'title'          => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Capability gate: caller must be able to publish posts.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_check() {
		if ( ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error(
				'aieb_forbidden',
				__( 'You are not allowed to save patterns.', 'ai-elementor-builder' ),
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
		$elementor_json = $request->get_param( 'elementor_json' );
		$title          = (string) $request->get_param( 'title' );
		if ( '' === trim( $title ) ) {
			$title = __( 'AI Generated Pattern', 'ai-elementor-builder' );
		}

		// Re-validate + normalize before converting/persisting.
		$validated = $this->validator->validate( (string) wp_json_encode( $elementor_json ) );
		if ( empty( $validated['valid'] ) ) {
			return new WP_Error(
				'aieb_invalid_template',
				$validated['error'],
				array( 'status' => 422 )
			);
		}

		$markup = $this->converter->convert( $validated['data']['content'] );

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => $markup,
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'aieb_pattern_failed',
				$post_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Mark as a synced pattern (empty sync status = synced/reusable).
		update_post_meta( $post_id, 'wp_pattern_sync_status', '' );

		return new WP_REST_Response(
			array(
				'pattern_id'  => $post_id,
				'title'       => $title,
				'edit_url'    => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
				'library_url' => admin_url( 'edit.php?post_type=' . self::POST_TYPE ),
			),
			201
		);
	}
}
