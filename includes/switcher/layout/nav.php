<?php
/**
 * @package Linguator
 */

namespace LinguatorPro\Includes\Switcher\Layout;

use LinguatorPro\Includes\Other\Linguator_Language;
use LinguatorPro\Includes\Switcher\Element\Nav as Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class that displays a language switcher as a list.
 */
class Nav extends Abstract_Layout {
	/**
	 * Returns the markup of the switcher.
	 *
	 * @return string
	 */
	public function get(): string {
		$cr  = $this->settings->preserve_spacing ? "\n" : '';
		$out = $cr;

		foreach ( $this->get_elements() as $element ) {
			$out .= $element->get();
		}

		if ( empty( $out ) || ! $this->settings->show_wrapper ) {
			return $out;
		}

		return $this->wrap( "<ul>{$out}</ul>" );
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
}
