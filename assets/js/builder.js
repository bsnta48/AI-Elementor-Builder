/**
 * AI Elementor Builder — Builder page behavior (vanilla JS, no jQuery).
 *
 * Reads config from window.AIEB injected via wp_localize_script:
 *   { restUrl, nonce, i18n }
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
			section: root.querySelector( '#aieb-section-type' ),
			reference: root.querySelector( '#aieb-reference' ),
			referenceDesc: root.querySelector( '#aieb-reference-desc' ),
			templates: root.querySelectorAll( '.aieb-template-btn' ),
			image: root.querySelector( '#aieb-image' ),
			imagePreview: root.querySelector( '#aieb-image-preview' ),
			imageThumb: root.querySelector( '#aieb-image-thumb' ),
			imageRemove: root.querySelector( '#aieb-image-remove' ),
			generate: root.querySelector( '#aieb-generate' ),
			frame: root.querySelector( '#aieb-preview-frame' ),
			json: root.querySelector( '#aieb-json' ),
			toggle: root.querySelector( '#aieb-toggle-json' ),
			fullscreen: root.querySelector( '#aieb-fullscreen' ),
			previewPane: root.querySelector( '.aieb-preview-pane' ),
			overlay: root.querySelector( '#aieb-overlay' ),
			notice: root.querySelector( '#aieb-notice' ),
			noticeMsg: root.querySelector( '#aieb-notice-message' ),
			noticeDismiss: root.querySelector( '#aieb-notice-dismiss' ),
			pageSelect: root.querySelector( '#aieb-page-select' ),
			push: root.querySelector( '#aieb-push' ),
			pushStatus: root.querySelector( '#aieb-push-status' ),
			historyList: root.querySelector( '#aieb-history-list' ),
			download: root.querySelector( '#aieb-download' ),
			downloadStatus: root.querySelector( '#aieb-download-status' ),
			templateType: root.querySelector( '#aieb-template-type' ),
			templateTitle: root.querySelector( '#aieb-template-title' ),
			templateHistoryList: root.querySelector( '#aieb-template-history-list' ),
			pushTemplate: root.querySelector( '#aieb-push-template' ),
			pushTemplateStatus: root.querySelector( '#aieb-push-template-status' )
		};

		// state.image: { data: base64-without-prefix, mime: string } or null.
		var state = { template: null, showingJson: false, image: null };
		var history = Array.isArray( cfg.history ) ? cfg.history.slice() : [];

		var TEMPLATE_HISTORY_KEY = 'aeb_template_history';
		var templateHistory = loadTemplateHistory();

		// Cap reference images at 5 MB to keep request payloads sane.
		var MAX_IMAGE_BYTES = 5 * 1024 * 1024;

		els.generate.addEventListener( 'click', onGenerate );
		els.toggle.addEventListener( 'click', onToggleJson );
		if ( els.fullscreen ) {
			els.fullscreen.addEventListener( 'click', toggleFullscreen );
		}
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && els.previewPane && els.previewPane.classList.contains( 'aieb-fs' ) ) {
				toggleFullscreen();
			}
		} );
		if ( els.noticeDismiss ) {
			els.noticeDismiss.addEventListener( 'click', hideNotice );
		}

		Array.prototype.forEach.call( els.templates, function ( btn ) {
			btn.addEventListener( 'click', function () {
				onTemplate( btn );
			} );
		} );
		if ( els.image ) {
			els.image.addEventListener( 'change', onImageChange );
		}
		if ( els.imageRemove ) {
			els.imageRemove.addEventListener( 'click', clearImage );
		}
		els.push.addEventListener( 'click', onPush );
		els.pageSelect.addEventListener( 'change', function () {
			setPushStatus( '' );
			refreshPushState();
		} );
		if ( els.download ) {
			els.download.addEventListener( 'click', onDownload );
		}
		if ( els.pushTemplate ) {
			els.pushTemplate.addEventListener( 'click', onPushTemplate );
		}

		loadPages();
		renderHistory();
		renderTemplateHistory();
		populateReferences();

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
			var checked = root.querySelector( 'input[name="aieb_provider"]:checked' );
			return checked ? checked.value : '';
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
			var prompt = btn.getAttribute( 'data-prompt' ) || '';
			var section = btn.getAttribute( 'data-section' ) || '';
			els.prompt.value = prompt;
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
			hideNotice();
			var file = els.image.files && els.image.files[ 0 ];
			if ( ! file ) {
				clearImage();
				return;
			}
			if ( file.size > MAX_IMAGE_BYTES ) {
				showNotice( t( 'imageTooLarge', 'Image is too large (max 5 MB).' ) );
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
			};
			reader.onerror = function () {
				showNotice( t( 'imageReadError', 'Could not read the selected image.' ) );
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
		}

		function onGenerate() {
			hideNotice();

			var rawPrompt = ( els.prompt.value || '' ).trim();
			if ( ! rawPrompt ) {
				showNotice( t( 'emptyPrompt', 'Please enter a design prompt.' ) );
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
						showNotice( msg );
						return;
					}
					renderTemplate( result.data.template );
					addHistoryEntry( {
						prompt: rawPrompt,
						provider: provider,
						json: result.data.template,
						timestamp: Math.floor( Date.now() / 1000 )
					} );
				} )
				.catch( function () {
					showNotice( t( 'networkError', 'Network error. Could not reach the server.' ) );
				} )
				.finally( function () {
					setLoading( false );
				} );
		}

		function renderTemplate( template ) {
			state.template = template;

			els.json.textContent = JSON.stringify( template, null, 2 );
			els.frame.srcdoc = previewDocument( elementorJsonToHtml( template ) );

			// Reset to preview view after a fresh render.
			state.showingJson = false;
			applyView();

			// A template now exists — allow pushing.
			refreshPushState();
		}

		function applyView() {
			els.frame.classList.toggle( 'aieb-hidden', state.showingJson );
			els.json.classList.toggle( 'aieb-hidden', ! state.showingJson );
			els.toggle.textContent = state.showingJson
				? t( 'viewPreview', 'View Preview' )
				: t( 'viewJson', 'View JSON' );
		}

		function onToggleJson() {
			state.showingJson = ! state.showingJson;
			applyView();
		}

		function toggleFullscreen() {
			if ( ! els.previewPane ) {
				return;
			}
			var on = els.previewPane.classList.toggle( 'aieb-fs' );
			document.body.classList.toggle( 'aieb-fs-lock', on );
			els.fullscreen.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			var icon = els.fullscreen.querySelector( '.dashicons' );
			if ( icon ) {
				icon.classList.toggle( 'dashicons-fullscreen-alt', ! on );
				icon.classList.toggle( 'dashicons-fullscreen-exit-alt', on );
			}
		}

		function setLoading( loading ) {
			els.overlay.classList.toggle( 'aieb-hidden', ! loading );
			els.generate.disabled = loading;
		}

		function showNotice( message ) {
			els.noticeMsg.textContent = message;
			els.notice.classList.remove( 'aieb-hidden' );
		}

		function hideNotice() {
			els.notice.classList.add( 'aieb-hidden' );
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
				setPushStatus( t( 'noTemplate', 'Generate a template before pushing.' ), true );
				return;
			}
			var pageId = parseInt( els.pageSelect.value, 10 );
			if ( ! pageId ) {
				setPushStatus( t( 'noPage', 'Select a target page.' ), true );
				return;
			}

			els.push.disabled = true;
			setPushStatus( '' );

			wp.apiFetch( {
				url: cfg.pushUrl,
				method: 'POST',
				data: { page_id: pageId, elementor_json: state.template }
			} )
				.then( function ( res ) {
					var link = res && res.edit_url
						? ' <a href="' + esc( res.edit_url ) + '" target="_blank" rel="noopener">' + esc( t( 'editInElementor', 'Edit in Elementor' ) ) + '</a>'
						: '';
					els.pushStatus.innerHTML = '<span class="aieb-ok">' + esc( t( 'pushed', 'Pushed to Elementor.' ) ) + '</span>' + link;
				} )
				.catch( function ( err ) {
					var msg = ( err && err.message ) ? err.message : t( 'pushFailed', 'Could not push to Elementor.' );
					setPushStatus( msg, true );
				} )
				.finally( function () {
					refreshPushState();
				} );
		}

		function setPushStatus( message, isError ) {
			els.pushStatus.textContent = message || '';
			els.pushStatus.classList.toggle( 'aieb-err', !! isError );
			els.pushStatus.classList.toggle( 'aieb-ok', false );
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

		function setDownloadStatus( message, isError ) {
			if ( ! els.downloadStatus ) {
				return;
			}
			els.downloadStatus.textContent = message || '';
			els.downloadStatus.classList.toggle( 'aieb-err', !! isError );
		}

		function onDownload() {
			setDownloadStatus( '' );

			var content = contentArray( state.template );
			if ( ! content || ! content.length ) {
				setDownloadStatus( t( 'downloadEmpty', 'Generate a design first before downloading.' ), true );
				return;
			}

			var title = ( els.templateTitle && els.templateTitle.value || '' ).trim();
			var type = ( els.templateType && els.templateType.value ) || 'page';

			downloadElementorTemplate( content, title, type );

			addTemplateHistoryEntry( {
				title: title || 'AI Generated Template',
				type: type,
				date: Math.floor( Date.now() / 1000 ),
				content: content
			} );
		}

		/* ---- Push directly into the Elementor Library (Saved Template) ---- */

		function setPushTemplateStatus( message, isError ) {
			if ( ! els.pushTemplateStatus ) {
				return;
			}
			els.pushTemplateStatus.textContent = message || '';
			els.pushTemplateStatus.classList.toggle( 'aieb-err', !! isError );
			els.pushTemplateStatus.classList.toggle( 'aieb-ok', false );
		}

		function onPushTemplate() {
			setPushTemplateStatus( '' );

			if ( ! state.template ) {
				setPushTemplateStatus( t( 'noTemplate', 'Generate a template before pushing.' ), true );
				return;
			}
			if ( ! cfg.pushTemplateUrl ) {
				setPushTemplateStatus( t( 'templateFailed', 'Could not save the template.' ), true );
				return;
			}

			var title = ( els.templateTitle && els.templateTitle.value || '' ).trim();
			var type = ( els.templateType && els.templateType.value ) || 'page';

			els.pushTemplate.disabled = true;
			setPushTemplateStatus( t( 'pushingTemplate', 'Saving template…' ) );

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
					var edit = res && res.edit_url
						? ' <a href="' + esc( res.edit_url ) + '" target="_blank" rel="noopener">' + esc( t( 'editInElementor', 'Edit in Elementor' ) ) + '</a>'
						: '';
					var lib = res && res.library_url
						? ' <a href="' + esc( res.library_url ) + '" target="_blank" rel="noopener">' + esc( t( 'openLibrary', 'Open Library' ) ) + '</a>'
						: '';
					els.pushTemplateStatus.innerHTML = '<span class="aieb-ok">' + esc( t( 'templateSaved', 'Saved to Elementor Library.' ) ) + '</span>' + edit + lib;
				} )
				.catch( function ( err ) {
					var msg = ( err && err.message ) ? err.message : t( 'templateFailed', 'Could not save the template.' );
					setPushTemplateStatus( msg, true );
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
				var empty = document.createElement( 'li' );
				empty.className = 'aieb-history-empty';
				empty.textContent = t( 'templateHistoryEmpty', 'No templates downloaded yet.' );
				els.templateHistoryList.appendChild( empty );
				return;
			}

			templateHistory.forEach( function ( entry, index ) {
				var li = document.createElement( 'li' );
				li.className = 'aieb-history-item';

				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'aieb-history-btn';
				btn.title = t( 'redownload', 'Download again' );

				var title = document.createElement( 'span' );
				title.className = 'aieb-history-prompt';
				title.textContent = entry.title || '(untitled)';

				var meta = document.createElement( 'span' );
				meta.className = 'aieb-history-meta';
				meta.textContent = ( entry.type || 'page' ) + ' · ' + formatTime( entry.date );

				btn.appendChild( title );
				btn.appendChild( meta );
				btn.addEventListener( 'click', function () {
					var item = templateHistory[ index ];
					if ( item ) {
						downloadElementorTemplate( item.content, item.title, item.type );
					}
				} );

				li.appendChild( btn );
				els.templateHistoryList.appendChild( li );
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
			els.historyList.innerHTML = '';

			if ( ! history.length ) {
				var empty = document.createElement( 'li' );
				empty.className = 'aieb-history-empty';
				empty.textContent = t( 'historyEmpty', 'No generations yet.' );
				els.historyList.appendChild( empty );
				return;
			}

			history.forEach( function ( entry, index ) {
				var li = document.createElement( 'li' );
				li.className = 'aieb-history-item';

				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'aieb-history-btn';
				btn.title = t( 'restore', 'Restore' );

				var prompt = document.createElement( 'span' );
				prompt.className = 'aieb-history-prompt';
				prompt.textContent = entry.prompt || '(no prompt)';

				var meta = document.createElement( 'span' );
				meta.className = 'aieb-history-meta';
				meta.textContent = ( entry.provider || '' ) + ' · ' + formatTime( entry.timestamp );

				btn.appendChild( prompt );
				btn.appendChild( meta );
				btn.addEventListener( 'click', function () {
					restoreEntry( history[ index ] );
				} );

				li.appendChild( btn );
				els.historyList.appendChild( li );
			} );
		}

		function restoreEntry( entry ) {
			if ( ! entry ) {
				return;
			}
			if ( entry.prompt ) {
				els.prompt.value = entry.prompt;
			}
			if ( entry.provider ) {
				var radio = root.querySelector( 'input[name="aieb_provider"][value="' + entry.provider + '"]' );
				if ( radio ) {
					radio.checked = true;
				}
			}
			if ( entry.json ) {
				renderTemplate( entry.json );
			}
		}

		function formatTime( ts ) {
			if ( ! ts ) {
				return '';
			}
			var d = new Date( ts * 1000 );
			return d.toLocaleString();
		}

		/* ---- Elementor JSON -> HTML preview ---- */

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
				return '<div class="aieb-c"' + ( style ? ' style="' + style + '"' : '' ) + '>' +
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

			switch ( el.widgetType ) {
				case 'heading':
					// Models vary on the field name: title (Elementor canonical) or text.
					var tag = headingTag( s.header_size || s.size );
					return '<' + tag + styleAttr( textStyle( s, 'title_color' ) ) + '>' +
						esc( s.title || s.text || '' ) + '</' + tag + '>';

				case 'text-editor':
					// Editor markup comes from a validated provider response. Accept the
					// canonical "editor" key plus the "content"/"text" variants models emit.
					return '<div' + styleAttr( textStyle( s, 'text_color' ) ) + '>' +
						( s.editor || s.content || s.text || '' ) + '</div>';

				case 'button':
					var btnText = s.button_text || s.text || 'Button';
					var link = '';
					if ( s.button_link ) {
						link = ( 'object' === typeof s.button_link ) ? ( s.button_link.url || '' ) : s.button_link;
					} else if ( s.link && 'object' === typeof s.link ) {
						link = s.link.url || '';
					}
					return '<div' + styleAttr( wrapperStyle( s ) ) + '><a class="aieb-btn"' +
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
					return '<div' + styleAttr( textStyle( s, 'text_color' ) ) + '>' + esc( label ) + '</div>';
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
			return '<!DOCTYPE html><html><head><meta charset="utf-8">' +
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
				'</style></head><body>' + ( bodyHtml || '' ) + '</body></html>';
		}
	} );
} )();
