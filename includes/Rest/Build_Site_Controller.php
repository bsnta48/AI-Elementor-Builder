<?php
/**
 * REST: POST /ai-elementor/v1/build-site
 *
 * Builds a multi-page site from an approved sitemap. Client-driven to stay
 * within request timeouts:
 *  - mode "page": generate ONE page, create the WP page, write its Elementor
 *    data, return the new page id. The client loops over every page.
 *  - mode "finalize": with all page ids collected, build the nav menu, assign it
 *    to the theme's primary location, and optionally set the home page.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Rest;

use AI_Elementor_Builder\Media\Image_Resolver;
use AI_Elementor_Builder\Media\Stock_Image_Provider;
use AI_Elementor_Builder\Providers\Provider_Factory;
use AI_Elementor_Builder\References\Reference_Registry;
use AI_Elementor_Builder\Services\Elementor_Page_Writer;
use AI_Elementor_Builder\Services\Page_Generator;
use AI_Elementor_Builder\Settings\Settings;
use AI_Elementor_Builder\Validator\Elementor_Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Builds pages, a navigation menu, and the home page for a planned site.
 */
class Build_Site_Controller {

	const NAMESPACE = 'ai-elementor/v1';
	const ROUTE     = '/build-site';

	/**
	 * @var Page_Generator
	 */
	private $generator;

	/**
	 * @var Image_Resolver
	 */
	private $images;

	/**
	 * @var Elementor_Page_Writer
	 */
	private $writer;

	/**
	 * @var Provider_Factory
	 */
	private $factory;

	/**
	 * @param Provider_Factory    $factory    Provider factory.
	 * @param Elementor_Validator $validator  Elementor JSON validator.
	 * @param Settings            $settings   Settings handler.
	 * @param Reference_Registry  $references Design reference library.
	 */
	public function __construct( Provider_Factory $factory, Elementor_Validator $validator, Settings $settings, Reference_Registry $references ) {
		$this->factory   = $factory;
		$this->generator = new Page_Generator( $factory, $validator, $settings, $references );
		$this->images    = new Image_Resolver( new Stock_Image_Provider( $settings ) );
		$this->writer    = new Elementor_Page_Writer();
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
					'mode'       => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => array( 'page', 'finalize' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'provider'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'model'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'site_title' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					// mode=page: the single page to build { slug,title,brief,scope }.
					'page'       => array(
						'type'     => 'object',
						'required' => false,
					),
					// mode=finalize: ordered [ { slug, page_id, nav_label } ].
					'pages'      => array(
						'type'     => 'array',
						'required' => false,
					),
					'home_slug'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_title',
					),
					'set_homepage' => array(
						'type'     => 'boolean',
						'required' => false,
						'default'  => false,
					),
					'menu_name'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Capability gate: creating + publishing pages.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_check() {
		if ( ! current_user_can( 'publish_pages' ) ) {
			return new WP_Error(
				'aieb_forbidden',
				__( 'You are not allowed to build a site.', 'ai-elementor-builder' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Route by mode.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		if ( 'finalize' === (string) $request->get_param( 'mode' ) ) {
			return $this->handle_finalize( $request );
		}
		return $this->handle_page( $request );
	}

	/**
	 * Generate one page and persist it as a published Elementor page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle_page( WP_REST_Request $request ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$page = (array) $request->get_param( 'page' );
		$title = isset( $page['title'] ) ? sanitize_text_field( (string) $page['title'] ) : '';
		$brief = isset( $page['brief'] ) ? sanitize_textarea_field( (string) $page['brief'] ) : '';
		$slug  = isset( $page['slug'] ) ? sanitize_title( (string) $page['slug'] ) : sanitize_title( $title );
		$scope = isset( $page['scope'] ) ? sanitize_key( (string) $page['scope'] ) : 'fullpage';

		if ( '' === $title || '' === $brief ) {
			return new WP_Error( 'aieb_invalid_page', __( 'Page title and brief are required.', 'ai-elementor-builder' ), array( 'status' => 400 ) );
		}

		$site_title = (string) $request->get_param( 'site_title' );
		$prompt     = $this->page_prompt( $site_title, $title, $brief );

		$result = $this->generator->generate(
			$prompt,
			array(
				'provider' => (string) $request->get_param( 'provider' ),
				'model'    => (string) $request->get_param( 'model' ),
				'scope'    => $scope,
			)
		);

		if ( empty( $result['success'] ) ) {
			$data = array( 'status' => $result['status'] );
			if ( isset( $result['raw'] ) ) {
				$data['raw'] = $result['raw'];
			}
			return new WP_Error( $result['error_code'], $result['error'], $data );
		}

		// Create the page first so images attach to it.
		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_content' => '',
			),
			true
		);

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			return new WP_Error( 'aieb_page_create_failed', __( 'Could not create the page.', 'ai-elementor-builder' ), array( 'status' => 500 ) );
		}
		$page_id = (int) $page_id;

		$content = isset( $result['template']['content'] ) && is_array( $result['template']['content'] )
			? $this->images->resolve_final( $result['template']['content'], $page_id )
			: array();

		$this->writer->write( $page_id, $content );

		return new WP_REST_Response(
			array(
				'slug'     => $slug,
				'page_id'  => $page_id,
				'title'    => $title,
				'edit_url' => admin_url( 'post.php?post=' . $page_id . '&action=elementor' ),
				'view_url' => get_permalink( $page_id ),
			),
			200
		);
	}

	/**
	 * Build the nav menu + (optionally) set the home page once all pages exist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle_finalize( WP_REST_Request $request ) {
		$pages = (array) $request->get_param( 'pages' );
		$pages = array_values(
			array_filter(
				array_map(
					static function ( $p ) {
						if ( ! is_array( $p ) || empty( $p['page_id'] ) ) {
							return null;
						}
						return array(
							'page_id'   => (int) $p['page_id'],
							'slug'      => isset( $p['slug'] ) ? sanitize_title( (string) $p['slug'] ) : '',
							'nav_label' => isset( $p['nav_label'] ) ? sanitize_text_field( (string) $p['nav_label'] ) : '',
						);
					},
					$pages
				)
			)
		);

		if ( empty( $pages ) ) {
			return new WP_Error( 'aieb_no_pages', __( 'No pages to finalize.', 'ai-elementor-builder' ), array( 'status' => 400 ) );
		}

		$menu_id  = 0;
		$assigned = false;

		// Nav menu requires theme-options capability; degrade gracefully otherwise.
		if ( current_user_can( 'edit_theme_options' ) ) {
			$menu_name = (string) $request->get_param( 'menu_name' );
			if ( '' === $menu_name ) {
				$site_title = (string) $request->get_param( 'site_title' );
				$menu_name  = ( '' !== $site_title ? $site_title . ' ' : '' ) . __( 'Menu', 'ai-elementor-builder' );
			}

			$menu_id = $this->ensure_menu( $menu_name );
			if ( $menu_id > 0 ) {
				foreach ( $pages as $p ) {
					$label = '' !== $p['nav_label'] ? $p['nav_label'] : get_the_title( $p['page_id'] );
					wp_update_nav_menu_item(
						$menu_id,
						0,
						array(
							'menu-item-title'     => $label,
							'menu-item-object'    => 'page',
							'menu-item-object-id' => $p['page_id'],
							'menu-item-type'      => 'post_type',
							'menu-item-status'    => 'publish',
						)
					);
				}
				$assigned = $this->assign_primary_location( $menu_id );
			}
		}

		// Home page: set the static front page when requested.
		$home_id = 0;
		if ( $request->get_param( 'set_homepage' ) && current_user_can( 'manage_options' ) ) {
			$home_slug = (string) $request->get_param( 'home_slug' );
			foreach ( $pages as $p ) {
				if ( ( '' !== $home_slug && $p['slug'] === $home_slug ) || 0 === $home_id ) {
					$home_id = $p['page_id'];
					if ( '' !== $home_slug && $p['slug'] === $home_slug ) {
						break;
					}
				}
			}
			if ( $home_id > 0 ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $home_id );
			}
		}

		return new WP_REST_Response(
			array(
				'menu_id'       => $menu_id,
				'menu_assigned' => $assigned,
				'home_id'       => $home_id,
			),
			200
		);
	}

	/**
	 * Build the per-page generation prompt from the sitemap brief.
	 *
	 * @param string $site_title Site name.
	 * @param string $title      Page title.
	 * @param string $brief      Page brief.
	 * @return string
	 */
	private function page_prompt( string $site_title, string $title, string $brief ): string {
		$context = '' !== $site_title ? "This page belongs to the website \"{$site_title}\". " : '';
		return $context
			. "Build the \"{$title}\" page. Keep the palette, typography and overall style consistent with the rest of the site.\n\n"
			. $brief;
	}

	/**
	 * Get an existing menu by name or create it.
	 *
	 * @param string $name Menu name.
	 * @return int Menu term id, or 0 on failure.
	 */
	private function ensure_menu( string $name ): int {
		$existing = wp_get_nav_menu_object( $name );
		if ( $existing && isset( $existing->term_id ) ) {
			return (int) $existing->term_id;
		}
		$menu_id = wp_create_nav_menu( $name );
		return is_wp_error( $menu_id ) ? 0 : (int) $menu_id;
	}

	/**
	 * Assign the menu to the theme's primary nav location (first registered).
	 *
	 * @param int $menu_id Menu term id.
	 * @return bool Whether a location was assigned.
	 */
	private function assign_primary_location( int $menu_id ): bool {
		$locations = get_registered_nav_menus();
		if ( empty( $locations ) ) {
			return false;
		}

		// Prefer a location that looks primary; else take the first.
		$location_keys = array_keys( $locations );
		$target        = $location_keys[0];
		foreach ( $location_keys as $key ) {
			if ( preg_match( '/primary|main|header|top/i', $key ) ) {
				$target = $key;
				break;
			}
		}

		$current            = (array) get_theme_mod( 'nav_menu_locations', array() );
		$current[ $target ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $current );

		return true;
	}
}
