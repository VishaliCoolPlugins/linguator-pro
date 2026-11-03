<?php
/**
 * Polylang string translation operations.
 *
 * @package AutoPoly
 * @since   1.10.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Services;

use PLL_MO;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes Polylang's registered string translations.
 *
 * @since 1.10.0
 */
final class String_Service {

	/**
	 * Sets the translation of a registered string.
	 *
	 * @since 1.10.0
	 * @param string $original    Original string; must match exactly.
	 * @param string $language    Target language slug.
	 * @param string $translation Translated string.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function translate_string( $original, $language, $translation ) {
		$original = (string) $original;

		if ( '' === $original ) {
			return new WP_Error(
				'atfpp_empty_string',
				__( 'The original string cannot be empty.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		if ( ! class_exists( 'PLL_MO' ) ) {
			return Polylang_Guard::unavailable_error();
		}

		$slug     = sanitize_key( (string) $language );
		$language = Language_Service::get_by_slug( $slug );

		if ( null === $language ) {
			return new WP_Error(
				'atfpp_invalid_language',
				sprintf(
					/* translators: %s: language slug. */
					__( 'Language "%s" was not found.', 'autopoly-ai-translation-for-polylang-pro' ),
					$slug
				)
			);
		}

		$mo = new PLL_MO();
		$mo->import_from_db( $language );
		$mo->add_entry( $mo->make_entry( $original, (string) $translation ) );
		$mo->export_to_db( $language );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: language slug. */
				__( 'String translated into "%s".', 'autopoly-ai-translation-for-polylang-pro' ),
				$slug
			),
		);
	}

	/**
	 * Ensures Polylang's admin strings class is loaded.
	 *
	 * The reference implementation hardcoded wp-content/plugins/polylang/admin/,
	 * which is the wrong directory in Polylang 3.x, breaks on Polylang Pro and
	 * breaks under a custom WP_CONTENT_DIR.
	 *
	 * @since 1.10.0
	 * @return bool True when PLL_Admin_Strings is available.
	 */
	private static function load_admin_strings() {
		if ( class_exists( 'PLL_Admin_Strings' ) ) {
			return true;
		}

		if ( defined( 'POLYLANG_DIR' ) ) {
			$file = POLYLANG_DIR . '/src/admin/admin-strings.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		return class_exists( 'PLL_Admin_Strings' );
	}

	/**
	 * Lists registered string groups with per-language translation status.
	 *
	 * @since 1.10.0
	 * @param string|null $group Optional group name filter.
	 * @return array<int, array<string, mixed>> String groups.
	 */
	public static function get_string_groups( $group = null ) {
		if ( ! Polylang_Guard::model_ready() || ! self::load_admin_strings() ) {
			return array();
		}

		$strings   = \PLL_Admin_Strings::get_strings();
		$languages = PLL()->model->get_languages_list();
		$filter    = ( null !== $group && '' !== $group ) ? sanitize_text_field( (string) $group ) : '';
		$groups    = array();

		if ( ! class_exists( 'PLL_MO' ) ) {
			return array();
		}

		// One MO object per language, built once rather than per string.
		$mo_objects = array();

		foreach ( $languages as $language ) {
			$mo = new PLL_MO();
			$mo->import_from_db( $language );

			$mo_objects[ $language->slug ] = $mo;
		}

		foreach ( $strings as $string_data ) {
			$context = isset( $string_data['context'] ) ? (string) $string_data['context'] : 'Polylang';

			if ( '' !== $filter && $context !== $filter ) {
				continue;
			}

			if ( ! isset( $groups[ $context ] ) ) {
				$groups[ $context ] = array(
					'name'    => $context,
					'strings' => array(),
				);
			}

			$translations = array();

			foreach ( $languages as $language ) {
				$mo         = $mo_objects[ $language->slug ];
				$translated = (string) $mo->translate( $string_data['string'] );

				$is_translated = ( '' !== $translated && $translated !== $string_data['string'] );

				$translations[ $language->slug ] = array(
					'translated' => $is_translated,
					'value'      => $is_translated ? $translated : null,
				);
			}

			$groups[ $context ]['strings'][] = array(
				'name'         => isset( $string_data['name'] ) ? $string_data['name'] : '',
				'string'       => $string_data['string'],
				'multiline'    => isset( $string_data['multiline'] ) ? (bool) $string_data['multiline'] : false,
				'translations' => $translations,
			);
		}

		return array_values( $groups );
	}
}
