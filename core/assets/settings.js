/* Aluleth › Settings › Security: the test check of the captcha keys. */
( function () {
	var box = document.querySelector( '.al-captcha-box[data-provider]' );
	if ( ! box ) { return; }
	var form = box.closest( 'form' );
	var input = form ? form.querySelector( 'input[name="captcha_test_token"]' ) : null;
	if ( ! input ) { return; }
	var provider = box.getAttribute( 'data-provider' );
	var key = box.getAttribute( 'data-sitekey' );
	var v3 = 'recaptcha' === provider && 'v3' === box.getAttribute( 'data-version' );
	var opts = {
		sitekey: key,
		callback: function ( token ) { input.value = token; },
		'expired-callback': function () { input.value = ''; }
	};
	function render() {
		try {
			if ( 'turnstile' === provider && window.turnstile ) { opts.appearance = 'always'; window.turnstile.render( box, opts ); return true; }
			if ( 'hcaptcha' === provider && window.hcaptcha ) { window.hcaptcha.render( box, opts ); return true; }
			if ( 'recaptcha' === provider && window.grecaptcha ) {
				if ( v3 ) { return true; }
				if ( window.grecaptcha.render ) { window.grecaptcha.render( box, opts ); return true; }
			}
		} catch ( err ) { return true; }
		return false;
	}
	( function wait( n ) {
		if ( render() || n > 150 ) { return; }
		setTimeout( function () { wait( n + 1 ); }, 100 );
	}( 0 ) );
	if ( ! v3 ) { return; }
	var sent = false;
	form.addEventListener( 'submit', function ( e ) {
		var button = e.submitter;
		if ( sent || ! button || 'check' !== button.name || ! window.grecaptcha || ! window.grecaptcha.ready ) { return; }
		e.preventDefault();
		window.grecaptcha.ready( function () {
			window.grecaptcha.execute( key, { action: 'aluleth_key_check' } ).then( function ( token ) {
				input.value = token;
				sent = true;
				form.requestSubmit( button );
			}, function () { sent = true; form.requestSubmit( button ); } );
		} );
	} );
}() );
