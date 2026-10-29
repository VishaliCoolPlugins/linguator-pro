<?php
/**
 * Bootstraps Linguator Pro on top of the free Linguator plugin.
 *
 * @package LinguatorPro
 */

namespace LinguatorPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the Pro features once the free Linguator plugin is ready, and tells the admin when it is not.
 *
 * Everything shared (languages, settings, switcher, translation) runs from the free plugin. Pro only adds
 * the Site Scan and the license manager.
 *
 * @since 2.2.2
 */
final class Linguator_Pro {

	/**
	 * Singleton instance.
	 *
	 * @var Linguator_Pro|null
	 */
	private static $instance = null;

	/**
	 * Whether the Pro features have been loaded.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Free plugin model, set on boot.
	 *
	 * @var object|null
	 */
	private $model = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since 2.2.2
	 *
	 * @return Linguator_Pro
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks the dependency notice and waits for the free plugin.
	 *
	 * @since 2.2.2
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_notices', array( $this, 'dependency_notice' ), 10, 0 );
		add_action( 'network_admin_notices', array( $this, 'dependency_notice' ), 10, 0 );

		// The free plugin fires `lmat_init` on plugins_loaded, once its model is ready.
		add_action( 'lmat_init', array( $this, 'boot' ), 10, 1 );
	}

	/**
	 * Whether the free plugin is loaded in a version Pro supports.
	 *
	 * @since 2.2.2
	 *
	 * @return bool
	 */
	public function is_free_ready() {
		return defined( 'LINGUATOR_VERSION' ) && version_compare( LINGUATOR_VERSION, LINGUATOR_PRO_MIN_FREE_VERSION, '>=' );
	}

	/**
	 * Loads the Pro features.
	 *
	 * @since 2.2.2
	 *
	 * @param object $linguator The free plugin's main object.
	 * @return void
	 */
	public function boot( $linguator ) {
		if ( $this->booted || ! $this->is_free_ready() || empty( $linguator->model ) ) {
			return;
		}
		$this->booted = true;
		$this->model  = $linguator->model;

		require_once LINGUATOR_PRO_DIR . '/modules/scan/load.php';
		require_once LINGUATOR_PRO_DIR . '/modules/rest/v1/scan.php';
		// The scan REST route reads the license state, and REST requests are not admin requests.
		require_once LINGUATOR_PRO_DIR . '/admin/Register/class-linguator-pro-base.php';

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ), 10, 0 );

		if ( ! is_admin() ) {
			return;
		}

		require_once LINGUATOR_PRO_DIR . '/admin/Register/Linguator_Pro_Register.php';
		require_once LINGUATOR_PRO_DIR . '/admin/class-scan-page.php';

		// The license screen needs the current user, which is only known once plugins are loaded.
		add_action( 'plugins_loaded', array( $this, 'register_license' ), 10, 0 );

		\LinguatorPro\Admin\Scan_Page::init();
	}

	/**
	 * Registers the Site Scan REST routes.
	 *
	 * @since 2.2.2
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		$scan = new \LinguatorPro\Modules\REST\V1\Scan( $this->model );
		$scan->register_routes();
	}

	/**
	 * Loads the license manager for administrators.
	 *
	 * @since 2.2.2
	 *
	 * @return void
	 */
	public function register_license() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		new \LinguatorPro\Admin\Register\Linguator_Pro_Register();
	}

	/**
	 * Tells the admin that the free plugin is needed, with a link to install, activate or update it.
	 *
	 * @since 2.2.2
	 *
	 * @return void
	 */
	public function dependency_notice() {
		if ( $this->is_free_ready() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = array_key_exists( LINGUATOR_PRO_FREE_PLUGIN_BASE, get_plugins() );
		$action    = '';

		if ( defined( 'LINGUATOR_VERSION' ) ) {
			/* translators: %s: minimum free plugin version. */
			$message = sprintf( __( 'Linguator Pro needs the free Linguator plugin version %s or newer. Please update it.', 'linguator-pro' ), LINGUATOR_PRO_MIN_FREE_VERSION );
			if ( current_user_can( 'update_plugins' ) ) {
				$action = sprintf(
					'<a href="%s">%s</a>',
					esc_url( self_admin_url( 'plugins.php?plugin_status=upgrade' ) ),
					esc_html__( 'Update Linguator', 'linguator-pro' )
				);
			}
		} elseif ( $installed ) {
			$message = __( 'Linguator Pro needs the free Linguator plugin to work. Please activate it.', 'linguator-pro' );
			$action  = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( LINGUATOR_PRO_FREE_PLUGIN_BASE ) ), 'activate-plugin_' . LINGUATOR_PRO_FREE_PLUGIN_BASE ) ),
				esc_html__( 'Activate Linguator', 'linguator-pro' )
			);
		} else {
			$message = __( 'Linguator Pro needs the free Linguator plugin to work. Please install and activate it.', 'linguator-pro' );
			if ( current_user_can( 'install_plugins' ) ) {
				$action = sprintf(
					'<a href="%s">%s</a>',
					esc_url( wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . LINGUATOR_PRO_FREE_PLUGIN_SLUG ), 'install-plugin_' . LINGUATOR_PRO_FREE_PLUGIN_SLUG ) ),
					esc_html__( 'Install Linguator', 'linguator-pro' )
				);
			}
		}

		echo '<div class="notice notice-error"><p><strong>Linguator Pro:</strong> ' . esc_html( $message ) . ' ';
		echo wp_kses( $action, array( 'a' => array( 'href' => array() ) ) );
		echo '</p></div>';
	}
}
