<?php
/**
 * Get WooCommerce Status ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Abilities\Environment;

use CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Services\Status_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get WooCommerce Status.
 *
 * @since 1.3.0
 */
final class Get_Woocommerce_Status extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-woocommerce-status';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get WooCommerce Status', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator WooCommerce: report whether products are translated, whether the _product_attributes custom field is enabled, how many products exist per language, which products lack a translation per language, the sync notes, and the product properties and meta keys kept identical across languages. Call this first.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return $this->empty_object_schema();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param mixed $input Validated input, or null.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( $input = array() ) {
		unset( $input );

		return Status_Service::report();
	}
}
