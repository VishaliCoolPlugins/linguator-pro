/**
 * Handles the options in the language switcher widget.
 */
const lmatWidget = {
	/**
	 * Init.
	 */
	init: () => {
		if ( document.readyState !== 'loading' ) {
			lmatWidget.ready();
		} else {
			document.addEventListener( 'DOMContentLoaded', lmatWidget.ready );
		}
	},

	/**
	 * Called when the DOM is ready. Attaches the events to the wrapper.
	 */
	ready: () => {
		if (
			window.lmat_widgets?.flags &&
			'object' === typeof window.lmat_widgets.flags
		) {
			lmatWidget.displayFlags.flags = window.lmat_widgets.flags;
		}
		document
			.querySelectorAll(
				// Widgets page without Classic Widgets, Widgets page with Classic Widgets, Customizer page.
				'#widgets-editor, .widget-liquid-right #widgets-right, #customize-theme-controls'
			)
			.forEach( ( wrapper ) => {
				wrapper.addEventListener( 'change', ( event ) => {
					if (
						event.target.closest(
							'.lmat-language-switcher-widget-content select, .lmat-language-switcher-widget-content input'
						)
					) {
						lmatWidget.manageRowsAndValues.handleEvent( event );
						return;
					}

					if (
						! lmatWidget.displayFlags.flags.length ||
						'customize-theme-controls' === wrapper.id
					) {
						// No flags for the customizer.
						return;
					}

					if (
						event.target.closest(
							'.wp-block-legacy-widget__edit-form .lmat-lang-choice'
						)
					) {
						// Without Classic Widgets.
						lmatWidget.displayFlags.handleEvent(
							event,
							'.wp-block-legacy-widget__edit-form',
							'.wp-block-legacy-widget__edit-form-title'
						);
					} else if (
						event.target.closest( '.widget .lmat-lang-choice' )
					) {
						// With Classic Widgets.
						lmatWidget.displayFlags.handleEvent(
							event,
							'.widget',
							'.widget-top .widget-title h3'
						);
					}
				} );

				if ( 'customize-theme-controls' !== wrapper.id ) {
					// No flags for the customizer.
					if (
						wrapper.classList.contains( 'blocks-widgets-container' )
					) {
						// Without Classic Widgets: wait a second that everything is inserted into the page by WP.
						setTimeout( () => {
							lmatWidget.displayFlags.triggerChange( wrapper );
						}, 1000 );
					} else {
						lmatWidget.displayFlags.triggerChange( wrapper );
					}
				}
			} );
	},

	/**
	 * Display or hide rows, depending on the value of other settings.
	 */
	manageRowsAndValues: {
		/**
		 * Event callback that hides rows and forbids disabling both flag and name/code.
		 *
		 * @param {Event} event The event.
		 */
		handleEvent: ( event ) => {
			let value = '';

			if ( 'SELECT' === event.target.nodeName ) {
				value = event.target.value;
			} else if (
				'INPUT' === event.target.nodeName &&
				'checkbox' === event.target.type
			) {
				value = event.target.checked;
			} else {
				return;
			}

			const wrapper = event.target.closest(
				'.lmat-language-switcher-widget-content'
			);

			if ( ! wrapper ) {
				return;
			}

			const key = event.target.getAttribute( 'data-key' );

			// Show/Hide rows.
			wrapper
				.querySelectorAll(
					`:scope [class*="lmat-hidden-if-${ key }-"]:not(.lmat-hidden-if-${ key }-${ value })`
				)
				.forEach( ( row ) => {
					row.classList.remove( `lmat-hidden-by-${ key }` );
				} );
			wrapper
				.querySelectorAll( `:scope .lmat-hidden-if-${ key }-${ value }` )
				.forEach( ( row ) => {
					row.classList.add( `lmat-hidden-by-${ key }` );
				} );

			// Forbid disabling both flag and name/code.
			if ( 'show_labels' === key && '' === value ) {
				const otherInput = wrapper.querySelector(
					'[data-key="show_flags"]'
				);

				if ( true !== otherInput.checked ) {
					otherInput.checked = true;
					otherInput.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				}
			} else if ( 'show_flags' === key && false === value ) {
				const otherInput = wrapper.querySelector(
					'[data-key="show_labels"]'
				);

				if ( '' === otherInput.value ) {
					otherInput.value = 'names';
					otherInput.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				}
			}
		},
	},

	/**
	 * Display a flag in front of a widget title, depending on the language the widget should be displayed for.
	 */
	displayFlags: {
		/**
		 * Flags (markup), with language slug as property names.
		 *
		 * @member {Object}
		 */
		flags: {},

		/**
		 * Event callback that adds or removes the flag.
		 *
		 * @param {Event}  event          The event.
		 * @param {string} widgetSelector Selector for the widget wrapper, relative to the event target.
		 * @param {string} titleSelector  Selector for the title, relative to the wrapper.
		 */
		handleEvent: ( event, widgetSelector, titleSelector ) => {
			const wrapper = event.target.closest( widgetSelector );

			if ( ! wrapper ) {
				return;
			}

			const title = wrapper.querySelector( titleSelector );

			if ( ! title ) {
				return;
			}

			const langSlug = event.target.value;
			const icon =
				langSlug &&
				lmatWidget.displayFlags.flags.hasOwnProperty( langSlug )
					? lmatWidget.displayFlags.flags[ langSlug ]
					: null;
			const currentFlag = title.querySelector( '.lmat-lang' );

			if ( icon ) {
				if ( currentFlag ) {
					currentFlag.innerHTML = `${ icon } &nbsp; `; // `icon` comes from `the admin base inline scripts`.
				} else {
					const newFlag = document.createElement( 'span' );
					newFlag.classList.add( 'lmat-lang' );
					newFlag.innerHTML = `${ icon } &nbsp; `; // `icon` comes from `the admin base inline scripts`.
					title.prepend( newFlag ); // `newFlag` is a new element we just created with safe data.
				}
			} else if ( currentFlag ) {
				currentFlag.remove();
			}
		},

		/**
		 * Triggers a language selection.
		 *
		 * @param {HTMLElement} wrapper Wrapper.
		 */
		triggerChange: ( wrapper ) => {
			wrapper
				.querySelectorAll( ':scope .lmat-lang-choice' )
				.forEach( ( select ) => {
					select.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				} );
		},
	},
};

lmatWidget.init();

