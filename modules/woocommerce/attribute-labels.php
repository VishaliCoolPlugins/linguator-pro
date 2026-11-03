<?php
/**
 * Makes the names of global product attributes translatable.
 *
 * @package LinguatorPro
 */

namespace LinguatorPro\Modules\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers each global attribute name (for example "Color") as a Linguator string, and shows its translation
 * wherever WooCommerce prints the name: product attribute tables, variation dropdowns, cart and filters.
 *
 * The values of a global attribute are terms shared by every language.
 *
 * @since 2.3.0
 */
class Attribute_Labels {

	/**
	 * String group shown in the Linguator strings translation screen.
	 */
	const CONTEXT = 'WooCommerce attributes';

	/**
	 * String group for the custom attributes added on a single product.
	 */
	const PRODUCT_CONTEXT = 'WooCommerce product attributes';

	/**
	 * Option that remembers the custom attribute texts seen on products, so they can be registered on every
	 * admin request.
	 */
	const PRODUCT_STRINGS_OPTION = 'lmat_wc_product_attribute_strings';

	/**
	 * Hooks the attribute labels.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_strings' ), 20, 0 );
		add_filter( 'woocommerce_attribute_label', array( __CLASS__, 'translate_label' ), 10, 3 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'register_product_strings' ), 10, 2 );
	}

	/**
	 * Registers the name and values of a product's custom attributes (the ones added on the product itself) as
	 * strings, so they can be translated on the Linguator strings screen. They are applied when the product is
	 * translated.
	 *
	 * @since 2.3.0
	 *
	 * @param int              $product_id Product ID.
	 * @param \WC_Product|null $product    Product, when WooCommerce passes it.
	 * @return void
	 */
	public static function register_product_strings( $product_id, $product = null ) {
		$strings = '\Linguator\Admin\Controllers\Linguator_Admin_Strings';
		if ( ! is_admin() || ! class_exists( $strings ) ) {
			return;
		}

		$product = $product instanceof \WC_Product ? $product : wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return;
		}

		$stored  = (array) get_option( self::PRODUCT_STRINGS_OPTION, array() );
		$changed = false;

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || $attribute->is_taxonomy() ) {
				continue;
			}

			$texts = array( 'Attribute name' => array( (string) $attribute->get_name() ), 'Attribute value' => (array) $attribute->get_options() );
			foreach ( $texts as $label => $values ) {
				foreach ( $values as $value ) {
					$value = trim( (string) $value );
					$key   = md5( $value );
					if ( '' === $value || isset( $stored[ $key ] ) ) {
						continue;
					}
					$stored[ $key ] = array( $label, $value );
					$changed        = true;
				}
			}
		}

		if ( $changed ) {
			update_option( self::PRODUCT_STRINGS_OPTION, $stored, false );
		}
	}

	/**
	 * Translation of a text in a given language, or the text itself when there is none.
	 *
	 * @since 2.3.0
	 *
	 * @param string $text Text in the original language.
	 * @param string $lang Target language slug.
	 * @return string
	 */
	public static function translate_text( $text, $lang ) {
		if ( '' === trim( $text ) || ! function_exists( 'linguator_translate_string' ) ) {
			return $text;
		}

		$translated = linguator_translate_string( $text, $lang );

		return is_string( $translated ) && '' !== $translated ? $translated : $text;
	}

	/**
	 * Registers the name of every global attribute for translation.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function register_strings() {
		// The free plugin's linguator_register_string() tests an unqualified class name and never registers, so the
		// strings class is called directly.
		$strings = '\Linguator\Admin\Controllers\Linguator_Admin_Strings';
		if ( ! is_admin() || ! class_exists( $strings ) || ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return;
		}

		foreach ( (array) get_option( self::PRODUCT_STRINGS_OPTION, array() ) as $entry ) {
			if ( is_array( $entry ) && 2 === count( $entry ) ) {
				$strings::register_string( (string) $entry[0], (string) $entry[1], self::PRODUCT_CONTEXT );
			}
		}

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( '' === trim( (string) $attribute->attribute_label ) ) {
				continue;
			}
			$strings::register_string( 'Attribute: ' . $attribute->attribute_name, $attribute->attribute_label, self::CONTEXT );
		}
	}

	/**
	 * Shows the translated name of a global attribute on the front end.
	 *
	 * @since 2.3.0
	 *
	 * @param string $label   Attribute name.
	 * @param string $name    Attribute taxonomy or key, such as `pa_color`.
	 * @param mixed  $product Product the attribute belongs to, when known.
	 * @return string
	 */
	public static function translate_label( $label, $name, $product = '' ) {
		unset( $product );

		if ( is_admin() || ! function_exists( 'linguator__' ) || 0 !== strpos( (string) $name, 'pa_' ) || '' === (string) $label ) {
			return $label;
		}

		$translated = linguator__( $label );

		return is_string( $translated ) && '' !== $translated ? $translated : $label;
	}
}
