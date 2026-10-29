<?php
/**
 * Site Scan admin page.
 *
 * @package LinguatorPro
 */

namespace LinguatorPro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the "Site Scan" screen to the Linguator menu of the free plugin.
 *
 * The free plugin builds the menu and the page shell. Pro hooks into its filters to add the tab,
 * print the mount point and load the scan app instead of the free settings app.
 *
 * @since 2.2.2
 */
class Scan_Page {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE = 'lmat_scan';

	/**
	 * Hooks the page into the free plugin.
	 *
	 * @since 2.2.2
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'lmat_settings_tabs', array( __CLASS__, 'add_tab' ), 20, 1 );
		add_filter( 'lmat_render_languages_page', array( __CLASS__, 'render' ), 10, 3 );
		add_filter( 'lmat_frontend_settings_assets', array( __CLASS__, 'skip_free_assets' ), 10, 3 );
		add_filter( 'lmat_admin_settings_assets', array( __CLASS__, 'skip_free_assets' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20, 0 );
	}

	/**
	 * Whether the current admin request is for the Site Scan page.
	 *
	 * @since 2.2.2
	 *
	 * @return bool
	 */
	private static function is_scan_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check.
		return isset( $_GET['page'] ) && self::PAGE === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * Adds "Site Scan" after "Settings" in the Linguator menu.
	 *
	 * @since 2.2.2
	 *
	 * @param string[] $tabs Sub-menu items, page slugs as keys and titles as values.
	 * @return string[]
	 */
	public static function add_tab( $tabs ) {
		$new = array();
		foreach ( (array) $tabs as $slug => $title ) {
			$new[ $slug ] = $title;
			if ( 'settings' === $slug ) {
				$new['scan'] = __( 'Site Scan', 'linguator-pro' );
			}
		}
		if ( ! isset( $new['scan'] ) ) {
			$new['scan'] = __( 'Site Scan', 'linguator-pro' );
		}
		return $new;
	}

	/**
	 * Prints the scan mount point and stops the free plugin from rendering its own content.
	 *
	 * @since 2.2.2
	 *
	 * @param bool   $status       Whether the free plugin should render the page.
	 * @param string $selected_tab Selected tab.
	 * @param string $active_tab   Active page.
	 * @return bool
	 */
	public static function render( $status, $selected_tab, $active_tab ) {
		if ( 'scan' !== $active_tab || ! self::is_scan_page() ) {
			return $status;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'linguator-pro' ) );
		}

		echo '<div class="wrap lmat-styles"><div id="lmat-site-scan" class="px-8 py-4"></div></div>';

		return false;
	}

	/**
	 * Keeps the free settings app assets off the scan page.
	 *
	 * @since 2.2.2
	 *
	 * @param bool $status Whether to load the assets.
	 * @return bool
	 */
	public static function skip_free_assets( $status ) {
		return self::is_scan_page() ? false : $status;
	}

	/**
	 * Loads the scan app.
	 *
	 * @since 2.2.2
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( ! self::is_scan_page() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$asset_file = LINGUATOR_PRO_DIR . '/admin/assets/frontend/scan/scan.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_style(
			'lmat_scan',
			LINGUATOR_PRO_URL . 'admin/assets/css/build/scan.css',
			array(),
			LINGUATOR_PRO_VERSION
		);
		wp_enqueue_style(
			'lmat_scan_inspector',
			LINGUATOR_PRO_URL . 'admin/assets/css/scan-inspector.css',
			array( 'lmat_scan' ),
			(string) filemtime( LINGUATOR_PRO_DIR . '/admin/assets/css/scan-inspector.css' )
		);
		wp_enqueue_script(
			'lmat_scan',
			LINGUATOR_PRO_URL . 'admin/assets/frontend/scan/scan.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'lmat_scan',
			'lmat_settings',
			array(
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'license_active' => class_exists( '\LinguatorPro\Admin\Register\Linguator_Pro_Base' ) && \LinguatorPro\Admin\Register\Linguator_Pro_Base::is_license_active(),
				'license_url'    => esc_url_raw( admin_url( 'admin.php?page=lmat_settings&tab=license' ) ),
			)
		);
		wp_set_script_translations( 'lmat_scan', 'linguator-pro' );
	}
}
