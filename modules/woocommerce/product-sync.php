<?php
/**
 * Keeps the shared data of translated products identical, and translates attributes and variations.
 *
 * @package LinguatorPro
 */

namespace LinguatorPro\Modules\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pushes price, stock, dimensions, images, relations, attributes and variations from a saved product to its
 * translations. Text (title, descriptions, purchase note, custom attribute names) stays per language.
 *
 * @since 2.3.0
 */
class Product_Sync {

	/**
	 * Marks a product that has already been synchronized once.
	 */
	const SYNCED_META = '_lmat_wc_synced';

	/**
	 * Whether a synchronization is running, to stop the saves it causes from starting another.
	 *
	 * @var bool
	 */
	private static $running = false;

	/**
	 * Hooks the sync.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_update_product', array( __CLASS__, 'sync_product' ), 20, 1 );
		add_action( 'woocommerce_ajax_save_product_variations', array( __CLASS__, 'sync_product' ), 20, 1 );
		add_action( 'lmat_save_post', array( __CLASS__, 'sync_after_language_save' ), 20, 1 );
		add_action( 'admin_post_lmat_wc_sync_product', array( __CLASS__, 'handle_sync_request' ), 10, 0 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'sync_stock' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'sync_stock' ), 10, 1 );
	}

	/**
	 * Runs the sync once Linguator has saved the language and translation group of a product.
	 *
	 * @since 2.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function sync_after_language_save( $post_id ) {
		if ( 'product' === get_post_type( (int) $post_id ) ) {
			self::sync_product( (int) $post_id );
		}
	}

	/**
	 * URL that synchronizes a product with its translations, used by the site scan.
	 *
	 * @since 2.3.0
	 *
	 * @param int $product_id Original product ID.
	 * @return string
	 */
	public static function sync_url( $product_id ) {
		$product_id = (int) $product_id;

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'lmat_wc_sync_product',
					'product' => $product_id,
				),
				admin_url( 'admin-post.php' )
			),
			'lmat_wc_sync_product_' . $product_id
		);
	}

	/**
	 * Synchronizes one product on request, then returns to the page the request came from.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function handle_sync_request() {
		$product_id = isset( $_GET['product'] ) ? absint( wp_unslash( $_GET['product'] ) ) : 0;

		check_admin_referer( 'lmat_wc_sync_product_' . $product_id );

		if ( $product_id < 1 || ! current_user_can( 'edit_post', $product_id ) ) {
			wp_die( esc_html__( 'You are not allowed to update this product.', 'linguator-pro' ), 403 );
		}

		self::sync_product( $product_id );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'edit.php?post_type=product' ) );
		exit;
	}

	/**
	 * Synchronizes a product with its translations.
	 *
	 * The saved product is the source, unless it has never been synchronized and one of its translations has.
	 * Then that translation is the source, so a blank new translation cannot overwrite the original.
	 *
	 * @since 2.3.0
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function sync_product( $product_id ) {
		$product_id = (int) $product_id;
		if ( self::$running || $product_id < 1 || 'product' !== get_post_type( $product_id ) || ! function_exists( 'linguator_get_post_translations' ) ) {
			return;
		}

		$translations = array_map( 'intval', linguator_get_post_translations( $product_id ) );
		if ( count( $translations ) < 2 ) {
			return;
		}

		$source_id = self::source_id( $product_id );

		self::$running = true;
		try {
			foreach ( $translations as $lang => $target_id ) {
				if ( $target_id !== $source_id ) {
					self::sync_pair( $source_id, $target_id, (string) $lang );
				}
			}
			update_post_meta( $source_id, self::SYNCED_META, 1 );
		} catch ( \Exception $e ) {
			error_log( 'Linguator Pro WooCommerce sync failed for product ' . $source_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		self::$running = false;
	}

	/**
	 * Copies the shared data of one product to its translation in one language.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $source_id Source product ID.
	 * @param int    $target_id Target product ID.
	 * @param string $lang      Target language slug.
	 * @return void
	 */
	private static function sync_pair( $source_id, $target_id, $lang ) {
		$source = wc_get_product( $source_id );
		$target = wc_get_product( $target_id );
		if ( ! $source || ! $target ) {
			return;
		}

		// A type change replaces the product class, so it is applied first and the product is loaded again.
		if ( $source->get_type() !== $target->get_type() ) {
			wp_set_object_terms( $target_id, $source->get_type(), 'product_type' );
			$class = \WC_Product_Factory::get_product_classname( $target_id, $source->get_type() );
			if ( ! class_exists( $class ) ) {
				return;
			}
			$target = new $class( $target_id );
		}

		self::copy_props( $source, $target, self::product_props() );

		if ( method_exists( $source, 'get_product_url' ) && method_exists( $target, 'set_product_url' ) ) {
			$target->set_product_url( $source->get_product_url() );
		}

		$target->set_image_id( self::translate_image( (int) $source->get_image_id(), $lang ) );
		$target->set_gallery_image_ids( Registry::translate_ids( array_map( 'absint', $source->get_gallery_image_ids() ), $lang, true ) );
		$target->set_upsell_ids( Registry::translate_ids( array_map( 'absint', $source->get_upsell_ids() ), $lang, false ) );
		$target->set_cross_sell_ids( Registry::translate_ids( array_map( 'absint', $source->get_cross_sell_ids() ), $lang, false ) );
		if ( $source->is_type( 'grouped' ) && method_exists( $target, 'set_children' ) ) {
			$target->set_children( Registry::translate_ids( array_map( 'absint', $source->get_children() ), $lang, false ) );
		}

		$attribute_map = self::sync_attributes( $source, $target, $lang );

		try {
			$target->save();
		} catch ( \Exception $e ) {
			error_log( 'Linguator Pro WooCommerce could not save product ' . $target_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		if ( $source->is_type( 'variable' ) ) {
			self::sync_variations( $source, wc_get_product( $target_id ), $lang, $attribute_map );
		}
	}

	/**
	 * Product settings that are the same in every language.
	 *
	 * @since 2.3.0
	 *
	 * @return string[]
	 */
	public static function product_props() {
		$props = array(
			'sku',
			'regular_price',
			'sale_price',
			'date_on_sale_from',
			'date_on_sale_to',
			'manage_stock',
			'stock_quantity',
			'stock_status',
			'backorders',
			'low_stock_amount',
			'sold_individually',
			'weight',
			'length',
			'width',
			'height',
			'tax_status',
			'tax_class',
			'virtual',
			'downloadable',
			'downloads',
			'download_limit',
			'download_expiry',
			'shipping_class_id',
			'featured',
			'catalog_visibility',
			'menu_order',
		);

		/**
		 * Filters the product settings (WooCommerce product property names) kept identical across languages.
		 *
		 * @since 2.3.0
		 *
		 * @param string[] $props Property names, such as `regular_price`.
		 */
		return (array) apply_filters( 'lmat_wc_synced_product_props', $props );
	}

	/**
	 * Copies properties through the WooCommerce getters and setters.
	 *
	 * @since 2.3.0
	 *
	 * @param \WC_Product $source Source product.
	 * @param \WC_Product $target Target product.
	 * @param string[]    $props  Property names.
	 * @return void
	 */
	private static function copy_props( $source, $target, array $props ) {
		foreach ( $props as $prop ) {
			$getter = 'get_' . $prop;
			$setter = 'set_' . $prop;
			if ( ! method_exists( $source, $getter ) || ! method_exists( $target, $setter ) ) {
				continue;
			}
			try {
				$target->$setter( $source->$getter( 'edit' ) );
			} catch ( \Exception $e ) {
				continue; // For example a SKU another product already uses.
			}
		}
	}

	/**
	 * Image of the target language, or the same image when it has no translation.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $image_id Attachment ID.
	 * @param string $lang     Target language slug.
	 * @return int
	 */
	private static function translate_image( $image_id, $lang ) {
		if ( $image_id < 1 ) {
			return 0;
		}
		$translated = (int) linguator_get_post( $image_id, $lang );

		return $translated > 0 ? $translated : $image_id;
	}

	/**
	 * Gives the translation the same attributes.
	 *
	 * Global attributes share their terms between languages. Custom attributes hold free text, so wording from the
	 * translation editor or the strings screen is applied, and a translator's own wording is kept once it exists.
	 *
	 * @since 2.3.0
	 *
	 * @param \WC_Product $source Source product.
	 * @param \WC_Product $target Target product.
	 * @param string      $lang   Target language slug.
	 * @return array<string,array{slug:string,values:array<string,string>}> Source attribute slug => target attribute slug and value map, for custom attributes.
	 */
	private static function sync_attributes( $source, $target, $lang ) {
		$existing = array();
		foreach ( $target->get_attributes() as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && ! $attribute->is_taxonomy() ) {
				$existing[] = $attribute;
			}
		}

		$editor = (array) get_post_meta( $target->get_id(), Attribute_Texts::META, true );
		$new    = array();
		$map    = array();
		$idx    = 0;

		foreach ( $source->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}

			$copy = new \WC_Product_Attribute();
			$copy->set_id( $attribute->get_id() );
			$copy->set_position( $attribute->get_position() );
			$copy->set_visible( $attribute->get_visible() );
			$copy->set_variation( $attribute->get_variation() );

			if ( $attribute->is_taxonomy() ) {
				// The values of a global attribute are terms shared by every language.
				$copy->set_name( $attribute->get_name() );
				$copy->set_options( array_map( 'absint', $attribute->get_options() ) );
				$new[] = $copy;
				continue;
			}

			$own      = isset( $existing[ $idx ] ) ? $existing[ $idx ] : null;
			$idx_slot = $idx;
			++$idx;
			$source_options = array_values( $attribute->get_options() );
			$own_options    = $own ? array_values( $own->get_options() ) : array();
			$keep_own       = $own && count( $own_options ) === count( $source_options );

			// Wording typed in the translation editor wins, then a translation saved on the strings screen, then the
			// translator's own wording, then the original.
			$slot       = 'a' . $idx_slot;
			$typed      = isset( $editor[ $slot ] ) && is_array( $editor[ $slot ] ) ? $editor[ $slot ] : array();
			$name       = (string) $attribute->get_name();
			$translated = Attribute_Labels::translate_text( $name, $lang );
			if ( $translated === $name && $own ) {
				$translated = $own->get_name();
			}
			$typed_name = isset( $typed['name'] ) ? trim( (string) $typed['name'] ) : '';
			if ( '' !== $typed_name && $typed_name !== $name ) {
				$translated = $typed_name;
			}
			$copy->set_name( $translated );

			$options = array();
			foreach ( $source_options as $i => $option ) {
				$translated  = Attribute_Labels::translate_text( (string) $option, $lang );
				$typed_value = isset( $typed['values']['v' . $i] ) ? trim( (string) $typed['values']['v' . $i] ) : '';
				if ( $translated === (string) $option && $keep_own ) {
					$translated = $own_options[ $i ];
				}
				if ( '' !== $typed_value && $typed_value !== (string) $option ) {
					$translated = $typed_value;
				}
				$options[] = $translated;
			}
			$copy->set_options( $options );

			$values = array();
			foreach ( $source_options as $i => $option ) {
				$target_options          = array_values( $copy->get_options() );
				$values[ (string) $option ] = isset( $target_options[ $i ] ) ? (string) $target_options[ $i ] : (string) $option;
			}
			$map[ sanitize_title( $attribute->get_name() ) ] = array(
				'slug'   => sanitize_title( $copy->get_name() ),
				'values' => $values,
			);
			$new[] = $copy;
		}

		$target->set_attributes( $new );

		return $map;
	}

	/**
	 * ID of the product the other languages are copied from.
	 *
	 * That is the product that was synchronized before. When none was, it is the oldest product of the group, which
	 * is the original, so a new translation can never overwrite it.
	 *
	 * @since 2.3.0
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	public static function source_id( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! function_exists( 'linguator_get_post_translations' ) ) {
			return $product_id;
		}

		$group = array_map( 'intval', array_values( linguator_get_post_translations( $product_id ) ) );
		if ( count( $group ) < 2 ) {
			return $product_id;
		}

		foreach ( $group as $id ) {
			if ( get_post_meta( $id, self::SYNCED_META, true ) ) {
				return $id;
			}
		}

		return min( $group );
	}

	/**
	 * Creates, updates and removes the variations of a translation to match the source.
	 *
	 * @since 2.3.0
	 *
	 * @param \WC_Product      $source        Source variable product.
	 * @param \WC_Product|bool $target        Target variable product.
	 * @param string           $lang          Target language slug.
	 * @param array            $attribute_map Custom attribute map from sync_attributes().
	 * @return void
	 */
	private static function sync_variations( $source, $target, $lang, array $attribute_map ) {
		if ( ! $target || ! $target->is_type( 'variable' ) ) {
			return;
		}

		$existing = array();
		foreach ( $target->get_children() as $variation_id ) {
			$origin = (int) get_post_meta( $variation_id, Registry::SOURCE_META, true );
			if ( $origin > 0 ) {
				$existing[ $origin ] = (int) $variation_id;
			}
		}

		$props = array_diff(
			self::product_props(),
			array( 'sku', 'featured', 'catalog_visibility', 'sold_individually' )
		);

		foreach ( $source->get_children() as $source_variation_id ) {
			$source_variation = wc_get_product( $source_variation_id );
			if ( ! $source_variation instanceof \WC_Product_Variation ) {
				continue;
			}

			$variation = isset( $existing[ $source_variation_id ] ) ? wc_get_product( $existing[ $source_variation_id ] ) : null;
			if ( ! $variation instanceof \WC_Product_Variation ) {
				$variation = new \WC_Product_Variation();
				$variation->set_parent_id( $target->get_id() );
				// The description is text, so it is copied once and then translated.
				$variation->set_description( $source_variation->get_description( 'edit' ) );
			}

			$variation->set_status( $source_variation->get_status( 'edit' ) );
			self::copy_props( $source_variation, $variation, $props );
			$variation->set_image_id( self::translate_image( (int) $source_variation->get_image_id(), $lang ) );
			$variation->set_attributes( self::translate_variation_attributes( $source_variation->get_attributes( 'edit' ), $lang, $attribute_map ) );

			try {
				$variation->save();
			} catch ( \Exception $e ) {
				continue;
			}

			// The link has to exist before the SKU is set, so the shared SKU is recognized as a translation.
			update_post_meta( $variation->get_id(), Registry::SOURCE_META, $source_variation_id );
			unset( $existing[ $source_variation_id ] );

			if ( $source_variation->get_sku( 'edit' ) !== $variation->get_sku( 'edit' ) ) {
				try {
					$variation->set_sku( $source_variation->get_sku( 'edit' ) );
					$variation->save();
				} catch ( \Exception $e ) {
					continue;
				}
			}
		}

		// Variations whose source was deleted.
		foreach ( $existing as $orphan_id ) {
			$orphan = wc_get_product( $orphan_id );
			if ( $orphan ) {
				$orphan->delete( true );
			}
		}

		\WC_Product_Variable::sync( $target->get_id() );
	}

	/**
	 * Maps the attribute values of a variation to the target language.
	 *
	 * @since 2.3.0
	 *
	 * @param array  $attributes    Attribute slug => value.
	 * @param string $lang          Target language slug.
	 * @param array  $attribute_map Custom attribute map.
	 * @return array
	 */
	private static function translate_variation_attributes( array $attributes, $lang, array $attribute_map ) {
		$out = array();

		foreach ( $attributes as $slug => $value ) {
			$value = (string) $value;

			if ( taxonomy_exists( $slug ) ) {
				// Global attribute: the value is a term slug shared by every language.
				$out[ $slug ] = $value;
				continue;
			}

			if ( isset( $attribute_map[ $slug ] ) ) {
				$mapped = $attribute_map[ $slug ];
				$out[ $mapped['slug'] ] = isset( $mapped['values'][ $value ] ) ? $mapped['values'][ $value ] : $value;
				continue;
			}

			$out[ $slug ] = $value;
		}

		return $out;
	}

	/**
	 * Applies a stock change, such as a sale, to the same product in the other languages.
	 *
	 * @since 2.3.0
	 *
	 * @param \WC_Product $product Product or variation whose stock changed.
	 * @return void
	 */
	public static function sync_stock( $product ) {
		if ( self::$running || ! $product instanceof \WC_Product || ! $product->get_manage_stock() || ! function_exists( 'linguator_get_post_translations' ) ) {
			return;
		}

		$id    = $product->get_id();
		$group = array_diff( Registry::translation_group( $id ), array( $id ) );
		if ( empty( $group ) ) {
			return;
		}

		self::$running = true;
		foreach ( $group as $other_id ) {
			$other = wc_get_product( $other_id );
			if ( $other && $other->get_manage_stock() ) {
				wc_update_product_stock( $other, $product->get_stock_quantity(), 'set', true );
			}
		}
		self::$running = false;
	}
}
