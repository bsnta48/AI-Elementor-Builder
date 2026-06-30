<?php
/**
 * REST: POST /ai-elementor/v1/generate
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Rest;

use AI_Elementor_Builder\History\History;
use AI_Elementor_Builder\Media\Image_Resolver;
use AI_Elementor_Builder\Media\Stock_Image_Provider;
use AI_Elementor_Builder\Providers\Provider_Factory;
use AI_Elementor_Builder\References\Reference_Registry;
use AI_Elementor_Builder\Services\Page_Generator;
use AI_Elementor_Builder\Settings\Settings;
use AI_Elementor_Builder\Validator\Elementor_Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Generates an Elementor template from a natural-language prompt via an AI provider.
 */
class Generate_Controller {

	const NAMESPACE = 'ai-elementor/v1';
	const ROUTE     = '/generate';

	/**
	 * @var Provider_Factory
	 */
	private $factory;

	/**
	 * @var Elementor_Validator
	 */
	private $validator;

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var Reference_Registry
	 */
	private $references;

	/**
	 * @var Page_Generator
	 */
	private $generator;

	/**
	 * @var Image_Resolver
	 */
	private $images;

	/**
	 * @param Provider_Factory    $factory    Provider factory.
	 * @param Elementor_Validator $validator  Elementor JSON validator.
	 * @param Settings            $settings   Settings handler (mock mode lookup).
	 * @param Reference_Registry  $references Design reference library.
	 */
	public function __construct( Provider_Factory $factory, Elementor_Validator $validator, Settings $settings, Reference_Registry $references ) {
		$this->factory    = $factory;
		$this->validator  = $validator;
		$this->settings   = $settings;
		$this->references = $references;
		$this->generator  = new Page_Generator( $factory, $validator, $settings, $references );
		$this->images     = new Image_Resolver( new Stock_Image_Provider( $settings ) );
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
					'provider' => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => $this->factory->provider_keys(),
						'sanitize_callback' => 'sanitize_key',
					),
					'prompt'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && '' !== trim( $value );
						},
					),
					'model'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					// Optional design reference id; injected as a few-shot exemplar.
					'reference' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					// Optional inferred scope; steers auto-selected exemplars when no
					// explicit reference is chosen.
					'scope'     => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					// Optional reference image: raw base64 (no data: prefix) for
					// vision-capable providers.
					'image'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => array( $this, 'sanitize_base64' ),
					),
					'image_mime' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif' ),
					),
				),
			)
		);
	}

	/**
	 * Capability gate: callers must be able to edit posts.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_check() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'aieb_forbidden',
				__( 'You are not allowed to generate templates.', 'ai-elementor-builder' ),
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
		// Large/full-page generations on slower models can exceed PHP's default
		// 30s max_execution_time, which kills the request mid-flight and surfaces
		// as a "Network error" in the browser. Give the request room to finish
		// (a touch beyond the provider's 60s HTTP timeout). Best-effort: silently
		// no-ops where the host disallows it (e.g. safe_mode / hardened FPM).
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$provider_key = (string) $request->get_param( 'provider' );
		$prompt       = (string) $request->get_param( 'prompt' );

		$result = $this->generator->generate(
			$prompt,
			array(
				'provider'   => $provider_key,
				'model'      => (string) $request->get_param( 'model' ),
				'reference'  => (string) $request->get_param( 'reference' ),
				'scope'      => (string) $request->get_param( 'scope' ),
				'image'      => (string) $request->get_param( 'image' ),
				'image_mime' => (string) $request->get_param( 'image_mime' ),
			)
		);

		if ( empty( $result['success'] ) ) {
			$data = array( 'status' => $result['status'] );
			if ( isset( $result['raw'] ) ) {
				$data['raw'] = $result['raw'];
			}
			return new WP_Error( $result['error_code'], $result['error'], $data );
		}

		// Resolve AI image keywords to real photos in the Media Library (or
		// placeholders when no stock key is set), so preview + push show images.
		if ( isset( $result['template']['content'] ) && is_array( $result['template']['content'] ) ) {
			$result['template']['content'] = $this->images->resolve_final( $result['template']['content'], 0 );
		}

		// Record this generation in the user's history (newest first, last 10).
		History::add( get_current_user_id(), $prompt, $provider_key, $result['template'] );

		return new WP_REST_Response(
			array(
				'provider' => $result['provider'],
				'model'    => $result['model'],
				'mock'     => ! empty( $result['mock'] ),
				'template' => $result['template'],
			),
			200
		);
	}

	/**
	 * Sanitize a base64 payload: strip any data-URL prefix and reject anything
	 * that is not valid base64 or that decodes to a non-image.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string Clean base64 string, or '' when invalid.
	 */
	public function sanitize_base64( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		// Drop a leading "data:image/png;base64," prefix if the client sent one.
		if ( 0 === strpos( $value, 'data:' ) && false !== strpos( $value, ',' ) ) {
			$value = substr( $value, strpos( $value, ',' ) + 1 );
		}

		// Keep only valid base64 characters, then verify it round-trips.
		$value   = preg_replace( '/[^A-Za-z0-9+\/=]/', '', $value );
		$decoded = base64_decode( $value, true );
		if ( false === $decoded || '' === $decoded ) {
			return '';
		}

		// Cap the decoded size at 5 MB (mirrors the client-side limit).
		if ( strlen( $decoded ) > 5 * 1024 * 1024 ) {
			return '';
		}

		return $value;
	}
}
