<?php
/**
 * Uninstall handler for AI Elementor Builder.
 *
 * Fires only when the plugin is deleted from the WordPress admin. Removes all
 * plugin options and the per-user history meta.
 *
 * Option/meta keys mirror the constants in:
 *   - Settings\Settings::OPTION_KEY      => 'aieb_settings'
 *   - History\History::META_KEY          => 'ai_elementor_history'
 *
 * They are hard-coded here because the plugin's classes are not loaded during
 * uninstall.
 *
 * @package AI_Elementor_Builder
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove all plugin data for the current site.
 *
 * @return void
 */
function aieb_uninstall_cleanup() {
	$options = array(
		'aieb_settings',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Remove the per-user generation history from every user.
	delete_metadata( 'user', 0, 'ai_elementor_history', '', true );
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		aieb_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	aieb_uninstall_cleanup();
}
