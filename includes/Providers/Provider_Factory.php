<?php
/**
 * Provider factory.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Providers;

use AI_Elementor_Builder\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a configured AI_Provider from a provider key, pulling the decrypted
 * API key and default model out of plugin Settings.
 */
class Provider_Factory {

	/**
	 * Map provider key => [class, settings key for the API key].
	 *
	 * @var array<string,array{class:class-string<AI_Provider>,key_field:string}>
	 */
	private const MAP = array(
		'anthropic' => array(
			'class'     => Provider_Claude::class,
			'key_field' => 'anthropic_api_key',
		),
		'openai'    => array(
			'class'     => Provider_OpenAI::class,
			'key_field' => 'openai_api_key',
		),
		'gemini'    => array(
			'class'     => Provider_Gemini::class,
			'key_field' => 'gemini_api_key',
		),
		'openrouter' => array(
			'class'     => Provider_OpenRouter::class,
			'key_field' => 'openrouter_api_key',
		),
		'nvidia'    => array(
			'class'     => Provider_Nvidia::class,
			'key_field' => 'nvidia_api_key',
		),
		// Ollama runs locally: no API key, configured by URL + model instead.
		'ollama'    => array(
			'class'     => Provider_Ollama::class,
			'key_field' => '',
		),
	);

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @param Settings $settings Settings handler.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether a provider key is known.
	 *
	 * @param string $provider Provider key.
	 * @return bool
	 */
	public function supports( string $provider ): bool {
		return isset( self::MAP[ $provider ] );
	}

	/**
	 * Known provider keys.
	 *
	 * @return string[]
	 */
	public function provider_keys(): array {
		return array_keys( self::MAP );
	}

	/**
	 * Create a provider instance for the given key.
	 *
	 * @param string $provider Provider key (anthropic|openai|gemini).
	 * @return AI_Provider|null Null if the key is unknown.
	 */
	public function make( string $provider ): ?AI_Provider {
		if ( ! $this->supports( $provider ) ) {
			return null;
		}

		// Ollama is keyless: build it from the configured URL + model.
		if ( 'ollama' === $provider ) {
			return new Provider_Ollama(
				$this->settings->get_ollama_url(),
				$this->settings->get_ollama_model()
			);
		}

		$class   = self::MAP[ $provider ]['class'];
		$api_key = $this->settings->get_key( self::MAP[ $provider ]['key_field'] );

		return new $class( $api_key );
	}

	/**
	 * Create a provider instance using an explicit API key instead of the stored
	 * one. Used by the "Test API Key" tool, which may probe an as-yet-unsaved key.
	 *
	 * @param string $provider Provider key (anthropic|openai|gemini).
	 * @param string $api_key  Decrypted API key to use.
	 * @return AI_Provider|null Null if the provider key is unknown.
	 */
	public function make_with_key( string $provider, string $api_key ): ?AI_Provider {
		if ( ! $this->supports( $provider ) ) {
			return null;
		}
		$class = self::MAP[ $provider ]['class'];
		return new $class( $api_key );
	}

	/**
	 * Map a provider key to its stored secret field name.
	 *
	 * @param string $provider Provider key.
	 * @return string Field name, or '' when unknown.
	 */
	public function key_field( string $provider ): string {
		return isset( self::MAP[ $provider ] ) ? self::MAP[ $provider ]['key_field'] : '';
	}

	/**
	 * Resolve the configured default model for a provider.
	 *
	 * @param string $provider Provider key.
	 * @return string Model ID, or '' to let the provider use its own default.
	 */
	public function default_model( string $provider ): string {
		$opts  = $this->settings->get_settings();
		$field = 'default_model_' . $provider;
		$model = isset( $opts[ $field ] ) ? (string) $opts[ $field ] : '';

		// Drop a stale/decommissioned stored model (e.g. one removed from the
		// provider's model list) so the provider's own valid default applies
		// instead — avoids a 404 until the user re-saves Settings.
		$providers = $this->settings->providers();
		if ( '' !== $model
			&& isset( $providers[ $provider ]['models'] )
			&& ! isset( $providers[ $provider ]['models'][ $model ] ) ) {
			return '';
		}

		return $model;
	}
}
