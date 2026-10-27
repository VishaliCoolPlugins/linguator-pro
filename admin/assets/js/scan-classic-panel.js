/**
 * Linguator Site Scan - Classic Editor translation status box and "what changed" dialog.
 */
( function( wp ) {
	'use strict';

	const config = window.lmatScanEditor || {};
	const i18n = config.i18n || {};
	const box = document.querySelector( '.lmat-scan-metabox' );

	if ( ! box || ! wp || ! wp.apiFetch ) {
		return;
	}

	const LIST_LIMIT = 5;
	let lastFocused = null;

	function speak( message, politeness ) {
		if ( wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( message, politeness );
		}
	}

	function format( template, value ) {
		return String( template || '' ).replace( /%[sd]/, value );
	}

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function( key ) {
			if ( key === 'text' ) {
				node.textContent = attrs[ key ];
			} else if ( key === 'html' ) {
				// Only used for the diff table, which the server builds with wp_text_diff() and wp_kses().
				node.innerHTML = attrs[ key ];
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function( child ) {
			if ( child ) {
				node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
			}
		} );
		return node;
	}

	function icon( name ) {
		return el( 'span', { 'class': 'dashicons dashicons-' + name, 'aria-hidden': 'true' } );
	}

	function typeLabel( type ) {
		return { changed: i18n.typeChanged, added: i18n.typeAdded, removed: i18n.typeRemoved }[ type ] || type;
	}

	function api( path, method, data ) {
		return wp.apiFetch( { path: 'lmat/v1/scan/editor/' + path, method: method || 'GET', data: data } );
	}

	function flag( url ) {
		return url ? el( 'img', { 'class': 'lmat-scan-lang-flag', src: url, alt: '', width: '16', height: '11' } ) : null;
	}

	function shortList( items, render ) {
		const list = el( 'ul', { 'class': 'lmat-scan-editor-list' } );
		items.slice( 0, LIST_LIMIT ).forEach( function( item ) {
			list.appendChild( el( 'li', {}, [ render( item ) ] ) );
		} );
		if ( items.length > LIST_LIMIT ) {
			list.appendChild( el( 'li', { 'class': 'description', text: format( i18n.moreItems, items.length - LIST_LIMIT ) } ) );
		}
		return list;
	}

	function render( status ) {
		box.textContent = '';

		if ( status.role === 'source' ) {
			box.appendChild( el( 'div', { 'class': 'lmat-scan-editor-source-header' }, [
				el( 'span', { 'class': 'lmat-scan-badge lmat-scan-badge--source' }, [ icon( 'admin-site-alt3' ), ' ' + i18n.sourceRole ] ),
				el( 'p', { 'class': 'description', text: i18n.sourceRoleDesc } )
			] ) );

			const slugs = Object.keys( status.translations || {} );
			if ( slugs.length ) {
				const items = el( 'ul', { 'class': 'lmat-scan-editor-trans-items' } );
				slugs.forEach( function( slug ) {
					const t = status.translations[ slug ];
					const action = t.edit_url
						? el( 'a', { 'class': 'button button-small', href: t.edit_url, target: '_blank', rel: 'noopener noreferrer', text: i18n.editTranslation } )
						: ( t.add_url ? el( 'a', { 'class': 'button button-small button-primary', href: t.add_url, target: '_blank', rel: 'noopener noreferrer', text: i18n.addTranslation } ) : null );
					items.appendChild( el( 'li', { 'class': 'lmat-scan-editor-trans-item' }, [
						el( 'div', { 'class': 'lmat-scan-editor-trans-info' }, [
							flag( t.flag_url ),
							el( 'span', { 'class': 'lmat-scan-editor-lang-name', text: t.language_name } ),
							el( 'span', { 'class': 'lmat-scan-pill lmat-scan-pill--' + ( t.status_badge || 'info' ), text: t.status_label } )
						] ),
						el( 'div', { 'class': 'lmat-scan-editor-trans-action' }, [ action ] )
					] ) );
				} );
				box.appendChild( el( 'div', { 'class': 'lmat-scan-editor-trans-list' }, [ el( 'strong', { text: i18n.translationsLabel } ), items ] ) );
			}
			return;
		}

		if ( status.role !== 'translation' ) {
			box.appendChild( el( 'p', { 'class': 'description', text: i18n.noLanguage } ) );
			return;
		}

		const badge = status.status_badge || 'info';
		const parts = [
			el( 'div', { 'class': 'lmat-scan-editor-status-row' }, [
				el( 'span', { 'class': 'lmat-scan-editor-status-badge lmat-scan-editor-status-badge--' + badge }, [ icon( status.status_icon || 'info' ), ' ' + status.status_label ] )
			] )
		];

		if ( status.notice ) {
			parts.push( el( 'div', { 'class': 'lmat-scan-editor-notice lmat-scan-editor-notice--' + badge }, [ el( 'p', { text: status.notice } ) ] ) );
		}

		if ( status.source ) {
			parts.push( el( 'p', { 'class': 'lmat-scan-editor-meta-line' }, [
				el( 'strong', { text: i18n.sourceLabel + ' ' } ),
				flag( status.source.flag_url ),
				el( 'a', { href: status.source.edit_url, target: '_blank', rel: 'noopener noreferrer', text: status.source.title } )
			] ) );
			if ( status.source.modified_human ) {
				parts.push( el( 'p', { 'class': 'description', text: format( i18n.sourceChanged, status.source.modified_human ) } ) );
			}
		}

		if ( status.changes && status.changes.length ) {
			parts.push( el( 'div', { 'class': 'lmat-scan-editor-changed-fields' }, [
				el( 'strong', { text: i18n.changedLabel } ),
				shortList( status.changes, function( change ) {
					return el( 'span', {}, [ change.label + ' ', el( 'span', { 'class': 'lmat-scan-change-tag lmat-scan-change-tag--' + change.type, text: typeLabel( change.type ) } ) ] );
				} )
			] ) );
		}

		if ( status.still_same && status.still_same.length ) {
			parts.push( el( 'div', { 'class': 'lmat-scan-editor-changed-fields' }, [
				el( 'strong', { text: i18n.stillSameLabel } ),
				shortList( status.still_same, function( label ) {
					return el( 'span', { text: label } );
				} )
			] ) );
		}

		box.appendChild( el( 'div', { 'class': 'lmat-scan-editor-trans-header' }, parts ) );

		const actions = el( 'div', { 'class': 'lmat-scan-editor-actions' } );
		if ( status.has_diff ) {
			const view = el( 'button', { type: 'button', 'class': 'button button-primary' }, [ icon( 'visibility' ), ' ' + i18n.seeChanges ] );
			view.addEventListener( 'click', function() {
				openModal( status );
			} );
			actions.appendChild( view );
		}
		if ( status.can_sync && status.status === 'outdated' ) {
			const mark = el( 'button', { type: 'button', 'class': 'button button-secondary' }, [ icon( 'yes' ), ' ' + i18n.markSynced ] );
			mark.addEventListener( 'click', function() {
				markSynced( mark );
			} );
			actions.appendChild( mark );
			actions.appendChild( el( 'p', { 'class': 'description', text: i18n.markSyncedHint } ) );
		}
		box.appendChild( actions );
	}

	function load() {
		api( 'status?post_id=' + config.postId ).then( render ).catch( function( e ) {
			box.textContent = '';
			box.appendChild( el( 'p', { 'class': 'description', text: ( e && e.message ) || i18n.loadError } ) );
		} );
	}

	function markSynced( button ) {
		const label = button.textContent;
		button.disabled = true;
		button.textContent = i18n.markingSynced;

		api( 'mark-synced', 'POST', { translation_id: config.postId } ).then( function( status ) {
			closeModal();
			render( status );
			speak( i18n.markedSynced );
		} ).catch( function( e ) {
			button.disabled = false;
			button.textContent = label;
			speak( ( e && e.message ) || i18n.markError, 'assertive' );
		} );
	}

	function closeModal() {
		const overlay = document.querySelector( '.lmat-scan-diff-modal-overlay' );
		if ( overlay ) {
			overlay.remove();
		}
		if ( lastFocused && lastFocused.focus ) {
			lastFocused.focus();
		}
	}

	function openModal( status, split ) {
		split = split !== false;
		if ( ! document.querySelector( '.lmat-scan-diff-modal-overlay' ) ) {
			lastFocused = document.activeElement;
		}
		const old = document.querySelector( '.lmat-scan-diff-modal-overlay' );
		if ( old ) {
			old.remove();
		}

		const close = el( 'button', { type: 'button', 'class': 'lmat-scan-diff-modal-close', 'aria-label': i18n.close, text: '×' } );
		const sideBySide = el( 'button', { type: 'button', 'aria-pressed': split ? 'true' : 'false', 'class': split ? 'active' : '', text: i18n.sideBySide } );
		const inline = el( 'button', { type: 'button', 'aria-pressed': split ? 'false' : 'true', 'class': split ? '' : 'active', text: i18n.inline } );
		const intro = el( 'div', { 'class': 'lmat-scan-diff-modal-intro' } );
		const body = el( 'div', { 'class': 'lmat-scan-diff-modal-body', 'aria-live': 'polite' }, [ el( 'p', { 'class': 'lmat-scan-diff-modal-loading', text: i18n.diffLoading } ) ] );
		const footer = el( 'div', { 'class': 'lmat-scan-diff-modal-footer' } );
		const dialog = el( 'div', { 'class': 'lmat-scan-diff-modal', role: 'dialog', 'aria-modal': 'true', tabindex: '-1' }, [
			el( 'div', { 'class': 'lmat-scan-diff-modal-header' }, [ el( 'h2', { 'class': 'lmat-scan-diff-modal-title', text: i18n.diffModalTitle } ), close ] ),
			el( 'div', { 'class': 'lmat-scan-diff-modal-toolbar' }, [ el( 'div', { 'class': 'lmat-scan-diff-view-toggle', role: 'group' }, [ sideBySide, inline ] ) ] ),
			intro, body, footer
		] );
		const overlay = el( 'div', { 'class': 'lmat-scan-diff-modal-overlay' }, [ dialog ] );

		close.addEventListener( 'click', closeModal );
		overlay.addEventListener( 'mousedown', function( event ) {
			if ( event.target === overlay ) {
				closeModal();
			}
		} );
		overlay.addEventListener( 'keydown', function( event ) {
			if ( event.key === 'Escape' ) {
				closeModal();
			}
		} );
		sideBySide.addEventListener( 'click', function() {
			openModal( status, true );
		} );
		inline.addEventListener( 'click', function() {
			openModal( status, false );
		} );

		document.body.appendChild( overlay );
		dialog.focus();

		api( 'diff?translation_id=' + config.postId + '&split=' + ( split ? 'true' : 'false' ) ).then( function( data ) {
			intro.appendChild( el( 'p', {}, [ el( 'strong', { text: data.source_title } ), data.baseline_human ? ' — ' + format( i18n.comparedWith, data.baseline_human ) : '' ] ) );
			if ( data.partial ) {
				intro.appendChild( el( 'p', { 'class': 'description', text: i18n.partialNote } ) );
			}

			body.textContent = '';
			if ( ! data.fields.length ) {
				body.appendChild( el( 'p', { text: i18n.diffEmpty } ) );
			}
			data.fields.forEach( function( item ) {
				const block = el( 'div', { 'class': 'lmat-scan-diff-field-block' }, [
					el( 'h3', { 'class': 'lmat-scan-diff-field-title' }, [ item.label + ' ', el( 'span', { 'class': 'lmat-scan-change-tag lmat-scan-change-tag--' + item.type, text: typeLabel( item.type ) } ) ] )
				] );
				if ( item.truncated ) {
					block.appendChild( el( 'p', { 'class': 'description', text: i18n.truncatedNote } ) );
				}
				block.appendChild( el( 'div', { 'class': 'lmat-scan-diff-table-wrap', html: item.diff } ) );
				body.appendChild( block );
			} );

			if ( data.source_edit_url ) {
				footer.appendChild( el( 'a', { 'class': 'button', href: data.source_edit_url, target: '_blank', rel: 'noopener noreferrer', text: i18n.openSource } ) );
			}
			if ( data.can_sync && status.status === 'outdated' ) {
				const mark = el( 'button', { type: 'button', 'class': 'button button-primary', text: i18n.markSynced } );
				mark.addEventListener( 'click', function() {
					markSynced( mark );
				} );
				footer.appendChild( mark );
			}
			const done = el( 'button', { type: 'button', 'class': 'button button-secondary', text: i18n.close } );
			done.addEventListener( 'click', closeModal );
			footer.appendChild( done );
		} ).catch( function( e ) {
			body.textContent = '';
			body.appendChild( el( 'p', { 'class': 'lmat-scan-diff-error', role: 'alert', text: ( e && e.message ) || i18n.diffError } ) );
		} );
	}

	load();

	// Reload after a save: the classic editor reloads the page, so only the first load is needed.
}( window.wp ) );
