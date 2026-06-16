<?php
/**
 * Abstract AI provider.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for all AI text-generation providers.
 *
 * Concrete providers implement generate() to call their vendor's HTTP API and
 * normalize the result into a common shape:
 *
 *     [
 *         'success' => bool,    // true when the HTTP call returned 2xx
 *         'json'    => string,  // raw response body (JSON) on success, '' otherwise
 *         'error'   => string,  // human-readable error on failure, '' otherwise
 *     ]
 */
abstract class AI_Provider {

	/**
	 * Request timeout in seconds. Generous: full-page generations on large
	 * models can take well over a minute to stream back.
	 */
	const TIMEOUT = 90;

	/**
	 * Decrypted API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * @param string $api_key Decrypted API key for the provider.
	 */
	public function __construct( $api_key ) {
		$this->api_key = (string) $api_key;
	}

	/**
	 * Generate a completion for the given prompt.
	 *
	 * @param string $prompt  The user prompt.
	 * @param array  $options Provider-specific options (model, max_tokens, etc.).
	 * @return array{success:bool,json:string,error:string}
	 */
	abstract public function generate( string $prompt, array $options ): array;

	/**
	 * Extract the model's text output from a successful raw response body.
	 *
	 * @param string $json Raw response JSON (the 'json' value from generate()).
	 * @return string The assistant's text, or '' if it can't be located.
	 */
	abstract public function extract_text( string $json ): string;

	/**
	 * POST JSON to an endpoint with a 60s timeout and normalize the result.
	 *
	 * @param string $url     Endpoint URL.
	 * @param array  $headers Request headers.
	 * @param array  $body    Request body (encoded to JSON).
	 * @return array{success:bool,json:string,error:string}
	 */
	protected function post( string $url, array $headers, array $body ): array {
		if ( '' === $this->api_key ) {
			return $this->error( __( 'Missing API key.', 'ai-elementor-builder' ) );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return $this->error(
				sprintf(
					/* translators: 1: HTTP status code, 2: response body. */
					__( 'API request failed (HTTP %1$d): %2$s', 'ai-elementor-builder' ),
					$code,
					$raw
				)
			);
		}

		return array(
			'success' => true,
			'json'    => $raw,
			'error'   => '',
		);
	}

	/**
	 * Build a normalized error result.
	 *
	 * @param string $message Error message.
	 * @return array{success:bool,json:string,error:string}
	 */
	protected function error( string $message ): array {
		return array(
			'success' => false,
			'json'    => '',
			'error'   => $message,
		);
	}
}
