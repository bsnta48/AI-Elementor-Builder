<?php
/**
 * Settings admin page — sidenav + cards layout.
 *
 * Renders custom markup inside the standard options.php form so the WordPress
 * Settings API still saves and sanitizes (Settings::sanitize). Every field keeps
 * its exact name="aieb_settings[<field>]" + the JS hooks settings.js relies on
 * (.aieb-test-key, #aieb-key-badge-<prov>, .aieb-ollama-test, #ollama_url,
 * #ollama_model, #default_provider, #default_model_<key>, #mock_mode).
 *
 * @package AI_Elementor_Builder
 *
 * @var string $title Page title.
 */

use AI_Elementor_Builder\Settings\Settings;
use AI_Elementor_Builder\Settings\Crypto;
use AI_Elementor_Builder\Providers\Provider_Ollama;

defined( 'ABSPATH' ) || exit;

$aieb_settings_obj  = new Settings();
$aieb_opts          = $aieb_settings_obj->get_settings();
$aieb_providers_def = $aieb_settings_obj->providers();
$aieb_debug         = Settings::debug_enabled();

// Provider key cards: field, provider key, badge id source, ico + sub copy.
$aieb_key_cards = array(
	'anthropic'  => array( 'field' => 'anthropic_api_key', 'name' => __( 'Anthropic', 'ai-elementor-builder' ), 'sub' => __( 'Claude family', 'ai-elementor-builder' ), 'ico' => 'CL' ),
	'openai'     => array( 'field' => 'openai_api_key', 'name' => __( 'OpenAI', 'ai-elementor-builder' ), 'sub' => __( 'GPT family', 'ai-elementor-builder' ), 'ico' => 'AI' ),
	'gemini'     => array( 'field' => 'gemini_api_key', 'name' => __( 'Google Gemini', 'ai-elementor-builder' ), 'sub' => __( 'Gemini family', 'ai-elementor-builder' ), 'ico' => 'GE' ),
	'openrouter' => array( 'field' => 'openrouter_api_key', 'name' => __( 'OpenRouter', 'ai-elementor-builder' ), 'sub' => __( 'Multi-model gateway', 'ai-elementor-builder' ), 'ico' => 'OR' ),
	'nvidia'     => array( 'field' => 'nvidia_api_key', 'name' => __( 'NVIDIA', 'ai-elementor-builder' ), 'sub' => __( 'NIM / build.nvidia.com', 'ai-elementor-builder' ), 'ico' => 'NV' ),
);

$aieb_default_provider = isset( $aieb_opts['default_provider'] ) ? (string) $aieb_opts['default_provider'] : '';
$aieb_ollama_url       = isset( $aieb_opts['ollama_url'] ) && '' !== $aieb_opts['ollama_url'] ? (string) $aieb_opts['ollama_url'] : Provider_Ollama::DEFAULT_URL;
$aieb_ollama_model     = isset( $aieb_opts['ollama_model'] ) && '' !== $aieb_opts['ollama_model'] ? (string) $aieb_opts['ollama_model'] : Provider_Ollama::DEFAULT_MODEL;

$aieb_builder_url  = menu_page_url( 'ai-elementor-builder', false );
$aieb_settings_url = menu_page_url( 'ai-elementor-builder-settings', false );
$aieb_opt_key      = Settings::OPTION_KEY;
?>
<div class="aieb-settings-wrap aieb-app">

	<header class="aieb-appbar">
		<div class="aieb-brand">
			<span class="aieb-logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/></svg></span>
			<b><?php esc_html_e( 'Elementor Builder', 'ai-elementor-builder' ); ?></b><span class="aieb-tag"><?php esc_html_e( 'AI', 'ai-elementor-builder' ); ?></span>
		</div>
		<span class="aieb-spacer"></span>
		<nav class="aieb-nav">
			<a href="<?php echo esc_url( $aieb_builder_url ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16v6H4zM4 14h10v6H4zM18 14h2v6h-2z"/></svg><?php esc_html_e( 'Builder', 'ai-elementor-builder' ); ?></a>
			<a class="active" href="<?php echo esc_url( $aieb_settings_url ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 7 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0-1.1-2.7H1a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 7"/></svg><?php esc_html_e( 'Settings', 'ai-elementor-builder' ); ?></a>
		</nav>
	</header>

	<form action="options.php" method="post" id="aieb-settings-form">
		<?php settings_fields( Settings::GROUP ); ?>

		<div class="aieb-settings-page">
			<div class="aieb-pagehead">
				<div class="eyebrow"><?php esc_html_e( 'Configuration', 'ai-elementor-builder' ); ?></div>
				<h1><?php esc_html_e( 'Settings', 'ai-elementor-builder' ); ?></h1>
				<p><?php esc_html_e( 'Connect AI providers, set defaults, and test your keys. Keys are stored obfuscated and shown masked — leave a field unchanged to keep the existing key.', 'ai-elementor-builder' ); ?></p>
			</div>

			<?php settings_errors( Settings::OPTION_KEY ); ?>

			<div class="aieb-settings-layout">
				<nav class="aieb-sidenav" id="aieb-sidenav">
					<a class="active" href="#aieb-keys"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 2l-2 2m-7.6 7.6a5 5 0 1 0-2 2L13 11m0 0l2 2m-2-2l2.5-2.5"/></svg><?php esc_html_e( 'API Keys', 'ai-elementor-builder' ); ?></a>
					<a href="#aieb-ollama"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="4" width="20" height="14" rx="2"/><path d="M8 21h8M12 18v3M6 9h4M6 13h8"/></svg><?php esc_html_e( 'Local LLM', 'ai-elementor-builder' ); ?></a>
					<a href="#aieb-defaults"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/></svg><?php esc_html_e( 'Defaults', 'ai-elementor-builder' ); ?></a>
					<?php if ( $aieb_debug ) : ?>
						<a href="#aieb-dev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/></svg><?php esc_html_e( 'Developer', 'ai-elementor-builder' ); ?></a>
					<?php endif; ?>
				</nav>

				<div>
					<!-- API KEYS -->
					<section class="aieb-block" id="aieb-keys">
						<div class="aieb-block-h"><h2><?php esc_html_e( 'API Keys', 'ai-elementor-builder' ); ?></h2><span class="meta"><?php esc_html_e( '5 providers', 'ai-elementor-builder' ); ?></span></div>
						<div class="card">
							<?php
							foreach ( $aieb_key_cards as $aieb_pkey => $aieb_card ) :
								$aieb_field  = $aieb_card['field'];
								$aieb_masked = '';
								if ( ! empty( $aieb_opts[ $aieb_field ] ) ) {
									$aieb_masked = Crypto::mask( Crypto::decrypt( $aieb_opts[ $aieb_field ] ) );
								}
								$aieb_saved = '' !== $aieb_masked;
								?>
								<div class="aieb-prov-card" data-prov="<?php echo esc_attr( $aieb_pkey ); ?>">
									<div class="id"><span class="ico"><?php echo esc_html( $aieb_card['ico'] ); ?></span><span><div class="nm"><?php echo esc_html( $aieb_card['name'] ); ?></div><div class="sub"><?php echo esc_html( $aieb_card['sub'] ); ?></div></span></div>
									<div class="keyzone">
										<div class="keywrap">
											<input class="input pw mono" type="password" id="<?php echo esc_attr( $aieb_field ); ?>" name="<?php echo esc_attr( $aieb_opt_key ); ?>[<?php echo esc_attr( $aieb_field ); ?>]" value="<?php echo esc_attr( $aieb_masked ); ?>" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e( 'Enter API key', 'ai-elementor-builder' ); ?>"<?php echo $aieb_saved ? ' data-saved="1"' : ''; ?> />
											<button class="reveal" type="button" aria-label="<?php esc_attr_e( 'Reveal key', 'ai-elementor-builder' ); ?>"></button>
										</div>
									</div>
									<div class="stat">
										<?php if ( $aieb_saved ) : ?>
											<span class="pill ok"><span class="dot on"></span><?php esc_html_e( 'Saved', 'ai-elementor-builder' ); ?></span>
										<?php else : ?>
											<span class="pill warn"><span class="dot warn"></span><?php esc_html_e( 'Not set', 'ai-elementor-builder' ); ?></span>
										<?php endif; ?>
									</div>
									<button class="btn sm test aieb-test-key" type="button" data-provider="<?php echo esc_attr( $aieb_pkey ); ?>" data-field="<?php echo esc_attr( $aieb_field ); ?>"><?php esc_html_e( 'Test', 'ai-elementor-builder' ); ?></button>
									<span class="aieb-key-badge" id="aieb-key-badge-<?php echo esc_attr( $aieb_pkey ); ?>" role="status" aria-live="polite"></span>
								</div>
							<?php endforeach; ?>
						</div>
						<p class="hint" style="margin-top:9px;"><?php esc_html_e( 'A key is saved. Type a new value to replace it, or leave masked to keep the current one.', 'ai-elementor-builder' ); ?></p>
					</section>

					<!-- OLLAMA -->
					<section class="aieb-block" id="aieb-ollama">
						<div class="aieb-block-h"><h2><?php esc_html_e( 'Local LLM', 'ai-elementor-builder' ); ?></h2><span class="meta"><?php esc_html_e( 'Ollama · no key, free, offline', 'ai-elementor-builder' ); ?></span></div>
						<div class="card"><div class="card-b" style="display:flex;flex-direction:column;gap:16px;">
							<div class="aieb-note">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
								<span><?php
								printf(
									/* translators: %s: ollama serve command. */
									esc_html__( 'Ollama must run on the same server as WordPress. On a remote/hosted site Ollama must also be installed there. To allow remote connections start it with %s.', 'ai-elementor-builder' ),
									'<code>OLLAMA_HOST=0.0.0.0 ollama serve</code>'
								);
								?></span>
							</div>
							<div class="aieb-deframe">
								<div class="lbl"><?php esc_html_e( 'Server URL', 'ai-elementor-builder' ); ?><div class="sub"><?php esc_html_e( 'Leave default if Ollama runs locally', 'ai-elementor-builder' ); ?></div></div>
								<div style="display:flex;gap:8px;max-width:440px;">
									<input class="input mono" type="url" id="ollama_url" name="<?php echo esc_attr( $aieb_opt_key ); ?>[ollama_url]" value="<?php echo esc_attr( $aieb_ollama_url ); ?>" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( Provider_Ollama::DEFAULT_URL ); ?>" style="flex:1" />
									<button class="btn sm aieb-ollama-test" type="button"><?php esc_html_e( 'Test connection', 'ai-elementor-builder' ); ?></button>
								</div>
								<span class="aieb-key-badge" id="aieb-ollama-badge" role="status" aria-live="polite"></span>
								<hr class="sep" />
								<div class="lbl"><?php esc_html_e( 'Model name', 'ai-elementor-builder' ); ?><div class="sub"><?php
								printf(
									/* translators: %s: ollama pull command. */
									esc_html__( 'Must already be pulled: %s', 'ai-elementor-builder' ),
									'<code>ollama pull modelname</code>'
								);
								?></div></div>
								<input class="input mono" type="text" id="ollama_model" name="<?php echo esc_attr( $aieb_opt_key ); ?>[ollama_model]" value="<?php echo esc_attr( $aieb_ollama_model ); ?>" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( Provider_Ollama::DEFAULT_MODEL ); ?>" style="max-width:280px" />
							</div>
						</div></div>
					</section>

					<!-- DEFAULTS -->
					<section class="aieb-block" id="aieb-defaults">
						<div class="aieb-block-h"><h2><?php esc_html_e( 'Defaults', 'ai-elementor-builder' ); ?></h2><span class="meta"><?php esc_html_e( "Used when a request doesn't specify", 'ai-elementor-builder' ); ?></span></div>
						<div class="card"><div class="card-b">
							<div class="aieb-deframe">
								<div class="lbl"><?php esc_html_e( 'Default provider', 'ai-elementor-builder' ); ?></div>
								<select class="input" id="default_provider" name="<?php echo esc_attr( $aieb_opt_key ); ?>[default_provider]">
									<?php foreach ( $aieb_providers_def as $aieb_pk => $aieb_pdef ) : ?>
										<option value="<?php echo esc_attr( $aieb_pk ); ?>" <?php selected( $aieb_default_provider, $aieb_pk ); ?>><?php echo esc_html( $aieb_pdef['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<hr class="sep" />
								<?php
								foreach ( $aieb_providers_def as $aieb_pk => $aieb_pdef ) :
									$aieb_mfield  = 'default_model_' . $aieb_pk;
									$aieb_current = isset( $aieb_opts[ $aieb_mfield ] ) ? $aieb_opts[ $aieb_mfield ] : '';
									?>
									<div class="lbl"><?php echo esc_html( $aieb_pdef['label'] ); ?><div class="sub"><?php esc_html_e( 'Default model', 'ai-elementor-builder' ); ?></div></div>
									<select class="input" id="<?php echo esc_attr( $aieb_mfield ); ?>" name="<?php echo esc_attr( $aieb_opt_key ); ?>[<?php echo esc_attr( $aieb_mfield ); ?>]">
										<?php foreach ( $aieb_pdef['models'] as $aieb_mval => $aieb_mlabel ) : ?>
											<option value="<?php echo esc_attr( $aieb_mval ); ?>" <?php selected( $aieb_current, $aieb_mval ); ?>><?php echo esc_html( $aieb_mlabel ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php endforeach; ?>
							</div>
						</div></div>
					</section>

					<?php if ( $aieb_debug ) : ?>
						<!-- DEVELOPER -->
						<section class="aieb-block" id="aieb-dev">
							<div class="aieb-block-h"><h2><?php esc_html_e( 'Developer', 'ai-elementor-builder' ); ?></h2><span class="meta"><?php
							printf(
								/* translators: %s: WP_DEBUG constant name. */
								esc_html__( 'Shown because %s is enabled', 'ai-elementor-builder' ),
								'<span class="mono">WP_DEBUG</span>'
							);
							?></span></div>
							<div class="card"><div class="card-b">
								<div class="aieb-toggle-row">
									<div class="tx"><div class="tt"><?php esc_html_e( 'Mock mode', 'ai-elementor-builder' ); ?></div><div class="ts"><?php esc_html_e( 'Skip the real API call and return a canned Elementor template. Test the generate → preview → push flow without provider cost.', 'ai-elementor-builder' ); ?></div></div>
									<input type="checkbox" id="mock_mode" class="aieb-toggle-input screen-reader-text" name="<?php echo esc_attr( $aieb_opt_key ); ?>[mock_mode]" value="1" <?php checked( ! empty( $aieb_opts['mock_mode'] ) ); ?> />
									<label class="aieb-toggle" for="mock_mode"><span class="screen-reader-text"><?php esc_html_e( 'Toggle mock mode', 'ai-elementor-builder' ); ?></span></label>
								</div>
							</div></div>
						</section>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="aieb-savebar">
			<div class="inner">
				<span class="status" id="aieb-save-status"><span class="dot on"></span><?php esc_html_e( 'All changes saved', 'ai-elementor-builder' ); ?></span>
				<span class="grow"></span>
				<button class="btn" type="button" id="aieb-reset-btn"><?php esc_html_e( 'Discard', 'ai-elementor-builder' ); ?></button>
				<button class="btn primary" type="submit" id="aieb-save-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg><?php esc_html_e( 'Save changes', 'ai-elementor-builder' ); ?></button>
			</div>
		</div>
	</form>

	<div class="toast" id="aieb-toast" role="status" aria-live="polite"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M20 6L9 17l-5-5"/></svg><span id="aieb-toast-msg"></span></div>
</div>
