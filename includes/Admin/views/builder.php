<?php
/**
 * Builder admin page — "Studio" layout.
 *
 * Full-bleed two-column studio: left compose rail (segmented Compose / History /
 * Saved tabs, provider grid) and a center preview stage with a JSON drawer and a
 * bottom action bar. Behavior lives in assets/js/builder.js; all #aieb-* IDs and
 * data-attributes here are the JS↔DOM contract.
 *
 * @package AI_Elementor_Builder
 *
 * @var string $title Page title.
 */

use AI_Elementor_Builder\Settings\Settings;

defined( 'ABSPATH' ) || exit;

$aieb_settings_obj     = new Settings();
$aieb_opts             = $aieb_settings_obj->get_settings();
$aieb_providers_def    = $aieb_settings_obj->providers();
$aieb_default_provider = isset( $aieb_opts['default_provider'] ) ? (string) $aieb_opts['default_provider'] : 'anthropic';

if ( ! isset( $aieb_providers_def[ $aieb_default_provider ] ) ) {
	$aieb_default_provider = (string) key( $aieb_providers_def );
}

// Provider display meta for the picker grid: short mono badge + whether it is the
// keyless local option.
$aieb_provider_meta = array(
	'anthropic'  => array( 'label' => __( 'Claude', 'ai-elementor-builder' ), 'ico' => 'CL' ),
	'openai'     => array( 'label' => __( 'OpenAI', 'ai-elementor-builder' ), 'ico' => 'AI' ),
	'gemini'     => array( 'label' => __( 'Gemini', 'ai-elementor-builder' ), 'ico' => 'GE' ),
	'openrouter' => array( 'label' => __( 'OpenRouter', 'ai-elementor-builder' ), 'ico' => 'OR' ),
	'nvidia'     => array( 'label' => __( 'NVIDIA', 'ai-elementor-builder' ), 'ico' => 'NV' ),
	'ollama'     => array( 'label' => __( 'Ollama', 'ai-elementor-builder' ), 'ico' => 'OL', 'local' => true ),
);

/**
 * Resolve the default model label for a provider (for the model bar / picker).
 *
 * @param string $key                Provider key.
 * @param array  $opts               Stored settings.
 * @param array  $providers_def      Provider definitions.
 * @return string Human label.
 */
$aieb_model_label = static function ( $key, $opts, $providers_def ) {
	if ( 'ollama' === $key ) {
		return isset( $opts['ollama_model'] ) && '' !== $opts['ollama_model']
			? (string) $opts['ollama_model']
			: 'qwen2.5:7b';
	}
	if ( ! isset( $providers_def[ $key ]['models'] ) ) {
		return '';
	}
	$models  = $providers_def[ $key ]['models'];
	$mfield  = 'default_model_' . $key;
	$current = isset( $opts[ $mfield ] ) ? $opts[ $mfield ] : '';
	if ( isset( $models[ $current ] ) ) {
		return $models[ $current ];
	}
	return (string) reset( $models );
};

$aieb_builder_url  = menu_page_url( 'ai-elementor-builder', false );
$aieb_settings_url = menu_page_url( 'ai-elementor-builder-settings', false );
?>
<div class="aieb-builder aieb-app">

	<header class="aieb-appbar">
		<div class="aieb-brand">
			<span class="aieb-logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/></svg></span>
			<b><?php esc_html_e( 'Elementor Builder', 'ai-elementor-builder' ); ?></b><span class="aieb-tag"><?php esc_html_e( 'AI', 'ai-elementor-builder' ); ?></span>
		</div>
		<span class="aieb-spacer"></span>
		<nav class="aieb-nav">
			<a class="active" href="<?php echo esc_url( $aieb_builder_url ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16v6H4zM4 14h10v6H4zM18 14h2v6h-2z"/></svg><?php esc_html_e( 'Builder', 'ai-elementor-builder' ); ?></a>
			<a href="<?php echo esc_url( $aieb_settings_url ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 7 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0-1.1-2.7H1a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 7"/></svg><?php esc_html_e( 'Settings', 'ai-elementor-builder' ); ?></a>
		</nav>
	</header>

	<div class="aieb-studio">
		<!-- ============ LEFT RAIL ============ -->
		<aside class="aieb-rail">
			<div class="aieb-segtabs" role="tablist">
				<button class="active" type="button" data-tab="compose" role="tab" aria-selected="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg><?php esc_html_e( 'Compose', 'ai-elementor-builder' ); ?></button>
				<button type="button" data-tab="history" role="tab" aria-selected="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 3v5h5M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l3 2"/></svg><?php esc_html_e( 'Chats', 'ai-elementor-builder' ); ?> <span class="aieb-count" id="aieb-hist-count">0</span></button>
				<button type="button" data-tab="templates" role="tab" aria-selected="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg><?php esc_html_e( 'Saved', 'ai-elementor-builder' ); ?></button>
			</div>

			<div class="aieb-railbody scroll">
				<!-- COMPOSE (chat) -->
				<div class="aieb-tabpane active" data-pane="compose">
					<div class="aieb-modesw" id="aieb-modesw" role="tablist" aria-label="<?php esc_attr_e( 'Build mode', 'ai-elementor-builder' ); ?>">
						<button class="active" type="button" data-mode="single" role="tab" aria-selected="true"><?php esc_html_e( 'Single page', 'ai-elementor-builder' ); ?></button>
						<button type="button" data-mode="site" role="tab" aria-selected="false"><?php esc_html_e( 'Full website', 'ai-elementor-builder' ); ?></button>
					</div>
					<div class="aieb-chathead">
						<button type="button" class="btn xs" id="aieb-new-chat" title="<?php esc_attr_e( 'Start a new chat', 'ai-elementor-builder' ); ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
							<?php esc_html_e( 'New chat', 'ai-elementor-builder' ); ?>
						</button>
						<span class="aieb-spacer"></span>
						<button type="button" class="btn xs" id="aieb-opts-toggle" aria-expanded="false" title="<?php esc_attr_e( 'Provider & reference image', 'ai-elementor-builder' ); ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8M4.6 9a1.6 1.6 0 0 0-.3-1.8"/></svg>
							<?php esc_html_e( 'Options', 'ai-elementor-builder' ); ?>
							<span class="aieb-modelchip" id="aieb-model-name"><?php echo esc_html( $aieb_model_label( $aieb_default_provider, $aieb_opts, $aieb_providers_def ) ); ?></span>
						</button>
					</div>

					<!-- Collapsible options: provider + reference image (scope & style are asked in-chat) -->
					<div class="aieb-options aieb-hidden" id="aieb-options">
						<div class="aieb-group">
							<div class="aieb-lbl"><?php esc_html_e( 'Provider', 'ai-elementor-builder' ); ?></div>
							<div class="aieb-providers" id="aieb-prov-grid" role="radiogroup" aria-label="<?php esc_attr_e( 'AI provider', 'ai-elementor-builder' ); ?>">
								<?php
								foreach ( $aieb_provider_meta as $aieb_pkey => $aieb_pmeta ) :
									$aieb_is_active = ( $aieb_pkey === $aieb_default_provider );
									$aieb_pmodel    = $aieb_model_label( $aieb_pkey, $aieb_opts, $aieb_providers_def );
									?>
									<button
										type="button"
										class="aieb-prov<?php echo $aieb_is_active ? ' active' : ''; ?>"
										data-prov="<?php echo esc_attr( $aieb_pkey ); ?>"
										data-model="<?php echo esc_attr( $aieb_pmodel ); ?>"
										role="radio"
										aria-checked="<?php echo $aieb_is_active ? 'true' : 'false'; ?>"
									>
										<?php if ( ! empty( $aieb_pmeta['local'] ) ) : ?>
											<span class="aieb-prov-badge"><?php esc_html_e( 'LOCAL', 'ai-elementor-builder' ); ?></span>
										<?php endif; ?>
										<span class="ico"><?php echo esc_html( $aieb_pmeta['ico'] ); ?></span>
										<span><span class="nm"><?php echo esc_html( $aieb_pmeta['label'] ); ?></span><span class="st"><span class="dot on"></span><?php echo ! empty( $aieb_pmeta['local'] ) ? esc_html__( 'Offline-ready', 'ai-elementor-builder' ) : esc_html__( 'Ready', 'ai-elementor-builder' ); ?></span></span>
									</button>
								<?php endforeach; ?>
							</div>
						</div>

					</div>

					<!-- Conversation thread -->
					<div class="aieb-thread" id="aieb-thread">
						<div class="aieb-thread-empty" id="aieb-thread-empty">
							<span class="glyph"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/></svg></span>
							<p><?php esc_html_e( 'Tell me what you want to build. We’ll talk it through, shape a plan together, then generate the design when you’re ready.', 'ai-elementor-builder' ); ?></p>
							<div class="aieb-chips">
								<button class="aieb-chip" type="button" data-seed="<?php esc_attr_e( 'I want a website for my business', 'ai-elementor-builder' ); ?>"><?php esc_html_e( 'Business website', 'ai-elementor-builder' ); ?></button>
								<button class="aieb-chip" type="button" data-seed="<?php esc_attr_e( 'A landing page to capture leads', 'ai-elementor-builder' ); ?>"><?php esc_html_e( 'Landing page', 'ai-elementor-builder' ); ?></button>
								<button class="aieb-chip" type="button" data-seed="<?php esc_attr_e( 'A pricing section with three tiers', 'ai-elementor-builder' ); ?>"><?php esc_html_e( 'Pricing section', 'ai-elementor-builder' ); ?></button>
							</div>
						</div>
					</div>

					<!-- Design plan (brief the AI builds; finalize → generate) -->
					<div class="aieb-plan aieb-hidden" id="aieb-plan">
						<div class="aieb-plan-head">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
							<span><?php esc_html_e( 'Design plan', 'ai-elementor-builder' ); ?></span>
						</div>
						<textarea class="input" id="aieb-brief" rows="6" placeholder="<?php esc_attr_e( 'The plan will appear here as we chat. You can edit it before generating.', 'ai-elementor-builder' ); ?>"></textarea>
						<button class="btn primary block" type="button" id="aieb-generate-design" disabled>
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/></svg>
							<?php esc_html_e( 'Generate design', 'ai-elementor-builder' ); ?>
						</button>
					</div>

					<!-- FULL WEBSITE: plan a multi-page site, then build each page + nav menu -->
					<div class="aieb-site" id="aieb-site">
						<div class="aieb-site-intro" id="aieb-site-intro">
							<p class="aieb-site-hint"><?php esc_html_e( 'Describe the whole website. I’ll plan the pages, then build each one and wire up a navigation menu.', 'ai-elementor-builder' ); ?></p>
							<textarea class="input" id="aieb-site-prompt" rows="3" placeholder="<?php esc_attr_e( 'e.g. A website for a yoga studio: home, about, classes, pricing, contact', 'ai-elementor-builder' ); ?>"></textarea>
							<button class="btn primary block" type="button" id="aieb-plan-site">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z"/></svg>
								<?php esc_html_e( 'Plan site', 'ai-elementor-builder' ); ?>
							</button>
						</div>

						<div class="aieb-site-plan aieb-hidden" id="aieb-site-plan">
							<label class="aieb-lbl" for="aieb-site-title"><?php esc_html_e( 'Site title', 'ai-elementor-builder' ); ?></label>
							<input class="input" id="aieb-site-title" />
							<div class="aieb-lbl aieb-pages-lbl"><?php esc_html_e( 'Pages', 'ai-elementor-builder' ); ?></div>
							<div class="aieb-pagelist" id="aieb-page-list"></div>
							<button class="btn xs block" type="button" id="aieb-add-page">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
								<?php esc_html_e( 'Add page', 'ai-elementor-builder' ); ?>
							</button>
							<label class="aieb-check"><input type="checkbox" id="aieb-set-home" checked /> <?php esc_html_e( 'Set the Home page as the site’s front page', 'ai-elementor-builder' ); ?></label>
							<button class="btn primary block" type="button" id="aieb-build-site">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
								<?php esc_html_e( 'Build site', 'ai-elementor-builder' ); ?>
							</button>
							<div class="aieb-site-results aieb-hidden" id="aieb-site-results"></div>
						</div>
					</div>
				</div>

				<!-- HISTORY -->
				<div class="aieb-tabpane" data-pane="history">
					<div class="aieb-searchwrap">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
						<input class="input" id="aieb-hist-search" placeholder="<?php esc_attr_e( 'Search chats…', 'ai-elementor-builder' ); ?>" />
					</div>
					<ul id="aieb-history-list" class="aieb-history-list"></ul>
				</div>

				<!-- SAVED TEMPLATES -->
				<div class="aieb-tabpane" data-pane="templates">
					<ul id="aieb-template-history-list" class="aieb-history-list"></ul>
				</div>
			</div>

			<div class="aieb-railfoot">
				<!-- Attached reference image preview chip -->
				<div id="aieb-image-preview" class="aieb-image-chip aieb-hidden">
					<img id="aieb-image-thumb" src="" alt="<?php esc_attr_e( 'Selected reference image preview', 'ai-elementor-builder' ); ?>" />
					<span class="aieb-image-chip-name" id="aieb-ref-name"><?php esc_html_e( 'Reference image', 'ai-elementor-builder' ); ?></span>
					<button type="button" id="aieb-image-remove" class="aieb-image-remove" aria-label="<?php esc_attr_e( 'Remove image', 'ai-elementor-builder' ); ?>">&times;</button>
				</div>

				<div class="aieb-composer2">
					<!-- Attach menu -->
					<div class="aieb-attach-wrap">
						<button class="btn aieb-attach" type="button" id="aieb-attach" aria-haspopup="menu" aria-expanded="false" title="<?php esc_attr_e( 'Attach', 'ai-elementor-builder' ); ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
						</button>
						<div class="aieb-attach-menu aieb-hidden" id="aieb-attach-menu" role="menu">
							<button class="aieb-attach-item" type="button" id="aieb-attach-image" role="menuitem">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
								<?php esc_html_e( 'Upload reference image', 'ai-elementor-builder' ); ?>
							</button>
						</div>
					</div>

					<label class="screen-reader-text" for="aieb-prompt"><?php esc_html_e( 'Message', 'ai-elementor-builder' ); ?></label>
					<textarea class="input" id="aieb-prompt" rows="2" placeholder="<?php esc_attr_e( 'Describe a page, or ask for a change…', 'ai-elementor-builder' ); ?>"></textarea>
					<button class="btn primary aieb-send" type="button" id="aieb-generate" title="<?php esc_attr_e( 'Send (Enter)', 'ai-elementor-builder' ); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/></svg>
					</button>
				</div>
				<input type="file" id="aieb-image" accept="image/png,image/jpeg,image/webp,image/gif" hidden />
				<div class="aieb-composer-meta"><span class="hint"><?php esc_html_e( 'Enter to send · Shift+Enter for newline', 'ai-elementor-builder' ); ?></span><span class="aieb-cc"><span id="aieb-cc">0</span> <?php esc_html_e( 'chars', 'ai-elementor-builder' ); ?></span></div>
			</div>
		</aside>

		<!-- ============ CENTER STAGE ============ -->
		<main class="aieb-stage">
			<div class="aieb-stagebar">
				<label class="screen-reader-text" for="aieb-page-select"><?php esc_html_e( 'Target page', 'ai-elementor-builder' ); ?></label>
				<select class="input" id="aieb-page-select">
					<option value=""><?php esc_html_e( 'Loading pages…', 'ai-elementor-builder' ); ?></option>
				</select>
				<span class="aieb-spacer"></span>
				<div class="aieb-devtoggle" id="aieb-dev-toggle" role="group" aria-label="<?php esc_attr_e( 'Preview width', 'ai-elementor-builder' ); ?>">
					<button class="active" type="button" data-dev="desktop" title="<?php esc_attr_e( 'Desktop', 'ai-elementor-builder' ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg></button>
					<button type="button" data-dev="tablet" title="<?php esc_attr_e( 'Tablet', 'ai-elementor-builder' ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg></button>
					<button type="button" data-dev="mobile" title="<?php esc_attr_e( 'Mobile', 'ai-elementor-builder' ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg></button>
				</div>
				<button class="btn sm" type="button" id="aieb-toggle-json"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M8 3H6a2 2 0 0 0-2 2v4a2 2 0 0 1-2 2 2 2 0 0 1 2 2v4a2 2 0 0 0 2 2h2M16 3h2a2 2 0 0 1 2 2v4a2 2 0 0 0 2 2 2 2 0 0 0-2 2v4a2 2 0 0 1-2 2h-2"/></svg><?php esc_html_e( 'View JSON', 'ai-elementor-builder' ); ?></button>
				<button class="btn sm" type="button" id="aieb-fullscreen" title="<?php esc_attr_e( 'Preview full screen (F)', 'ai-elementor-builder' ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M3 16v3a2 2 0 0 0 2 2h3"/></svg><?php esc_html_e( 'Full screen', 'ai-elementor-builder' ); ?></button>
			</div>

			<div class="aieb-canvaswrap scroll">
				<div class="aieb-canvas" id="aieb-canvas" data-dev="desktop">
					<!-- empty -->
					<div class="aieb-empty" id="aieb-empty">
						<span class="glyph"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M5 19l1 .4.4 1 .4-1 1-.4-1-.4L6 17l-.4 1z"/></svg></span>
						<h2><?php esc_html_e( 'Your generated layout previews here', 'ai-elementor-builder' ); ?></h2>
						<p><?php esc_html_e( 'Describe a section on the left, pick a provider, and hit Generate. We render a live preview you can push straight into Elementor.', 'ai-elementor-builder' ); ?></p>
						<div class="aieb-ec">
							<button class="aieb-chip" type="button" data-seed="<?php esc_attr_e( 'A pricing section with 3 tiers and a highlighted Pro plan', 'ai-elementor-builder' ); ?>"><?php esc_html_e( 'Pricing section', 'ai-elementor-builder' ); ?></button>
							<button class="aieb-chip" type="button" data-seed="<?php esc_attr_e( 'A hero for a Himalayan trekking company with two CTAs', 'ai-elementor-builder' ); ?>"><?php esc_html_e( 'Trekking hero', 'ai-elementor-builder' ); ?></button>
							<button class="aieb-chip" type="button" data-seed="<?php esc_attr_e( 'A testimonials wall with 4 customer quotes and ratings', 'ai-elementor-builder' ); ?>"><?php esc_html_e( 'Testimonials', 'ai-elementor-builder' ); ?></button>
						</div>
					</div>

					<!-- loading -->
					<div class="aieb-loading" id="aieb-loading">
						<div class="aieb-lstatus"><svg class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M21 12a9 9 0 1 1-6.2-8.6"/></svg><span id="aieb-load-msg"><?php esc_html_e( 'Drafting layout…', 'ai-elementor-builder' ); ?></span></div>
						<div class="aieb-sk skeleton" style="width:40%;height:11px;"></div>
						<div class="aieb-sk skeleton" style="width:72%;height:26px;"></div>
						<div class="aieb-sk skeleton" style="width:55%;"></div>
						<div class="aieb-sk skeleton" style="width:60%;"></div>
						<div class="aieb-sk-grid">
							<div class="aieb-sk skeleton" style="height:120px;margin:0;"></div>
							<div class="aieb-sk skeleton" style="height:120px;margin:0;"></div>
							<div class="aieb-sk skeleton" style="height:120px;margin:0;"></div>
						</div>
					</div>

					<!-- generated preview -->
					<iframe id="aieb-preview-frame" class="aieb-preview-frame aieb-hidden" title="<?php esc_attr_e( 'Template preview', 'ai-elementor-builder' ); ?>"></iframe>
				</div>
			</div>

			<div class="aieb-jsonpane scroll aieb-hidden" id="aieb-json-pane"><pre id="aieb-json" tabindex="0"></pre></div>

			<div class="aieb-actionbar">
				<span class="aieb-template-opt">
					<label class="screen-reader-text" for="aieb-template-type"><?php esc_html_e( 'Template type', 'ai-elementor-builder' ); ?></label>
					<select class="input sm" id="aieb-template-type">
						<option value="page" selected><?php esc_html_e( 'Page', 'ai-elementor-builder' ); ?></option>
						<option value="section"><?php esc_html_e( 'Section', 'ai-elementor-builder' ); ?></option>
						<option value="header"><?php esc_html_e( 'Header', 'ai-elementor-builder' ); ?></option>
						<option value="footer"><?php esc_html_e( 'Footer', 'ai-elementor-builder' ); ?></option>
						<option value="popup"><?php esc_html_e( 'Popup', 'ai-elementor-builder' ); ?></option>
					</select>
				</span>
				<div class="aieb-ti"><input class="input" id="aieb-template-title" placeholder="<?php esc_attr_e( 'Template title — e.g. Himalaya Hero', 'ai-elementor-builder' ); ?>" /></div>
				<span class="aieb-grow"></span>
				<button class="btn" type="button" id="aieb-download" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg><?php esc_html_e( 'Download JSON', 'ai-elementor-builder' ); ?></button>
				<button class="btn" type="button" id="aieb-push-template" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg><?php esc_html_e( 'Save as Template', 'ai-elementor-builder' ); ?></button>
				<button class="btn" type="button" id="aieb-save-pattern" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/></svg><?php esc_html_e( 'Save as Pattern', 'ai-elementor-builder' ); ?></button>
					<button class="btn" type="button" id="aieb-push-gutenberg" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 3h18v18H3zM3 9h18M9 21V9"/></svg><?php esc_html_e( 'Push to Gutenberg', 'ai-elementor-builder' ); ?></button>
					<button class="btn primary" type="button" id="aieb-push" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg><?php esc_html_e( 'Push to Elementor', 'ai-elementor-builder' ); ?></button>
			</div>
		</main>
	</div>

	<div class="toast" id="aieb-toast" role="status" aria-live="polite"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M20 6L9 17l-5-5"/></svg><span id="aieb-toast-msg"></span></div>

	<!-- ============ FULLSCREEN PREVIEW ============ -->
	<div class="aieb-fsview" id="aieb-fsview" aria-hidden="true">
		<div class="aieb-fsbar">
			<span class="aieb-fstitle"><span class="dot on"></span><span id="aieb-fs-name"><?php esc_html_e( 'Live preview', 'ai-elementor-builder' ); ?></span></span>
			<span class="aieb-fsspacer"></span>
			<div class="aieb-devtoggle" id="aieb-fs-dev" role="group" aria-label="<?php esc_attr_e( 'Preview width', 'ai-elementor-builder' ); ?>">
				<button class="active" type="button" data-dev="desktop" title="<?php esc_attr_e( 'Desktop', 'ai-elementor-builder' ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg></button>
				<button type="button" data-dev="tablet" title="<?php esc_attr_e( 'Tablet', 'ai-elementor-builder' ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg></button>
				<button type="button" data-dev="mobile" title="<?php esc_attr_e( 'Mobile', 'ai-elementor-builder' ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg></button>
			</div>
			<button class="btn sm" type="button" id="aieb-fs-exit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M8 3v3a2 2 0 0 1-2 2H3M16 3v3a2 2 0 0 0 2 2h3M21 16h-3a2 2 0 0 0-2 2v3M3 16h3a2 2 0 0 1 2 2v3"/></svg><?php esc_html_e( 'Exit', 'ai-elementor-builder' ); ?> <span class="aieb-kbd"><?php esc_html_e( 'Esc', 'ai-elementor-builder' ); ?></span></button>
		</div>
		<div class="aieb-fsscroll scroll"><iframe class="aieb-fsframe" id="aieb-fsframe" data-dev="desktop" title="<?php esc_attr_e( 'Fullscreen preview', 'ai-elementor-builder' ); ?>"></iframe></div>
	</div>
</div>
