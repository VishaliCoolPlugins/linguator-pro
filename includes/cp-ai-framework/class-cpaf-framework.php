<?php
/**
 * Cool Plugins AI framework: core.
 *
 * @package CoolPluginsAiFramework
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CPAF_Framework' ) ) {

	/**
	 * Registers the abilities of every active translation plugin ("pack") and
	 * publishes them on one MCP server.
	 *
	 * Booted once per request by cpaf_boot(), from the highest-version copy.
	 *
	 * @since 1.0.0
	 */
	final class CPAF_Framework {

		/**
		 * MCP server id.
		 *
		 * @since 1.0.0
		 * @var string
		 */
		const MCP_SERVER_ID = 'cool-translation-mcp-server';

		/**
		 * MCP REST namespace.
		 *
		 * @since 1.0.0
		 * @var string
		 */
		const MCP_REST_NAMESPACE = 'cool-translation';

		/**
		 * MCP REST route.
		 *
		 * @since 1.0.0
		 * @var string
		 */
		const MCP_REST_ROUTE = 'mcp';

		/**
		 * The registration that won: version, dir, text_domain, domain_path.
		 *
		 * @since 1.0.0
		 * @var array
		 */
		private static $winner = array();

		/**
		 * Whether boot() already ran.
		 *
		 * @since 1.0.0
		 * @var bool
		 */
		private static $booted = false;

		/**
		 * Starts the framework.
		 *
		 * @since 1.0.0
		 * @param array $winner        Winning registration.
		 * @param array $registrations Every registration, winner first.
		 * @return void
		 */
		public static function boot( array $winner, array $registrations ) {
			if ( self::$booted ) {
				return;
			}

			self::$booted = true;
			self::$winner = $winner;

			// Every registering plugin gets its own text domain loaded now, so a
			// plugin running alone is translated from the moment it registers.
			foreach ( $registrations as $registration ) {
				self::load_text_domain( (string) $registration['text_domain'], (string) $registration['domain_path'] );
			}

			require_once $winner['dir'] . '/class-cpaf-autoloader.php';
			CPAF_Autoloader::register( $winner['dir'] );

			require_once $winner['dir'] . '/class-cpaf-connect.php';
			CPAF_Connect::init();

			require_once $winner['dir'] . '/class-cpaf-adapter-installer.php';
			CPAF_Adapter_Installer::init();

			require_once $winner['dir'] . '/class-cpaf-tools-page.php';
			CPAF_Tools_Page::init();

			// The Abilities API landed in WordPress 6.9.
			if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}

			/*
			 * Priority 20, after any plugin that still registers its own abilities
			 * at the default 10. Names already taken are skipped, so an older
			 * plugin next to a framework-aware one never causes a duplicate notice.
			 */
			add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_categories' ), 20, 0 );
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 20, 0 );

			// Registered unconditionally: the adapter may load after this plugin.
			add_action( 'mcp_adapter_init', array( __CLASS__, 'create_mcp_server' ), 10, 1 );
		}

		/**
		 * Version of the copy that is running.
		 *
		 * @since 1.0.0
		 * @return string Version, or an empty string before boot.
		 */
		public static function version() {
			return isset( self::$winner['version'] ) ? (string) self::$winner['version'] : '';
		}

		/**
		 * Absolute path to the folder of the copy that is running.
		 *
		 * @since 1.4.0
		 * @return string Path without a trailing slash, or an empty string before boot.
		 */
		public static function dir() {
			return isset( self::$winner['dir'] ) ? (string) self::$winner['dir'] : '';
		}

		/**
		 * Translates a framework string with the winning plugin's text domain.
		 *
		 * The domain is a variable on purpose: only the winner's translations are
		 * guaranteed to be loaded. Strings passed here are extracted by the
		 * plugin's own make-pot run, which must scan this folder.
		 *
		 * @since 1.0.0
		 * @param string $text Text to translate.
		 * @return string Translated text.
		 */
		public static function t( $text ) {
			$domain = isset( self::$winner['text_domain'] ) ? (string) self::$winner['text_domain'] : '';

			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain -- Shared framework string; domain is the winning plugin's.
			return __( $text, $domain );
		}

		/**
		 * Loads one plugin's translations unless they are already loaded.
		 *
		 * @since 1.0.0
		 * @param string $domain      Text domain.
		 * @param string $domain_path Absolute path to the languages folder.
		 * @return void
		 */
		private static function load_text_domain( $domain, $domain_path ) {
			if ( '' === $domain || '' === $domain_path || is_textdomain_loaded( $domain ) ) {
				return;
			}

			load_textdomain( $domain, $domain_path . '/' . $domain . '-' . determine_locale() . '.mo' );
		}

		/**
		 * Packs that can be offered right now.
		 *
		 * A pack is one plugin's set of abilities:
		 * array(
		 *     'id'           => 'polylang',
		 *     'label'        => 'AutoPoly (Polylang)',
		 *     'description'  => 'One line for the Tools page', // optional
		 *     'namespace'    => 'autopoly',                // ability name prefix
		 *     'category'     => array( 'slug' => ..., 'label' => ..., 'description' => ... ),
		 *     'is_available' => callable returning bool,   // host active, switch on
		 *     'abilities'    => array( Ability_Class::class, ... ),
		 * )
		 * Packs are added to the list below one plugin at a time.
		 *
		 * @since 1.0.0
		 * @return array<int, array<string, mixed>> Available packs.
		 */
		public static function packs() {
			$packs = array(
				\CoolPlugins\AiFramework\Packs\Polylang\Pack::definition(),
				\CoolPlugins\AiFramework\Packs\Linguator\Pack::definition(),
				\CoolPlugins\AiFramework\Packs\LinguatorInspector\Pack::definition(),
				\CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Pack::definition(),
				\CoolPlugins\AiFramework\Packs\Wpml\Pack::definition(),
				\CoolPlugins\AiFramework\Packs\Loco\Pack::definition(),
				\CoolPlugins\AiFramework\Packs\Inspector\Pack::definition(),
			);

			return array_values(
				array_filter(
					$packs,
					function ( $pack ) {
						return is_callable( $pack['is_available'] ) && (bool) call_user_func( $pack['is_available'] );
					}
				)
			);
		}

		/**
		 * Fully qualified names of every ability the available packs provide.
		 *
		 * @since 1.0.0
		 * @return string[] Ability names.
		 */
		public static function ability_names() {
			$names = array();

			foreach ( self::packs() as $pack ) {
				foreach ( $pack['abilities'] as $class_name ) {
					$ability = new $class_name();
					$names[] = $ability->get_name();
				}
			}

			return $names;
		}

		/**
		 * Registers each available pack's category.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public static function register_categories() {
			foreach ( self::packs() as $pack ) {
				$category = $pack['category'];

				if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( $category['slug'] ) ) {
					continue;
				}

				wp_register_ability_category(
					$category['slug'],
					array(
						'label'       => $category['label'],
						'description' => $category['description'],
					)
				);
			}
		}

		/**
		 * Registers every available pack's abilities.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public static function register_abilities() {
			foreach ( self::packs() as $pack ) {
				foreach ( $pack['abilities'] as $class_name ) {
					$ability = new $class_name();

					if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability->get_name() ) ) {
						continue;
					}

					wp_register_ability( $ability->get_name(), $ability->get_args() );
				}
			}
		}

		/**
		 * Whether an MCP adapter is loaded in this request.
		 *
		 * @since 1.0.0
		 * @return bool True when an adapter class exists.
		 */
		public static function adapter_available() {
			return class_exists( 'WP\\MCP\\Core\\McpAdapter' );
		}

		/**
		 * Returns the MCP Adapter version that actually won class loading.
		 *
		 * @since 1.0.0
		 * @return string Loaded adapter version, or an empty string.
		 */
		public static function adapter_version() {
			$class = 'WP\\MCP\\Core\\McpAdapter';

			if ( ! class_exists( $class ) ) {
				return '';
			}

			$constant = $class . '::VERSION';

			return defined( $constant ) ? (string) constant( $constant ) : '';
		}

		/**
		 * Whether the loaded adapter supports the framework's current MCP contract.
		 *
		 * @since 1.0.0
		 * @return bool True when MCP Adapter 0.7.0 or newer is loaded.
		 */
		public static function adapter_is_compatible() {
			$version = self::adapter_version();

			return '' !== $version && version_compare( $version, '0.7.0', '>=' );
		}

		/**
		 * Returns the directory of the adapter copy that won class loading.
		 *
		 * @since 1.0.0
		 * @return string Adapter directory, or an empty string when unavailable.
		 */
		public static function adapter_directory() {
			$class = 'WP\\MCP\\Core\\McpAdapter';

			if ( ! class_exists( $class ) ) {
				return '';
			}

			$constant = $class . '::DIR';

			if ( defined( $constant ) ) {
				return (string) constant( $constant );
			}

			// Older bundled adapters do not expose McpAdapter::DIR, but their
			// bootstrap defines WP_MCP_DIR before loading the class.
			if ( defined( 'WP_MCP_DIR' ) ) {
				return (string) WP_MCP_DIR;
			}

			// Composer-loaded older copies may define neither constant. Reflection
			// still gives us the physical file that won class loading.
			$reflection = new ReflectionClass( $class );
			$file       = $reflection->getFileName();

			return $file ? dirname( dirname( dirname( $file ) ) ) : '';
		}

		/**
		 * Returns the plugin that supplied the loaded adapter copy.
		 *
		 * @since 1.0.0
		 * @return string Plugin name, or an empty string.
		 */
		public static function adapter_source_name() {
			$directory = self::adapter_directory();

			if ( '' === $directory ) {
				return '';
			}

			$plugin_root = defined( 'WP_PLUGIN_DIR' ) ? wp_normalize_path( WP_PLUGIN_DIR ) : '';
			$directory   = wp_normalize_path( $directory );

			$root_prefix = trailingslashit( $plugin_root );

			if ( '' !== $plugin_root && 0 === strpos( strtolower( $directory ), strtolower( $root_prefix ) ) ) {
				$relative = ltrim( substr( $directory, strlen( $root_prefix ) ), '/' );
				$slug     = strtok( $relative, '/' );

				if ( $slug && ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				if ( $slug && function_exists( 'get_plugins' ) ) {
					$plugins = get_plugins();

					foreach ( $plugins as $file => $headers ) {
						if ( 0 === strpos( $file, $slug . '/' ) && ! empty( $headers['Name'] ) ) {
							return (string) $headers['Name'];
						}
					}
				}

				return sanitize_text_field( ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ) );
			}

			return '';
		}

		/**
		 * The server description, which MCP clients receive as the server instructions.
		 *
		 * Built from the packs that are active, so each tool is named only where
		 * it exists: a site with only Loco Translate must not be told to call a
		 * tool that only the Polylang pack provides.
		 *
		 * @since 1.0.0
		 * @param array<int, array<string, mixed>>|null $packs Packs to describe; the available packs when null.
		 * @return string One short paragraph.
		 */
		private static function server_description( $packs = null ) {
			$parts = array( self::t( 'AI translation tools for the translation plugins active on this site.' ) );
			$packs = null === $packs ? self::packs() : $packs;

			foreach ( $packs as $pack ) {
				switch ( $pack['id'] ) {
					case 'polylang':
						$parts[] = self::t( 'AutoPoly (Polylang): for any multi-page job (homepage, header, footer, menu pages) first call get-site-info and follow its translation_workflow, then get-translation-plan. A successful save is not completion: read the outcome of every write and finish with verify-translations.' );
						break;
					case 'linguator':
						$parts[] = self::t( 'Linguator Pro: call get-site-info first and follow its translation_workflow. A successful save is not completion: read the result of every write and finish with get-translation-status.' );
						break;
					case 'linguator-inspector':
						$parts[] = self::t( 'Linguator Inspector: start with get-inspector-status, then run-scan, get-issues and get-fix-plan. Fix with fix-issue (dry run first) and confirm with recheck-issue.' );
						break;
					case 'linguator-woocommerce':
						$parts[] = self::t( 'Linguator WooCommerce: call get-woocommerce-status first. After translate-post creates a product translation, call sync-product to build its variations and attributes, then translate-product-attributes for the attribute texts.' );
						break;
					case 'loco':
						$parts[] = self::t( 'LocoAI (Loco Translate): translates the text of plugins, themes and WordPress core through language files, not posts or pages. Call get-site-info first, then list-projects and get-project-status. A successful save is not completion: check rejected and not_found in every save-translations result and confirm the counts with get-project-status.' );
						break;
					case 'wpml':
						$parts[] = self::t( 'AutoMLP (WPML): call get-site-info first and fix any unmet requirement it names, then follow its translation_workflow. For a multi-page job (homepage, header, footer, menu pages) call get-translation-plan. New translations are drafts unless you pass post_status publish. A successful save is not completion: read the result of every write and finish with verify-translations.' );
						break;
					case 'inspector':
						$parts[] = self::t( 'Translation Inspector: start with get-inspector-status.' );
						break;
				}
			}

			return implode( ' ', $parts );
		}

		/**
		 * Creates the single MCP server with the tools of every available pack.
		 *
		 * Does nothing without packs, so a site with no active translation
		 * plugin exposes no empty server.
		 *
		 * @since 1.0.0
		 * @param object $adapter MCP adapter instance, normally WP\MCP\Core\McpAdapter.
		 * @return void
		 */
		public static function create_mcp_server( $adapter ) {
			if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
				return;
			}

			$names = self::ability_names();

			if ( empty( $names ) ) {
				return;
			}

			$transport     = 'WP\\MCP\\Transport\\HttpTransport';
			$error_handler = 'WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler';
			$observability = 'WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler';

			// An adapter with an unexpected shape is not an error worth surfacing.
			if ( ! class_exists( $transport ) || ! class_exists( $error_handler ) || ! class_exists( $observability ) ) {
				return;
			}

			$adapter->create_server(
				self::MCP_SERVER_ID,
				self::MCP_REST_NAMESPACE,
				self::MCP_REST_ROUTE,
				self::t( 'Cool Plugins Translation Server' ),
				self::server_description(),
				self::version(),
				array( $transport ),
				$error_handler,
				$observability,
				$names,
				array(),
				array()
			);
		}
	}
}
