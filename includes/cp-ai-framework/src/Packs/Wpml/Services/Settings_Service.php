<?php
/**
 * WPML settings reads for the abilities subsystem.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Services;

use WPML_AT_PRO_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports the WPML and AutoMLP settings that change translation behaviour.
 *
 * Everything here comes out of WPML's single icl_sitepress_settings option plus
 * AutoMLP's own options. The values are translated into words rather than
 * passed through raw, because WPML stores most of them as bare integers whose
 * meaning lives in constants an agent has no access to.
 *
 * @since 1.5.0
 */
final class Settings_Service {

	/**
	 * Language-negotiation modes, keyed by WPML's stored integer.
	 *
	 * @since 1.5.0
	 * @var array<int, string>
	 */
	const URL_MODES = array(
		1 => 'directories',
		2 => 'separate_domains',
		3 => 'query_parameter',
	);

	/**
	 * Post-type and taxonomy translation modes, keyed by WPML's stored integer.
	 *
	 * @since 1.5.0
	 * @var array<int, string>
	 */
	const SYNC_MODES = array(
		0 => 'not_translatable',
		1 => 'translatable',
		2 => 'translatable_show_default',
	);

	/**
	 * Custom-field handling modes, keyed by WPML's stored integer.
	 *
	 * @since 1.5.0
	 * @var array<int, string>
	 */
	const FIELD_MODES = array(
		0 => 'ignore',
		1 => 'copy',
		2 => 'translate',
		3 => 'copy_once',
	);

	/**
	 * Reads WPML's settings option, preferring its recovery-aware getter.
	 *
	 * WPML serialises a large array into one option; when it is truncated by a
	 * database or migration issue, Option::getOrAttemptRecovery() restores it
	 * from WPML's backup. A plain get_option() would silently return garbage.
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> WPML settings.
	 */
	public static function get_wpml_settings() {
		if ( class_exists( '\WPML\LIB\WP\Option' ) ) {
			$settings = \WPML\LIB\WP\Option::getOrAttemptRecovery( 'icl_sitepress_settings', array() );

			if ( is_array( $settings ) ) {
				return $settings;
			}
		}

		$settings = get_option( 'icl_sitepress_settings', array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * The full settings payload for the get-translation-settings ability.
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> Settings, grouped by concern.
	 */
	public static function get_all() {
		$settings = self::get_wpml_settings();

		return array(
			'wpml'    => array(
				'setup_complete'  => Wpml_Guard::setup_complete(),
				'default_language' => Language_Service::get_default_code(),
				'url_format'      => self::label( self::URL_MODES, $settings, 'language_negotiation_type', 'unknown' ),
				'translated_document_slug' => self::slug_mode( $settings ),
				'sync_post_taxonomies' => ! empty( $settings['sync_post_taxonomies'] ),
				'sync_delete'      => ! empty( $settings['sync_delete'] ),
				'sync_page_parent' => ! empty( $settings['sync_page_parent'] ),
			),
			'automlp' => self::get_automlp_settings(),
			'custom_fields' => self::get_custom_field_modes( $settings ),
			'notes'   => array(
				__( 'url_format "directories" means translations live at /es/..., "separate_domains" means a different domain per language, "query_parameter" means ?lang=es.', 'wpml-translation-check-pro' ),
				__( 'A custom field set to "copy" is duplicated to the translation unchanged; "translate" exposes it as translatable text; "ignore" leaves it out entirely. Elementor and Divi store their layouts in fields that must stay on "copy", because their text is translated through the page-builder package instead.', 'wpml-translation-check-pro' ),
			),
		);
	}

	/**
	 * AutoMLP's own translation-affecting options.
	 *
	 * API keys are reported only as present or absent; the values never leave
	 * the server through this surface.
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> AutoMLP settings.
	 */
	public static function get_automlp_settings() {
		$models = get_option( 'automlp_ai_translation_models', array() );
		$models = is_array( $models ) ? $models : array();

		$providers = array();

		if ( class_exists( 'WPML_AT_PRO_Helper' ) ) {
			$keys = WPML_AT_PRO_Helper::get_providers_key( array( 'openai', 'google' ) );
			$keys = is_array( $keys ) ? $keys : array();

			foreach ( array( 'openai', 'google' ) as $provider ) {
				$providers[ $provider ] = array(
					'configured' => ! empty( $keys[ $provider ] ),
					'model'      => isset( $models[ $provider ] ) ? (string) $models[ $provider ] : '',
				);
			}
		}

		$enabled = get_option( 'automlp_enabled_providers', array( 'google', 'openai', 'chrome-built-in-ai', 'edge-built-in-ai' ) );

		return array(
			'version'            => defined( 'AUTOMLP_AI_PRO_VERSION' ) ? AUTOMLP_AI_PRO_VERSION : '',
			'setup_complete'     => Wpml_Guard::automlp_setup_complete(),
			'providers'          => $providers,
			'enabled_providers'  => is_array( $enabled ) ? array_values( $enabled ) : array(),
			'new_post_status'    => Translation_Writer::wpml_new_post_status(),
			'translate_default_language' => (string) get_option( 'automlp_translate_default_language', '' ),
			'supported_editors'  => class_exists( 'WPML_AT_PRO_Helper' ) ? WPML_AT_PRO_Helper::supported_editors() : array(),
		);
	}

	/**
	 * How WPML derives the slug of a translated post.
	 *
	 * Delegates to the writer so this report always matches what
	 * translate-post will actually do. WPML stores the setting as a word
	 * ("auto-generate", "translate", "copy-encoded"), not a number.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $settings WPML settings.
	 * @return string One of from_title|translate|copy|unknown.
	 */
	private static function slug_mode( array $settings ) {
		$writer = '\AUTOMLP_PRO_WPML\Includes\Wpml\Create_Translated_Post';

		if ( ! isset( $settings['translated_document_page_url'] ) || ! class_exists( $writer ) ) {
			return 'unknown';
		}

		return (string) $writer::get_wpml_translated_document_page_url_mode();
	}

	/**
	 * Custom fields grouped by how WPML handles them.
	 *
	 * Only fields WPML has an explicit setting for are listed; the option holds
	 * hundreds of entries on a mature site, so this returns counts plus the
	 * translate-mode fields, which are the ones that affect a translation run.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $settings WPML settings.
	 * @return array<string, mixed> Custom-field summary.
	 */
	private static function get_custom_field_modes( array $settings ) {
		$fields = isset( $settings['translation-management']['custom_fields_translation'] )
			? $settings['translation-management']['custom_fields_translation']
			: array();

		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		$counts    = array_fill_keys( array_values( self::FIELD_MODES ), 0 );
		$translate = array();
		$copy      = array();

		foreach ( $fields as $field => $mode ) {
			$label = isset( self::FIELD_MODES[ (int) $mode ] ) ? self::FIELD_MODES[ (int) $mode ] : 'ignore';

			++$counts[ $label ];

			if ( 'translate' === $label ) {
				$translate[] = (string) $field;
			} elseif ( 'copy' === $label && 0 === strpos( (string) $field, '_' ) ) {
				// Underscore-prefixed copies are where builders keep their layouts.
				$copy[] = (string) $field;
			}
		}

		sort( $translate );
		sort( $copy );

		return array(
			'total'            => count( $fields ),
			'counts'           => $counts,
			'translated_fields' => $translate,
			'copied_private_fields' => $copy,
		);
	}

	/**
	 * Maps a stored integer setting to its word, with a fallback.
	 *
	 * @since 1.5.0
	 * @param array<int, string>   $map      Integer-to-word map.
	 * @param array<string, mixed> $settings WPML settings.
	 * @param string               $key      Settings key to read.
	 * @param string               $fallback Value when the key is absent.
	 * @return string The mapped word.
	 */
	private static function label( array $map, array $settings, $key, $fallback ) {
		if ( ! isset( $settings[ $key ] ) ) {
			return $fallback;
		}

		$value = (int) $settings[ $key ];

		return isset( $map[ $value ] ) ? $map[ $value ] : $fallback;
	}
}
