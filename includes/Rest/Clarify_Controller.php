<?php
/**
 * REST: POST /ai-elementor/v1/clarify
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
 * Pre-generation step: inspects a natural-language prompt and either declares it
 * ready to build or returns a short set of clarifying questions (with selectable
 * options) plus an enriched brief and an inferred scope.
 *
 * The model returns a small JSON object:
 * {
 *   "ready": true|false,
 *   "scope": "fullpage|hero|pricing|about|features|testimonials|contact|custom",
 *   "enriched_prompt": "a fuller, build-ready brief derived from the user prompt",
 *   "questions": [
 *     { "id": "site_type", "question": "What kind of site?",
 *       "type": "single|multi",
 *       "options": [ { "label": "Business", "value": "business" }, ... ] }
 *   ]
 * }
 */
class Clarify_Controller {

	const NAMESPACE = 'ai-elementor/v1';
	const ROUTE     = '/clarify';

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
	 * @param Settings         $settings Settings handler (mock mode lookup).
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
		$provider_key = (string) $request->get_param( 'provider' );
		$prompt       = (string) $request->get_param( 'prompt' );

		// Mock mode (WP_DEBUG only): return canned questions so the clarify → build
		// flow is testable without an API key or cost.
		if ( $this->settings->is_mock_mode() ) {
			return new WP_REST_Response( $this->mock_clarification( $prompt ), 200 );
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

		// Clarification is short; a modest cap keeps the round-trip fast and cheap.
		$options = array(
			'system'     => $this->system_prompt(),
			'max_tokens' => 1024,
		);
		if ( '' !== $model ) {
			$options['model'] = $model;
		}

		$result = $provider->generate( $prompt, $options );
		if ( empty( $result['success'] ) ) {
			return new WP_Error(
				'aieb_provider_error',
				$result['error'] ? $result['error'] : __( 'Provider request failed.', 'ai-elementor-builder' ),
				array( 'status' => 502 )
			);
		}

		$text   = $provider->extract_text( $result['json'] );
		$parsed = $this->parse_json( $text );

		// If the model fails to return usable JSON, degrade gracefully: treat the
		// prompt as ready so the user is never blocked from generating.
		if ( null === $parsed ) {
			return new WP_REST_Response(
				array(
					'ready'           => true,
					'scope'           => 'custom',
					'enriched_prompt' => $prompt,
					'questions'       => array(),
				),
				200
			);
		}

		return new WP_REST_Response( $this->normalize( $parsed, $prompt ), 200 );
	}

	/**
	 * Strip code fences and decode the model's JSON object.
	 *
	 * @param string $text Raw provider text.
	 * @return array|null Decoded associative array, or null when unusable.
	 */
	private function parse_json( string $text ): ?array {
		$text = trim( $text );
		if ( '' === $text ) {
			return null;
		}

		// Drop ```json ... ``` fences if the model wrapped the object.
		if ( 0 === strpos( $text, '```' ) ) {
			$text = preg_replace( '/^```[a-z]*\s*/i', '', $text );
			$text = preg_replace( '/\s*```$/', '', (string) $text );
		}

		// Isolate the outermost JSON object when prose leaks in around it.
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end < $start ) {
			return null;
		}
		$text = substr( $text, $start, $end - $start + 1 );

		$data = json_decode( $text, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Coerce the decoded payload into the strict response shape, dropping anything
	 * malformed. Never trusts the model to be well-formed.
	 *
	 * @param array  $data   Decoded model payload.
	 * @param string $prompt Original user prompt (fallback for enriched_prompt).
	 * @return array
	 */
	private function normalize( array $data, string $prompt ): array {
		$ready = ! empty( $data['ready'] );

		$allowed_scopes = array( 'fullpage', 'custom', 'hero', 'pricing', 'about', 'features', 'testimonials', 'contact' );
		$scope          = isset( $data['scope'] ) ? sanitize_key( (string) $data['scope'] ) : 'custom';
		if ( ! in_array( $scope, $allowed_scopes, true ) ) {
			$scope = 'custom';
		}

		$enriched = isset( $data['enriched_prompt'] ) ? trim( (string) $data['enriched_prompt'] ) : '';
		if ( '' === $enriched ) {
			$enriched = $prompt;
		}

		$questions = array();
		if ( ! empty( $data['questions'] ) && is_array( $data['questions'] ) ) {
			foreach ( $data['questions'] as $q ) {
				$question = $this->normalize_question( $q );
				if ( null !== $question ) {
					$questions[] = $question;
				}
				// Cap at 4 questions to keep the interaction short.
				if ( count( $questions ) >= 4 ) {
					break;
				}
			}
		}

		// No questions means there is nothing to ask — treat as ready.
		if ( empty( $questions ) ) {
			$ready = true;
		}

		return array(
			'ready'           => $ready,
			'scope'           => $scope,
			'enriched_prompt' => $enriched,
			'questions'       => $ready ? array() : $questions,
		);
	}

	/**
	 * Validate and clean a single question object.
	 *
	 * @param mixed $q Raw question entry.
	 * @return array|null Clean question, or null when invalid.
	 */
	private function normalize_question( $q ): ?array {
		if ( ! is_array( $q ) || empty( $q['question'] ) || empty( $q['options'] ) || ! is_array( $q['options'] ) ) {
			return null;
		}

		$type = ( isset( $q['type'] ) && 'multi' === $q['type'] ) ? 'multi' : 'single';

		$options = array();
		foreach ( $q['options'] as $opt ) {
			if ( is_array( $opt ) && ! empty( $opt['label'] ) ) {
				$label = sanitize_text_field( (string) $opt['label'] );
				$value = isset( $opt['value'] ) ? sanitize_text_field( (string) $opt['value'] ) : $label;
			} elseif ( is_string( $opt ) && '' !== trim( $opt ) ) {
				$label = sanitize_text_field( $opt );
				$value = $label;
			} else {
				continue;
			}
			$options[] = array(
				'label' => $label,
				'value' => $value,
			);
			if ( count( $options ) >= 6 ) {
				break;
			}
		}

		if ( count( $options ) < 2 ) {
			return null;
		}

		$id = isset( $q['id'] ) ? sanitize_key( (string) $q['id'] ) : sanitize_key( substr( (string) $q['question'], 0, 20 ) );
		if ( '' === $id ) {
			$id = 'q' . wp_rand( 1000, 9999 );
		}

		return array(
			'id'       => $id,
			'question' => sanitize_text_field( (string) $q['question'] ),
			'type'     => $type,
			'options'  => $options,
		);
	}

	/**
	 * Canned clarification for mock mode: returns questions for a short/vague
	 * prompt, otherwise declares it ready.
	 *
	 * @param string $prompt User prompt.
	 * @return array
	 */
	private function mock_clarification( string $prompt ): array {
		$word_count = count( preg_split( '/\s+/', trim( $prompt ) ) );

		$mock_brief = $prompt
			. "\nPalette: primary #4f46e5, accent #ec4899, bg #f8fafc, surface #ffffff, text #0f172a."
			. "\nFonts: bold geometric sans headings, clean humanist sans body."
			. "\nSections: hero with dual CTA → 3 feature cards (icon-box) → stats band → testimonials → pricing (3 tiers, middle highlighted) → closing CTA → footer."
			. "\nTone: confident, modern, generous spacing, soft card shadows.";

		if ( $word_count >= 8 ) {
			return array(
				'ready'           => true,
				'scope'           => 'fullpage',
				'enriched_prompt' => $mock_brief,
				'questions'       => array(),
			);
		}

		return array(
			'ready'           => false,
			'scope'           => 'fullpage',
			'enriched_prompt' => $mock_brief,
			'questions'       => array(
				array(
					'id'       => 'site_type',
					'question' => __( 'What kind of site is this?', 'ai-elementor-builder' ),
					'type'     => 'single',
					'options'  => array(
						array( 'label' => __( 'Business', 'ai-elementor-builder' ), 'value' => 'business' ),
						array( 'label' => __( 'Portfolio', 'ai-elementor-builder' ), 'value' => 'portfolio' ),
						array( 'label' => __( 'Online store', 'ai-elementor-builder' ), 'value' => 'ecommerce' ),
						array( 'label' => __( 'Blog', 'ai-elementor-builder' ), 'value' => 'blog' ),
						array( 'label' => __( 'Landing page', 'ai-elementor-builder' ), 'value' => 'landing' ),
					),
				),
				array(
					'id'       => 'goal',
					'question' => __( 'What is the primary goal?', 'ai-elementor-builder' ),
					'type'     => 'single',
					'options'  => array(
						array( 'label' => __( 'Generate leads', 'ai-elementor-builder' ), 'value' => 'leads' ),
						array( 'label' => __( 'Sell products', 'ai-elementor-builder' ), 'value' => 'sell' ),
						array( 'label' => __( 'Showcase work', 'ai-elementor-builder' ), 'value' => 'showcase' ),
						array( 'label' => __( 'Inform / educate', 'ai-elementor-builder' ), 'value' => 'inform' ),
					),
				),
				array(
					'id'       => 'style',
					'question' => __( 'Which visual style fits best?', 'ai-elementor-builder' ),
					'type'     => 'single',
					'options'  => array(
						array( 'label' => __( 'Modern & minimal', 'ai-elementor-builder' ), 'value' => 'minimal' ),
						array( 'label' => __( 'Bold & colorful', 'ai-elementor-builder' ), 'value' => 'bold' ),
						array( 'label' => __( 'Corporate', 'ai-elementor-builder' ), 'value' => 'corporate' ),
						array( 'label' => __( 'Playful', 'ai-elementor-builder' ), 'value' => 'playful' ),
					),
				),
			),
		);
	}

	/**
	 * The system prompt instructing the model to assess the request and either
	 * ask clarifying questions or declare it ready.
	 *
	 * @return string
	 */
	private function system_prompt(): string {
		return <<<'PROMPT'
You are the intake assistant for an Elementor page-template generator. Your job is to decide whether a user's request has enough detail to build a strong page, and if not, to ask a SHORT set of clarifying questions with selectable options.

Output ONLY a single valid JSON object — no markdown, no code fences, no prose before or after — with exactly this shape:
{
  "ready": true | false,
  "scope": "fullpage" | "hero" | "pricing" | "about" | "features" | "testimonials" | "contact" | "custom",
  "enriched_prompt": "a fuller, build-ready design brief derived from the user's request",
  "questions": [
    {
      "id": "short_snake_case_id",
      "question": "A concise question",
      "type": "single" | "multi",
      "options": [ { "label": "Human label", "value": "short_value" } ]
    }
  ]
}

Rules:
- "ready": true when the request is specific enough to build immediately (it names the kind of page/section, its purpose, and at least a rough sense of content or style). false when it is vague (e.g. "make me a website", "build a landing page").
- When "ready" is true, return an empty "questions" array.
- When "ready" is false, return 2 to 4 questions MAX. Each question MUST have 2 to 6 options. Prefer "single" type; use "multi" only when several choices genuinely combine (e.g. which sections to include).
- Ask only what materially changes the design: kind of site/section, primary goal, visual style/tone, key sections, audience. Never ask for information you can reasonably assume.
- "scope": your best guess at what to build. Use "fullpage" for a whole site/page request, a specific section name when the user clearly wants one section, or "custom" otherwise.
- "enriched_prompt": ALWAYS rewrite the user's request into a concrete, build-ready design brief. Keep the user's intent; do not invent a different business. This string is fed straight to the page builder, so make it specific. It MUST include:
  • A COLOR PALETTE with real hex values: primary, accent, page background, card/surface, and text colors (e.g. "Palette: primary #4f46e5, accent #ec4899, bg #f8fafc, surface #ffffff, text #0f172a").
  • A FONT PAIRING (a heading font feel and a body font feel, e.g. "Fonts: bold geometric sans headings, clean humanist sans body").
  • An ORDERED SECTION LIST naming each section to build (e.g. "Sections: hero with dual CTA → 3 feature cards → stats band → testimonials → pricing (3 tiers, middle highlighted) → closing CTA → footer").
  • A short tone/voice note and any content specifics implied by the answers.
  Write it as a few tight lines, not prose paragraphs.

Return the JSON object and nothing else.
PROMPT;
	}
}
