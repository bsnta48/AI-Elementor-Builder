<?php
/**
 * Shared Elementor page-writing service.
 *
 * Owns the `_elementor_data` write invariants used by both Push_Controller and
 * the multi-page Build_Site_Controller: persist the elements tree (slashed),
 * flip the page into builder mode, stamp version/template type, and flush
 * cached CSS so the new layout renders.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a validated Elementor template tree onto a page.
 */
class Elementor_Page_Writer {

	/**
	 * Persist an Elementor elements tree to a page and enable builder mode.
	 *
	 * @param int   $page_id Target page id.
	 * @param array $content The elements tree (the validated template's `content` array).
	 * @return void
	 */
	public function write( int $page_id, array $content ): void {
		// Elementor stores the elements tree (not the document envelope) as a
		// JSON-encoded string. wp_slash() guards the slashes update_post_meta strips.
		update_post_meta( $page_id, '_elementor_data', wp_slash( (string) wp_json_encode( $content ) ) );
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );

		if ( '' === (string) get_post_meta( $page_id, '_elementor_version', true ) ) {
			update_post_meta( $page_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );
		}

		$this->flush_css( $page_id );
	}

	/**
	 * Flush Elementor's cached CSS for a page so a new layout renders.
	 *
	 * @param int $page_id Page id.
	 * @return void
	 */
	public function flush_css( int $page_id ): void {
		delete_post_meta( $page_id, '_elementor_css' );
		delete_option( '_elementor_css_' . $page_id );
		delete_post_meta( $page_id, '_elementor_inline_css' );
	}
}
