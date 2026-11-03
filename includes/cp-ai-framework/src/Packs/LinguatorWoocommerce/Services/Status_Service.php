<?php
/**
 * Reports the state of WooCommerce translation on a Linguator site.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Services;

use CoolPlugins\AiFramework\Packs\Linguator\Services\Content_Service;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Linguator_Guard;
use LinguatorPro\Modules\WooCommerce\Attribute_Texts;
use LinguatorPro\Modules\WooCommerce\Product_Sync;
use LinguatorPro\Modules\WooCommerce\Registry;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the WooCommerce status report.
 *
 * @since 1.3.0
 */
final class Status_Service {

	/**
	 * Most original products inspected for missing translations.
	 *
	 * @since 1.3.0
	 * @var int
	 */
	const SCAN_LIMIT = 2000;

	/**
	 * Most missing products listed per language.
	 *
	 * @since 1.3.0
	 * @var int
	 */
	const LIST_LIMIT = 20;

	/**
	 * Statuses that count as a product.
	 *
	 * @since 1.3.0
	 * @var string[]
	 */
	const STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Builds the status report.
	 *
	 * @since 1.3.0
	 * @return array<string, mixed>|WP_Error Report, or an error.
	 */
	public static function report() {
		$ready = Woo_Guard::check_ready();

		if ( null !== $ready ) {
			return $ready;
		}

		$model     = Linguator_Guard::model();
		$default   = (string) linguator_default_language();
		$languages = array();
		$targets   = array();

		foreach ( (array) $model->get_languages_list() as $language ) {
			$languages[] = array(
				'slug'    => (string) $language->slug,
				'name'    => (string) $language->name,
				'default' => (string) $language->slug === $default,
			);

			if ( (string) $language->slug !== $default ) {
				$targets[] = (string) $language->slug;
			}
		}

		$translated = Linguator_Guard::is_translated_post_type( 'product' );
		$report     = array(
			'woocommerce'             => array(
				'active'  => true,
				'version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			),
			'linguator_pro_version'   => defined( 'LINGUATOR_PRO_VERSION' ) ? (string) LINGUATOR_PRO_VERSION : '',
			'product_translated'      => $translated,
			'translated_taxonomies'   => self::translated_taxonomies(),
			'attribute_texts_enabled' => Attribute_Texts::is_enabled(),
			'attribute_texts_note'    => Attribute_Texts::is_enabled()
				? __( 'The _product_attributes custom field is enabled: the translation editor and translate-product-attributes translate custom attribute texts.', 'linguator-pro' )
				: __( 'The _product_attributes custom field is not enabled in Linguator Custom Fields settings, so the editor does not offer attribute texts. translate-product-attributes still works.', 'linguator-pro' ),
			'default_language'        => $default,
			'languages'               => $languages,
			'synced_product_props'    => array_values( array_map( 'strval', Product_Sync::product_props() ) ),
			'synced_meta_keys'        => array_values( Registry::shared_meta_keys() ),
			'translatable_meta_keys'  => array_values( Registry::translatable_meta_keys() ),
			'extension_filters'       => array(
				'lmat_wc_synced_product_props' => 'Product properties kept identical across languages.',
				'lmat_wc_synced_meta_keys'     => 'Meta keys copied to a new translation and kept identical.',
				'lmat_wc_translatable_meta_keys' => 'Text meta keys translated instead of synchronized.',
			),
		);

		if ( ! $translated ) {
			$report['message'] = Linguator_Guard::not_translated_message( 'product' );

			return $report;
		}

		$report['products_by_language'] = self::counts_by_language( $languages );
		$report['missing_translations'] = self::missing( $default, $targets, $report );
		$report['sync']                 = self::sync_state();

		return $report;
	}

	/**
	 * Taxonomies of WooCommerce that Linguator translates.
	 *
	 * @since 1.3.0
	 * @return array<string, bool> Taxonomy => translated.
	 */
	private static function translated_taxonomies() {
		$out = array(
			'product_cat' => linguator_is_translated_taxonomy( 'product_cat' ),
			'product_tag' => linguator_is_translated_taxonomy( 'product_tag' ),
		);

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$taxonomy         = wc_attribute_taxonomy_name( $attribute->attribute_name );
			$out[ $taxonomy ] = linguator_is_translated_taxonomy( $taxonomy );
		}

		return $out;
	}

	/**
	 * IDs of the products of one language.
	 *
	 * Linguator narrows queries to the current language, so the language is made current for the query.
	 *
	 * @since 1.3.0
	 * @param string $slug Language slug.
	 * @return int[] Product IDs.
	 */
	private static function product_ids( $slug ) {
		$language = Linguator_Guard::language( $slug );

		if ( is_wp_error( $language ) ) {
			return array();
		}

		return Linguator_Guard::with_language(
			$language,
			static function () use ( $slug ) {
				return Content_Service::post_ids( 'product', $slug );
			}
		);
	}

	/**
	 * Number of products per language.
	 *
	 * @since 1.3.0
	 * @param array<int, array<string, mixed>> $languages Languages.
	 * @return array<string, int> Language slug => products (all statuses but trash).
	 */
	private static function counts_by_language( array $languages ) {
		$counts = array();

		foreach ( $languages as $language ) {
			$counts[ $language['slug'] ] = count( self::product_ids( $language['slug'] ) );
		}

		return $counts;
	}

	/**
	 * Default-language products that lack a translation, per language.
	 *
	 * @since 1.3.0
	 * @param string               $default Default language slug.
	 * @param string[]             $targets Non-default language slugs.
	 * @param array<string, mixed> $report  Report so far, extended with a truncation flag.
	 * @return array<string, mixed> Per language: missing count and the first products.
	 */
	private static function missing( $default, array $targets, array &$report ) {
		$out = array();

		foreach ( $targets as $slug ) {
			$out[ $slug ] = array(
				'missing'  => 0,
				'products' => array(),
			);
		}

		if ( '' === $default || empty( $targets ) ) {
			return $out;
		}

		$ids = self::product_ids( $default );

		$report['missing_translations_scanned'] = count( $ids );
		$report['missing_translations_capped']  = count( $ids ) >= Content_Service::SCAN_LIMIT;

		foreach ( $ids as $id ) {
			$group = (array) linguator_get_post_translations( (int) $id );

			foreach ( $targets as $slug ) {
				$existing = (int) ( $group[ $slug ] ?? 0 );

				if ( $existing > 0 && get_post( $existing ) && 'trash' !== get_post_status( $existing ) ) {
					continue;
				}

				++$out[ $slug ]['missing'];

				if ( count( $out[ $slug ]['products'] ) < self::LIST_LIMIT ) {
					$out[ $slug ]['products'][] = array(
						'id'    => (int) $id,
						'title' => get_the_title( (int) $id ),
					);
				}
			}
		}

		return $out;
	}

	/**
	 * What is known about synchronization runs.
	 *
	 * The integration keeps no run log: it marks a product it has synchronized
	 * and writes failures to the PHP error log.
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Sync notes.
	 */
	private static function sync_state() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$synced = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				Product_Sync::SYNCED_META
			)
		);

		return array(
			'products_marked_synced' => $synced,
			'marker_meta_key'        => Product_Sync::SYNCED_META,
			'notes'                  => array(
				__( 'A product group is synchronized when any product in it is saved, when its language is saved, and by sync-product.', 'linguator-pro' ),
				__( 'The product marked as synchronized is the source of the next sync; a group never synchronized uses its oldest product, the original.', 'linguator-pro' ),
				__( 'The integration keeps no run log: failures go to the PHP error log. Use get-product-translation to see the differences that remain.', 'linguator-pro' ),
			),
		);
	}
}
