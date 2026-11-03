<?php
/**
 * Cool Plugins AI framework: the AI Translation MCP page under Tools.
 *
 * @package CoolPluginsAiFramework
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CPAF_Tools_Page' ) ) {

	/**
	 * One status and setup page shared by every translation plugin.
	 *
	 * Registered by the winning copy of the framework only, so the menu item
	 * exists once however many plugins are active. The page is generic: it is
	 * built from whatever packs are available, so a new plugin's pack appears
	 * here with no change to this class.
	 *
	 * @since 1.0.0
	 */
	final class CPAF_Tools_Page {

		/**
		 * Page slug: tools.php?page=cp-ai-tools.
		 *
		 * @since 1.0.0
		 * @var string
		 */
		const PAGE = 'cp-ai-tools';

		/**
		 * Screen hook suffix returned by add_management_page().
		 *
		 * @since 1.4.0
		 * @var string
		 */
		private static $hook = '';

		/**
		 * Hooks the page into the admin menu.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 10, 0 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 10, 1 );
		}

		/**
		 * Adds the page under Tools.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public static function add_menu() {
			$hook = add_management_page(
				CPAF_Framework::t( 'AI Translation MCP' ),
				CPAF_Framework::t( 'AI Translation MCP' ),
				'manage_options',
				self::PAGE,
				array( __CLASS__, 'render' )
			);

			self::$hook = is_string( $hook ) ? $hook : '';

			// Late, so it runs after every plugin has added its notices.
			add_action( 'in_admin_header', array( __CLASS__, 'hide_admin_notices' ), 1000, 0 );
		}

		/**
		 * Removes every WordPress admin notice from this screen.
		 *
		 * The page is a setup screen and must stay uncluttered. in_admin_header
		 * fires just before WordPress prints the notice hooks, so removing them
		 * here catches notices from every plugin. Messages the page prints itself
		 * are plain markup, not notice hooks, and are not affected. Notices printed
		 * by other means are hidden by the CSS in assets/cpaf-tools.css.
		 *
		 * @since 1.4.1
		 * @return void
		 */
		public static function hide_admin_notices() {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

			if ( ! $screen || '' === self::$hook || self::$hook !== $screen->id ) {
				return;
			}

			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
			remove_all_actions( 'user_admin_notices' );
			remove_all_actions( 'network_admin_notices' );
		}

		/**
		 * Loads the page's CSS and JS on this screen only.
		 *
		 * @since 1.4.0
		 * @param string $hook_suffix Current admin screen hook suffix.
		 * @return void
		 */
		public static function enqueue( $hook_suffix ) {
			if ( '' === self::$hook || self::$hook !== $hook_suffix ) {
				return;
			}

			$anchor  = CPAF_Framework::dir() . '/class-cpaf-framework.php';
			$version = CPAF_Framework::version();

			wp_enqueue_style( 'cpaf-tools', plugins_url( 'assets/cpaf-tools.css', $anchor ), array(), $version );
			wp_enqueue_script( 'cpaf-tools', plugins_url( 'assets/cpaf-tools.js', $anchor ), array(), $version, true );

			wp_localize_script(
				'cpaf-tools',
				'cpafConnect',
				array(
					'restUrl' => esc_url_raw( rest_url( CPAF_Connect::REST_NAMESPACE . '/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'clients' => CPAF_Connect::clients(),
					'tools'   => self::count_tools(),
					'adapter' => array(
						'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
						'slug'         => CPAF_Adapter_Installer::SLUG,
						'init'         => CPAF_Adapter_Installer::PLUGIN_FILE,
						'nonceInstall' => wp_create_nonce( 'updates' ),
						'pluginFile'   => CPAF_Adapter_Installer::PLUGIN_FILE,
						'nonce'        => wp_create_nonce( CPAF_Adapter_Installer::NONCE_ACTION ),
					),
					'i18n'    => array(
						'confirmNew'    => CPAF_Framework::t( 'Create a new password? The current one stops working, so an AI app that already uses it will need the new prompt.' ),
						'requestFailed' => CPAF_Framework::t( 'The request failed. Please try again.' ),
						'profileLink'   => CPAF_Framework::t( 'Create an Application Password on your profile page' ),
						'installing'    => CPAF_Framework::t( 'Installing and activating...' ),
						'activating'    => CPAF_Framework::t( 'Activating...' ),
						'updating'      => CPAF_Framework::t( 'Updating...' ),
						'waiting'       => CPAF_Framework::t( 'Waiting for your AI app to connect...' ),
						'readyTitle'    => CPAF_Framework::t( 'Setup prompt copied' ),
						'readyNote'     => CPAF_Framework::t( 'Now paste it into %s and press Enter.' ),
						'readyTitleNoCopy' => CPAF_Framework::t( 'Setup prompt ready' ),
						'readyNoteNoCopy'  => CPAF_Framework::t( 'Use Copy again, then paste it into %s and press Enter.' ),
						'connected'     => CPAF_Framework::t( 'Connected to %s.' ),
						'connectedTools' => CPAF_Framework::t( '%d translation tools are ready to use.' ),
						'timeout'       => CPAF_Framework::t( 'No connection was detected within three minutes. Check that you sent the prompt and restarted the app, or generate the prompt again.' ),
					),
				)
			);
		}

		/**
		 * Registered abilities of the available packs, grouped by pack.
		 *
		 * Descriptions and read/write flags are read off the registered
		 * abilities, so this page cannot drift from the tool definitions.
		 *
		 * @since 1.0.0
		 * @param array $packs Available packs.
		 * @return array<string, array<int, array<string, mixed>>> Tools keyed by pack id.
		 */
		private static function tools_by_pack( array $packs ) {
			$grouped = array();

			foreach ( $packs as $pack ) {
				$grouped[ $pack['id'] ] = array();
			}

			if ( ! function_exists( 'wp_get_abilities' ) ) {
				return $grouped;
			}

			foreach ( wp_get_abilities() as $ability ) {
				$name = $ability->get_name();

				foreach ( $packs as $pack ) {
					if ( 0 !== strpos( $name, $pack['namespace'] . '/' ) ) {
						continue;
					}

					$meta        = $ability->get_meta();
					$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();

					if ( ! empty( $annotations['destructive'] ) ) {
						$kind = 'destructive';
					} elseif ( ! empty( $annotations['readonly'] ) ) {
						$kind = 'read';
					} else {
						$kind = 'write';
					}

					$grouped[ $pack['id'] ][] = array(
						'name'        => $name,
						'label'       => $ability->get_label(),
						'description' => $ability->get_description(),
						'kind'        => $kind,
					);
				}
			}

			return $grouped;
		}

		/**
		 * One short line from a tool description for the list row.
		 *
		 * Descriptions are written for the AI and can run to a paragraph. The row
		 * shows the first sentence only, without the plugin prefix; the full text
		 * stays available inside the expanded row.
		 *
		 * @since 1.6.0
		 * @param string $description Full tool description.
		 * @return string Short description.
		 */
		private static function short_description( $description ) {
			$text      = preg_replace( '/^(?:AutoPoly|AutoMLP|LocoAI|Loco)\s*:\s*/i', '', trim( (string) $description ) );
			$sentences = preg_split( '/(?<=[.!?])\s+/', (string) $text, 2 );
			$first     = is_array( $sentences ) && isset( $sentences[0] ) ? $sentences[0] : (string) $text;

			if ( function_exists( 'mb_strlen' ) && mb_strlen( $first ) > 140 ) {
				$first = rtrim( mb_substr( $first, 0, 137 ), " ,;:-" ) . '...';
			}

			return $first;
		}

		/**
		 * Example prompt and result summary for a tool, if one is written.
		 *
		 * @since 1.6.0
		 * @param string $name Ability name, e.g. automlp/translate-post.
		 * @return array{try: string, returns: string[]}|null Example, or null when there is none.
		 */
		private static function example( $name ) {
			static $examples = null;

			if ( null === $examples ) {
				$file     = CPAF_Framework::dir() . '/resources/tool-examples.php';
				$examples = is_readable( $file ) ? include $file : array();
				$examples = is_array( $examples ) ? $examples : array();
			}

			if ( empty( $examples[ $name ]['try'] ) || empty( $examples[ $name ]['returns'] ) ) {
				return null;
			}

			return $examples[ $name ];
		}

		/**
		 * Number of tools across the available packs.
		 *
		 * @since 1.6.0
		 * @return int Tool count.
		 */
		private static function count_tools() {
			$total = 0;

			foreach ( self::tools_by_pack( CPAF_Framework::packs() ) as $pack_tools ) {
				$total += count( $pack_tools );
			}

			return $total;
		}

		/**
		 * Label and CSS modifier for a tool kind.
		 *
		 * @since 1.4.0
		 * @param string $kind read|write|destructive.
		 * @return array{0: string, 1: string, 2: string} Badge text, modifier class, plain-language effect.
		 */
		private static function kind_info( $kind ) {
			if ( 'destructive' === $kind ) {
				return array(
					CPAF_Framework::t( 'Destructive' ),
					'is-destructive',
					CPAF_Framework::t( 'Changes your site and can remove data. Use it deliberately.' ),
				);
			}

			if ( 'read' === $kind ) {
				return array(
					CPAF_Framework::t( 'Read only' ),
					'is-read',
					CPAF_Framework::t( 'Only reads information. It never changes anything on your site.' ),
				);
			}

			return array(
				CPAF_Framework::t( 'Writes data' ),
				'is-write',
				CPAF_Framework::t( 'Creates or changes data on your site.' ),
			);
		}

		/**
		 * Inline SVG icon from a fixed allow-list (outline, 24px grid).
		 *
		 * The path data is hardcoded below, so the markup is safe to print as is.
		 *
		 * @since 1.5.0
		 * @param string $name Icon key.
		 * @return string SVG markup, or an empty string for an unknown key.
		 */
		private static function icon( $name ) {
			$paths = array(
				'translate' => 'M3 5h12M9 3v2m1.05 9.5A18 18 0 0 1 6.4 9m6.1 9h7M11 21l5-10 5 10M12.75 5C11.8 10.8 8.1 15.6 3 18.1',
				'bolt'      => 'M13 10V3L4 14h7v7l9-11h-7z',
				'layers'    => 'M12 3 3 8l9 5 9-5-9-5zM3 13l9 5 9-5M3 17.5l9 5 9-5',
				'wrench'    => 'M10.3 4.3c-.4 1.8.1 3.7 1.5 5.1 1.4 1.4 3.3 1.9 5.1 1.5l3.8 3.8a2.1 2.1 0 0 1-3 3l-3.8-3.8a6 6 0 0 1-7.2-7.2L9 9l2-2-1.7-2.7z',
				'plug'      => 'M9 3v5m6-5v5M6 8h12v4a6 6 0 0 1-12 0V8zm6 10v3',
				'refresh'   => 'M4 4v5h.6m14.8 2A8 8 0 0 0 4.6 9m0 0H9m11 11v-5h-.6m0 0a8 8 0 0 1-14.8-2m14.8 2H15',
				'search'    => 'M21 21l-6-6m2-5a7 7 0 1 1-14 0 7 7 0 0 1 14 0z',
				'copy'      => 'M8 16H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2m-6 12h8a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-8a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2z',
				'warning'   => 'M12 9v2m0 4h.01M5.1 19h13.8c1.5 0 2.5-1.7 1.7-3L13.7 4c-.8-1.3-2.7-1.3-3.5 0L3.3 16c-.8 1.3.2 3 1.8 3z',
				'check'     => 'M5 13l4 4L19 7',
				'book'      => 'M12 6.3C10.5 5 8.4 4.5 6 4.5c-1.2 0-2.3.2-3 .5v13c.7-.3 1.8-.5 3-.5 2.4 0 4.5.5 6 1.8m0-13c1.5-1.3 3.6-1.8 6-1.8 1.2 0 2.3.2 3 .5v13c-.7-.3-1.8-.5-3-.5-2.4 0-4.5.5-6 1.8m0-13v13',
				'shield'    => 'M12 3l8 3v6c0 4.5-3.2 8.3-8 9-4.8-.7-8-4.5-8-9V6l8-3zM9 12l2 2 4-4',
				'arrow'     => 'M5 12h14m-6-6 6 6-6 6',
				'lock'      => 'M7 11V8a5 5 0 0 1 10 0v3M6 11h12v9H6z',
				'chevron'   => 'M6 9l6 6 6-6',
			);

			if ( ! isset( $paths[ $name ] ) ) {
				return '';
			}

			return '<svg class="cpaf-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . $paths[ $name ] . '"/></svg>';
		}

		/**
		 * One status tile.
		 *
		 * @since 1.4.0
		 * @param string $label Tile label.
		 * @param string $state ok|warn|bad.
		 * @param string $value Main text.
		 * @param string $note  Small text under the value.
		 * @param string $icon  Icon key for icon().
		 * @return void
		 */
		private static function stat( $label, $state, $value, $note = '', $icon = '' ) {
			?>
			<div class="cpaf-stat is-<?php echo esc_attr( $state ); ?>">
				<div class="cpaf-stat-top">
					<span class="cpaf-stat-label"><?php echo esc_html( $label ); ?></span>
					<?php if ( '' !== $icon ) : ?>
						<span class="cpaf-stat-icon"><?php echo self::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from icon(). ?></span>
					<?php endif; ?>
				</div>
				<span class="cpaf-stat-value">
					<span class="cpaf-dot is-<?php echo esc_attr( $state ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $value ); ?>
				</span>
				<?php if ( '' !== $note ) : ?>
					<span class="cpaf-stat-note"><?php echo esc_html( $note ); ?></span>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * A read-only value with a copy button.
		 *
		 * @since 1.4.0
		 * @param string $id    Element id for the value.
		 * @param string $label Field label.
		 * @param string $value Value to show and copy.
		 * @return void
		 */
		private static function copy_field( $id, $label, $value ) {
			?>
			<div class="cpaf-field">
				<span class="cpaf-field-label"><?php echo esc_html( $label ); ?></span>
				<code class="cpaf-code-inline" id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $value ); ?></code>
				<button type="button" class="button button-small cpaf-copy" data-cpaf-copy="<?php echo esc_attr( $id ); ?>" data-cpaf-done="<?php echo esc_attr( CPAF_Framework::t( 'Copied' ) ); ?>"><?php echo esc_html( CPAF_Framework::t( 'Copy' ) ); ?></button>
			</div>
			<?php
		}

		/**
		 * A code block with a copy button.
		 *
		 * @since 1.4.0
		 * @param string $id   Element id for the code.
		 * @param string $code Code to show and copy.
		 * @return void
		 */
		private static function code_block( $id, $code ) {
			?>
			<div class="cpaf-code">
				<button type="button" class="button button-small cpaf-copy" data-cpaf-copy="<?php echo esc_attr( $id ); ?>" data-cpaf-done="<?php echo esc_attr( CPAF_Framework::t( 'Copied' ) ); ?>"><?php echo esc_html( CPAF_Framework::t( 'Copy' ) ); ?></button>
				<pre id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $code ); ?></pre>
			</div>
			<?php
		}

		/**
		 * Renders the page.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public static function render() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$abilities_available = function_exists( 'wp_register_ability' );
			$adapter_present     = CPAF_Framework::adapter_available();
			$adapter_compatible  = CPAF_Framework::adapter_is_compatible();
			$adapter_version     = CPAF_Framework::adapter_version();
			$adapter_source      = CPAF_Framework::adapter_source_name();
			$packs               = CPAF_Framework::packs();
			$tools               = self::tools_by_pack( $packs );
			$tool_total          = 0;

			$kind_totals = array(
				'read'        => 0,
				'write'       => 0,
				'destructive' => 0,
			);

			foreach ( $tools as $pack_tools ) {
				$tool_total += count( $pack_tools );

				foreach ( $pack_tools as $pack_tool ) {
					++$kind_totals[ $pack_tool['kind'] ];
				}
			}

			$server        = CPAF_Framework::MCP_REST_NAMESPACE;
			$rest_endpoint = rest_url( 'wp-abilities/v1/abilities' );
			$mcp_endpoint  = rest_url( $server . '/' . CPAF_Framework::MCP_REST_ROUTE );
			$user          = wp_get_current_user();
			$username      = $user instanceof WP_User && $user->user_login ? $user->user_login : 'your-username';
			$json_flags    = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

			// Runs a local proxy: for Claude Desktop and similar clients.
			$stdio_json = (string) wp_json_encode(
				array(
					'mcpServers' => array(
						$server => array(
							'command' => 'npx',
							'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
							'env'     => array(
								'WP_API_URL'      => $mcp_endpoint,
								'WP_API_USERNAME' => $username,
								'WP_API_PASSWORD' => 'xxxx xxxx xxxx xxxx xxxx xxxx',
							),
						),
					),
				),
				$json_flags
			);

			// A visible placeholder, not a realistic Base64 blob a user could paste unchanged.
			$http_json = (string) wp_json_encode(
				array(
					'mcpServers' => array(
						$server => array(
							'type'    => 'http',
							'url'     => $mcp_endpoint,
							'headers' => array(
								'Authorization' => 'Basic REPLACE_WITH_BASE64_OF_' . $username . ':APPLICATION_PASSWORD',
							),
						),
					),
				),
				$json_flags
			);
			?>
			<div class="wrap">
				<div class="cpaf">

					<div class="cpaf-topbar">
						<div class="cpaf-topbar-left">
							<span class="cpaf-topbar-icon"><?php echo self::icon( 'translate' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from icon(). ?></span>
							<span class="cpaf-topbar-name"><?php echo esc_html( CPAF_Framework::t( 'Translation MCP' ) ); ?></span>
						</div>
						<div class="cpaf-topbar-right">
							<a class="cpaf-btn" href="https://coolplugins.net/products/" target="_blank" rel="noopener noreferrer"><?php echo esc_html( CPAF_Framework::t( 'Explore Cool Plugins' ) ); ?></a>
							<a class="cpaf-btn" href="https://docs.coolplugins.net/" target="_blank" rel="noopener noreferrer"><?php echo self::icon( 'book' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from icon(). ?><?php echo esc_html( CPAF_Framework::t( 'Docs' ) ); ?></a>
							<a class="cpaf-btn" href="https://coolplugins.net/support/" target="_blank" rel="noopener noreferrer"><?php echo esc_html( CPAF_Framework::t( 'Support' ) ); ?></a>
						</div>
					</div>

					<header class="cpaf-header">
							<div class="cpaf-header-text">
								<h1><?php echo esc_html( CPAF_Framework::t( 'Connect AI to your translations' ) ); ?></h1>
								<p class="cpaf-lead"><?php echo esc_html( CPAF_Framework::t( 'Translate pages, find missing translations, and manage languages just by chatting with Claude, Codex, or Cursor.' ) ); ?></p>
								<p class="cpaf-assure"><?php echo self::icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from icon(). ?><span><?php echo esc_html( CPAF_Framework::t( 'AI apps can only do what your WordPress user role allows.' ) ); ?></span></p>
							</div>
							<?php if ( $tool_total > 0 ) : ?>
								<a class="cpaf-header-link" href="#cpaf-tools">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: number of tools. */
											CPAF_Framework::t( 'Browse %d tools' ),
											$tool_total
										)
									);
									echo self::icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from icon().
									?>
								</a>
							<?php endif; ?>
						</header>
						<hr class="wp-header-end">

					<div class="cpaf-stats">
							<?php
							self::stat(
								CPAF_Framework::t( 'WordPress support' ),
								$abilities_available ? 'ok' : 'bad',
								$abilities_available ? CPAF_Framework::t( 'Available' ) : CPAF_Framework::t( 'Not available' ),
								$abilities_available
									? sprintf(
										/* translators: %s: WordPress version. */
										CPAF_Framework::t( 'WordPress %s · Abilities API' ),
										get_bloginfo( 'version' )
									)
									: CPAF_Framework::t( 'Requires WordPress 6.9 or later.' ),
								'bolt'
							);
							self::stat(
								CPAF_Framework::t( 'Connected plugins' ),
								empty( $packs ) ? 'warn' : 'ok',
								empty( $packs )
									? CPAF_Framework::t( 'None active' )
									: sprintf(
										/* translators: %d: number of active translation plugins. */
										CPAF_Framework::t( '%d active' ),
										count( $packs )
									),
								empty( $packs )
									? CPAF_Framework::t( 'No supported translation plugin is ready.' )
									: implode( ', ', wp_list_pluck( $packs, 'label' ) ),
								'layers'
							);
							self::stat(
								CPAF_Framework::t( 'AI tools' ),
								$tool_total > 0 ? 'ok' : 'warn',
								(string) $tool_total,
								CPAF_Framework::t( 'Available to AI assistants' ),
								'wrench'
							);
							self::stat(
								CPAF_Framework::t( 'MCP connection' ),
								! $adapter_present ? 'warn' : ( $adapter_compatible ? 'ok' : 'bad' ),
								! $adapter_present ? CPAF_Framework::t( 'Not installed' ) : ( $adapter_compatible ? CPAF_Framework::t( 'Detected' ) : CPAF_Framework::t( 'Outdated' ) ),
								! $adapter_present
									? CPAF_Framework::t( 'Install the MCP Adapter to connect AI apps.' )
									: ( $adapter_compatible
										? sprintf(
											/* translators: %s: MCP Adapter version. */
											CPAF_Framework::t( 'Version %s · Ready to connect' ),
											$adapter_version ? $adapter_version : CPAF_Framework::t( 'unknown' )
										)
										: sprintf(
											/* translators: 1: MCP Adapter version, 2: plugin that bundles it. */
											CPAF_Framework::t( 'Version %1$s from %2$s is too old. Update or disable that copy.' ),
											$adapter_version ? $adapter_version : CPAF_Framework::t( 'unknown' ),
											$adapter_source ? $adapter_source : CPAF_Framework::t( 'another plugin' )
										)
									),
								'plug'
							);
							?>
						</div>

						<section class="cpaf-card cpaf-connect" id="cpaf-connect">
						<div class="cpaf-card-head">
							<h2><?php echo esc_html( CPAF_Framework::t( 'Connect an AI app' ) ); ?></h2>
							<p class="cpaf-hint"><?php echo esc_html( CPAF_Framework::t( 'Pick your AI app, copy the setup prompt, and paste it into the app. Takes about a minute.' ) ); ?></p>
						</div>
						<div class="cpaf-card-body">

						<?php if ( $adapter_present && ! $adapter_compatible ) : ?>
							<div class="cpaf-notice is-error">
								<strong><?php echo esc_html( CPAF_Framework::t( 'An outdated MCP Adapter is loaded.' ) ); ?></strong>
								<?php echo esc_html( sprintf( CPAF_Framework::t( 'Version %s from %s is active. This framework requires MCP Adapter 0.7.0 or newer. The loaded copy may be bundled by another plugin, such as Elementor.' ), $adapter_version ? $adapter_version : CPAF_Framework::t( 'unknown' ), $adapter_source ? $adapter_source : CPAF_Framework::t( 'an unknown plugin' ) ) ); ?>
								<?php
								$outdated_action = CPAF_Adapter_Installer::can_manage() ? CPAF_Adapter_Installer::outdated_action() : '';
								if ( '' !== $outdated_action ) :
									$outdated_labels = array(
										'install'  => CPAF_Framework::t( 'Install latest version' ),
										'activate' => CPAF_Framework::t( 'Activate latest version' ),
										'update'   => CPAF_Framework::t( 'Update to latest version' ),
									);
									?>
									<p>
										<button type="button" class="button button-primary" data-cpaf-adapter="<?php echo esc_attr( $outdated_action ); ?>"><?php echo esc_html( $outdated_labels[ $outdated_action ] ); ?></button>
									</p>
									<p class="cpaf-adapter-message" data-cpaf-adapter-message role="alert" hidden></p>
								<?php endif; ?>
							</div>
						<?php elseif ( ! $adapter_present ) : ?>
							<?php
							$adapter_state = CPAF_Adapter_Installer::state();
							$can_manage    = CPAF_Adapter_Installer::can_manage();
							?>
							<div class="cpaf-notice is-info">
								<strong><?php echo esc_html( CPAF_Framework::t( 'The MCP Adapter plugin is needed first.' ) ); ?></strong>
								<?php if ( 'inactive' === $adapter_state ) : ?>
									<?php echo esc_html( CPAF_Framework::t( 'It is installed but not active. Activate it and the server is published automatically.' ) ); ?>
								<?php else : ?>
									<?php echo esc_html( CPAF_Framework::t( 'It is the transport AI apps use to reach these tools. Install it from here. The server is published automatically. The tools already work over the WordPress REST API without it.' ) ); ?>
								<?php endif; ?>
								<p>
									<?php if ( $can_manage && 'inactive' === $adapter_state ) : ?>
										<button type="button" class="button button-primary" data-cpaf-adapter="activate"><?php echo esc_html( CPAF_Framework::t( 'Activate MCP Adapter' ) ); ?></button>
									<?php elseif ( $can_manage ) : ?>
										<button type="button" class="button button-primary" data-cpaf-adapter="install"><?php echo esc_html( CPAF_Framework::t( 'Install and activate MCP Adapter' ) ); ?></button>
									<?php endif; ?>
								</p>
								<p class="cpaf-adapter-message" data-cpaf-adapter-message role="alert" hidden></p>
							</div>
						<?php endif; ?>

						<ol class="cpaf-steps">
							<li class="is-done" data-cpaf-step="app">
								<h3 class="cpaf-step-title"><?php echo esc_html( CPAF_Framework::t( 'Choose AI app' ) ); ?></h3>
								<div class="cpaf-clients" role="radiogroup" aria-label="<?php echo esc_attr( CPAF_Framework::t( 'AI app' ) ); ?>">
									<?php foreach ( CPAF_Connect::clients() as $client_id => $client_label ) : ?>
										<label class="cpaf-client">
											<input type="radio" name="cpaf-client" value="<?php echo esc_attr( $client_id ); ?>" <?php checked( 'claude-code', $client_id ); ?>>
											<span><?php echo esc_html( $client_label ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</li>
							<li data-cpaf-step="prompt">
								<h3 class="cpaf-step-title"><?php echo esc_html( CPAF_Framework::t( 'Generate setup details' ) ); ?></h3>
								<p class="cpaf-step-text" data-cpaf-generate-intro><?php echo esc_html( CPAF_Framework::t( 'The first time you connect an AI app, WordPress creates a dedicated Application Password for the app. The same password will be used for future connections, and you can rename or revoke it any time from your WordPress profile.' ) ); ?></p>
								<p>
									<button type="button" class="button button-primary" data-cpaf-generate <?php disabled( ! $adapter_compatible ); ?>><?php echo esc_html( CPAF_Framework::t( 'Generate & copy prompt' ) ); ?></button>
								</p>
								<div class="cpaf-notice" id="cpaf-error" role="alert" hidden></div>
								<div id="cpaf-prompt-wrap" hidden>
									<div class="cpaf-ready">
										<span class="cpaf-ready-icon"><?php echo self::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from icon(). ?></span>
										<div class="cpaf-ready-text">
											<strong id="cpaf-ready-title"></strong>
											<span id="cpaf-ready-note"></span>
										</div>
										<div class="cpaf-ready-actions">
											<button type="button" class="cpaf-btn-outline cpaf-copy" data-cpaf-copy="cpaf-prompt" data-cpaf-done="<?php echo esc_attr( CPAF_Framework::t( 'Copied' ) ); ?>"><?php echo self::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from icon(). ?><span><?php echo esc_html( CPAF_Framework::t( 'Copy again' ) ); ?></span></button>
											<button type="button" class="cpaf-link-btn" data-cpaf-toggle="cpaf-term" data-cpaf-show="<?php echo esc_attr( CPAF_Framework::t( 'Show prompt' ) ); ?>" data-cpaf-hide="<?php echo esc_attr( CPAF_Framework::t( 'Hide prompt' ) ); ?>" aria-expanded="false" aria-controls="cpaf-term"><span><?php echo esc_html( CPAF_Framework::t( 'Show prompt' ) ); ?></span><?php echo self::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from icon(). ?></button>
										</div>
									</div>
									<div class="cpaf-privacy">
										<span class="cpaf-privacy-text"><?php echo self::icon( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from icon(). ?><?php echo esc_html( CPAF_Framework::t( 'This prompt contains your app password. Don\'t share it or paste it in public chats.' ) ); ?></span>
										<button type="button" class="cpaf-link-btn" data-cpaf-regenerate><?php echo esc_html( CPAF_Framework::t( 'Create a new password (revokes the old one)' ) ); ?></button>
									</div>
									<p class="cpaf-step-text" id="cpaf-saved-note" hidden><?php echo esc_html( CPAF_Framework::t( 'This AI app already has an Application Password, so a new one was not created.' ) ); ?></p>
									<div class="cpaf-term" id="cpaf-term" hidden>
										<div class="cpaf-term-bar">
											<span class="cpaf-term-title"><span class="cpaf-term-dot" aria-hidden="true"></span><?php echo esc_html( CPAF_Framework::t( 'Setup prompt' ) ); ?></span>
										</div>
										<textarea id="cpaf-prompt" class="cpaf-prompt" rows="9" readonly aria-label="<?php echo esc_attr( CPAF_Framework::t( 'Setup prompt' ) ); ?>"></textarea>
									</div>
									<div class="cpaf-cli" id="cpaf-cli" hidden>
										<span class="cpaf-cli-label"><?php echo esc_html( CPAF_Framework::t( 'Prefer the terminal? Run this instead' ) ); ?></span>
										<?php self::code_block( 'cpaf-cli-cmd', '' ); ?>
									</div>
								</div>
							</li>
							<li data-cpaf-step="connect">
								<h3 class="cpaf-step-title"><?php echo esc_html( CPAF_Framework::t( 'Paste it into your AI app' ) ); ?></h3>
								<p class="cpaf-step-text" data-cpaf-paste-intro><?php echo esc_html( CPAF_Framework::t( 'Paste the setup prompt into your AI app. The connection will be saved automatically, and this page will confirm when it is ready.' ) ); ?></p>
								<div class="cpaf-status" id="cpaf-status" role="status" aria-live="polite" hidden></div>
								<div class="cpaf-try" id="cpaf-try" hidden>
									<span class="cpaf-try-label"><?php echo esc_html( CPAF_Framework::t( 'Try asking' ) ); ?> <span id="cpaf-try-client"></span></span>
									<div class="cpaf-try-list">
										<?php
										$try_prompts = array(
											CPAF_Framework::t( 'Show posts missing a French translation' ),
											CPAF_Framework::t( 'Translate all draft pages to Spanish' ),
											CPAF_Framework::t( 'Which languages are active on this site?' ),
										);
										foreach ( $try_prompts as $try_index => $try_prompt ) :
											$try_id = 'cpaf-try-' . ( $try_index + 1 );
											?>
											<div class="cpaf-try-item">
												<span class="cpaf-try-text" id="<?php echo esc_attr( $try_id ); ?>"><?php echo esc_html( $try_prompt ); ?></span>
												<button type="button" class="cpaf-icon-btn cpaf-copy" data-cpaf-copy="<?php echo esc_attr( $try_id ); ?>" data-cpaf-done="<?php echo esc_attr( CPAF_Framework::t( 'Copied' ) ); ?>" data-cpaf-icon aria-label="<?php echo esc_attr( CPAF_Framework::t( 'Copy' ) ); ?>"><?php echo self::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from icon(). ?></button>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
							</li>
						</ol>

						<details class="cpaf-manual">
							<summary>
								<span class="cpaf-manual-title"><?php echo esc_html( CPAF_Framework::t( 'Set up manually instead' ) ); ?></span>
								<span class="cpaf-manual-note"><?php echo esc_html( CPAF_Framework::t( 'Collapsed by default · for advanced users and other apps' ) ); ?></span>
							</summary>
							<ol class="cpaf-mini-steps">
								<li>
									<?php echo esc_html( CPAF_Framework::t( 'Create an app password' ) ); ?>
									<span class="cpaf-mini-note">
										<?php
										printf(
											/* translators: %s: link to the current user's profile page. */
											wp_kses(
												CPAF_Framework::t( 'Create one on <a href="%s">your profile</a>' ),
												array( 'a' => array( 'href' => array() ) )
											),
											esc_url( admin_url( 'profile.php#application-passwords-section' ) )
										);
										?>
									</span>
								</li>
								<li><?php echo esc_html( CPAF_Framework::t( 'Copy the config below into your app\'s MCP settings and replace the password placeholder' ) ); ?></li>
								<li><?php echo esc_html( CPAF_Framework::t( 'Restart the app' ) ); ?></li>
							</ol>
							<?php self::copy_field( 'cpaf-mcp-url', CPAF_Framework::t( 'MCP address' ), $mcp_endpoint ); ?>
							<details class="cpaf-advanced">
								<summary><?php echo esc_html( CPAF_Framework::t( 'Advanced: REST address' ) ); ?></summary>
								<?php self::copy_field( 'cpaf-rest-url', CPAF_Framework::t( 'REST address' ), $rest_endpoint ); ?>
							</details>

							<div class="cpaf-tabs" role="tablist" aria-label="<?php echo esc_attr( CPAF_Framework::t( 'Connection type' ) ); ?>">
								<button type="button" class="cpaf-tab" role="tab" id="cpaf-tab-stdio" aria-controls="cpaf-panel-stdio" aria-selected="true"><?php echo esc_html( CPAF_Framework::t( 'Local app (npx)' ) ); ?></button>
								<button type="button" class="cpaf-tab" role="tab" id="cpaf-tab-http" aria-controls="cpaf-panel-http" aria-selected="false"><?php echo esc_html( CPAF_Framework::t( 'Remote (HTTP)' ) ); ?></button>
							</div>
							<div class="cpaf-panel" role="tabpanel" id="cpaf-panel-stdio" aria-labelledby="cpaf-tab-stdio">
								<p><?php echo esc_html( CPAF_Framework::t( 'Works with Claude Desktop, Cursor and similar apps. Needs Node.js installed.' ) ); ?></p>
								<div class="cpaf-chips">
									<span class="cpaf-chip"><strong><?php echo esc_html( CPAF_Framework::t( 'Claude Desktop:' ) ); ?></strong> <?php echo esc_html( CPAF_Framework::t( 'Settings › Developer › Edit Config' ) ); ?></span>
									<span class="cpaf-chip"><strong><?php echo esc_html( CPAF_Framework::t( 'Cursor:' ) ); ?></strong> ~/.cursor/mcp.json</span>
								</div>
								<?php self::code_block( 'cpaf-json-stdio', $stdio_json ); ?>
							</div>
							<div class="cpaf-panel" role="tabpanel" id="cpaf-panel-http" aria-labelledby="cpaf-tab-http" hidden>
								<p><?php echo esc_html( CPAF_Framework::t( 'No proxy needed. The Authorization value is your username and Application Password joined by a colon, then Base64 encoded. Pasting the placeholder unchanged returns HTTP 401.' ) ); ?></p>
								<?php self::code_block( 'cpaf-json-http', $http_json ); ?>
							</div>
						</details>

<?php if ( ! is_ssl() ) : ?>
							<div class="cpaf-notice is-icon"><?php echo self::icon( 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from icon(). ?><span><?php echo esc_html( CPAF_Framework::t( 'This site is not served over HTTPS, so WordPress may refuse to create Application Passwords, and a password would travel in clear text. That is fine for local development, but do not use it on a public site.' ) ); ?></span></div>
						<?php endif; ?>
						</div>
					</section>

					<div class="cpaf-tools-bar" id="cpaf-tools">
						<div>
							<h2 class="cpaf-section-title"><?php echo esc_html( CPAF_Framework::t( 'Available tools' ) ); ?></h2>
							<p class="cpaf-foot"><?php echo esc_html( CPAF_Framework::t( 'What your AI app can do on this site. Access depends on your user role: content tools require edit access, while site-wide settings require administrator access.' ) ); ?></p>
						</div>
						<?php if ( $tool_total > 0 ) : ?>
							<div class="cpaf-filters">
								<span class="cpaf-search-wrap">
									<?php echo self::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from icon(). ?>
									<input type="search" class="cpaf-search" placeholder="<?php echo esc_attr( CPAF_Framework::t( 'Search tools or tasks...' ) ); ?>" aria-label="<?php echo esc_attr( CPAF_Framework::t( 'Search tools' ) ); ?>">
								</span>
								<div class="cpaf-segment" role="group" aria-label="<?php echo esc_attr( CPAF_Framework::t( 'Filter by permission' ) ); ?>">
									<button type="button" class="cpaf-segment-btn" data-cpaf-kind-filter="" aria-pressed="true"><?php echo esc_html( CPAF_Framework::t( 'All' ) . ' ' . $tool_total ); ?></button>
									<button type="button" class="cpaf-segment-btn" data-cpaf-kind-filter="read" aria-pressed="false"><?php echo esc_html( CPAF_Framework::t( 'Read only' ) . ' ' . $kind_totals['read'] ); ?></button>
									<button type="button" class="cpaf-segment-btn" data-cpaf-kind-filter="change" aria-pressed="false"><?php echo esc_html( CPAF_Framework::t( 'Changes site' ) . ' ' . ( $kind_totals['write'] + $kind_totals['destructive'] ) ); ?></button>
								</div>
							</div>
						<?php endif; ?>
					</div>

					<?php if ( $tool_total > 0 ) : ?>
						<div class="cpaf-banner is-read" data-cpaf-banner="read" hidden>
							<?php echo self::icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from icon(). ?>
							<span>
								<strong>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: number of read-only tools. */
											CPAF_Framework::t( 'These %d tools only read information.' ),
											$kind_totals['read']
										)
									);
									?>
								</strong>
								<?php echo esc_html( CPAF_Framework::t( 'They never change anything on your site.' ) ); ?>
							</span>
						</div>
						<div class="cpaf-banner is-change" data-cpaf-banner="change" hidden>
							<?php echo self::icon( 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from icon(). ?>
							<span>
								<strong>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: number of tools that change the site. */
											CPAF_Framework::t( 'These %d tools create or update content on your site.' ),
											$kind_totals['write'] + $kind_totals['destructive']
										)
									);
									?>
								</strong>
								<?php echo esc_html( CPAF_Framework::t( 'They only run for users whose role allows the change.' ) ); ?>
							</span>
						</div>
					<?php endif; ?>

					<?php if ( empty( $packs ) ) : ?>
						<div class="cpaf-card cpaf-empty">
							<strong><?php echo esc_html( CPAF_Framework::t( 'No tools yet' ) ); ?></strong>
							<?php echo esc_html( CPAF_Framework::t( 'Tools appear here when a supported translation plugin is active and ready.' ) ); ?>
						</div>
					<?php endif; ?>

					<?php foreach ( $packs as $pack ) : ?>
						<?php $pack_tools = $tools[ $pack['id'] ]; ?>
						<section class="cpaf-pack" data-cpaf-total="<?php echo esc_attr( (string) count( $pack_tools ) ); ?>">
							<div class="cpaf-pack-head">
								<div>
									<h3 class="cpaf-pack-name"><?php echo esc_html( $pack['label'] ); ?></h3>
									<?php if ( ! empty( $pack['description'] ) ) : ?>
										<p class="cpaf-pack-desc"><?php echo esc_html( $pack['description'] ); ?></p>
									<?php endif; ?>
								</div>
								<span class="cpaf-pack-count" data-cpaf-showing="<?php echo esc_attr( CPAF_Framework::t( 'Showing %1$s of %2$s tools' ) ); ?>" data-cpaf-grand="<?php echo esc_attr( (string) $tool_total ); ?>">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: tools shown in this tool set, 2: total tools. */
											CPAF_Framework::t( 'Showing %1$s of %2$s tools' ),
											count( $pack_tools ),
											$tool_total
										)
									);
									?>
								</span>
							</div>
							<?php foreach ( $pack_tools as $tool ) : ?>
								<?php $info = self::kind_info( $tool['kind'] ); ?>
								<details class="cpaf-tool" data-cpaf-kind="<?php echo esc_attr( $tool['kind'] ); ?>" data-cpaf-search="<?php echo esc_attr( strtolower( $tool['name'] . ' ' . $tool['label'] . ' ' . $tool['description'] ) ); ?>">
									<summary>
										<span class="cpaf-tool-main">
											<span class="cpaf-tool-title"><?php echo esc_html( $tool['label'] ); ?></span>
											<span class="cpaf-tool-desc"><?php echo esc_html( self::short_description( $tool['description'] ) ); ?> <code class="cpaf-tool-name"><?php echo esc_html( $tool['name'] ); ?></code></span>
										</span>
										<?php if ( 'read' !== $tool['kind'] ) : ?>
											<span class="cpaf-badge <?php echo esc_attr( $info[1] ); ?>"><?php echo esc_html( $info[0] ); ?></span>
										<?php endif; ?>
									</summary>
									<div class="cpaf-tool-body">
										<?php $example = self::example( $tool['name'] ); ?>
										<?php if ( $example ) : ?>
											<?php $example_id = 'cpaf-ex-' . sanitize_html_class( str_replace( '/', '-', $tool['name'] ) ); ?>
											<div class="cpaf-examples">
												<div class="cpaf-example">
													<span class="cpaf-example-label"><?php echo esc_html( CPAF_Framework::t( 'Try asking' ) ); ?></span>
													<div class="cpaf-example-row">
														<span class="cpaf-try-text" id="<?php echo esc_attr( $example_id ); ?>"><?php echo esc_html( CPAF_Framework::t( $example['try'] ) ); ?></span>
														<button type="button" class="cpaf-icon-btn cpaf-copy" data-cpaf-copy="<?php echo esc_attr( $example_id ); ?>" data-cpaf-done="<?php echo esc_attr( CPAF_Framework::t( 'Copied' ) ); ?>" data-cpaf-icon aria-label="<?php echo esc_attr( CPAF_Framework::t( 'Copy' ) ); ?>"><?php echo self::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from icon(). ?></button>
													</div>
												</div>
												<div class="cpaf-example">
													<span class="cpaf-example-label"><?php echo esc_html( CPAF_Framework::t( 'What it returns' ) ); ?></span>
													<ul>
														<?php foreach ( $example['returns'] as $returns_line ) : ?>
															<li><?php echo esc_html( CPAF_Framework::t( $returns_line ) ); ?></li>
														<?php endforeach; ?>
													</ul>
												</div>
											</div>
										<?php endif; ?>
										<?php if ( 'read' !== $tool['kind'] ) : ?>
											<p class="cpaf-step-text"><?php echo esc_html( $info[2] ); ?></p>
										<?php endif; ?>
										<details class="cpaf-tool-full">
											<summary><?php echo esc_html( CPAF_Framework::t( 'Full instructions sent to AI' ) ); ?></summary>
											<p><?php echo esc_html( $tool['description'] ); ?></p>
										</details>
									</div>
								</details>
							<?php endforeach; ?>
						</section>
					<?php endforeach; ?>

<?php if ( $tool_total > 0 ) : ?>
						<p class="cpaf-no-results cpaf-empty" hidden><?php echo esc_html( CPAF_Framework::t( 'No tools match your search.' ) ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}
	}
}
