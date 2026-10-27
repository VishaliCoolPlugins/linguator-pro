<?php
/**
 * @package Linguator
 */

namespace Linguator\Includes\Switcher\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class that holds the setting field data when in a menu.
 *
 * @phpstan-import-type FieldsData from Abstract_Fields
 */
class Menu extends Abstract_Fields {
	/**
	 * Returns setting field data available for the language switcher.
	 *
	 * @return array[]
	 *
	 * @phpstan-return FieldsData
	 */
	public static function get(): array {
		return array(
			'layout'                 => array(
				'label'   => __( 'Layout', 'linguator-pro' ),
				'choices' => array(
					'horizontal' => __( 'Inline', 'linguator-pro' ),
					'dropdown'   => __( 'Dropdown', 'linguator-pro' ),
				),
				'section' => __( 'Display', 'linguator-pro' ),
			),
			'show_labels'            => array(
				'label'   => __( 'Labels', 'linguator-pro' ),
				'choices' => array(
					''      => _x( 'None', 'Labels', 'linguator-pro' ),
					'names' => __( 'Language names', 'linguator-pro' ),
					'codes' => __( 'Language codes', 'linguator-pro' ),
				),
			),
			'show_flags'             => array(
				'label'   => __( 'Display flags', 'linguator-pro' ),
				'section' => __( 'Flags Settings', 'linguator-pro' ),
			),
			'flag_aspect_ratio'      => array(
				'label'   => __( 'Aspect', 'linguator-pro' ),
				'choices' => array(
					'3:2' => __( 'Landscape (3:2)', 'linguator-pro' ),
					'1:1' => __( 'Square (1:1)', 'linguator-pro' ),
				),
				'hide_if' => array(
					'show_flags' => false,
				),
			),
			'force_home'             => array(
				'label'   => __( 'Force link to front page', 'linguator-pro' ),
				'section' => __( 'Behavior', 'linguator-pro' ),
			),
			'hide_current'           => array(
				'label' => __( 'Hide the current language', 'linguator-pro' ),
			),
			'hide_if_no_translation' => array(
				'label' => __( 'Hide languages with no translation', 'linguator-pro' ),
			),
		);
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
	public static function add_legacy_settings( array $settings ): array {
		$settings['dropdown']   = isset( $settings['layout'] ) && 'dropdown' === $settings['layout'] ? 1 : 0;
		$settings['show_names'] = ! empty( $settings['show_labels'] ) ? 1 : 0;
		return $settings;
	}
}
