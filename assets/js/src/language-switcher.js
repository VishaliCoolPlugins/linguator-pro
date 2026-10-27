/**
 * Allows to open/close the language switcher's submenus on click when the "dropdown" layout is used.
 */

import Dropdown from './frontend-dropdown.js';

const lmatSwitcher = {
	/**
	 * Init.
	 */
	init: () => {
		if ( document.readyState !== 'loading' ) {
			lmatSwitcher.ready();
		} else {
			document.addEventListener( 'DOMContentLoaded', lmatSwitcher.ready );
		}
	},

	/**
	 * Called when the DOM is ready. Attaches the events to the buttons.
	 */
	ready: () => {
		// Dropdowns.
		const buttons = document.getElementsByClassName( 'lmat-submenu-toggle' );

		for ( const button of buttons ) {
			new Dropdown( button );
		}

		// Selects.
		const selects = document.getElementsByClassName(
			'lmat-switcher-select'
		);

		for ( const select of selects ) {
			select.addEventListener( 'change', ( event ) => {
				if ( event.currentTarget.value ) {
					window.location.href = event.currentTarget.value; // Already escaped in `the select switcher element`.
				}
			} );
		}
	},
};

lmatSwitcher.init();
