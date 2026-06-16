<?php
/**
 * REST: POST /ai-elementor/v1/push-template
 *
 * Saves a generated Elementor template directly into the Elementor Library
 * (`elementor_library` post type) as a reusable Saved Template — no manual
 * download + import needed.
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
 * Creates an Elementor Library template (Saved Template) from generated JSON.
 */
class Push_Template_Controller {

	const NAMESPACE = 'ai-elementor/v1';
	const ROUTE     = '/push-template';

	/**
	 * Elementor Library post type.
	 */
	const LIBRARY_POST_TYPE = 'elementor_library';

	/**
	 * Elementor Library type taxonomy.
	 */
	const LIBRARY_TAXONOMY = 'elementor_library_type';

	/**
	 * Template types Elementor's library accepts.
	 *
	 * @var string[]
	 */
	const ALLOWED_TYPES = array( 'page', 'section', 'container', 'header', 'footer', 'popup' );

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
					'elementor_json' => array(
						'type'     => 'object',
						'required' => true,
					),
					'title'          => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'template_type'  => array(
						'type'     => 'string',
						'required' => false,
						'enum'     => self::ALLOWED_TYPES,
						'default'  => 'page',
					),
				),
			)
		);
	}

	/**
	 * Capability gate: caller must be able to create library templates.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_check() {
		if ( ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error(
				'aieb_forbidden',
				__( 'You are not allowed to create Elementor templates.', 'ai-elementor-builder' ),
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
		$type           = (string) $request->get_param( 'template_type' );

		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			$type = 'page';
		}

		if ( '' === trim( $title ) ) {
			$title = __( 'AI Generated Template', 'ai-elementor-builder' );
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

		$content = $validated['data']['content'];

		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => self::LIBRARY_POST_TYPE,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'aieb_template_insert_failed',
				$post_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Elementor stores the elements tree (not the document envelope) as a
		// JSON-encoded string. wp_slash() guards the slashes update_post_meta strips.
		update_post_meta( $post_id, '_elementor_data', wp_slash( (string) wp_json_encode( $content ) ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', $type );
		update_post_meta( $post_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );

		// Classify the template in the Elementor Library so it shows under the
		// correct type filter (Page / Section / Header …).
		if ( taxonomy_exists( self::LIBRARY_TAXONOMY ) ) {
			wp_set_object_terms( $post_id, $type, self::LIBRARY_TAXONOMY );
		}

		return new WP_REST_Response(
			array(
				'template_id'   => $post_id,
				'template_type' => $type,
				'title'         => $title,
				'edit_url'      => admin_url( 'post.php?post=' . $post_id . '&action=elementor' ),
				'library_url'   => admin_url( 'edit.php?post_type=' . self::LIBRARY_POST_TYPE ),
			),
			201
		);
	}
}
