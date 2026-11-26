/* Aluleth SMTP – routing rules and small helpers. */
( function () {
	'use strict';
	var cfg = window.alulethSmtp || {};
	var list = document.querySelector( '[data-alsmtp-rules]' );
	var tpl = document.querySelector( '[data-alsmtp-rule-template]' );
	var next = list ? list.querySelectorAll( '[data-alsmtp-rule]' ).length : 0;
	document.addEventListener( 'click', function ( e ) {
		var add = e.target.closest && e.target.closest( '[data-alsmtp-add-rule]' );
		if ( add && list && tpl ) {
			var html = tpl.innerHTML.replace( /__i__/g, String( next++ ) );
			list.insertAdjacentHTML( 'beforeend', html );
			var empty = document.querySelector( '[data-alsmtp-no-rules]' );
			if ( empty ) { empty.hidden = true; }
			var fields = list.lastElementChild ? list.lastElementChild.querySelector( 'input,select' ) : null;
			if ( fields ) { fields.focus(); }
			return;
		}
		var copy = e.target.closest && e.target.closest( '[data-alsmtp-copy]' );
		if ( copy ) {
			var src = document.getElementById( copy.getAttribute( 'data-alsmtp-copy' ) );
			if ( src ) {
				var text = 'value' in src ? src.value : src.textContent;
				var done = function () {
					var old = copy.textContent;
					copy.textContent = cfg.copied || 'Copied';
					window.setTimeout( function () { copy.textContent = old; }, 1600 );
				};
				var fallback = function () {
					if ( src.select ) {
						src.select();
						try { document.execCommand( 'copy' ); done(); } catch ( err ) {}
					} else {
						var range = document.createRange();
						range.selectNodeContents( src );
						var sel = window.getSelection();
						sel.removeAllRanges();
						sel.addRange( range );
						try { document.execCommand( 'copy' ); done(); } catch ( err ) {}
					}
				};
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( text ).then( done, fallback );
				} else {
					fallback();
				}
			}
			return;
		}
		var remove = e.target.closest && e.target.closest( '[data-alsmtp-remove-rule]' );
		if ( remove ) {
			var row = remove.closest( '[data-alsmtp-rule]' );
			if ( row ) { row.parentNode.removeChild( row ); }
			if ( add ) { add.focus(); }
		}
	} );
	document.addEventListener( 'submit', function ( e ) {
		var btn = e.target.querySelector && e.target.querySelector( '[data-alsmtp-confirm]' );
		if ( btn && ! window.confirm( btn.getAttribute( 'data-alsmtp-confirm' ) || cfg.confirm || 'Delete?' ) ) {
			e.preventDefault();
		}
	} );
}() );
