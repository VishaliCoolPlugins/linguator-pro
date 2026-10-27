<?php
/**
 * @package Linguator
 */

namespace LinguatorPro\Includes\Switcher;

use LinguatorPro\Includes\Services\Links\Linguator_Links;
use LinguatorPro\Includes\Switcher\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class that can display a language switcher.
 */
class Switcher {
	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var Linguator_Links
	 */
	private $links;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Instance of `Settings`.
	 * @param Linguator_Links $links    Instance of `Linguator_Links`.
	 */
	public function __construct( Settings $settings, Linguator_Links $links ) {
		$this->settings = $settings;
		$this->links    = $links;
	}

	/**
	 * Prints the switcher.
	 *
	 * @return void
	 */
	public function print(): void {
		echo $this->get(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Returns the switcher's markup.
	 *
	 * @return string
	 */
	public function get(): string {
		$switcher_class = $this->settings->get_switcher_class();

		if ( ! class_exists( $switcher_class ) ) {
			return '';
		}

		$html = ( new $switcher_class( $this->settings, $this->links ) )->get();

		if ( has_filter( 'lmat_the_languages' ) ) {
			/**
			 * Filter the whole HTML markup returned by the 'lmat_the_languages' template tag.
			 *
			 * @param string $html HTML returned/outputted by the template tag.
			 * @param array  $args Arguments passed to the template tag.
			 */
			$html = (string) apply_filters( 'lmat_the_languages', $html, $this->settings->get_legacy() );
		}

		/**
		 * Filter the whole switcher markup.
		 *
		 * @param string   $html     Switcher markup.
		 * @param Settings $settings Switcher settings.
		 * @param Switcher $switcher Switcher's instance.
		 */
		return (string) apply_filters( 'lmat_language_switcher_output', $html, $this->settings, $this );
	}

	/**
	 * Returns the switcher's raw data.
	 *
	 * @return Element\Abstract_Element[]
	 */
	public function get_elements(): array {
		$switcher_class = $this->settings->get_switcher_class();

		if ( ! class_exists( $switcher_class ) ) {
			return array();
		}

		$switcher = new $switcher_class( $this->settings, $this->links );

		return $switcher->get_elements();
	}
}
