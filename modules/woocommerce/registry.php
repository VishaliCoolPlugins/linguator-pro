<?php
/**
 * Decides which product fields are shared between languages and which are translated.
 *
 * @package LinguatorPro
 */

namespace LinguatorPro\Modules\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides how product custom fields behave across languages. Products, categories and tags are not switched on
 * here: they are public, so they appear in the Linguator settings and the site owner turns them on.
 *
 * @since 2.3.0
 */
class Registry {

	/**
	 * Meta key on a translated variation that holds the ID of the variation it was copied from.
	 */
	const SOURCE_META = '_lmat_wc_source_variation';

	/**
	 * Shop pages whose ID is swapped for the page in the current language.
	 */
	const PAGES = array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' );

	/**
	 * Hooks the registry.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'lmat_copy_post_metas', array( __CLASS__, 'copy_post_metas' ), 20, 5 );
		add_filter( 'lmat_translate_post_meta', array( __CLASS__, 'translate_meta' ), 10, 5 );
		add_filter( 'wc_product_pre_has_unique_sku', array( __CLASS__, 'has_unique_sku' ), 10, 3 );

		foreach ( self::PAGES as $page ) {
			add_filter( 'woocommerce_get_' . $page . '_page_id', array( __CLASS__, 'translate_page_id' ), 10, 1 );
		}
	}

	/**
	 * Product meta keys that are copied when a translation is created, then kept in sync by the product sync.
	 *
	 * @since 2.3.0
	 *
	 * @return string[]
	 */
	public static function shared_meta_keys() {
		$keys = array(
			'_sku',
			'_regular_price',
			'_sale_price',
			'_price',
			'_sale_price_dates_from',
			'_sale_price_dates_to',
			'_manage_stock',
			'_stock',
			'_stock_status',
			'_backorders',
			'_low_stock_amount',
			'_sold_individually',
			'_weight',
			'_length',
			'_width',
			'_height',
			'_tax_status',
			'_tax_class',
			'_virtual',
			'_downloadable',
			'_downloadable_files',
			'_download_limit',
			'_download_expiry',
			'_product_image_gallery',
			'_upsell_ids',
			'_crosssell_ids',
			'_children',
			'_product_attributes',
			'_product_url',
		);

		/**
		 * Filters the product meta keys copied to a new translation and kept identical in every language.
		 *
		 * @since 2.3.0
		 *
		 * @param string[] $keys Meta keys.
		 */
		return array_values( array_diff( array_unique( (array) apply_filters( 'lmat_wc_synced_meta_keys', $keys ) ), self::translatable_meta_keys() ) );
	}

	/**
	 * Product custom fields that hold text. They are copied once to a new translation, then edited per language.
	 *
	 * @since 2.3.0
	 *
	 * @return string[]
	 */
	public static function translatable_meta_keys() {
		$keys = array( '_purchase_note', '_button_text' );

		/**
		 * Filters the product custom fields that are translated instead of synchronized.
		 *
		 * Add the meta keys of text fields added by themes or plugins. Their value is copied to a new translation
		 * as a starting point, and is never overwritten afterwards.
		 *
		 * @since 2.3.0
		 *
		 * @param string[] $keys Meta keys.
		 */
		return array_values( array_unique( (array) apply_filters( 'lmat_wc_translatable_meta_keys', $keys ) ) );
	}

	/**
	 * Adds the protected WooCommerce fields to the fields Linguator copies, and keeps translatable fields out of
	 * the synchronized ones.
	 *
	 * @since 2.3.0
	 *
	 * @param string[] $keys Meta keys.
	 * @param bool     $sync True when synchronizing, false when copying to a new translation.
	 * @param int      $from Source post ID.
	 * @param int      $to   Target post ID.
	 * @param string   $lang Target language slug.
	 * @return string[]
	 */
	public static function copy_post_metas( $keys, $sync, $from, $to, $lang ) {
		unset( $lang );
		if ( 'product' !== get_post_type( (int) $from ) && 'product' !== get_post_type( (int) $to ) ) {
			return $keys;
		}

		if ( $sync ) {
			return array_values( array_diff( (array) $keys, self::translatable_meta_keys() ) );
		}

		return array_values( array_unique( array_merge( (array) $keys, self::shared_meta_keys(), self::translatable_meta_keys() ) ) );
	}

	/**
	 * Points product relations to the products of the target language.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed  $value Meta value.
	 * @param string $key   Meta key.
	 * @param string $lang  Target language slug.
	 * @param int    $from  Source post ID.
	 * @param int    $to    Target post ID.
	 * @return mixed
	 */
	public static function translate_meta( $value, $key, $lang, $from, $to ) {
		unset( $from, $to );
		if ( ! function_exists( 'linguator_get_post' ) ) {
			return $value;
		}

		if ( '_product_image_gallery' === $key && is_string( $value ) && '' !== $value ) {
			$ids = array_map( 'absint', explode( ',', $value ) );

			return implode( ',', self::translate_ids( $ids, $lang, true ) );
		}

		if ( in_array( $key, array( '_upsell_ids', '_crosssell_ids', '_children' ), true ) && is_array( $value ) ) {
			return self::translate_ids( array_map( 'absint', $value ), $lang, false );
		}

		return $value;
	}

	/**
	 * Translates a list of post IDs.
	 *
	 * @since 2.3.0
	 *
	 * @param int[]  $ids      Post IDs.
	 * @param string $lang     Target language slug.
	 * @param bool   $fallback Keep the original ID when there is no translation, as media can be shared.
	 * @return int[]
	 */
	public static function translate_ids( array $ids, $lang, $fallback ) {
		$out = array();
		foreach ( $ids as $id ) {
			$translated = (int) linguator_get_post( $id, $lang );
			if ( $translated > 0 ) {
				$out[] = $translated;
			} elseif ( $fallback && $id > 0 ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * Lets a product and its translations share a SKU.
	 *
	 * @since 2.3.0
	 *
	 * @param bool|null $unique     Short-circuit value.
	 * @param int       $product_id Product or variation ID being saved.
	 * @param string    $sku        SKU.
	 * @return bool|null
	 */
	public static function has_unique_sku( $unique, $product_id, $sku ) {
		global $wpdb;

		if ( null !== $unique || '' === (string) $sku || ! function_exists( 'linguator_get_post_translations' ) ) {
			return $unique;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$conflicts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT posts.ID FROM {$wpdb->posts} AS posts
				INNER JOIN {$wpdb->wc_product_meta_lookup} AS lookup ON posts.ID = lookup.product_id
				WHERE posts.post_type IN ( 'product', 'product_variation' )
				AND posts.post_status != 'trash'
				AND lookup.sku = %s
				AND lookup.product_id <> %d",
				wp_slash( $sku ),
				(int) $product_id
			)
		);

		if ( empty( $conflicts ) ) {
			return true;
		}

		$group = self::translation_group( (int) $product_id );

		return ! array_diff( array_map( 'intval', $conflicts ), $group );
	}

	/**
	 * IDs of a product or variation together with its counterparts in other languages.
	 *
	 * @since 2.3.0
	 *
	 * @param int $id Product or variation ID.
	 * @return int[]
	 */
	public static function translation_group( $id ) {
		if ( 'product_variation' === get_post_type( $id ) ) {
			$root = (int) get_post_meta( $id, self::SOURCE_META, true );
			$root = $root > 0 ? $root : $id;

			$siblings = get_posts(
				array(
					'post_type'      => 'product_variation',
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'meta_key'       => self::SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $root, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);

			return array_values( array_unique( array_map( 'intval', array_merge( array( $id, $root ), $siblings ) ) ) );
		}

		return array_values( array_unique( array_map( 'intval', array_merge( array( $id ), array_values( linguator_get_post_translations( $id ) ) ) ) ) );
	}

	/**
	 * Swaps a shop page for its translation in the current language.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $page_id Page ID.
	 * @return mixed
	 */
	public static function translate_page_id( $page_id ) {
		if ( is_admin() || ! function_exists( 'linguator_get_post' ) || (int) $page_id < 1 ) {
			return $page_id;
		}

		$translated = (int) linguator_get_post( (int) $page_id );

		return $translated > 0 ? $translated : $page_id;
	}
}
