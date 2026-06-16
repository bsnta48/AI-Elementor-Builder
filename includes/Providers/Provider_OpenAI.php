<?php
/**
 * OpenAI provider.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Calls the OpenAI Chat Completions API.
 *
 * @see https://platform.openai.com/docs/api-reference/chat
 */
class Provider_OpenAI extends AI_Provider {

	const ENDPOINT      = 'https://api.openai.com/v1/chat/completions';
	const DEFAULT_MODEL = 'gpt-4o';

	/**
	 * {@inheritDoc}
	 */
	public function generate( string $prompt, array $options ): array {
		$model = isset( $options['model'] ) ? (string) $options['model'] : self::DEFAULT_MODEL;

		$headers = array(
			'content-type'  => 'application/json',
			'authorization' => 'Bearer ' . $this->api_key,
		);

		$messages = array();
		if ( ! empty( $options['system'] ) ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => (string) $options['system'],
			);
		}
		// Vision: send a content-part array (text + image data URL) when a
		// reference image is supplied; otherwise a plain string.
		$user_content = $prompt;
		if ( ! empty( $options['image']['data'] ) ) {
			$user_content = array(
				array(
					'type' => 'text',
					'text' => $prompt,
				),
				array(
					'type'      => 'image_url',
					'image_url' => array(
						'url' => 'data:' . (string) $options['image']['mime'] . ';base64,' . (string) $options['image']['data'],
					),
				),
			);
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => $user_content,
		);

		$body = array(
			'model'    => $model,
			'messages' => $messages,
		);

		if ( isset( $options['max_tokens'] ) ) {
			$body['max_tokens'] = (int) $options['max_tokens'];
		}

		return $this->post( self::ENDPOINT, $headers, $body );
	}

	/**
	 * {@inheritDoc}
	 */
	public function extract_text( string $json ): string {
		$data = json_decode( $json, true );
		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			return (string) $data['choices'][0]['message']['content'];
		}
		return '';
	}
}
