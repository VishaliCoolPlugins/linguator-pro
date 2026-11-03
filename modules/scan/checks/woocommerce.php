<?php
/**
 * WooCommerce product checks.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Modules\Scan\Abstract_Check;
use LinguatorPro\Modules\Scan\Result;
use LinguatorPro\Modules\WooCommerce\Product_Sync;
use LinguatorPro\Modules\WooCommerce\Registry;

/**
 * Compares translated products with their original, and reports three kinds of problems per language:
 *
 *   variations  Variations the translation lacks, or whose description was never translated.
 *   attributes  Custom attributes whose name and values still read like the original.
 *   fields      SKU, stock, tax, visibility, prices and other shared settings that differ from the original.
 *
 * Products with no translation at all are reported by the Content check, like any other post type.
 *
 * @since 2.3.0
 */
class Woocommerce extends Abstract_Check {

	/**
	 * @var string
	 */
	protected $group = 'Content';

	/**
	 * @var string
	 */
	protected $section = 'content';

	/**
	 * Kinds of problem, each with its own result per language.
	 */
	const KINDS = array( 'variations', 'attributes', 'fields' );

	/**
	 * Runs the check.
	 *
	 * @since 2.3.0
	 *
	 * @return Result[]
	 */
	public function run(): array {
		if ( ! function_exists( 'wc_get_product' ) || ! class_exists( Product_Sync::class ) ) {
			return array();
		}

		$default   = $this->default_lang();
		$languages = $this->active_languages();
		if ( '' === $default || count( $languages ) < 2 || ! in_array( 'product', $this->translated_post_types(), true ) ) {
			return array();
		}

		$default_obj = $this->model->get_language( $default );
		if ( ! $default_obj ) {
			return array();
		}

		$targets = array();
		foreach ( $languages as $lang ) {
			if ( $lang->slug === $default ) {
				continue;
			}
			foreach ( self::KINDS as $kind ) {
				if ( $this->wants( 'woo_' . $kind . '_' . $lang->slug ) ) {
					$targets[ $lang->slug ] = $lang;
				}
			}
		}
		if ( empty( $targets ) ) {
			return array();
		}

		$rows     = array();
		$totals   = array();
		$compared = array_fill_keys( array_keys( $targets ), 0 );
		foreach ( self::KINDS as $kind ) {
			$rows[ $kind ]   = array_fill_keys( array_keys( $targets ), array() );
			$totals[ $kind ] = array_fill_keys( array_keys( $targets ), 0 );
		}

		foreach ( $this->default_language_post_ids( 'product', $default_obj ) as $source_id ) {
			$source = wc_get_product( $source_id );
			if ( ! $source ) {
				continue;
			}

			$translations = $this->model->post->get_translations( $source_id );
			$title        = '' !== $source->get_name() ? $source->get_name() : __( '(no title)', 'linguator-pro' );

			foreach ( $targets as $slug => $lang ) {
				$target_id = (int) ( $translations[ $slug ] ?? 0 );
				$status    = $target_id > 0 ? get_post_status( $target_id ) : false;
				if ( ! $status || in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
					continue;
				}

				$target = wc_get_product( $target_id );
				if ( ! $target ) {
					continue;
				}
				++$compared[ $slug ];

				$found = array(
					'variations' => $this->wants( 'woo_variations_' . $slug ) ? $this->variation_problems( $source, $target ) : array(),
					'attributes' => $this->wants( 'woo_attributes_' . $slug ) ? $this->attribute_problems( $source, $target ) : array(),
					'fields'     => $this->wants( 'woo_fields_' . $slug ) ? $this->field_problems( $source, $target ) : array(),
				);

				foreach ( $found as $kind => $problems ) {
					if ( empty( $problems ) ) {
						continue;
					}

					++$totals[ $kind ][ $slug ];
					if ( count( $rows[ $kind ][ $slug ] ) >= self::DETAIL_LIMIT ) {
						continue;
					}

					$rows[ $kind ][ $slug ][] = $this->row( $kind, $title, $lang, $problems, (int) $source_id, $target_id );
				}
			}
		}

		return $this->results( $targets, $rows, $totals, $compared );
	}

	/**
	 * Builds the results of every language and kind.
	 *
	 * @since 2.3.0
	 *
	 * @param object[] $targets  Languages by slug.
	 * @param array    $rows     Detail rows by kind and language.
	 * @param array    $totals   Number of affected products by kind and language.
	 * @param int[]    $compared Number of compared products by language.
	 * @return Result[]
	 */
	private function results( array $targets, array $rows, array $totals, array $compared ): array {
		$copy = array(
			'variations' => array(
				/* translators: %s: language name with code */
				'label'   => __( 'Product variations in "%s"', 'linguator-pro' ),
				'pass'    => __( 'Every variation of a translated product has a translated counterpart.', 'linguator-pro' ),
				/* translators: 1: number of products, 2: language name with code */
				'warning' => _n_noop( '%1$d translated product has missing or untranslated variations in "%2$s".', '%1$d translated products have missing or untranslated variations in "%2$s".', 'linguator-pro' ),
			),
			'attributes' => array(
				/* translators: %s: language name with code */
				'label'   => __( 'Product attributes in "%s"', 'linguator-pro' ),
				'pass'    => __( 'The custom attributes of translated products are translated.', 'linguator-pro' ),
				/* translators: 1: number of products, 2: language name with code */
				'warning' => _n_noop( '%1$d translated product still has custom attributes that match the original in "%2$s".', '%1$d translated products still have custom attributes that match the original in "%2$s".', 'linguator-pro' ),
			),
			'fields'     => array(
				/* translators: %s: language name with code */
				'label'   => __( 'Product data in "%s"', 'linguator-pro' ),
				'pass'    => __( 'SKU, stock, tax, visibility and prices match the original product.', 'linguator-pro' ),
				/* translators: 1: number of products, 2: language name with code */
				'warning' => _n_noop( '%1$d translated product has SKU, stock, tax, visibility or price settings that differ from the original in "%2$s".', '%1$d translated products have SKU, stock, tax, visibility or price settings that differ from the original in "%2$s".', 'linguator-pro' ),
			),
		);

		$results = array();
		foreach ( $targets as $slug => $lang ) {
			$label = $this->lang_label( $lang );

			foreach ( self::KINDS as $kind ) {
				$id = 'woo_' . $kind . '_' . $slug;
				if ( ! $this->wants( $id ) ) {
					continue;
				}

				$title = sprintf( $copy[ $kind ]['label'], $label );
				if ( $totals[ $kind ][ $slug ] > 0 ) {
					$results[] = $this->result(
						$id,
						Result::WARNING,
						$title,
						sprintf( translate_nooped_plural( $copy[ $kind ]['warning'], $totals[ $kind ][ $slug ], 'linguator-pro' ), $totals[ $kind ][ $slug ], $label )
					)->with_details( $rows[ $kind ][ $slug ], $totals[ $kind ][ $slug ] > count( $rows[ $kind ][ $slug ] ) );
				} elseif ( $compared[ $slug ] > 0 ) {
					$results[] = $this->result( $id, Result::PASS, $title, $copy[ $kind ]['pass'] );
				}
			}
		}

		return $results;
	}

	/**
	 * Builds one detail row.
	 *
	 * @since 2.3.0
	 *
	 * @param string   $kind      Kind of problem.
	 * @param string   $title     Original product title.
	 * @param object   $lang      Language of the translation.
	 * @param string[] $problems  What is wrong.
	 * @param int      $source_id Original product ID.
	 * @param int      $target_id Translated product ID.
	 * @return array
	 */
	private function row( string $kind, string $title, $lang, array $problems, int $source_id, int $target_id ): array {
		$row = array(
			'label'          => $title,
			'message'        => implode( '; ', $problems ),
			'ignore_key'     => 'woo_' . $kind . ':' . $source_id . ':' . $lang->slug,
			'translation_id' => $target_id,
			'language'       => $lang->slug,
			'post_type'      => 'product',
		);

		if ( 'attributes' === $kind ) {
			$row['action_url']   = (string) get_edit_post_link( $target_id, 'url' );
			$row['action_label'] = __( 'Edit translation', 'linguator-pro' );
		} else {
			// The sync copies the original's variations and shared settings to the translation.
			$row['action_url']   = Product_Sync::sync_url( $source_id );
			$row['action_label'] = __( 'Sync from original', 'linguator-pro' );
		}

		return $row;
	}

	/**
	 * Variations of the original that the translation lacks, and translated variations whose description was copied.
	 *
	 * @since 2.3.0
	 *
	 * @param \WC_Product $source Original product.
	 * @param \WC_Product $target Translated product.
	 * @return string[]
	 */
	private function variation_problems( $source, $target ): array {
		if ( ! $source->is_type( 'variable' ) ) {
			return array();
		}

		$linked = $this->linked_variations( $target );
		$lacks  = array();
		$copied = array();

		foreach ( $source->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}

			$name = $this->variation_name( $variation );
			if ( empty( $linked[ $variation_id ] ) ) {
				$lacks[] = $name;
				continue;
			}

			$counterpart = wc_get_product( $linked[ $variation_id ] );
			$text        = trim( (string) $variation->get_description( 'edit' ) );
			if ( $counterpart && '' !== $text && $text === trim( (string) $counterpart->get_description( 'edit' ) ) ) {
				$copied[] = $name;
			}
		}

		$problems = array();
		if ( $lacks ) {
			$problems[] = sprintf(
				/* translators: %s: variation names such as "Size: S" */
				__( 'Missing variations: %s', 'linguator-pro' ),
				implode( ' | ', $lacks )
			);
		}
		if ( $copied ) {
			$problems[] = sprintf(
				/* translators: %s: variation names such as "Size: S" */
				__( 'Variation description not translated: %s', 'linguator-pro' ),
				implode( ' | ', $copied )
			);
		}

		return $problems;
	}

	/**
	 * Custom attributes of a translation that still match the original, by name and by every value.
	 *
	 * @since 2.3.0
	 *
	 * @param \WC_Product $source Original product.
	 * @param \WC_Product $target Translated product.
	 * @return string[]
	 */
	private function attribute_problems( $source, $target ): array {
		$own = array();
		foreach ( $target->get_attributes() as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && ! $attribute->is_taxonomy() ) {
				$own[] = $attribute;
			}
		}

		$names = array();
		$index = 0;
		foreach ( $source->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || $attribute->is_taxonomy() ) {
				continue;
			}

			$counterpart = $own[ $index ] ?? null;
			++$index;
			if ( ! $counterpart ) {
				continue;
			}

			$text = trim( $attribute->get_name() . ' ' . implode( ' ', (array) $attribute->get_options() ) );
			// Sizes, numbers and codes read the same in every language.
			if ( '' === $text || preg_match( '/^[\d\s\.\,\%\$€£₹\/\-\+xX]+$/u', $text ) ) {
				continue;
			}

			$same_name   = $attribute->get_name() === $counterpart->get_name();
			$same_values = array_values( (array) $attribute->get_options() ) === array_values( (array) $counterpart->get_options() );
			if ( $same_name && $same_values ) {
				$names[] = $attribute->get_name();
			}
		}

		if ( empty( $names ) ) {
			return array();
		}

		return array(
			sprintf(
				/* translators: %s: attribute names */
				__( 'Attributes still match the original: %s', 'linguator-pro' ),
				implode( ', ', $names )
			),
		);
	}

	/**
	 * Settings of the translation that differ from the original, for the product and for each linked variation.
	 *
	 * @since 2.3.0
	 *
	 * @param \WC_Product $source Original product.
	 * @param \WC_Product $target Translated product.
	 * @return string[]
	 */
	private function field_problems( $source, $target ): array {
		$props = Product_Sync::product_props();
		if ( $source->is_type( 'variable' ) ) {
			// WooCommerce derives the stock status of a variable product from its variations, which are compared below.
			$props = array_diff( $props, array( 'stock_status' ) );
		}
		$problems = $this->differences( $source, $target, $props );

		if ( $source->is_type( 'variable' ) ) {
			// A translated variation keeps its own visibility, featured flag and sold-individually setting.
			$variation_props = array_diff( $props, array( 'featured', 'catalog_visibility', 'sold_individually' ) );
			$linked          = $this->linked_variations( $target );

			foreach ( $source->get_children() as $variation_id ) {
				if ( empty( $linked[ $variation_id ] ) ) {
					continue;
				}
				$variation   = wc_get_product( $variation_id );
				$counterpart = wc_get_product( $linked[ $variation_id ] );
				if ( ! $variation || ! $counterpart ) {
					continue;
				}

				$diff = $this->differences( $variation, $counterpart, $variation_props );
				if ( $diff ) {
					$problems[] = sprintf(
						/* translators: 1: variation name such as "Size: S", 2: the differences */
						__( 'Variation %1$s: %2$s', 'linguator-pro' ),
						$this->variation_name( $variation ),
						implode( ', ', $diff )
					);
				}
			}
		}

		return $problems;
	}

	/**
	 * Compares the given product properties and describes each one that differs.
	 *
	 * @since 2.3.0
	 *
	 * @param \WC_Product $source Original product or variation.
	 * @param \WC_Product $target Translated product or variation.
	 * @param string[]    $props  Property names.
	 * @return string[]
	 */
	private function differences( $source, $target, array $props ): array {
		$out = array();

		foreach ( $props as $prop ) {
			$getter = 'get_' . $prop;
			if ( ! method_exists( $source, $getter ) || ! method_exists( $target, $getter ) ) {
				continue;
			}

			$original = $source->$getter( 'edit' );
			$current  = $target->$getter( 'edit' );
			if ( $this->normalize( $original ) === $this->normalize( $current ) ) {
				continue;
			}

			$out[] = sprintf(
				/* translators: 1: setting name such as "Price", 2: value in the original, 3: value in the translation */
				__( '%1$s: %2$s → %3$s', 'linguator-pro' ),
				$this->prop_label( $prop ),
				$this->show( $original ),
				$this->show( $current )
			);
		}

		return $out;
	}

	/**
	 * Variations of a translated product, by the ID of the original variation they were copied from.
	 *
	 * @since 2.3.0
	 *
	 * @param \WC_Product $target Translated product.
	 * @return array<int,int>
	 */
	private function linked_variations( $target ): array {
		$linked = array();
		if ( ! $target->is_type( 'variable' ) ) {
			return $linked;
		}

		foreach ( $target->get_children() as $variation_id ) {
			$origin = (int) get_post_meta( $variation_id, Registry::SOURCE_META, true );
			if ( $origin > 0 ) {
				$linked[ $origin ] = (int) $variation_id;
			}
		}

		return $linked;
	}

	/**
	 * Short name of a variation, such as "Size: S, Material: Cotton".
	 *
	 * @since 2.3.0
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @return string
	 */
	private function variation_name( $variation ): string {
		$name = wp_strip_all_tags( (string) wc_get_formatted_variation( $variation, true, true ) );

		/* translators: %d: variation ID */
		return '' !== $name ? $name : sprintf( __( 'Variation #%d', 'linguator-pro' ), $variation->get_id() );
	}

	/**
	 * Comparable form of a property value.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $value Value from a product getter.
	 * @return string
	 */
	private function normalize( $value ): string {
		if ( $value instanceof \WC_DateTime ) {
			return (string) $value->getTimestamp();
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_array( $value ) ) {
			return (string) wp_json_encode(
				array_map(
					static function ( $item ) {
						return is_object( $item ) && method_exists( $item, 'get_data' ) ? $item->get_data() : $item;
					},
					$value
				)
			);
		}

		return null === $value ? '' : trim( (string) $value );
	}

	/**
	 * Readable form of a property value.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $value Value from a product getter.
	 * @return string
	 */
	private function show( $value ): string {
		if ( $value instanceof \WC_DateTime ) {
			return $value->date_i18n( get_option( 'date_format' ) );
		}
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'linguator-pro' ) : __( 'No', 'linguator-pro' );
		}
		if ( is_array( $value ) ) {
			/* translators: %d: number of items */
			return sprintf( _n( '%d item', '%d items', count( $value ), 'linguator-pro' ), count( $value ) );
		}

		$text = trim( (string) $value );

		return '' !== $text ? $text : __( 'empty', 'linguator-pro' );
	}

	/**
	 * Name of a product property as a site owner knows it.
	 *
	 * @since 2.3.0
	 *
	 * @param string $prop Property name, such as `regular_price`.
	 * @return string
	 */
	private function prop_label( string $prop ): string {
		$labels = array(
			'sku'                => __( 'SKU', 'linguator-pro' ),
			'regular_price'      => __( 'Regular price', 'linguator-pro' ),
			'sale_price'         => __( 'Sale price', 'linguator-pro' ),
			'date_on_sale_from'  => __( 'Sale starts', 'linguator-pro' ),
			'date_on_sale_to'    => __( 'Sale ends', 'linguator-pro' ),
			'manage_stock'       => __( 'Manage stock', 'linguator-pro' ),
			'stock_quantity'     => __( 'Stock', 'linguator-pro' ),
			'stock_status'       => __( 'Stock status', 'linguator-pro' ),
			'backorders'         => __( 'Backorders', 'linguator-pro' ),
			'low_stock_amount'   => __( 'Low stock threshold', 'linguator-pro' ),
			'sold_individually'  => __( 'Sold individually', 'linguator-pro' ),
			'tax_status'         => __( 'Tax status', 'linguator-pro' ),
			'tax_class'          => __( 'Tax class', 'linguator-pro' ),
			'catalog_visibility' => __( 'Visibility', 'linguator-pro' ),
			'featured'           => __( 'Featured', 'linguator-pro' ),
			'shipping_class_id'  => __( 'Shipping class', 'linguator-pro' ),
			'virtual'            => __( 'Virtual', 'linguator-pro' ),
			'downloadable'       => __( 'Downloadable', 'linguator-pro' ),
			'downloads'          => __( 'Downloadable files', 'linguator-pro' ),
			'download_limit'     => __( 'Download limit', 'linguator-pro' ),
			'download_expiry'    => __( 'Download expiry', 'linguator-pro' ),
			'weight'             => __( 'Weight', 'linguator-pro' ),
			'length'             => __( 'Length', 'linguator-pro' ),
			'width'              => __( 'Width', 'linguator-pro' ),
			'height'             => __( 'Height', 'linguator-pro' ),
			'menu_order'         => __( 'Menu order', 'linguator-pro' ),
		);

		return $labels[ $prop ] ?? ucfirst( str_replace( '_', ' ', $prop ) );
	}
}
