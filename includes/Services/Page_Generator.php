<?php
/**
 * Shared single-page generation service.
 *
 * Encapsulates the AI generation pipeline used by both the interactive
 * Generate_Controller and the multi-page Build_Site_Controller: exemplar
 * injection, mock mode, provider call, text extraction, and validation.
 *
 * Pure generation — it does NOT record history or write to any page. Callers
 * decide what to do with the validated template.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Services;

use AI_Elementor_Builder\Prompts\Design_Spec;
use AI_Elementor_Builder\Providers\Provider_Factory;
use AI_Elementor_Builder\References\Reference_Registry;
use AI_Elementor_Builder\Settings\Settings;
use AI_Elementor_Builder\Validator\Elementor_Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Generates a validated Elementor template from a natural-language prompt.
 */
class Page_Generator {

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
	 * Generate a validated Elementor template.
	 *
	 * @param string $prompt Natural-language prompt.
	 * @param array  $args   {
	 *     @type string $provider   Provider key (required).
	 *     @type string $model      Model id (optional; falls back to provider default).
	 *     @type string $reference  Explicit reference exemplar id (optional).
	 *     @type string $scope      Inferred scope for auto-selected exemplars (optional).
	 *     @type string $image      Reference image as raw base64, no data: prefix (optional).
	 *     @type string $image_mime Reference image MIME (optional).
	 * }
	 * @return array {
	 *     @type bool        $success    Whether a valid template was produced.
	 *     @type array|null  $template   Validated template (envelope + content) on success.
	 *     @type string      $provider   Provider key used.
	 *     @type string      $model      Model used ('mock' in mock mode).
	 *     @type bool        $mock       Whether mock mode produced this.
	 *     @type string      $error_code Machine error code on failure.
	 *     @type string      $error      Human error message on failure.
	 *     @type int         $status     Suggested HTTP status on failure.
	 *     @type string      $raw        Raw provider text when the template was invalid.
	 * }
	 */
	public function generate( string $prompt, array $args = array() ): array {
		$provider_key = isset( $args['provider'] ) ? (string) $args['provider'] : '';

		$prompt = $this->with_exemplars(
			$prompt,
			isset( $args['reference'] ) ? (string) $args['reference'] : '',
			isset( $args['scope'] ) ? (string) $args['scope'] : ''
		);

		// Mock mode (WP_DEBUG only): canned template, no provider call.
		if ( $this->settings->is_mock_mode() ) {
			return array(
				'success'  => true,
				'template' => $this->mock_template(),
				'provider' => $provider_key,
				'model'    => 'mock',
				'mock'     => true,
			);
		}

		$provider = $this->factory->make( $provider_key );
		if ( null === $provider ) {
			return $this->fail( 'aieb_unknown_provider', __( 'Unknown provider.', 'ai-elementor-builder' ), 400, $provider_key );
		}

		$model = isset( $args['model'] ) ? (string) $args['model'] : '';
		if ( '' === $model ) {
			$model = $this->factory->default_model( $provider_key );
		}

		// Full-page layouts produce large JSON; give the model generous headroom.
		$options = array(
			'system'     => $this->system_prompt(),
			'max_tokens' => 16384,
		);
		if ( '' !== $model ) {
			$options['model'] = $model;
		}

		$image_data    = isset( $args['image'] ) ? (string) $args['image'] : '';
		$image_mime    = isset( $args['image_mime'] ) ? (string) $args['image_mime'] : '';
		$allowed_mimes = array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif' );
		if ( '' !== $image_data && in_array( $image_mime, $allowed_mimes, true ) ) {
			$options['image'] = array(
				'mime' => $image_mime,
				'data' => $image_data,
			);
		}

		$result = $provider->generate( $prompt, $options );
		if ( empty( $result['success'] ) ) {
			$message = ! empty( $result['error'] ) ? $result['error'] : __( 'Provider request failed.', 'ai-elementor-builder' );
			return $this->fail( 'aieb_provider_error', $message, 502, $provider_key, $model );
		}

		$text = $provider->extract_text( $result['json'] );
		if ( '' === $text ) {
			return $this->fail( 'aieb_empty_response', __( 'Provider returned no usable text.', 'ai-elementor-builder' ), 502, $provider_key, $model );
		}

		$validated = $this->validator->validate( $text );
		if ( empty( $validated['valid'] ) ) {
			$fail        = $this->fail( 'aieb_invalid_template', (string) $validated['error'], 422, $provider_key, $model );
			$fail['raw'] = $text;
			return $fail;
		}

		return array(
			'success'  => true,
			'template' => $validated['data'],
			'provider' => $provider_key,
			'model'    => $model,
			'mock'     => false,
		);
	}

	/**
	 * Append curated design exemplars to the prompt as few-shot examples.
	 * An explicit pick wins; otherwise auto-select the best exemplar(s).
	 *
	 * @param string $prompt       User prompt.
	 * @param string $reference_id Explicit reference id, or ''.
	 * @param string $scope        Inferred scope, or ''.
	 * @return string Augmented prompt.
	 */
	private function with_exemplars( string $prompt, string $reference_id, string $scope ): string {
		$exemplars = array();
		if ( '' !== $reference_id ) {
			$reference = $this->references->get( $reference_id );
			if ( $reference ) {
				$exemplars[] = $reference['content'];
			}
		} else {
			foreach ( $this->references->auto_select( $scope, $prompt ) as $ref ) {
				$exemplars[] = $ref['content'];
			}
		}

		if ( empty( $exemplars ) ) {
			return $prompt;
		}

		return $prompt
			. "\n\nREFERENCE DESIGNS — match the structure, layout sophistication, spacing rhythm, typography scale and color discipline of the example(s) below. Adapt all content (text, labels, counts of items, colors if the request implies a different palette) to the user request above; do NOT copy their wording verbatim. Reuse their setting keys and nesting style. Reference Elementor JSON:\n"
			. (string) wp_json_encode( $exemplars );
	}

	/**
	 * Build a failure result.
	 *
	 * @param string $code     Error code.
	 * @param string $message  Human message.
	 * @param int    $status   HTTP status.
	 * @param string $provider Provider key.
	 * @param string $model    Model id.
	 * @return array
	 */
	private function fail( string $code, string $message, int $status, string $provider = '', string $model = '' ): array {
		return array(
			'success'    => false,
			'template'   => null,
			'provider'   => $provider,
			'model'      => $model,
			'mock'       => false,
			'error_code' => $code,
			'error'      => $message,
			'status'     => $status,
		);
	}

	/**
	 * A hardcoded, valid Elementor template used by mock mode. Mirrors the exact
	 * top-level shape the system prompt requires: a hero container with a heading,
	 * a sub-heading, and a button.
	 *
	 * @return array
	 */
	public function mock_template(): array {
		return array(
			'version'       => '0.4',
			'type'          => 'page',
			'page_settings' => (object) array(),
			'content'       => array(
				array(
					'id'       => 'a1b2c3d4',
					'elType'   => 'container',
					'settings' => array(
						'content_width'             => 'boxed',
						'background_background'     => 'gradient',
						'background_color'          => '#4f46e5',
						'background_color_b'        => '#9333ea',
						'background_gradient_angle' => array( 'unit' => 'deg', 'size' => 135 ),
						'background_gradient_type'  => 'linear',
						'padding'                   => array( 'unit' => 'px', 'top' => '90', 'right' => '24', 'bottom' => '90', 'left' => '24' ),
						'flex_direction'            => 'column',
						'align_items'               => 'center',
						'gap'                       => array( 'unit' => 'px', 'size' => 20 ),
					),
					'elements' => array(
						array(
							'id'         => 'b2c3d4e5',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array(
								'title'                  => 'Mock Mode Is Working',
								'header_size'            => 'h1',
								'align'                  => 'center',
								'title_color'            => '#ffffff',
								'typography_typography'  => 'custom',
								'typography_font_size'   => array( 'unit' => 'px', 'size' => 48 ),
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
								'text'               => 'Get Started',
								'button_text'        => 'Get Started',
								'align'              => 'center',
								'button_link'        => array( 'url' => '#' ),
								'background_color'   => '#ffffff',
								'button_text_color'  => '#4f46e5',
								'border_radius'      => array( 'unit' => 'px', 'top' => '8', 'right' => '8', 'bottom' => '8', 'left' => '8' ),
								'text_padding'       => array( 'unit' => 'px', 'top' => '14', 'right' => '36', 'bottom' => '14', 'left' => '36' ),
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
	public function system_prompt(): string {
		$contract = <<<'PROMPT'
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

IMAGES — never invent or hardcode a real image URL (they will 404). For every image widget set "image":{"url":"","alt":"<3-6 descriptive keywords for the photo, e.g. 'modern office team meeting'>"}. For a container background photo set "background_background":"classic","background_image":{"url":"","alt":"<keywords>"}. Leave "url" empty; the plugin resolves real photos from the keywords. Always provide vivid, specific "alt" keywords.

STYLING — always include visual styling in "settings" so the design has real colors, spacing, and typography. Use these Elementor setting keys:
- Container background: "background_background":"classic" with "background_color":"#RRGGBB"; OR a gradient with "background_background":"gradient","background_color":"#RRGGBB","background_color_b":"#RRGGBB","background_gradient_angle":{"unit":"deg","size":135},"background_gradient_type":"linear".
- Container layout/spacing: "padding":{"unit":"px","top":"80","right":"24","bottom":"80","left":"24"}, "flex_direction":"column"|"row", "justify_content":"center", "align_items":"center", "gap":{"unit":"px","size":24}.
- Heading color: "title_color":"#RRGGBB". Text color: "text_color":"#RRGGBB".
- Typography (any text widget): "typography_typography":"custom","typography_font_size":{"unit":"px","size":48},"typography_font_weight":"700","typography_line_height":{"unit":"em","size":1.2}.
- Button: "background_color":"#RRGGBB","button_text_color":"#ffffff","border_radius":{"unit":"px","top":"8","right":"8","bottom":"8","left":"8"},"text_padding":{"unit":"px","top":"14","right":"32","bottom":"14","left":"32"}.
- Sizes are objects {"unit":"px","size":N}; dimensions (padding/margin/border_radius) are {"unit":"px","top","right","bottom","left"}. Pick a coherent, attractive color palette and apply it consistently.

RESPONSIVE — the layout MUST adapt to tablet and mobile. Elementor stores per-breakpoint values under the SAME setting key with a "_tablet" or "_mobile" suffix; desktop is the unsuffixed key. Always provide responsive values where they matter:
- Multi-column/row containers ("flex_direction":"row") MUST stack on mobile: add "flex_direction_mobile":"column" (and "flex_direction_tablet":"column" when 3+ columns). Reset alignment if needed (e.g. "align_items_mobile":"stretch").
- Reduce section spacing on smaller screens: e.g. "padding":{"unit":"px","top":"80",...} with "padding_tablet":{"unit":"px","top":"56",...} and "padding_mobile":{"unit":"px","top":"40",...}.
- Scale large typography down: a heading with "typography_font_size":{"unit":"px","size":48} should add "typography_font_size_tablet":{"unit":"px","size":36} and "typography_font_size_mobile":{"unit":"px","size":28}.
- Shrink gaps on mobile when large: pair "gap" with "gap_mobile".
- Suffixed keys take the SAME value shape as their base key (size objects stay size objects, dimensions stay dimension objects). Only add a suffixed key when the responsive value differs from desktop.

Return the JSON object and nothing else.
PROMPT;

		return $contract . "\n\n" . Design_Spec::rules();
	}
}
