<?php
/**
 * REST: POST /ai-elementor/v1/test-key
 *
 * Makes the smallest possible call to a provider's API to verify an API key,
 * returning a connected/failed result for the Settings page badge.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Rest;

use AI_Elementor_Builder\Providers\Provider_Factory;
use AI_Elementor_Builder\Settings\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a provider API key with a minimal, low-cost request.
 */
class Test_Key_Controller {

	const NAMESPACE = 'ai-elementor/v1';
	const ROUTE     = '/test-key';

	/**
	 * Per-provider minimal test request: model override + max tokens. A small
	 * max_tokens keeps the probe as cheap as possible.
	 *
	 * @var array<string,array{model:string,max_tokens:int}>
	 */
	private const TESTS = array(
		// gpt-3.5-turbo with a 5-token cap, per spec, to minimize cost.
		'openai'    => array( 'model' => 'gpt-3.5-turbo', 'max_tokens' => 5 ),
		// Cheapest Claude + Gemini models for the probe.
		'anthropic' => array( 'model' => 'claude-haiku-4-5', 'max_tokens' => 5 ),
		'gemini'    => array( 'model' => 'gemini-1.5-flash', 'max_tokens' => 5 ),
		// Cheap OpenRouter model for the probe.
		'openrouter' => array( 'model' => 'openai/gpt-4o-mini', 'max_tokens' => 5 ),
		// NVIDIA NIM probe model. Match the default so the key is tested against
		// the model the user actually has access to.
		'nvidia'    => array( 'model' => 'openai/gpt-oss-120b', 'max_tokens' => 5 ),
	);

	/**
	 * @var Provider_Factory
	 */
	private $factory;

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @param Provider_Factory $factory  Provider factory.
	 * @param Settings         $settings Settings handler (saved-key fallback).
	 */
	public function __construct( Provider_Factory $factory, Settings $settings ) {
		$this->factory  = $factory;
		$this->settings = $settings;
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
					// Optional: test an unsaved key typed into the field. When the
					// field still shows the saved mask, the client omits this and
					// we fall back to the stored key.
					'api_key'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ollama-test',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_ollama' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					// Optional: probe an unsaved URL typed into the field.
					'url' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
	}

	/**
	 * Capability gate: settings is a manage_options page.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'aieb_forbidden',
				__( 'You are not allowed to test API keys.', 'ai-elementor-builder' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Handle the request: resolve the key, fire a minimal call, report the result.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$provider_key = (string) $request->get_param( 'provider' );

		// Prefer a freshly typed key; otherwise use the stored (decrypted) one.
		$api_key = trim( (string) $request->get_param( 'api_key' ) );
		if ( '' === $api_key ) {
			$api_key = $this->settings->get_key( $this->factory->key_field( $provider_key ) );
		}

		if ( '' === $api_key ) {
			return new WP_REST_Response(
				array(
					'connected' => false,
					'error'     => __( 'No API key to test. Enter or save a key first.', 'ai-elementor-builder' ),
				),
				200
			);
		}

		$provider = $this->factory->make_with_key( $provider_key, $api_key );
		if ( null === $provider ) {
			return new WP_Error(
				'aieb_unknown_provider',
				__( 'Unknown provider.', 'ai-elementor-builder' ),
				array( 'status' => 400 )
			);
		}

		$test   = isset( self::TESTS[ $provider_key ] ) ? self::TESTS[ $provider_key ] : array( 'max_tokens' => 5 );
		$result = $provider->generate( 'Hi', $test );

		if ( empty( $result['success'] ) ) {
			return new WP_REST_Response(
				array(
					'connected' => false,
					'error'     => $result['error'] ? $result['error'] : __( 'Request failed.', 'ai-elementor-builder' ),
				),
				200
			);
		}

		return new WP_REST_Response( array( 'connected' => true ), 200 );
	}

	/**
	 * Probe an Ollama server: GET {url}/api/tags and list available models.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_ollama( WP_REST_Request $request ) {
		$url = trim( (string) $request->get_param( 'url' ) );
		if ( '' === $url ) {
			$url = $this->settings->get_ollama_url();
		}

		$response = wp_remote_get(
			trailingslashit( $url ) . 'api/tags',
			array( 'timeout' => 10 )
		);

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response(
				array(
					'connected' => false,
					'models'    => array(),
					'error'     => __( 'Cannot reach Ollama — make sure it is running', 'ai-elementor-builder' ),
				),
				200
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $data ) ) {
			return new WP_REST_Response(
				array(
					'connected' => false,
					'models'    => array(),
					'error'     => __( 'Cannot reach Ollama — make sure it is running', 'ai-elementor-builder' ),
				),
				200
			);
		}

		// /api/tags returns { models: [ { name: "llama3.1:8b", ... }, ... ] }.
		$models = array();
		if ( ! empty( $data['models'] ) && is_array( $data['models'] ) ) {
			foreach ( $data['models'] as $model ) {
				if ( isset( $model['name'] ) ) {
					$models[] = (string) $model['name'];
				}
			}
		}

		return new WP_REST_Response(
			array(
				'connected' => true,
				'models'    => $models,
				'error'     => '',
			),
			200
		);
	}
}
