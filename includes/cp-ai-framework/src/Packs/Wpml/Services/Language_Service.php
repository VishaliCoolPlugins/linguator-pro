<?php
/**
 * WPML language reads for the abilities subsystem.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Services;

use WP_Error;
use WPML_AT_PRO_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes WPML's active languages for agent consumption.
 *
 * Wraps WPML_AT_PRO_Helper rather than calling icl_get_languages() directly, so
 * the ability layer and the translation UI always see the same language list.
 *
 * @since 1.5.0
 */
final class Language_Service {

	/**
	 * Every active language, with the default flagged.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, mixed>> Language rows.
	 */
	public static function get_all() {
		if ( ! class_exists( 'WPML_AT_PRO_Helper' ) ) {
			return array();
		}

		$default   = self::get_default_code();
		$languages = WPML_AT_PRO_Helper::get_wpml_languages();

		if ( ! is_array( $languages ) ) {
			return array();
		}

		$rows = array();

		foreach ( $languages as $language ) {
			if ( ! is_array( $language ) || empty( $language['code'] ) ) {
				continue;
			}

			$code = (string) $language['code'];

			$rows[] = array(
				'code'       => $code,
				'name'       => isset( $language['name'] ) ? (string) $language['name'] : $code,
				'locale'     => isset( $language['locale'] ) ? (string) $language['locale'] : '',
				'flag_url'   => isset( $language['flag_url'] ) ? (string) $language['flag_url'] : '',
				'is_default' => $code === $default,
			);
		}

		return $rows;
	}

	/**
	 * The site's default (source) language code.
	 *
	 * @since 1.5.0
	 * @return string Language code, or an empty string.
	 */
	public static function get_default_code() {
		if ( ! class_exists( 'WPML_AT_PRO_Helper' ) ) {
			return '';
		}

		return (string) WPML_AT_PRO_Helper::get_default_language();
	}

	/**
	 * Active language codes only.
	 *
	 * @since 1.5.0
	 * @return string[] Language codes.
	 */
	public static function get_codes() {
		return array_column( self::get_all(), 'code' );
	}

	/**
	 * Whether a language code is active on this site.
	 *
	 * @since 1.5.0
	 * @param string $code Language code to test.
	 * @return bool True when the language is active.
	 */
	public static function is_active( $code ) {
		return in_array( (string) $code, self::get_codes(), true );
	}

	/**
	 * The error returned when a caller passes a language that is not active.
	 *
	 * @since 1.5.0
	 * @param string $code The requested code.
	 * @return WP_Error Error naming the active codes.
	 */
	public static function inactive_error( $code ) {
		return new WP_Error(
			'automlp_language_inactive',
			sprintf(
				/* translators: 1: requested language code, 2: comma-separated active codes. */
				__( '"%1$s" is not an active language on this site. Active languages are: %2$s. Call list-languages and use one of those codes.', 'wpml-translation-check-pro' ),
				(string) $code,
				implode( ', ', self::get_codes() )
			)
		);
	}
}
