/**
 * Shows only the settings of the selected mailer and the backup mailer.
 */
( function () {
	const radios = document.querySelectorAll( '.wpsb-mailer input[type="radio"]' );
	const backup = document.getElementById( 'wpsb-backup-mailer' );

	if ( ! radios.length ) {
		return;
	}

	function update() {
		const checked = document.querySelector( '.wpsb-mailer input[type="radio"]:checked' );
		const visible = [ checked ? checked.value : '', backup ? backup.value : '' ];

		radios.forEach( ( radio ) => {
			radio.closest( '.wpsb-mailer' ).classList.toggle( 'is-selected', radio.checked );
		} );

		document.querySelectorAll( '.wpsb-mailer-fields' ).forEach( ( section ) => {
			section.hidden = ! visible.includes( section.dataset.mailer );
		} );
	}

	radios.forEach( ( radio ) => radio.addEventListener( 'change', update ) );

	if ( backup ) {
		backup.addEventListener( 'change', update );
	}

	update();
} )();
