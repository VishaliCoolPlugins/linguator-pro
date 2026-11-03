/**
 * Refreshes the custom attribute fields of the product form after the translation editor saves its texts.
 */
( function () {
	'use strict';

	var config = window.lmatWcAttributeForm;
	if ( ! config || ! config.postId || ! window.fetch ) {
		return;
	}

	var nativeFetch = window.fetch;

	function setValue( field, value ) {
		if ( ! field || field.value === value ) {
			return;
		}
		field.value = value;
		if ( window.jQuery ) {
			window.jQuery( field ).trigger( 'input' ).trigger( 'change' );
		} else {
			field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	}

	function fill( attributes ) {
		var rows = document.querySelectorAll( '#product_attributes .woocommerce_attribute' );
		var index = 0;

		Array.prototype.forEach.call( rows, function ( row ) {
			var values = row.querySelector( 'textarea[name^="attribute_values"]' );
			// Rows of global attributes have a list to pick from instead of a text area.
			if ( ! values || ! attributes[ index ] ) {
				return;
			}
			setValue( row.querySelector( 'input[name^="attribute_names"]' ), attributes[ index ].name );
			setValue( values, attributes[ index ].value );

			var title = row.querySelector( 'h3 strong.attribute_name' );
			if ( title ) {
				title.textContent = attributes[ index ].name;
			}
			index++;
		} );
	}

	function refresh() {
		nativeFetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'content-type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new URLSearchParams( {
				action: 'lmat_wc_attribute_form',
				nonce: config.nonce,
				post_id: config.postId
			} )
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( result && result.success && result.data && result.data.attributes ) {
					fill( result.data.attributes );
				}
			} )
			.catch( function () {} );
	}

	// The editor saves its texts with a fetch request. Refresh the form once that request has finished.
	window.fetch = function ( input, init ) {
		var request = nativeFetch.apply( this, arguments );

		try {
			var body = init && init.body;
			if ( body instanceof URLSearchParams && 'lmat_update_post_meta_fields' === body.get( 'action' ) ) {
				request.then( refresh, function () {} );
			}
		} catch ( e ) {}

		return request;
	};
}() );
