<?php
/**
 * Site profile for get-site-info.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the site and how to translate it, as the first call of an AI client.
 *
 * @since 1.2.0
 */
final class Site_Profile_Service {

	/**
	 * The site profile.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Profile.
	 */
	public static function get() {
		$settings = Settings_Service::get_settings();
		$profile  = array(
			'site_name'            => get_bloginfo( 'name' ),
			'site_url'             => home_url( '/' ),
			'wordpress_version'    => get_bloginfo( 'version' ),
			'linguator_version'    => defined( 'LINGUATOR_VERSION' ) ? (string) LINGUATOR_VERSION : '',
			'linguator_pro_version' => defined( 'LINGUATOR_PRO_VERSION' ) ? (string) LINGUATOR_PRO_VERSION : '',
			'default_language'     => $settings['default_language'],
			'languages'            => Language_Service::get_all(),
			'translated_types'     => $settings['translated_types'],
			'translated_taxonomies' => $settings['translated_taxonomies'],
			'current_user_can'     => array(
				'publish_posts'  => current_user_can( 'publish_posts' ),
				'manage_options' => current_user_can( 'manage_options' ),
			),
			'translation_workflow' => array(
				__( 'Call get-translation-status to see what is missing in each language.', 'linguator-pro' ),
				__( 'Call get-untranslated-content for a language and type, then get-content for each item.', 'linguator-pro' ),
				__( 'Translate terms the post uses first (get-term, translate-term) so translate-post can attach their translations.', 'linguator-pro' ),
				__( 'Save with translate-post. A successful save is not completion: read created, status, meta_skipped and the translations in the result.', 'linguator-pro' ),
				__( 'Finish with get-translation-status and confirm the missing count dropped.', 'linguator-pro' ),
			),
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$profile['notes'] = array(
				__( 'WooCommerce is active. Product prices, stock and variations are synchronised from the original by Linguator Pro; translate only the texts.', 'linguator-pro' ),
			);
		}

		return $profile;
	}
}
