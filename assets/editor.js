/* Bulk editor: check each GTIN as you type (the same rules as LASR_GTIN::is_valid on the server, which still
   checks every value on save). Messages are text next to the field, linked with aria-describedby. */
( function () {
	var t = window.lasrEditor || { invalid: 'Not a valid GTIN.', ok: 'Valid GTIN.' };
	function valid( raw ) {
		var g = String( raw ).replace( /[\s-]+/g, '' );
		if ( ! /^(\d{8}|\d{12}|\d{13}|\d{14})$/.test( g ) || /^0+$/.test( g ) ) {
			return false;
		}
		var d = g.split( '' ).map( Number );
		var check = d.pop();
		var sum = 0;
		d.reverse().forEach( function ( n, i ) {
			sum += i % 2 === 0 ? n * 3 : n;
		} );
		return ( 10 - ( sum % 10 ) ) % 10 === check;
	}
	function show( input ) {
		var msg = document.getElementById( input.getAttribute( 'aria-describedby' ) );
		var v = input.value.trim();
		if ( ! msg ) {
			return;
		}
		if ( v === '' ) {
			input.removeAttribute( 'aria-invalid' );
			msg.textContent = '';
			msg.className = 'lasr-gtin-msg';
			return;
		}
		var ok = valid( v );
		input.setAttribute( 'aria-invalid', ok ? 'false' : 'true' );
		msg.textContent = ok ? t.ok : t.invalid;
		msg.className = 'lasr-gtin-msg ' + ( ok ? 'is-ok' : 'is-bad' );
	}
	document.querySelectorAll( 'input.lasr-gtin' ).forEach( function ( input ) {
		input.addEventListener( 'input', function () {
			show( input );
		} );
		input.addEventListener( 'blur', function () {
			show( input );
		} );
	} );
} )();
