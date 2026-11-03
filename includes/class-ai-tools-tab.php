<?php
/**
 * Adds an "AI Tools" tab to the Linguator settings that opens the AI Translation MCP page.
 *
 * @package LinguatorPro
 */

namespace LinguatorPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI Translation MCP page is added by the shared AI framework under Tools. This puts a tab for it in the
 * Linguator settings header, next to General Settings, Static Strings and the other tabs.
 *
 * The header builds every tab link as `admin.php?page=<slug>`, so the tab points at a hidden Linguator page that
 * redirects to the real page. WordPress checks access to a page slug before `admin_init`, so the slug must be a
 * registered admin page, not just a request parameter.
 *
 * @since 2.3.0
 */
class AI_Tools_Tab {

	/**
	 * Page slug the tab links to. It only redirects.
	 */
	const PAGE = 'lmat_ai_tools';

	/**
	 * Header tab key.
	 */
	const TAB = 'ai-tools';

	/**
	 * Hooks the tab and the redirect.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'lmat_settings_header_tabs', array( __CLASS__, 'add_tab' ), 10, 1 );
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 20, 0 );
	}

	/**
	 * URL of the AI Translation MCP page.
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 */
	public static function target_url() {
		$page = class_exists( 'CPAF_Tools_Page' ) ? \CPAF_Tools_Page::PAGE : 'cp-ai-tools';

		return admin_url( 'tools.php?page=' . $page );
	}

	/**
	 * Adds the tab to the Linguator settings header, for users who can open the page.
	 *
	 * @since 2.3.0
	 *
	 * @param array $tabs Header tabs, keyed by tab name.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		if ( ! is_array( $tabs ) || ! class_exists( 'CPAF_Tools_Page' ) || ! current_user_can( 'manage_options' ) ) {
			return $tabs;
		}

		$tabs[ self::TAB ] = array(
			'title'        => __( 'AI Tools', 'linguator-pro' ),
			'redirect'     => true,
			'redirect_url' => self::PAGE,
		);

		return $tabs;
	}

	/**
	 * Registers the hidden page the tab links to, and redirects as soon as it loads.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function register_page() {
		if ( ! class_exists( 'CPAF_Tools_Page' ) ) {
			return;
		}

		// A parent that is a core file keeps the page out of the menu.
		$hook = add_submenu_page(
			'options.php',
			__( 'AI Tools', 'linguator-pro' ),
			__( 'AI Tools', 'linguator-pro' ),
			'manage_options',
			self::PAGE,
			'__return_null'
		);

		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'redirect' ), 10, 0 );
		}
	}

	/**
	 * Sends the tab's page to the AI Translation MCP page, before any output.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function redirect() {
		wp_safe_redirect( self::target_url() );
		exit;
	}
}
