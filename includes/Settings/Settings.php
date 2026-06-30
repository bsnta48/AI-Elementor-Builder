<?php
/**
 * Settings page: registration, fields, sanitization.
 *
 * Uses the WordPress Settings API. Nonce + referer checks are handled by
 * settings_fields() / options.php for us. API keys are stored encrypted
 * (see Crypto) and shown masked after saving.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Settings;

use AI_Elementor_Builder\Providers\Provider_Ollama;

defined( 'ABSPATH' ) || exit;

/**
 * Settings controller.
 */
class Settings {

	const OPTION_KEY = 'aieb_settings';

	const GROUP = 'aieb_settings_group';

	const PAGE = 'ai-elementor-builder-settings';

	/**
	 * Field keys holding sensitive (encrypted) API keys.
	 *
	 * @var string[]
	 */
	private $secret_fields = array(
		'anthropic_api_key',
		'openai_api_key',
		'gemini_api_key',
		'openrouter_api_key',
		'nvidia_api_key',
		'unsplash_api_key',
		'pexels_api_key',
	);

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Provider definitions: label + selectable models.
	 *
	 * @return array<string,array{label:string,models:array<string,string>}>
	 */
	public function providers() {
		return array(
			'anthropic' => array(
				'label'  => __( 'Anthropic', 'ai-elementor-builder' ),
				'models' => array(
					'claude-fable-5'    => 'Claude Fable 5',
					'claude-opus-4-8'   => 'Claude Opus 4.8',
					'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
					'claude-haiku-4-5'  => 'Claude Haiku 4.5',
				),
			),
			'openai'    => array(
				'label'  => __( 'OpenAI', 'ai-elementor-builder' ),
				'models' => array(
					'gpt-4o'      => 'GPT-4o',
					'gpt-4o-mini' => 'GPT-4o mini',
					'gpt-4.1'     => 'GPT-4.1',
					'o3'          => 'o3',
				),
			),
			'gemini'    => array(
				'label'  => __( 'Google Gemini', 'ai-elementor-builder' ),
				'models' => array(
					'gemini-2.5-pro'   => 'Gemini 2.5 Pro',
					'gemini-2.5-flash' => 'Gemini 2.5 Flash',
					'gemini-1.5-pro'   => 'Gemini 1.5 Pro',
				),
			),
			'openrouter' => array(
				'label'  => __( 'OpenRouter', 'ai-elementor-builder' ),
				'models' => array(
					'openai/gpt-4o'                  => 'GPT-4o',
					'anthropic/claude-sonnet-4'      => 'Claude Sonnet 4',
					'google/gemini-2.5-pro'          => 'Gemini 2.5 Pro',
					'meta-llama/llama-3.3-70b-instruct' => 'Llama 3.3 70B',
				),
			),
			'nvidia'    => array(
				'label'  => __( 'NVIDIA', 'ai-elementor-builder' ),
				'models' => array(
					'openai/gpt-oss-120b'                    => 'GPT-OSS 120B',
					'meta/llama-3.3-70b-instruct'            => 'Llama 3.3 70B',
					'nvidia/llama-3.3-nemotron-super-49b-v1' => 'Nemotron Super 49B',
					'nvidia/llama-3.1-nemotron-70b-instruct' => 'Nemotron 70B',
					'meta/llama-3.2-90b-vision-instruct'     => 'Llama 3.2 90B (vision)',
				),
			),
		);
	}

	/**
	 * Register setting, sections, and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'aieb_keys',
			__( 'API Keys', 'ai-elementor-builder' ),
			function () {
				echo '<p>' . esc_html__( 'Keys are stored obfuscated and shown masked after saving. Leave a field unchanged to keep the existing key.', 'ai-elementor-builder' ) . '</p>';
			},
			self::PAGE
		);

		$key_labels = array(
			'anthropic_api_key' => __( 'Anthropic API Key', 'ai-elementor-builder' ),
			'openai_api_key'    => __( 'OpenAI API Key', 'ai-elementor-builder' ),
			'gemini_api_key'    => __( 'Google Gemini API Key', 'ai-elementor-builder' ),
			'openrouter_api_key' => __( 'OpenRouter API Key', 'ai-elementor-builder' ),
			'nvidia_api_key'    => __( 'NVIDIA API Key', 'ai-elementor-builder' ),
		);

		foreach ( $key_labels as $field => $label ) {
			add_settings_field(
				$field,
				$label,
				array( $this, 'render_key_field' ),
				self::PAGE,
				'aieb_keys',
				array(
					'label_for' => $field,
					'field'     => $field,
				)
			);
		}

		add_settings_section(
			'aieb_images',
			__( 'Stock Images', 'ai-elementor-builder' ),
			function () {
				echo '<p>' . esc_html__( 'Optional. When set, generated image keywords are resolved to real photos and downloaded into your Media Library. Without a key, tasteful placeholders are used so pages never show broken images.', 'ai-elementor-builder' ) . '</p>';
				printf(
					'<p class="description">%1$s <a href="https://unsplash.com/developers" target="_blank" rel="noopener">Unsplash</a> · <a href="https://www.pexels.com/api/" target="_blank" rel="noopener">Pexels</a></p>',
					esc_html__( 'Get a free API key:', 'ai-elementor-builder' )
				);
			},
			self::PAGE
		);

		$image_key_labels = array(
			'unsplash_api_key' => __( 'Unsplash Access Key', 'ai-elementor-builder' ),
			'pexels_api_key'   => __( 'Pexels API Key', 'ai-elementor-builder' ),
		);

		foreach ( $image_key_labels as $field => $label ) {
			add_settings_field(
				$field,
				$label,
				array( $this, 'render_key_field' ),
				self::PAGE,
				'aieb_images',
				array(
					'label_for' => $field,
					'field'     => $field,
				)
			);
		}

		add_settings_section(
			'aieb_ollama',
			__( 'Ollama (Local LLM)', 'ai-elementor-builder' ),
			array( $this, 'render_ollama_section' ),
			self::PAGE
		);

		add_settings_field(
			'ollama_url',
			__( 'Ollama Server URL', 'ai-elementor-builder' ),
			array( $this, 'render_ollama_url_field' ),
			self::PAGE,
			'aieb_ollama',
			array( 'label_for' => 'ollama_url' )
		);

		add_settings_field(
			'ollama_model',
			__( 'Model name', 'ai-elementor-builder' ),
			array( $this, 'render_ollama_model_field' ),
			self::PAGE,
			'aieb_ollama',
			array( 'label_for' => 'ollama_model' )
		);

		add_settings_section(
			'aieb_defaults',
			__( 'Defaults', 'ai-elementor-builder' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			'default_provider',
			__( 'Default Provider', 'ai-elementor-builder' ),
			array( $this, 'render_provider_field' ),
			self::PAGE,
			'aieb_defaults',
			array( 'label_for' => 'default_provider' )
		);

		foreach ( $this->providers() as $key => $provider ) {
			add_settings_field(
				'default_model_' . $key,
				sprintf(
					/* translators: %s: provider name. */
					__( 'Default Model — %s', 'ai-elementor-builder' ),
					$provider['label']
				),
				array( $this, 'render_model_field' ),
				self::PAGE,
				'aieb_defaults',
				array(
					'label_for' => 'default_model_' . $key,
					'provider'  => $key,
				)
			);
		}

		// Developer tools: only available while WP_DEBUG is on.
		if ( self::debug_enabled() ) {
			add_settings_section(
				'aieb_developer',
				__( 'Developer', 'ai-elementor-builder' ),
				function () {
					echo '<p>' . esc_html__( 'Developer-only tools, shown because WP_DEBUG is enabled.', 'ai-elementor-builder' ) . '</p>';
				},
				self::PAGE
			);

			add_settings_field(
				'mock_mode',
				__( 'Mock Mode', 'ai-elementor-builder' ),
				array( $this, 'render_mock_field' ),
				self::PAGE,
				'aieb_developer',
				array( 'label_for' => 'mock_mode' )
			);
		}
	}

	/**
	 * Whether WP_DEBUG is on.
	 *
	 * @return bool
	 */
	public static function debug_enabled() {
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/**
	 * Whether mock mode is active (debug-gated): the generate endpoint returns a
	 * canned template instead of calling a real provider.
	 *
	 * @return bool
	 */
	public function is_mock_mode() {
		if ( ! self::debug_enabled() ) {
			return false;
		}
		$opts = $this->get_settings();
		return ! empty( $opts['mock_mode'] );
	}

	/**
	 * Read the stored settings array.
	 *
	 * @return array
	 */
	public function get_settings() {
		$opts = get_option( self::OPTION_KEY, array() );
		return is_array( $opts ) ? $opts : array();
	}

	/**
	 * Get a decrypted API key for runtime use.
	 *
	 * @param string $field One of the secret field keys.
	 * @return string
	 */
	public function get_key( $field ) {
		$opts = $this->get_settings();
		if ( empty( $opts[ $field ] ) ) {
			return '';
		}
		return Crypto::decrypt( $opts[ $field ] );
	}

	/**
	 * Get the configured Ollama server URL (falls back to the default).
	 *
	 * @return string
	 */
	public function get_ollama_url() {
		$opts = $this->get_settings();
		$url  = isset( $opts['ollama_url'] ) ? (string) $opts['ollama_url'] : '';
		return '' !== $url ? $url : Provider_Ollama::DEFAULT_URL;
	}

	/**
	 * Get the configured Ollama model name (falls back to the default).
	 *
	 * @return string
	 */
	public function get_ollama_model() {
		$opts  = $this->get_settings();
		$model = isset( $opts['ollama_model'] ) ? (string) $opts['ollama_model'] : '';
		return '' !== $model ? $model : Provider_Ollama::DEFAULT_MODEL;
	}

	/**
	 * Sanitize + encrypt on save.
	 *
	 * Masked submissions (value contains the mask bullet) mean "unchanged",
	 * so we retain the previously stored encrypted value.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array Clean values to persist.
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$existing = $this->get_settings();
		$clean    = array();

		// API keys: encrypt new values, retain on masked/unchanged input.
		foreach ( $this->secret_fields as $field ) {
			$submitted = isset( $input[ $field ] ) ? trim( (string) $input[ $field ] ) : '';

			if ( '' === $submitted ) {
				// Empty submission clears the key.
				$clean[ $field ] = '';
				continue;
			}

			// Mask bullet present => treat as "leave unchanged".
			if ( false !== strpos( $submitted, '•' ) ) {
				$clean[ $field ] = isset( $existing[ $field ] ) ? $existing[ $field ] : '';
				continue;
			}

			$clean[ $field ] = Crypto::encrypt( sanitize_text_field( $submitted ) );
		}

		// Default provider: must be a known provider.
		$providers = $this->providers();
		$provider  = isset( $input['default_provider'] ) ? sanitize_key( $input['default_provider'] ) : '';
		$clean['default_provider'] = isset( $providers[ $provider ] ) ? $provider : key( $providers );

		// Default model per provider: must be a known model for that provider.
		foreach ( $providers as $key => $provider_def ) {
			$mfield    = 'default_model_' . $key;
			$submitted = isset( $input[ $mfield ] ) ? sanitize_text_field( $input[ $mfield ] ) : '';
			$clean[ $mfield ] = isset( $provider_def['models'][ $submitted ] )
				? $submitted
				: (string) key( $provider_def['models'] );
		}

		// Ollama: server URL + model name (empty falls back to defaults at read time).
		$clean['ollama_url']   = isset( $input['ollama_url'] ) ? esc_url_raw( trim( (string) $input['ollama_url'] ) ) : '';
		$clean['ollama_model'] = isset( $input['ollama_model'] ) ? sanitize_text_field( trim( (string) $input['ollama_model'] ) ) : '';

		// Mock mode: debug-gated checkbox. Retain the stored value when WP_DEBUG is
		// off, since the field is not rendered (and thus not submitted) then.
		if ( self::debug_enabled() ) {
			$clean['mock_mode'] = empty( $input['mock_mode'] ) ? 0 : 1;
		} elseif ( isset( $existing['mock_mode'] ) ) {
			$clean['mock_mode'] = $existing['mock_mode'];
		}

		add_settings_error( self::OPTION_KEY, 'aieb_saved', __( 'Settings saved.', 'ai-elementor-builder' ), 'updated' );

		return $clean;
	}

	/**
	 * Render an API key input (masked when a value exists).
	 *
	 * @param array $args Field args.
	 * @return void
	 */
	public function render_key_field( $args ) {
		$field = $args['field'];
		$opts  = $this->get_settings();
		$value = '';

		if ( ! empty( $opts[ $field ] ) ) {
			$value = Crypto::mask( Crypto::decrypt( $opts[ $field ] ) );
		}

		printf(
			'<input type="text" class="regular-text" id="%1$s" name="%2$s[%1$s]" value="%3$s" autocomplete="off" spellcheck="false" placeholder="%4$s" />',
			esc_attr( $field ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( $value ),
			esc_attr__( 'Enter API key', 'ai-elementor-builder' )
		);

		// Map the secret field back to its provider key for the test endpoint.
		$provider_for_field = array(
			'anthropic_api_key' => 'anthropic',
			'openai_api_key'    => 'openai',
			'gemini_api_key'    => 'gemini',
			'openrouter_api_key' => 'openrouter',
			'nvidia_api_key'    => 'nvidia',
		);
		$provider = isset( $provider_for_field[ $field ] ) ? $provider_for_field[ $field ] : '';

		if ( '' !== $provider ) {
			printf(
				' <button type="button" class="button aieb-test-key" data-provider="%1$s" data-field="%2$s">%3$s</button>',
				esc_attr( $provider ),
				esc_attr( $field ),
				esc_html__( 'Test API Key', 'ai-elementor-builder' )
			);
			printf(
				' <span class="aieb-key-badge" id="aieb-key-badge-%1$s" role="status" aria-live="polite"></span>',
				esc_attr( $provider )
			);
		}

		if ( '' !== $value ) {
			echo '<p class="description">' . esc_html__( 'A key is saved. Type a new key to replace it.', 'ai-elementor-builder' ) . '</p>';
		}
	}

	/**
	 * Render the Mock Mode checkbox (developer tool).
	 *
	 * @return void
	 */
	public function render_mock_field() {
		$opts    = $this->get_settings();
		$checked = ! empty( $opts['mock_mode'] );

		printf(
			'<label><input type="checkbox" id="mock_mode" name="%1$s[mock_mode]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_KEY ),
			checked( $checked, true, false ),
			esc_html__( 'Skip the real API call and return a canned Elementor template instantly.', 'ai-elementor-builder' )
		);

		echo '<p class="description">' . esc_html__( 'For testing the generate → preview → push flow without an API key or provider costs.', 'ai-elementor-builder' ) . '</p>';
	}

	/**
	 * Render the Ollama section intro: what it is, plus the localhost/remote and
	 * OLLAMA_HOST caveats.
	 *
	 * @return void
	 */
	public function render_ollama_section() {
		echo '<p>' . esc_html__( 'Run a local LLM with Ollama — no API key or license needed, free and offline.', 'ai-elementor-builder' ) . '</p>';

		printf(
			'<p class="notice notice-warning aieb-inline-notice"><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'Note:', 'ai-elementor-builder' ),
			esc_html__( 'Ollama must be running on the same server as WordPress. If you are using LocalWP, XAMPP, WAMP or running WordPress directly on your computer, this will work. If WordPress is on a remote/hosted server, Ollama must also be installed on that server.', 'ai-elementor-builder' )
		);

		printf(
			'<p class="description">%1$s <code>OLLAMA_HOST=0.0.0.0 ollama serve</code> %2$s</p>',
			esc_html__( 'If Ollama is unreachable, run:', 'ai-elementor-builder' ),
			esc_html__( 'to allow connections from all interfaces.', 'ai-elementor-builder' )
		);
	}

	/**
	 * Render the Ollama server URL field plus the Test Connection control.
	 *
	 * @return void
	 */
	public function render_ollama_url_field() {
		$opts  = $this->get_settings();
		$value = isset( $opts['ollama_url'] ) && '' !== $opts['ollama_url']
			? (string) $opts['ollama_url']
			: Provider_Ollama::DEFAULT_URL;

		printf(
			'<input type="url" class="regular-text" id="ollama_url" name="%1$s[ollama_url]" value="%2$s" autocomplete="off" spellcheck="false" placeholder="%3$s" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $value ),
			esc_attr( Provider_Ollama::DEFAULT_URL )
		);

		echo ' <button type="button" class="button aieb-ollama-test">' . esc_html__( 'Test Connection', 'ai-elementor-builder' ) . '</button>';
		echo ' <span class="aieb-key-badge" id="aieb-ollama-badge" role="status" aria-live="polite"></span>';
		echo '<p class="description">' . esc_html__( 'Leave default if running Ollama locally', 'ai-elementor-builder' ) . '</p>';
	}

	/**
	 * Render the Ollama model field. Starts as a text input; settings.js swaps in
	 * a dropdown of pulled models after a successful Test Connection.
	 *
	 * @return void
	 */
	public function render_ollama_model_field() {
		$opts  = $this->get_settings();
		$value = isset( $opts['ollama_model'] ) && '' !== $opts['ollama_model']
			? (string) $opts['ollama_model']
			: Provider_Ollama::DEFAULT_MODEL;

		printf(
			'<input type="text" class="regular-text" id="ollama_model" name="%1$s[ollama_model]" value="%2$s" autocomplete="off" spellcheck="false" placeholder="%3$s" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $value ),
			esc_attr( Provider_Ollama::DEFAULT_MODEL )
		);

		echo '<p class="description">' . esc_html__( 'Must be already pulled via: ollama pull modelname', 'ai-elementor-builder' ) . '</p>';
	}

	/**
	 * Render the default provider dropdown.
	 *
	 * @return void
	 */
	public function render_provider_field() {
		$opts     = $this->get_settings();
		$current  = isset( $opts['default_provider'] ) ? $opts['default_provider'] : '';

		echo '<select id="default_provider" name="' . esc_attr( self::OPTION_KEY ) . '[default_provider]">';
		foreach ( $this->providers() as $key => $provider ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $key ),
				selected( $current, $key, false ),
				esc_html( $provider['label'] )
			);
		}
		echo '</select>';
	}

	/**
	 * Render a per-provider default model dropdown.
	 *
	 * @param array $args Field args.
	 * @return void
	 */
	public function render_model_field( $args ) {
		$provider_key = $args['provider'];
		$providers    = $this->providers();
		if ( ! isset( $providers[ $provider_key ] ) ) {
			return;
		}

		$opts    = $this->get_settings();
		$mfield  = 'default_model_' . $provider_key;
		$current = isset( $opts[ $mfield ] ) ? $opts[ $mfield ] : '';

		echo '<select id="' . esc_attr( $mfield ) . '" name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $mfield ) . ']">';
		foreach ( $providers[ $provider_key ]['models'] as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}
}
