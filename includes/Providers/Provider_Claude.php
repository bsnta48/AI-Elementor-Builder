<?php
/**
 * Anthropic Claude provider.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Calls the Anthropic Messages API.
 *
 * @see https://docs.anthropic.com/en/api/messages
 */
class Provider_Claude extends AI_Provider {

	const ENDPOINT        = 'https://api.anthropic.com/v1/messages';
	const DEFAULT_MODEL   = 'claude-sonnet-4-6';
	const ANTHROPIC_VERSION = '2023-06-01';

	/**
	 * {@inheritDoc}
	 */
	public function generate( string $prompt, array $options ): array {
		$model      = isset( $options['model'] ) ? (string) $options['model'] : self::DEFAULT_MODEL;
		$max_tokens = isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : 16384;

		$headers = array(
			'content-type'      => 'application/json',
			'x-api-key'         => $this->api_key,
			'anthropic-version' => self::ANTHROPIC_VERSION,
		);

		// Vision: when a reference image is supplied, send a content block array
		// (image first, then the text prompt) instead of a plain string.
		$content = $prompt;
		if ( ! empty( $options['image']['data'] ) ) {
			$content = array(
				array(
					'type'   => 'image',
					'source' => array(
						'type'       => 'base64',
						'media_type' => (string) $options['image']['mime'],
						'data'       => (string) $options['image']['data'],
					),
				),
				array(
					'type' => 'text',
					'text' => $prompt,
				),
			);
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
		);

		if ( ! empty( $options['system'] ) ) {
			$body['system'] = (string) $options['system'];
		}

		return $this->post( self::ENDPOINT, $headers, $body );
	}

	/**
	 * {@inheritDoc}
	 */
	public function extract_text( string $json ): string {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['content'] ) || ! is_array( $data['content'] ) ) {
			return '';
		}

		$out = '';
		foreach ( $data['content'] as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$out .= $block['text'];
			}
		}

		return $out;
	}
}
