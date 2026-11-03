<?php
/**
 * Sync Product ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Abilities\Products;

use CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Services\Product_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync Product.
 *
 * @since 1.3.0
 */
final class Sync_Product extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'sync-product';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Sync Product', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator WooCommerce: synchronize a product with its translations. Prices, stock, SKU, dimensions, images, relations, global attributes and variations are copied from the source product to every translation; text is never overwritten. dry_run (default true) only reports what would change; set dry_run to false to write.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the original product, or of any of its translations.', 'linguator-pro' ),
				),
				'dry_run'    => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'When true (default), report the differences without writing anything.', 'linguator-pro' ),
				),
			),
			'required'   => array( 'product_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return $this->write_meta();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param mixed $input Validated input, or null.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( $input = array() ) {
		$input   = self::input_array( $input );
		$dry_run = ! isset( $input['dry_run'] ) || filter_var( $input['dry_run'], FILTER_VALIDATE_BOOLEAN );

		return Product_Service::sync( isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0, $dry_run );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param mixed $input Validated input, or null.
	 * @return bool True when permitted.
	 */
	public static function check_permission( $input = array() ) {
		$input      = self::input_array( $input );
		$product_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;

		return $product_id > 0 && static::user_can( 'edit_post', $input, $product_id ) && static::user_can( 'edit_products', $input );
	}
}
