/**
 * Node-RED WordPress front end.
 *
 * Keeps every `.nrwp-data` element on the page fresh by polling the REST API. All keys
 * on the page are fetched with a single request, polling pauses while the tab is hidden,
 * and values are written as text (never HTML).
 *
 * Each updated element dispatches a bubbling `nrwp:update` event, so themes can react:
 *
 *     document.addEventListener( 'nrwp:update', function ( e ) {
 *         console.log( e.detail.key, e.detail.value, e.detail.previous );
 *     } );
 */
( function () {
	'use strict';

	var settings = window.nrwp || {};
	var interval = Math.max( 1000, parseInt( settings.interval, 10 ) || 3000 );
	var timer = null;
	var inFlight = false;

	function elements() {
		return Array.prototype.slice.call( document.querySelectorAll( '.nrwp-data[data-key]' ) );
	}

	function buildUrl( keys ) {
		var url = settings.endpoint;
		return url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'keys=' + encodeURIComponent( keys.join( ',' ) );
	}

	function toText( value ) {
		if ( value === null || value === undefined ) {
			return null;
		}
		return String( value );
	}

	function apply( els, data ) {
		els.forEach( function ( elm ) {
			var key = elm.getAttribute( 'data-key' );
			var value = Object.prototype.hasOwnProperty.call( data, key ) ? toText( data[ key ] ) : null;
			var previous = elm.hasAttribute( 'data-value' ) ? elm.getAttribute( 'data-value' ) : null;

			if ( value === previous ) {
				return;
			}

			if ( value === null ) {
				elm.removeAttribute( 'data-value' );
				elm.textContent = elm.getAttribute( 'data-fallback' ) || '';
			} else {
				elm.setAttribute( 'data-value', value );
				elm.textContent = value;
				// Kept for backwards compatibility with 0.x themes.
				document.body.setAttribute( 'nrwp-' + key, value );
			}

			elm.dispatchEvent(
				new CustomEvent( 'nrwp:update', {
					bubbles: true,
					detail: { key: key, value: value, previous: previous },
				} )
			);
		} );
	}

	function schedule() {
		clearTimeout( timer );
		if ( ! document.hidden ) {
			timer = setTimeout( update, interval );
		}
	}

	function update() {
		var els = elements();
		var keys = els
			.map( function ( elm ) {
				return elm.getAttribute( 'data-key' );
			} )
			.filter( function ( key, i, all ) {
				return key && all.indexOf( key ) === i;
			} );

		if ( ! keys.length || ! settings.endpoint || inFlight ) {
			schedule();
			return;
		}

		var headers = { Accept: 'application/json' };
		if ( settings.nonce ) {
			headers[ 'X-WP-Nonce' ] = settings.nonce;
		}

		inFlight = true;

		window
			.fetch( buildUrl( keys ), { headers: headers, credentials: 'same-origin', cache: 'no-store' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function ( data ) {
				apply( els, data || {} );
			} )
			.catch( function () {
				// Network hiccup or auth failure: keep the last known values and retry later.
			} )
			.then( function () {
				inFlight = false;
				schedule();
			} );
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			clearTimeout( timer );
		} else {
			update();
		}
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', schedule );
	} else {
		schedule();
	}
} )();
