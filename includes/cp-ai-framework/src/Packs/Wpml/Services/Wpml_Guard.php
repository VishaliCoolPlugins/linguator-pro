<?php
/**
 * WPML availability guard.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports whether WPML is usable, and explains it to an agent when it is not.
 *
 * Unlike Polylang, WPML has three distinct unusable states, and they need three
 * different answers: core missing, String Translation missing, or both present
 * but WPML's own setup wizard never finished. The third is invisible from the
 * outside and would otherwise make every ability fail for no stated reason.
 *
 * @since 1.5.0
 */
final class Wpml_Guard {

	/**
	 * Whether every AutoMLP requirement is satisfied.
	 *
	 * Delegates to the main plugin class so this never drifts from the gate that
	 * decides whether AutoMLP loads its own dependencies at all.
	 *
	 * @since 1.5.0
	 * @return bool True when WPML core, String Translation and setup are all ready.
	 */
	public static function ready() {
		return self::has_core() && self::has_string_translation() && self::setup_complete();
	}

	/**
	 * Whether WPML Multilingual CMS is active.
	 *
	 * @since 1.5.0
	 * @return bool True when WPML core is loaded.
	 */
	public static function has_core() {
		return defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' );
	}

	/**
	 * Whether WPML String Translation is active.
	 *
	 * @since 1.5.0
	 * @return bool True when String Translation is loaded.
	 */
	public static function has_string_translation() {
		return defined( 'WPML_ST_VERSION' ) || class_exists( 'WPML_String_Translation' );
	}

	/**
	 * Whether WPML's setup wizard has been completed.
	 *
	 * Prefers the main plugin's own check, which uses WPML's recovery-aware
	 * option getter. Falls back to a plain option read.
	 *
	 * @since 1.5.0
	 * @return bool True when setup_complete is set in icl_sitepress_settings.
	 */
	public static function setup_complete() {
		if ( function_exists( 'automlp_ai_pro_translate_addon' ) ) {
			$plugin = automlp_ai_pro_translate_addon();

			if ( is_object( $plugin ) && method_exists( $plugin, 'is_wpml_setup_complete' ) ) {
				return (bool) $plugin->is_wpml_setup_complete();
			}
		}

		$settings = get_option( 'icl_sitepress_settings', array() );

		return is_array( $settings ) && ! empty( $settings['setup_complete'] );
	}

	/**
	 * Whether the AutoMLP setup wizard has been completed.
	 *
	 * Reads are still useful before the wizard runs, so this is reported rather
	 * than enforced. Translation abilities added later should require it.
	 *
	 * @since 1.5.0
	 * @return bool True when the AutoMLP wizard finished.
	 */
	public static function automlp_setup_complete() {
		return (bool) get_option( 'automlp_ai_setup_complete' );
	}

	/**
	 * A WP_Error naming the first unmet requirement, or null when ready.
	 *
	 * The messages address the calling agent directly, because they are what it
	 * sees instead of a result. Each says whether retrying is worthwhile.
	 *
	 * @since 1.5.0
	 * @return WP_Error|null Error describing the blocker, or null.
	 */
	public static function unavailable_error() {
		if ( ! self::has_core() ) {
			return new WP_Error(
				'automlp_wpml_core_missing',
				__( 'WPML Multilingual CMS is not available in this request. It is the plugin that makes the site multilingual, and nothing can be translated without it. Tell the user it must be installed and activated under Plugins; it is a paid plugin from wpml.org, so this is not something a tool can do for them. Do not retry.', 'wpml-translation-check-pro' )
			);
		}

		if ( ! self::has_string_translation() ) {
			return new WP_Error(
				'automlp_wpml_st_missing',
				__( 'WPML Multilingual CMS is active but WPML String Translation is not. AutoMLP needs both: String Translation is what exposes page-builder and theme text as translatable strings, so without it a post cannot be broken into translatable content. Tell the user to activate WPML String Translation under Plugins; it ships with their WPML subscription. Do not retry.', 'wpml-translation-check-pro' )
			);
		}

		if ( ! self::setup_complete() ) {
			return new WP_Error(
				'automlp_wpml_setup_incomplete',
				__( 'WPML is active but its setup was never finished, so the site has no languages configured yet and there is nothing to translate into. Tell the user to complete the WPML setup wizard under WPML > Languages, choosing the site language and at least one language to translate into. This is a one-time step no tool can do for them. Retry once they confirm it is done.', 'wpml-translation-check-pro' )
			);
		}

		/*
		 * Polylang ships a WPML compatibility layer that hooks wpml_element_trid,
		 * wpml_get_element_translations, wpml_switch_language and more, and
		 * answers them from Polylang's own data. With both active every WPML
		 * lookup here returns Polylang's groups and languages, and a write
		 * through Create_Translated_Post would link the new post to a
		 * translation group that does not exist in WPML. Verified on a live site.
		 */
		if ( defined( 'POLYLANG_VERSION' ) ) {
			return new WP_Error(
				'automlp_polylang_active',
				__( 'Polylang is active alongside WPML. Polylang answers WPML\'s own lookups with its own data, so translation groups and languages read here would be wrong, and a translation written now would be linked to the wrong group. Tell the user to deactivate Polylang under Plugins before using these tools. Do not retry until they confirm it is done.', 'wpml-translation-check-pro' )
			);
		}

		return null;
	}

	/**
	 * A WP_Error explaining that the AutoMLP wizard has not been run.
	 *
	 * @since 1.5.0
	 * @return WP_Error Error describing the blocker.
	 */
	public static function setup_required_error() {
		return new WP_Error(
			'automlp_setup_incomplete',
			__( 'AutoMLP has no AI provider configured yet, so it cannot translate. Tell the user to run the AutoMLP setup wizard under WPML > AutoMLP AI and add an OpenAI or Google Gemini API key. Reading site and content information still works without it. Retry once they confirm it is done.', 'wpml-translation-check-pro' )
		);
	}
}
