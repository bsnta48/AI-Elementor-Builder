<?php
/**
 * Admin menu registration.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level menu and its sub pages.
 */
class Menu {

	const SLUG = 'ai-elementor-builder';

	const CAPABILITY = 'manage_options';

	/**
	 * Hook suffix of the Builder page, captured at registration.
	 *
	 * @var string
	 */
	private $builder_hook = '';

	/**
	 * Hook suffix of the Settings page, captured at registration.
	 *
	 * @var string
	 */
	private $settings_hook = '';

	/**
	 * Hook menu registration.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
	}

	/**
	 * Tag the builder/settings screens with body classes so the stylesheet can
	 * scope the full-bleed studio reset and the fixed savebar offset without
	 * relying on the (long, fragile) WordPress slug-based body classes.
	 *
	 * @param string $classes Space-separated admin body classes.
	 * @return string
	 */
	public function admin_body_class( $classes ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return $classes;
		}

		if ( $screen->id === $this->builder_hook ) {
			$classes .= ' aieb-admin aieb-builder-screen';
		} elseif ( $screen->id === $this->settings_hook ) {
			$classes .= ' aieb-admin aieb-settings-screen';
		}

		return $classes;
	}

	/**
	 * Register top-level menu plus Builder and Settings sub pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->builder_hook = add_menu_page(
			__( 'AI Elementor Builder', 'ai-elementor-builder' ),
			__( 'AI Elementor Builder', 'ai-elementor-builder' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_builder_page' ),
			'dashicons-superhero',
			58
		);

		add_submenu_page(
			self::SLUG,
			__( 'Builder', 'ai-elementor-builder' ),
			__( 'Builder', 'ai-elementor-builder' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_builder_page' )
		);

		$this->settings_hook = add_submenu_page(
			self::SLUG,
			__( 'Settings', 'ai-elementor-builder' ),
			__( 'Settings', 'ai-elementor-builder' ),
			self::CAPABILITY,
			self::SLUG . '-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue Builder page assets and inject runtime config.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook === $this->settings_hook ) {
			$this->enqueue_settings_assets();
			return;
		}

		if ( $hook !== $this->builder_hook ) {
			return;
		}

		wp_enqueue_style(
			'aieb-builder',
			AIEB_PLUGIN_URL . 'assets/css/builder.css',
			array( 'dashicons' ),
			AIEB_VERSION
		);

		wp_enqueue_script(
			'aieb-builder',
			AIEB_PLUGIN_URL . 'assets/js/builder.js',
			array( 'wp-api-fetch' ),
			AIEB_VERSION,
			true
		);

		wp_localize_script(
			'aieb-builder',
			'AIEB',
			array(
				'restUrl'         => esc_url_raw( rest_url( 'ai-elementor/v1/generate' ) ),
				'pushUrl'         => esc_url_raw( rest_url( 'ai-elementor/v1/push' ) ),
				'pushTemplateUrl' => esc_url_raw( rest_url( 'ai-elementor/v1/push-template' ) ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'history' => \AI_Elementor_Builder\History\History::get(),
				'references' => ( new \AI_Elementor_Builder\References\Reference_Registry() )->listing(),
				'i18n'    => array(
					'emptyPrompt'  => __( 'Please enter a design prompt.', 'ai-elementor-builder' ),
					'genericError' => __( 'Generation failed. Please try again.', 'ai-elementor-builder' ),
					'generated'    => __( 'Layout generated.', 'ai-elementor-builder' ),
					'downloaded'   => __( 'Template JSON downloaded.', 'ai-elementor-builder' ),
					'restored'     => __( 'Prompt loaded into composer.', 'ai-elementor-builder' ),
					'dropImage'    => __( 'Drop a screenshot or mockup', 'ai-elementor-builder' ),
					'networkError' => __( 'Network error. Could not reach the server.', 'ai-elementor-builder' ),
					'viewJson'     => __( 'View JSON', 'ai-elementor-builder' ),
					'viewPreview'  => __( 'View Preview', 'ai-elementor-builder' ),
					'noTemplate'   => __( 'Generate a template before pushing.', 'ai-elementor-builder' ),
					'noPage'       => __( 'Select a target page.', 'ai-elementor-builder' ),
					'pushFailed'   => __( 'Could not push to Elementor.', 'ai-elementor-builder' ),
					'pushed'         => __( 'Pushed to Elementor.', 'ai-elementor-builder' ),
					'editInElementor' => __( 'Edit in Elementor', 'ai-elementor-builder' ),
					'loadingPages'   => __( 'Loading pages…', 'ai-elementor-builder' ),
					'selectPage'     => __( '— Select a page —', 'ai-elementor-builder' ),
					'historyEmpty'   => __( 'No generations yet.', 'ai-elementor-builder' ),
					'restore'        => __( 'Restore', 'ai-elementor-builder' ),
					'imageTooLarge'  => __( 'Image is too large (max 5 MB).', 'ai-elementor-builder' ),
					'imageReadError' => __( 'Could not read the selected image.', 'ai-elementor-builder' ),
					'downloadEmpty'  => __( 'Generate a design first before downloading.', 'ai-elementor-builder' ),
					'templateHistoryEmpty' => __( 'No templates downloaded yet.', 'ai-elementor-builder' ),
					'redownload'     => __( 'Download again', 'ai-elementor-builder' ),
					'pushTemplate'      => __( 'Push as Template', 'ai-elementor-builder' ),
					'pushingTemplate'   => __( 'Saving template…', 'ai-elementor-builder' ),
					'templateSaved'     => __( 'Saved to Elementor Library.', 'ai-elementor-builder' ),
					'templateFailed'    => __( 'Could not save the template.', 'ai-elementor-builder' ),
					'openLibrary'       => __( 'Open Library', 'ai-elementor-builder' ),
				),
			)
		);
	}

	/**
	 * Enqueue Settings page assets (API key tester).
	 *
	 * @return void
	 */
	private function enqueue_settings_assets() {
		wp_enqueue_style(
			'aieb-builder',
			AIEB_PLUGIN_URL . 'assets/css/builder.css',
			array( 'dashicons' ),
			AIEB_VERSION
		);

		wp_enqueue_script(
			'aieb-settings',
			AIEB_PLUGIN_URL . 'assets/js/settings.js',
			array(),
			AIEB_VERSION,
			true
		);

		wp_localize_script(
			'aieb-settings',
			'AIEB_SETTINGS',
			array(
				'restUrl'       => esc_url_raw( rest_url( 'ai-elementor/v1/test-key' ) ),
				'ollamaTestUrl' => esc_url_raw( rest_url( 'ai-elementor/v1/ollama-test' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'i18n'          => array(
					'testing'         => __( 'Testing…', 'ai-elementor-builder' ),
					'connected'       => __( 'Connected', 'ai-elementor-builder' ),
					'failed'          => __( 'Failed', 'ai-elementor-builder' ),
					'failedPrefix'    => __( 'Failed: ', 'ai-elementor-builder' ),
					'networkError'    => __( 'Network error.', 'ai-elementor-builder' ),
					'connectedModels' => __( 'Connected — %d models available', 'ai-elementor-builder' ),
					'cannotReach'     => __( 'Cannot reach Ollama — make sure it is running', 'ai-elementor-builder' ),
					'unsaved'         => __( 'Unsaved changes', 'ai-elementor-builder' ),
				),
			)
		);
	}

	/**
	 * Render the Builder admin page.
	 *
	 * @return void
	 */
	public function render_builder_page() {
		$this->render_template( 'builder', __( 'Builder', 'ai-elementor-builder' ) );
	}

	/**
	 * Render the Settings admin page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$this->render_template( 'settings', __( 'Settings', 'ai-elementor-builder' ) );
	}

	/**
	 * Load a view template, guarding capability.
	 *
	 * @param string $view  View file slug under includes/Admin/views/.
	 * @param string $title Page title passed to the view.
	 * @return void
	 */
	private function render_template( $view, $title ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ai-elementor-builder' ) );
		}

		$template = AIEB_PLUGIN_DIR . 'includes/Admin/views/' . $view . '.php';
		if ( file_exists( $template ) ) {
			require $template;
		}
	}
}
