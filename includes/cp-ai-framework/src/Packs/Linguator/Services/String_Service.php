<?php
/**
 * Strings registered with Linguator, and their translations.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and saves the translations shown on Linguator's strings screen.
 *
 * @since 1.2.0
 */
final class String_Service {

	/**
	 * Strings registered with Linguator, keyed by an internal hash.
	 *
	 * @since 1.2.0
	 * @return array<string, array<string, mixed>> Strings with name, string and context.
	 */
	private static function registered() {
		$class = '\Linguator\Admin\Controllers\Linguator_Admin_Strings';

		if ( ! class_exists( $class ) ) {
			return array();
		}

		$strings = $class::get_strings();

		return is_array( $strings ) ? $strings : array();
	}

	/**
	 * String groups with how many strings each has and how many are translated.
	 *
	 * @since 1.2.0
	 * @param string $context  Group to list strings of, optional.
	 * @param string $language Language slug the strings are listed for, optional.
	 * @return array<string, mixed>|WP_Error Groups, or the strings of one group.
	 */
	public static function get_groups( $context = '', $language = '' ) {
		$strings = self::registered();
		$targets = Language_Service::target_slugs();

		if ( '' !== $context && '' !== $language ) {
			$checked = Linguator_Guard::language( $language );

			if ( is_wp_error( $checked ) ) {
				return $checked;
			}

			$items = array();

			foreach ( $strings as $entry ) {
				if ( (string) $entry['context'] !== $context ) {
					continue;
				}

				$translation = (string) linguator_translate_string( (string) $entry['string'], sanitize_key( $language ) );
				$items[]     = array(
					'name'        => (string) $entry['name'],
					'source'      => (string) $entry['string'],
					'translation' => $translation !== (string) $entry['string'] ? $translation : '',
				);
			}

			return array(
				'context'  => $context,
				'language' => sanitize_key( $language ),
				'strings'  => $items,
			);
		}

		$groups = array();

		foreach ( $strings as $entry ) {
			$group = (string) $entry['context'];

			if ( ! isset( $groups[ $group ] ) ) {
				$groups[ $group ] = array(
					'context'    => $group,
					'total'      => 0,
					'translated' => array_fill_keys( $targets, 0 ),
				);
			}

			++$groups[ $group ]['total'];

			foreach ( $targets as $slug ) {
				if ( (string) linguator_translate_string( (string) $entry['string'], $slug ) !== (string) $entry['string'] ) {
					++$groups[ $group ]['translated'][ $slug ];
				}
			}
		}

		return array( 'groups' => array_values( $groups ) );
	}

	/**
	 * Saves the translation of one registered string.
	 *
	 * @since 1.2.0
	 * @param string $source      The string in the original language, exactly as registered.
	 * @param string $language    Target language slug.
	 * @param string $translation The translation.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function save( $source, $language, $translation ) {
		$target = Linguator_Guard::language( $language );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$found = null;

		foreach ( self::registered() as $entry ) {
			if ( (string) $entry['string'] === $source ) {
				$found = $entry;
				break;
			}
		}

		if ( null === $found ) {
			return new WP_Error( 'lmat_unknown_string', __( 'That string is not registered with Linguator. Use get-string-groups to list the strings, and pass the source text exactly.', 'linguator-pro' ) );
		}

		$class = '\Linguator\Admin\Controllers\Linguator_Admin_Strings';

		if ( method_exists( $class, 'sanitize_string_translation' ) ) {
			$translation = (string) $class::sanitize_string_translation( $translation, (string) $found['name'] );
		}

		$mo = new \Linguator\Includes\Helpers\Linguator_MO();
		$mo->import_from_db( $target );
		$mo->add_entry( $mo->make_entry( $source, $translation ) );
		$mo->export_to_db( $target );

		return array(
			'name'        => (string) $found['name'],
			'context'     => (string) $found['context'],
			'language'    => (string) $target->slug,
			'translation' => $translation,
		);
	}
}
