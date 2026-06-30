/**
 * AI Elementor Builder — Builder page behavior (vanilla JS, no jQuery).
 *
 * "Studio" layout: left compose rail (Compose / History / Saved tabs, provider
 * grid) + center preview stage with a JSON drawer and a bottom action bar.
 *
 * Reads config from window.AIEB injected via wp_localize_script:
 *   { restUrl, pushUrl, pushTemplateUrl, nonce, history, references, i18n }
 */
( function () {
	'use strict';

	var cfg = window.AIEB || {};
	var i18n = cfg.i18n || {};

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var root = document.querySelector( '.aieb-builder' );
		if ( ! root ) {
			return;
		}

		var els = {
			prompt: root.querySelector( '#aieb-prompt' ),
			cc: root.querySelector( '#aieb-cc' ),
			thread: root.querySelector( '#aieb-thread' ),
			threadEmpty: root.querySelector( '#aieb-thread-empty' ),
			newChat: root.querySelector( '#aieb-new-chat' ),
			optsToggle: root.querySelector( '#aieb-opts-toggle' ),
			options: root.querySelector( '#aieb-options' ),
			plan: root.querySelector( '#aieb-plan' ),
			brief: root.querySelector( '#aieb-brief' ),
			generateDesign: root.querySelector( '#aieb-generate-design' ),
			provGrid: root.querySelector( '#aieb-prov-grid' ),
			modelName: root.querySelector( '#aieb-model-name' ),
			image: root.querySelector( '#aieb-image' ),
			attach: root.querySelector( '#aieb-attach' ),
			attachMenu: root.querySelector( '#aieb-attach-menu' ),
			attachImage: root.querySelector( '#aieb-attach-image' ),
			refName: root.querySelector( '#aieb-ref-name' ),
			imagePreview: root.querySelector( '#aieb-image-preview' ),
			imageThumb: root.querySelector( '#aieb-image-thumb' ),
			imageRemove: root.querySelector( '#aieb-image-remove' ),
			generate: root.querySelector( '#aieb-generate' ),
			frame: root.querySelector( '#aieb-preview-frame' ),
			empty: root.querySelector( '#aieb-empty' ),
			loading: root.querySelector( '#aieb-loading' ),
			loadMsg: root.querySelector( '#aieb-load-msg' ),
			json: root.querySelector( '#aieb-json' ),
			jsonPane: root.querySelector( '#aieb-json-pane' ),
			toggle: root.querySelector( '#aieb-toggle-json' ),
			canvas: root.querySelector( '#aieb-canvas' ),
			devToggle: root.querySelector( '#aieb-dev-toggle' ),
			segtabs: root.querySelectorAll( '.aieb-segtabs button' ),
			panes: root.querySelectorAll( '.aieb-tabpane' ),
			histCount: root.querySelector( '#aieb-hist-count' ),
			histSearch: root.querySelector( '#aieb-hist-search' ),
			historyList: root.querySelector( '#aieb-history-list' ),
			templateHistoryList: root.querySelector( '#aieb-template-history-list' ),
			pageSelect: root.querySelector( '#aieb-page-select' ),
			push: root.querySelector( '#aieb-push' ),
			pushGutenberg: root.querySelector( '#aieb-push-gutenberg' ),
			savePattern: root.querySelector( '#aieb-save-pattern' ),
			download: root.querySelector( '#aieb-download' ),
			templateType: root.querySelector( '#aieb-template-type' ),
			templateTitle: root.querySelector( '#aieb-template-title' ),
			pushTemplate: root.querySelector( '#aieb-push-template' ),
			toast: root.querySelector( '#aieb-toast' ),
			toastMsg: root.querySelector( '#aieb-toast-msg' ),
			fullscreen: root.querySelector( '#aieb-fullscreen' ),
			fsview: root.querySelector( '#aieb-fsview' ),
			fsframe: root.querySelector( '#aieb-fsframe' ),
			fsExit: root.querySelector( '#aieb-fs-exit' ),
			fsDev: root.querySelector( '#aieb-fs-dev' )
		};

		// state.image: { data: base64-without-prefix, mime: string } or null.
		// state.busy: a request is in flight. state.awaitingAnswers: a clarify
		// question card is open and we should not refine on the next send.
		// scope: build framing ('fullpage' | section name | '' = let the model decide),
		// set by the clarify step. reference: chosen few-shot exemplar id ('' = none),
		// picked via a clarify question rather than a manual dropdown.
		var state = {
			template: null,
			showingJson: false,
			image: null,
			provider: '',
			scope: '',
			reference: '',
			fullscreen: false,
			busy: false,
			awaitingAnswers: false,
			// Conversational planning + session persistence.
			sessionId: null,
			messages: [],
			brief: '',
			creating: null
		};

		// Loaded session list (for the Chats tab) + debounce handle.
		var sessions = [];
		var saveTimer = null;
		// Curated design references (id/name/description), offered as a "match style"
		// option inside the clarify question card.
		var refList = Array.isArray( cfg.references ) ? cfg.references : [];

		var TEMPLATE_HISTORY_KEY = 'aeb_template_history';
		var templateHistory = loadTemplateHistory();

		// Cap reference images at 5 MB to keep request payloads sane.
		var MAX_IMAGE_BYTES = 5 * 1024 * 1024;

		// Rotating status lines shown while a generation request is in flight.
		var STEPS = [
			'Reading your prompt…',
			'Drafting layout…',
			'Composing sections…',
			'Styling with theme tokens…',
			'Building Elementor schema…'
		];
		var loadTimer = null;

		// Seed provider from the server-rendered active button.
		var activeProv = els.provGrid && els.provGrid.querySelector( '.aieb-prov.active' );
		state.provider = activeProv ? activeProv.getAttribute( 'data-prov' ) : '';

		bindEvents();
		loadPages();
		loadSessions();
		renderTemplateHistory();
		updateCharCount();
		initSiteBuilder();

		function bindEvents() {
			els.generate.addEventListener( 'click', onSend );
			els.toggle.addEventListener( 'click', onToggleJson );
			els.prompt.addEventListener( 'input', updateCharCount );

			// Enter sends; Shift+Enter inserts a newline.
			els.prompt.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key && ! e.shiftKey ) {
					e.preventDefault();
					onSend();
				}
			} );

			if ( els.newChat ) {
				els.newChat.addEventListener( 'click', resetChat );
			}
			if ( els.optsToggle && els.options ) {
				els.optsToggle.addEventListener( 'click', function () {
					var open = els.options.classList.toggle( 'aieb-hidden' );
					els.optsToggle.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
				} );
			}

			// Provider grid (radio-style single select).
			if ( els.provGrid ) {
				Array.prototype.forEach.call( els.provGrid.querySelectorAll( '.aieb-prov' ), function ( btn ) {
					btn.addEventListener( 'click', function () {
						selectProvider( btn );
					} );
				} );
			}

			// Segmented tabs.
			Array.prototype.forEach.call( els.segtabs, function ( btn ) {
				btn.addEventListener( 'click', function () {
					switchTab( btn.getAttribute( 'data-tab' ) );
				} );
			} );

			// Device width toggle.
			if ( els.devToggle ) {
				Array.prototype.forEach.call( els.devToggle.querySelectorAll( 'button' ), function ( btn ) {
					btn.addEventListener( 'click', function () {
						Array.prototype.forEach.call( els.devToggle.querySelectorAll( 'button' ), function ( x ) {
							x.classList.remove( 'active' );
						} );
						btn.classList.add( 'active' );
						els.canvas.setAttribute( 'data-dev', btn.getAttribute( 'data-dev' ) );
					} );
				} );
			}

			// Seed chips (center empty-state + thread empty-state): drop the text in
			// the composer, switch to Compose, and send it.
			var seedChips = [];
			if ( els.empty ) {
				seedChips = seedChips.concat( Array.prototype.slice.call( els.empty.querySelectorAll( '[data-seed]' ) ) );
			}
			if ( els.threadEmpty ) {
				seedChips = seedChips.concat( Array.prototype.slice.call( els.threadEmpty.querySelectorAll( '[data-seed]' ) ) );
			}
			seedChips.forEach( function ( chip ) {
				chip.addEventListener( 'click', function () {
					els.prompt.value = chip.getAttribute( 'data-seed' );
					updateCharCount();
					switchTab( 'compose' );
					onSend();
				} );
			} );

			if ( els.image ) {
				els.image.addEventListener( 'change', onImageChange );
			}
			if ( els.imageRemove ) {
				els.imageRemove.addEventListener( 'click', clearImage );
			}

			// Attach menu in the composer: toggle dropdown, pick file, close on outside click.
			if ( els.attach && els.attachMenu ) {
				els.attach.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					var open = els.attachMenu.classList.toggle( 'aieb-hidden' );
					els.attach.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
				} );
				document.addEventListener( 'click', function ( e ) {
					if ( ! els.attachMenu.classList.contains( 'aieb-hidden' ) &&
						! els.attachMenu.contains( e.target ) && e.target !== els.attach ) {
						els.attachMenu.classList.add( 'aieb-hidden' );
						els.attach.setAttribute( 'aria-expanded', 'false' );
					}
				} );
			}
			if ( els.attachImage && els.image ) {
				els.attachImage.addEventListener( 'click', function () {
					els.attachMenu.classList.add( 'aieb-hidden' );
					els.attach.setAttribute( 'aria-expanded', 'false' );
					els.image.click();
				} );
			}
			if ( els.histSearch ) {
				els.histSearch.addEventListener( 'input', function () {
					renderSessions();
				} );
			}

			// Plan panel: finalize → generate; edits to the brief are persisted.
			if ( els.generateDesign ) {
				els.generateDesign.addEventListener( 'click', onGenerateDesign );
			}
			if ( els.brief ) {
				els.brief.addEventListener( 'input', function () {
					state.brief = els.brief.value;
					persistSession();
				} );
			}

			els.push.addEventListener( 'click', onPush );
			if ( els.pushGutenberg ) {
				els.pushGutenberg.addEventListener( 'click', onPushGutenberg );
			}
			if ( els.savePattern ) {
				els.savePattern.addEventListener( 'click', onSavePattern );
			}
			els.pageSelect.addEventListener( 'change', refreshPushState );
			if ( els.download ) {
				els.download.addEventListener( 'click', onDownload );
			}
			if ( els.pushTemplate ) {
				els.pushTemplate.addEventListener( 'click', onPushTemplate );
			}

			// Fullscreen toggle.
			if ( els.fullscreen ) {
				els.fullscreen.addEventListener( 'click', openFullscreen );
			}
			if ( els.fsExit ) {
				els.fsExit.addEventListener( 'click', closeFullscreen );
			}
			if ( els.fsDev ) {
				Array.prototype.forEach.call( els.fsDev.querySelectorAll( 'button' ), function ( btn ) {
					btn.addEventListener( 'click', function () {
						Array.prototype.forEach.call( els.fsDev.querySelectorAll( 'button' ), function ( x ) {
							x.classList.remove( 'active' );
						} );
						btn.classList.add( 'active' );
						if ( els.fsframe ) {
							els.fsframe.setAttribute( 'data-dev', btn.getAttribute( 'data-dev' ) );
						}
					} );
				} );
			}
			// Keyboard shortcut (F for fullscreen, Esc to exit).
			document.addEventListener( 'keydown', function ( e ) {
				if ( ! state.fullscreen && 'F' === e.key && ! e.ctrlKey && ! e.metaKey && ! e.altKey ) {
					var tag = ( e.target.tagName || '' ).toUpperCase();
					if ( 'INPUT' !== tag && 'TEXTAREA' !== tag ) {
						e.preventDefault();
						openFullscreen();
					}
				}
				if ( state.fullscreen && 'Escape' === e.key ) {
					e.preventDefault();
					closeFullscreen();
				}
			} );
		}

		/* ---- Tabs ---- */

		function switchTab( name ) {
			Array.prototype.forEach.call( els.segtabs, function ( b ) {
				var on = b.getAttribute( 'data-tab' ) === name;
				b.classList.toggle( 'active', on );
				b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			Array.prototype.forEach.call( els.panes, function ( p ) {
				p.classList.toggle( 'active', p.getAttribute( 'data-pane' ) === name );
			} );
		}

		/* ---- Provider grid ---- */

		function selectProvider( btn ) {
			Array.prototype.forEach.call( els.provGrid.querySelectorAll( '.aieb-prov' ), function ( x ) {
				x.classList.remove( 'active' );
				x.setAttribute( 'aria-checked', 'false' );
			} );
			btn.classList.add( 'active' );
			btn.setAttribute( 'aria-checked', 'true' );
			state.provider = btn.getAttribute( 'data-prov' );
			if ( els.modelName ) {
				els.modelName.textContent = btn.getAttribute( 'data-model' ) || '';
			}
		}

		function selectProviderByKey( key ) {
			if ( ! els.provGrid || ! key ) {
				return;
			}
			var btn = els.provGrid.querySelector( '.aieb-prov[data-prov="' + key + '"]' );
			if ( btn ) {
				selectProvider( btn );
			}
		}

		function updateCharCount() {
			if ( els.cc ) {
				els.cc.textContent = ( els.prompt.value || '' ).length;
			}
		}

		function selectedProvider() {
			return state.provider || '';
		}

		// Wrap a brief in scope framing based on state.scope (set by the clarify
		// step), so the model knows whether to build a full page or a single section.
		function wrapScope( text ) {
			var scope = state.scope || '';
			text = ( text || '' ).trim();
			if ( 'fullpage' === scope ) {
				return 'Generate a COMPLETE, multi-section Elementor landing page (not a single section). ' +
					'Include several stacked top-level section containers — typically a hero, then features, ' +
					'about, testimonials, pricing or call-to-action, and a footer-style closing section — each ' +
					'as its own top-level container with appropriate content and styling. ' + text;
			}
			if ( 'custom' === scope || ! scope ) {
				return text;
			}
			return 'Generate an Elementor "' + scope + '" section. ' + text;
		}

		/* ---- Reference image (base64 for vision providers) ---- */

		function onImageChange() {
			var file = els.image.files && els.image.files[ 0 ];
			if ( ! file ) {
				clearImage();
				return;
			}
			if ( file.size > MAX_IMAGE_BYTES ) {
				toast( t( 'imageTooLarge', 'Image is too large (max 5 MB).' ), false );
				clearImage();
				return;
			}

			var reader = new FileReader();
			reader.onload = function () {
				var result = String( reader.result || '' );
				// Data URL shape: "data:<mime>;base64,<data>".
				var comma = result.indexOf( ',' );
				var meta = result.slice( 5, result.indexOf( ';' ) );
				state.image = {
					mime: meta || file.type || 'image/png',
					data: comma >= 0 ? result.slice( comma + 1 ) : ''
				};
				els.imageThumb.src = result;
				els.imagePreview.classList.remove( 'aieb-hidden' );
				if ( els.refName ) {
					els.refName.textContent = file.name;
				}
				if ( els.attach ) {
					els.attach.classList.add( 'active' );
				}
			};
			reader.onerror = function () {
				toast( t( 'imageReadError', 'Could not read the selected image.' ), false );
				clearImage();
			};
			reader.readAsDataURL( file );
		}

		function clearImage() {
			state.image = null;
			if ( els.image ) {
				els.image.value = '';
			}
			if ( els.imageThumb ) {
				els.imageThumb.src = '';
			}
			if ( els.imagePreview ) {
				els.imagePreview.classList.add( 'aieb-hidden' );
			}
			if ( els.refName ) {
				els.refName.textContent = t( 'dropImage', 'Reference image' );
			}
			if ( els.attach ) {
				els.attach.classList.remove( 'active' );
			}
		}

		/* ---- Chat orchestration ----
		 * One thread drives three calls:
		 *   /clarify  — vague first prompt → questions (with option chips)
		 *   /generate — build a template from a (possibly enriched) brief
		 *   /refine   — modify the current template from a follow-up instruction
		 */

		function setBusy( busy ) {
			state.busy = busy;
			if ( els.generate ) {
				els.generate.disabled = busy;
			}
			if ( els.prompt ) {
				els.prompt.disabled = busy;
			}
		}

		// JSON POST helper → resolves { ok, data }.
		function apiPost( url, payload ) {
			return fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce
				},
				body: JSON.stringify( payload )
			} ).then( function ( res ) {
				return res.json().then( function ( data ) {
					return { ok: res.ok, data: data };
				} );
			} );
		}

		// JSON GET helper → resolves { ok, data }.
		function apiGet( url ) {
			return fetch( url, {
				method: 'GET',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': cfg.nonce }
			} ).then( function ( res ) {
				return res.json().then( function ( data ) {
					return { ok: res.ok, data: data };
				} );
			} );
		}

		function onSend() {
			if ( state.busy ) {
				return;
			}
			var text = ( els.prompt.value || '' ).trim();
			if ( ! text ) {
				toast( t( 'emptyPrompt', 'Please enter a design prompt.' ), false );
				switchTab( 'compose' );
				els.prompt.focus();
				return;
			}

			hideThreadEmpty();
			addUserMessage( text );
			els.prompt.value = '';
			updateCharCount();

			// A template exists → treat the message as an edit instruction (refine).
			if ( state.template ) {
				runRefine( text );
				return;
			}

			// Planning phase → discuss with the consultant.
			runChat();
		}

		// Conversational planning: send the running conversation, get a reply + an
		// updated brief back. No template is generated here.
		function runChat() {
			setBusy( true );
			ensureSession();
			var typing = addTyping( t( 'thinking', 'Thinking through your request…' ) );
			apiPost( cfg.chatUrl, { provider: selectedProvider(), messages: state.messages } )
				.then( function ( r ) {
					removeNode( typing );
					if ( ! r.ok ) {
						addAIError( ( r.data && r.data.message ) ? r.data.message : t( 'chatError', 'Could not reach the assistant.' ) );
						return;
					}
					var d = r.data || {};
					if ( d.brief ) {
						setBrief( d.brief );
					}
					addAIMessage( d.reply || '…' );
					if ( d.ready ) {
						markPlanReady();
					}
					persistSession();
				} )
				.catch( function () {
					removeNode( typing );
					addAIError( t( 'chatError', 'Could not reach the assistant.' ) );
				} )
				.finally( function () {
					setBusy( false );
				} );
		}

		// Finalize the plan → build the template from the brief.
		function onGenerateDesign() {
			if ( state.busy ) {
				return;
			}
			var brief = ( els.brief && els.brief.value || state.brief || '' ).trim();
			if ( ! brief ) {
				brief = lastUserText();
			}
			if ( ! brief ) {
				toast( t( 'noPlan', 'Chat a little first so I can draft a plan.' ), false );
				switchTab( 'compose' );
				els.prompt.focus();
				return;
			}
			state.brief = brief;
			hideThreadEmpty();
			runGenerate( wrapScope( brief ), t( 'generateDesign', 'Generate design' ) );
		}

		// Most recent user message text (fallback when no brief yet).
		function lastUserText() {
			for ( var i = state.messages.length - 1; i >= 0; i-- ) {
				if ( 'user' === state.messages[ i ].role ) {
					return state.messages[ i ].content;
				}
			}
			return '';
		}

		function runGenerate( promptText, displayText ) {
			setBusy( true );
			setLoading( true );
			var typing = addTyping( t( 'thinking', 'Thinking through your request…' ) );

			var payload = { provider: selectedProvider(), prompt: promptText };
			if ( state.scope ) {
				payload.scope = state.scope;
			}
			if ( state.reference ) {
				payload.reference = state.reference;
			}
			if ( state.image && state.image.data ) {
				payload.image = state.image.data;
				payload.image_mime = state.image.mime;
			}

			apiPost( cfg.restUrl, payload )
				.then( function ( result ) {
					removeNode( typing );
					if ( ! result.ok ) {
						var msg = ( result.data && result.data.message )
							? result.data.message
							: t( 'genericError', 'Generation failed. Please try again.' );
						addAIError( msg );
						toast( msg, false );
						showState();
						return;
					}
					renderTemplate( result.data.template );
					addAIMessage( t( 'builtReply', 'Done — your layout is in the preview. Tell me what to change, or push it to Elementor.' ) );
					toast( t( 'generated', 'Layout generated.' ), true );
					if ( els.templateTitle && ! els.templateTitle.value ) {
						els.templateTitle.value = 'AI Generated — ' + new Date().toLocaleDateString();
					}
					persistSession();
				} )
				.catch( function () {
					removeNode( typing );
					addAIError( t( 'networkError', 'Network error. Could not reach the server.' ) );
					toast( t( 'networkError', 'Network error. Could not reach the server.' ), false );
					showState();
				} )
				.finally( function () {
					setLoading( false );
					setBusy( false );
				} );
		}

		function runRefine( instruction ) {
			setBusy( true );
			setLoading( true );
			var typing = addTyping( t( 'refining', 'Updating your layout…' ) );

			apiPost( cfg.refineUrl, {
				provider: selectedProvider(),
				instruction: instruction,
				template: state.template
			} )
				.then( function ( result ) {
					removeNode( typing );
					if ( ! result.ok ) {
						var msg = ( result.data && result.data.message )
							? result.data.message
							: t( 'genericError', 'Generation failed. Please try again.' );
						addAIError( msg );
						toast( msg, false );
						showState();
						return;
					}
					renderTemplate( result.data.template );
					addAIMessage( t( 'refinedReply', 'Updated the preview. Anything else to adjust?' ) );
					toast( t( 'refined', 'Layout updated.' ), true );
					persistSession();
				} )
				.catch( function () {
					removeNode( typing );
					addAIError( t( 'networkError', 'Network error. Could not reach the server.' ) );
					toast( t( 'networkError', 'Network error. Could not reach the server.' ), false );
					showState();
				} )
				.finally( function () {
					setLoading( false );
					setBusy( false );
				} );
		}

		/* ---- Thread rendering ---- */

		function hideThreadEmpty() {
			if ( els.threadEmpty ) {
				els.threadEmpty.style.display = 'none';
			}
		}

		function scrollThread() {
			if ( els.thread ) {
				els.thread.scrollTop = els.thread.scrollHeight;
			}
		}

		function addMessage( role, build ) {
			if ( ! els.thread ) {
				return null;
			}
			var wrap = document.createElement( 'div' );
			wrap.className = 'aieb-msg ' + role;
			var bubble = document.createElement( 'div' );
			bubble.className = 'aieb-bubble';
			build( bubble );
			wrap.appendChild( bubble );
			els.thread.appendChild( wrap );
			scrollThread();
			return wrap;
		}

		// Render a bubble WITHOUT tracking (used when rebuilding a restored session).
		function renderMessage( role, text ) {
			return addMessage( 'ai' === role ? 'ai' : 'user', function ( b ) {
				b.textContent = text;
			} );
		}

		function addUserMessage( text ) {
			state.messages.push( { role: 'user', content: text } );
			return renderMessage( 'user', text );
		}

		function addAIMessage( text ) {
			state.messages.push( { role: 'assistant', content: text } );
			return renderMessage( 'ai', text );
		}

		function addAIError( text ) {
			return addMessage( 'ai err', function ( b ) {
				b.textContent = text;
			} );
		}

		function addTyping( label ) {
			return addMessage( 'ai typing', function ( b ) {
				var dots = document.createElement( 'span' );
				dots.className = 'aieb-dots';
				dots.innerHTML = '<i></i><i></i><i></i>';
				b.appendChild( dots );
				var s = document.createElement( 'span' );
				s.className = 'aieb-typing-label';
				s.textContent = ' ' + label;
				b.appendChild( s );
			} );
		}

		function removeNode( n ) {
			if ( n && n.parentNode ) {
				n.parentNode.removeChild( n );
			}
		}

		// A "match a saved design style?" question built from the curated reference
		// library — folded into the clarify card instead of a manual dropdown.
		function referenceQuestion() {
			if ( ! refList.length ) {
				return null;
			}
			var options = [ { label: t( 'refNone', 'No preference' ), value: '' } ];
			refList.forEach( function ( r ) {
				options.push( { label: r.name || r.id, value: r.id } );
			} );
			return {
				id: '__reference',
				question: t( 'refQuestion', 'Match a saved design style?' ),
				type: 'single',
				options: options
			};
		}

		// Render a clarify question card with single/multi option chips.
		function renderQuestions( questions, enriched, original ) {
			state.awaitingAnswers = true;
			var answers = {};

			// Append the reference "match style" question to the AI's content questions.
			var allQuestions = questions.slice();
			var refQ = referenceQuestion();
			if ( refQ ) {
				allQuestions.push( refQ );
			}

			addMessage( 'ai', function ( b ) {
				var intro = document.createElement( 'div' );
				intro.className = 'aieb-q-intro';
				intro.textContent = t( 'clarifyIntro', 'A few quick questions to nail the design:' );
				b.appendChild( intro );

				allQuestions.forEach( function ( q ) {
					answers[ q.id ] = { labels: [], values: [] };

					var qb = document.createElement( 'div' );
					qb.className = 'aieb-q';
					var qt = document.createElement( 'div' );
					qt.className = 'aieb-q-title';
					qt.textContent = q.question;
					qb.appendChild( qt );

					var opts = document.createElement( 'div' );
					opts.className = 'aieb-q-opts';
					( q.options || [] ).forEach( function ( opt ) {
						var chip = document.createElement( 'button' );
						chip.type = 'button';
						chip.className = 'aieb-chip aieb-q-opt';
						chip.textContent = opt.label;
						chip.setAttribute( 'data-value', null != opt.value ? opt.value : opt.label );
						chip.addEventListener( 'click', function () {
							if ( 'multi' === q.type ) {
								chip.classList.toggle( 'active' );
							} else {
								Array.prototype.forEach.call( opts.querySelectorAll( '.aieb-q-opt' ), function ( x ) {
									x.classList.remove( 'active' );
								} );
								chip.classList.add( 'active' );
							}
							var active = Array.prototype.slice.call( opts.querySelectorAll( '.aieb-q-opt.active' ) );
							answers[ q.id ].labels = active.map( function ( x ) {
								return x.textContent;
							} );
							answers[ q.id ].values = active.map( function ( x ) {
								return x.getAttribute( 'data-value' );
							} );
						} );
						opts.appendChild( chip );
					} );
					qb.appendChild( opts );
					b.appendChild( qb );
				} );

				var actions = document.createElement( 'div' );
				actions.className = 'aieb-q-actions';

				var build = document.createElement( 'button' );
				build.type = 'button';
				build.className = 'btn primary sm';
				build.textContent = t( 'buildNow', 'Build it' );
				build.addEventListener( 'click', function () {
					disableCard( actions );
					submitAnswers( allQuestions, answers, enriched, original );
				} );

				var skip = document.createElement( 'button' );
				skip.type = 'button';
				skip.className = 'btn sm';
				skip.textContent = t( 'skipQuestions', 'Skip & build anyway' );
				skip.addEventListener( 'click', function () {
					disableCard( actions );
					state.awaitingAnswers = false;
					runGenerate( wrapScope( enriched ), original );
				} );

				actions.appendChild( build );
				actions.appendChild( skip );
				b.appendChild( actions );
			} );
		}

		// Disable every control inside a question card once it has been answered.
		function disableCard( actions ) {
			var card = actions.parentNode;
			if ( ! card ) {
				return;
			}
			Array.prototype.forEach.call( card.querySelectorAll( 'button' ), function ( btn ) {
				btn.disabled = true;
			} );
			card.classList.add( 'aieb-q-done' );
		}

		function submitAnswers( questions, answers, enriched, original ) {
			state.awaitingAnswers = false;

			var lines = [];
			var summary = [];
			questions.forEach( function ( q ) {
				var a = answers[ q.id ];
				if ( ! a || ! a.labels || ! a.labels.length ) {
					return;
				}
				// Reference question: drive the few-shot exemplar, not the text brief.
				if ( '__reference' === q.id ) {
					state.reference = ( a.values && a.values[ 0 ] ) ? a.values[ 0 ] : '';
					if ( state.reference ) {
						summary.push( a.labels[ 0 ] );
					}
					return;
				}
				lines.push( '- ' + q.question + ' ' + a.labels.join( ', ' ) );
				summary.push( a.labels.join( ', ' ) );
			} );

			if ( summary.length ) {
				addUserMessage( summary.join( ' · ' ) );
			}

			var finalPrompt = enriched;
			if ( lines.length ) {
				finalPrompt = enriched + '\n\nDetails:\n' + lines.join( '\n' );
			}
			runGenerate( wrapScope( finalPrompt ), original );
		}

		// Store the AI's inferred scope (used by wrapScope when building).
		function setScope( scope ) {
			var allowed = { fullpage: 1, custom: 1, hero: 1, pricing: 1, about: 1, features: 1, testimonials: 1, contact: 1 };
			state.scope = allowed[ scope ] ? scope : '';
		}

		// Start a fresh conversation. The new session is created lazily on the
		// first message, so this just clears local state.
		function clearThreadState() {
			state.template = null;
			state.awaitingAnswers = false;
			state.showingJson = false;
			state.scope = '';
			state.reference = '';
			state.brief = '';
			state.messages = [];
			if ( els.thread ) {
				Array.prototype.forEach.call( els.thread.querySelectorAll( '.aieb-msg' ), function ( n ) {
					removeNode( n );
				} );
				if ( els.threadEmpty ) {
					els.threadEmpty.style.display = '';
				}
			}
			if ( els.brief ) {
				els.brief.value = '';
			}
			if ( els.plan ) {
				els.plan.classList.add( 'aieb-hidden' );
			}
			if ( els.prompt ) {
				els.prompt.value = '';
				updateCharCount();
			}
			if ( els.jsonPane ) {
				els.jsonPane.classList.add( 'aieb-hidden' );
			}
			showState( 'empty' );
			refreshPushState();
		}

		function resetChat() {
			if ( state.busy ) {
				return;
			}
			state.sessionId = null;
			clearThreadState();
		}

		/* ---- Design plan (brief) ---- */

		function setBrief( text ) {
			state.brief = text;
			if ( els.brief ) {
				els.brief.value = text;
			}
			if ( els.plan && '' !== text ) {
				els.plan.classList.remove( 'aieb-hidden' );
			}
			if ( els.generateDesign ) {
				els.generateDesign.disabled = false;
			}
		}

		function markPlanReady() {
			if ( els.generateDesign ) {
				els.generateDesign.classList.add( 'aieb-pulse' );
			}
			if ( els.plan ) {
				els.plan.classList.remove( 'aieb-hidden' );
			}
		}

		/* ---- Sessions (server-persisted conversations) ---- */

		// Create a session on the server the first time it's needed.
		function ensureSession() {
			if ( state.sessionId ) {
				return Promise.resolve( state.sessionId );
			}
			if ( state.creating ) {
				return state.creating;
			}
			if ( ! cfg.sessionsUrl ) {
				return Promise.resolve( null );
			}
			state.creating = apiPost( cfg.sessionsUrl, {} )
				.then( function ( r ) {
					state.creating = null;
					if ( r.ok && r.data && r.data.id ) {
						state.sessionId = r.data.id;
						loadSessions();
					}
					return state.sessionId;
				} )
				.catch( function () {
					state.creating = null;
					return null;
				} );
			return state.creating;
		}

		// Debounced save of the current session.
		function persistSession() {
			if ( ! cfg.sessionsUrl ) {
				return;
			}
			clearTimeout( saveTimer );
			saveTimer = setTimeout( doPersist, 800 );
		}

		function doPersist() {
			ensureSession().then( function ( id ) {
				if ( ! id ) {
					return;
				}
				apiPost( cfg.sessionsUrl + '/' + id, {
					messages: state.messages,
					brief: state.brief,
					template: state.template,
					provider: selectedProvider(),
					scope: state.scope,
					reference: state.reference,
					title: sessionTitle()
				} ).then( function () {
					loadSessions();
				} );
			} );
		}

		// Title from the first user message (truncated), else a default.
		function sessionTitle() {
			var first = '';
			for ( var i = 0; i < state.messages.length; i++ ) {
				if ( 'user' === state.messages[ i ].role ) {
					first = state.messages[ i ].content;
					break;
				}
			}
			first = ( first || '' ).replace( /\s+/g, ' ' ).trim();
			if ( ! first ) {
				return '';
			}
			return first.length > 60 ? first.slice( 0, 57 ) + '…' : first;
		}

		function loadSessions() {
			if ( ! cfg.sessionsUrl ) {
				return;
			}
			apiGet( cfg.sessionsUrl )
				.then( function ( r ) {
					sessions = ( r.ok && Array.isArray( r.data ) ) ? r.data : [];
					renderSessions();
				} )
				.catch( function () {} );
		}

		function renderSessions() {
			if ( ! els.historyList ) {
				return;
			}
			if ( els.histCount ) {
				els.histCount.textContent = sessions.length;
			}
			els.historyList.innerHTML = '';

			var filter = els.histSearch ? ( els.histSearch.value || '' ).toLowerCase() : '';
			var shown = sessions.filter( function ( s ) {
				return ! filter || ( s.title || '' ).toLowerCase().indexOf( filter ) > -1;
			} );

			if ( ! shown.length ) {
				els.historyList.appendChild( emptyListItem( t( 'sessionsEmpty', 'No chats yet. Start one below.' ) ) );
				return;
			}

			shown.forEach( function ( s ) {
				var row = document.createElement( 'div' );
				row.className = 'aieb-litem aieb-session' + ( s.id === state.sessionId ? ' active' : '' );

				var main = document.createElement( 'button' );
				main.type = 'button';
				main.className = 'aieb-session-open';
				main.title = t( 'restore', 'Open' );

				var lt = document.createElement( 'div' );
				lt.className = 'lt';
				lt.textContent = s.title || t( 'newChat', 'New chat' );
				var lm = document.createElement( 'div' );
				lm.className = 'lm';
				var meta = document.createElement( 'span' );
				meta.textContent = ( s.message_count || 0 ) + ' ' + t( 'messages', 'messages' );
				lm.appendChild( meta );
				var tm = document.createElement( 'span' );
				tm.style.marginLeft = 'auto';
				tm.textContent = formatTime( s.updated );
				lm.appendChild( tm );
				main.appendChild( lt );
				main.appendChild( lm );
				main.addEventListener( 'click', function () {
					openSession( s.id );
				} );

				var del = document.createElement( 'button' );
				del.type = 'button';
				del.className = 'aieb-litem-del';
				del.title = t( 'deleteChat', 'Delete' );
				del.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>';
				del.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					deleteSession( s.id );
				} );

				row.appendChild( main );
				row.appendChild( del );
				els.historyList.appendChild( row );
			} );
		}

		function openSession( id ) {
			if ( state.busy || ! cfg.sessionsUrl ) {
				return;
			}
			apiGet( cfg.sessionsUrl + '/' + id )
				.then( function ( r ) {
					if ( ! r.ok || ! r.data ) {
						return;
					}
					var d = r.data;
					clearThreadState();
					state.sessionId = d.id;
					state.messages = Array.isArray( d.messages ) ? d.messages : [];
					state.scope = d.scope || '';
					state.reference = d.reference || '';
					if ( d.provider ) {
						selectProviderByKey( d.provider );
					}

					// Rebuild the thread.
					if ( state.messages.length ) {
						hideThreadEmpty();
						state.messages.forEach( function ( m ) {
							renderMessage( 'assistant' === m.role ? 'ai' : 'user', m.content );
						} );
					}
					if ( d.brief ) {
						setBrief( d.brief );
					}
					if ( d.template ) {
						renderTemplate( d.template );
					} else {
						showState( 'empty' );
					}
					refreshPushState();
					renderSessions();
					switchTab( 'compose' );
				} )
				.catch( function () {} );
		}

		function deleteSession( id ) {
			if ( ! cfg.sessionsUrl ) {
				return;
			}
			if ( window.confirm && ! window.confirm( t( 'confirmDeleteChat', 'Delete this chat? This cannot be undone.' ) ) ) {
				return;
			}
			fetch( cfg.sessionsUrl + '/' + id, {
				method: 'DELETE',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': cfg.nonce }
			} )
				.then( function () {
					if ( id === state.sessionId ) {
						resetChat();
					}
					loadSessions();
				} )
				.catch( function () {} );
		}

		function renderTemplate( template ) {
			state.template = template;

			els.json.textContent = JSON.stringify( template, null, 2 );
			els.frame.srcdoc = previewDocument( elementorJsonToHtml( template ) );

			showState( 'preview' );

			// A template now exists — allow pushing/downloading.
			refreshPushState();
		}

		// Switch the canvas between empty / loading / preview. With no argument,
		// shows the preview when a template exists, otherwise the empty state.
		function showState( which ) {
			if ( ! which ) {
				which = state.template ? 'preview' : 'empty';
			}
			els.empty.style.display = 'preview' === which || 'loading' === which ? 'none' : 'flex';
			els.loading.style.display = 'loading' === which ? 'block' : 'none';
			els.frame.classList.toggle( 'aieb-hidden', 'preview' !== which );
		}

		function onToggleJson() {
			if ( ! state.template ) {
				toast( t( 'noTemplate', 'Generate a template before pushing.' ), false );
				return;
			}
			state.showingJson = ! state.showingJson;
			els.jsonPane.classList.toggle( 'aieb-hidden', ! state.showingJson );
			els.toggle.classList.toggle( 'primary', state.showingJson );
		}

		/* ---- Fullscreen preview ---- */

		function openFullscreen() {
			if ( ! state.template ) {
				toast( t( 'noTemplate', 'Generate a template first.' ), false );
				return;
			}
			state.fullscreen = true;
			document.body.classList.add( 'aieb-fs-open' );
			if ( els.fsview ) {
				els.fsview.classList.add( 'show' );
				els.fsview.setAttribute( 'aria-hidden', 'false' );
			}
			// Copy preview content to fullscreen frame.
			if ( els.fsframe && els.frame && els.frame.srcdoc ) {
				els.fsframe.srcdoc = els.frame.srcdoc;
			}
			// Sync device toggle state.
			if ( els.devToggle && els.fsDev ) {
				var activeDev = els.devToggle.querySelector( 'button.active' );
				if ( activeDev ) {
					var dev = activeDev.getAttribute( 'data-dev' );
					Array.prototype.forEach.call( els.fsDev.querySelectorAll( 'button' ), function ( b ) {
						b.classList.toggle( 'active', b.getAttribute( 'data-dev' ) === dev );
					} );
					if ( els.fsframe ) {
						els.fsframe.setAttribute( 'data-dev', dev );
					}
				}
			}
		}

		function closeFullscreen() {
			state.fullscreen = false;
			document.body.classList.remove( 'aieb-fs-open' );
			if ( els.fsview ) {
				els.fsview.classList.remove( 'show' );
				els.fsview.setAttribute( 'aria-hidden', 'true' );
			}
		}

		function setLoading( loading ) {
			els.generate.disabled = loading;
			if ( loading ) {
				showState( 'loading' );
				var i = 0;
				var model = els.modelName ? els.modelName.textContent : '';
				els.loadMsg.textContent = STEPS[ 0 ] + ( model ? ' · ' + model : '' );
				clearInterval( loadTimer );
				loadTimer = setInterval( function () {
					i = ( i + 1 ) % STEPS.length;
					els.loadMsg.textContent = STEPS[ i ] + ( model ? ' · ' + model : '' );
				}, 700 );
			} else {
				clearInterval( loadTimer );
				loadTimer = null;
			}
		}

		/* ---- Page selector + Push to Elementor ---- */

		function refreshPushState() {
			var hasTemplate = !! state.template;
			var hasPage = !! ( els.pageSelect && els.pageSelect.value );
			els.push.disabled = ! ( hasTemplate && hasPage );
			if ( els.pushGutenberg ) {
				els.pushGutenberg.disabled = ! ( hasTemplate && hasPage );
			}
			if ( els.download ) {
				els.download.disabled = ! hasTemplate;
			}
			if ( els.pushTemplate ) {
				els.pushTemplate.disabled = ! hasTemplate;
			}
			if ( els.savePattern ) {
				els.savePattern.disabled = ! hasTemplate;
			}
		}

		function loadPages() {
			if ( ! ( window.wp && wp.apiFetch ) ) {
				return;
			}
			wp.apiFetch( { path: '/wp/v2/pages?per_page=100&status=publish,draft&orderby=title&order=asc&_fields=id,title' } )
				.then( function ( pages ) {
					var opts = [ '<option value="">' + esc( t( 'selectPage', '— Select a page —' ) ) + '</option>' ];
					( pages || [] ).forEach( function ( p ) {
						var label = ( p.title && p.title.rendered ) ? p.title.rendered : ( '#' + p.id );
						opts.push( '<option value="' + p.id + '">' + esc( label ) + '</option>' );
					} );
					els.pageSelect.innerHTML = opts.join( '' );
				} )
				.catch( function () {
					els.pageSelect.innerHTML = '<option value="">' + esc( t( 'selectPage', '— Select a page —' ) ) + '</option>';
				} );
		}

		function onPush() {
			if ( ! state.template ) {
				toast( t( 'noTemplate', 'Generate a template before pushing.' ), false );
				return;
			}
			var pageId = parseInt( els.pageSelect.value, 10 );
			if ( ! pageId ) {
				toast( t( 'noPage', 'Select a target page.' ), false );
				return;
			}

			els.push.disabled = true;

			wp.apiFetch( {
				url: cfg.pushUrl,
				method: 'POST',
				data: { page_id: pageId, elementor_json: state.template }
			} )
				.then( function ( res ) {
					var link = res && res.edit_url
						? '  ' + t( 'editInElementor', 'Edit in Elementor' )
						: '';
					toast( t( 'pushed', 'Pushed to Elementor.' ), true );
					if ( res && res.edit_url ) {
						window.open( res.edit_url, '_blank', 'noopener' );
					}
				} )
				.catch( function ( err ) {
					var msg = ( err && err.message ) ? err.message : t( 'pushFailed', 'Could not push to Elementor.' );
					toast( msg, false );
				} )
				.finally( function () {
					refreshPushState();
				} );
		}

		// Push the same template into a page as Gutenberg blocks (post_content).
		function onPushGutenberg() {
			if ( ! state.template ) {
				toast( t( 'noTemplate', 'Generate a template before pushing.' ), false );
				return;
			}
			if ( ! cfg.pushGutenbergUrl ) {
				toast( t( 'pushGutenbergFailed', 'Could not push to Gutenberg.' ), false );
				return;
			}
			var pageId = parseInt( els.pageSelect.value, 10 );
			if ( ! pageId ) {
				toast( t( 'noPage', 'Select a target page.' ), false );
				return;
			}

			els.pushGutenberg.disabled = true;

			wp.apiFetch( {
				url: cfg.pushGutenbergUrl,
				method: 'POST',
				data: { page_id: pageId, elementor_json: state.template }
			} )
				.then( function ( res ) {
					toast( t( 'pushedGutenberg', 'Pushed to Gutenberg.' ), true );
					if ( res && res.edit_url ) {
						window.open( res.edit_url, '_blank', 'noopener' );
					}
				} )
				.catch( function ( err ) {
					var msg = ( err && err.message ) ? err.message : t( 'pushGutenbergFailed', 'Could not push to Gutenberg.' );
					toast( msg, false );
				} )
				.finally( function () {
					refreshPushState();
				} );
		}

		// Save the design as a reusable Gutenberg pattern (wp_block post).
		function onSavePattern() {
			if ( ! state.template ) {
				toast( t( 'noTemplate', 'Generate a template before pushing.' ), false );
				return;
			}
			if ( ! cfg.savePatternUrl ) {
				toast( t( 'patternFailed', 'Could not save the pattern.' ), false );
				return;
			}

			var title = ( els.templateTitle && els.templateTitle.value || '' ).trim();

			els.savePattern.disabled = true;
			toast( t( 'savingPattern', 'Saving pattern…' ), true );

			wp.apiFetch( {
				url: cfg.savePatternUrl,
				method: 'POST',
				data: { elementor_json: state.template, title: title }
			} )
				.then( function ( res ) {
					toast( t( 'patternSaved', 'Saved as a Gutenberg pattern.' ), true );
					if ( res && res.edit_url ) {
						window.open( res.edit_url, '_blank', 'noopener' );
					}
				} )
				.catch( function ( err ) {
					var msg = ( err && err.message ) ? err.message : t( 'patternFailed', 'Could not save the pattern.' );
					toast( msg, false );
				} )
				.finally( function () {
					refreshPushState();
				} );
		}

		/* ---- Download as Elementor template (client-side) ---- */

		// state.template may be a { content: [...] } wrapper or a raw elements array.
		function contentArray( template ) {
			if ( Array.isArray( template ) ) {
				return template;
			}
			if ( template && Array.isArray( template.content ) ) {
				return template.content;
			}
			return null;
		}

		function downloadElementorTemplate( contentJson, title, type ) {
			var template = {
				version: '0.4',
				title: title || 'AI Generated Template',
				type: type || 'page',
				page_settings: {},
				content: contentJson
			};

			var blob = new Blob(
				[ JSON.stringify( template, null, 2 ) ],
				{ type: 'application/json' }
			);

			var url = URL.createObjectURL( blob );
			var a = document.createElement( 'a' );
			a.href = url;

			// Sanitize title for filename: lowercase, spaces to hyphens.
			var filename = ( title || 'ai-template' )
				.toLowerCase()
				.replace( /[^a-z0-9]+/g, '-' )
				.replace( /^-+|-+$/g, '' );

			a.download = ( filename || 'ai-template' ) + '.json';
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );
			URL.revokeObjectURL( url );
		}

		function onDownload() {
			var content = contentArray( state.template );
			if ( ! content || ! content.length ) {
				toast( t( 'downloadEmpty', 'Generate a design first before downloading.' ), false );
				return;
			}

			var title = ( els.templateTitle && els.templateTitle.value || '' ).trim();
			var type = ( els.templateType && els.templateType.value ) || 'page';

			downloadElementorTemplate( content, title, type );
			toast( t( 'downloaded', 'Template JSON downloaded.' ), true );

			addTemplateHistoryEntry( {
				title: title || 'AI Generated Template',
				type: type,
				date: Math.floor( Date.now() / 1000 ),
				content: content
			} );
		}

		/* ---- Push directly into the Elementor Library (Saved Template) ---- */

		function onPushTemplate() {
			if ( ! state.template ) {
				toast( t( 'noTemplate', 'Generate a template before pushing.' ), false );
				return;
			}
			if ( ! cfg.pushTemplateUrl ) {
				toast( t( 'templateFailed', 'Could not save the template.' ), false );
				return;
			}

			var title = ( els.templateTitle && els.templateTitle.value || '' ).trim();
			var type = ( els.templateType && els.templateType.value ) || 'page';

			els.pushTemplate.disabled = true;
			toast( t( 'pushingTemplate', 'Saving template…' ), true );

			wp.apiFetch( {
				url: cfg.pushTemplateUrl,
				method: 'POST',
				data: {
					elementor_json: state.template,
					title: title,
					template_type: type
				}
			} )
				.then( function ( res ) {
					toast( t( 'templateSaved', 'Saved to Elementor Library.' ), true );
					if ( res && res.library_url ) {
						window.open( res.library_url, '_blank', 'noopener' );
					}
				} )
				.catch( function ( err ) {
					var msg = ( err && err.message ) ? err.message : t( 'templateFailed', 'Could not save the template.' );
					toast( msg, false );
				} )
				.finally( function () {
					refreshPushState();
				} );
		}

		/* ---- Template history (localStorage) ---- */

		function loadTemplateHistory() {
			try {
				var raw = window.localStorage.getItem( TEMPLATE_HISTORY_KEY );
				var parsed = raw ? JSON.parse( raw ) : [];
				return Array.isArray( parsed ) ? parsed : [];
			} catch ( e ) {
				return [];
			}
		}

		function saveTemplateHistory() {
			try {
				window.localStorage.setItem( TEMPLATE_HISTORY_KEY, JSON.stringify( templateHistory ) );
			} catch ( e ) {
				// Storage unavailable or quota exceeded — keep the in-memory list.
			}
		}

		function addTemplateHistoryEntry( entry ) {
			templateHistory.unshift( entry );
			templateHistory = templateHistory.slice( 0, 20 );
			saveTemplateHistory();
			renderTemplateHistory();
		}

		function renderTemplateHistory() {
			if ( ! els.templateHistoryList ) {
				return;
			}
			els.templateHistoryList.innerHTML = '';

			if ( ! templateHistory.length ) {
				els.templateHistoryList.appendChild( emptyListItem( t( 'templateHistoryEmpty', 'No templates downloaded yet.' ) ) );
				return;
			}

			templateHistory.forEach( function ( entry, index ) {
				var btn = listItem(
					entry.title || '(untitled)',
					( entry.type || 'page' ),
					formatTime( entry.date ),
					t( 'redownload', 'Download again' )
				);
				btn.addEventListener( 'click', function () {
					var item = templateHistory[ index ];
					if ( item ) {
						downloadElementorTemplate( item.content, item.title, item.type );
						toast( t( 'downloaded', 'Template JSON downloaded.' ), true );
					}
				} );
				els.templateHistoryList.appendChild( btn );
			} );
		}

		/* ---- List item helpers ---- */

		// Build a design ".litem" list row: title line + meta line (tag + time).
		function listItem( title, tag, time, hint ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'aieb-litem';
			btn.title = hint || '';

			var lt = document.createElement( 'div' );
			lt.className = 'lt';
			lt.textContent = title;

			var lm = document.createElement( 'div' );
			lm.className = 'lm';
			if ( tag ) {
				var dot = document.createElement( 'span' );
				dot.className = 'dot on';
				lm.appendChild( dot );
				var tagEl = document.createElement( 'span' );
				tagEl.textContent = tag;
				lm.appendChild( tagEl );
			}
			var timeEl = document.createElement( 'span' );
			timeEl.style.marginLeft = 'auto';
			timeEl.textContent = time || '';
			lm.appendChild( timeEl );

			btn.appendChild( lt );
			btn.appendChild( lm );
			return btn;
		}

		function emptyListItem( text ) {
			var li = document.createElement( 'div' );
			li.className = 'aieb-history-empty';
			li.textContent = text;
			return li;
		}

		function formatTime( ts ) {
			if ( ! ts ) {
				return '';
			}
			var d = new Date( ts * 1000 );
			return d.toLocaleString();
		}

		/* ---- Toast ---- */

		var toastTimer = null;
		function toast( msg, ok ) {
			if ( ! els.toast ) {
				return;
			}
			els.toastMsg.textContent = msg;
			var svg = els.toast.querySelector( 'svg' );
			if ( svg ) {
				svg.style.color = ok ? 'var(--success)' : 'var(--warn)';
			}
			els.toast.classList.add( 'show' );
			clearTimeout( toastTimer );
			toastTimer = setTimeout( function () {
				els.toast.classList.remove( 'show' );
			}, 2600 );
		}

		/* ===================== FULL WEBSITE (multi-page) ===================== */

		// Self-contained: plan a sitemap, edit it, then build every page + a nav
		// menu via /plan-site and /build-site. Reuses apiPost/toast/t/selectedProvider
		// from this scope; manages its own injected DOM under #aieb-site.
		function initSiteBuilder() {
			var s = {
				modesw: root.querySelector( '#aieb-modesw' ),
				panel: root.querySelector( '#aieb-site' ),
				intro: root.querySelector( '#aieb-site-intro' ),
				prompt: root.querySelector( '#aieb-site-prompt' ),
				planBtn: root.querySelector( '#aieb-plan-site' ),
				planWrap: root.querySelector( '#aieb-site-plan' ),
				title: root.querySelector( '#aieb-site-title' ),
				list: root.querySelector( '#aieb-page-list' ),
				addBtn: root.querySelector( '#aieb-add-page' ),
				setHome: root.querySelector( '#aieb-set-home' ),
				buildBtn: root.querySelector( '#aieb-build-site' ),
				results: root.querySelector( '#aieb-site-results' )
			};

			// Bail quietly if the markup or config isn't present.
			if ( ! s.panel || ! s.modesw || ! cfg.planSiteUrl || ! cfg.buildSiteUrl ) {
				return;
			}

			var busy = false;

			function esc( str ) {
				return String( str == null ? '' : str ).replace( /[&<>"']/g, function ( c ) {
					return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
				} );
			}

			function slugify( str ) {
				return String( str || '' ).toLowerCase().trim()
					.replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' );
			}

			// Toggle between single-page (chat) and full-site mode.
			Array.prototype.forEach.call( s.modesw.querySelectorAll( 'button' ), function ( btn ) {
				btn.addEventListener( 'click', function () {
					var mode = btn.getAttribute( 'data-mode' );
					Array.prototype.forEach.call( s.modesw.querySelectorAll( 'button' ), function ( b ) {
						var on = b === btn;
						b.classList.toggle( 'active', on );
						b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
					} );
					root.classList.toggle( 'aieb-site-mode', 'site' === mode );
				} );
			} );

			s.planBtn.addEventListener( 'click', planSite );
			s.addBtn.addEventListener( 'click', function () {
				s.list.appendChild( pageCard( { title: '', brief: '', scope: 'custom', role: 'standard' } ) );
			} );
			s.buildBtn.addEventListener( 'click', buildSite );

			function planSite() {
				if ( busy ) {
					return;
				}
				var prompt = ( s.prompt.value || '' ).trim();
				if ( ! prompt ) {
					toast( t( 'sitePromptEmpty', 'Describe the website you want to build.' ), false );
					s.prompt.focus();
					return;
				}
				setBusy( true );
				s.planBtn.textContent = t( 'planningSite', 'Planning your site…' );
				apiPost( cfg.planSiteUrl, { provider: selectedProvider(), prompt: prompt } )
					.then( function ( r ) {
						if ( ! r.ok || ! r.data || ! r.data.pages ) {
							toast( ( r.data && r.data.message ) ? r.data.message : t( 'sitePlanFailed', 'Could not plan the site. Try rephrasing.' ), false );
							return;
						}
						renderPlan( r.data );
						toast( t( 'sitePlanReady', 'Site plan ready — review the pages, then build.' ), true );
					} )
					.catch( function () {
						toast( t( 'networkError', 'Network error. Could not reach the server.' ), false );
					} )
					.then( function () {
						setBusy( false );
						resetPlanBtn();
					} );
			}

			function resetPlanBtn() {
				s.planBtn.textContent = t( 'planSite', 'Plan site' );
			}

			function renderPlan( plan ) {
				s.title.value = plan.site_title || '';
				s.list.innerHTML = '';
				( plan.pages || [] ).forEach( function ( p ) {
					s.list.appendChild( pageCard( p ) );
				} );
				s.planWrap.classList.remove( 'aieb-hidden' );
				s.results.classList.add( 'aieb-hidden' );
				s.results.innerHTML = '';
			}

			// Build one editable page card. The first home-flagged card gets the radio.
			function pageCard( p ) {
				var card = document.createElement( 'div' );
				card.className = 'aieb-pagecard';
				card.setAttribute( 'data-slug', p.slug || '' );
				card.setAttribute( 'data-scope', p.scope || 'fullpage' );

				var isHome = 'home' === p.role;
				card.innerHTML =
					'<div class="aieb-pagecard-top">' +
						'<input class="input sm aieb-page-title" value="' + esc( p.title || '' ) + '" placeholder="' + esc( t( 'pageTitleLabel', 'Page title' ) ) + '" />' +
						'<label class="aieb-home-radio" title="' + esc( t( 'homeBadge', 'Home' ) ) + '"><input type="radio" name="aieb-home"' + ( isHome ? ' checked' : '' ) + ' /> ' + esc( t( 'homeBadge', 'Home' ) ) + '</label>' +
						'<button type="button" class="aieb-page-del" aria-label="' + esc( t( 'removePage', 'Remove' ) ) + '">&times;</button>' +
					'</div>' +
					'<textarea class="input aieb-page-brief" rows="3" placeholder="' + esc( t( 'pageBriefLabel', 'What this page contains' ) ) + '">' + esc( p.brief || '' ) + '</textarea>';

				card.querySelector( '.aieb-page-del' ).addEventListener( 'click', function () {
					card.parentNode.removeChild( card );
				} );
				return card;
			}

			// Collect the current (possibly edited) sitemap from the DOM.
			function collectPages() {
				var pages = [];
				Array.prototype.forEach.call( s.list.querySelectorAll( '.aieb-pagecard' ), function ( card ) {
					var title = ( card.querySelector( '.aieb-page-title' ).value || '' ).trim();
					var brief = ( card.querySelector( '.aieb-page-brief' ).value || '' ).trim();
					if ( ! title || ! brief ) {
						return;
					}
					var home = card.querySelector( '.aieb-home-radio input' ).checked;
					pages.push( {
						slug: card.getAttribute( 'data-slug' ) || slugify( title ),
						title: title,
						brief: brief,
						scope: card.getAttribute( 'data-scope' ) || 'fullpage',
						home: home
					} );
				} );
				return pages;
			}

			function buildSite() {
				if ( busy ) {
					return;
				}
				var pages = collectPages();
				if ( ! pages.length ) {
					toast( t( 'sitePromptEmpty', 'Describe the website you want to build.' ), false );
					return;
				}
				var siteTitle = ( s.title.value || '' ).trim();
				var homeSlug = ( pages.filter( function ( p ) { return p.home; } )[ 0 ] || pages[ 0 ] ).slug;

				setBusy( true );
				s.results.classList.remove( 'aieb-hidden' );
				s.results.innerHTML = '';
				var built = [];
				var failed = null;

				// Build pages sequentially to respect per-request timeouts + rate limits.
				var chain = Promise.resolve();
				pages.forEach( function ( page, i ) {
					chain = chain.then( function () {
						if ( failed ) {
							return;
						}
						progress( buildingMsg( page.title, i + 1, pages.length ) );
						return apiPost( cfg.buildSiteUrl, {
							mode: 'page',
							provider: selectedProvider(),
							site_title: siteTitle,
							page: { slug: page.slug, title: page.title, brief: page.brief, scope: page.scope }
						} ).then( function ( r ) {
							if ( ! r.ok || ! r.data || ! r.data.page_id ) {
								failed = ( r.data && r.data.message ) ? r.data.message : t( 'genericError', 'Generation failed. Please try again.' );
								return;
							}
							built.push( {
								slug: r.data.slug || page.slug,
								page_id: r.data.page_id,
								nav_label: page.title,
								view_url: r.data.view_url,
								edit_url: r.data.edit_url,
								title: r.data.title || page.title
							} );
							renderResults( built, null );
						} );
					} );
				} );

				chain.then( function () {
					if ( failed ) {
						renderResults( built, failed );
						toast( t( 'siteBuildFailed', 'Site build stopped on a page. See details below.' ), false );
						return null;
					}
					if ( ! built.length ) {
						return null;
					}
					progress( t( 'finalizingSite', 'Building navigation menu…' ) );
					return apiPost( cfg.buildSiteUrl, {
						mode: 'finalize',
						site_title: siteTitle,
						pages: built.map( function ( b ) {
							return { slug: b.slug, page_id: b.page_id, nav_label: b.nav_label };
						} ),
						home_slug: homeSlug,
						set_homepage: !! ( s.setHome && s.setHome.checked )
					} ).then( function () {
						renderResults( built, null );
						toast( ( t( 'siteBuilt', '%d pages created.' ) ).replace( '%d', built.length ), true );
						loadPages();
					} );
				} ).catch( function () {
					renderResults( built, t( 'networkError', 'Network error. Could not reach the server.' ) );
				} ).then( function () {
					setBusy( false );
				} );
			}

			function buildingMsg( title, n, total ) {
				return ( t( 'buildingPage', 'Building “%1$s” (%2$d of %3$d)…' ) )
					.replace( '%1$s', title ).replace( '%2$d', n ).replace( '%3$d', total );
			}

			function progress( msg ) {
				var p = s.results.querySelector( '.aieb-site-progress' );
				if ( ! p ) {
					p = document.createElement( 'div' );
					p.className = 'aieb-site-progress';
					s.results.insertBefore( p, s.results.firstChild );
				}
				p.innerHTML = '<span class="aieb-spin"></span> ' + esc( msg );
			}

			function renderResults( built, error ) {
				var html = '';
				if ( built.length ) {
					html += '<ul class="aieb-site-pages">';
					built.forEach( function ( b ) {
						html += '<li>' +
							'<span class="aieb-site-pg-title">' + esc( b.title ) + '</span>' +
							'<a class="aieb-site-pg-link" href="' + esc( b.view_url ) + '" target="_blank" rel="noopener">' + esc( t( 'viewSite', 'View site' ) ) + '</a>' +
							'<a class="aieb-site-pg-link" href="' + esc( b.edit_url ) + '" target="_blank" rel="noopener">' + esc( t( 'editInElementor', 'Edit in Elementor' ) ) + '</a>' +
							'</li>';
					} );
					html += '</ul>';
				}
				if ( error ) {
					html += '<div class="aieb-site-err">' + esc( error ) + '</div>';
				}
				s.results.innerHTML = html;
			}

			function setBusy( v ) {
				busy = v;
				s.planBtn.disabled = v;
				s.buildBtn.disabled = v;
				s.addBtn.disabled = v;
			}
		}

		/* ---- Elementor JSON -> HTML preview ---- */

		// Responsive overrides collected during a render pass, emitted as media
		// queries in previewDocument(). Elementor breakpoints: tablet <=1024px,
		// mobile <=767px — matching the device-toggle iframe widths.
		var rspTablet = [];
		var rspMobile = [];

		// Build a settings "view" for a breakpoint: base keys, then any
		// "<key>_tablet"/"<key>_mobile" override its base counterpart. Lets us
		// reuse the desktop style builders unchanged for each breakpoint.
		function bpView( s, suffix ) {
			var v = {};
			var k;
			for ( k in s ) {
				if ( ! /_(tablet|mobile)$/.test( k ) ) {
					v[ k ] = s[ k ];
				}
			}
			for ( k in s ) {
				if ( k.length > suffix.length && k.slice( -suffix.length ) === suffix ) {
					v[ k.slice( 0, -suffix.length ) ] = s[ k ];
				}
			}
			return v;
		}

		function hasBp( s, suffix ) {
			for ( var k in s ) {
				if ( k.length > suffix.length && k.slice( -suffix.length ) === suffix ) {
					return true;
				}
			}
			return false;
		}

		// Media-query rules must beat the element's inline base style, so each
		// declaration carries !important. Values use commas, not semicolons.
		function important( css ) {
			return css.split( ';' ).filter( Boolean ).map( function ( d ) {
				return d + ' !important';
			} ).join( ';' );
		}

		// Queue tablet/mobile overrides for one element id, reusing its desktop
		// style builder against a per-breakpoint settings view.
		function collectResponsive( id, s, builder ) {
			if ( ! id ) {
				return;
			}
			if ( hasBp( s, '_tablet' ) ) {
				var t = builder( bpView( s, '_tablet' ) );
				if ( t ) {
					rspTablet.push( '#' + id + '{' + important( t ) + '}' );
				}
			}
			if ( hasBp( s, '_mobile' ) ) {
				var m = builder( bpView( s, '_mobile' ) );
				if ( m ) {
					rspMobile.push( '#' + id + '{' + important( m ) + '}' );
				}
			}
		}

		// Stable element id for responsive selectors; '' when missing.
		function elId( el ) {
			return el && el.id ? 'aieb-el-' + String( el.id ).replace( /[^a-z0-9_-]/gi, '' ) : '';
		}

		function esc( value ) {
			var div = document.createElement( 'div' );
			div.textContent = null == value ? '' : String( value );
			return div.innerHTML;
		}

		/**
		 * Convert an Elementor template object into an HTML string for preview.
		 *
		 * @param {Object} json Elementor template ({ content: [...] }) or a raw elements array.
		 * @return {string} HTML markup.
		 */
		function elementorJsonToHtml( json ) {
			rspTablet = [];
			rspMobile = [];
			var elements;
			if ( Array.isArray( json ) ) {
				elements = json;
			} else if ( json && Array.isArray( json.content ) ) {
				elements = json.content;
			} else {
				elements = [];
			}
			return renderElements( elements );
		}

		function renderElements( elements ) {
			if ( ! Array.isArray( elements ) ) {
				return '';
			}
			return elements.map( renderElement ).join( '' );
		}

		function renderElement( el ) {
			if ( ! el || 'object' !== typeof el ) {
				return '';
			}
			if ( 'container' === el.elType ) {
				var s = el.settings || {};
				var style = containerStyle( s );
				var id = elId( el );
				collectResponsive( id, s, containerStyle );
				return '<div class="aieb-c"' + ( id ? ' id="' + id + '"' : '' ) +
					( style ? ' style="' + style + '"' : '' ) + '>' +
					renderElements( el.elements ) + '</div>';
			}
			if ( 'widget' === el.elType ) {
				return renderWidget( el );
			}
			return '';
		}

		// Elementor size controls are { size, unit } objects or plain numbers.
		function sizeValue( raw, fallbackUnit ) {
			var unit = fallbackUnit || 'px';
			if ( raw && 'object' === typeof raw ) {
				if ( null == raw.size || '' === raw.size ) {
					return '';
				}
				return raw.size + cssUnit( raw.unit || unit );
			}
			if ( null == raw || '' === raw ) {
				return '';
			}
			return String( raw ) + unit;
		}

		// Elementor allows units px/%/em/rem/vw/vh; anything else falls back to px.
		function cssUnit( unit ) {
			var ok = { px: 1, '%': 1, em: 1, rem: 1, vw: 1, vh: 1 };
			return ok[ unit ] ? unit : 'px';
		}

		// Append "prop:value" to an array of declarations when value is truthy.
		function decl( out, prop, value ) {
			if ( value ) {
				out.push( prop + ':' + value );
			}
		}

		// Elementor dimension control: { top, right, bottom, left, unit }.
		// Returns a CSS shorthand ("12px 8px 12px 8px") or '' when empty.
		function dimension( raw ) {
			if ( ! raw || 'object' !== typeof raw ) {
				return '';
			}
			var u = cssUnit( raw.unit || 'px' );
			var has = false;
			var parts = [ 'top', 'right', 'bottom', 'left' ].map( function ( side ) {
				var v = raw[ side ];
				if ( '' === v || null == v ) {
					return '0';
				}
				has = true;
				return v + u;
			} );
			return has ? parts.join( ' ' ) : '';
		}

		// Background: classic color/image or a linear/radial gradient.
		function backgroundDecls( s, out ) {
			if ( 'gradient' === s.background_background ) {
				var a = s.background_color || '#ffffff';
				var b = s.background_color_b || '#ffffff';
				if ( 'radial' === s.background_gradient_type ) {
					decl( out, 'background', 'radial-gradient(circle, ' + a + ', ' + b + ')' );
				} else {
					var angle = ( s.background_gradient_angle && null != s.background_gradient_angle.size )
						? s.background_gradient_angle.size
						: 180;
					decl( out, 'background', 'linear-gradient(' + angle + 'deg, ' + a + ', ' + b + ')' );
				}
				return;
			}
			decl( out, 'background-color', s.background_color );
			var img = s.background_image && s.background_image.url;
			if ( img ) {
				out.push( 'background-image:url(' + img + ')' );
				out.push( 'background-size:' + ( s.background_size || 'cover' ) );
				out.push( 'background-position:' + ( s.background_position || 'center center' ) );
				out.push( 'background-repeat:' + ( s.background_repeat || 'no-repeat' ) );
			}
		}

		// Typography group: keys are prefixed (default "typography_").
		function typographyDecls( s, out, prefix ) {
			prefix = prefix || 'typography_';
			decl( out, 'font-size', sizeValue( s[ prefix + 'font_size' ] ) );
			decl( out, 'font-weight', s[ prefix + 'font_weight' ] );
			decl( out, 'font-family', s[ prefix + 'font_family' ] );
			decl( out, 'line-height', sizeValue( s[ prefix + 'line_height' ], 'em' ) );
			decl( out, 'letter-spacing', sizeValue( s[ prefix + 'letter_spacing' ] ) );
			decl( out, 'text-transform', s[ prefix + 'text_transform' ] );
			decl( out, 'font-style', s[ prefix + 'font_style' ] );
		}

		function borderDecls( s, out ) {
			if ( s.border_border && 'none' !== s.border_border ) {
				out.push( 'border-style:' + s.border_border );
				out.push( 'border-width:' + ( dimension( s.border_width ) || '1px' ) );
				decl( out, 'border-color', s.border_color );
			}
			decl( out, 'border-radius', dimension( s.border_radius ) );
		}

		// Elementor box-shadow group: "<prefix>_box_shadow_type":"yes" +
		// "<prefix>_box_shadow":{horizontal,vertical,blur,spread,color}. Default
		// prefix is "box_shadow". Gives cards/sections real elevation in preview.
		function boxShadowDecls( s, out, prefix ) {
			prefix = prefix || 'box_shadow';
			var bs = s[ prefix + '_box_shadow' ];
			if ( 'yes' !== s[ prefix + '_box_shadow_type' ] && ! bs ) {
				return;
			}
			if ( ! bs || 'object' !== typeof bs ) {
				return;
			}
			var h = ( null != bs.horizontal ? bs.horizontal : 0 ) + 'px';
			var v = ( null != bs.vertical ? bs.vertical : 0 ) + 'px';
			var blur = ( null != bs.blur ? bs.blur : 0 ) + 'px';
			var spread = ( null != bs.spread ? bs.spread : 0 ) + 'px';
			var color = bs.color || 'rgba(0,0,0,0.15)';
			var inset = ( 'inset' === s[ prefix + '_box_shadow_position' ] ) ? 'inset ' : '';
			out.push( 'box-shadow:' + inset + h + ' ' + v + ' ' + blur + ' ' + spread + ' ' + color );
		}

		function spacingDecls( s, out ) {
			decl( out, 'padding', dimension( s.padding ) );
			decl( out, 'margin', dimension( s.margin ) );
		}

		// Styles for a container element (background, spacing, border, flex layout).
		function containerStyle( s ) {
			var out = [];
			backgroundDecls( s, out );
			spacingDecls( s, out );
			borderDecls( s, out );
			boxShadowDecls( s, out );
			decl( out, 'min-height', sizeValue( s.min_height ) );
			decl( out, 'flex-direction', s.flex_direction );
			decl( out, 'justify-content', s.flex_justify_content || s.justify_content );
			decl( out, 'align-items', s.flex_align_items || s.align_items );
			var gap = s.flex_gap || s.gap;
			if ( gap && 'object' === typeof gap ) {
				decl( out, 'gap', sizeValue( gap ) );
			}
			// Rows wrap so multi-card layouts don't overflow narrow previews.
			if ( 'row' === s.flex_direction && 'nowrap' !== s.flex_wrap ) {
				out.push( 'flex-wrap:wrap' );
			}
			// Boxed content / explicit max-width: cap and center (Elementor "boxed").
			var maxW = sizeValue( s.max_width );
			if ( ! maxW && 'boxed' === s.content_width ) {
				maxW = '1140px';
			}
			if ( maxW ) {
				out.push( 'max-width:' + maxW );
				out.push( 'width:100%' );
				out.push( 'margin-left:auto' );
				out.push( 'margin-right:auto' );
			}
			decl( out, 'text-align', s.align );
			return out.join( ';' );
		}

		// Styles for text-like widgets (heading, text-editor): color, typography,
		// plus any box styling (background, border, padding) — text widgets are
		// commonly skinned as quote/callout boxes via these settings.
		function textStyle( s, colorKey ) {
			var out = [];
			decl( out, 'color', s[ colorKey ] || s.color || s.title_color || s.text_color );
			typographyDecls( s, out );
			backgroundDecls( s, out );
			borderDecls( s, out );
			boxShadowDecls( s, out );
			spacingDecls( s, out );
			decl( out, 'text-align', s.align );
			return out.join( ';' );
		}

		function styleAttr( style ) {
			return style ? ' style="' + style + '"' : '';
		}

		// Wrapper carries layout (alignment); styling lives on the inner element.
		function wrapperStyle( s ) {
			var out = [];
			decl( out, 'text-align', s.align );
			spacingDecls( s, out );
			return out.join( ';' );
		}

		function renderWidget( el ) {
			var s = el.settings || {};
			var id = elId( el );
			var idAttr = id ? ' id="' + id + '"' : '';

			switch ( el.widgetType ) {
				case 'heading':
					// Models vary on the field name: title (Elementor canonical) or text.
					var tag = headingTag( s.header_size || s.size );
					collectResponsive( id, s, function ( v ) { return textStyle( v, 'title_color' ); } );
					return '<' + tag + idAttr + styleAttr( textStyle( s, 'title_color' ) ) + '>' +
						esc( s.title || s.text || '' ) + '</' + tag + '>';

				case 'text-editor':
					// Editor markup comes from a validated provider response. Accept the
					// canonical "editor" key plus the "content"/"text" variants models emit.
					collectResponsive( id, s, function ( v ) { return textStyle( v, 'text_color' ); } );
					return '<div' + idAttr + styleAttr( textStyle( s, 'text_color' ) ) + '>' +
						( s.editor || s.content || s.text || '' ) + '</div>';

				case 'button':
					var btnText = s.button_text || s.text || 'Button';
					var link = '';
					if ( s.button_link ) {
						link = ( 'object' === typeof s.button_link ) ? ( s.button_link.url || '' ) : s.button_link;
					} else if ( s.link && 'object' === typeof s.link ) {
						link = s.link.url || '';
					}
					// id lives on the <a> so font/padding overrides target the button.
					collectResponsive( id, s, buttonStyle );
					return '<div' + styleAttr( wrapperStyle( s ) ) + '><a class="aieb-btn"' + idAttr +
						styleAttr( buttonStyle( s ) ) + ' href="' + esc( link || '#' ) + '">' +
						esc( btnText ) + '</a></div>';

				case 'image':
					var src = '';
					if ( s.image && 'object' === typeof s.image ) {
						src = s.image.url || '';
					} else if ( 'string' === typeof s.image ) {
						src = s.image;
					} else if ( s.url ) {
						src = s.url;
					} else if ( s.src ) {
						src = s.src;
					}
					if ( ! src ) {
						return '';
					}
					var imgStyle = [];
					decl( imgStyle, 'width', sizeValue( s.width ) );
					decl( imgStyle, 'border-radius', dimension( s.border_radius ) );
					return '<div' + styleAttr( wrapperStyle( s ) ) + '><img src="' + esc( src ) +
						'" alt="' + esc( s.alt || '' ) + '"' + styleAttr( imgStyle.join( ';' ) ) + ' /></div>';

				case 'icon-list':
					var items = Array.isArray( s.icon_list ) ? s.icon_list : [];
					if ( ! items.length ) {
						return '';
					}
					var lis = items.map( function ( item ) {
						var txt = item && ( item.text || item.title ) || '';
						return '<li>' + esc( txt ) + '</li>';
					} ).join( '' );
					var listStyle = [];
					decl( listStyle, 'color', s.icon_color || s.text_color );
					return '<ul' + styleAttr( ( wrapperStyle( s ) + ';' + listStyle.join( ';' ) ).replace( /^;|;$/g, '' ) ) + '>' + lis + '</ul>';

				case 'spacer':
					var h = sizeValue( s.space_height || s.space, 'px' ) || '50px';
					return '<div style="height:' + esc( h ) + ';" aria-hidden="true"></div>';

				case 'divider':
					var hr = [];
					decl( hr, 'border-top-color', s.color );
					decl( hr, 'border-top-width', sizeValue( s.weight ) );
					return '<hr' + styleAttr( hr.join( ';' ) ) + ' />';

				case 'blockquote':
					var quote = s.blockquote_content || s.editor || s.content || s.text || '';
					var author = s.author_name || '';
					var bq = [];
					decl( bq, 'color', s.blockquote_content_color || s.text_color || s.color );
					// Default to a left-accent "border" skin when no explicit border given.
					if ( s.border_border && 'none' !== s.border_border ) {
						borderDecls( s, bq );
					} else {
						bq.push( 'border-left:4px solid ' + ( s.blockquote_border_color || s.border_color || '#10b981' ) );
					}
					backgroundDecls( s, bq );
					bq.push( 'padding:' + ( dimension( s.padding ) || '20px 24px' ) );
					decl( bq, 'border-radius', dimension( s.border_radius ) );
					typographyDecls( s, bq );
					decl( bq, 'margin', dimension( s.margin ) );
					decl( bq, 'text-align', s.align || s.blockquote_alignment );
					var cite = author
						? '<cite style="display:block;margin-top:8px;font-style:normal;font-weight:600;">— ' + esc( author ) + '</cite>'
						: '';
					return '<blockquote class="aieb-quote"' + styleAttr( bq.join( ';' ) ) + '>' + esc( quote ) + cite + '</blockquote>';

				case 'icon-box':
					var ibTitle = s.title_text || s.title || s.text || '';
					var ibDesc = s.description_text || s.description || s.editor || s.content || '';
					var ibWrap = [];
					decl( ibWrap, 'text-align', s.align || 'center' );
					spacingDecls( s, ibWrap );
					var ibTitleStyle = [];
					decl( ibTitleStyle, 'color', s.title_color );
					var ibDescStyle = [];
					decl( ibDescStyle, 'color', s.description_color || s.text_color );
					return '<div' + idAttr + styleAttr( ibWrap.join( ';' ) ) + '>' +
						iconCircle( s.primary_color || s.icon_color ) +
						( ibTitle ? '<div class="aieb-ib-title"' + styleAttr( ibTitleStyle.join( ';' ) ) + '>' + esc( ibTitle ) + '</div>' : '' ) +
						( ibDesc ? '<div class="aieb-ib-desc"' + styleAttr( ibDescStyle.join( ';' ) ) + '>' + esc( ibDesc ) + '</div>' : '' ) +
						'</div>';

				case 'icon':
				case 'icons':
					var icWrap = [];
					decl( icWrap, 'text-align', s.align || 'center' );
					return '<div' + idAttr + styleAttr( icWrap.join( ';' ) ) + '>' +
						iconCircle( s.primary_color || s.color || s.icon_color ) + '</div>';

				default:
					var label = s.title || s.text || s.editor || s.content || el.widgetType || '';
					collectResponsive( id, s, function ( v ) { return textStyle( v, 'text_color' ); } );
					return '<div' + idAttr + styleAttr( textStyle( s, 'text_color' ) ) + '>' + esc( label ) + '</div>';
			}
		}

		// A circular icon placeholder. The Elementor icon library can't be mapped
		// 1:1 in preview, so we render a tinted circle with a generic glyph — enough
		// for feature/value cards to read as designed rather than empty.
		function iconCircle( color ) {
			return '<span class="aieb-ico-c" style="background:' + ( color || '#4f46e5' ) + ';">' +
				'<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/></svg>' +
				'</span>';
		}

		// Button styling lives on the <a>: background, color, padding, radius, type.
		function buttonStyle( s ) {
			var out = [];
			decl( out, 'background-color', s.background_color || s.button_background_color );
			decl( out, 'color', s.button_text_color || s.text_color || s.color );
			decl( out, 'padding', dimension( s.text_padding || s.padding ) );
			borderDecls( s, out );
			boxShadowDecls( s, out );
			typographyDecls( s, out );
			return out.join( ';' );
		}

		function headingTag( size ) {
			var allowed = { h1: 1, h2: 1, h3: 1, h4: 1, h5: 1, h6: 1 };
			var tag = ( '' + ( size || 'h2' ) ).toLowerCase();
			return allowed[ tag ] ? tag : 'h2';
		}

		function previewDocument( bodyHtml ) {
			// Responsive overrides gathered in the last elementorJsonToHtml() pass.
			// Tablet first so mobile (narrower) wins where both match.
			var media = '';
			if ( rspTablet.length ) {
				media += '@media (max-width:1024px){' + rspTablet.join( '' ) + '}';
			}
			if ( rspMobile.length ) {
				media += '@media (max-width:767px){' + rspMobile.join( '' ) + '}';
			}
			return '<!DOCTYPE html><html><head><meta charset="utf-8">' +
				'<meta name="viewport" content="width=device-width, initial-scale=1">' +
				'<style>' +
				'*{box-sizing:border-box;}' +
				'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;margin:0;padding:0;color:#1d2327;line-height:1.5;}' +
				// Elementor containers are flex columns by default; inline styles override.
				'.aieb-c{display:flex;flex-direction:column;min-width:0;}' +
				// Row children share width but keep a sensible min basis so they wrap
				// and stack on narrow/mobile widths instead of squishing into slivers.
				'.aieb-c[style*="flex-direction:row"]>.aieb-c{flex:1 1 260px;}' +
				// Below mobile width, force any row to stack even without _mobile keys.
				'@media (max-width:600px){.aieb-c[style*="flex-direction:row"]{flex-direction:column!important;}}' +
				'h1,h2,h3,h4,h5,h6,p{margin:0 0 12px;}' +
				'img{max-width:100%;height:auto;}' +
				// Icon placeholder circle + icon-box text (inline color overrides apply).
				'.aieb-ico-c{display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:14px;margin-bottom:14px;}' +
				'.aieb-ico-c svg{width:26px;height:26px;}' +
				'.aieb-ib-title{font-size:19px;font-weight:700;margin-bottom:8px;}' +
				'.aieb-ib-desc{font-size:15px;opacity:.85;}' +
				// Base button look; inline color/background from settings overrides these.
				'.aieb-btn{display:inline-block;background:#2271b1;color:#fff;padding:12px 24px;border-radius:4px;text-decoration:none;}' +
				// Base quote look; inline border/background from settings overrides these.
				'.aieb-quote{border-left:4px solid #10b981;background:#ecfdf5;padding:20px 24px;margin:0 0 16px;border-radius:4px;}' +
				'.aieb-quote:before{content:"\\201C";color:#10b981;font-size:28px;font-weight:700;line-height:0;vertical-align:-8px;margin-right:6px;}' +
				'ul{padding-left:20px;}' +
				media +
				'</style></head><body>' + ( bodyHtml || '' ) + '</body></html>';
		}
	} );
} )();
