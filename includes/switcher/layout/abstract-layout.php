<?php
/**
 * @package Linguator
 */

namespace Linguator\Includes\Switcher\Layout;

use Linguator\Includes\Services\Links\Linguator_Links;
use Linguator\Includes\Other\Linguator_Language;
use Linguator\Includes\Switcher\Settings\Settings;
use Linguator\Includes\Switcher\Element\Abstract_Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract class to display a certain type of language switcher.
 */
abstract class Abstract_Layout {
	/**
	 * @var Settings
	 */
	protected $settings;

	/**
	 * @var Linguator_Links
	 */
	protected $links;

	/**
	 * @var Abstract_Element[]
	 */
	protected $elements = array();

	/**
	 * Constructor.
	 *
	 * @param Settings  $settings Instance of `Settings`.
	 * @param Linguator_Links $links    Instance of `Linguator_Links`.
	 */
	public function __construct( Settings $settings, Linguator_Links $links ) {
		$this->settings = $settings;
		$this->links    = $links;

		foreach ( $this->links->model->languages->get_list() as $language ) {
			$this->elements[ $language->slug ] = $this->get_element( $language );
		}
	}

	/**
	 * Returns the markup of the switcher.
	 *
	 * @return string
	 */
	abstract public function get(): string;

	/**
	 * Returns the switcher's data.
	 *
	 * @return Abstract_Element[]
	 *
	 * @phpstan-return array<non-empty-string, Abstract_Element>
	 */
	public function get_elements(): array {
		$out      = array();
		$is_first = true;

		foreach ( $this->elements as $element ) {
			if ( $this->settings->hide_current && $element->is_current ) {
				// Hide current item.
				continue;
			}

			if ( $this->settings->hide_if_empty && $element->is_empty ) {
				// Hide empty item.
				continue;
			}

			if ( $this->settings->hide_if_no_translation && ! $element->has_translations ) {
				// Hide item with no translations.
				continue;
			}

			if ( empty( $element->url ) ) {
				// Failed to get a URL.
				continue;
			}

			if ( $is_first && ! in_array( 'lang-item-first', $element->item_classes, true ) ) {
				// This is the first element of the list, for example the first `<li>` in the `<ul>` tag.
				$is_first = false;
				$element->item_classes[] = 'lang-item-first';
			}

			$out[ $element->slug ] = $element;
		}

		return $out;
	}

	/**
	 * Returns an instance of `Abstract_Element`.
	 *
	 * @param Linguator_Language $language Instance of `Linguator_Language`.
	 * @return Abstract_Element
	 */
	abstract protected function get_element( Linguator_Language $language ): Abstract_Element;

	/**
	 * Returns the list of HTML classes to add to the wrapper tag.
	 *
	 * @return string[]
	 */
	protected function get_wrapper_classes(): array {
		$classes = array_merge(
			$this->settings->wrapper_classes,
			array(
				'lmat-switcher',
				"lmat-layout-{$this->settings->layout}",
				"lmat-alignment-{$this->settings->alignment}",
			)
		);

		if ( $this->settings->show_flags ) {
			$classes[] = 'lmat-aspect-ratio-' . str_replace( ':', '', $this->settings->flag_aspect_ratio );
		}

		return $classes;
	}

	/**
	 * Wraps the given content into a `<nav>` or `<div>` tag.
	 *
	 * @param string $content The content.
	 * @return string
	 */
	protected function wrap( string $content ): string {
		$format = current_theme_supports( 'html5', 'navigation-widgets' ) ? 'html5' : 'xhtml';
		/** This filter is documented in wp-includes/widgets/class-wp-nav-menu-widget.php */
		$format = apply_filters( 'navigation_widgets_format', $format );

		$cr         = $this->settings->preserve_spacing ? "\n" : '';
		$attributes = sprintf(
			'id="%1$s" class="%2$s" aria-label="%3$s"',
			esc_attr( $this->settings->unique_id ),
			esc_attr( implode( ' ', $this->get_wrapper_classes() ) ),
			esc_attr( __( 'Choose a language', 'linguator-pro' ) )
		);

		if ( 'html5' === $format ) {
			return "{$cr}<nav {$attributes}>{$cr}{$content}{$cr}</nav>{$cr}";
		} else {
			return "{$cr}<div role=\"navigation\" {$attributes}>{$cr}{$content}{$cr}</div>{$cr}";
		}
	}
}
