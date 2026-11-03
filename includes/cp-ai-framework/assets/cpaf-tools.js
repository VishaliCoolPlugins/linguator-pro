/**
 * Cool Plugins AI framework: AI Translation Tools page.
 *
 * Plain ES5, no build step. Behaviours: copy buttons, config tabs, the tool
 * search box, and the prompt-based "connect an AI app" flow.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.cpaf' );

	if ( ! root ) {
		return;
	}

	var data = window.cpafConnect || {};
	var i18n = data.i18n || {};

	/* ---------- MCP Adapter: install and activate ---------- */
	function adapterRequest( fields, tolerateHtml ) {
		var body = new FormData();

		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return fetch( data.adapter.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( response ) {
			return response.text().then( function ( text ) {
				try {
					return JSON.parse( text );
				} catch ( error ) {
					// A plugin that redirects on activation can end the request without JSON.
					return tolerateHtml ? { success: true, data: {} } : { success: false, data: { message: i18n.requestFailed } };
				}
			} );
		} );
	}

	function adapterMessage( text ) {
		var box = root.querySelector( '[data-cpaf-adapter-message]' );

		if ( box ) {
			box.textContent = text;
			box.hidden = ! text;
		}
	}

	function adapterActivate( init ) {
		return adapterRequest( {
			action: 'cpaf_adapter_activate',
			security: data.adapter.nonce,
			init: init
		}, true ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				throw new Error( ( res && res.data && res.data.message ) || i18n.requestFailed );
			}
		} );
	}

	function adapterInstall() {
		return adapterRequest( {
			action: 'cpaf_adapter_install',
			_ajax_nonce: data.adapter.nonceInstall,
			slug: data.adapter.slug
		}, false ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				var message = res && res.data && ( res.data.errorMessage || res.data.message );

				throw new Error( typeof message === 'string' && message ? message : i18n.requestFailed );
			}

			return ( res.data && res.data.plugin ) || data.adapter.init;
		} ).then( adapterActivate );
	}

	function adapterUpdate() {
		return adapterRequest( {
			action: 'cpaf_adapter_update',
			_ajax_nonce: data.adapter.nonceInstall,
			plugin: data.adapter.init,
			slug: data.adapter.slug
		}, false ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				var message = res && res.data && ( res.data.errorMessage || res.data.message );

				throw new Error( typeof message === 'string' && message ? message : i18n.requestFailed );
			}
		} );
	}

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-cpaf-adapter]' ) : null;

		if ( ! button || ! data.adapter ) {
			return;
		}

		event.preventDefault();

		var mode = button.getAttribute( 'data-cpaf-adapter' );
		var label = button.textContent;
		var task;

		adapterMessage( '' );
		button.disabled = true;

		if ( 'install' === mode ) {
			button.textContent = i18n.installing;
			task = adapterInstall();
		} else if ( 'update' === mode ) {
			button.textContent = i18n.updating;
			task = adapterUpdate();
		} else {
			button.textContent = i18n.activating;
			task = adapterActivate( data.adapter.init );
		}

		task.then( function () {
			// Reload so the page re-reads the adapter state and unlocks the connect step.
			window.location.reload();
		} ).catch( function ( error ) {
			button.disabled = false;
			button.textContent = label;
			adapterMessage( ( error && error.message ) || i18n.requestFailed );
		} );
	} );

	/* ---------- Copy ---------- */
	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );

			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();

			try {
				document.execCommand( 'copy' ) ? resolve() : reject();
			} catch ( e ) {
				reject( e );
			}

			document.body.removeChild( area );
		} );
	}

	function flash( button, text ) {
		// Icon-only buttons keep their icon and just show a highlight.
		if ( button.hasAttribute( 'data-cpaf-icon' ) ) {
			if ( button.classList.contains( 'is-copied' ) ) {
				return;
			}

			var originalIcon = button.innerHTML;

			button.classList.add( 'is-copied' );
			button.innerHTML = '<svg class="cpaf-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 13l4 4L19 7"/></svg><span class="cpaf-copied-tip" role="status">' + text + '</span>';

			window.setTimeout( function () {
				button.classList.remove( 'is-copied' );
				button.innerHTML = originalIcon;
			}, 1500 );

			return;
		}

		// Buttons with an icon and a label swap only the label.
		var label = button.querySelector( 'span' );

		if ( label ) {
			var originalLabel = label.textContent;

			label.textContent = text;
			window.setTimeout( function () {
				label.textContent = originalLabel;
			}, 1500 );

			return;
		}

		var original = button.textContent;

		button.textContent = text;
		window.setTimeout( function () {
			button.textContent = original;
		}, 1500 );
	}

	/* [data-cpaf-copy] points at the element whose text is copied. */
	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-cpaf-copy]' );

		if ( ! button ) {
			return;
		}

		var target = document.getElementById( button.getAttribute( 'data-cpaf-copy' ) );

		if ( target ) {
			copyText( target.value || target.textContent ).then( function () {
				flash( button, button.getAttribute( 'data-cpaf-done' ) || button.textContent );
			} );
		}
	} );

	/* [data-cpaf-toggle] shows or hides the element with that id. */
	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-cpaf-toggle]' );

		if ( ! button ) {
			return;
		}

		var target = document.getElementById( button.getAttribute( 'data-cpaf-toggle' ) );

		if ( ! target ) {
			return;
		}

		target.hidden = ! target.hidden;
		button.setAttribute( 'aria-expanded', target.hidden ? 'false' : 'true' );
		button.querySelector( 'span' ).textContent = button.getAttribute( target.hidden ? 'data-cpaf-show' : 'data-cpaf-hide' );
	} );

	/* ---------- Tabs ---------- */
	root.addEventListener( 'click', function ( event ) {
		var tab = event.target.closest( '[role="tab"]' );

		if ( ! tab ) {
			return;
		}

		Array.prototype.forEach.call( tab.parentNode.querySelectorAll( '[role="tab"]' ), function ( other ) {
			var selected = other === tab;
			var panel = document.getElementById( other.getAttribute( 'aria-controls' ) );

			other.setAttribute( 'aria-selected', selected ? 'true' : 'false' );

			if ( panel ) {
				panel.hidden = ! selected;
			}
		} );
	} );

	/* ---------- Search ---------- */
	var search = root.querySelector( '.cpaf-search' );

	if ( search ) {
		var tools = root.querySelectorAll( '.cpaf-tool' );
		var packs = root.querySelectorAll( '.cpaf-pack' );
		var none = root.querySelector( '.cpaf-no-results' );

		var kindButtons = root.querySelectorAll( '[data-cpaf-kind-filter]' );
		var banners = root.querySelectorAll( '[data-cpaf-banner]' );
		var kind = '';

		/* "change" covers every tool that is not read only. */
		function kindMatches( toolKind ) {
			if ( '' === kind ) {
				return true;
			}

			return 'change' === kind ? 'read' !== toolKind : toolKind === kind;
		}

		function filterTools() {
			var query = search.value.trim().toLowerCase();
			var shown = 0;

			Array.prototype.forEach.call( tools, function ( tool ) {
				var match = ( '' === query || tool.getAttribute( 'data-cpaf-search' ).indexOf( query ) !== -1 ) &&
					kindMatches( tool.getAttribute( 'data-cpaf-kind' ) );

				tool.hidden = ! match;
				shown += match ? 1 : 0;
			} );

			Array.prototype.forEach.call( packs, function ( pack ) {
				var visible = pack.querySelectorAll( '.cpaf-tool:not([hidden])' ).length;
				var count = pack.querySelector( '.cpaf-pack-count' );

				pack.hidden = 0 === visible;

				if ( count ) {
					count.textContent = count.getAttribute( 'data-cpaf-showing' )
						.replace( '%1$s', visible )
						.replace( '%2$s', count.getAttribute( 'data-cpaf-grand' ) );
				}
			} );

			Array.prototype.forEach.call( banners, function ( banner ) {
				banner.hidden = banner.getAttribute( 'data-cpaf-banner' ) !== kind;
			} );

			if ( none ) {
				none.hidden = 0 !== shown;
			}
		}

		search.addEventListener( 'input', filterTools );

		Array.prototype.forEach.call( kindButtons, function ( button ) {
			button.addEventListener( 'click', function () {
				kind = button.getAttribute( 'data-cpaf-kind-filter' );

				Array.prototype.forEach.call( kindButtons, function ( other ) {
					other.setAttribute( 'aria-pressed', other === button ? 'true' : 'false' );
				} );

				filterTools();
			} );
		} );
	}

	/* ---------- Connect an AI app ---------- */
	var generate = root.querySelector( '[data-cpaf-generate]' );

	if ( ! generate || ! data.restUrl ) {
		return;
	}

	var promptBox = document.getElementById( 'cpaf-prompt' );
	var promptWrap = document.getElementById( 'cpaf-prompt-wrap' );
	var errorBox = document.getElementById( 'cpaf-error' );
	var statusBox = document.getElementById( 'cpaf-status' );
	var isWindows = /win/i.test( navigator.platform || '' );
	var pollTimer = null;
	var POLL_MS = 4000;
	var POLL_LIMIT_MS = 180000;

	/* JSON string escaping for TOML basic strings. */
	function tomlString( value ) {
		return value.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' );
	}

	function json( value ) {
		return JSON.stringify( value, null, 2 );
	}

	function httpJson( c ) {
		var servers = {};

		servers[ c.serverName ] = {
			type: 'http',
			url: c.serverUrl,
			headers: { Authorization: 'Basic ' + c.encodedCredentials },
		};

		return json( { mcpServers: servers } );
	}

	/*
	 * How each AI app stores its MCP servers. "block" names the section the AI
	 * must merge into, so an existing config is never overwritten.
	 */
	var clients = {
		'claude-code': {
			cli: function ( c ) {
				return 'claude mcp add --transport http ' + c.serverName + ' "' + c.serverUrl + '" --header "Authorization: Basic ' + c.encodedCredentials + '"';
			},
			path: '~/.claude.json',
			winPath: '%USERPROFILE%\\.claude.json',
			lang: 'json',
			block: '"mcpServers"',
			config: httpJson,
		},
		'claude-desktop': {
			path: '~/Library/Application Support/Claude/claude_desktop_config.json',
			winPath: '%APPDATA%\\Claude\\claude_desktop_config.json',
			lang: 'json',
			block: '"mcpServers"',
			config: function ( c ) {
				var servers = {};

				servers[ c.serverName ] = {
					command: 'npx',
					args: [ '-y', 'mcp-remote', c.serverUrl, '--header', 'Authorization: Basic ' + c.encodedCredentials ],
				};

				return json( { mcpServers: servers } );
			},
		},
		codex: {
			path: '~/.codex/config.toml',
			winPath: '%USERPROFILE%\\.codex\\config.toml',
			lang: 'toml',
			block: '"mcp_servers"',
			config: function ( c ) {
				return (
					'[mcp_servers.' + c.serverName + ']\n' +
					'url = "' + tomlString( c.serverUrl ) + '"\n\n' +
					'[mcp_servers.' + c.serverName + '.http_headers]\n' +
					'Authorization = "' + tomlString( 'Basic ' + c.encodedCredentials ) + '"\n'
				);
			},
		},
		cursor: {
			path: '~/.cursor/mcp.json',
			winPath: '%USERPROFILE%\\.cursor\\mcp.json',
			lang: 'json',
			block: '"mcpServers"',
			config: httpJson,
		},
		other: {
			path: '~/mcp-config.json',
			winPath: '%USERPROFILE%\\mcp-config.json',
			lang: 'json',
			block: '"mcpServers"',
			config: httpJson,
		},
	};

	function selectedClient() {
		var checked = root.querySelector( 'input[name="cpaf-client"]:checked' );

		return checked ? checked.value : 'claude-code';
	}

	function clientLabel( id ) {
		return ( data.clients && data.clients[ id ] ) || id;
	}

	/* The prompt is for the AI app, so it stays in English. */
	function buildPrompt( id, c ) {
		var client = clients[ id ] || clients.other;
		var path = isWindows ? client.winPath : client.path;

		return [
			'I want to add this WordPress site as an MCP server to this AI client.',
			'',
			'Connection details:',
			'- Server URL: ' + c.serverUrl,
			'- Username: ' + c.username,
			'- Application password: ' + c.password,
			'- Server name to use in the config: ' + c.serverName,
			'',
			'Please:',
			'1. Open or create the config file at: ' + path,
			'2. If the file already exists, add this server to its ' + client.block + ' section and keep every other entry unchanged. If it does not exist, create it with:',
			'',
			'```' + client.lang,
			client.config( c ),
			'```',
			'',
			'3. Save the file',
			'4. Restart ' + clientLabel( id ) + ' if needed',
			'',
			'Do not change anything else.',
		].join( '\n' );
	}

	function setStatus( state, text ) {
		statusBox.hidden = false;
		statusBox.className = 'cpaf-status is-' + state;
		statusBox.textContent = text;
	}

	var readyTitle = document.getElementById( 'cpaf-ready-title' );
	var readyNote = document.getElementById( 'cpaf-ready-note' );
	var termBox = document.getElementById( 'cpaf-term' );
	var termToggle = root.querySelector( '[data-cpaf-toggle="cpaf-term"]' );
	var cliBox = document.getElementById( 'cpaf-cli' );
	var cliCmd = document.getElementById( 'cpaf-cli-cmd' );
	var tryBox = document.getElementById( 'cpaf-try' );
	var tryClient = document.getElementById( 'cpaf-try-client' );
	var stepPrompt = root.querySelector( '[data-cpaf-step="prompt"]' );
	var stepConnect = root.querySelector( '[data-cpaf-step="connect"]' );
	var generateIntro = root.querySelector( '[data-cpaf-generate-intro]' );
	var pasteIntro = root.querySelector( '[data-cpaf-paste-intro]' );

	function markStep( step, done ) {
		if ( step ) {
			step.classList.toggle( 'is-done', done );
		}
	}

	/* The "ready" box above the prompt; copied is false when the clipboard was not used. */
	function setReady( id, copied ) {
		readyTitle.textContent = copied ? i18n.readyTitle : i18n.readyTitleNoCopy;
		readyNote.textContent = ( copied ? i18n.readyNote : i18n.readyNoteNoCopy ).replace( '%s', clientLabel( id ) );
	}

	/* Collapses the prompt and the connected extras back to their starting state. */
	function resetSteps() {
		markStep( stepPrompt, false );
		markStep( stepConnect, false );

		if ( termBox ) {
			termBox.hidden = true;
		}

		if ( termToggle ) {
			termToggle.setAttribute( 'aria-expanded', 'false' );
			termToggle.querySelector( 'span' ).textContent = termToggle.getAttribute( 'data-cpaf-show' );
		}

		if ( cliBox ) {
			cliBox.hidden = true;
		}

		if ( tryBox ) {
			tryBox.hidden = true;
		}

		if ( generateIntro ) {
			generateIntro.hidden = false;
		}

		if ( pasteIntro ) {
			pasteIntro.hidden = false;
		}
	}

	function setConnected( id ) {
		var title = document.createElement( 'strong' );
		var count = parseInt( data.tools, 10 ) || 0;

		statusBox.hidden = false;
		statusBox.className = 'cpaf-status is-ok';
		statusBox.textContent = '';
		title.textContent = i18n.connected.replace( '%s', clientLabel( id ) );
		statusBox.appendChild( title );

		if ( count > 0 ) {
			statusBox.appendChild( document.createTextNode( ' ' + i18n.connectedTools.replace( '%d', count ) ) );
		}

		markStep( stepConnect, true );

		if ( pasteIntro ) {
			pasteIntro.hidden = true;
		}

		if ( tryBox ) {
			tryClient.textContent = clientLabel( id );
			tryBox.hidden = false;
		}
	}

	function showError( text, profileUrl ) {
		errorBox.hidden = false;
		errorBox.textContent = text;

		if ( profileUrl ) {
			var link = document.createElement( 'a' );

			link.href = profileUrl;
			link.textContent = i18n.profileLink;
			errorBox.appendChild( document.createTextNode( ' ' ) );
			errorBox.appendChild( link );
		}
	}

	function stopPolling() {
		if ( pollTimer ) {
			window.clearTimeout( pollTimer );
			pollTimer = null;
		}
	}

	function api( path, options ) {
		options = options || {};
		options.credentials = 'same-origin';
		options.headers = Object.assign( { 'X-WP-Nonce': data.nonce }, options.headers || {} );

		return fetch( data.restUrl + path, options ).then( function ( response ) {
			return response.json().then( function ( body ) {
				if ( ! response.ok ) {
					var error = new Error( ( body && body.message ) || i18n.requestFailed );

					error.body = body;
					throw error;
				}

				return body;
			} );
		} );
	}

	/* Waits for the first request made with the new password. */
	function waitForConnection( id, uuid ) {
		var started = Date.now();

		setStatus( 'waiting', i18n.waiting );

		( function poll() {
			api( 'connection?uuid=' + encodeURIComponent( uuid ) ).then( function ( body ) {
				if ( body.connected ) {
					setConnected( id );
					return;
				}

				if ( Date.now() - started > POLL_LIMIT_MS ) {
					setStatus( 'warn', i18n.timeout );
					return;
				}

				pollTimer = window.setTimeout( poll, POLL_MS );
			} ).catch( function () {
				setStatus( 'warn', i18n.timeout );
			} );
		}() );
	}

	var regenerate = root.querySelector( '[data-cpaf-regenerate]' );
	var savedNote = document.getElementById( 'cpaf-saved-note' );

	/* Shows the prompt for the chosen app and waits for it to connect. */
	function show( id, credentials ) {
		promptBox.value = buildPrompt( id, credentials );
		promptWrap.hidden = false;
		generate.hidden = true;
		savedNote.hidden = ! credentials.reused;
		markStep( stepPrompt, true );
		markStep( stepConnect, false );
		setReady( id, false );

		if ( generateIntro ) {
			generateIntro.hidden = true;
		}

		/* The one-line terminal command exists only for apps with a CLI. */
		if ( cliBox && clients[ id ] && clients[ id ].cli ) {
			cliCmd.textContent = clients[ id ].cli( credentials );
			cliBox.hidden = false;
		} else if ( cliBox ) {
			cliBox.hidden = true;
		}

		waitForConnection( id, credentials.uuid );

		return promptBox.value;
	}

	/* Saved credentials are reused; a new password is made only when none is saved or asked for. */
	function issue( newPassword ) {
		var id = selectedClient();

		stopPolling();
		errorBox.hidden = true;
		generate.disabled = true;

		api( 'credentials', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { client: id, regenerate: newPassword } ),
		} ).then( function ( body ) {
			return copyText( show( id, body.data ) ).then( function () {
				setReady( id, true );
			} ).catch( function () {} );
		} ).catch( function ( error ) {
			showError( error.message || i18n.requestFailed, error.body && error.body.data && error.body.data.profile_url );
		} ).then( function () {
			generate.disabled = false;
		} );
	}

	/* Chosen app changed (or page loaded): show its saved prompt, if there is one. */
	function load() {
		var id = selectedClient();

		stopPolling();
		promptWrap.hidden = true;
		generate.hidden = false;
		statusBox.hidden = true;
		errorBox.hidden = true;
		resetSteps();

		api( 'credentials?client=' + encodeURIComponent( id ) ).then( function ( body ) {
			if ( body.exists && id === selectedClient() ) {
				show( id, body.data );
			}
		} ).catch( function () {} );
	}

	generate.addEventListener( 'click', function () {
		issue( false );
	} );

	regenerate.addEventListener( 'click', function () {
		if ( window.confirm( i18n.confirmNew ) ) {
			issue( true );
		}
	} );

	root.addEventListener( 'change', function ( event ) {
		if ( event.target.name === 'cpaf-client' ) {
			load();
		}
	} );

	load();
}() );
