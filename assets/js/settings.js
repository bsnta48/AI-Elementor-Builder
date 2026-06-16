/**
 * AI Elementor Builder — Settings page behavior.
 *
 * Wires the "Test API Key" buttons to the test-key REST endpoint and renders an
 * inline connected/failed badge. Reads config from window.AIEB_SETTINGS:
 *   { restUrl, nonce, i18n }
 */
( function () {
	'use strict';

	var cfg = window.AIEB_SETTINGS || {};
	var i18n = cfg.i18n || {};

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var buttons = document.querySelectorAll( '.aieb-test-key' );
		Array.prototype.forEach.call( buttons, function ( btn ) {
			btn.addEventListener( 'click', function () {
				onTest( btn );
			} );
		} );

		var ollamaBtn = document.querySelector( '.aieb-ollama-test' );
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
			select.className = 'regular-text';

			models.forEach( function ( name ) {
				var opt = document.createElement( 'option' );
				opt.value = name;
				opt.textContent = name;
				if ( name === selected ) {
					opt.selected = true;
				}
				select.appendChild( opt );
			} );

			current.parentNode.replaceChild( select, current );
		}

		function setBadge( badge, kind, text ) {
			if ( ! badge ) {
				return;
			}
			badge.textContent = text;
			badge.className = 'aieb-key-badge aieb-key-badge--' + kind;
		}
	} );
} )();
