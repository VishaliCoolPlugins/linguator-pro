<?php
/**
 * License screen, form handlers and update notices for Linguator Pro.
 *
 * Same structure as the AutoPoly Pro Register class so the folder can be reused as a framework.
 *
 * @package Linguator
 */

namespace LinguatorPro\Admin\Register;

use Exception;
use LinguatorPro\Settings\Header\Header;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

require_once __DIR__ . '/class-linguator-pro-base.php';

class Linguator_Pro_Register {
	public $plugin_file = LINGUATOR_PRO_FILE;
	public $response_obj;
	public $license_message;
	public $slug                       = 'lmatp';
	public static $form_status         = false;
	private const OPTION_LICENSE_KEY   = 'linguator_pro_lic_key';
	private const OPTION_LICENSE_EMAIL = 'linguator_pro_lic_email';
	private const PAGE                 = 'lmat_settings';
	private const TAB                  = 'license';
	private $showMessage               = false;
	private $licenseMessage            = '';
	private $responseObj               = null;
	private $lmatp_renew_link_html     = '';

	/**
	 * Option the license key is stored in. Bound to this site's URL by the licensor's get_lic_key_param(),
	 * so a database copied to another domain does not carry the license with it.
	 *
	 * @return string
	 */
	public static function lmatp_license_key_option() {
		return Linguator_Pro_Base::get_lic_key_param( self::OPTION_LICENSE_KEY );
	}

	function __construct() {
		$this->lmatp_register_hooks();
		$this->lmatp_initialize_license();
		// Add error notice hook
		add_action( 'lmat_display_admin_notices', array( $this, 'lmatp_display_license_error_messages' ), 20 );
		add_action( 'lmat_display_admin_notices', array( $this, 'lmatp_display_license_key_notice' ), 20 );
	}

	private function lmatp_register_hooks() {
		add_action( 'admin_print_styles', array( $this, 'lmatp_set_admin_style' ) );
		// License is a tab of the Linguator settings page: menu entry, header tab, tab content and its assets.
		add_filter( 'lmat_settings_tabs', array( $this, 'lmatp_add_menu_tab' ), 20 );
		add_filter( 'lmat_settings_header_tabs', array( $this, 'lmatp_add_header_tab' ) );
		add_filter( 'lmat_render_languages_page', array( $this, 'lmatp_dashboard_page' ), 10, 3 );
		add_filter( 'lmat_admin_settings_assets', array( $this, 'lmatp_header_assets' ), 10, 3 );
		add_filter( 'lmat_frontend_settings_assets', array( $this, 'lmatp_stop_frontend_assets' ), 10, 3 );
		add_action( 'admin_post_lmatp_el_activate_license', array( $this, 'lmatp_handle_license_activation' ) );
		add_action( 'admin_post_lmatp_el_deactivate_license', array( $this, 'lmatp_handle_license_deactivation' ) );
		add_action( 'wp_ajax_lmatp_refresh_license_ajax', array( $this, 'lmatp_handle_refresh_license_ajax' ) );
		add_action( 'wp_ajax_lmatp_activate_license_ajax', array( $this, 'lmatp_handle_license_activation_ajax' ) );
		// Plugins screen: renewal notice under the plugin row, and no update notice without a valid license.
		add_action( 'load-plugins.php', array( $this, 'lmatp_check_plugin_custom_notice' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'lmatp_hide_plugin_update_notice' ) );
	}

	private function lmatp_initialize_license() {
		// Move a key saved under the plain option name (earlier builds) to the site-bound name.
		$lmatp_plain_key = get_option( self::OPTION_LICENSE_KEY, '' );
		if ( ! empty( $lmatp_plain_key ) && empty( get_option( self::lmatp_license_key_option(), '' ) ) ) {
			update_option( self::lmatp_license_key_option(), $lmatp_plain_key );
		}
		if ( ! empty( $lmatp_plain_key ) ) {
			delete_option( self::OPTION_LICENSE_KEY );
		}

		$licenseKey = get_option( self::lmatp_license_key_option(), '' );
		$liceEmail  = get_option( self::OPTION_LICENSE_EMAIL, get_bloginfo( 'admin_email' ) );

		Linguator_Pro_Base::add_on_delete(
			function () {
				delete_option( self::lmatp_license_key_option() );
				delete_option( self::OPTION_LICENSE_EMAIL );
			}
		);

		if ( Linguator_Pro_Base::check_wp_plugin( $licenseKey, $liceEmail, $this->licenseMessage, $this->responseObj, $this->plugin_file ) ) {
			self::$form_status = true;
		} else {
			self::$form_status = false;
			if ( ! empty( $licenseKey ) && ! empty( $this->licenseMessage ) ) {
				$this->showMessage = true;
			}
		}
	}

	public function lmatp_set_admin_style() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lmatp_current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lmatp_active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( self::PAGE !== $lmatp_current_page || self::TAB !== $lmatp_active_tab ) {
			return;
		}

		wp_enqueue_style(
			'lmatp-license-style',
			LINGUATOR_PRO_URL . 'admin/Register/css/license.css',
			array(),
			LINGUATOR_PRO_VERSION,
			'all'
		);

		// The license card uses dashicons for its status/detail icons.
		wp_enqueue_style( 'dashicons' );

		wp_enqueue_script(
			'lmatp-dashboard-data-share',
			LINGUATOR_PRO_URL . 'admin/Register/js/license.js',
			array( 'jquery' ),
			LINGUATOR_PRO_VERSION,
			true
		);

		wp_localize_script(
			'lmatp-dashboard-data-share',
			'lmatp_ajax',
			array(
				'nonce'               => wp_create_nonce( 'lmatp_refresh_license_nonce' ),
				'ajaxurl'             => admin_url( 'admin-ajax.php' ),
				'license_expired_msg' => __( 'Your license has expired, ', 'linguator-pro' ),
				'support_expired_msg' => __( 'Your support has expired, ', 'linguator-pro' ),
			)
		);
	}

	/*
	|-------------------------------------------------------
	|   Linguator Pro license tab (settings page)
	|-------------------------------------------------------
	*/

	/**
	 * Add License to the Linguator submenu (opens the settings page on the license tab).
	 *
	 * @param string[] $tabs Sub-menu items.
	 * @return string[]
	 */
	public function lmatp_add_menu_tab( $tabs ) {
		$tabs['settings&tab=license'] = __( 'License', 'linguator-pro' );

		return $tabs;
	}

	/**
	 * Add License to the settings header tabs.
	 *
	 * @param array $tabs Header tabs.
	 * @return array
	 */
	public function lmatp_add_header_tab( $tabs ) {
		$tabs['license'] = array(
			'title'        => __( 'License', 'linguator-pro' ),
			'redirect'     => true,
			'redirect_url' => 'lmat_settings&tab=license',
		);

		return $tabs;
	}

	/**
	 * Load the settings header styles on the license tab and keep the React settings app off it.
	 *
	 * @param bool   $status          Whether to load the settings app assets.
	 * @param string $tab             Selected tab.
	 * @param bool   $is_settings_tab Whether this is a settings tab.
	 * @return bool
	 */
	public function lmatp_header_assets( $status, $tab, $is_settings_tab ) {
		if ( $is_settings_tab && self::TAB === $tab && function_exists( 'LMATP' ) ) {
			Header::get_instance( self::TAB, LMATP()->model )->header_assets();

			return false;
		}

		return $status;
	}

	/**
	 * Keep the front-end settings assets off the license tab.
	 *
	 * @param bool   $status          Whether to load the assets.
	 * @param string $tab             Selected tab.
	 * @param bool   $is_settings_tab Whether this is a settings tab.
	 * @return bool
	 */
	public function lmatp_stop_frontend_assets( $status, $tab, $is_settings_tab ) {
		if ( $is_settings_tab && self::TAB === $tab ) {
			return false;
		}

		return $status;
	}

	/**
	 * Render the license tab under the settings header.
	 *
	 * @param bool   $status       Whether the settings app should render.
	 * @param string $selected_tab Selected tab.
	 * @param string $active_tab   Active page ('settings' for the settings page).
	 * @return bool False once the license tab has been rendered.
	 */
	public function lmatp_dashboard_page( $status, $selected_tab, $active_tab ) {
		if ( self::TAB !== $selected_tab || 'settings' !== $active_tab || ! function_exists( 'LMATP' ) ) {
			return $status;
		}

		$this->lmatp_check_user_capabilities();

		Header::get_instance( self::TAB, LMATP()->model )->header();

		$file_prefix = LINGUATOR_PRO_DIR . '/admin/Register/views/';

		/*
		 * Resolve the licence before the page opens. The expiry notice has to print
		 * above the card so it pushes the card down.
		 */
		$licenseKey = get_option( self::lmatp_license_key_option(), '' );
		$liceEmail  = get_option( self::OPTION_LICENSE_EMAIL, '' );
		Linguator_Pro_Base::add_on_delete(
			function () {
				delete_option( self::lmatp_license_key_option() );
			}
		);
		$license_is_valid = Linguator_Pro_Base::check_wp_plugin( $licenseKey, $liceEmail, $this->licenseMessage, $this->responseObj, $this->plugin_file );

		require_once $file_prefix . 'license.php';
		require_once $file_prefix . 'sidebar.php';
		?>
			<div class="wrap lmatp-license-wrap">
				<h1 class="screen-reader-text"><?php esc_html_e( 'Linguator Pro - License', 'linguator-pro' ); ?></h1>
				<?php
				// The notice slot sits above both columns, so a notice pushes the card and the sidebar down together.
				lmatp_render_license_notices();
				?>
				<div class="lmatp-tab-content">
					<?php
					if ( $license_is_valid ) {
						lmatp_render_license_page_pro( $this->responseObj );
					} else {
						lmatp_render_license_page();
					}

					lmatp_render_license_sidebar();
					?>
				</div>
			</div>
		<?php

		return false;
	}

	public function lmatp_handle_license_activation_ajax() {
		$this->lmatp_check_user_capabilities();

		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'el-lmatp-license' ) ) {
			$lmatp_error = $this->lmatp_get_error_message( 'default' );
			wp_send_json_error(
				array(
					'message'     => $lmatp_error['title'],
					'title'       => $lmatp_error['title'],
					'description' => $lmatp_error['description'],
				)
			);
            return;
		}

		$license_key   = ! empty( $_POST['license_code'] ) ? sanitize_text_field( wp_unslash( $_POST['license_code'] ) ) : '';
		// Callers may still post an address; otherwise derive it as the form flow does.
		$license_email = ! empty( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $license_email ) ) {
			$stored_email  = sanitize_email( get_option( self::OPTION_LICENSE_EMAIL, '' ) );
			$license_email = is_email( $stored_email )
				? $stored_email
				: sanitize_email( get_bloginfo( 'admin_email' ) );
		}
		if ( ! is_email( $license_email ) ) {
			$lmatp_error = $this->lmatp_get_error_message( 'invalid_email' );
			wp_send_json_error(
				array(
					'message'     => $lmatp_error['title'],
					'title'       => $lmatp_error['title'],
					'description' => $lmatp_error['description'],
				)
			);
            return;
		}

		if ( empty( $license_key ) ) {
			$lmatp_error = $this->lmatp_get_error_message( 'missing_key' );
			wp_send_json_error(
				array(
					'message'     => $lmatp_error['title'],
					'title'       => $lmatp_error['title'],
					'description' => $lmatp_error['description'],
				)
			);
			return;
		}

		$error       = '';
		$responseObj = null;
		if ( $this->lmatp_activate_license( $license_key, $license_email, $error, $responseObj ) ) {
			wp_send_json_success( array( 'message' => __( 'License activated successfully.', 'linguator-pro' ) ) );
		}
		// Map error message to error code
		$error_code = $this->lmatp_map_error_to_code( $error );
		$lmatp_error = $this->lmatp_get_error_message( $error_code );
			wp_send_json_error(
				array(
					'message'     => $lmatp_error['title'],
					'title'       => $lmatp_error['title'],
					'description' => $lmatp_error['description'],
				)
			);
	}

	public function lmatp_handle_license_activation() {
		$this->lmatp_check_user_capabilities();
		check_admin_referer( 'el-lmatp-license' );

		// Validate license key
		$license_key = ! empty( $_POST['license_code'] ) ? sanitize_text_field( wp_unslash( $_POST['license_code'] ) ) : '';
		if ( empty( $license_key ) ) {
			$this->lmatp_redirect_with_error( 'missing_key' );
			return;
		}

		// The activation form no longer collects an address. Honour one if a caller
		// still posts it, otherwise fall back to the stored license email and
		// finally the site admin address.
		$license_email = ! empty( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $license_email ) ) {
			$stored_email  = sanitize_email( get_option( self::OPTION_LICENSE_EMAIL, '' ) );
			$license_email = is_email( $stored_email )
				? $stored_email
				: sanitize_email( get_bloginfo( 'admin_email' ) );
		}

		// A site with no usable admin address cannot register a license.
		if ( ! is_email( $license_email ) ) {
			$this->lmatp_redirect_with_error( 'invalid_email' );
			return;
		}

		$error       = '';
		$responseObj = null;

		if ( $this->lmatp_activate_license( $license_key, $license_email, $error, $responseObj ) ) {
			$this->lmatp_redirect_with_success();
		}
		// Map error message to error code
		$error_code = $this->lmatp_map_error_to_code( $error );
		$this->lmatp_redirect_with_error( $error_code, $error );
	}

	private function lmatp_check_user_capabilities() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'linguator-pro' ) );
		}
	}

	private function lmatp_redirect_with_success() {
		wp_safe_redirect( admin_url( 'admin.php?page=lmat_settings&tab=license&activated=true' ) );
		exit;
	}

	private function lmatp_redirect_with_error( $error, $detail = '' ) {
		$url = 'admin.php?page=lmat_settings&tab=license&error=' . urlencode( $error );
		if ( is_string( $detail ) && '' !== $detail && $detail !== $error ) {
			$url .= '&detail=' . urlencode( substr( $detail, 0, 200 ) );
		}
		wp_safe_redirect( admin_url( $url ) );
		exit;
	}

	private function lmatp_activate_license( $licenseKey, $licenseEmail, &$error, &$responseObj ) {

		if ( Linguator_Pro_Base::check_wp_plugin( $licenseKey, $licenseEmail, $error, $responseObj, $this->plugin_file ) ) {
			Linguator_Pro_Base::update_secret_option( self::lmatp_license_key_option(), $licenseKey );
			Linguator_Pro_Base::update_secret_option( self::OPTION_LICENSE_EMAIL, $licenseEmail );
			delete_site_transient( 'update_plugins' );
			return true;
		}
		return false;
	}


	public function lmatp_handle_license_deactivation() {
		try {
			$this->lmatp_check_user_capabilities();
			check_admin_referer( 'el-lmatp-license' );

			$message = '';
			if ( Linguator_Pro_Base::remove_license_key( $this->plugin_file, $message ) ) {
				delete_option( self::lmatp_license_key_option() );
				delete_option( self::OPTION_LICENSE_EMAIL );
				delete_site_transient( 'update_plugins' );

				wp_safe_redirect( admin_url( 'admin.php?page=lmat_settings&tab=license&deactivated=true' ) );
				exit;
			}

			wp_safe_redirect( admin_url( 'admin.php?page=lmat_settings&tab=license&error=' . urlencode( $message ) ) );
			exit;

		} catch ( \Exception $e ) {
			wp_safe_redirect( admin_url( 'admin.php?page=lmat_settings&tab=license&error=unexpected_error' ) );
			exit;
		}
	}

	/**
	 * Handle AJAX license refresh request
	 */
	public function lmatp_handle_refresh_license_ajax() {
		// Check nonce for security
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'lmatp_refresh_license_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'linguator-pro' ) ) );
			return;
		}

		$this->lmatp_check_user_capabilities();

		// Get existing license key and email
		$license_key   = get_option( self::lmatp_license_key_option(), '' );
		$license_email = get_option( self::OPTION_LICENSE_EMAIL, '' );

		if ( empty( $license_key ) || empty( $license_email ) ) {
			wp_send_json_error( array( 'message' => __( 'No license information found to refresh.', 'linguator-pro' ) ) );
			return;
		}

		$error        = '';
		$response_obj = null;

		// Use the same activation logic to refresh the license, bypassing the cached record
		Linguator_Pro_Base::force_refresh();
		if ( Linguator_Pro_Base::check_wp_plugin( $license_key, $license_email, $error, $response_obj, $this->plugin_file ) ) {

			// Get updated license information
			$license_info = array(
				'is_valid'      => $response_obj->is_valid ? $response_obj->is_valid : false,
				'license_title' => $response_obj->license_title ? $response_obj->license_title : '',
				'expire_date'   => $response_obj->expire_date ? $response_obj->expire_date : '',
				'market'        => $response_obj->market ? $response_obj->market : '',
				'support_end'   => $response_obj->support_end ? $response_obj->support_end : '',
				'plugin_name'   => self::lmatp_license_plugin_name(),
			);

			wp_send_json_success(
				array(
					'message'                   => __( 'License Status Updated successfully!', 'linguator-pro' ),
					'license_info'              => $license_info,
					'version_available_message' => self::lmatpGetVersionAvailableMessage(),
				)
			);
		} else {
			// Map error message to error code
			$error_code    = $this->lmatp_map_error_to_code( $error );
			$error_message = $this->lmatp_get_error_message( $error_code );
			wp_send_json_error(
				array(
					'message'     => $error_message['title'],
					'title'       => $error_message['title'],
					'description' => $error_message['description'],
				)
			);
		}
	}

	/**
	 * Plugin name shown in the license card heading, status banner and renewal notices.
	 *
	 * Short form on purpose: it is interpolated into sentences such as
	 * "Your %s license is active." where the full product name reads badly.
	 * Lives on the class, not in the view, so the refresh AJAX handler can reach it.
	 *
	 * @return string
	 */
	public static function lmatp_license_plugin_name() {
		return __( 'Linguator Pro', 'linguator-pro' );
	}

	private function lmatp_map_error_to_code( $error ) {
		$error = strtolower( $error );

		if ( strpos( $error, 'invalid' ) !== false ) {
			return 'invalid_key';
		} elseif ( strpos( $error, 'disabled' ) !== false || strpos( $error, 'temporary inactivated' ) !== false || strpos( $error, 'inactive_license' ) !== false ) {
			return 'disabled';
		} elseif ( strpos( $error, 'license quota has been over' ) !== false || strpos( $error, 'installed on another domain' ) !== false ) {
			return 'no_activations';
		} elseif ( strpos( $error, 'refunded' ) !== false || strpos( $error, 'refunded_license' ) !== false ) {
			return 'refunded';
		} elseif ( strpos( $error, 'expired' ) !== false ) {
			return 'expired';
		} elseif ( strpos( $error, 'wrong_license_status' ) !== false ) {
			return 'wrong_license_status';
		} elseif ( strpos( $error, 'domain_exceeded' ) !== false ) {
			return 'domain_exceeded';
		} elseif ( strpos( $error, 'no valid request method works for license checking' ) !== false ) {
			return 'limit_exceeded';
		} elseif ( strpos( $error, 'missing_key' ) !== false ) {
			return 'missing_key';
		} elseif ( strpos( $error, 'missing_email' ) !== false ) {
			return 'missing_email';
		} elseif ( strpos( $error, 'wrong_email_format' ) !== false ) {
			return 'invalid_email';
		} elseif ( strpos( $error, 'response error, contact with the author or update the plugin or theme' ) !== false ) {
			return 'response_error';
		}
		return 'default';
	}

	// Add new method to display error messages
	public function lmatp_display_license_error_messages() {

		if ( isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === 'lmat_settings' && isset( $_GET['tab'] ) && sanitize_key( $_GET['tab'] ) === 'license' ) {
			// Display error message if exists
			if ( isset( $_GET['error'] ) ) {
				$error_param   = sanitize_text_field( wp_unslash( $_GET['error'] ) );
				$error_message = $this->lmatp_get_error_message( $error_param );
				?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php echo esc_html( $error_message['title'] ); ?></strong></p>
					<?php if ( ! empty( $error_message['description'] ) ) : ?>
						<p><?php echo wp_kses_post( $error_message['description'] ); ?></p>
					<?php endif; ?>
					<?php if ( isset( $_GET['detail'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
						<p><em><?php echo esc_html( sprintf( /* translators: %s: message returned by the license server. */ __( 'License server response: %s', 'linguator-pro' ), sanitize_text_field( wp_unslash( $_GET['detail'] ) ) ) ); ?></em></p>
					<?php endif; ?>
				</div>
				<?php
			}

			// Display success message
			if ( isset( $_GET['activated'] ) && sanitize_key( wp_unslash( $_GET['activated'] ) ) === 'true' ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'License activated successfully!', 'linguator-pro' ); ?></p>
				</div>
				<?php
			}

			if ( isset( $_GET['deactivated'] ) && sanitize_key( wp_unslash( $_GET['deactivated'] ) ) === 'true' ) {
				?>
				<div class="notice notice-info is-dismissible">
					<p><?php esc_html_e( 'License deactivated successfully!', 'linguator-pro' ); ?></p>
				</div>
				<?php
			}

			if ( $this->showMessage && ! empty( $this->licenseMessage ) ) {
				?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $this->licenseMessage ); ?></p>
				</div>
				<?php
			}
		}
	}

	/**
	 * Map an error code to a title + description pair.
	 *
	 * @param string $error_code Mapped code, or a raw API message passed through a redirect.
	 * @return array{title:string,description:string}
	 */
	private function lmatp_get_error_message( $error_code ) {

		$account_url = 'https://my.coolplugins.net/account/subscriptions/';
		$support_url = 'https://my.coolplugins.net/account/support-tickets/';

		$messages = array(
			'missing_key'          => array(
				'title'       => __( 'License key is required.', 'linguator-pro' ),
				'description' => __( 'Enter your purchase/license key in the field above, then try activating again.', 'linguator-pro' ),
			),
			'missing_email'        => array(
				'title'       => __( 'Email address is required.', 'linguator-pro' ),
				'description' => __( 'The site admin email address is missing. Set it under Settings > General, then try again.', 'linguator-pro' ),
			),
			'invalid_email'        => array(
				'title'       => __( 'Please enter a valid email address.', 'linguator-pro' ),
				'description' => __( 'The site admin email address could not be used for activation. Check it under Settings > General, then try again.', 'linguator-pro' ),
			),
			'invalid_format'       => array(
				'title'       => __( 'Invalid license key format.', 'linguator-pro' ),
				'description' => __( 'Please check your license key for missing characters or extra spaces, then try again.', 'linguator-pro' ),
			),
			'invalid_key'          => array(
				'title'       => __( 'Invalid license key.', 'linguator-pro' ),
				'description' => __( 'Please check your license key and try again. If the problem continues, verify the key in your account.', 'linguator-pro' ),
			),
			'expired'              => array(
				'title'       => __( 'Your license key has expired.', 'linguator-pro' ),
				'description' => sprintf(
					/* translators: %s: account URL */
					__( 'Please renew your license to continue receiving updates and priority support. You can renew from <a href="%s" target="_blank" rel="noopener noreferrer">your account</a>.', 'linguator-pro' ),
					esc_url( $account_url )
				),
			),
			'disabled'             => array(
				'title'       => __( 'Your license key has been disabled.', 'linguator-pro' ),
				'description' => sprintf(
					/* translators: %s: support URL */
					__( 'This license cannot be activated right now. Please contact support by <a href="%s" target="_blank" rel="noopener noreferrer">clicking here</a> for help.', 'linguator-pro' ),
					esc_url( $support_url )
				),
			),
			'no_activations'       => array(
				'title'       => __( 'License quota has been over, you can not add more domain with this license key.', 'linguator-pro' ),
				'description' => sprintf(
					/* translators: %s: account URL */
					__( 'Your license activation limit has been reached. Please deactivate this license from one of your existing domains to use it on this site. You can manage your license from <a href="%s" target="_blank" rel="noopener noreferrer">your account</a>.', 'linguator-pro' ),
					esc_url( $account_url )
				),
			),
			'refunded'             => array(
				'title'       => __( 'Your purchase key has been refunded.', 'linguator-pro' ),
				'description' => sprintf(
					/* translators: %s: support URL */
					__( 'This license is no longer valid for activation. If you believe this is a mistake, please contact support by <a href="%s" target="_blank" rel="noopener noreferrer">clicking here</a>.', 'linguator-pro' ),
					esc_url( $support_url )
				),
			),
			'wrong_license_status' => array(
				'title'       => __( 'Your license key cannot be activated.', 'linguator-pro' ),
				'description' => sprintf(
					/* translators: %s: account URL */
					__( 'Your license may be inactive, refunded, or may have exceeded the allowed domain limit. Please review it in <a href="%s" target="_blank" rel="noopener noreferrer">your account</a>.', 'linguator-pro' ),
					esc_url( $account_url )
				),
			),
			'domain_exceeded'      => array(
				'title'       => __( 'Your license key has exceeded the allowed domain limit.', 'linguator-pro' ),
				'description' => sprintf(
					/* translators: %s: account URL */
					__( 'Please deactivate the license on another site before activating it here. You can manage your domains from <a href="%s" target="_blank" rel="noopener noreferrer">your account</a>.', 'linguator-pro' ),
					esc_url( $account_url )
				),
			),
			// Kept from this plugin's own error set; the support link used to be
			// bolted on at the notice, and now travels with the message itself.
			'limit_exceeded'       => array(
				'title'       => __( 'There was an issue with your account.', 'linguator-pro' ),
				'description' => sprintf(
					/* translators: %s: support URL */
					__( 'Please contact our plugin support team by <a href="%s" target="_blank" rel="noopener noreferrer">clicking here</a>.', 'linguator-pro' ),
					esc_url( $support_url )
				),
			),
			'response_error'       => array(
				'title'       => __( 'There is an issue with your account.', 'linguator-pro' ),
				'description' => sprintf(
					/* translators: %s: support URL */
					__( 'Please contact our plugin support team by <a href="%s" target="_blank" rel="noopener noreferrer">clicking here</a>.', 'linguator-pro' ),
					esc_url( $support_url )
				),
			),
			'default'              => array(
				'title'       => __( 'An error occurred while validating your license.', 'linguator-pro' ),
				'description' => __( 'Please try again. If the problem continues, contact support for help.', 'linguator-pro' ),
			),
		);

		if ( isset( $messages[ $error_code ] ) ) {
			return $messages[ $error_code ];
		}

		// Unknown / raw API message passed via redirect (deactivation sends one).
		if ( ! empty( $error_code ) ) {
			return array(
				'title'       => $error_code,
				'description' => $messages['default']['description'],
			);
		}

		return $messages['default'];
	}

	public function lmatp_display_license_key_notice() {
		if ( ! self::$form_status &&
		( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== 'lmat_settings' ||
		! isset( $_GET['tab'] ) || sanitize_key( wp_unslash( $_GET['tab'] ) ) !== 'license' ) ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p><?php echo wp_kses_post( __( 'Please <a href="admin.php?page=lmat_settings&tab=license">enter your license key</a> to enable automatic updates and premium support for Linguator Pro.', 'linguator-pro' ) ); ?></p>
			</div>
			<?php
		}
	}

		/**
		 * Check if the support has expired
		 * 
		 * @param object $license_info The license information.
		 * @return boolean True if the support has expired, false otherwise.
		 */
		public function lmatp_ai_is_support_expired( $license_info ) {
			return $license_info->support_end === 'no support'
				|| $license_info->is_valid === 'support_expired'
					|| ( strtolower( $license_info->support_end ) !== 'unlimited'
						&& strtotime( $license_info->support_end ) < time() );
		}

		public function lmatp_check_plugin_custom_notice() {
			$this->lmatp_renew_link_html = $this->lmatp_get_plugin_custom_notice_html();
			if ( ! empty( $this->lmatp_renew_link_html ) ) {
				add_action( 'after_plugin_row_' . LINGUATOR_PRO_BASENAME, array( $this, 'lmatp_render_plugin_custom_notice' ), 10, 2);
			}
		}

		public function lmatp_render_plugin_custom_notice( $plugin_file, $plugin_data ) {
			if ( ! empty( $this->lmatp_renew_link_html ) ) {
				?>
				<tr class="plugin-update-tr active lmatp-pro">
					<td colspan="4" class="plugin-update colspanchange">
						<div class="update-message notice inline notice-warning notice-alt"> 
							<p><?php echo wp_kses_post( $this->lmatp_renew_link_html ); ?></p>
						</div>
					</td>
				</tr>
				<?php
			}
		}

		/**
		 * Display custom update notices for the Linguator Pro plugin in the WordPress plugins list table.
		 *
		 * This function checks the plugin's license status and update availability, and then conditionally
		 * returns a message:
		 *
		 * - If an update is available but the license key is not entered, it returns a warning to activate the license.
		 * - If the license or support period has expired, it returns a renewal message with appropriate links.
		 * - If the license is valid, an empty string is returned.
		 *
		 * @return string HTML content of the notice or empty string.
		*/
		public function lmatp_get_plugin_custom_notice_html() {
	
		if ( !is_admin() || !function_exists('current_user_can') || !current_user_can('manage_options') ) {
			return '';
		}
			
		$instance = null;

        if ( method_exists( Linguator_Pro_Base::class, 'getInstance' ) ) {
            $instance = Linguator_Pro_Base::getInstance(LINGUATOR_PRO_FILE);
        }

        if ( ! $instance || ! method_exists( $instance, 'el_plugin_update_info' ) ) {
            return '';
        }

		// Get license info and update info for the plugin
        $update_info = $instance->el_plugin_update_info();
        $license_info = $instance::get_register_info();	

		$version_available_message = self::lmatpGetVersionAvailableMessage();

		$plugin_basename = plugin_basename(LINGUATOR_PRO_FILE);
		$renew_link='';
		
		if ( ! self::$form_status &&  $plugin_basename === LINGUATOR_PRO_BASENAME ) {
				$renew_link = wp_kses_post(
					sprintf(
						/* translators: %s: version available message (may contain HTML). */
						__( '%1$s Please <a href="admin.php?page=lmat_settings&amp;tab=license">enter your license key</a> to enable automatic updates and premium support for Linguator Pro', 'linguator-pro' ),
						$version_available_message
					),
					array(
						'a' => array(
							'href' => array(),
						),
					)
				);

			} else {

				if ( empty( $update_info->download_link ) && ( $this->lmatp_ai_is_support_expired( $license_info ) === true || $license_info->is_valid === 'license_expired' ) ) {
					
					$is_expired = ( empty( $update_info->is_downloadable ) )
						? __( 'license', 'linguator-pro' )
						: __( 'support', 'linguator-pro' );

					$message = sprintf(
						/* translators: %s: license or support */
						__( ' Your %s has expired,', 'linguator-pro' ),
						esc_html( $is_expired )
					);

					if ( ! empty( $license_info->market ) && 'E' === $license_info->market ) {
						$renew_text = sprintf(
							/* translators: %s: license or support */
							__( 'Please renew your %s to continue receiving automatic updates and priority support.', 'linguator-pro' ),
							esc_html( $is_expired )
						);
					} else {
						$renew_text = sprintf(
							/* translators: %s: opening anchor tag for renew link */
							__( 'Please %1$sRenew now%2$s to continue receiving automatic updates and priority support.', 'linguator-pro' ),
							'<a href="https://my.coolplugins.net/account/subscriptions/" target="_blank" rel="noopener noreferrer">',
							'</a>'
						);
					}

					$renew_link = $version_available_message . $message . ' ' . wp_kses_post( $renew_text );
				}
			}

			return $renew_link;
		}

				/**
		 * Hide WordPress core plugin update notice when license is not valid
		 * 
		 * @param object $transient The site transient for plugin updates
		 * @return object Modified transient
		 */
		public function lmatp_hide_plugin_update_notice( $transient ) {
			if ( !is_admin() || !function_exists('current_user_can') || !current_user_can('manage_options') ) {
				return $transient;
			}

			$instance = null;
            if ( method_exists( Linguator_Pro_Base::class, 'getInstance' ) ) {
                $instance = Linguator_Pro_Base::getInstance(LINGUATOR_PRO_FILE);
            }

            if ( ! $instance || ! method_exists( $instance, 'el_plugin_update_info' ) ) {
                return $transient;
            }

            $update_info = $instance->el_plugin_update_info();
            $license_info = $instance::GetRegisterInfo();

			if ( empty($update_info->download_link) || $license_info == null){
					if ( isset( $transient->response ) && isset( $transient->response[LINGUATOR_PRO_BASENAME] ) ) {
						unset( $transient->response[LINGUATOR_PRO_BASENAME] );
					}
				}
			
			return $transient;
		}

			/**
			 * Get version available message if update is available
			 * 
			 * @return string Version available message or empty string
			 */
			public static function lmatpGetVersionAvailableMessage() {

				if ( !is_admin() || !function_exists('current_user_can') || !current_user_can('manage_options') ) {
					return '';
				}

				// Get license info and update info for the plugin
				$update_info = Linguator_Pro_Base::getInstance(LINGUATOR_PRO_FILE)->el_plugin_update_info();

				// Initialize and sanitize version information
				$latest_version = isset($update_info->new_version) ? sanitize_text_field($update_info->new_version) : '';
				$version_available_message = '';

				// Prepare update available message if current version is outdated
				$plugin_basename = plugin_basename(LINGUATOR_PRO_FILE);
				if ( ! empty($latest_version) && version_compare(LINGUATOR_PRO_VERSION, $latest_version, '<') ) {

					list($plugin_slug, $plugin_file) = explode('/', $plugin_basename);

					$plugin_info_url = add_query_arg([
						'tab'       => 'plugin-information',
						'plugin'    => $plugin_slug . '/' . $plugin_file,
						'section'   => 'changelog',
						'TB_iframe' => 'true',
						'width'     => 772,
						'height'    => 390,
					], admin_url('plugin-install.php'));

					$version_available_message = sprintf(
						/* translators: %s: version number with link */
						__('Version %s is available.', 'linguator-pro'),
						sprintf(
							'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s">%s</a>',
							esc_url($plugin_info_url),
							// translators: 1: Version number
							esc_attr(sprintf(__('View changelog for version %s', 'linguator-pro'), $latest_version)),
							// translators: 1: Version number
							esc_html(sprintf(__('%s (View details)', 'linguator-pro'), $latest_version))
						)
					);
				}
				
				return $version_available_message;
			}
}
