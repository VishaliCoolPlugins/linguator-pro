<?php
/**
 * Cool Plugins AI framework: installs and activates the MCP Adapter plugin.
 *
 * The MCP Adapter (wordpress.org/plugins/mcp-adapter/) is the transport AI apps
 * use to reach the abilities. The AI Translation Tools page offers a one-click
 * install and a one-click activate, so a user does not have to leave the page.
 *
 * @package CoolPluginsAiFramework
 * @since   1.5.1
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CPAF_Adapter_Installer' ) ) {

	/**
	 * Locked to one plugin on purpose: these endpoints can install and activate
	 * the MCP Adapter and nothing else, whatever a request asks for.
	 *
	 * Installing goes through WordPress core's own installer (the same AJAX
	 * handler the Plugins screen uses), after this class has checked the slug.
	 * Activating is a small handler of our own.
	 *
	 * @since 1.5.1
	 */
	final class CPAF_Adapter_Installer {

		/**
		 * WordPress.org slug of the MCP Adapter plugin.
		 *
		 * @since 1.5.1
		 * @var string
		 */
		const SLUG = 'mcp-adapter';

		/**
		 * Plugin basename, as WordPress lists it.
		 *
		 * @since 1.5.1
		 * @var string
		 */
		const PLUGIN_FILE = 'mcp-adapter/mcp-adapter.php';

		/**
		 * Nonce action for the activate request.
		 *
		 * @since 1.5.1
		 * @var string
		 */
		const NONCE_ACTION = 'cpaf_adapter';

		/**
		 * Registers the AJAX endpoints.
		 *
		 * @since 1.5.1
		 * @return void
		 */
		public static function init() {
			// Check the slug first, then hand over to core's installer.
			add_action( 'wp_ajax_cpaf_adapter_install', array( __CLASS__, 'validate_install' ), 1, 0 );
			add_action( 'wp_ajax_cpaf_adapter_install', 'wp_ajax_install_plugin', 10, 0 );
			add_action( 'wp_ajax_cpaf_adapter_activate', array( __CLASS__, 'ajax_activate' ), 10, 0 );
			add_action( 'wp_ajax_cpaf_adapter_update', array( __CLASS__, 'validate_update' ), 1, 0 );
			add_action( 'wp_ajax_cpaf_adapter_update', 'wp_ajax_update_plugin', 10, 0 );
		}

		/**
		 * What the notice for an outdated loaded adapter should offer.
		 *
		 * Looks at the standalone plugin only, because a copy bundled by another
		 * plugin cannot be changed from here.
		 *
		 * @since 1.5.2
		 * @return string "install", "activate", "update" or "" when nothing can help.
		 */
		public static function outdated_action() {
			if ( ! self::is_installed() ) {
				return 'install';
			}

			if ( ! is_plugin_active( self::PLUGIN_FILE ) ) {
				return 'activate';
			}

			$updates = get_site_transient( 'update_plugins' );

			return ( is_object( $updates ) && isset( $updates->response[ self::PLUGIN_FILE ] ) ) ? 'update' : '';
		}

		/**
		 * Refuses any update request that is not for the MCP Adapter.
		 *
		 * Runs before core's updater on the same AJAX action.
		 *
		 * @since 1.5.2
		 * @return void
		 */
		public static function validate_update() {
			check_ajax_referer( 'updates', '_ajax_nonce' );

			if ( ! current_user_can( 'update_plugins' ) ) {
				wp_send_json_error(
					array( 'message' => CPAF_Framework::t( 'You do not have permission to update plugins.' ) ),
					403
				);
			}

			// Nonce verified above.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$plugin = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
			$slug   = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			if ( self::PLUGIN_FILE !== $plugin || self::SLUG !== $slug ) {
				wp_send_json_error(
					array( 'message' => CPAF_Framework::t( 'This page can only update the MCP Adapter plugin.' ) ),
					400
				);
			}
		}

		/**
		 * Where the adapter stands on this site.
		 *
		 * "active" means an adapter is loaded, from this plugin or from any other
		 * plugin that bundles one. "inactive" means the standalone plugin is
		 * installed but switched off. "missing" means it is not installed.
		 *
		 * @since 1.5.1
		 * @return string One of "active", "inactive" or "missing".
		 */
		public static function state() {
			if ( CPAF_Framework::adapter_available() ) {
				return 'active';
			}

			return self::is_installed() ? 'inactive' : 'missing';
		}

		/**
		 * Whether the current user may install and activate plugins.
		 *
		 * @since 1.5.1
		 * @return bool True when both capabilities are held.
		 */
		public static function can_manage() {
			return current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' );
		}

		/**
		 * Whether the standalone plugin is in the plugins folder.
		 *
		 * @since 1.5.1
		 * @return bool True when installed.
		 */
		private static function is_installed() {
			if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			return array_key_exists( self::PLUGIN_FILE, get_plugins() );
		}

		/**
		 * Refuses any install request that is not for the MCP Adapter.
		 *
		 * Runs before core's installer on the same AJAX action.
		 *
		 * @since 1.5.1
		 * @return void
		 */
		public static function validate_install() {
			check_ajax_referer( 'updates', '_ajax_nonce' );

			if ( ! current_user_can( 'install_plugins' ) ) {
				wp_send_json_error(
					array( 'message' => CPAF_Framework::t( 'You do not have permission to install plugins.' ) ),
					403
				);
			}

			// Nonce verified above.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';

			if ( self::SLUG !== $slug ) {
				wp_send_json_error(
					array( 'message' => CPAF_Framework::t( 'This page can only install the MCP Adapter plugin.' ) ),
					400
				);
			}
		}

		/**
		 * AJAX: activates the installed MCP Adapter plugin.
		 *
		 * @since 1.5.1
		 * @return void
		 */
		public static function ajax_activate() {
			check_ajax_referer( self::NONCE_ACTION, 'security' );

			if ( ! current_user_can( 'activate_plugins' ) ) {
				wp_send_json_error(
					array( 'message' => CPAF_Framework::t( 'You do not have permission to activate plugins.' ) ),
					403
				);
			}

			// Nonce verified above.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$init = isset( $_POST['init'] ) ? sanitize_text_field( wp_unslash( $_POST['init'] ) ) : '';

			if ( self::PLUGIN_FILE !== $init ) {
				wp_send_json_error(
					array( 'message' => CPAF_Framework::t( 'This page can only activate the MCP Adapter plugin.' ) ),
					400
				);
			}

			if ( ! function_exists( 'activate_plugin' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			if ( ! self::is_installed() ) {
				wp_send_json_error(
					array( 'message' => CPAF_Framework::t( 'The MCP Adapter plugin is not installed yet.' ) ),
					404
				);
			}

			// Some plugins redirect and exit on activation; keep this request on the page.
			add_filter( 'wp_redirect', '__return_false' );
			$result = activate_plugin( self::PLUGIN_FILE );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
			}

			wp_send_json_success( array( 'message' => CPAF_Framework::t( 'MCP Adapter activated.' ) ) );
		}
	}
}
