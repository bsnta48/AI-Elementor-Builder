<?php
/**
 * Builder admin page.
 *
 * Two-column layout: prompt/provider/section controls on the left, HTML
 * preview + JSON toggle on the right. Behavior lives in assets/js/builder.js.
 *
 * @package AI_Elementor_Builder
 *
 * @var string $title Page title.
 */

use AI_Elementor_Builder\Settings\Settings;

defined( 'ABSPATH' ) || exit;

$aieb_opts             = get_option( Settings::OPTION_KEY, array() );
$aieb_default_provider = isset( $aieb_opts['default_provider'] ) ? (string) $aieb_opts['default_provider'] : 'anthropic';

$aieb_providers = array(
	'anthropic' => __( 'Claude', 'ai-elementor-builder' ),
	'openai'    => __( 'OpenAI', 'ai-elementor-builder' ),
	'gemini'    => __( 'Gemini', 'ai-elementor-builder' ),
	'openrouter' => __( 'OpenRouter', 'ai-elementor-builder' ),
	'nvidia'    => __( 'NVIDIA', 'ai-elementor-builder' ),
	'ollama'    => __( 'Ollama (Local)', 'ai-elementor-builder' ),
);

if ( ! isset( $aieb_providers[ $aieb_default_provider ] ) ) {
	$aieb_default_provider = 'anthropic';
}

$aieb_sections = array(
	'fullpage'     => __( 'Full Page (all sections)', 'ai-elementor-builder' ),
	'hero'         => __( 'Hero', 'ai-elementor-builder' ),
	'pricing'      => __( 'Pricing', 'ai-elementor-builder' ),
	'about'        => __( 'About', 'ai-elementor-builder' ),
	'contact'      => __( 'Contact', 'ai-elementor-builder' ),
	'testimonials' => __( 'Testimonials', 'ai-elementor-builder' ),
	'features'     => __( 'Features', 'ai-elementor-builder' ),
	'custom'       => __( 'Custom', 'ai-elementor-builder' ),
);

// Prompt templates: clicking a button pre-fills the textarea and selects the
// matching section type. label => [ section, prompt ].
$aieb_templates = array(
	'hero'         => array(
		'label'  => __( 'Hero', 'ai-elementor-builder' ),
		'prompt' => __( 'Create a hero section with a bold headline, a supporting subheadline, and a prominent call-to-action button centered over a spacious full-width background.', 'ai-elementor-builder' ),
	),
	'pricing'      => array(
		'label'  => __( 'Pricing', 'ai-elementor-builder' ),
		'prompt' => __( 'Create a pricing section with three side-by-side pricing tiers (Starter, Pro, Enterprise). Each tier has a name, price, a short feature list, and a sign-up button. Highlight the middle tier as most popular.', 'ai-elementor-builder' ),
	),
	'about'        => array(
		'label'  => __( 'About', 'ai-elementor-builder' ),
		'prompt' => __( 'Create an about section with a heading, two paragraphs of company story text, and a supporting image alongside the copy.', 'ai-elementor-builder' ),
	),
	'features'     => array(
		'label'  => __( 'Features', 'ai-elementor-builder' ),
		'prompt' => __( 'Create a features section with a centered heading and a three-column grid of feature cards, each with an icon, a short title, and a one-line description.', 'ai-elementor-builder' ),
	),
	'testimonials' => array(
		'label'  => __( 'Testimonials', 'ai-elementor-builder' ),
		'prompt' => __( 'Create a testimonials section with a heading and three customer quotes, each showing the quote text, the author name, and their role or company.', 'ai-elementor-builder' ),
	),
	'contact'      => array(
		'label'  => __( 'Contact', 'ai-elementor-builder' ),
		'prompt' => __( 'Create a contact section with a heading, a short invitation paragraph, contact details (email, phone, address), and a prominent call-to-action button.', 'ai-elementor-builder' ),
	),
);
?>
<div class="wrap aieb-builder">
	<h1><?php echo esc_html( $title ); ?></h1>

	<div id="aieb-notice" class="notice notice-error aieb-notice aieb-hidden" role="alert" aria-live="assertive">
		<p id="aieb-notice-message"></p>
		<button type="button" id="aieb-notice-dismiss" class="aieb-notice-dismiss">
			<span class="dashicons dashicons-no-alt"></span>
			<span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'ai-elementor-builder' ); ?></span>
		</button>
	</div>

	<div class="aieb-grid">
		<!-- Left: controls -->
		<div class="aieb-pane">
			<div class="aieb-field">
				<span class="aieb-templates-label"><?php esc_html_e( 'Start from a template', 'ai-elementor-builder' ); ?></span>
				<div class="aieb-templates" role="group" aria-label="<?php esc_attr_e( 'Prompt templates', 'ai-elementor-builder' ); ?>">
					<?php foreach ( $aieb_templates as $aieb_tkey => $aieb_tpl ) : ?>
						<button
							type="button"
							class="button aieb-template-btn"
							data-section="<?php echo esc_attr( $aieb_tkey ); ?>"
							data-prompt="<?php echo esc_attr( $aieb_tpl['prompt'] ); ?>"
						>
							<?php echo esc_html( $aieb_tpl['label'] ); ?>
						</button>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="aieb-field">
				<label for="aieb-prompt"><?php esc_html_e( 'Design prompt', 'ai-elementor-builder' ); ?></label>
				<textarea id="aieb-prompt" placeholder="<?php esc_attr_e( 'Describe the section you want to build…', 'ai-elementor-builder' ); ?>"></textarea>
			</div>

			<div class="aieb-field">
				<label for="aieb-image"><?php esc_html_e( 'Reference image (optional)', 'ai-elementor-builder' ); ?></label>
				<p class="aieb-field-hint"><?php esc_html_e( 'Attach a screenshot or mockup. Sent to the model for vision-capable providers (Claude, OpenAI, Gemini).', 'ai-elementor-builder' ); ?></p>
				<input type="file" id="aieb-image" accept="image/png,image/jpeg,image/webp,image/gif" />
				<div id="aieb-image-preview" class="aieb-image-preview aieb-hidden">
					<img id="aieb-image-thumb" src="" alt="<?php esc_attr_e( 'Selected reference image preview', 'ai-elementor-builder' ); ?>" />
					<button type="button" id="aieb-image-remove" class="button-link aieb-image-remove">
						<?php esc_html_e( 'Remove image', 'ai-elementor-builder' ); ?>
					</button>
				</div>
			</div>

			<fieldset class="aieb-field aieb-fieldset">
				<legend><?php esc_html_e( 'Provider', 'ai-elementor-builder' ); ?></legend>
				<div class="aieb-radios">
					<?php foreach ( $aieb_providers as $aieb_key => $aieb_label ) : ?>
						<label>
							<input type="radio" name="aieb_provider" value="<?php echo esc_attr( $aieb_key ); ?>" <?php checked( $aieb_default_provider, $aieb_key ); ?> />
							<?php echo esc_html( $aieb_label ); ?>
							<?php if ( 'ollama' === $aieb_key ) : ?>
								<span class="aieb-provider-badge"><?php esc_html_e( 'Free · Offline', 'ai-elementor-builder' ); ?></span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<div class="aieb-field">
				<label for="aieb-section-type"><?php esc_html_e( 'Section type', 'ai-elementor-builder' ); ?></label>
				<select id="aieb-section-type">
					<?php foreach ( $aieb_sections as $aieb_value => $aieb_label ) : ?>
						<option value="<?php echo esc_attr( $aieb_value ); ?>"><?php echo esc_html( $aieb_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="aieb-field">
				<label for="aieb-reference"><?php esc_html_e( 'Design reference (optional)', 'ai-elementor-builder' ); ?></label>
				<p class="aieb-field-hint"><?php esc_html_e( 'Pick a curated design to guide structure, layout and color. The AI adapts it to your prompt — great for complex or polished sections.', 'ai-elementor-builder' ); ?></p>
				<select id="aieb-reference">
					<option value=""><?php esc_html_e( '— No reference —', 'ai-elementor-builder' ); ?></option>
				</select>
				<p id="aieb-reference-desc" class="aieb-field-hint aieb-reference-desc"></p>
			</div>

			<div class="aieb-actions">
				<button type="button" id="aieb-generate" class="button button-primary button-hero">
					<?php esc_html_e( 'Generate', 'ai-elementor-builder' ); ?>
				</button>
			</div>

			<details class="aieb-history" open>
				<summary><?php esc_html_e( 'History', 'ai-elementor-builder' ); ?></summary>
				<ul id="aieb-history-list" class="aieb-history-list"></ul>

				<span class="aieb-templates-history-label"><?php esc_html_e( 'Templates', 'ai-elementor-builder' ); ?></span>
				<ul id="aieb-template-history-list" class="aieb-history-list"></ul>
			</details>
		</div>

		<!-- Right: preview -->
		<div class="aieb-pane aieb-preview-pane">
			<div class="aieb-preview-toolbar">
				<h2><?php esc_html_e( 'Preview', 'ai-elementor-builder' ); ?></h2>
				<div class="aieb-preview-tools">
					<button type="button" id="aieb-toggle-json" class="button">
						<?php esc_html_e( 'View JSON', 'ai-elementor-builder' ); ?>
					</button>
					<button type="button" id="aieb-fullscreen" class="button aieb-fullscreen-btn" aria-pressed="false" title="<?php esc_attr_e( 'Toggle fullscreen preview', 'ai-elementor-builder' ); ?>">
						<span class="dashicons dashicons-fullscreen-alt" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Toggle fullscreen preview', 'ai-elementor-builder' ); ?></span>
					</button>
				</div>
			</div>
			<div class="aieb-preview-body">
				<div id="aieb-overlay" class="aieb-overlay aieb-hidden">
					<div class="aieb-spinner" aria-hidden="true"></div>
					<p><?php esc_html_e( 'Generating…', 'ai-elementor-builder' ); ?></p>
				</div>
				<iframe id="aieb-preview-frame" class="aieb-preview-frame" title="<?php esc_attr_e( 'Template preview', 'ai-elementor-builder' ); ?>"></iframe>
				<pre id="aieb-json" class="aieb-json aieb-hidden" tabindex="0"></pre>
			</div>

			<div class="aieb-push-bar">
				<label for="aieb-page-select" class="screen-reader-text"><?php esc_html_e( 'Target page', 'ai-elementor-builder' ); ?></label>
				<select id="aieb-page-select">
					<option value=""><?php esc_html_e( 'Loading pages…', 'ai-elementor-builder' ); ?></option>
				</select>
				<button type="button" id="aieb-push" class="button button-secondary" disabled>
					<?php esc_html_e( 'Push to Elementor', 'ai-elementor-builder' ); ?>
				</button>
				<button type="button" id="aieb-download" class="button button-secondary" disabled>
					<?php esc_html_e( 'Download Template', 'ai-elementor-builder' ); ?>
				</button>
				<span id="aieb-push-status" class="aieb-push-status" role="status" aria-live="polite"></span>
			</div>

			<div class="aieb-template-bar">
				<div class="aieb-template-opts">
					<span class="aieb-template-opt">
						<label for="aieb-template-type"><?php esc_html_e( 'Template type:', 'ai-elementor-builder' ); ?></label>
						<select id="aieb-template-type">
							<option value="page" selected><?php esc_html_e( 'Page', 'ai-elementor-builder' ); ?></option>
							<option value="section"><?php esc_html_e( 'Section', 'ai-elementor-builder' ); ?></option>
							<option value="header"><?php esc_html_e( 'Header', 'ai-elementor-builder' ); ?></option>
							<option value="footer"><?php esc_html_e( 'Footer', 'ai-elementor-builder' ); ?></option>
							<option value="popup"><?php esc_html_e( 'Popup', 'ai-elementor-builder' ); ?></option>
						</select>
					</span>
					<span class="aieb-template-opt">
						<label for="aieb-template-title"><?php esc_html_e( 'Template title', 'ai-elementor-builder' ); ?></label>
						<input type="text" id="aieb-template-title" placeholder="<?php esc_attr_e( 'My AI Template', 'ai-elementor-builder' ); ?>" />
					</span>
					<span class="aieb-template-opt">
						<button type="button" id="aieb-push-template" class="button button-primary" disabled>
							<?php esc_html_e( 'Push as Template', 'ai-elementor-builder' ); ?>
						</button>
					</span>
				</div>
				<span id="aieb-download-status" class="aieb-push-status" role="status" aria-live="polite"></span>
				<span id="aieb-push-template-status" class="aieb-push-status" role="status" aria-live="polite"></span>

				<details class="aieb-import-hint">
					<summary><?php esc_html_e( 'How to import this file? ↓', 'ai-elementor-builder' ); ?></summary>
					<ol class="aieb-import-steps">
						<li><?php esc_html_e( 'Go to WordPress Admin → Templates → Saved Templates', 'ai-elementor-builder' ); ?></li>
						<li><?php esc_html_e( 'Click \'Import Templates\' at the top', 'ai-elementor-builder' ); ?></li>
						<li><?php esc_html_e( 'Upload the downloaded .json file', 'ai-elementor-builder' ); ?></li>
						<li><?php esc_html_e( 'Click \'Import Now\'', 'ai-elementor-builder' ); ?></li>
						<li><?php esc_html_e( 'Open any page in Elementor → click the folder icon → My Templates → Insert', 'ai-elementor-builder' ); ?></li>
					</ol>
				</details>
			</div>
		</div>
	</div>
</div>
