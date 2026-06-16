<?php
/**
 * Elementor JSON validator / normalizer.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and normalizes an Elementor template JSON string.
 *
 * Checks: the string parses as JSON, exposes a `content` array, and every
 * element (recursively) carries the four structural fields Elementor expects —
 * `id`, `elType`, `settings`, `elements`. Missing 8-char hex IDs are generated.
 */
class Elementor_Validator {

	/**
	 * Elementor template schema version this plugin targets.
	 */
	const VERSION = '0.4';

	/**
	 * Validate + normalize a raw JSON string.
	 *
	 * The model may wrap the JSON in markdown fences or prose; we strip a single
	 * leading/trailing ```json fence before decoding.
	 *
	 * @param string $json Raw model output.
	 * @return array{valid:bool,data:array|null,error:string}
	 */
	public function validate( string $json ): array {
		$json = $this->strip_code_fence( $json );

		$decoded = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return $this->fail(
				sprintf(
					/* translators: %s: JSON parser error. */
					__( 'Response is not valid JSON: %s', 'ai-elementor-builder' ),
					json_last_error_msg()
				)
			);
		}

		if ( ! isset( $decoded['content'] ) || ! is_array( $decoded['content'] ) ) {
			return $this->fail( __( 'Missing or invalid "content" array.', 'ai-elementor-builder' ) );
		}

		$decoded['content'] = $this->normalize_elements( $decoded['content'] );

		// Stamp the document envelope so the result is a complete Elementor template.
		$decoded['version'] = isset( $decoded['version'] ) ? (string) $decoded['version'] : self::VERSION;
		$decoded['type']    = isset( $decoded['type'] ) ? (string) $decoded['type'] : 'page';
		if ( ! isset( $decoded['page_settings'] ) || ! is_array( $decoded['page_settings'] ) ) {
			$decoded['page_settings'] = array();
		}

		return array(
			'valid' => true,
			'data'  => $decoded,
			'error' => '',
		);
	}

	/**
	 * Recursively normalize a list of elements.
	 *
	 * @param array $elements Raw elements list.
	 * @return array Normalized elements.
	 */
	private function normalize_elements( array $elements ): array {
		$normalized = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			// id — generate an 8-char hex ID where missing or malformed (Elementor's format).
			if ( empty( $element['id'] ) || ! is_string( $element['id'] ) ) {
				$element['id'] = $this->generate_id();
			}

			// elType — default to container when absent.
			if ( empty( $element['elType'] ) || ! is_string( $element['elType'] ) ) {
				$element['elType'] = 'container';
			}

			// widgets must declare a widgetType; default to a safe text widget.
			if ( 'widget' === $element['elType'] && empty( $element['widgetType'] ) ) {
				$element['widgetType'] = 'text-editor';
			}

			// settings — always an object/array.
			if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
				$element['settings'] = array();
			}

			// elements — always an array; recurse into children.
			if ( ! isset( $element['elements'] ) || ! is_array( $element['elements'] ) ) {
				$element['elements'] = array();
			} else {
				$element['elements'] = $this->normalize_elements( $element['elements'] );
			}

			$normalized[] = $element;
		}

		return $normalized;
	}

	/**
	 * Generate an 8-character hex ID (Elementor element ID format).
	 *
	 * @return string
	 */
	private function generate_id(): string {
		// wp_generate_password gives us cryptographically-random hex when restricted to that alphabet.
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 );
		}
		return substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
	}

	/**
	 * Strip a single surrounding markdown code fence if present.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function strip_code_fence( string $text ): string {
		$text = trim( $text );
		// ```json ... ``` or ``` ... ```
		if ( preg_match( '/^```(?:json)?\s*(.+?)\s*```$/is', $text, $m ) ) {
			return trim( $m[1] );
		}
		return $text;
	}

	/**
	 * Build a failure result.
	 *
	 * @param string $error Error message.
	 * @return array{valid:bool,data:null,error:string}
	 */
	private function fail( string $error ): array {
		return array(
			'valid' => false,
			'data'  => null,
			'error' => $error,
		);
	}
}
