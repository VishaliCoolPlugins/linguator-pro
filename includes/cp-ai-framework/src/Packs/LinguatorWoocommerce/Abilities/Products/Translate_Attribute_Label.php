<?php
/**
 * Translate Attribute Label ability.
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
 * Translate Attribute Label.
 *
 * @since 1.3.0
 */
final class Translate_Attribute_Label extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'translate-attribute-label';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Translate Attribute Label', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator WooCommerce: save the translation of a global product attribute name (for example "Size") as a Linguator string in the "WooCommerce attributes" group. The name is shared by every product that uses the attribute. Replaces an existing translation unless overwrite is false.', 'linguator-pro' );
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
				'attribute_slug'  => array(
					'type'        => 'string',
					'description' => __( 'Attribute slug without prefix, for example "size". Give this or taxonomy.', 'linguator-pro' ),
				),
				'taxonomy'        => array(
					'type'        => 'string',
					'description' => __( 'Attribute taxonomy, for example "pa_size". Give this or attribute_slug.', 'linguator-pro' ),
				),
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Target language slug, for example "fr".', 'linguator-pro' ),
				),
				'label'           => array(
					'type'        => 'string',
					'description' => __( 'The translated attribute name.', 'linguator-pro' ),
				),
				'overwrite'       => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Replace an existing translation. Set false to keep it and get an error instead.', 'linguator-pro' ),
				),
			),
			'required'   => array( 'target_language', 'label' ),
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
		return Attribute_Service::translate_label( self::input_array( $input ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The name is a site-wide string, so editing it needs the shop manager capability.
	 *
	 * @since 1.3.0
	 * @param mixed $input Validated input, or null.
	 * @return bool True when permitted.
	 */
	public static function check_permission( $input = array() ) {
		return static::user_can( 'manage_woocommerce', self::input_array( $input ) );
	}
}
