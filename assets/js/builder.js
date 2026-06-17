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
			section: root.querySelector( '#aieb-section-type' ),
			reference: root.querySelector( '#aieb-reference' ),
			referenceDesc: root.querySelector( '#aieb-reference-desc' ),
			templates: root.querySelectorAll( '.aieb-template-btn' ),
			provGrid: root.querySelector( '#aieb-prov-grid' ),
			modelName: root.querySelector( '#aieb-model-name' ),
			image: root.querySelector( '#aieb-image' ),
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
		var state = { template: null, showingJson: false, image: null, provider: '', fullscreen: false };

		// state.image: { data: base64-without-prefix, mime: string } or null.
		var state = { template: null, showingJson: false, image: null, provider: '' };
		var history = Array.isArray( cfg.history ) ? cfg.history.slice() : [];

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
		renderHistory();
		renderTemplateHistory();
		populateReferences();
		updateCharCount();

		function bindEvents() {
			els.generate.addEventListener( 'click', onGenerate );
			els.toggle.addEventListener( 'click', onToggleJson );
			els.prompt.addEventListener( 'input', updateCharCount );

			Array.prototype.forEach.call( els.templates, function ( btn ) {
				btn.addEventListener( 'click', function () {
					onTemplate( btn );
				} );
			} );

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

			// Empty-state seed chips.
			Array.prototype.forEach.call( els.empty.querySelectorAll( '[data-seed]' ), function ( chip ) {
				chip.addEventListener( 'click', function () {
					els.prompt.value = chip.getAttribute( 'data-seed' );
					updateCharCount();
					switchTab( 'compose' );
					els.prompt.focus();
				} );
			} );

			if ( els.image ) {
				els.image.addEventListener( 'change', onImageChange );
			}
			if ( els.imageRemove ) {
				els.imageRemove.addEventListener( 'click', clearImage );
			}
			if ( els.histSearch ) {
				els.histSearch.addEventListener( 'input', function () {
					renderHistory();
				} );
			}

			els.push.addEventListener( 'click', onPush );
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

		function populateReferences() {
			if ( ! els.reference ) {
				return;
			}
			var refs = Array.isArray( cfg.references ) ? cfg.references : [];
			refs.forEach( function ( ref ) {
				var opt = document.createElement( 'option' );
				opt.value = ref.id;
				opt.textContent = ref.name || ref.id;
				els.reference.appendChild( opt );
			} );
			els.reference.addEventListener( 'change', function () {
				if ( ! els.referenceDesc ) {
					return;
				}
				var found = refs.filter( function ( r ) {
					return r.id === els.reference.value;
				} )[ 0 ];
				els.referenceDesc.textContent = found ? ( found.description || '' ) : '';
			} );
		}

		function selectedProvider() {
			return state.provider || '';
		}

		function buildPrompt() {
			var section = els.section.value;
			var text = ( els.prompt.value || '' ).trim();
			if ( 'fullpage' === section ) {
				return 'Generate a COMPLETE, multi-section Elementor landing page (not a single section). ' +
					'Include several stacked top-level section containers — typically a hero, then features, ' +
					'about, testimonials, pricing or call-to-action, and a footer-style closing section — each ' +
					'as its own top-level container with appropriate content and styling. ' + text;
			}
			if ( 'custom' === section || ! section ) {
				return text;
			}
			return 'Generate an Elementor "' + section + '" section. ' + text;
		}

		/* ---- Prompt templates ---- */

		function onTemplate( btn ) {
			Array.prototype.forEach.call( els.templates, function ( x ) {
				x.classList.remove( 'active' );
			} );
			btn.classList.add( 'active' );

			var prompt = btn.getAttribute( 'data-prompt' ) || '';
			var section = btn.getAttribute( 'data-section' ) || '';
			els.prompt.value = prompt;
			updateCharCount();
			if ( section ) {
				var opt = els.section.querySelector( 'option[value="' + section + '"]' );
				if ( opt ) {
					els.section.value = section;
				}
			}
			els.prompt.focus();
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
				els.refName.textContent = t( 'dropImage', 'Drop a screenshot or mockup' );
			}
		}

		/* ---- Generate ---- */

		function onGenerate() {
			var rawPrompt = ( els.prompt.value || '' ).trim();
			if ( ! rawPrompt ) {
				toast( t( 'emptyPrompt', 'Please enter a design prompt.' ), false );
				switchTab( 'compose' );
				els.prompt.focus();
				return;
			}

			var provider = selectedProvider();
			setLoading( true );

			var payload = {
				provider: provider,
				prompt: buildPrompt()
			};
			if ( els.reference && els.reference.value ) {
				payload.reference = els.reference.value;
			}
			if ( state.image && state.image.data ) {
				payload.image = state.image.data;
				payload.image_mime = state.image.mime;
			}

			fetch( cfg.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce
				},
				body: JSON.stringify( payload )
			} )
				.then( function ( res ) {
					return res.json().then( function ( data ) {
						return { ok: res.ok, data: data };
					} );
				} )
				.then( function ( result ) {
					if ( ! result.ok ) {
						var msg = ( result.data && result.data.message )
							? result.data.message
							: t( 'genericError', 'Generation failed. Please try again.' );
						toast( msg, false );
						showState();
						return;
					}
					renderTemplate( result.data.template );
					toast( t( 'generated', 'Layout generated.' ), true );
					if ( els.templateTitle && ! els.templateTitle.value ) {
						els.templateTitle.value = 'AI Generated — ' + new Date().toLocaleDateString();
					}
					addHistoryEntry( {
						prompt: rawPrompt,
						provider: provider,
						json: result.data.template,
						timestamp: Math.floor( Date.now() / 1000 )
					} );
				} )
				.catch( function () {
					toast( t( 'networkError', 'Network error. Could not reach the server.' ), false );
					showState();
				} )
				.finally( function () {
					setLoading( false );
				} );
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
			if ( els.download ) {
				els.download.disabled = ! hasTemplate;
			}
			if ( els.pushTemplate ) {
				els.pushTemplate.disabled = ! hasTemplate;
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

		/* ---- Generation history ---- */

		function addHistoryEntry( entry ) {
			history.unshift( entry );
			history = history.slice( 0, 10 );
			renderHistory();
		}

		function renderHistory() {
			if ( ! els.historyList ) {
				return;
			}
			if ( els.histCount ) {
				els.histCount.textContent = history.length;
			}
			els.historyList.innerHTML = '';

			var filter = els.histSearch ? ( els.histSearch.value || '' ).toLowerCase() : '';
			var shown = history.filter( function ( h ) {
				return ! filter || ( h.prompt || '' ).toLowerCase().indexOf( filter ) > -1;
			} );

			if ( ! shown.length ) {
				els.historyList.appendChild( emptyListItem( t( 'historyEmpty', 'No generations yet.' ) ) );
				return;
			}

			shown.forEach( function ( entry ) {
				var btn = listItem(
					entry.prompt || '(no prompt)',
					entry.provider || '',
					formatTime( entry.timestamp ),
					t( 'restore', 'Restore' )
				);
				btn.addEventListener( 'click', function () {
					restoreEntry( entry );
				} );
				els.historyList.appendChild( btn );
			} );
		}

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

		function restoreEntry( entry ) {
			if ( ! entry ) {
				return;
			}
			if ( entry.prompt ) {
				els.prompt.value = entry.prompt;
				updateCharCount();
			}
			if ( entry.provider ) {
				selectProviderByKey( entry.provider );
			}
			if ( entry.json ) {
				renderTemplate( entry.json );
			}
			switchTab( 'compose' );
			toast( t( 'restored', 'Prompt loaded into composer.' ), true );
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
			decl( out, 'min-height', sizeValue( s.min_height ) );
			decl( out, 'flex-direction', s.flex_direction );
			decl( out, 'justify-content', s.flex_justify_content || s.justify_content );
			decl( out, 'align-items', s.flex_align_items || s.align_items );
			var gap = s.flex_gap || s.gap;
			if ( gap && 'object' === typeof gap ) {
				decl( out, 'gap', sizeValue( gap ) );
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

				default:
					var label = s.title || s.text || s.editor || s.content || el.widgetType || '';
					collectResponsive( id, s, function ( v ) { return textStyle( v, 'text_color' ); } );
					return '<div' + idAttr + styleAttr( textStyle( s, 'text_color' ) ) + '>' + esc( label ) + '</div>';
			}
		}

		// Button styling lives on the <a>: background, color, padding, radius, type.
		function buttonStyle( s ) {
			var out = [];
			decl( out, 'background-color', s.background_color || s.button_background_color );
			decl( out, 'color', s.button_text_color || s.text_color || s.color );
			decl( out, 'padding', dimension( s.text_padding || s.padding ) );
			borderDecls( s, out );
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
				'.aieb-c{display:flex;flex-direction:column;}' +
				'h1,h2,h3,h4,h5,h6,p{margin:0 0 12px;}' +
				'img{max-width:100%;height:auto;}' +
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
