/* Aluleth Core – contact import screens. No dependencies. */
( function () {
	'use strict';
	var cfg = window.alulethImport || {};
	var t = cfg.i18n || {};

	function qa( root, sel ) { return Array.prototype.slice.call( root.querySelectorAll( sel ) ); }
	function fmt( n ) { try { return Number( n ).toLocaleString(); } catch ( e ) { return String( n ); } }

	// Step 2: show the field name only for custom fields, dim skipped columns,
	// and ask for the consent statement only when it matters.
	var match = document.querySelector( '.al-import-match' );
	if ( match ) {
		var consent = match.querySelector( '.al-consent' );
		var refresh = function () {
			var hasStatus = false;
			qa( match, '.al-map-select' ).forEach( function ( select ) {
				var row = select.closest( 'tr' );
				var key = row.querySelector( '.al-map-key' );
				key.hidden = select.value !== 'meta';
				row.classList.toggle( 'is-off', select.value === '' );
				hasStatus = hasStatus || select.value === 'status' || select.value === 'optout';
			} );
			var picked = match.querySelector( 'input[name="status"]:checked' );
			var needs = ( picked && picked.value === 'subscribed' ) || hasStatus;
			if ( consent ) {
				consent.hidden = ! needs;
				consent.classList.toggle( 'is-required', !! ( picked && picked.value === 'subscribed' ) );
			}
		};
		match.addEventListener( 'change', refresh );
		refresh();
		match.addEventListener( 'submit', function ( e ) {
			var picked = match.querySelector( 'input[name="status"]:checked' );
			var box = consent && consent.querySelector( 'input' );
			if ( picked && picked.value === 'subscribed' && box && ! box.checked ) {
				e.preventDefault();
				consent.classList.add( 'has-error' );
				box.focus();
			}
		} );
	}

	// Step 1: "Name <address>" (copied from an email app) becomes "Name address", so the
	// address is not taken for an HTML tag when the site cleans the pasted text.
	var source = document.querySelector( '.al-import-source' );
	if ( source ) {
		source.addEventListener( 'submit', function () {
			var paste = source.querySelector( 'textarea[name="paste"]' );
			if ( paste ) {
				paste.value = paste.value.replace( /<([^<>\s]+@[^<>\s]+)>/g, ' $1 ' );
			}
		} );
	}

	// Step 3: import batch after batch.
	var run = document.querySelector( '.al-run' );
	if ( run && window.fetch && window.FormData ) {
		var bar = run.querySelector( '.al-progress span' );
		var meter = run.querySelector( '.al-progress' );
		var text = run.querySelector( '.al-progress-text' );
		var error = run.querySelector( '.al-run-error' );
		var fallback = run.querySelector( '.al-run-fallback' );
		var retries = 0;
		fallback.hidden = true;

		var next = function () {
			var body = new FormData();
			body.append( 'action', 'aluleth_import_run' );
			body.append( 'job', run.getAttribute( 'data-job' ) );
			body.append( '_ajax_nonce', run.getAttribute( 'data-nonce' ) );
			fetch( cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( ! res || ! res.success ) { throw new Error( 'failed' ); }
					retries = 0;
					var d = res.data;
					var pct = d.total ? Math.min( 100, Math.floor( d.done * 100 / d.total ) ) : 100;
					bar.style.width = pct + '%';
					meter.setAttribute( 'aria-valuenow', pct );
					text.textContent = ( t.progress || '%1$s / %2$s' ).replace( '%1$s', fmt( Math.min( d.done, d.total ) ) ).replace( '%2$s', fmt( d.total ) );
					if ( d.state === 'done' ) {
						window.location.href = run.getAttribute( 'data-done-url' );
						return;
					}
					next();
				} )
				.catch( function () {
					if ( retries < 3 ) {
						retries++;
						window.setTimeout( next, 1500 * retries );
						return;
					}
					error.textContent = t.error || '';
					error.hidden = false;
					fallback.hidden = false;
				} );
		};
		next();
	}
}() );
