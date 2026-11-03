<?php
/**
 * The Linguator WooCommerce pack: translate and synchronize WooCommerce products with Linguator Pro.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorWoocommerce;

use CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Abilities\Environment\Get_Woocommerce_Status;
use CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Abilities\Products\Get_Product_Translation;
use CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Abilities\Products\Sync_Product;
use CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Abilities\Products\Translate_Attribute_Label;
use CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Abilities\Products\Translate_Product_Attributes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the pack to the framework and says when it may be offered.
 *
 * The WooCommerce integration ships inside Linguator Pro (modules/woocommerce),
 * so the pack is offered only while Linguator Pro and WooCommerce are active
 * and that integration is loaded.
 *
 * @since 1.3.0
 */
final class Pack {

	/**
	 * Option that switches the pack off when set to "no".
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const OPTION_ENABLED = 'lmat_wc_abilities_enabled';

	/**
	 * Whether the site owner has left the pack on. Defaults to on.
	 *
	 * @since 1.3.0
	 * @return bool True when enabled.
	 */
	public static function is_enabled() {
		return 'no' !== get_option( self::OPTION_ENABLED, 'yes' );
	}

	/**
	 * Whether the pack can be offered in this request.
	 *
	 * Needs the pack switched on, Linguator Pro and WooCommerce active and the
	 * WooCommerce integration of Linguator Pro loaded.
	 *
	 * @since 1.3.0
	 * @return bool True when the pack's abilities may be registered.
	 */
	public static function is_available() {
		return self::is_enabled()
			&& defined( 'LINGUATOR_PRO_VERSION' )
			&& class_exists( 'WooCommerce' )
			&& class_exists( 'LinguatorPro\\Modules\\WooCommerce\\Product_Sync' );
	}

	/**
	 * The pack definition consumed by CPAF_Framework::packs().
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Pack definition.
	 */
	public static function definition() {
		return array(
			'id'           => 'linguator-woocommerce',
			'label'        => __( 'Linguator WooCommerce', 'linguator-pro' ),
			'description'  => __( 'Inspect, translate and synchronize WooCommerce products, attributes and variations across Linguator languages.', 'linguator-pro' ),
			'namespace'    => Abilities\Abstract_Ability::ABILITY_NAMESPACE,
			'category'     => array(
				'slug'        => Abilities\Abstract_Ability::ABILITY_CATEGORY,
				'label'       => __( 'Linguator WooCommerce', 'linguator-pro' ),
				'description' => __( 'Tools that translate and synchronize WooCommerce products on a Linguator site, provided by Linguator Pro.', 'linguator-pro' ),
			),
			'is_available' => array( __CLASS__, 'is_available' ),
			'abilities'    => array(
				Get_Woocommerce_Status::class,
				Get_Product_Translation::class,
				Sync_Product::class,
				Translate_Product_Attributes::class,
				Translate_Attribute_Label::class,
			),
		);
	}
}
