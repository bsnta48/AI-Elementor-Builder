<?php
/**
 * Stock image search.
 *
 * Resolves descriptive keywords to a real photo URL via Unsplash (preferred)
 * or Pexels, whichever has an API key configured. Results are cached per query
 * in a transient to respect rate limits and avoid duplicate lookups.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Media;

use AI_Elementor_Builder\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Searches stock photo APIs by keyword.
 */
class Stock_Image_Provider {

	const CACHE_PREFIX = 'aieb_stock_';
	const CACHE_TTL    = DAY_IN_SECONDS;

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @param Settings $settings Settings handler (for API keys).
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether any stock provider is configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->settings->get_key( 'unsplash_api_key' )
			|| '' !== $this->settings->get_key( 'pexels_api_key' );
	}

	/**
	 * Find a photo for a keyword query.
	 *
	 * @param string $query       Descriptive keywords.
	 * @param string $orientation 'landscape', 'portrait', or 'squarish'.
	 * @return array|null { url, alt, credit } or null when nothing found / unconfigured.
	 */
	public function search( string $query, string $orientation = 'landscape' ) {
		$query = trim( $query );
		if ( '' === $query ) {
			return null;
		}

		$cache_key = self::CACHE_PREFIX . md5( $query . '|' . $orientation );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		// A cached miss is stored as the string 'none' to avoid re-hitting the API.
		if ( 'none' === $cached ) {
			return null;
		}

		$result = null;
		if ( '' !== $this->settings->get_key( 'unsplash_api_key' ) ) {
			$result = $this->search_unsplash( $query, $orientation );
		}
		if ( null === $result && '' !== $this->settings->get_key( 'pexels_api_key' ) ) {
			$result = $this->search_pexels( $query, $orientation );
		}

		set_transient( $cache_key, null === $result ? 'none' : $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Query the Unsplash search API.
	 *
	 * @param string $query       Keywords.
	 * @param string $orientation Orientation.
	 * @return array|null
	 */
	private function search_unsplash( string $query, string $orientation ) {
		$key = $this->settings->get_key( 'unsplash_api_key' );
		$url = add_query_arg(
			array(
				'query'       => rawurlencode( $query ),
				'per_page'    => 1,
				'orientation' => in_array( $orientation, array( 'landscape', 'portrait', 'squarish' ), true ) ? $orientation : 'landscape',
				'content_filter' => 'high',
			),
			'https://api.unsplash.com/search/photos'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Client-ID ' . $key,
					'Accept-Version' => 'v1',
				),
			)
		);

		$data = $this->decode( $response );
		if ( empty( $data['results'][0]['urls']['regular'] ) ) {
			return null;
		}

		$photo = $data['results'][0];

		return array(
			'url'    => (string) $photo['urls']['regular'],
			'alt'    => isset( $photo['alt_description'] ) ? (string) $photo['alt_description'] : $query,
			'credit' => isset( $photo['user']['name'] ) ? (string) $photo['user']['name'] . ' / Unsplash' : 'Unsplash',
		);
	}

	/**
	 * Query the Pexels search API.
	 *
	 * @param string $query       Keywords.
	 * @param string $orientation Orientation.
	 * @return array|null
	 */
	private function search_pexels( string $query, string $orientation ) {
		$key = $this->settings->get_key( 'pexels_api_key' );
		$url = add_query_arg(
			array(
				'query'       => rawurlencode( $query ),
				'per_page'    => 1,
				'orientation' => in_array( $orientation, array( 'landscape', 'portrait', 'square' ), true ) ? $orientation : 'landscape',
			),
			'https://api.pexels.com/v1/search'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => $key,
				),
			)
		);

		$data = $this->decode( $response );
		if ( empty( $data['photos'][0]['src']['large'] ) ) {
			return null;
		}

		$photo = $data['photos'][0];

		return array(
			'url'    => (string) $photo['src']['large'],
			'alt'    => isset( $photo['alt'] ) && '' !== $photo['alt'] ? (string) $photo['alt'] : $query,
			'credit' => isset( $photo['photographer'] ) ? (string) $photo['photographer'] . ' / Pexels' : 'Pexels',
		);
	}

	/**
	 * Decode a successful JSON response, or null on transport/HTTP error.
	 *
	 * @param array|\WP_Error $response wp_remote_get result.
	 * @return array|null
	 */
	private function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : null;
	}
}
