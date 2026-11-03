<?php
/**
 * Read access to the settings that govern how translation behaves.
 *
 * Answers the question nothing else in this layer can: why did a translation
 * come out the way it did. That answer is split across two plugins -- Polylang
 * decides what is translatable and what is synchronised, AutoPoly decides what
 * status translations get, which meta keys are allowed and what guidance the
 * translator is given -- so both are read here and returned together.
 *
 * Read-only by design. Several of these values reshape every URL on the site or
 * flush rewrite rules when changed, which is not a thing to expose to an
 * automated caller; changing them stays in wp-admin.
 *
 * @package AutoPoly
 * @since   1.11.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Services;

use CoolPlugins\AiFramework\Packs\Polylang\Pack;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects Polylang and AutoPoly translation settings into one payload.
 *
 * @since 1.11.0
 */
final class Settings_Service {

	/**
	 * Option that holds AutoPoly's slug rule (Settings > Slug translation).
	 *
	 * @since 1.17.0
	 * @var string
	 */
	const SLUG_OPTION = 'atfp_slug_translation_option';

	/**
	 * The slug rules the option can hold: slug from the translated title, a translated slug, or the source slug kept.
	 *
	 * @since 1.17.0
	 * @var string[]
	 */
	const SLUG_MODES = array( 'title_translate', 'slug_translate', 'slug_keep' );

	/**
	 * Every meta key Polylang can synchronise between translations.
	 *
	 * Used only when PLL_Settings_Sync cannot be reached, which is the normal
	 * case in a REST request: it is an admin-only class. The list matches
	 * PLL_Settings_Sync::list_metas_to_sync().
	 *
	 * @since 1.11.0
	 * @var string[]
	 */
	const SYNC_KEYS = array(
		'taxonomies',
		'post_meta',
		'comment_status',
		'ping_status',
		'sticky_posts',
		'post_date',
		'post_format',
		'post_parent',
		'_wp_page_template',
		'menu_order',
		'_thumbnail_id',
	);

	/**
	 * Returns every translation-related setting from both plugins.
	 *
	 * @since 1.11.0
	 * @return array<string, mixed> Settings payload.
	 */
	public static function get_all() {
		return array(
			'polylang' => self::get_polylang_settings(),
			'autopoly' => self::get_autopoly_settings(),
			// Settings an Elementor header or footer translation depends on, each with what to do if it is off.
			'elementor' => Template_Service::environment(),
		);
	}

	/**
	 * Polylang's own configuration.
	 *
	 * @since 1.11.0
	 * @return array<string, mixed> Polylang settings, or an availability note.
	 */
	public static function get_polylang_settings() {
		if ( ! Polylang_Guard::model_ready() ) {
			return array(
				'available' => false,
				'notice'    => __( 'Polylang is not active, so its settings cannot be read.', 'autopoly-ai-translation-for-polylang-pro' ),
			);
		}

		$sync = self::pll_get( 'sync', array() );
		$sync = is_array( $sync ) ? array_values( array_map( 'strval', $sync ) ) : array();

		/*
		 * Two different lists, and reporting the wrong one is actively
		 * misleading. The "post_types" option holds only the extra types an
		 * admin opted in; posts and pages are always translatable and never
		 * appear there, so a site translating pages perfectly well still stores
		 * an empty array. The model's list is the effective one.
		 */
		$post_types = self::effective_types( 'get_translated_post_types' );
		$taxonomies = self::effective_types( 'get_translated_taxonomies' );

		$opted_in_types = self::pll_get( 'post_types', array() );
		$opted_in_taxes = self::pll_get( 'taxonomies', array() );

		$force_lang = self::pll_get( 'force_lang', 1 );

		return array(
			'available'                    => true,
			'version'                      => defined( 'POLYLANG_VERSION' ) ? POLYLANG_VERSION : '',
			// Says which of the two option back ends answered, for diagnosis.
			'settings_source'              => null !== self::options_object() ? 'options_api' : 'legacy_option',
			'default_language'             => (string) self::pll_get( 'default_lang', '' ),
			'url_mode'                     => (int) $force_lang,
			'url_mode_label'               => self::url_mode_label( (int) $force_lang ),
			'hide_default_language_in_url' => (bool) self::pll_get( 'hide_default', true ),
			'detect_browser_language'      => (bool) self::pll_get( 'browser', false ),
			'media_translation_enabled'    => (bool) self::pll_get( 'media_support', false ),
			/*
			 * These two are the usual answer to "why can't this be translated":
			 * a type absent here cannot be translated at all.
			 */
			'translatable_post_types'      => $post_types,
			'translatable_taxonomies'      => $taxonomies,
			// The subset explicitly enabled in Polylang's settings, if any.
			'post_types_enabled_in_settings' => is_array( $opted_in_types ) ? array_values( array_map( 'strval', $opted_in_types ) ) : array(),
			'taxonomies_enabled_in_settings' => is_array( $opted_in_taxes ) ? array_values( array_map( 'strval', $opted_in_taxes ) ) : array(),
			/*
			 * Split rather than returned raw: the useful half is what is NOT
			 * synchronised, which is what explains a featured image or a page
			 * template failing to reach the translation.
			 */
			'synchronized'                 => $sync,
			'not_synchronized'             => array_values( array_diff( self::sync_keys(), $sync ) ),
			// Which menu is shown for which language; the input to sync-menu.
			'menus'                        => Menu_Service::list_menus(),
		);
	}

	/**
	 * AutoPoly's own translation settings.
	 *
	 * @since 1.11.0
	 * @return array<string, mixed> AutoPoly settings.
	 */
	public static function get_autopoly_settings() {
		/*
		 * Stored as a real boolean by the settings screen but as the string
		 * "false" by older builds, and ATFP_Re_Translation compares it against
		 * the string. The same comparison is used here so this never disagrees
		 * with the code that acts on it.
		 */
		$retranslation = get_option( 'atfp_retranslation_enabled', true );

		$settings = array(
			'abilities_enabled'           => Pack::is_enabled(),
			'retranslation_tracking'      => 'false' !== $retranslation && false !== $retranslation,
			'bulk_translation_status'     => (string) get_option( 'atfp_bulk_post_status', 'draft' ),
			'slug_translation'            => self::slug_mode(),
			'custom_block_translation'    => (bool) get_option( 'atfp_custom_block_translation', false ),
			'glossary_entry_count'        => self::glossary_count(),
			'menu_sync_enabled'           => Menu_Service::is_enabled(),
			'translatable_post_meta_keys' => array_keys( Content_Service::get_allowed_meta_keys( 'post' ) ),
			'translatable_term_meta_keys' => array_keys( Content_Service::get_allowed_meta_keys( 'term' ) ),
		);

		/*
		 * Site-specific instructions the owner wrote for AutoPoly's translator.
		 * Carried here for the same reason the glossary is carried in
		 * get-content: an MCP client that ignores it produces translations in a
		 * different voice from the rest of the site.
		 */
		$custom_prompt = trim( (string) get_option( 'atfp_context_aware', '' ) );

		if ( '' !== $custom_prompt ) {
			$settings['custom_translation_prompt'] = $custom_prompt;
		}

		$provider = self::default_provider();

		if ( '' !== $provider ) {
			// The provider name only. API keys are never exposed here.
			$settings['default_ai_provider'] = $provider;
		}

		return $settings;
	}

	/**
	 * The post types or taxonomies Polylang actually treats as translatable.
	 *
	 * @since 1.11.0
	 * @param string $method Model method: get_translated_post_types or get_translated_taxonomies.
	 * @return string[] Effective type names.
	 */
	private static function effective_types( $method ) {
		/*
		 * is_callable(), never method_exists(): PLL_Model declares neither of
		 * these methods and routes both through __call(), so method_exists()
		 * reports false and would silently yield an empty list on a site whose
		 * posts and pages are perfectly translatable.
		 */
		if ( ! Polylang_Guard::model_ready() || ! is_callable( array( PLL()->model, $method ) ) ) {
			return array();
		}

		$types = PLL()->model->$method();

		return is_array( $types ) ? array_values( array_map( 'strval', $types ) ) : array();
	}

	/**
	 * Polylang's typed options object, when this version has one.
	 *
	 * Detected by capability rather than by version number: the typed API
	 * arrived in Polylang 3.7, but a version comparison would also have to know
	 * about Polylang Pro and any fork, whereas the object either answers get()
	 * or it does not.
	 *
	 * @since 1.11.0
	 * @return object|null The options object, or null on older Polylang.
	 */
	private static function options_object() {
		if ( ! function_exists( 'PLL' ) ) {
			return null;
		}

		$pll = PLL();

		if ( ! is_object( $pll ) ) {
			return null;
		}

		foreach ( array( 'options', 'model' ) as $property ) {
			if ( ! property_exists( $pll, $property ) || ! is_object( $pll->$property ) ) {
				continue;
			}

			$candidate = ( 'model' === $property )
				? ( property_exists( $pll->model, 'options' ) ? $pll->model->options : null )
				: $pll->options;

			if ( is_object( $candidate ) && method_exists( $candidate, 'get' ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Reads one Polylang option through whichever back end is available.
	 *
	 * @since 1.11.0
	 * @param string $key     Option key.
	 * @param mixed  $default Value to use when the key is absent.
	 * @return mixed The stored value, or the default.
	 */
	private static function pll_get( $key, $default = null ) {
		$options = self::options_object();

		if ( null !== $options ) {
			$value = $options->get( (string) $key );

			return null === $value ? $default : $value;
		}

		// Polylang before 3.7 kept a plain array in a single option.
		$legacy = get_option( 'polylang', array() );

		return ( is_array( $legacy ) && array_key_exists( $key, $legacy ) ) ? $legacy[ $key ] : $default;
	}

	/**
	 * Every meta key Polylang is able to synchronise.
	 *
	 * @since 1.11.0
	 * @return string[] Sync keys.
	 */
	private static function sync_keys() {
		if ( class_exists( 'PLL_Settings_Sync' ) && method_exists( 'PLL_Settings_Sync', 'list_metas_to_sync' ) ) {
			$keys = \PLL_Settings_Sync::list_metas_to_sync();

			if ( is_array( $keys ) && array() !== $keys ) {
				return array_map( 'strval', array_keys( $keys ) );
			}
		}

		return self::SYNC_KEYS;
	}

	/**
	 * Turns Polylang's force_lang integer into something readable.
	 *
	 * The raw value is an integer with no meaning to a caller, and it decides
	 * the shape of every translated URL on the site.
	 *
	 * @since 1.11.0
	 * @param int $force_lang Stored force_lang value.
	 * @return string Translated label.
	 */
	private static function url_mode_label( $force_lang ) {
		switch ( (int) $force_lang ) {
			case 0:
				return __( 'The language is set from content, and does not appear in the URL.', 'autopoly-ai-translation-for-polylang-pro' );
			case 2:
				return __( 'The language is set from the subdomain name in pretty permalinks.', 'autopoly-ai-translation-for-polylang-pro' );
			case 3:
				return __( 'The language is set from a different domain per language.', 'autopoly-ai-translation-for-polylang-pro' );
			case 1:
			default:
				return __( 'The language is set from the directory name in pretty permalinks, for example /fr/page.', 'autopoly-ai-translation-for-polylang-pro' );
		}
	}

	/**
	 * The slug rule saved in AutoPoly's settings, always one of SLUG_MODES.
	 *
	 * @since 1.17.0
	 * @return string "title_translate", "slug_translate" or "slug_keep".
	 */
	public static function slug_mode() {
		$mode = (string) get_option( self::SLUG_OPTION, 'title_translate' );

		return in_array( $mode, self::SLUG_MODES, true ) ? $mode : 'title_translate';
	}

	/**
	 * Number of glossary entries recorded, so a caller knows whether to fetch them.
	 *
	 * @since 1.11.0
	 * @return int Entry count.
	 */
	private static function glossary_count() {
		if ( ! Glossary_Service::is_available() ) {
			return 0;
		}

		$glossary = get_option( 'atfpp_glossary_data', array() );

		return is_array( $glossary ) ? count( $glossary ) : 0;
	}

	/**
	 * Name of the configured AI provider, without any credential.
	 *
	 * @since 1.11.0
	 * @return string Provider name, or an empty string.
	 */
	private static function default_provider() {
		if ( class_exists( 'ATFPP_Helper' ) && method_exists( 'ATFPP_Helper', 'get_default_provider' ) ) {
			$provider = \ATFPP_Helper::get_default_provider();

			if ( is_string( $provider ) ) {
				return $provider;
			}

			if ( is_array( $provider ) && isset( $provider['value'] ) ) {
				return (string) $provider['value'];
			}
		}

		return (string) get_option( 'atfp_default_provider', '' );
	}
}
