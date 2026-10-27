/**
 * Adds one biography textarea field per language in the user profile.
 *
 * @package Linguator
 */

const lmatDescription = {
	/**
	 * Init.
	 */
	init: () => {
		if ( typeof lmatDescriptionData === 'undefined' || ! lmatDescriptionData ) { // eslint-disable-line no-undef
			return;
		}
		if ( document.readyState !== 'loading' ) {
			lmatDescription.ready();
		} else {
			document.addEventListener(
				'DOMContentLoaded',
				lmatDescription.ready
			);
		}
	},

	/**
	 * Called when the DOM is ready.
	 */
	ready: () => {
		const originTextarea = document.getElementById( 'description' );

		if ( ! originTextarea ) {
			return;
		}

		const rows = [];

		lmatDescriptionData.forEach( ( data ) => { // eslint-disable-line no-undef
			const wrapper = document.createElement( 'div' );
			wrapper.setAttribute( 'lang', data.lang );

			const label = document.createElement( 'label' );
			label.setAttribute( 'for', `description_${ data.slug }` );
			label.setAttribute( 'dir', data.direction );

			if ( data.flag.src ) {
				const img = document.createElement( 'img' );
				img.setAttribute( 'alt', '' );
				img.setAttribute( 'src', data.flag.src );
				if ( data.flag.width ) {
					img.setAttribute( 'width', data.flag.width );
				}
				if ( data.flag.height ) {
					img.setAttribute( 'height', data.flag.height );
				}
				label.textContent = ` ${ data.name }`;
				label.prepend( img ); // phpcs:ignore WordPressVIPMinimum.JS.HTMLExecutingFunctions.prepend
			} else {
				label.textContent = data.name;
			}

			const textarea = originTextarea.cloneNode( true );
			textarea.setAttribute( 'id', `description_${ data.slug }` );
			textarea.setAttribute( 'name', `description_${ data.slug }` );
			textarea.setAttribute( 'dir', data.direction );
			textarea.innerHTML = data.description; // phpcs:ignore WordPressVIPMinimum.JS.InnerHTML.Found

			wrapper.append( label, document.createElement( 'br' ), textarea ); // phpcs:ignore WordPressVIPMinimum.JS.HTMLExecutingFunctions.append
			rows.push( wrapper );
		} );

		originTextarea.replaceWith( ...rows ); // phpcs:ignore WordPressVIPMinimum.JS.HTMLExecutingFunctions.replaceWith
	},
};

lmatDescription.init();
