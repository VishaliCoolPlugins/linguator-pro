<?php
/**
 * @package Linguator
 */

namespace LinguatorPro\Includes\Switcher\Layout;

use LinguatorPro\Includes\Other\Linguator_Language;
use LinguatorPro\Includes\Switcher\Assets;
use LinguatorPro\Includes\Switcher\Element\Nav as Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class that displays a language switcher as a dropdown.
 */
class Dropdown extends Abstract_Layout {
	/**
	 * Returns the markup of the switcher.
	 *
	 * @return string
	 */
	public function get(): string {
		$current_item = $this->get_top_level_item();

		if ( empty( $current_item ) ) {
			return '';
		}

		$cr  = $this->settings->preserve_spacing ? "\n" : '';
		$out = $cr;

		foreach ( $this->get_elements() as $element ) {
			$out .= $element->get();
		}

		if ( empty( $out ) || ! $this->settings->show_wrapper ) {
			return $out;
		}

		Assets::enqueue_frontend_scripts();

		$out = sprintf(
			"<div class=\"lmat-switcher-inner\">{$cr}%s{$cr}%s{$cr}<ul>%s</ul>{$cr}</div>",
			$current_item,
			$this->get_button(),
			$out
		);
		return $this->wrap( $out );
	}

	/**
	 * Returns an instance of `Element\Nav`.
	 *
	 * @param Linguator_Language $language Instance of `Linguator_Language`.
	 * @return Element
	 */
	protected function get_element( Linguator_Language $language ): Element {
		return new Element( $language, $this->settings, $this->links );
	}

	/**
	 * Returns the markup of the current item.
	 *
	 * @return string
	 */
	private function get_top_level_item(): string {
		if ( ! empty( $this->links->curlang ) ) {
			$curlang = $this->links->curlang;
		} else {
			$curlang = $this->links->model->languages->get( $this->links->options['default_lang'] );
		}

		if ( empty( $curlang ) ) {
			return '';
		}

		return $this->get_element( $curlang )->get_link();
	}

	/**
	 * Returns the markup of the button.
	 *
	 * @return string
	 */
	private function get_button(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" focusable="false"><path d="M1.5 4L6 8L10.5 4" stroke-width="1.5"></path></svg>';
		return sprintf(
			'<button aria-label="%1$s" class="lmat-submenu-toggle">%2$s</button>',
			/* translators: accessibility text */
			esc_attr__( 'Open/Close languages submenu', 'linguator-pro' ),
			$svg
		);
	}
}
