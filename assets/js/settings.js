/**
 * AI Elementor Builder — Settings page behavior.
 *
 * Wires the "Test" buttons to the test-key REST endpoint, the Ollama "Test
 * connection" probe, plus the design-shell interactions: reveal toggles, sidenav
 * scrollspy, dirty tracking on the sticky savebar, and the mock-mode toggle.
 *
 * Reads config from window.AIEB_SETTINGS: { restUrl, ollamaTestUrl, nonce, i18n }
 */
( function () {
	'use strict';

	var cfg = window.AIEB_SETTINGS || {};
	var i18n = cfg.i18n || {};

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	var EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>';
	var EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17.9 17.9A10.4 10.4 0 0 1 12 19c-7 0-11-7-11-7a18.4 18.4 0 0 1 5.1-5.9M9.9 4.2A10.5 10.5 0 0 1 12 4c7 0 11 7 11 7a18.5 18.5 0 0 1-2.2 3.2M1 1l22 22M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';

	document.addEventListener( 'DOMContentLoaded', function () {
		var $ = function ( s, r ) { return ( r || document ).querySelector( s ); };
		var $$ = function ( s, r ) { return Array.prototype.slice.call( ( r || document ).querySelectorAll( s ) ); };

		var form = $( '#aieb-settings-form' );
		var saveStatus = $( '#aieb-save-status' );
		var dirty = false;

		/* ---- Test API key ---- */

		$$( '.aieb-test-key' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				onTest( btn );
			} );
		} );

		var ollamaBtn = $( '.aieb-ollama-test' );
		if ( ollamaBtn ) {
			ollamaBtn.addEventListener( 'click', function () {
				onOllamaTest( ollamaBtn );
			} );
		}

		function onTest( btn ) {
			var provider = btn.getAttribute( 'data-provider' );
			var field = btn.getAttribute( 'data-field' );
			var badge = document.getElementById( 'aieb-key-badge-' + provider );

			// Send the typed key only when it is a real value (not the saved mask).
			var data = { provider: provider };
			var input = field ? document.getElementById( field ) : null;
			var typed = input ? ( input.value || '' ).trim() : '';
			if ( typed && typed.indexOf( '•' ) === -1 ) {
				data.api_key = typed;
			}

			setBadge( badge, 'pending', t( 'testing', 'Testing…' ) );
			btn.disabled = true;

			fetch( cfg.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce
				},
				body: JSON.stringify( data )
			} )
				.then( function ( res ) {
					return res.json().then( function ( body ) {
						return { ok: res.ok, body: body };
					} );
				} )
				.then( function ( result ) {
					if ( result.ok && result.body && result.body.connected ) {
						setBadge( badge, 'ok', t( 'connected', 'Connected' ) );
						return;
					}
					var err = ( result.body && result.body.error )
						? result.body.error
						: ( result.body && result.body.message )
							? result.body.message
							: t( 'failed', 'Failed' );
					setBadge( badge, 'err', t( 'failedPrefix', 'Failed: ' ) + err );
				} )
				.catch( function () {
					setBadge( badge, 'err', t( 'failedPrefix', 'Failed: ' ) + t( 'networkError', 'Network error.' ) );
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		}

		function onOllamaTest( btn ) {
			var badge = document.getElementById( 'aieb-ollama-badge' );
			var urlInput = document.getElementById( 'ollama_url' );
			var url = urlInput ? ( urlInput.value || '' ).trim() : '';

			setBadge( badge, 'pending', t( 'testing', 'Testing…' ) );
			btn.disabled = true;

			var endpoint = cfg.ollamaTestUrl + ( url ? ( '?url=' + encodeURIComponent( url ) ) : '' );

			fetch( endpoint, {
				method: 'GET',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': cfg.nonce }
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( body ) {
					if ( body && body.connected ) {
						var models = Array.isArray( body.models ) ? body.models : [];
						var msg = t( 'connectedModels', 'Connected — %d models available' )
							.replace( '%d', models.length );
						setBadge( badge, 'ok', msg );
						populateModels( models );
						return;
					}
					var err = ( body && body.error ) ? body.error : t( 'cannotReach', 'Cannot reach Ollama — make sure it is running' );
					setBadge( badge, 'err', err );
				} )
				.catch( function () {
					setBadge( badge, 'err', t( 'cannotReach', 'Cannot reach Ollama — make sure it is running' ) );
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		}

		// Swap the plain model text input for a dropdown of pulled models, keeping
		// the same id/name so it still saves under aieb_settings[ollama_model].
		function populateModels( models ) {
			var current = document.getElementById( 'ollama_model' );
			if ( ! current || ! models.length ) {
				return;
			}

			var selected = current.value;
			var select = document.createElement( 'select' );
			select.id = 'ollama_model';
			select.name = current.getAttribute( 'name' );
			select.className = current.className.replace( 'mono', '' ).trim() || 'input';
			select.style.maxWidth = '280px';

			models.forEach( function ( name ) {
				var opt = document.createElement( 'option' );
				opt.value = name;
				opt.textContent = name;
				if ( name === selected ) {
					opt.selected = true;
				}
				select.appendChild( opt );
			} );

			select.addEventListener( 'change', markDirty );
			current.parentNode.replaceChild( select, current );
		}

		function setBadge( badge, kind, text ) {
			if ( ! badge ) {
				return;
			}
			badge.textContent = text;
			badge.className = 'aieb-key-badge aieb-key-badge--' + kind;
		}

		/* ---- Reveal key toggles ---- */

		$$( '.reveal' ).forEach( function ( b ) {
			b.innerHTML = EYE;
			b.addEventListener( 'click', function () {
				var inp = b.parentNode.querySelector( 'input' );
				if ( ! inp ) {
					return;
				}
				if ( 'password' === inp.type ) {
					inp.type = 'text';
					b.innerHTML = EYE_OFF;
				} else {
					inp.type = 'password';
					b.innerHTML = EYE;
				}
			} );
		} );

		/* ---- Dirty tracking + savebar ---- */

		function markDirty() {
			if ( dirty || ! saveStatus ) {
				return;
			}
			dirty = true;
			saveStatus.innerHTML = '<span class="dot warn"></span>' + t( 'unsaved', 'Unsaved changes' );
		}

		$$( 'input, select, textarea', form ).forEach( function ( el ) {
			el.addEventListener( 'input', markDirty );
			el.addEventListener( 'change', markDirty );
		} );

		// Drop the saved-mask flag once the user edits a key field, so a real value
		// is submitted instead of the bullets.
		$$( '.input.pw' ).forEach( function ( i ) {
			i.addEventListener( 'input', function () {
				if ( i.dataset.saved ) {
					i.removeAttribute( 'data-saved' );
				}
			} );
		} );

		var resetBtn = $( '#aieb-reset-btn' );
		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', function () {
				window.location.reload();
			} );
		}

		// Submit handler: clear the dirty flag so the savebar reflects the save.
		if ( form ) {
			form.addEventListener( 'submit', function () {
				dirty = false;
			} );
		}

		/* ---- Sidenav scrollspy + smooth scroll ---- */

		var navlinks = $$( '#aieb-sidenav a' );
		var sections = navlinks.map( function ( a ) { return $( a.getAttribute( 'href' ) ); } );

		navlinks.forEach( function ( a ) {
			a.addEventListener( 'click', function ( e ) {
				var el = $( a.getAttribute( 'href' ) );
				if ( ! el ) {
					return;
				}
				e.preventDefault();
				window.scrollTo( { top: el.offsetTop - 90, behavior: 'smooth' } );
			} );
		} );

		window.addEventListener( 'scroll', function () {
			var y = window.scrollY + 130;
			var cur = 0;
			sections.forEach( function ( s, i ) {
				if ( s && s.offsetTop <= y ) {
					cur = i;
				}
			} );
			navlinks.forEach( function ( a, i ) {
				a.classList.toggle( 'active', i === cur );
			} );
		}, { passive: true } );
	} );
} )();
