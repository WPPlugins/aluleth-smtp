/* Aluleth Core – dashboard chart tooltip. No dependencies. */
( function () {
	'use strict';
	document.querySelectorAll( '.al-chart[data-chart]' ).forEach( function ( box ) {
		var meta;
		try { meta = JSON.parse( box.getAttribute( 'data-chart' ) ); } catch ( e ) { return; }
		var svg = box.querySelector( 'svg' );
		var tip = box.querySelector( '.al-tip' );
		var cross = box.querySelector( '.al-cross' );
		var n = meta.points.length;
		if ( ! svg || ! n ) { return; }
		var vb = svg.viewBox.baseVal;
		var left = 40, right = 14;
		function show( e ) {
			var rect = svg.getBoundingClientRect();
			var scale = vb.width / rect.width;
			var x = ( e.clientX - rect.left ) * scale;
			var i = n > 1 ? Math.round( ( x - left ) / ( ( vb.width - left - right ) / ( n - 1 ) ) ) : 0;
			i = Math.max( 0, Math.min( n - 1, i ) );
			var px = n > 1 ? left + i * ( vb.width - left - right ) / ( n - 1 ) : vb.width / 2;
			cross.setAttribute( 'x1', px );
			cross.setAttribute( 'x2', px );
			cross.removeAttribute( 'hidden' );
			var p = meta.points[ i ];
			var html = '<b></b>';
			tip.innerHTML = html;
			tip.querySelector( 'b' ).textContent = p.label;
			p.values.forEach( function ( v, k ) {
				var row = document.createElement( 'div' );
				var dot = document.createElement( 'i' );
				dot.style.background = meta.colors[ k ];
				row.appendChild( dot );
				row.appendChild( document.createTextNode( v.toLocaleString() + ' ' + meta.labels[ k ].toLowerCase() ) );
				tip.appendChild( row );
			} );
			tip.hidden = false;
			var left_px = px / scale;
			var w = tip.offsetWidth;
			tip.style.left = ( left_px + 12 + w > rect.width ? left_px - w - 12 : left_px + 12 ) + 'px';
		}
		function hide() { tip.hidden = true; cross.setAttribute( 'hidden', '' ); }
		svg.addEventListener( 'mousemove', show );
		svg.addEventListener( 'mouseleave', hide );
	} );
}() );
