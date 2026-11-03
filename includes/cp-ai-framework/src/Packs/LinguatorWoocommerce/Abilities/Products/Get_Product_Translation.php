<?php
/**
 * Get Product Translation ability.
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
 * Get Product Translation.
 *
 * @since 1.3.0
 */
final class Get_Product_Translation extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-product-translation';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Product Translation', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator WooCommerce: describe a product and its translations. Returns the original and translated IDs, type, SKU, prices, stock, attributes (global with terms, custom with values), variations with their translated counterparts, the differences from the original (same rules as the Site Scan check), and the translatable texts (title, description, short description, purchase note, button text, attribute names and values) with their translation state.', 'linguator-pro' );
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
				'product_id'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the original product, or of any of its translations.', 'linguator-pro' ),
				),
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Optional language slug, for example "fr", to describe only that translation.', 'linguator-pro' ),
				),
			),
			'required'   => array( 'product_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param mixed $input Validated input, or null.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( $input = array() ) {
		$input = self::input_array( $input );

		return Product_Service::describe(
			isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0,
			isset( $input['target_language'] ) ? (string) $input['target_language'] : ''
		);
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

		return $product_id > 0 && static::user_can( 'edit_post', $input, $product_id );
	}
}
