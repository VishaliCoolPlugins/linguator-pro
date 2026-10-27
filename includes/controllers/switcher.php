<?php
/**
 * @package Linguator
 */

namespace Linguator\Includes\Controllers;


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use Linguator\Includes\Other\Linguator_Language;
use Linguator\Includes\Switcher\Switcher;
use Linguator\Includes\Switcher\Settings\Settings;
use Linguator\Admin\Controllers\Linguator_Admin_Links;



/**
 * A class to display a language switcher on frontend
 *
 *  
 */
class Linguator_Switcher {
	/**
	 * @var Linguator_Links|null
	 */
	protected $links;

	/**
	 * Displays a language switcher
	 * or returns the raw elements to build a custom language switcher.
	 *
	 *  
	 *
	 * @param Linguator_Links $links Instance of Linguator_Links.
	 * @param array     $args {
	 *   Optional array of arguments.
	 *
	 *   @type int      $dropdown               The list is displayed as dropdown if set, defaults to 0.
	 *   @type int      $echo                   Echoes the list if set to 1, defaults to 1.
	 *   @type int      $hide_if_empty          Hides languages with no posts ( or pages ) if set to 1, defaults to 1.
	 *   @type int      $show_flags             Displays flags if set to 1, defaults to 0.
	 *   @type int      $show_names             Shows language names if set to 1, defaults to 1.
	 *   @type string   $display_names_as       Whether to display the language name or its slug, valid options are 'slug' and 'name', defaults to name.
	 *   @type int      $force_home             Will always link to home in translated language if set to 1, defaults to 0.
	 *   @type int      $hide_if_no_translation Hides the link if there is no translation if set to 1, defaults to 0.
	 *   @type int      $hide_current           Hides the current language if set to 1, defaults to 0.
	 *   @type int      $post_id                Returns links to the translations of the post defined by post_id if set, defaults not set.
	 *   @type int      $raw                    Return a raw array instead of html markup if set to 1, defaults to 0.
	 *   @type string   $item_spacing           Whether to preserve or discard whitespace between list items, valid options are 'preserve' and 'discard', defaults to 'preserve'.
	 *   @type int      $admin_render           Allows to force the current language code in an admin context if set, default to 0. Need to set the admin_current_lang argument below.
	 *   @type string   $admin_current_lang     The current language code in an admin context. Need to set the admin_render to 1, defaults not set.
	 *   @type string[] $classes                A list of CSS classes to set to each elements outputted.
	 *   @type string[] $link_classes           A list of CSS classes to set to each link outputted.
	 *   @type string   $layout                 Switcher layout: 'horizontal', 'vertical', 'dropdown' or 'select'. Overrides `dropdown`.
	 *   @type bool     $show_wrapper           Wraps the switcher in a navigation element, defaults to false when `layout` is not set.
	 *   @type string   $unique_id              Id of the wrapper or `<select>` element.
	 * }
	 * @return string|array either the html markup of the switcher or the raw elements to build a custom language switcher
	 */
	public function the_languages( $links, $args = array() ) {
		$this->links = $links;
		$args        = (array) $args;
		$raw         = ! empty( $args['raw'] );

		if ( empty( $links ) ) {
			return $raw ? array() : '';
		}

		// Legacy calls had no outer wrapper.
		if ( ! $raw && ! isset( $args['show_wrapper'] ) && ! isset( $args['layout'] ) ) {
			$args['show_wrapper'] = false;
		}

		// Keep the legacy `<select>` id so existing labels still target it.
		if ( ! $raw && ! empty( $args['dropdown'] ) && empty( $args['unique_id'] ) ) {
			$args['unique_id'] = 'lang_choice_' . $args['dropdown'];
		}

		// Force not to hide the language for the widget preview even if the option is checked.
		if ( $links instanceof Linguator_Admin_Links ) {
			$args['hide_if_no_translation'] = 0;
		}

		$settings = new Settings( $args );

		// Legacy dropdown switchers ignored `hide_current` unless `raw` was set.
		$settings->hide_current = ! empty( $args['hide_current'] ) && ! ( ! empty( $args['dropdown'] ) && ! $raw );

		$previous_lang = $this->maybe_set_admin_language( $args );
		$switcher      = new Switcher( $settings, $links );

		if ( $raw ) {
			$out = $this->get_raw_elements( $switcher, $settings );
		} else {
			$out = $switcher->get();
		}

		if ( false !== $previous_lang ) {
			$links->curlang = $previous_lang;
		}

		if ( ! $raw && ( ! isset( $args['echo'] ) || ! empty( $args['echo'] ) ) ) {
			echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the switcher elements.
		}

		return $out;
	}

	/**
	 * Temporarily sets the current language for switchers rendered in an admin context.
	 *
	 * @param array $args Arguments passed to {@see Linguator_Switcher::the_languages()}.
	 * @return Linguator_Language|null|false Previous current language, false if unchanged.
	 */
	protected function maybe_set_admin_language( $args ) {
		if ( empty( $args['admin_render'] ) || empty( $args['admin_current_lang'] ) ) {
			return false;
		}

		$language = $this->links->model->get_language( $args['admin_current_lang'] );

		if ( empty( $language ) ) {
			return false;
		}

		$previous             = $this->links->curlang;
		$this->links->curlang = $language;

		return $previous;
	}

	/**
	 * Returns the switcher elements as arrays, using the legacy raw format.
	 *
	 * @param Switcher $switcher Switcher instance.
	 * @param Settings $settings Switcher settings.
	 * @return array
	 */
	protected function get_raw_elements( Switcher $switcher, Settings $settings ) {
		$elements = array();

		foreach ( $switcher->get_elements() as $slug => $element ) {
			$language = $this->links->model->languages->get( $slug );

			if ( empty( $language ) ) {
				continue;
			}

			$element = get_object_vars( $element );

			$element['is_rtl']         = 'rtl' === $element['direction'];
			$element['name']           = 'codes' === $settings->show_labels ? $element['slug'] : $language->name;
			$element['flag']           = $settings->show_flags ? $element['flag'] : $language->get_display_flag_url();
			$element['current_lang']   = $element['is_current'];
			$element['no_translation'] = ! $element['has_translations'];
			$element['classes']        = $element['item_classes'];

			$elements[ $slug ] = $element;
		}

		return $elements;
	}
}
