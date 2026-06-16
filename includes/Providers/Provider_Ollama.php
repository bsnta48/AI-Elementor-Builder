<?php
/**
 * Ollama (local LLM) provider.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to a local Ollama server via its OpenAI-compatible chat endpoint.
 *
 * No API key or license is required — inference runs locally and free. The
 * server URL + model are injected from plugin Settings by Provider_Factory.
 *
 * @see https://github.com/ollama/ollama/blob/main/docs/openai.md
 */
class Provider_Ollama extends AI_Provider {

	const DEFAULT_URL   = 'http://localhost:11434';
	const DEFAULT_MODEL = 'qwen2.5:7b';

	/**
	 * Ollama server base URL.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Model name (must already be pulled on the server).
	 *
	 * @var string
	 */
	private $model;

	/**
	 * @param string $base_url Ollama server base URL.
	 * @param string $model    Model name.
	 */
	public function __construct( $base_url = '', $model = '' ) {
		parent::__construct( '' );
		$this->base_url = '' !== (string) $base_url ? (string) $base_url : self::DEFAULT_URL;
		$this->model    = '' !== (string) $model ? (string) $model : self::DEFAULT_MODEL;
	}

	/**
	 * {@inheritDoc}
	 */
	public function generate( string $prompt, array $options ): array {
		$endpoint = trailingslashit( $this->base_url ) . 'v1/chat/completions';

		// The Generate_Controller supplies the constraining prompt under 'system';
		// fall back to 'system_prompt' for callers that use that key.
		$system = '';
		if ( ! empty( $options['system'] ) ) {
			$system = (string) $options['system'];
		} elseif ( ! empty( $options['system_prompt'] ) ) {
			$system = (string) $options['system_prompt'];
		}

		$body = array(
			'model'       => $this->model,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'stream'      => false,
			'temperature' => 0.3,
			'format'      => 'json',
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 120,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error(
				sprintf(
					/* translators: %s: Ollama base URL. */
					__( 'Ollama not reachable at %s. Is Ollama running?', 'ai-elementor-builder' ),
					$this->base_url
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$msg = ( is_array( $data ) && ! empty( $data['error']['message'] ) )
				? (string) $data['error']['message']
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Ollama error: HTTP %d', 'ai-elementor-builder' ),
					$code
				);
			return $this->error( $msg );
		}

		$content = ( is_array( $data ) && isset( $data['choices'][0]['message']['content'] ) )
			? (string) $data['choices'][0]['message']['content']
			: '';

		if ( '' === $content ) {
			return $this->error( __( 'Empty response from Ollama', 'ai-elementor-builder' ) );
		}

		return array(
			'success' => true,
			'json'    => $content,
			'error'   => '',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * generate() already returns the assistant's text as the 'json' payload, so
	 * there is nothing further to extract.
	 */
	public function extract_text( string $json ): string {
		return $json;
	}
}
