<?php
/**
 * @package Linguator
 */

namespace LinguatorPro\Includes\Options\Business;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use LinguatorPro\Includes\Options\Primitive\Abstract_Boolean;
use LinguatorPro\Includes\Options\Options;

/**
 * Class defining the "Translate media" boolean option.
 *
 *  
 */
class Media_Support extends Abstract_Boolean {
	/**
	 * Returns option key.
	 *
	 *  
	 *
	 * @return string
	 *
	 * @phpstan-return 'media_support'
	 */
	public static function key(): string {
		return 'media_support';
	}

	/**
	 * Returns the description used in the JSON schema.
	 *
	 *  
	 *
	 * @return string
	 */
	protected function get_description(): string {
		return sprintf(
			/* translators: %1$s and %2$s are "true/false" values. */
			__( 'Translate media: %1$s to translate, %2$s otherwise.', 'linguator-pro' ),
			'`true`',
			'`false`'
		);
	}

	/**
	 * Appends the current state of the media support option to the site health information array.
	 *
	 * @since 0.0.8
	 *
	 * @param Options $options Instance of the Options class used to retrieve configuration settings.
	 *
	 * @return array The updated site health information array including media support status.
	 */
	public function get_site_health_info( Options $options ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( $this->get() ) {
			$value = '1: ' . __( 'The media are translated', 'linguator-pro' );
		} else {
			$value = '0: ' . __( 'The media are not translated', 'linguator-pro' );
		}

		return $this->format_single_value_for_site_health_info( $value );
	}
}
