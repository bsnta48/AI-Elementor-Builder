<?php
/**
 * REST: POST /ai-elementor/v1/plan-site
 *
 * Turns a natural-language site request into a structured sitemap (pages +
 * navigation order). Content generation happens later, per page, in
 * Build_Site_Controller.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Rest;

use AI_Elementor_Builder\Prompts\Site_Plan_Spec;
use AI_Elementor_Builder\Providers\Provider_Factory;
use AI_Elementor_Builder\Settings\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Plans a multi-page site from a prompt.
 */
class Site_Plan_Controller {

	const NAMESPACE = 'ai-elementor/v1';
	const ROUTE     = '/plan-site';

	const ALLOWED_SCOPES = array( 'fullpage', 'about', 'pricing', 'features', 'testimonials', 'contact', 'custom' );

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
	 * Capability gate: building a site creates + publishes pages.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_check() {
		if ( ! current_user_can( 'publish_pages' ) ) {
			return new WP_Error(
				'aieb_forbidden',
				__( 'You are not allowed to plan a site.', 'ai-elementor-builder' ),
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
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$provider_key = (string) $request->get_param( 'provider' );
		$prompt       = (string) $request->get_param( 'prompt' );

		if ( $this->settings->is_mock_mode() ) {
			return new WP_REST_Response( $this->normalize( $this->mock_plan(), $prompt ), 200 );
		}

		$provider = $this->factory->make( $provider_key );
		if ( null === $provider ) {
			return new WP_Error( 'aieb_unknown_provider', __( 'Unknown provider.', 'ai-elementor-builder' ), array( 'status' => 400 ) );
		}

		$model = (string) $request->get_param( 'model' );
		if ( '' === $model ) {
			$model = $this->factory->default_model( $provider_key );
		}

		$options = array(
			'system'     => Site_Plan_Spec::rules(),
			'max_tokens' => 4096,
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

		$parsed = $this->parse_json( $provider->extract_text( $result['json'] ) );
		if ( null === $parsed || empty( $parsed['pages'] ) ) {
			return new WP_Error( 'aieb_invalid_plan', __( 'Could not produce a site plan. Try rephrasing.', 'ai-elementor-builder' ), array( 'status' => 422 ) );
		}

		return new WP_REST_Response( $this->normalize( $parsed, $prompt ), 200 );
	}

	/**
	 * Validate + sanitize a raw plan into the response shape.
	 *
	 * @param array  $plan   Raw decoded plan.
	 * @param string $prompt Original prompt (fallback title source).
	 * @return array
	 */
	private function normalize( array $plan, string $prompt ): array {
		$site_title = isset( $plan['site_title'] ) ? sanitize_text_field( (string) $plan['site_title'] ) : '';
		if ( '' === $site_title ) {
			$site_title = wp_trim_words( $prompt, 6, '' );
		}

		$pages     = array();
		$seen_slug = array();
		$has_home  = false;

		foreach ( (array) $plan['pages'] as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$title = isset( $raw['title'] ) ? sanitize_text_field( (string) $raw['title'] ) : '';
			$brief = isset( $raw['brief'] ) ? sanitize_textarea_field( (string) $raw['brief'] ) : '';
			if ( '' === $title || '' === $brief ) {
				continue;
			}

			$slug = isset( $raw['slug'] ) ? sanitize_title( (string) $raw['slug'] ) : sanitize_title( $title );
			if ( '' === $slug || isset( $seen_slug[ $slug ] ) ) {
				$slug = $slug . '-' . ( count( $pages ) + 1 );
			}
			$seen_slug[ $slug ] = true;

			$role = ( isset( $raw['role'] ) && 'home' === $raw['role'] && ! $has_home ) ? 'home' : 'standard';
			if ( 'home' === $role ) {
				$has_home = true;
			}

			$scope = isset( $raw['scope'] ) ? sanitize_key( (string) $raw['scope'] ) : 'fullpage';
			if ( ! in_array( $scope, self::ALLOWED_SCOPES, true ) ) {
				$scope = 'fullpage';
			}

			$pages[] = array(
				'slug'      => $slug,
				'title'     => $title,
				'nav_label' => isset( $raw['nav_label'] ) ? sanitize_text_field( (string) $raw['nav_label'] ) : $title,
				'role'      => $role,
				'scope'     => $scope,
				'brief'     => $brief,
			);
		}

		// Guarantee exactly one home: promote the first page if none was flagged.
		if ( ! $has_home && ! empty( $pages ) ) {
			$pages[0]['role']  = 'home';
			$pages[0]['scope'] = 'fullpage';
		}

		// Navigation order: use the model's menu if it references real slugs, else page order.
		$menu = array();
		if ( ! empty( $plan['menu'] ) && is_array( $plan['menu'] ) ) {
			foreach ( $plan['menu'] as $slug ) {
				$slug = sanitize_title( (string) $slug );
				if ( isset( $seen_slug[ $slug ] ) && ! in_array( $slug, $menu, true ) ) {
					$menu[] = $slug;
				}
			}
		}
		foreach ( $pages as $p ) {
			if ( ! in_array( $p['slug'], $menu, true ) ) {
				$menu[] = $p['slug'];
			}
		}

		return array(
			'site_title' => $site_title,
			'pages'      => $pages,
			'menu'       => $menu,
		);
	}

	/**
	 * Strip fences and decode the model's JSON object.
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
	 * Canned sitemap for mock mode.
	 *
	 * @return array
	 */
	private function mock_plan(): array {
		return array(
			'site_title' => 'Acme Studio',
			'pages'      => array(
				array(
					'slug'      => 'home',
					'title'     => 'Home',
					'nav_label' => 'Home',
					'role'      => 'home',
					'scope'     => 'fullpage',
					'brief'     => 'Hero with the studio name, a bold value proposition and dual CTA; a 3-up services grid; an about teaser; a testimonials band; a closing CTA. Palette primary #4f46e5, accent #ec4899, bg #f8fafc.',
				),
				array(
					'slug'      => 'about',
					'title'     => 'About',
					'nav_label' => 'About',
					'role'      => 'standard',
					'scope'     => 'about',
					'brief'     => 'Studio story and mission, team highlights, and the values that drive the work. Same palette and tone as Home.',
				),
				array(
					'slug'      => 'services',
					'title'     => 'Services',
					'nav_label' => 'Services',
					'role'      => 'standard',
					'scope'     => 'features',
					'brief'     => 'Detailed service offerings as feature cards with icons, a process section, and a CTA. Same palette and tone as Home.',
				),
				array(
					'slug'      => 'contact',
					'title'     => 'Contact',
					'nav_label' => 'Contact',
					'role'      => 'standard',
					'scope'     => 'contact',
					'brief'     => 'A contact section with address, hours, and a prompt to get in touch, plus a closing CTA. Same palette and tone as Home.',
				),
			),
			'menu'       => array( 'home', 'about', 'services', 'contact' ),
		);
	}
}
