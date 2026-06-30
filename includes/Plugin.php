<?php
/**
 * Main plugin class.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder;

defined( 'ABSPATH' ) || exit;

/**
 * Core plugin controller. Singleton.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Admin menu handler.
	 *
	 * @var Admin\Menu
	 */
	private $menu;

	/**
	 * Settings handler.
	 *
	 * @var Settings\Settings
	 */
	private $settings;

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Wire up hooks.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize ' . __CLASS__ );
	}

	/**
	 * Initialize after all plugins are loaded.
	 *
	 * Bails (with an admin notice + self-deactivation) when Elementor is missing.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! $this->is_elementor_active() ) {
			add_action( 'admin_notices', array( $this, 'elementor_missing_notice' ) );
			add_action( 'admin_init', array( $this, 'deactivate_self' ) );
			return;
		}

		$this->settings = new Settings\Settings();
		$this->settings->register();

		$factory    = new Providers\Provider_Factory( $this->settings );
		$validator  = new Validator\Elementor_Validator();
		$references = new References\Reference_Registry();
		$session_store = new Sessions\Session_Store();
		$session_store->register();

		( new Rest\Generate_Controller( $factory, $validator, $this->settings, $references ) )->register();
		( new Rest\Site_Plan_Controller( $factory, $this->settings ) )->register();
		( new Rest\Build_Site_Controller( $factory, $validator, $this->settings, $references ) )->register();
		( new Rest\Clarify_Controller( $factory, $this->settings ) )->register();
		( new Rest\Chat_Controller( $factory, $this->settings ) )->register();
		( new Rest\Refine_Controller( $factory, $validator, $this->settings ) )->register();
		( new Rest\Sessions_Controller( $session_store ) )->register();
		( new Rest\Push_Controller( $validator ) )->register();
		( new Rest\Push_Template_Controller( $validator ) )->register();
		( new Rest\Push_Gutenberg_Controller( $validator ) )->register();
		( new Rest\Save_Pattern_Controller( $validator ) )->register();
		( new Rest\Test_Key_Controller( $factory, $this->settings ) )->register();

		$this->menu = new Admin\Menu();
		$this->menu->register();

		load_plugin_textdomain( 'ai-elementor-builder', false, dirname( AIEB_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Access the settings handler.
	 *
	 * @return Settings\Settings|null
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Whether Elementor is active.
	 *
	 * @return bool
	 */
	public function is_elementor_active() {
		return did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Admin notice shown when Elementor is not active.
	 *
	 * @return void
	 */
	public function elementor_missing_notice() {
		$message = sprintf(
			/* translators: %s: Elementor plugin name. */
			esc_html__( '"AI Elementor Builder" requires %s to be installed and active.', 'ai-elementor-builder' ),
			'<strong>Elementor</strong>'
		);

		printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $message ) );
	}

	/**
	 * Deactivate this plugin when its dependency is missing.
	 *
	 * @return void
	 */
	public function deactivate_self() {
		deactivate_plugins( AIEB_PLUGIN_BASENAME );

		// Suppress the "Plugin activated" notice after a redirect.
		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	}
}
