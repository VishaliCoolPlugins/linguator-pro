<?php
/**
 * Checks the Linguator WooCommerce abilities depend on.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Services;

use CoolPlugins\AiFramework\Packs\Linguator\Services\Linguator_Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads products, resolves translation groups and rejects requests the
 * WooCommerce integration cannot serve.
 *
 * @since 1.3.0
 */
final class Woo_Guard {

	/**
	 * Error when WooCommerce, Linguator or the integration is missing.
	 *
	 * @since 1.3.0
	 * @return WP_Error|null Error, or null when everything is loaded.
	 */
	public static function check_ready() {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'lmat_wc_missing_woocommerce', __( 'WooCommerce is not active.', 'linguator-pro' ) );
		}

		if ( null === Linguator_Guard::model() || ! class_exists( 'LinguatorPro\\Modules\\WooCommerce\\Product_Sync' ) ) {
			return new WP_Error( 'lmat_wc_not_ready', __( 'Linguator or its WooCommerce integration is not loaded.', 'linguator-pro' ) );
		}

		return null;
	}

	/**
	 * Loads a product by ID, rejecting variations and other post types.
	 *
	 * @since 1.3.0
	 * @param mixed $product_id Product ID.
	 * @return \WC_Product|WP_Error Product, or an error.
	 */
	public static function product( $product_id ) {
		$ready = self::check_ready();

		if ( null !== $ready ) {
			return $ready;
		}

		$product_id = absint( $product_id );
		$post       = $product_id > 0 ? get_post( $product_id ) : null;

		if ( ! $post || 'product' !== $post->post_type ) {
			return new WP_Error( 'lmat_wc_not_found', __( 'No product has that ID. Pass the ID of a product (not a variation).', 'linguator-pro' ) );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return new WP_Error( 'lmat_wc_not_found', __( 'The product could not be loaded.', 'linguator-pro' ) );
		}

		if ( ! Linguator_Guard::is_translated_post_type( 'product' ) ) {
			return new WP_Error( 'lmat_wc_untranslated_type', Linguator_Guard::not_translated_message( 'product' ) );
		}

		return $product;
	}

	/**
	 * The translation group of a product, language slug => product ID.
	 *
	 * @since 1.3.0
	 * @param int $product_id Product ID.
	 * @return array<string, int> Group, empty when the product has no language.
	 */
	public static function group( $product_id ) {
		$group = array();

		foreach ( (array) linguator_get_post_translations( (int) $product_id ) as $slug => $id ) {
			if ( (int) $id > 0 && get_post( (int) $id ) ) {
				$group[ (string) $slug ] = (int) $id;
			}
		}

		return $group;
	}

	/**
	 * The default-language member of a group, or the product the sync copies from.
	 *
	 * @since 1.3.0
	 * @param int $product_id Product ID.
	 * @return int Original product ID.
	 */
	public static function original_id( $product_id ) {
		$group   = self::group( $product_id );
		$default = (string) linguator_default_language();

		if ( '' !== $default && isset( $group[ $default ] ) ) {
			return $group[ $default ];
		}

		return (int) \LinguatorPro\Modules\WooCommerce\Product_Sync::source_id( (int) $product_id );
	}

	/**
	 * Error when the current user may not edit the given products.
	 *
	 * @since 1.3.0
	 * @param int[] $ids Product IDs.
	 * @return WP_Error|null Error, or null when allowed.
	 */
	public static function check_can_edit( array $ids ) {
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', (int) $id ) ) {
				return new WP_Error(
					'lmat_wc_forbidden',
					sprintf(
						/* translators: %d: product ID. */
						__( 'You are not allowed to edit product %d.', 'linguator-pro' ),
						(int) $id
					)
				);
			}
		}

		return null;
	}
}
