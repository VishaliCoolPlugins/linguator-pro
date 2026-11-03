<?php
/**
 * Translate Product Attributes ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Abilities\Products;

use CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Services\Attribute_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translate Product Attributes.
 *
 * @since 1.3.0
 */
final class Translate_Product_Attributes extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'translate-product-attributes';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Translate Product Attributes', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator WooCommerce: store the translated attribute texts of a product on its translation, as the Linguator editor does. Custom attributes (slots a0, a1...) take a name and values (v0, v1...); global attributes (pa_size...) take a label, saved as a Linguator string only when none exists yet. The translation must exist already: create it with linguator/translate-post first. Read the slots from get-product-translation (translatable_texts.attributes).', 'linguator-pro' );
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
				'source_product_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the original product.', 'linguator-pro' ),
				),
				'target_language'   => array(
					'type'        => 'string',
					'description' => __( 'Language slug of the existing translation, for example "fr".', 'linguator-pro' ),
				),
				'attributes'        => array(
					'type'        => 'object',
					'description' => __( 'Translated texts by slot: {"a0":{"name":"Farbe","values":{"v0":"Rot","v1":"Blau"}},"pa_size":{"label":"Groesse"}}. Only the slots to change are needed.', 'linguator-pro' ),
				),
			),
			'required'   => array( 'source_product_id', 'target_language', 'attributes' ),
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
		return Attribute_Service::translate_attributes( self::input_array( $input ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param mixed $input Validated input, or null.
	 * @return bool True when permitted.
	 */
	public static function check_permission( $input = array() ) {
		$input     = self::input_array( $input );
		$source_id = isset( $input['source_product_id'] ) ? absint( $input['source_product_id'] ) : 0;

		return $source_id > 0 && static::user_can( 'edit_post', $input, $source_id ) && static::user_can( 'edit_products', $input );
	}
}
