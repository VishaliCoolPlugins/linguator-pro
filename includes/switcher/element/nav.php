<?php
/**
 * @package Linguator
 */

namespace Linguator\Includes\Switcher\Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data representing an item.
 */
class Nav extends Abstract_Element {
	/**
	 * Returns the markup of a row.
	 *
	 * @return string
	 */
	public function get(): string {
		$out = sprintf(
			'<li class="%s">%s</li>',
			esc_attr( implode( ' ', $this->item_classes ) ),
			$this->get_link()
		);

		if ( ! $this->settings->preserve_spacing ) {
			return $out;
		}

		return "\t{$out}\n";
	}

	/**
	 * Returns the markup of a link.
	 *
	 * @return string
	 */
	public function get_link(): string {
		$link_atts = sprintf(
			'lang="%1$s" hreflang="%1$s" href="%2$s"',
			esc_attr( $this->locale ),
			esc_url( $this->url )
		);

		if ( ! empty( $this->link_classes ) ) {
			$link_atts .= sprintf(
				' class="%s"',
				esc_attr( implode( ' ', $this->link_classes ) )
			);
		}

		if ( $this->is_current ) {
			$link_atts .= ' aria-current="true"';
		}

		return sprintf( '<a %s>%s</a>', $link_atts, $this->get_label() );
	}

	/**
	 * Returns the markup of the label of a row.
	 *
	 * @return string
	 */
	public function get_label(): string {
		$label = '';

		if ( ! empty( $this->flag ) ) {
			$label .= sprintf(
				'<span class="lmat-switcher-flag" style="%1$s">%2$s</span>',
				esc_attr(
					sprintf(
						'display:inline-block;flex-shrink:0;width:var(--lmat-flag-width,18px);overflow:hidden;border-radius:calc(var(--lmat-flag-border-radius,0)*.5*1%%);aspect-ratio:%s',
						str_replace( ':', '/', $this->settings->flag_aspect_ratio )
					)
				),
				$this->flag
			);
		}

		if ( ! empty( $this->label ) ) {
			$style = empty( $this->flag ) ? '' : ' style="margin-inline-start:var(--lmat-flag-label-spacing,0.3em);writing-mode:horizontal-tb"';
			$label .= sprintf( '<span class="lmat-switcher-label"%1$s>%2$s</span>', $style, esc_html( $this->label ) );
		}

		return $label;
	}
}
