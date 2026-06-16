<?php
/**
 * REST: POST /ai-elementor/v1/generate
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Rest;

use AI_Elementor_Builder\History\History;
use AI_Elementor_Builder\Providers\Provider_Factory;
use AI_Elementor_Builder\References\Reference_Registry;
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

		// Inject a curated design reference as a few-shot exemplar when chosen.
		$reference_id = (string) $request->get_param( 'reference' );
		if ( '' !== $reference_id ) {
			$reference = $this->references->get( $reference_id );
			if ( $reference ) {
				$prompt .= "\n\nREFERENCE DESIGN — match the structure, layout sophistication, spacing rhythm, typography scale and color discipline of the example below. Adapt all content (text, labels, counts of items, colors if the request implies a different palette) to the user request above; do NOT copy its wording verbatim. Reuse its setting keys and nesting style. Reference Elementor JSON:\n"
					. (string) wp_json_encode( $reference['content'] );
			}
		}

		// Mock mode (WP_DEBUG only): return a canned template without touching a
		// real provider, so the UI → preview → push flow is testable for free.
		if ( $this->settings->is_mock_mode() ) {
			$template = $this->mock_template();
			History::add( get_current_user_id(), $prompt, $provider_key, $template );

			return new WP_REST_Response(
				array(
					'provider' => $provider_key,
					'model'    => 'mock',
					'mock'     => true,
					'template' => $template,
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

		// Full-page layouts produce large JSON; a low token cap truncates the
		// response and breaks JSON parsing. Give the model generous headroom.
		$options = array(
			'system'     => $this->system_prompt(),
			'max_tokens' => 16384,
		);
		if ( '' !== $model ) {
			$options['model'] = $model;
		}

		// Attach a reference image when both the data and a valid MIME are present.
		$image_data = (string) $request->get_param( 'image' );
		$image_mime = (string) $request->get_param( 'image_mime' );
		$allowed_mimes = array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif' );
		if ( '' !== $image_data && in_array( $image_mime, $allowed_mimes, true ) ) {
			$options['image'] = array(
				'mime' => $image_mime,
				'data' => $image_data,
			);
		}

		$result = $provider->generate( $prompt, $options );
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

		// Record this generation in the user's history (newest first, last 10).
		History::add( get_current_user_id(), $prompt, $provider_key, $validated['data'] );

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

	/**
	 * A hardcoded, valid Elementor template used by mock mode. Mirrors the exact
	 * top-level shape the system prompt requires: a hero container with a heading,
	 * a sub-heading, and a button.
	 *
	 * @return array
	 */
	private function mock_template(): array {
		return array(
			'version'       => '0.4',
			'type'          => 'page',
			'page_settings' => (object) array(),
			'content'       => array(
				array(
					'id'       => 'a1b2c3d4',
					'elType'   => 'container',
					'settings' => array(
						'content_width'          => 'boxed',
						'background_background'   => 'gradient',
						'background_color'        => '#4f46e5',
						'background_color_b'      => '#9333ea',
						'background_gradient_angle' => array( 'unit' => 'deg', 'size' => 135 ),
						'background_gradient_type'  => 'linear',
						'padding'                 => array( 'unit' => 'px', 'top' => '90', 'right' => '24', 'bottom' => '90', 'left' => '24' ),
						'flex_direction'          => 'column',
						'align_items'             => 'center',
						'gap'                     => array( 'unit' => 'px', 'size' => 20 ),
					),
					'elements' => array(
						array(
							'id'         => 'b2c3d4e5',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array(
								'title'                 => 'Mock Mode Is Working',
								'header_size'           => 'h1',
								'align'                 => 'center',
								'title_color'           => '#ffffff',
								'typography_typography' => 'custom',
								'typography_font_size'  => array( 'unit' => 'px', 'size' => 48 ),
								'typography_font_weight' => '700',
								'typography_line_height' => array( 'unit' => 'em', 'size' => 1.2 ),
							),
							'elements'   => array(),
						),
						array(
							'id'         => 'c3d4e5f6',
							'elType'     => 'widget',
							'widgetType' => 'text-editor',
							'settings'   => array(
								'editor'                => '<p>This template was returned instantly without calling any AI provider.</p>',
								'align'                 => 'center',
								'text_color'            => '#e9d5ff',
								'typography_typography' => 'custom',
								'typography_font_size'  => array( 'unit' => 'px', 'size' => 18 ),
							),
							'elements'   => array(),
						),
						array(
							'id'         => 'd4e5f6a7',
							'elType'     => 'widget',
							'widgetType' => 'button',
							'settings'   => array(
								'text'             => 'Get Started',
								'button_text'      => 'Get Started',
								'align'            => 'center',
								'button_link'      => array( 'url' => '#' ),
								'background_color' => '#ffffff',
								'button_text_color' => '#4f46e5',
								'border_radius'    => array( 'unit' => 'px', 'top' => '8', 'right' => '8', 'bottom' => '8', 'left' => '8' ),
								'text_padding'     => array( 'unit' => 'px', 'top' => '14', 'right' => '36', 'bottom' => '14', 'left' => '36' ),
							),
							'elements'   => array(),
						),
					),
				),
			),
		);
	}

	/**
	 * The detailed system prompt constraining the model to Elementor JSON.
	 *
	 * @return string
	 */
	private function system_prompt(): string {
		return <<<'PROMPT'
You are an Elementor page-template generator. Output ONLY a single valid JSON object — no markdown, no code fences, no prose, no explanation before or after.

The JSON MUST be a complete Elementor template document with this exact top-level shape:
{
  "version": "0.4",
  "type": "page",
  "page_settings": {},
  "content": [ ...top-level elements... ]
}

Every element in "content" (and recursively in any "elements" array) MUST have exactly these fields:
- "id": an 8-character lowercase hexadecimal string, unique within the document (e.g. "a1b2c3d4")
- "elType": one of "container" or "widget"
- "settings": an object of Elementor settings (use {} when none)
- "elements": an array of child elements (use [] when none)

Rules:
- Layout/structure elements use "elType": "container" and hold children in "elements".
- Content elements use "elType": "widget" and MUST additionally include "widgetType" (e.g. "heading", "text-editor", "button", "image", "icon-list"). Widgets do not contain other elements: their "elements" array is [].
- Use only standard Elementor core widget types.
- Nest containers to express rows/columns and sections.
- For a full page, output MULTIPLE top-level section containers in "content" (hero, features, about, testimonials, pricing/CTA, footer, etc.) — each a separate top-level container. Do not collapse a full page into a single section.
- Put real, sensible default content in widget "settings" (e.g. heading "title", button "text").
- Do NOT include any field other than those listed. Do NOT wrap the JSON in ```.

STYLING — always include visual styling in "settings" so the design has real colors, spacing, and typography. Use these Elementor setting keys:
- Container background: "background_background":"classic" with "background_color":"#RRGGBB"; OR a gradient with "background_background":"gradient","background_color":"#RRGGBB","background_color_b":"#RRGGBB","background_gradient_angle":{"unit":"deg","size":135},"background_gradient_type":"linear".
- Container layout/spacing: "padding":{"unit":"px","top":"80","right":"24","bottom":"80","left":"24"}, "flex_direction":"column"|"row", "justify_content":"center", "align_items":"center", "gap":{"unit":"px","size":24}.
- Heading color: "title_color":"#RRGGBB". Text color: "text_color":"#RRGGBB".
- Typography (any text widget): "typography_typography":"custom","typography_font_size":{"unit":"px","size":48},"typography_font_weight":"700","typography_line_height":{"unit":"em","size":1.2}.
- Button: "background_color":"#RRGGBB","button_text_color":"#ffffff","border_radius":{"unit":"px","top":"8","right":"8","bottom":"8","left":"8"},"text_padding":{"unit":"px","top":"14","right":"32","bottom":"14","left":"32"}.
- Sizes are objects {"unit":"px","size":N}; dimensions (padding/margin/border_radius) are {"unit":"px","top","right","bottom","left"}. Pick a coherent, attractive color palette and apply it consistently.

Return the JSON object and nothing else.
PROMPT;
	}
}
