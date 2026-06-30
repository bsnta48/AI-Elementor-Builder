<?php
/**
 * REST: POST /ai-elementor/v1/chat
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Rest;

use AI_Elementor_Builder\Prompts\Design_Spec;
use AI_Elementor_Builder\Providers\Provider_Factory;
use AI_Elementor_Builder\Settings\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Conversational planning endpoint. The model acts as a design consultant: it
 * discusses the user's idea over multiple turns and progressively assembles a
 * concrete design brief. It does NOT emit template JSON — generation happens
 * later, on demand, via Generate_Controller using the finalized brief.
 */
class Chat_Controller {

	const NAMESPACE = 'ai-elementor/v1';
	const ROUTE     = '/chat';

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
					'messages' => array(
						'type'     => 'array',
						'required' => true,
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
	 * Capability gate.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_check() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'aieb_forbidden',
				__( 'You are not allowed to use the builder.', 'ai-elementor-builder' ),
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
		$messages     = (array) $request->get_param( 'messages' );
		$transcript   = $this->build_transcript( $messages );
		if ( '' === $transcript ) {
			return new WP_Error( 'aieb_empty_chat', __( 'No message to send.', 'ai-elementor-builder' ), array( 'status' => 400 ) );
		}

		// Mock mode (WP_DEBUG only): canned consultant turn, no provider call.
		if ( $this->settings->is_mock_mode() ) {
			return new WP_REST_Response( $this->mock_reply( $messages ), 200 );
		}

		$provider = $this->factory->make( $provider_key );
		if ( null === $provider ) {
			return new WP_Error( 'aieb_unknown_provider', __( 'Unknown provider.', 'ai-elementor-builder' ), array( 'status' => 400 ) );
		}

		$model   = (string) $request->get_param( 'model' );
		if ( '' === $model ) {
			$model = $this->factory->default_model( $provider_key );
		}
		$options = array(
			'system'     => $this->system_prompt(),
			'max_tokens' => 1024,
		);
		if ( '' !== $model ) {
			$options['model'] = $model;
		}

		$result = $provider->generate( $transcript, $options );
		if ( empty( $result['success'] ) ) {
			return new WP_Error(
				'aieb_provider_error',
				$result['error'] ? $result['error'] : __( 'Provider request failed.', 'ai-elementor-builder' ),
				array( 'status' => 502 )
			);
		}

		$text   = $provider->extract_text( $result['json'] );
		$parsed = $this->parse_json( $text );

		if ( null === $parsed ) {
			// Not JSON — treat the whole reply as conversational text, keep brief empty.
			return new WP_REST_Response(
				array(
					'reply' => '' !== trim( $text ) ? trim( $text ) : __( 'Could you tell me a bit more about what you want to build?', 'ai-elementor-builder' ),
					'brief' => '',
					'ready' => false,
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'reply' => isset( $parsed['reply'] ) ? trim( (string) $parsed['reply'] ) : '',
				'brief' => isset( $parsed['brief'] ) ? trim( (string) $parsed['brief'] ) : '',
				'ready' => ! empty( $parsed['ready'] ),
			),
			200
		);
	}

	/**
	 * Render the conversation as a plain-text transcript for the provider.
	 *
	 * @param array $messages [ { role, content } ].
	 * @return string
	 */
	private function build_transcript( array $messages ): string {
		$lines = array();
		foreach ( $messages as $m ) {
			if ( ! is_array( $m ) || empty( $m['content'] ) ) {
				continue;
			}
			$role    = ( isset( $m['role'] ) && 'assistant' === $m['role'] ) ? 'Assistant' : 'User';
			$lines[] = $role . ': ' . trim( (string) $m['content'] );
		}
		if ( empty( $lines ) ) {
			return '';
		}
		return "Conversation so far:\n" . implode( "\n", $lines )
			. "\n\nRespond as the Assistant with your JSON object now.";
	}

	/**
	 * Strip fences and decode the model's JSON object (mirrors Clarify_Controller).
	 *
	 * @param string $text Raw provider text.
	 * @return array|null
	 */
	private function parse_json( string $text ): ?array {
		$text = trim( $text );
		if ( '' === $text ) {
			return null;
		}
		if ( 0 === strpos( $text, '```' ) ) {
			$text = preg_replace( '/^```[a-z]*\s*/i', '', $text );
			$text = preg_replace( '/\s*```$/', '', (string) $text );
		}
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end < $start ) {
			return null;
		}
		$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Canned consultant turn for mock mode.
	 *
	 * @param array $messages Conversation.
	 * @return array
	 */
	private function mock_reply( array $messages ): array {
		$turns = 0;
		foreach ( $messages as $m ) {
			if ( is_array( $m ) && isset( $m['role'] ) && 'user' === $m['role'] ) {
				++$turns;
			}
		}

		$brief = "Palette: primary #4f46e5, accent #ec4899, bg #f8fafc, surface #ffffff, text #0f172a.\n"
			. "Fonts: bold geometric sans headings, clean humanist sans body.\n"
			. "Sections: hero with dual CTA → 3 feature cards → stats band → testimonials → pricing → closing CTA → footer.\n"
			. 'Tone: confident, modern, generous spacing.';

		if ( $turns >= 2 ) {
			return array(
				'reply' => __( '(mock) Got it — I’ve drafted a plan on the right. Tweak it or hit “Generate design” when ready.', 'ai-elementor-builder' ),
				'brief' => $brief,
				'ready' => true,
			);
		}
		return array(
			'reply' => __( '(mock) Sounds good. Who is the audience, and what’s the main action you want visitors to take?', 'ai-elementor-builder' ),
			'brief' => $brief,
			'ready' => false,
		);
	}

	/**
	 * System prompt: the design consultant persona + strict JSON output contract.
	 *
	 * @return string
	 */
	private function system_prompt(): string {
		$base = <<<'PROMPT'
You are a friendly, sharp web-design consultant helping a user plan a page they will build with an Elementor/Gutenberg page builder. You are in the PLANNING phase: have a short back-and-forth to understand the goal, audience, content, and style. Do NOT write any page-builder JSON or HTML — generation happens later when the user clicks "Generate design".

Output ONLY a single valid JSON object — no markdown, no code fences, no prose outside it — with exactly:
{
  "reply": "your next conversational message to the user (1-4 short sentences; ask at most one focused question, or confirm you're ready)",
  "brief": "the CUMULATIVE design brief so far — rewrite it in full each turn, incorporating everything decided",
  "ready": true | false
}

Rules:
- "reply": natural, concise, helpful. Early on, ask one question at a time (audience, goal, must-have sections, tone/style). Once you have enough, say you're ready and invite them to Generate.
- "brief": ALWAYS a complete, build-ready brief reflecting the whole conversation. Include a hex COLOR PALETTE (primary, accent, bg, surface, text), a FONT PAIRING (heading + body feel), an ORDERED SECTION LIST, and tone/content notes. Fill sensible defaults for anything not yet specified rather than leaving it blank.
- "ready": true once the brief is specific enough to build a strong page (kind of page, purpose, sections, and a rough style are settled).
- Never include template JSON or code. Keep the conversation moving toward a buildable plan.
PROMPT;

		return $base . "\n\nUse this design language when shaping the brief:\n" . Design_Spec::rules();
	}
}
