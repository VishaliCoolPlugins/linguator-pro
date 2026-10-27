<?php
/**
 * @package Linguator
 */
namespace LinguatorPro\Settings\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use LinguatorPro\Admin\Controllers\Linguator_Admin_Base;
use LinguatorPro\Admin\Controllers\Linguator_Admin_Strings;
use LinguatorPro\Admin\Controllers\Linguator_Admin_Model;
use LinguatorPro\Settings\Controllers\Linguator_Settings_Module;
use LinguatorPro\Settings\Tables\Linguator_Table_Languages;
use LinguatorPro\Settings\Tables\Linguator_Table_String;
use LinguatorPro\Settings\Header\Header;
use LinguatorPro\Includes\Other\Linguator_Translation_Dashboard;

use WP_Error;

/**
 * A class for the Linguator settings pages, accessible from @see LMATP().
 *
 *  
 */
#[AllowDynamicProperties]
class Linguator_Settings extends Linguator_Admin_Base {

	/**
	 * @var Linguator_Admin_Model
	 */
	public $model;

	/**
	 * Name of the active module.
	 *
	 * @var string|null
	 */
	protected $active_tab;

	/**
	 * Array of modules classes.
	 *
	 * @var Linguator_Settings_Module[]|null
	 */
	protected $modules;

	// Module properties
	/**
	 * @var mixed
	 */
	public $sitemaps;

	/**
	 * @var mixed
	 */
	public $sync;

	/**
	 * @var mixed
	 */
	public $wizard;

	/**
	 * @var mixed
	 */
	public $rest;

	/**
	 * @var mixed
	 */
	public $switcher_block;

	/**
	 * @var mixed
	 */
	public $navigation_block;

	// Additional dynamic properties
	/**
	 * @var mixed
	 */
	public $pref_lang;

	/**
	 * @var mixed
	 */
	public $filter_lang;

	/**
	 * @var mixed
	 */
	private $header;

	/**
	 * @var mixed
	 */
	private $selected_tab;


	/**
	 * Constructor
	 *
	 *  
	 *
	 * @param Linguator_Links_Model $links_model Reference to the links model.
	 */
	public function __construct( &$links_model ) {
		parent::__construct( $links_model );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter for filtering
		$selected_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter for filtering
		$loco=isset($_GET['loco']) ? sanitize_text_field(wp_unslash($_GET['loco'])) : '';
		
		if ( isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$page            = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$this->active_tab = 'lmat' === $page ? 'lang' : substr( $page, 5 );
		}

		
		if($loco === 'true'){
			add_action( 'load-' . Linguator_Admin_Base::get_screen_id( 'settings' ), array( $this, 'linguator_loco_page_redirect' ) );
		}

		if($this->active_tab === 'lang'){
			$selected_tab='lang';
		}
		if ( 'scan' === $this->active_tab ) {
			$selected_tab = 'scan';
		}

		// Keep existing scan bookmarks working after moving it out of Settings.
		add_action( 'load-' . self::get_screen_id( 'settings' ), function () {
			if ( 'scan' === $this->selected_tab ) {
				wp_safe_redirect( admin_url( 'admin.php?page=lmat_scan' ) );
				exit;
			}
		} );

		if('loco' === $selected_tab || '' === $selected_tab){
			$this->selected_tab = 'general';
			$selected_tab='general';
		}
		
		if($selected_tab){
			if($selected_tab === 'strings'){
				$this->active_tab = $selected_tab;
			}

			$this->selected_tab = $selected_tab;
		}else{
			$this->selected_tab = $this->active_tab;
		}
		
		$this->header = Header::get_instance($this->selected_tab, $this->model);

		Linguator_Admin_Strings::init();

		add_action( 'admin_init', array( $this, 'linguator_register_settings_modules' ) );

		// Adds screen options and the about box in the languages admin panel.
		add_action( 'load-' . self::get_screen_id( 'lang' ), array( $this, 'linguator_load_page' ) );
		// add_action( 'load-' . self::get_screen_id( 'strings' ), array( $this, 'linguator_load_page_strings' ) ); AD pending

		// Saves the per-page value in screen options.
		add_filter( 'set_screen_option_lmat_lang_per_page', array( $this, 'linguator_set_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_lmat_strings_per_page', array( $this, 'linguator_set_screen_option' ), 10, 3 );
	}

	/**
	 * Initializes the modules
	 * 
	 * Note: Legacy settings modules are no longer needed since React handles settings.
	 * Only external modules from filters are registered now.
	 *
	 *  
	 *
	 * @return void
	 */
	public function linguator_register_settings_modules() {
		$modules = array();

		/**
		 * Filter the list of setting modules
		 * Allows external plugins/modules to add their own settings modules
		 *
		 *  
		 *
		 * @param array $modules the list of module classes
		 */
		$modules = apply_filters( 'lmat_settings_modules', $modules );

		foreach ( $modules as $key => $class ) {
			// Handle namespace mapping for remaining modules (mainly sync)
			if ( 'Linguator_Settings_Sync' === $class ) {
				$class = \LinguatorPro\Modules\sync\Linguator_Settings_Sync::class;
			}
			
			// Extract class name for the key if it's a full class name
			if ( is_string( $class ) && strpos( $class, '\\' ) !== false ) {
				$class_parts = explode( '\\', $class );
				$class_name = end( $class_parts );
			} else {
				$class_name = $class;
			}
			
			$key = is_numeric( $key ) ? strtolower( str_replace( 'Linguator_Settings_', '', $class_name ) ) : $key;
			$this->modules[ $key ] = new $class( $this );
		}
	}

	/**
	 * Adds screen options and the about box in the languages admin panel
	 *
	 *  
	 *
	 * @return void
	 */
	public function linguator_load_page() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Languages', 'linguator-pro' ),
				'default' => 10,
				'option'  => 'lmat_lang_per_page',
			)
		);

		add_action( 'admin_notices', array( $this, 'linguator_notice_objects_with_no_lang' ) );
	}

	/**
	 * Adds screen options in the strings translations admin panel
	 *
	 *  
	 *
	 * @return void
	 */
	public function linguator_load_page_strings() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Strings translations', 'linguator-pro' ),
				'default' => 10,
				'option'  => 'lmat_strings_per_page',
			)
		);
	}

	/**
	 * Adds screen options in the localizations admin panel
	 *
	 *  
	 *
	 * @return void
	 */
	public function loco_page_assets() {

		if(!function_exists('is_plugin_active')){
			include_once(ABSPATH . 'wp-admin/includes/plugin.php');
		}
		$loco_plugin_active=is_plugin_active('loco-translate/loco.php');
		$loco_install="false";
		
		if($loco_plugin_active){
			$loco_install="true";
			$plugin_info_url=admin_url('admin.php?page=loco');
		}else{

			if ( ! current_user_can( 'install_plugins' ) ) {
				return;
			}

			// Load ThickBox and updates JS
			add_thickbox();
			wp_enqueue_script( 'updates' );

			$plugin_info_url=admin_url('plugin-install.php?tab=plugin-information&plugin=loco-translate&TB_iframe=true&width=772&height=800');
		}
		

		wp_enqueue_script('lmat-loco-redirect-script', plugins_url('admin/assets/js/loco-redirect-script.js', LINGUATOR_PRO_ROOT_FILE), array('jquery'), LINGUATOR_PRO_VERSION, true);
		wp_localize_script('lmat-loco-redirect-script', 'lmat_loco_redirect_script', array('admin_' => esc_url(admin_url('admin.php?page=lmat_settings')), 'loco_iframe_page_url' => array("url" => esc_url( $plugin_info_url ), "title" => esc_js( __( 'Plugin: Loco Translate', 'linguator-pro' ) )), 'loco_install' => $loco_install));
	}

	/**
	 * Redirects to the loco page
	 *
	 *  
	 *
	 * @return void
	 */
	public function linguator_loco_page_redirect() {
		if(!function_exists('is_plugin_active')){
			include_once(ABSPATH . 'wp-admin/includes/plugin.php');
		}

		$loco_plugin_active=is_plugin_active('loco-translate/loco.php');

		if($loco_plugin_active){
			wp_safe_redirect(admin_url('admin.php?page=loco'));
			exit;
		};
	}

	/**
	 * Saves the number of rows in the languages or strings table set by this user.
	 *
	 *  
	 *
	 * @param mixed  $screen_option False or value returned by a previous filter, not used.
	 * @param string $option        The name of the option, not used.
	 * @param int    $value         The new value of the option to save.
	 * @return int The new value of the option.
	 */
	public function linguator_set_screen_option( $screen_option, $option, $value ) {
		return (int) $value;
	}

	/**
	 * Manages the user input for the languages pages.
	 *
	 *  
	 *
	 * @param string $action The action name.
	 * @return void
	 */
	public function linguator_handle_actions( string $action ): void {
		switch ( $action ) {
			case 'add':
				check_admin_referer( 'add-lang', '_wpnonce_add-lang' );
				$sanitized_data = array(
					'name'       => ! empty( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
					'slug'       => ! empty( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '',
					'locale'     => ! empty( $_POST['locale'] ) ? sanitize_locale_name( wp_unslash( $_POST['locale'] ) ) : '',
					// Treat rtl as a boolean flag derived from a sanitized, unslashed value.
					'rtl'        => ! empty( $_POST['rtl'] ) ? (bool) sanitize_text_field( wp_unslash( $_POST['rtl'] ) ) : false,
					'term_group' => ! empty( $_POST['term_group'] ) ? absint( wp_unslash( $_POST['term_group'] ) ) : 0,
					'flag'       => ! empty( $_POST['flag'] ) ? sanitize_text_field( wp_unslash( $_POST['flag'] ) ) : '',
				);
				$language = $this->model->add_language( $sanitized_data );

				if ( is_wp_error( $language ) ) {
						linguator_pro_add_notice( $language );
				} else {
					linguator_pro_add_notice( new WP_Error( 'lmat_languages_created', __( 'Language added.', 'linguator-pro' ), 'success' ) );
					$locale = $language->locale;

					if ( 'en_US' !== $language->locale && current_user_can( 'install_languages' ) ) {
						// Attempts to install the language pack
						require_once ABSPATH . 'wp-admin/includes/translation-install.php';
						if ( ! wp_download_language_pack( $language->locale ) ) {
							linguator_pro_add_notice( new WP_Error( 'lmat_download_mo', __( 'The language was created, but the WordPress language file was not downloaded. Please install it manually.', 'linguator-pro' ), 'warning' ) );
						}

						// Force checking for themes and plugins translations updates
						wp_clean_themes_cache();
						wp_clean_plugins_cache();
					}
				}
		
				break;

			case 'delete':
				check_admin_referer( 'delete-lang' );

				$lang_id = ! empty( $_GET['lang'] ) ? absint( wp_unslash( $_GET['lang'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
				if ( $lang_id && $this->model->delete_language( $lang_id ) ) {
					linguator_pro_add_notice( new WP_Error( 'lmat_languages_deleted', __( 'Language deleted.', 'linguator-pro' ), 'success' ) );
				}

				
				break;

			case 'update':
				check_admin_referer( 'add-lang', '_wpnonce_add-lang' );
				$sanitized_data = array(
					'lang_id'    => ! empty( $_POST['lang_id'] ) ? absint( wp_unslash( $_POST['lang_id'] ) ) : 0,
					'name'       => ! empty( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
					'slug'       => ! empty( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '',
					'locale'     => ! empty( $_POST['locale'] ) ? sanitize_locale_name( wp_unslash( $_POST['locale'] ) ) : '',
					'rtl'        => ! empty( $_POST['rtl'] ) ? (bool) sanitize_text_field( wp_unslash( $_POST['rtl'] ) ) : false,
					'term_group' => ! empty( $_POST['term_group'] ) ? absint( wp_unslash( $_POST['term_group'] ) ) : 0,
					'flag'       => ! empty( $_POST['flag'] ) ? sanitize_text_field( wp_unslash( $_POST['flag'] ) ) : '',
				);
				$errors = $this->model->update_language( $sanitized_data );

				if ( is_wp_error( $errors ) ) {
					linguator_pro_add_notice( $errors );
				} else {
					linguator_pro_add_notice( new WP_Error( 'lmat_languages_updated', __( 'Language updated.', 'linguator-pro' ), 'success' ) );
				}


				break;

			case 'default-lang':
				check_admin_referer( 'default-lang' );

				$lang_id = ! empty( $_GET['lang'] ) ? absint( wp_unslash( $_GET['lang'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
				if ( $lang_id && $lang = $this->model->get_language( $lang_id ) ) {
					$this->model->update_default_lang( $lang->slug );
				}


				break;

			case 'content-default-lang':
				check_admin_referer( 'content-default-lang' );

				$this->model->set_language_in_mass();


				break;

			case 'activate':
				check_admin_referer( 'lmat_activate' );
				if ( isset( $_GET['module'] ) ) {
					$module = sanitize_key( wp_unslash( $_GET['module'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
					if ( isset( $this->modules[ $module ] ) ) {
						$this->modules[ $module ]->activate();
					}
				}

				break;

			case 'deactivate':
				check_admin_referer( 'lmat_deactivate' );
				if ( isset( $_GET['module'] ) ) {
					$module = sanitize_key( wp_unslash( $_GET['module'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
					if ( isset( $this->modules[ $module ] ) ) {
						$this->modules[ $module ]->deactivate();
					}
				}
				break;

			default:
				/**
				 * Fires when a non default action has been sent to Linguator settings
				 *
				 *  
				 */
				do_action( "lmat_action_$action" );
				break;
		}
		self::redirect();
	}

	/**
	 * Displays the 3 tabs pages: languages, strings translations, settings
	 * Also manages user input for these pages
	 *
	 *  
	 *
	 * @return void
	 */
	public function languages_page() {
		if ( 'scan' === $this->active_tab ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'linguator-pro' ) );
			}
			echo '<div class="wrap lmat-styles"><div id="lmat-site-scan" class="px-8 py-4"></div></div>';
			return;
		}
		/*
		Filter to render the languages page
		@param bool $render_lmat_languages_page
		@param string $selected_tab
		@param string $active_tab
		@return bool
		*/
		$render_lmat_languages_page = apply_filters('lmat_render_languages_page', true, $this->selected_tab, $this->active_tab);

		// return if the active tab is localizations
		if($this->active_tab === 'localizations' || !$render_lmat_languages_page){
			return;
		}
		
		// Check if this is a settings tab (not lang, strings, or wizard which has its own handling)
		$is_settings_tab = ! in_array( $this->active_tab, array( 'lang', 'strings', 'wizard' ), true );
		
		if ( $is_settings_tab ) {
			// Handle user input for legacy actions
			$raw_action = isset( $_REQUEST['lmat_action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['lmat_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$action     = is_string( $raw_action ) ? sanitize_key( $raw_action ) : '';
			if ( ! empty( $action ) ) {
				// Legacy settings actions are passed via URL (GET) and must be nonce protected.
				$nonce_action = $action;
				if ( 'activate' === $action ) {
					$nonce_action = 'lmat_activate';
				} elseif ( 'deactivate' === $action ) {
					$nonce_action = 'lmat_deactivate';
				}
				check_admin_referer( $nonce_action );
				$this->linguator_handle_actions( $action );
			}

			// Render the React container for settings
			$this->header->header();
			echo '<div class="wrap lmat-styles">';
			echo '<div id="lmat-settings"></div>';
			echo '</div>';
			return;
		}

		// Original logic for lang and strings tabs
		switch ( $this->active_tab ) {
			case 'lang':
				// Prepare the list table of languages
				$list_table = new Linguator_Table_Languages();
				$list_table->prepare_items( $this->model->get_languages_list() );
				break;

			case 'strings':
				$string_table = new Linguator_Table_String( $this->model->languages );
				$string_table->prepare_items();
				break;
		}

		// Handle user input
		$raw_action = isset( $_REQUEST['lmat_action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['lmat_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$action     = is_string( $raw_action ) ? sanitize_key( $raw_action ) : '';
		if ( 'edit' === $action && ! empty( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer( 'edit-lang' );
			$lang_id = ! empty( $_GET['lang'] ) ? absint( wp_unslash( $_GET['lang'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			// phpcs:ignore WordPress.Security.NonceVerification, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			$edit_lang = $this->model->get_language( $lang_id );
		} elseif ( ! empty( $action ) ) {
			$this->linguator_handle_actions( $action );
		}

		// Displays the page
		$modules    = $this->modules;
		$active_tab = $this->active_tab;
		$header = $this->header;
		include __DIR__ . '/../views/view-languages.php';
	}

	/**
	 * Get synchronization options formatted for JavaScript
	 *
	 *  
	 *
	 * @return array Array of sync options with label and value
	 */
	private function linguator_get_sync_options() {
		// Use the static method from Linguator_Settings_Sync to get sync options
		if ( class_exists( 'LinguatorPro\Modules\sync\Linguator_Settings_Sync' ) ) {
			$sync_metas = \LinguatorPro\Modules\sync\Linguator_Settings_Sync::list_metas_to_sync();
			
			// Format for JavaScript consumption
			$formatted_options = array();
			foreach ( $sync_metas as $value => $label ) {
				$formatted_options[] = array(
					'label' => $label,
					'value' => $value,
				);
			}
			
			return $formatted_options;
		}
		
		// Fallback to empty array if class not available
		return array();
	}

	/**
	 * Get language switcher options formatted for JavaScript
	 *
	 *  
	 *
	 * @return array Array of language switcher options with label and value
	 */
	private function get_language_switcher_options() {
        $language_switcher_options = array(
            array(
                'label' => __( 'Classic (Widgets) Based', 'linguator-pro' ),
                'value' => 'default',
				'subheading' => 'Standard language switcher widget that can be added to widget areas and sidebars.'
            ),
            array(
                'label' => __( 'Block Based', 'linguator-pro' ),
                'value' => 'block',
				'subheading' => 'Gutenberg block widget for the block editor, compatible with modern WordPress themes.'
            )
        );
        if(linguator_pro_is_plugin_active('elementor/elementor.php')){
            $language_switcher_options[] = array(
                'label' => __( 'Elementor Widget Based', 'linguator-pro' ),
                'value' => 'elementor',
				'subheading' => 'Specialized widget for Elementor page builder with enhanced styling and customization options.'
            );
        }
        return $language_switcher_options;
    }  

	/**
	 * Gets the example URLs displayed for the front page URL option.
	 *
	 * @return array{home:string,page:string}|array Empty array when no static front page is set.
	 */
	private function get_redirect_lang_example() {
		$page_on_front = (int) get_option( 'page_on_front' );
		if ( ! $page_on_front ) {
			return array();
		}

		// The page on front may have no language yet.
		$lang = $this->model->post->get_language( $page_on_front );
		$lang = $lang ?: $this->model->get_default_language();

		return array(
			'home' => $lang ? $this->links_model->home_url( $lang ) : home_url( '/' ),
			'page' => _get_page_link( $page_on_front ),
		);
	}

	/**
	 * Enqueues scripts and styles
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts() {
		parent::admin_enqueue_scripts();

		// Ensure rewrite rules are flushed if REST API might not be working
		// This fixes "invalid JSON response" errors on fresh installations
		$this->maybe_flush_rewrite_rules();

		// Check if this is a settings tab (not lang, strings, or wizard which has its own handling)
		$is_settings_tab = ! in_array( $this->active_tab, array( 'lang', 'strings', 'wizard' ), true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : false;

		/*
		Filter to enqueue the frontend settings assets
		@param bool $frontend_settings_assets
		@param string $active_tab
		@param bool $is_settings_tab
		@return bool
		*/
		$frontend_settings_assets=apply_filters('lmat_frontend_settings_assets', true, $active_tab, $is_settings_tab);

		/*
		Filter to enqueue the admin settings assets
		@param bool $admin_settings_assets
		@param string $active_tab
		@param bool $is_settings_tab
		@return bool
		*/
		$admin_settings_assets=apply_filters('lmat_admin_settings_assets', true, $active_tab, $is_settings_tab);

		if ( $is_settings_tab && (!$active_tab || empty($active_tab) || 'strings' !== $active_tab) && $frontend_settings_assets ) {
			// Enqueue React-based settings for settings tabs
			$asset_file = plugin_dir_path( LINGUATOR_PRO_ROOT_FILE ) . 'admin/assets/frontend/settings/settings.asset.php';

			if ( ! file_exists( $asset_file ) ) {
				return;
			}

			$asset = require $asset_file;
			
			$this->header->header_assets();
			// Enqueue header assets

			$translations_data=array('total_string_count' => 0, 'total_character_count' => 0, 'total_time_taken' => 0, 'service_providers' => array());
			if(Linguator_Translation_Dashboard::class){
				$avilable_service_providers = array(
					'chrome_local_ai'      => 'Chrome AI',
					'edge_local_ai'        => 'Edge AI',
					'google'               => 'Google',
					'gemini'               => 'Gemini AI',
					'localAiTranslator'    => 'Chrome AI',
					'edgeLocalAiTranslator' => 'Edge AI',
				);
				$cpt_dashboard_data=Linguator_Translation_Dashboard::get_translation_data('lmat');
				$translation_providers=(isset($cpt_dashboard_data['service_providers']) && is_array($cpt_dashboard_data['service_providers'])) ? $cpt_dashboard_data['service_providers'] : array();
				$translations_data['total_string']=isset($cpt_dashboard_data['total_string_count']) ? $this->linguator_format_number($cpt_dashboard_data['total_string_count']) : 0;
				$translations_data['total_character']=isset($cpt_dashboard_data['total_character_count']) ? $this->linguator_format_number($cpt_dashboard_data['total_character_count']) : 0;
				$translations_data['total_time']=isset($cpt_dashboard_data['total_time_taken']) ? $this->linguator_format_time_taken($cpt_dashboard_data['total_time_taken']) : 0;
				$translations_data['total_pages']=isset($cpt_dashboard_data['data']) ? count($cpt_dashboard_data['data']) : 0;
				$translations_data['service_providers']=array_map(function($item) use ($avilable_service_providers){
					$key = is_string( $item ) ? $item : '';
					if ( isset( $avilable_service_providers[ $key ] ) ) {
						return $avilable_service_providers[ $key ];
					}
					return '' !== $key ? ucfirst( $key ) : '';
				}, $translation_providers);
			}

			// Built-in AI (Chrome/Edge) setup card and provider list used by the AI Translation tab.
			wp_enqueue_style( 'lmat-chrome-ai-setup', plugins_url( 'admin/chrome-ai-setup/css/chrome-ai-setup-framework.css', LINGUATOR_PRO_ROOT_FILE ), array(), LINGUATOR_PRO_VERSION );
			wp_enqueue_style( 'lmat-provider-list', plugins_url( 'admin/assets/css/lmat-provider-list.css', LINGUATOR_PRO_ROOT_FILE ), array( 'lmat-chrome-ai-setup' ), LINGUATOR_PRO_VERSION );
			wp_enqueue_script( 'lmat-chrome-ai-setup', plugins_url( 'admin/chrome-ai-setup/js/chrome-ai-setup-framework.js', LINGUATOR_PRO_ROOT_FILE ), array(), LINGUATOR_PRO_VERSION, true );

			// Enqueue React-based settings script
			wp_enqueue_script(
				'lmat_settings',
				plugins_url( 'admin/assets/frontend/settings/settings.js', LINGUATOR_PRO_ROOT_FILE ),
				array_merge( $asset['dependencies'], array( 'lmat-chrome-ai-setup' ) ),
				$asset['version'],
				true
			);

			// Localize script with settings data
			wp_localize_script(
				'lmat_settings',
				'lmat_settings',
				array(
					'dismiss_notice' => esc_html__( 'Dismiss this notice.', 'linguator-pro' ),
					'api_url'        => rest_url( 'lmat/v1/' ),
					'nonce'          => wp_create_nonce( 'wp_rest' ),
					'activate_nonce' => wp_create_nonce( 'activate-plugin_automatic-translator-addon-for-loco-translate/automatic-translator-addon-for-loco-translate.php' ),
					'languages'      => $this->model->get_languages_list(),
					'all_languages'  => self::get_predefined_languages(),
					'home_url'       => get_home_url(),
					'redirect_lang_example' => $this->get_redirect_lang_example(),
					'modules'        => ( $this->modules ? array_keys( $this->modules ) : array() ),
					'active_tab'     => $this->active_tab,
					'locoai_plugin_status' => $this->linguator_get_locoai_plugin_status(),
					'sync_options'   => $this->linguator_get_sync_options(),
					'language_switcher_options' => $this->get_language_switcher_options(),
					'translations_data' => $translations_data,
					'wp_ai_client_available' => function_exists( 'linguator_pro_is_ai_provider_allowed' ) ? linguator_pro_is_ai_provider_allowed( 'gemini' ) : false,
					'allowed_providers'     => function_exists( 'linguator_pro_get_allowed_ai_providers' ) ? linguator_pro_get_allowed_ai_providers() : array( 'chrome_local_ai', 'google' ),
					'chrome_ai_setup'           => $this->get_chrome_ai_setup_data(),
				)
			);
			wp_localize_script(
				'lmat_settings',
				'lmat_settings_logo_data',
				[
					'logoUrl' => plugin_dir_url(LINGUATOR_PRO_ROOT_FILE) . '/assets/logo/',
					'nonce' => wp_create_nonce('wp_rest'),
					'restUrl' => rest_url('lmat/v1/'),
				]
			);

			// Enqueue styles
			wp_enqueue_style(
				'lmat_settings',
				plugins_url( 'admin/assets/css/build/main.css', LINGUATOR_PRO_ROOT_FILE ),
				array(),
				LINGUATOR_PRO_VERSION
			);

			if ( 'scan' === $this->active_tab ) {
				wp_enqueue_style(
					'lmat_scan_inspector',
					plugins_url( 'admin/assets/css/scan-inspector.css', LINGUATOR_PRO_ROOT_FILE ),
					array( 'lmat_settings' ),
					(string) filemtime( LINGUATOR_PRO_DIR . '/admin/assets/css/scan-inspector.css' )
				);
			}
		}else if($admin_settings_assets){
			$this->header->header_assets();

			// Original scripts for lang and strings tabs
			$suffix = '.min';

			wp_enqueue_script( 'lmat_settings', plugins_url( 'admin/assets/js/build/settings' . $suffix . '.js', LINGUATOR_PRO_ROOT_FILE ), array( 'jquery', 'wp-ajax-response', 'postbox', 'jquery-ui-selectmenu', 'wp-hooks' ), LINGUATOR_PRO_VERSION, true );
			wp_localize_script( 'lmat_settings', 'lmat_settings', array( 'dismiss_notice' => esc_html__( 'Dismiss this notice.', 'linguator-pro' ) ) );

			wp_enqueue_style( 'lmat_selectmenu', plugins_url( 'admin/assets/css/build/selectmenu' . $suffix . '.css', LINGUATOR_PRO_ROOT_FILE ), array(), LINGUATOR_PRO_VERSION );
		}

		$this->loco_page_assets();
	}

	/**
	 * Check if rewrite rules need to be flushed and flush them if needed
	 * 
	 * This prevents "invalid JSON response" errors when REST API endpoints aren't registered yet.
	 * ONE-TIME FIX: For existing installations, this will flush rules once and set a permanent flag.
	 *
	 * @return void
	 */
	private function maybe_flush_rewrite_rules() {
		// Check if rewrite rules have ever been flushed by our code
		$rules_flushed = get_option( 'lmat_rewrite_rules_flushed' );
		
		// If rules have never been flushed, do it now (ONE TIME ONLY)
		// This catches both new installations and existing installations that were activated before the fix
		if ( ! $rules_flushed ) {
			// Flush rewrite rules to ensure REST API endpoints are accessible
			flush_rewrite_rules();
			// Mark that we've flushed the rules (prevents flushing on every page load)
			update_option( 'lmat_rewrite_rules_flushed', 'yes' );
		}
		
		// Also check for the activation flag (for new installs)
		$needs_flush = get_option( 'lmat_needs_rewrite_flush' );
		if ( $needs_flush ) {
			flush_rewrite_rules();
			delete_option( 'lmat_needs_rewrite_flush' );
			update_option( 'lmat_rewrite_rules_flushed', 'yes' );
		}
	}

	function linguator_format_time_taken($time_taken) {
		if ($time_taken === 0) return esc_html__('0', 'linguator-pro');
		if ($time_taken < 60) {
			// translators: %d: Time taken in seconds.
			return sprintf(esc_html__('%d sec', 'linguator-pro'), $time_taken);
		}
		if ($time_taken < 3600) {
			$min = floor($time_taken / 60);
			$sec = $time_taken % 60;
			// translators: %1$d: Minutes, %2$d: Seconds.
			return sprintf(esc_html__('%1$d min %2$d sec', 'linguator-pro'), $min, $sec);
		}
		$hours = floor($time_taken / 3600);
		$min = floor(($time_taken % 3600) / 60);
		// translators: %1$d: Hours, %2$d: Minutes.
		return sprintf(esc_html__('%1$d hours %2$d min', 'linguator-pro'), $hours, $min);
	}

	public function linguator_format_number($number) {
		if ($number >= 1000000000) {
			return round($number / 1000000000, 1) . esc_html__('B', 'linguator-pro');
		} elseif ($number >= 1000000) {
			return round($number / 1000000, 1) . esc_html__('M', 'linguator-pro');
		} elseif ($number >= 1000) {
			return round($number / 1000, 1) . esc_html__('K', 'linguator-pro');
		}
		return $number;
	}

	/**
	 * Displays a notice when there are objects with no language assigned
	 *
	 *  
	 *
	 * @return void
	 */
	public function linguator_notice_objects_with_no_lang() {
		if ( ! empty( $this->options['default_lang'] ) && $this->model->get_objects_with_no_lang( 1 ) ) {
			printf(
				'<div class="error"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'There are posts, pages, categories or tags without language.', 'linguator-pro' ),
				esc_url( wp_nonce_url( '?page=lmat&lmat_action=content-default-lang&noheader=true', 'content-default-lang' ) ),
				esc_html__( 'You can set them all to the default language.', 'linguator-pro' )
			);
		}
	}

	/**
	 * Redirects to language page ( current active tab )
	 * saves error messages in a transient for reuse in redirected page
	 *
	 *  
	 *
	 * @param array $args query arguments to add to the url
	 * @return void
	 */
	public static function redirect( array $args = array() ): void {
		$errors = get_settings_errors( 'linguator-multilingual-ai-translation' );
		if ( ! empty( $errors ) ) {
			set_transient( 'lmat_settings_errors', $errors, 30 );
			$args['settings-updated'] = 1;
		}
		// Remove all known query args from the referer before redirecting.
		$to_remove = array( 'lang', 'module', 'lmat_action', 'noheader', '_wpnonce' );
		wp_safe_redirect( add_query_arg( $args, remove_query_arg( $to_remove, wp_get_referer() ) ) );
		exit;
	}

	/**
	 * Data the Chrome/Edge AI setup card needs on the AI Translation settings tab.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_chrome_ai_setup_data() {
		$languages        = array();
		$source_language  = 'en';
		$source_label     = 'English';
		$default_language = $this->model->get_default_language();

		foreach ( $this->model->get_languages_list() as $language ) {
			$languages[ $language->slug ] = array( 'name' => $language->name );
		}

		if ( ! empty( $default_language->slug ) ) {
			$source_language = $default_language->slug;
			$source_label    = $default_language->name;
		}

		return array(
			'source_language'       => $source_language,
			'source_language_label' => $source_label,
			'all_languages'         => $languages,
			'chrome_icon_url'       => esc_url( plugins_url( 'admin/assets/images/chrome.png', LINGUATOR_PRO_ROOT_FILE ) ),
			'edge_icon_url'         => esc_url( plugins_url( 'admin/assets/images/edge.png', LINGUATOR_PRO_ROOT_FILE ) ),
			'alternative_url'       => esc_url( admin_url( 'admin.php?page=lmat_settings&tab=translation' ) ),
			'texts'                 => array(
				'cardTitle'                   => esc_html__( 'Chrome AI Setup', 'linguator-pro' ),
				'cardDescription'             => esc_html__( 'Free on-device translation. We detect what your browser needs - usually just one click.', 'linguator-pro' ),
				'statusChecking'              => esc_html__( 'Checking your browser...', 'linguator-pro' ),
				'statusCheckingDesc'          => esc_html__( 'Give us a second while we detect Chrome AI support.', 'linguator-pro' ),
				'statusReady'                 => esc_html__( 'Chrome AI is Ready', 'linguator-pro' ),
				'statusReadyDesc'             => esc_html__( 'On-device translation is set up. No API key, no cost.', 'linguator-pro' ),
				'statusDownloadable'          => esc_html__( 'Language pack required', 'linguator-pro' ),
				'statusDownloadableDesc'      => esc_html__( 'Add the target translation language in your browser settings to download the translation model.', 'linguator-pro' ),
				'statusDownloading'           => esc_html__( 'Downloading language model...', 'linguator-pro' ),
				'statusDownloadingDesc'       => esc_html__( 'Keep this tab open. This happens once.', 'linguator-pro' ),
				'statusError'                 => esc_html__( 'Chrome AI is currently unavailable', 'linguator-pro' ),
				'statusErrorDesc'             => esc_html__( 'Something blocked the check. See advanced steps or use alternative options.', 'linguator-pro' ),
				'statusHttpError'             => esc_html__( 'Chrome AI needs a secure (HTTPS) connection', 'linguator-pro' ),
				'statusHttpErrorDesc'         => esc_html__( 'Serving wp-admin over HTTP prevents Chrome AI from launching. Serve pages over HTTPS or use an alternative engine.', 'linguator-pro' ),
				'btnEnable'                   => esc_html__( 'Enable Chrome AI', 'linguator-pro' ),
				'btnRetry'                    => esc_html__( 'Retry', 'linguator-pro' ),
				'btnAlternative'              => esc_html__( 'Use Another Provider', 'linguator-pro' ),
				'previewTitle'                => esc_html__( 'Try a real translation', 'linguator-pro' ),
				'previewDesc'                 => esc_html__( 'Type anything and see the exact on-device result - no page needed.', 'linguator-pro' ),
				'previewInputLabel'           => esc_html__( 'Your text', 'linguator-pro' ),
				'previewOutputLabel'          => esc_html__( 'Translation', 'linguator-pro' ),
				'previewPlaceholder'          => esc_html__( 'Type or paste text to translate...', 'linguator-pro' ),
				'previewOutPlaceholder'       => esc_html__( 'Translation will appear here.', 'linguator-pro' ),
				'btnTranslate'                => esc_html__( 'Translate preview', 'linguator-pro' ),
				'translatingText'             => esc_html__( 'Translating...', 'linguator-pro' ),
				'translationDone'             => esc_html__( 'Done in {ms} ms - on-device - no data left your browser', 'linguator-pro' ),
				'translationFailed'           => esc_html__( 'Translation failed. This pair may need its own model, or see advanced steps below.', 'linguator-pro' ),
				'advancedTitle'               => esc_html__( 'Still not working? Advanced steps', 'linguator-pro' ),
				'advancedBrowserRequirements' => esc_html__( 'Chrome AI translation needs Chrome or Edge on desktop (version 138+). It does not run on mobile phones or tablets.', 'linguator-pro' ),
				'openSetupGuide'              => esc_html__( 'Open Official Setup Guide', 'linguator-pro' ),
			),
		);
	}

	/**
	 * Get the list of predefined languages
	 *
	 *  
	 *
	 * @return string[][] {
	 *   An array of array of language properties.
	 *
	 *   @type string[] {
	 *      @type string $code     ISO 639-1 language code.
	 *      @type string $locale   WordPress locale.
	 *      @type string $name     Native language name.
	 *      @type string $dir      Text direction: 'ltr' or 'rtl'.
	 *      @type string $flag     Flag code, generally the country code.
	 *      @type string $w3c      W3C locale.
	 *      @type string $facebook Facebook locale.
	 *   }
	 * }
	 */
	public static function get_predefined_languages() {
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';

		$languages    = include __DIR__ . '/languages.php';
		$translations = wp_get_available_translations();

		// Keep only languages with existing WP language pack
		// Unless the transient has expired and we don't have an internet connection to refresh it
		if ( ! empty( $translations ) ) {
			$translations['en_US'] = ''; // Languages packs don't include en_US
			$languages = array_intersect_key( $languages, $translations );
		}

		/**
		 * Filter the list of predefined languages
		 *
		 *  
		 *   The languages arrays use associative keys instead of numerical keys
		 *
		 * @param array $languages
		 */
		$languages = apply_filters( 'lmat_predefined_languages', $languages );

		// Keep only languages with all necessary information
		foreach ( $languages as $k => $lang ) {
			if ( ! isset( $lang['code'], $lang['locale'], $lang['name'], $lang['dir'], $lang['flag'] ) ) {
				unset( $languages[ $k ] );
			}
		}

		return $languages;
	}

	/**
	 * Check LocoAI plugin installation and activation status.
	 *
	 *  
	 *
	 * @return array Plugin status information
	 */
	private function linguator_get_locoai_plugin_status() {
		// Ensure plugin functions are available
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		
		// Check if plugin is installed
		$plugin_path = 'automatic-translator-addon-for-loco-translate/automatic-translator-addon-for-loco-translate.php';
		$all_plugins = get_plugins();
		$is_installed = isset( $all_plugins[ $plugin_path ] );
		
		// Check if plugin is active
		$is_active = is_plugin_active( $plugin_path );
		return array(
			'installed' => $is_installed,
			'active'    => $is_active,
			'status'    => $is_active ? 'active' : ( $is_installed ? 'installed' : 'not_installed' )
		);
	}
}
