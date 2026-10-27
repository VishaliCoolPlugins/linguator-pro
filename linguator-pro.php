<?php
/**
 * Plugin Name:       Linguator Pro - AI Auto Translate & Create Multilingual Sites
 * Plugin URI:        https://linguator.com/
 * Description:       Create a multilingual WordPress website in minutes with Linguator Pro AI – Auto Translate & Create Multilingual Sites.
 * Version:           2.2.1
 * Requires at least: 6.8
 * Requires PHP:      7.2
 * Author:            Cool Plugins
 * Author URI:        https://coolplugins.net/?utm_source=twlmat_plugin&utm_medium=inside&utm_campaign=author_page&utm_content=plugins_list
 * Text Domain:       linguator-pro
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.txt
 *
 * --- CREDITS & COPYRIGHT NOTICE ---
 * This plugin is a derivative work (fork) of the free version of Polylang, 
 * originally developed by WP SYNTEX (https://wordpress.org/plugins/polylang).
 * Original Copyright 2011-2019 Frédéric Demarle
 * Original Copyright 2019-2026 WP SYNTEX
 * it under the terms of the GNU General Public License as published by 
 * the Free Software Foundation, either version 3 or later.
 *  * * NOTE: While this is a fork of the free version, it incorporates the 
 * 'Abstract_Screen' class structure originally from the 
 * 'WP_Syntex\Polylang_Pro\Editors\Screens' namespace. This has been 
 * refactored into the 'LinguatorPro\Modules\Editors\Screens' namespace 
 * to ensure a unique environment and prevent naming collisions.
 * ----------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Includes\Core\Linguator;
use LinguatorPro\Install\Linguator_Activate;
use LinguatorPro\Install\Linguator_Deactivate;
use LinguatorPro\Install\Linguator_Usable;



// Free to Pro handoff. The free plugin shares options, hooks and routes with Pro, so only one may stay active.
if ( ! defined( 'LINGUATOR_FREE_PLUGIN_BASE' ) ) {
	define( 'LINGUATOR_FREE_PLUGIN_BASE', 'translate-words/translate-wp-words.php' );
}
if ( ! defined( 'LINGUATOR_FREE_NOTICE_KEY' ) ) {
	define( 'LINGUATOR_FREE_NOTICE_KEY', 'linguator_pro_free_deactivated' );
}

if ( ! function_exists( 'linguator_pro_deactivate_free' ) ) {
	/**
	 * Deactivate the free Linguator plugin while Pro is active.
	 *
	 * Runs on Pro activation and on every admin request, so activating the free
	 * plugin while Pro is active is undone as well. Settings are shared between
	 * the two builds and are kept.
	 *
	 * @since 2.2.2
	 *
	 * @return void
	 */
	function linguator_pro_deactivate_free() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$network_active = is_multisite() && is_plugin_active_for_network( LINGUATOR_FREE_PLUGIN_BASE );
		if ( ! $network_active && ! is_plugin_active( LINGUATOR_FREE_PLUGIN_BASE ) ) {
			return;
		}

		$capability = $network_active ? 'manage_network_plugins' : 'activate_plugins';
		if ( ! current_user_can( $capability ) ) {
			return;
		}

		deactivate_plugins( LINGUATOR_FREE_PLUGIN_BASE, true, $network_active );
		set_transient( LINGUATOR_FREE_NOTICE_KEY, 1, MINUTE_IN_SECONDS );
	}
}

if ( ! function_exists( 'linguator_pro_free_deactivated_notice' ) ) {
	/**
	 * Tell the admin that the free plugin was deactivated in favour of Pro.
	 *
	 * @since 2.2.2
	 *
	 * @return void
	 */
	function linguator_pro_free_deactivated_notice() {
		if ( ! get_transient( LINGUATOR_FREE_NOTICE_KEY ) ) {
			return;
		}
		delete_transient( LINGUATOR_FREE_NOTICE_KEY );

		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo wp_kses(
			sprintf(
				/* translators: 1: Pro plugin name, 2: Free plugin name. */
				esc_html__( 'The %1$s plugin is active and the %2$s plugin has been deactivated. Your settings and translations are kept.', 'linguator-pro' ),
				'<strong>Linguator Pro</strong>',
				'<strong>Linguator (Free)</strong>'
			),
			[ 'strong' => [] ]
		);
		echo '</p></div>';
	}
}

register_activation_hook( __FILE__, 'linguator_pro_deactivate_free' );
add_action( 'admin_init', 'linguator_pro_deactivate_free', 1, 0 );
add_action( 'admin_notices', 'linguator_pro_free_deactivated_notice', 10, 0 );
add_action( 'network_admin_notices', 'linguator_pro_free_deactivated_notice', 10, 0 );

// The free plugin already booted in this request (Pro is being activated on top of it). Both builds share
// hook names, so Pro must not boot beside it. Free is deactivated above and Pro takes over from the next request.
if ( class_exists( 'Linguator\Includes\Core\Linguator', false ) ) {
	return;
}

// Linguator constants - wrapped in checks to prevent redeclaration
if ( ! defined( 'LINGUATOR_PRO_VERSION' ) ) {
	define( 'LINGUATOR_PRO_VERSION', '2.2.1' );
}
if ( ! defined( 'LMATP_MIN_WP_VERSION' ) ) {
	define( 'LMATP_MIN_WP_VERSION', '6.8' );
}
if ( ! defined( 'LMATP_MIN_PHP_VERSION' ) ) {
	define( 'LMATP_MIN_PHP_VERSION', '7.2' );
}
if ( ! defined( 'LINGUATOR_PRO_FILE' ) ) {
	define( 'LINGUATOR_PRO_FILE', __FILE__ );
}
if ( ! defined( 'LINGUATOR_PRO_DIR' ) ) {
	define( 'LINGUATOR_PRO_DIR', __DIR__ );
}
if ( ! defined( 'LINGUATOR_PRO_URL' ) ) {
	define( 'LINGUATOR_PRO_URL', plugin_dir_url( LINGUATOR_PRO_FILE ) );
}
if ( ! defined( 'LINGUATOR_PRO_FEEDBACK_API' ) ) {
	define( 'LINGUATOR_PRO_FEEDBACK_API', 'https://feedback.coolplugins.net/' );
}
// Whether we are using Linguator, get the filename of the plugin in use.
if ( ! defined( 'LINGUATOR_PRO_ROOT_FILE' ) ) {
	define( 'LINGUATOR_PRO_ROOT_FILE', __FILE__ );
}

if ( ! defined( 'LINGUATOR_PRO_BASENAME' ) ) {
	define( 'LINGUATOR_PRO_BASENAME', plugin_basename( __FILE__ ) ); // Plugin name as known by WP.
	require __DIR__ . '/vendor/autoload.php';
}

// Register Gemini (Google AI) provider only when Gemini is in the allowed list
// (native WP AI Client on WordPress 7.0+ — not a LocoAI polyfill on 6.9.x).
add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'linguator_pro_is_ai_provider_allowed' ) || ! linguator_pro_is_ai_provider_allowed( 'gemini' ) ) {
			return;
		}

		require_once __DIR__ . '/includes/ai-providers/vendor/autoload.php';

		add_action(
			'init',
			static function () {
				if ( ! function_exists( 'linguator_pro_is_ai_provider_allowed' ) || ! linguator_pro_is_ai_provider_allowed( 'gemini' ) ) {
					return;
				}

				if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
					return;
				}

				$registry = \WordPress\AiClient\AiClient::defaultRegistry();
				if ( ! $registry || ! method_exists( $registry, 'registerProvider' ) || ! method_exists( $registry, 'hasProvider' ) ) {
					return;
				}

				$providers = array(
					'google' => '\WordPress\GoogleAiProvider\Provider\GoogleProvider',
				);

				foreach ( $providers as $provider_id => $provider_class ) {
					if ( $registry->hasProvider( $provider_id ) ) {
						continue;
					}
					if ( class_exists( $provider_class ) ) {
						$registry->registerProvider( $provider_class );
					}
				}
			},
			9
		);
	},
	20
);

if ( ! defined( 'LINGUATOR_PRO' ) ) {
	define( 'LINGUATOR_PRO', ucwords( str_replace( '-', ' ', dirname( LINGUATOR_PRO_BASENAME ) ) ) );
}

// Handle redirect after activation and language switcher visibility
add_action('admin_init', function() {
	// Don't redirect to wizard if Polylang is detected
	if ( defined( 'POLYLANG_VERSION' ) ) {
		return;
	}
	
	// Only run for users who can manage options
	if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
	// Only check setup flag on plugins page to avoid unnecessary database queries
	$is_plugins_page = false;
	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
        $request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
        $is_plugins_page = strpos( $request_uri, 'plugins.php' ) !== false;
    }
	// Only run on plugins page
	if ( $is_plugins_page ) {
        if ( get_option( 'lmat_needs_setup' ) === 'yes' ) {

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( ! is_network_admin() && ! isset( $_GET['activate-multi'] ) ) {

                delete_option( 'lmat_needs_setup' );

                wp_safe_redirect( admin_url( 'admin.php?page=lmat_wizard' ) );
                exit;
            }
        }
    }
	
	// Ensure language switcher is visible on nav-menus page for new installations
	$install_date = get_option('lmat_install_date');
	
	if ($install_date) {
		// Check if this is a recent installation (within last 24 hours)
		$install_timestamp = strtotime($install_date);
		$time_since_install = time() - $install_timestamp;
		
		// If installed within last 24 hours, ensure language switcher is visible
		if ($time_since_install <= 86400) {
			// Hook into nav-menus page load
			add_action('load-nav-menus.php', function() {
				$user_id = get_current_user_id();
				if (!$user_id) {
					return;
				}
				
				// Get hidden meta boxes for current user
				$hidden_meta_boxes = get_user_meta($user_id, 'metaboxhidden_nav-menus', true);
				
				// Initialize as empty array if not set
				if (!is_array($hidden_meta_boxes)) {
					$hidden_meta_boxes = array();
				}
				
				// Remove language switcher from hidden meta boxes to make it visible
				$hidden_meta_boxes = array_diff($hidden_meta_boxes, array('lmat_lang_switch_box'));
				
				// Update user meta
				update_user_meta($user_id, 'metaboxhidden_nav-menus', $hidden_meta_boxes);
			});
		}
	}
});

if ( ! function_exists( 'lmatp_has_constant' ) ) {
	require __DIR__ . '/includes/helpers/constant-functions.php';
}
if ( ! Linguator_Usable::can_activate() ) {
	// WP version or php version is too old.
	return;
}

if ( Linguator_Deactivate::is_deactivation() ) {
	// Stopping here if we are going to deactivate the plugin (avoids breaking rewrite rules).
	Linguator_Deactivate::add_hooks();
	return;
}

Linguator_Activate::add_hooks();

new Linguator();

