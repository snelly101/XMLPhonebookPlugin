/* Site Phonebooks admin enhancements. All screens work without this file. */
( function () {
	'use strict';
	var l10n = window.spbAdmin || {};

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'absolute';
			ta.style.left = '-9999px';
			document.body.appendChild( ta );
			ta.select();
			try {
				document.execCommand( 'copy' ) ? resolve() : reject();
			} catch ( e ) {
				reject( e );
			}
			document.body.removeChild( ta );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.spb-copy' );
		if ( ! button ) {
			return;
		}
		var target = document.getElementById( button.getAttribute( 'data-copy-target' ) );
		if ( ! target ) {
			return;
		}
		var original = button.textContent;
		copyText( target.value ).then( function () {
			button.classList.add( 'is-copied' );
			button.textContent = l10n.copied || 'Copied';
			button.setAttribute( 'aria-live', 'polite' );
			setTimeout( function () {
				button.classList.remove( 'is-copied' );
				button.textContent = original;
			}, 2000 );
		}, function () {
			target.focus();
			target.select();
			window.alert( l10n.copyFailed || 'Copy failed. Select the text and copy it manually.' );
		} );
	} );

	document.addEventListener( 'click', function ( event ) {
		var control = event.target.closest( '.spb-confirm' );
		if ( ! control ) {
			return;
		}
		var message = control.getAttribute( 'data-confirm' );
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	}, true );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form.classList || ! form.classList.contains( 'spb-single-submit' ) ) {
			return;
		}
		if ( form.getAttribute( 'data-submitted' ) ) {
			event.preventDefault();
			return;
		}
		form.setAttribute( 'data-submitted', '1' );
		var buttons = form.querySelectorAll( '[type="submit"]' );
		setTimeout( function () {
			Array.prototype.forEach.call( buttons, function ( b ) {
				b.disabled = true;
				if ( b.tagName === 'BUTTON' && l10n.working ) {
					b.setAttribute( 'data-label', b.textContent );
					b.textContent = l10n.working;
				}
			} );
		}, 0 );
	} );

	var bulkForm = document.getElementById( 'spb-contacts-form' );
	if ( bulkForm ) {
		bulkForm.addEventListener( 'submit', function ( event ) {
			var select = bulkForm.querySelector( '[name="bulk_action"]' );
			var checked = bulkForm.querySelectorAll( 'input[name="contact_ids[]"]:checked' ).length;
			if ( select && select.value === 'delete' && checked === 0 ) {
				event.preventDefault();
				select.focus();
			}
		} );
	}
}() );
