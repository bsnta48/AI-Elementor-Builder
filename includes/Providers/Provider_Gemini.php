<?php
/**
 * Google Gemini provider.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Calls the Google Generative Language API (Gemini).
 *
 * @see https://ai.google.dev/api/generate-content
 */
class Provider_Gemini extends AI_Provider {

	const ENDPOINT_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
	const DEFAULT_MODEL = 'gemini-1.5-pro';

	/**
	 * {@inheritDoc}
	 */
	public function generate( string $prompt, array $options ): array {
		$model = isset( $options['model'] ) ? (string) $options['model'] : self::DEFAULT_MODEL;
		$url   = self::ENDPOINT_BASE . rawurlencode( $model ) . ':generateContent';

		// Gemini authenticates via the x-goog-api-key header (keeps the key out of the URL/logs).
		$headers = array(
			'content-type'    => 'application/json',
			'x-goog-api-key'  => $this->api_key,
		);

		// Vision: prepend an inline_data part with the base64 image when supplied.
		$parts = array();
		if ( ! empty( $options['image']['data'] ) ) {
			$parts[] = array(
				'inline_data' => array(
					'mime_type' => (string) $options['image']['mime'],
					'data'      => (string) $options['image']['data'],
				),
			);
		}
		$parts[] = array( 'text' => $prompt );

		$body = array(
			'contents' => array(
				array(
					'parts' => $parts,
				),
			),
		);

		if ( ! empty( $options['system'] ) ) {
			$body['systemInstruction'] = array(
				'parts' => array(
					array( 'text' => (string) $options['system'] ),
				),
			);
		}

		if ( isset( $options['max_tokens'] ) ) {
			$body['generationConfig'] = array(
				'maxOutputTokens' => (int) $options['max_tokens'],
			);
		}

		return $this->post( $url, $headers, $body );
	}

	/**
	 * {@inheritDoc}
	 */
	public function extract_text( string $json ): string {
		$data = json_decode( $json, true );
		if ( empty( $data['candidates'][0]['content']['parts'] ) || ! is_array( $data['candidates'][0]['content']['parts'] ) ) {
			return '';
		}

		$out = '';
		foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
			if ( isset( $part['text'] ) ) {
				$out .= $part['text'];
			}
		}

		return $out;
	}
}
