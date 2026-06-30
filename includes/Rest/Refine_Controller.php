<?php
/**
 * REST: POST /ai-elementor/v1/refine
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Rest;

use AI_Elementor_Builder\Prompts\Design_Spec;
use AI_Elementor_Builder\Providers\Provider_Factory;
use AI_Elementor_Builder\Settings\Settings;
use AI_Elementor_Builder\Validator\Elementor_Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Iterate on an existing Elementor template: takes the current template JSON plus
 * a plain-language instruction ("make the hero darker", "add a pricing section")
 * and returns the FULL modified template, re-validated.
 */
class Refine_Controller {

	const NAMESPACE = 'ai-elementor/v1';
	const ROUTE     = '/refine';

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
	 * @param Provider_Factory    $factory   Provider factory.
	 * @param Elementor_Validator $validator Elementor JSON validator.
	 * @param Settings            $settings  Settings handler (mock mode lookup).
	 */
	public function __construct( Provider_Factory $factory, Elementor_Validator $validator, Settings $settings ) {
		$this->factory   = $factory;
		$this->validator = $validator;
		$this->settings  = $settings;
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
					'provider'    => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => $this->factory->provider_keys(),
						'sanitize_callback' => 'sanitize_key',
					),
					'instruction' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && '' !== trim( $value );
						},
					),
					// The current template to modify: the full template object
					// ({ version, type, page_settings, content[] }) or a bare content array.
					'template'    => array(
						'required'          => true,
						'validate_callback' => static function ( $value ) {
							return is_array( $value );
						},
					),
					'model'       => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Capability gate: callers must be able to edit posts (mirrors /generate).
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
		// Refining a full page is as heavy as generating one — give PHP headroom.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$provider_key = (string) $request->get_param( 'provider' );
		$instruction  = (string) $request->get_param( 'instruction' );
		$template     = $request->get_param( 'template' );

		// Re-validate the incoming template — never trust the client payload. This
		// also normalizes a bare content array into a full template document.
		$incoming = $this->coerce_template( $template );
		$base     = $this->validator->validate( (string) wp_json_encode( $incoming ) );
		if ( empty( $base['valid'] ) ) {
			return new WP_Error(
				'aieb_invalid_template',
				__( 'The template to refine is not valid Elementor JSON.', 'ai-elementor-builder' ),
				array( 'status' => 422 )
			);
		}

		// Mock mode (WP_DEBUG only): echo the template back with a marker heading so
		// the refine round-trip is testable without an API key.
		if ( $this->settings->is_mock_mode() ) {
			return new WP_REST_Response(
				array(
					'provider' => $provider_key,
					'model'    => 'mock',
					'mock'     => true,
					'template' => $base['data'],
				),
				200
			);
		}

		$provider = $this->factory->make( $provider_key );
		if ( null === $provider ) {
			return new WP_Error(
				'aieb_unknown_provider',
				__( 'Unknown provider.', 'ai-elementor-builder' ),
				array( 'status' => 400 )
			);
		}

		$model = (string) $request->get_param( 'model' );
		if ( '' === $model ) {
			$model = $this->factory->default_model( $provider_key );
		}

		$user_prompt = "CURRENT ELEMENTOR TEMPLATE (JSON):\n"
			. (string) wp_json_encode( $base['data'] )
			. "\n\nMODIFICATION REQUEST:\n" . $instruction;

		$options = array(
			'system'     => $this->system_prompt(),
			'max_tokens' => 16384,
		);
		if ( '' !== $model ) {
			$options['model'] = $model;
		}

		$result = $provider->generate( $user_prompt, $options );
		if ( empty( $result['success'] ) ) {
			return new WP_Error(
				'aieb_provider_error',
				$result['error'] ? $result['error'] : __( 'Provider request failed.', 'ai-elementor-builder' ),
				array( 'status' => 502 )
			);
		}

		$text = $provider->extract_text( $result['json'] );
		if ( '' === $text ) {
			return new WP_Error(
				'aieb_empty_response',
				__( 'Provider returned no usable text.', 'ai-elementor-builder' ),
				array( 'status' => 502 )
			);
		}

		$validated = $this->validator->validate( $text );
		if ( empty( $validated['valid'] ) ) {
			return new WP_Error(
				'aieb_invalid_template',
				$validated['error'],
				array(
					'status' => 422,
					'raw'    => $text,
				)
			);
		}

		return new WP_REST_Response(
			array(
				'provider' => $provider_key,
				'model'    => $model,
				'template' => $validated['data'],
			),
			200
		);
	}

	/**
	 * Wrap a bare content array in a template envelope; pass a full template
	 * object through unchanged.
	 *
	 * @param array $template Incoming template or content array.
	 * @return array Template-shaped array with a "content" key.
	 */
	private function coerce_template( array $template ): array {
		if ( isset( $template['content'] ) && is_array( $template['content'] ) ) {
			return $template;
		}

		// Assume a bare elements array.
		return array(
			'version'       => Elementor_Validator::VERSION,
			'type'          => 'page',
			'page_settings' => array(),
			'content'       => array_values( $template ),
		);
	}

	/**
	 * The system prompt: same Elementor JSON contract as generation, framed as an
	 * edit that preserves everything not mentioned in the instruction.
	 *
	 * @return string
	 */
	private function system_prompt(): string {
		$contract = <<<'PROMPT'
You are an Elementor page-template editor. You receive an existing Elementor template as JSON and a modification request. Apply ONLY the requested changes and return the COMPLETE, updated template.

Output ONLY a single valid JSON object — no markdown, no code fences, no prose, no explanation before or after.

The JSON MUST keep this exact top-level shape:
{
  "version": "0.4",
  "type": "page",
  "page_settings": {},
  "content": [ ...top-level elements... ]
}

Every element in "content" (and recursively in any "elements" array) MUST have exactly these fields:
- "id": an 8-character lowercase hexadecimal string, unique within the document
- "elType": "container" or "widget"
- "settings": an object of Elementor settings (use {} when none)
- "elements": an array of child elements (use [] when none)
- widgets ("elType":"widget") additionally include "widgetType" and keep "elements" as [].

Editing rules:
- PRESERVE the existing structure, content, ids, and styling EXCEPT where the modification request requires a change. Do not redesign unmentioned sections.
- Reuse existing element "id"s for elements you keep. Generate new 8-char hex ids only for newly added elements.
- When adding a section, match the spacing rhythm, typography scale, and color palette already present so it looks native to the page.
- Keep responsive (_tablet/_mobile) setting variants consistent with the rest of the page.
- Use only standard Elementor core widget types.

Return the full updated JSON object and nothing else.
PROMPT;

		return $contract . "\n\n" . Design_Spec::rules()
			. "\n\nWhen ADDING new sections or elements, follow the design system above so they match the existing page. When only TWEAKING existing elements, respect the user's explicit request even if it diverges from these defaults.";
	}
}
