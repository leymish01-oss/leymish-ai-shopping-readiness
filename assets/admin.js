/* LeyMish AI Readiness: the one-click upgrade. "Upgrade" opens checkout in a new tab (the form's target); this tab
   then asks, every 5 seconds for up to 30 minutes, whether the purchase has arrived, and switches Pro on by itself. */
( function () {
	var cfg = window.lasrAdmin;
	if ( ! cfg ) {
		return;
	}
	var polling = false;
	function poll( wait, started ) {
		var body = new URLSearchParams( { action: 'lasr_upgrade_poll', _ajax_nonce: cfg.pollNonce } );
		fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( d ) {
				var s = d && d.data ? d.data.status : '';
				if ( 'active' === s ) {
					window.location.href = cfg.planUrl;
					return;
				}
				if ( 'failed' === s ) {
					wait.textContent = cfg.failed;
					return;
				}
				if ( Date.now() - started < 30 * 60 * 1000 ) {
					setTimeout( function () { poll( wait, started ); }, 5000 );
				}
			} )
			.catch( function () {
				setTimeout( function () { poll( wait, started ); }, 10000 );
			} );
	}
	document.querySelectorAll( 'form[data-lasr-upgrade]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function () {
			var wait = form.querySelector( '.lasr-upgrade-wait' );
			if ( wait ) {
				wait.hidden = false;
			}
			if ( ! polling && wait ) {
				polling = true;
				setTimeout( function () { poll( wait, Date.now() ); }, 8000 );
			}
		} );
	} );
} )();
