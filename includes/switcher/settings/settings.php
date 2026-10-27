<?php
/**
 * @package Linguator
 */

namespace LinguatorPro\Includes\Switcher\Settings;

use LinguatorPro\Includes\Switcher\Layout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class that holds all the language switcher's settings.
 */
class Settings extends Abstract_Settings_Legacy {
	/**
	 * @var string
	 *
	 * @phpstan-var 'horizontal'|'vertical'|'dropdown'|'select'
	 */
	public $layout = 'vertical';

	/**
	 * No default value here because it depends on `is_rtl()`.
	 *
	 * @var string
	 *
	 * @phpstan-var 'left'|'center'|'right'|'stretched'|'inherit'
	 */
	public $alignment;

	/**
	 * @var bool
	 */
	public $show_flags = false;

	/**
	 * @var string
	 *
	 * @phpstan-var '3:2'|'1:1'
	 */
	public $flag_aspect_ratio = '3:2';

	/**
	 * @var string
	 *
	 * @phpstan-var ''|'names'|'codes'
	 */
	public $show_labels = 'names';

	/**
	 * @var bool
	 */
	public $hide_current = false;

	/**
	 * @var bool
	 */
	public $hide_if_empty = true;

	/**
	 * @var bool
	 */
	public $hide_if_no_translation = false;

	/**
	 * @var bool
	 */
	public $force_home = false;

	/**
	 * @var int
	 */
	public $post_id = 0;

	/**
	 * @var bool
	 */
	public $preserve_spacing = true;

	/**
	 * @var bool
	 */
	public $show_wrapper = true;

	/**
	 * @var array
	 *
	 * @phpstan-var non-empty-string[]
	 */
	public $wrapper_classes = array();

	/**
	 * @var array
	 *
	 * @phpstan-var non-empty-string[]
	 */
	public $item_classes = array();

	/**
	 * @var array
	 *
	 * @phpstan-var non-empty-string[]
	 */
	public $link_classes = array();

	/**
	 * @var string
	 */
	public $unique_id = '';

	/**
	 * @var int
	 */
	private static $increment = 0;

	/**
	 * Constructor.
	 *
	 * @param array $settings {
	 *     Optional switcher settings.
	 *
	 *     @type string   $layout                 Layout of the switcher. Possible values are `horizontal`, `vertical`,
	 *                                            `dropdown`, and `select`. Default is `vertical`.
	 *     @type string   $alignment              Alignment of the items. Possible values are `left`, `center`, `right`,
	 *                                            `stretched`, and `inherit`. Default is `left` or `right`, depending on
	 *                                            `is_rtl()`.
	 *     @type bool     $show_wrapper           Display the wrapper or not. Default is `true`.
	 *     @type bool     $show_flags             Display the flags or not. Default is `false`.
	 *     @type string   $flag_aspect_ratio      Flags aspect ratio. Possible values are `3:2` and `1:1`. Default is `3:2`.
	 *     @type string   $show_labels            Display the labels. Possible values are an empty string (no labels),
	 *                                            `names` (language names), `codes` (languages codes). Default is `names`.
	 *     @type bool     $hide_if_empty          Hide languages that don't have any posts. Default is `true`.
	 *     @type bool     $hide_if_no_translation Hide languages that don't have a translation. Default is `false`.
	 *     @type bool     $hide_current           Hide the current language. Default is `false`.
	 *     @type bool     $force_home             Force elements to link to the home pages instead of the translations.
	 *                                            Default is `false`.
	 *     @type int      $post_id                Build the links according to the translations of the given post ID.
	 *                                            Default is `0`.
	 *     @type bool     $preserve_spacing       Preserve or discard white space characters between tags.
	 *                                            Default is `true` (preserve).
	 *     @type string[] $wrapper_classes        HTML classes to add to the wrapper. Default is an empty array.
	 *     @type string[] $item_classes           HTML classes to add to each item. Default is an empty array.
	 *     @type string[] $link_classes           HTML classes to add to each link. Default is an empty array.
	 *     @type string   $unique_id              A unique identifier. Default is an empty string.
	 * }
	 */
	public function __construct( array $settings ) {
		$settings = $this->maybe_filter_legacy( $settings );

		/**
		 * Filter the language switcher settings.
		 *
		 * @param array $settings Settings.
		 */
		$settings = apply_filters( 'lmat_language_switcher_settings', $settings );

		foreach ( $this->validate( $settings ) as $name => $value ) {
			$this->$name = $value;
		}

		if ( '' === $this->unique_id ) {
			++self::$increment;
			$this->unique_id = 'lmat-switcher-' . self::$increment;
		}
	}

	/**
	 * Returns an instance of the switcher.
	 *
	 * @return string
	 *
	 * @phpstan-return class-string<Layout\Abstract_Layout>|''
	 */
	public function get_switcher_class(): string {
		switch ( $this->layout ) {
			case 'horizontal':
			case 'vertical':
				return Layout\Nav::class;

			case 'dropdown':
				return Layout\Dropdown::class;

			case 'select':
				return Layout\Select::class;

			default:
				return '';
		}
	}

	/**
	 * Validates the settings (value and type).
	 * This removes additional keys.
	 *
	 * @param array $settings Switcher settings.
	 * @return array
	 */
	protected function validate( array $settings ): array {
		$choices   = array(
			'layout'                 => array( 'horizontal', 'vertical', 'dropdown', 'select' ),
			'alignment'              => array( 'left', 'center', 'right', 'stretched', 'inherit' ),
			'flag_aspect_ratio'      => array( '3:2', '1:1' ),
			'show_labels'            => array( '', 'names', 'codes' ),
		);
		$validated = array_diff_key( get_class_vars( self::class ), array( 'increment' => 0 ) );

		$validated['alignment'] = is_rtl() ? 'right' : 'left';

		foreach ( $choices as $key => $setting_choices ) {
			if ( isset( $settings[ $key ] ) && in_array( $settings[ $key ], $setting_choices, true ) ) {
				$validated[ $key ] = $settings[ $key ];
			}
		}

		foreach ( array( 'show_flags', 'hide_current', 'hide_if_empty', 'hide_if_no_translation', 'force_home', 'preserve_spacing', 'show_wrapper' ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$validated[ $key ] = ! empty( $settings[ $key ] );
			}
		}

		if ( ! empty( $settings['post_id'] ) && is_numeric( $settings['post_id'] ) ) {
			$validated['post_id'] = absint( $settings['post_id'] );
		}

		foreach ( array( 'wrapper_classes', 'item_classes', 'link_classes' ) as $key ) {
			if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
				$validated[ $key ] = array_filter(
					$settings[ $key ],
					static function ( $class ) {
						return is_string( $class ) && ! empty( trim( $class ) );
					}
				);
			}
		}

		if ( ! empty( $settings['unique_id'] ) && is_string( $settings['unique_id'] ) ) {
			$validated['unique_id'] = sanitize_key( $settings['unique_id'] );
		}

		// Mandatory settings for the `select` layout.
		if ( 'select' === $validated['layout'] ) {
			$validated['show_flags']  = false;
			$validated['show_labels'] = 'names';
		}

		// Make sure something is displayed.
		if ( ! $validated['show_flags'] && empty( $validated['show_labels'] ) ) {
			$validated['show_labels'] = 'names';
		}

		return $validated;
	}
}
