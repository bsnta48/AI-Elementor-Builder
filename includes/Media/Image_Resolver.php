<?php
/**
 * Resolves AI-emitted image placeholders to real photos.
 *
 * The model is instructed to leave image URLs empty and supply descriptive
 * "alt" keywords. This walks the validated Elementor element tree, finds those
 * empty-URL image slots, and fills them:
 *  - resolve_preview(): remote stock URL only (fast, no download) for the preview.
 *  - resolve_final(): downloads into the Media Library and rewrites url + id,
 *    so the published page owns its images.
 *
 * When no stock provider is configured or a query returns nothing, a deterministic
 * inline-SVG placeholder is used so a page never renders a broken image.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Walks an Elementor tree and fills image slots.
 */
class Image_Resolver {

	/**
	 * @var Stock_Image_Provider
	 */
	private $stock;

	/**
	 * Per-run cache: query => resolved descriptor, to dedupe lookups/downloads.
	 *
	 * @var array<string,array>
	 */
	private $cache = array();

	/**
	 * @param Stock_Image_Provider $stock Stock image search.
	 */
	public function __construct( Stock_Image_Provider $stock ) {
		$this->stock = $stock;
	}

	/**
	 * Fill image slots with remote stock URLs (no download). For previews.
	 *
	 * @param array $content Elementor elements tree.
	 * @return array Tree with image URLs filled.
	 */
	public function resolve_preview( array $content ): array {
		$this->cache = array();
		return $this->walk( $content, 0, false );
	}

	/**
	 * Fill image slots, downloading photos into the Media Library. For published pages.
	 *
	 * @param array $content Elementor elements tree.
	 * @param int   $post_id Post the media is attached to (0 for unattached).
	 * @return array Tree with image URLs + ids filled.
	 */
	public function resolve_final( array $content, int $post_id = 0 ): array {
		$this->cache = array();
		$this->load_media_includes();
		return $this->walk( $content, $post_id, true );
	}

	/**
	 * Recursively walk elements, resolving image + background-image settings.
	 *
	 * @param array $elements Elements array.
	 * @param int   $post_id  Attachment target.
	 * @param bool  $sideload Whether to download into the Media Library.
	 * @return array
	 */
	private function walk( array $elements, int $post_id, bool $sideload ): array {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$element['settings'] = $this->resolve_settings( $element['settings'], $post_id, $sideload );
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = $this->walk( $element['elements'], $post_id, $sideload );
			}
		}
		unset( $element );

		return $elements;
	}

	/**
	 * Resolve image-bearing settings on a single element.
	 *
	 * @param array $settings Element settings.
	 * @param int   $post_id  Attachment target.
	 * @param bool  $sideload Whether to download.
	 * @return array
	 */
	private function resolve_settings( array $settings, int $post_id, bool $sideload ): array {
		// Widget image + container background image both use an { url, alt } object.
		foreach ( array( 'image', 'background_image' ) as $slot ) {
			if ( empty( $settings[ $slot ] ) || ! is_array( $settings[ $slot ] ) ) {
				continue;
			}

			$img = $settings[ $slot ];
			$url = isset( $img['url'] ) ? trim( (string) $img['url'] ) : '';
			$alt = isset( $img['alt'] ) ? trim( (string) $img['alt'] ) : '';

			// Only resolve empty slots that carry keywords. A real URL is left alone.
			if ( '' !== $url || '' === $alt ) {
				continue;
			}

			$orientation = ( 'background_image' === $slot ) ? 'landscape' : 'landscape';
			$resolved    = $this->resolve_one( $alt, $orientation, $post_id, $sideload );

			$settings[ $slot ]['url'] = $resolved['url'];
			if ( isset( $resolved['id'] ) ) {
				$settings[ $slot ]['id'] = $resolved['id'];
			}
		}

		return $settings;
	}

	/**
	 * Resolve one keyword query to a URL (and optionally a Media Library id).
	 *
	 * @param string $query       Keywords.
	 * @param string $orientation Orientation hint.
	 * @param int    $post_id     Attachment target.
	 * @param bool   $sideload    Whether to download.
	 * @return array { url, id? }
	 */
	private function resolve_one( string $query, string $orientation, int $post_id, bool $sideload ): array {
		$cache_key = $query . '|' . ( $sideload ? 'dl' : 'remote' );
		if ( isset( $this->cache[ $cache_key ] ) ) {
			return $this->cache[ $cache_key ];
		}

		$photo = $this->stock->search( $query, $orientation );

		if ( null === $photo ) {
			$result = array( 'url' => $this->placeholder( $query ) );
			$this->cache[ $cache_key ] = $result;
			return $result;
		}

		if ( ! $sideload ) {
			$result = array( 'url' => $photo['url'] );
			$this->cache[ $cache_key ] = $result;
			return $result;
		}

		$attachment_id = media_sideload_image( $photo['url'], $post_id, $photo['alt'], 'id' );
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			// Download failed — fall back to the remote URL (still renders).
			$result = array( 'url' => $photo['url'] );
			$this->cache[ $cache_key ] = $result;
			return $result;
		}

		$result = array(
			'url' => (string) wp_get_attachment_url( (int) $attachment_id ),
			'id'  => (int) $attachment_id,
		);
		$this->cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * A deterministic inline-SVG placeholder data-URI. Never 404s, no external call.
	 *
	 * @param string $label Seed/label text.
	 * @return string data: URI.
	 */
	private function placeholder( string $label ): string {
		// Deterministic hue from the label so repeated queries look stable.
		$hue = (int) ( hexdec( substr( md5( $label ), 0, 4 ) ) % 360 );
		$c1  = "hsl({$hue},62%,58%)";
		$c2  = 'hsl(' . ( ( $hue + 40 ) % 360 ) . ',60%,42%)';

		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800" viewBox="0 0 1200 800">'
			. '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
			. '<stop offset="0" stop-color="' . $c1 . '"/><stop offset="1" stop-color="' . $c2 . '"/>'
			. '</linearGradient></defs>'
			. '<rect width="1200" height="800" fill="url(#g)"/>'
			. '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Ensure the WP media-sideload helpers are loaded (REST context lacks them).
	 *
	 * @return void
	 */
	private function load_media_includes(): void {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}
}
