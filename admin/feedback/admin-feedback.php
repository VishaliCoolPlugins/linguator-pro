<?php
/**
 * @package Linguator
 */
namespace Linguator\Admin\Feedback;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin Feedback class for Linguator
 *
 *  
 */
class Linguator_Admin_Feedback {

	private $plugin_url     = LINGUATOR_URL;
	private $plugin_version = LINGUATOR_VERSION;
	private $plugin_name    = 'Linguator AI – Auto Translate & Create Multilingual Sites';
	private $plugin_slug    = 'twlmat';
	protected $options;

	/**
	 * Linguator bootstrap instance when constructed from the main plugin.
	 *
	 * @var object|null
	 */
	protected $linguator;

	/*
	|-----------------------------------------------------------------|
	|   Use this constructor to fire all actions and filters          |
	|-----------------------------------------------------------------|
	*/
	/**
	 * @param object|null $linguator Optional Linguator instance from the main bootstrap.
	 */
	public function __construct( $linguator = null ) {
		$this->linguator = $linguator;
		$this->options   = ( $linguator && isset( $linguator->options ) )
			? $linguator->options
			: get_option( 'linguator' );
	}

    /*
	|-----------------------------------------------------------------|
	|   Enqueue all scripts and styles to required page only          |
	|-----------------------------------------------------------------|
	*/
	function enqueue_feedback_scripts() {
		$screen = get_current_screen();
		if ( isset( $screen ) && $screen->id == 'plugins' ) {
			wp_enqueue_script( 'lmat-feedback-script', $this->plugin_url . 'admin/feedback/js/admin-feedback.js', array( 'jquery' ), $this->plugin_version, true );
			wp_enqueue_style( 'lmat-feedback-css', $this->plugin_url . 'admin/feedback/css/admin-feedback.css', null, $this->plugin_version );
		}
	}

    /*
	|-----------------------------------------------------------------|
	|   HTML for creating feedback popup form                         |
	|-----------------------------------------------------------------|
	*/
	public function show_deactivate_feedback_popup() {
		$screen = get_current_screen();
		if ( ! isset( $screen ) || $screen->id != 'plugins' ) {
			return;
		}
		$deactivate_reasons = array(
			'didnt_work_as_expected'         => array(
				'title'             => __( 'The plugin didn\'t work as expected.', 'linguator-pro' ),
				'input_placeholder' => 'What did you expect?',
			),
			'found_a_better_plugin'          => array(
				'title'             => __( 'I found a better plugin.', 'linguator-pro' ),
				'input_placeholder' => __( 'Please share which plugin.', 'linguator-pro' ),
			),
			'couldnt_get_the_plugin_to_work' => array(
				'title'             => __( 'The plugin is not working.', 'linguator-pro' ),
				'input_placeholder' => 'Please share your issue. So we can fix that for other users.',
			),
			'temporary_deactivation'         => array(
				'title'             => __( 'It\'s a temporary deactivation.', 'linguator-pro' ),
				'input_placeholder' => '',
			),
			'other'                          => array(
				'title'             => __( 'Other reason.', 'linguator-pro' ),
				'input_placeholder' => __( 'Please share the reason.', 'linguator-pro' ),
			),
		);

		?>
		<div id="cool-plugins-feedback-<?php echo esc_attr( $this->plugin_slug ); ?>" class="hide-feedback-popup">
						
			<div class="cp-feedback-wrapper">

			<div class="cp-feedback-header">
				<div class="cp-feedback-title"><?php echo esc_html__( 'Quick Feedback', 'linguator-pro' ); ?></div>
				<div class="cp-feedback-title-link">A plugin by <a href="https://coolplugins.net/?utm_source=<?php echo esc_attr( $this->plugin_slug ); ?>_plugin&utm_medium=inside&utm_campaign=coolplugins&utm_content=deactivation_feedback" target="_blank">CoolPlugins.net</a></div>
			</div>

			<div class="cp-feedback-loader">
				<img src="<?php echo esc_url( $this->plugin_url ); ?>admin/feedback/images/cool-plugins-preloader.gif">
			</div>

			<div class="cp-feedback-form-wrapper">
				<div class="cp-feedback-form-title"><?php echo esc_html__( 'If you have a moment, please share the reason for deactivating this plugin.', 'linguator-pro' ); ?></div>
				<form class="cp-feedback-form" method="post">
					<?php
					wp_nonce_field( '_cool-plugins_deactivate_feedback_nonce' );
					?>
					<input type="hidden" name="action" value="cool-plugins_deactivate_feedback" />
					
					<?php foreach ( $deactivate_reasons as $reason_key => $reason ) : ?>
						<div class="cp-feedback-input-wrapper">
							<input id="cp-feedback-reason-<?php echo esc_attr( $reason_key ); ?>" class="cp-feedback-input" type="radio" name="reason_key" value="<?php echo esc_attr( $reason_key ); ?>" />
							<label for="cp-feedback-reason-<?php echo esc_attr( $reason_key ); ?>" class="cp-feedback-reason-label"><?php echo esc_html( $reason['title'] ); ?></label>
							<?php if ( ! empty( $reason['input_placeholder'] ) ) : ?>
								<textarea class="cp-feedback-text" type="textarea" name="reason_<?php echo esc_attr( $reason_key ); ?>" placeholder="<?php echo esc_attr( $reason['input_placeholder'] ); ?>"></textarea>
							<?php endif; ?>
							<?php if ( ! empty( $reason['alert'] ) ) : ?>
								<div class="cp-feedback-text"><?php echo esc_html( $reason['alert'] ); ?></div>
							<?php endif; ?>	
						</div>
					<?php endforeach; ?>
					
					<div class="cp-feedback-terms">
					<input class="cp-feedback-terms-input" id="cp-feedback-terms-input" type="checkbox"><label for="cp-feedback-terms-input"><?php echo esc_html__( 'I agree to share anonymous usage data and basic site details (such as server, PHP, and WordPress versions) to support Linguator AI – Auto Translate & Create Multilingual Sites improvement efforts. Additionally, I allow Cool Plugins to store all information provided through this form and to respond to my inquiry.', 'linguator-pro' ); ?></label>
					</div>

					<div class="cp-feedback-button-wrapper">
						<a class="cp-feedback-button cp-submit" id="cool-plugin-submitNdeactivate">Submit and Deactivate</a>
						<a class="cp-feedback-button cp-skip" id="cool-plugin-skipNdeactivate">Skip and Deactivate</a>
					</div>
				</form>
			</div>


		   </div>
		</div>
		<?php
	}

    //  store the activate plugin version in the database
	function cpfm_get_user_info() {
		global $wpdb;
	
		// Server and WP environment details
		$server_info = [
			'server_software'        => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : 'N/A',
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct DB query is required here because WordPress core does not provide an efficient or native way to fetch all objects (posts/terms/etc) that do NOT have a language assigned (i.e., not related to any language term_taxonomy_id) in bulk. This negative relationship cannot be expressed using get_terms()/wp_get_object_terms(), especially when type filtering is needed. Using a raw query here ensures both performance and compatibility.
			'mysql_version'          => $wpdb ? sanitize_text_field($wpdb->get_var("SELECT VERSION()")) : 'N/A',
			'php_version'            => sanitize_text_field(phpversion() ?: 'N/A'),
			'wp_version'             => sanitize_text_field(get_bloginfo('version') ?: 'N/A'),
			'wp_debug'               => (defined('WP_DEBUG') && WP_DEBUG) ? 'Enabled' : 'Disabled',
			'wp_memory_limit'        => sanitize_text_field(ini_get('memory_limit') ?: 'N/A'),
			'wp_max_upload_size'     => sanitize_text_field(ini_get('upload_max_filesize') ?: 'N/A'),
			'wp_permalink_structure' => sanitize_text_field(get_option('permalink_structure') ?: 'Default'),
			'wp_multisite'           => is_multisite() ? 'Enabled' : 'Disabled',
			'wp_language'            => sanitize_text_field(get_option('WPLANG') ?: get_locale()),
			'wp_prefix'              => isset($wpdb->prefix) ? sanitize_key($wpdb->prefix) : 'N/A',
		];
	
		// Theme details
		$theme = wp_get_theme();
		$theme_data = [
			'name'      => sanitize_text_field($theme->get('Name')),
			'version'   => sanitize_text_field($theme->get('Version')),
			'theme_uri' => esc_url($theme->get('ThemeURI')),
		];
	

		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if (!function_exists('get_plugin_data')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	

		$plugin_data = [];
		$active_plugins = get_option('active_plugins', []);
	
		foreach ( $active_plugins as $plugin_path ) {
			$plugin_info = get_plugin_data(WP_PLUGIN_DIR . '/' . sanitize_text_field($plugin_path));
			$author_url = ( isset( $plugin_info['AuthorURI'] ) && !empty( $plugin_info['AuthorURI'] ) ) ? esc_url( $plugin_info['AuthorURI'] ) : 'N/A';
			$plugin_url = ( isset( $plugin_info['PluginURI'] ) && !empty( $plugin_info['PluginURI'] ) ) ? esc_url( $plugin_info['PluginURI'] ) : 'N/A';
			$plugin_data[] = [
				'name'       => sanitize_text_field($plugin_info['Name']),
				'version'    => sanitize_text_field($plugin_info['Version']),
				'plugin_uri' => !empty($plugin_url) ? $plugin_url : $author_url,
			];
		}
	
		$plugin_options = $this->get_all_plugin_options();

		return [
			'server_info'   => $server_info,
			'extra_details' => [
				'wp_theme'       => $theme_data,
				'active_plugins' => $plugin_data,
				'plugin_options' => $plugin_options,
			],
		];
	}

	/**
	 * Get all saved plugin options and configurations for telemetry.
	 *
	 * @return array
	 */
	public function get_all_plugin_options() {
		// Resolve Linguator context with model
		$linguator_context = null;
		if ( isset( $this->linguator->model ) ) {
			$linguator_context = $this->linguator;
		} elseif ( function_exists( 'LMAT' ) && LMAT() && isset( LMAT()->model ) ) {
			$linguator_context = LMAT();
		} elseif ( isset( $GLOBALS['linguator'] ) && isset( $GLOBALS['linguator']->model ) ) {
			$linguator_context = $GLOBALS['linguator'];
		}

		// Extract configured languages
		$languages = array();
		if ( $linguator_context && method_exists( $linguator_context->model, 'get_languages_list' ) ) {
			$lang_objects = $linguator_context->model->get_languages_list();
			if ( is_array( $lang_objects ) ) {
				foreach ( $lang_objects as $lang ) {
					if ( is_object( $lang ) ) {
						$languages[] = array(
							'slug' => isset( $lang->slug ) ? sanitize_key( $lang->slug ) : '',
							'name' => isset( $lang->name ) ? sanitize_text_field( $lang->name ) : '',
						);
					}
				}
			}
		}

		// Fallback: If model is not booted in this request, query lmat_language taxonomy
		if ( empty( $languages ) && taxonomy_exists( 'lmat_language' ) ) {
			$terms = get_terms( array(
				'taxonomy'   => 'lmat_language',
				'hide_empty' => false,
			) );
			if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$languages[] = array(
						'slug' => sanitize_key( $term->slug ),
						'name' => sanitize_text_field( $term->name ),
					);
				}
			}
		}

		// AI translation full configuration (excluding raw API keys)
		$ai_config = isset( $options['ai_translation_configuration'] ) && is_array( $options['ai_translation_configuration'] )
			? $options['ai_translation_configuration']
			: array();

		// Check if API key is added without saving or transmitting any secret key strings
		$is_api_key_added = '' !== trim( (string) get_option( 'connectors_ai_google_api_key', '' ) );

		$safe_ai_config = array(
			'provider'                  => isset( $ai_config['provider'] ) && is_array( $ai_config['provider'] ) ? array_keys( array_filter( $ai_config['provider'] ) ) : array(),
			'all_providers_status'      => isset( $ai_config['provider'] ) && is_array( $ai_config['provider'] ) ? $ai_config['provider'] : array(),
			'auto_translate'            => ! empty( $ai_config['auto_translate'] ),
			'auto_translate_on_publish' => ! empty( $ai_config['auto_translate_on_publish'] ),
			'content_types'             => isset( $ai_config['content_types'] ) && is_array( $ai_config['content_types'] ) ? array_keys( array_filter( $ai_config['content_types'] ) ) : array(),
			'translate_taxonomy'        => ! empty( $ai_config['translate_taxonomy'] ),
			'batch_size'                => isset( $ai_config['batch_size'] ) ? (int) $ai_config['batch_size'] : (int) get_option( 'lmat_ai_request_batch_size', 5 ),
			'models'                    => isset( $options['api_keys'] ) && is_array( $options['api_keys'] ) ? $options['api_keys'] : array(),
			'is_api_key_added'          => (bool) $is_api_key_added,
		);

		// Language switcher options
		$switcher_options = isset( $options['lmat_language_switcher_options'] ) && is_array( $options['lmat_language_switcher_options'] )
			? array_values( array_map( 'sanitize_text_field', $options['lmat_language_switcher_options'] ) )
			: (array) get_option( 'lmat_language_switcher_options', array( 'default' ) );

		// Custom post types and taxonomies
		$post_types = isset( $options['post_types'] ) && is_array( $options['post_types'] )
			? array_values( array_map( 'sanitize_key', $options['post_types'] ) )
			: array();

		$taxonomies = isset( $options['taxonomies'] ) && is_array( $options['taxonomies'] )
			? array_values( array_map( 'sanitize_key', $options['taxonomies'] ) )
			: array();

		// Synchronization options
		$sync_options = isset( $options['sync'] ) && is_array( $options['sync'] )
			? $options['sync']
			: array();

		// Domain mappings if configured
		$domains = isset( $options['domains'] ) && is_array( $options['domains'] )
			? $options['domains']
			: array();

		// Nav menus configuration
		$nav_menus = isset( $options['nav_menus'] ) && is_array( $options['nav_menus'] )
			? $options['nav_menus']
			: array();

		return array(
			// Language settings
			'default_lang'               => isset( $options['default_lang'] ) ? sanitize_text_field( $options['default_lang'] ) : '',
			'total_languages'            => count( $languages ),
			'languages'                  => $languages,

			// URL & Domain modifications
			'force_lang'                 => isset( $options['force_lang'] ) ? (int) $options['force_lang'] : 0,
			'hide_default'               => ! empty( $options['hide_default'] ),
			'rewrite'                    => ! empty( $options['rewrite'] ),
			'redirect_lang'              => ! empty( $options['redirect_lang'] ),
			'browser_detection'          => ! empty( $options['browser'] ),
			'domains'                    => $domains,

			// Media translation
			'media_support'              => ! empty( $options['media_support'] ),

			// Content translation settings
			'post_types'                 => $post_types,
			'taxonomies'                 => $taxonomies,

			// Synchronization
			'sync'                       => $sync_options,
			'menu_sync_visibility'       => ! empty( $options['menu_sync_visibility'] ),
			'nav_menus'                  => $nav_menus,

			// Language switchers
			'language_switcher_options'  => $switcher_options,

			// AI translation & providers
			'ai_translation_config'      => $safe_ai_config,

			// Status & Lifecycle
			'setup_complete'             => (bool) get_option( 'lmat_setup_complete', false ),
			'video_status'               => (bool) get_option( 'lmat_video_status', false ),
			'migration_completed'        => (bool) get_option( 'lmat_migration_completed', false ),
			'first_activation'           => isset( $options['first_activation'] ) ? $options['first_activation'] : '',
			'version'                    => defined( 'LINGUATOR_VERSION' ) ? LINGUATOR_VERSION : '',
			'initial_version'            => sanitize_text_field( (string) get_option( 'lmat_initial_version', '' ) ),
			'feedback_data_enabled'      => (bool) get_option( 'cpfm_opt_in_choice_cool_translations' ) === 'yes' || ( isset( $options['lmat_feedback_data'] ) && true === $options['lmat_feedback_data'] ),
		);
	}

	/**
	 * Backward compatibility wrapper for wizard configuration.
	 *
	 * @return array
	 */
	public function get_setup_wizard_configuration() {
		return $this->get_all_plugin_options();
	}

    function submit_deactivation_response() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), '_cool-plugins_deactivate_feedback_nonce' ) ) {
			wp_send_json_error();
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized', 'linguator-pro' ), 403 );
			return;
		}

		$reason             = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$deactivate_reasons = array(
			'didnt_work_as_expected'         => array(
				'title'             => __( 'The plugin didn\'t work as expected', 'linguator-pro' ),
				'input_placeholder' => 'What did you expect?',
			),
			'found_a_better_plugin'          => array(
				'title'             => __( 'I found a better plugin', 'linguator-pro' ),
				'input_placeholder' => __( 'Please share which plugin.', 'linguator-pro' ),
			),
			'couldnt_get_the_plugin_to_work' => array(
				'title'             => __( 'The plugin is not working', 'linguator-pro' ),
				'input_placeholder' => 'Please share your issue. So we can fix that for other users.',
			),
			'temporary_deactivation'         => array(
				'title'             => __( 'It\'s a temporary deactivation.', 'linguator-pro' ),
				'input_placeholder' => '',
			),
			'other'                          => array(
				'title'             => __( 'Other', 'linguator-pro' ),
				'input_placeholder' => __( 'Please share the reason.', 'linguator-pro' ),
			),
		);

		$plugin_initial = isset( $this->options['first_activation'] ) ? $this->options['first_activation'] : '';
		$deativation_reason = array_key_exists( $reason, $deactivate_reasons ) ? $reason : 'other';
		$sanitized_message = empty( $_POST['message'] ) || sanitize_text_field( wp_unslash( $_POST['message'] ) ) === '' ? 'N/A' : sanitize_text_field( wp_unslash( $_POST['message'] ) );
		$admin_email       = sanitize_email( get_option( 'admin_email' ) );
		$site_url          = esc_url( site_url() );
		$install_date 		= get_option('lmat_install_date');
		$unique_key     	= '153';  // Ensure this key is unique per plugin to prevent collisions when site URL and install date are the same across plugins
		$site_id        	= $site_url . '-' . $install_date . '-' . $unique_key;
		$feedback_url      = LINGUATOR_FEEDBACK_API .'wp-json/coolplugins-feedback/v1/feedback';
		$user_info         = $this->cpfm_get_user_info();
		$server_info         = $user_info['server_info'];
		$extra_details         = $user_info['extra_details'];
		$response          = wp_remote_post(
			$feedback_url,
			array(
				'timeout' => 30,
				'body'    => array(
					'server_info' => wp_json_encode($server_info), 
					'extra_details' => wp_json_encode($extra_details),
					'plugin_initial'  => sanitize_text_field(get_option('lmat_initial_version')),
					'plugin_version' => sanitize_text_field($this->plugin_version),
					'plugin_name'    => sanitize_text_field($this->plugin_name),
					'reason'         => sanitize_text_field($deativation_reason),
					'review'         => $sanitized_message,
					'email'          => $admin_email,
					'domain'         => $site_url,
					'site_id'    	 => md5($site_id),
				),
			)
		);

		wp_send_json_success(
			array(
				'response_code' => wp_remote_retrieve_response_code( $response ),
			)
		);
	}
    
}
