<?php
/**
 * Plugin Name:       AI Elementor Builder
 * Plugin URI:        https://example.com/ai-elementor-builder
 * Description:       AI-assisted page building for Elementor. Adds a top-level admin menu with Builder and Settings pages.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Basant CH
 * Author URI:        https://codewing.co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-elementor-builder
 * Domain Path:       /languages
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder;

defined( 'ABSPATH' ) || exit;

define( 'AIEB_VERSION', '1.0.9' );
define( 'AIEB_PLUGIN_FILE', __FILE__ );
define( 'AIEB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIEB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIEB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4 style autoloader for the AI_Elementor_Builder\ namespace.
 *
 * Maps AI_Elementor_Builder\Sub\Name to includes/Sub/Name.php.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix   = __NAMESPACE__ . '\\';
		$base_dir = AIEB_PLUGIN_DIR . 'includes/';

		$len = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}

		$relative = substr( $class, $len );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * Boot the plugin.
 *
 * @return Plugin
 */
function aieb() {
	return Plugin::instance();
}

aieb();
