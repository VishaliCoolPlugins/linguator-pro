<?php
/**
 * @package Linguator
 */

namespace Linguator\Includes\Switcher\Fields;

use Linguator\Includes\Switcher\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract class to use to manage setting field data.
 *
 * @phpstan-type FieldsData array<
 *     non-falsy-string,
 *     array{
 *         label: string,
 *         choices?: array<string>,
 *         hide_if?: array<string, string|bool>,
 *         section?: string
 *     }
 * >
 */
abstract class Abstract_Fields {
	/**
	 * Returns setting field data available for the language switcher.
	 *
	 * @return array[]
	 *
	 * @phpstan-return FieldsData
	 */
	abstract public static function get(): array;

	/**
	 * Removes the legacy keys that were stored in the database alongside the new ones in case of plugin rollback.
	 *
	 * Backward compatibility with legacy switcher settings.
	 *
	 * @param array $raw_settings Raw settings.
	 * @return array
	 */
	public static function remove_legacy_settings( array $raw_settings ): array {
		if ( isset( $raw_settings['layout'] ) ) {
			unset( $raw_settings['dropdown'], $raw_settings['show_names'] );
		}
		return $raw_settings;
	}

	/**
	 * Adds some legacy keys that we want to keep in the database alongside the new ones in case of plugin rollback.
	 * Must not be called before `Abstract_Fields::filter()`.
	 *
	 * Keeps instances readable by code relying on legacy keys.
	 * Backward compatibility with legacy switcher settings.
	 *
	 * @param array $settings Switcher settings.
	 * @return array
	 */
	abstract public static function add_legacy_settings( array $settings ): array;

	/**
	 * Returns an array containing ONLY the values corresponding to the setting fields.
	 *
	 * @param Settings $settings Switcher settings.
	 * @return array
	 */
	public static function filter( Settings $settings ): array {
		return array_intersect_key( get_object_vars( $settings ), static::get() );
	}
}
