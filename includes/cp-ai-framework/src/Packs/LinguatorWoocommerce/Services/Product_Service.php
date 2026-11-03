<?php
/**
 * Reads products with their translations and synchronizes them.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Services;

use CoolPlugins\AiFramework\Packs\Linguator\Services\Linguator_Guard;
use LinguatorPro\Modules\WooCommerce\Attribute_Labels;
use LinguatorPro\Modules\WooCommerce\Attribute_Texts;
use LinguatorPro\Modules\WooCommerce\Product_Sync;
use LinguatorPro\Modules\WooCommerce\Registry;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes a product group and compares translations with the original by the
 * rules of the Site Scan WooCommerce check.
 *
 * @since 1.3.0
 */
final class Product_Service {

	/**
	 * Most variations described per product.
	 *
	 * @since 1.3.0
	 * @var int
	 */
	const VARIATION_LIMIT = 100;

	/**
	 * Describes a product and the counterparts in its translation group.
	 *
	 * @since 1.3.0
	 * @param int    $product_id Original or translated product ID.
	 * @param string $target     Optional language slug to limit the translations to.
	 * @return array<string, mixed>|WP_Error Description, or an error.
	 */
	public static function describe( $product_id, $target = '' ) {
		$product = Woo_Guard::product( $product_id );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$target = sanitize_key( (string) $target );

		if ( '' !== $target ) {
			$language = Linguator_Guard::language( $target );

			if ( is_wp_error( $language ) ) {
				return $language;
			}
		}

		$group       = Woo_Guard::group( $product->get_id() );
		$original_id = Woo_Guard::original_id( $product->get_id() );
		$original    = wc_get_product( $original_id );

		if ( ! $original ) {
			return new WP_Error( 'lmat_wc_not_found', __( 'The original product could not be loaded.', 'linguator-pro' ) );
		}

		$original_lang = (string) linguator_get_post_language( $original_id );
		$translations  = array();

		foreach ( $group as $slug => $id ) {
			if ( $id === $original_id || ( '' !== $target && $slug !== $target ) ) {
				continue;
			}

			$translations[ $slug ] = $id;
		}

		$out = array(
			'product_id'            => $product->get_id(),
			'original_id'           => $original_id,
			'original_language'     => $original_lang,
			'sync_source_id'        => (int) Product_Sync::source_id( $product->get_id() ),
			'translation_group'     => $group,
			'type'                  => $original->get_type(),
			'status'                => get_post_status( $original_id ),
			'sku'                   => $original->get_sku( 'edit' ),
			'price'                 => self::prices( $original ),
			'stock'                 => self::stock( $original ),
			'attributes'            => self::attributes( $original ),
			'translations'          => array(),
			'variations'            => array(),
			'translatable_texts'    => self::texts( $original, null ),
		);

		if ( '' !== $target && ! isset( $group[ $target ] ) ) {
			$out['note'] = sprintf(
				/* translators: %s: language slug. */
				__( 'The product has no translation in "%s" yet. Create it with linguator/translate-post (source_post_id = original_id), then use translate-product-attributes.', 'linguator-pro' ),
				$target
			);
		}

		foreach ( $translations as $slug => $id ) {
			$counterpart = wc_get_product( $id );

			if ( ! $counterpart ) {
				continue;
			}

			$differences = self::differences( $original, $counterpart );

			$out['translations'][ $slug ] = array(
				'id'                 => $id,
				'status'             => get_post_status( $id ),
				'type'               => $counterpart->get_type(),
				'sku'                => $counterpart->get_sku( 'edit' ),
				'price'              => self::prices( $counterpart ),
				'stock'              => self::stock( $counterpart ),
				'attributes'         => self::attributes( $counterpart, $slug ),
				'differences'        => $differences,
				'in_sync'            => empty( $differences['fields'] ) && empty( $differences['variations'] ),
				'translatable_texts' => self::texts( $original, $counterpart ),
			);
		}

		$out['variations'] = self::variations( $original, $translations );

		return $out;
	}

	/**
	 * Price fields of a product.
	 *
	 * @since 1.3.0
	 * @param \WC_Product $product Product or variation.
	 * @return array<string, mixed> Prices.
	 */
	private static function prices( $product ) {
		return array(
			'regular_price' => (string) $product->get_regular_price( 'edit' ),
			'sale_price'    => (string) $product->get_sale_price( 'edit' ),
			'price'         => (string) $product->get_price( 'edit' ),
			'on_sale_from'  => self::show( $product->get_date_on_sale_from( 'edit' ) ),
			'on_sale_to'    => self::show( $product->get_date_on_sale_to( 'edit' ) ),
		);
	}

	/**
	 * Stock fields of a product.
	 *
	 * @since 1.3.0
	 * @param \WC_Product $product Product or variation.
	 * @return array<string, mixed> Stock data.
	 */
	private static function stock( $product ) {
		return array(
			'manage_stock'   => (bool) $product->get_manage_stock( 'edit' ),
			'stock_quantity' => $product->get_stock_quantity( 'edit' ),
			'stock_status'   => (string) $product->get_stock_status( 'edit' ),
			'backorders'     => (string) $product->get_backorders( 'edit' ),
		);
	}

	/**
	 * Attributes of a product, global ones with their terms and custom ones with their values.
	 *
	 * @since 1.3.0
	 * @param \WC_Product $product Product.
	 * @param string      $lang    Language the labels are shown in, empty for the original.
	 * @return array<int, array<string, mixed>> Attributes.
	 */
	public static function attributes( $product, $lang = '' ) {
		$out = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}

			$row = array(
				'position'     => (int) $attribute->get_position(),
				'visible'      => (bool) $attribute->get_visible(),
				'for_variants' => (bool) $attribute->get_variation(),
			);

			if ( $attribute->is_taxonomy() ) {
				$taxonomy = $attribute->get_name();
				$label    = Attribute_Service::global_label( $taxonomy );
				$terms    = array();

				foreach ( (array) $attribute->get_options() as $term_id ) {
					$term = get_term( (int) $term_id, $taxonomy );

					if ( $term && ! is_wp_error( $term ) ) {
						$terms[] = array(
							'id'   => (int) $term->term_id,
							'slug' => (string) $term->slug,
							'name' => (string) $term->name,
						);
					}
				}

				$row['type']     = 'global';
				$row['taxonomy'] = $taxonomy;
				$row['label']    = $label;

				if ( '' !== $lang ) {
					$row['label_translation'] = Attribute_Labels::translate_text( $label, $lang );
				}

				$row['terms'] = $terms;
			} else {
				$row['type']   = 'custom';
				$row['name']   = (string) $attribute->get_name();
				$row['values'] = array_values( array_map( 'strval', (array) $attribute->get_options() ) );
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Variations of the original with their counterparts in each translation.
	 *
	 * @since 1.3.0
	 * @param \WC_Product        $original     Original product.
	 * @param array<string, int> $translations Language => translated product ID.
	 * @return array<int, array<string, mixed>> Variations.
	 */
	private static function variations( $original, array $translations ) {
		$out = array();

		if ( ! $original->is_type( 'variable' ) ) {
			return $out;
		}

		$linked = array();

		foreach ( $translations as $slug => $id ) {
			$counterpart = wc_get_product( $id );

			if ( $counterpart ) {
				$linked[ $slug ] = self::linked_variations( $counterpart );
			}
		}

		foreach ( array_slice( $original->get_children(), 0, self::VARIATION_LIMIT ) as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}

			$row = array(
				'id'           => (int) $variation_id,
				'name'         => self::variation_name( $variation ),
				'sku'          => $variation->get_sku( 'edit' ),
				'attributes'   => (array) $variation->get_attributes( 'edit' ),
				'price'        => self::prices( $variation ),
				'stock'        => self::stock( $variation ),
				'translations' => array(),
			);

			foreach ( $translations as $slug => $id ) {
				$counterpart_id = (int) ( $linked[ $slug ][ $variation_id ] ?? 0 );
				$counterpart    = $counterpart_id > 0 ? wc_get_product( $counterpart_id ) : null;

				$row['translations'][ $slug ] = array(
					'id'          => $counterpart_id,
					'exists'      => (bool) $counterpart,
					'attributes'  => $counterpart ? (array) $counterpart->get_attributes( 'edit' ) : array(),
					'differences' => $counterpart ? self::prop_differences( $variation, $counterpart, self::variation_props() ) : array(),
				);
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * The translatable texts of a product and their translation state.
	 *
	 * @since 1.3.0
	 * @param \WC_Product      $original    Original product.
	 * @param \WC_Product|null $translation Translated product, or null for the original only.
	 * @return array<string, mixed> Texts.
	 */
	public static function texts( $original, $translation ) {
		$pairs = array(
			'title'             => array( (string) $original->get_name( 'edit' ), $translation ? (string) $translation->get_name( 'edit' ) : null ),
			'description'       => array( (string) $original->get_description( 'edit' ), $translation ? (string) $translation->get_description( 'edit' ) : null ),
			'short_description' => array( (string) $original->get_short_description( 'edit' ), $translation ? (string) $translation->get_short_description( 'edit' ) : null ),
			'purchase_note'     => array( (string) $original->get_purchase_note( 'edit' ), $translation ? (string) $translation->get_purchase_note( 'edit' ) : null ),
		);

		if ( method_exists( $original, 'get_button_text' ) ) {
			$pairs['button_text'] = array(
				(string) $original->get_button_text( 'edit' ),
				$translation && method_exists( $translation, 'get_button_text' ) ? (string) $translation->get_button_text( 'edit' ) : null,
			);
		}

		foreach ( Registry::translatable_meta_keys() as $key ) {
			if ( in_array( $key, array( '_purchase_note', '_button_text' ), true ) ) {
				continue;
			}

			$pairs[ $key ] = array(
				self::meta_text( $original->get_id(), $key ),
				$translation ? self::meta_text( $translation->get_id(), $key ) : null,
			);
		}

		$texts = array();

		foreach ( $pairs as $field => $pair ) {
			$texts[ $field ] = self::text_entry( $pair[0], $pair[1] );
		}

		$texts['attributes'] = self::attribute_texts( $original, $translation );

		if ( $original->is_type( 'variable' ) ) {
			$texts['variation_descriptions'] = self::variation_descriptions( $original, $translation );
		}

		return $texts;
	}

	/**
	 * A text meta value as a string.
	 *
	 * @since 1.3.0
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return string Value, empty when it is not text.
	 */
	private static function meta_text( $post_id, $key ) {
		$value = get_post_meta( (int) $post_id, (string) $key, true );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * One text with its translation state.
	 *
	 * @since 1.3.0
	 * @param string      $original    Text in the original.
	 * @param string|null $translation Text in the translation, or null when there is none.
	 * @return array<string, mixed> Entry.
	 */
	private static function text_entry( $original, $translation ) {
		$entry = array( 'original' => $original );

		if ( null === $translation ) {
			return $entry;
		}

		$entry['translation'] = $translation;

		if ( '' === trim( $original ) ) {
			$entry['state'] = 'empty_in_original';
		} elseif ( '' === trim( $translation ) ) {
			$entry['state'] = 'missing';
		} elseif ( trim( $translation ) === trim( $original ) ) {
			$entry['state'] = 'same_as_original';
		} else {
			$entry['state'] = 'translated';
		}

		return $entry;
	}

	/**
	 * Attribute texts in the shape Attribute_Texts and translate-product-attributes use.
	 *
	 * @since 1.3.0
	 * @param \WC_Product      $original    Original product.
	 * @param \WC_Product|null $translation Translated product, or null.
	 * @return array<string, mixed> Slot => texts.
	 */
	private static function attribute_texts( $original, $translation ) {
		$source = Attribute_Texts::build( $original->get_id() );
		$target = $translation ? Attribute_Texts::build( $translation->get_id() ) : null;
		$lang   = $translation ? (string) linguator_get_post_language( $translation->get_id() ) : '';
		$out    = array();

		foreach ( $source as $slot => $entry ) {
			if ( isset( $entry['label'] ) ) {
				$out[ $slot ] = array(
					'label' => self::text_entry(
						(string) $entry['label'],
						$translation ? Attribute_Labels::translate_text( (string) $entry['label'], $lang ) : null
					),
				);
				continue;
			}

			$counterpart = is_array( $target ) && isset( $target[ $slot ] ) ? $target[ $slot ] : array();
			$row         = array(
				'name'   => self::text_entry( (string) $entry['name'], $translation ? (string) ( $counterpart['name'] ?? '' ) : null ),
				'values' => array(),
			);

			foreach ( (array) $entry['values'] as $value_key => $value ) {
				$row['values'][ $value_key ] = self::text_entry( (string) $value, $translation ? (string) ( $counterpart['values'][ $value_key ] ?? '' ) : null );
			}

			$out[ $slot ] = $row;
		}

		return $out;
	}

	/**
	 * Descriptions of the variations of a variable product.
	 *
	 * @since 1.3.0
	 * @param \WC_Product      $original    Original product.
	 * @param \WC_Product|null $translation Translated product, or null.
	 * @return array<int, array<string, mixed>> Descriptions by original variation.
	 */
	private static function variation_descriptions( $original, $translation ) {
		$linked = $translation ? self::linked_variations( $translation ) : array();
		$out    = array();

		foreach ( array_slice( $original->get_children(), 0, self::VARIATION_LIMIT ) as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}

			$counterpart = $translation && ! empty( $linked[ $variation_id ] ) ? wc_get_product( $linked[ $variation_id ] ) : null;
			$entry       = self::text_entry( (string) $variation->get_description( 'edit' ), $translation ? ( $counterpart ? (string) $counterpart->get_description( 'edit' ) : '' ) : null );

			$entry['variation_id']  = (int) $variation_id;
			$entry['translated_id'] = $counterpart ? $counterpart->get_id() : 0;
			$out[]                  = $entry;
		}

		return $out;
	}

	/**
	 * Differences between an original and a translation, by the rules of the Site Scan WooCommerce check.
	 *
	 * @since 1.3.0
	 * @param \WC_Product $source Original product.
	 * @param \WC_Product $target Translated product.
	 * @return array<string, array<int, mixed>> Differences under fields, variations and attributes.
	 */
	public static function differences( $source, $target ) {
		return array(
			'fields'     => self::field_problems( $source, $target ),
			'variations' => self::variation_problems( $source, $target ),
			'attributes' => self::attribute_problems( $source, $target ),
		);
	}

	/**
	 * Product properties kept identical on variations.
	 *
	 * @since 1.3.0
	 * @return string[] Property names.
	 */
	private static function variation_props() {
		return array_values( array_diff( Product_Sync::product_props(), array( 'sku', 'featured', 'catalog_visibility', 'sold_individually' ) ) );
	}

	/**
	 * Settings that differ between the source and the translation, for the product and each linked variation.
	 *
	 * @since 1.3.0
	 * @param \WC_Product $source Original product.
	 * @param \WC_Product $target Translated product.
	 * @return array<int, array<string, mixed>> Differences.
	 */
	private static function field_problems( $source, $target ) {
		$props = Product_Sync::product_props();

		if ( $source->is_type( 'variable' ) ) {
			// WooCommerce derives the stock status of a variable product from its variations.
			$props = array_diff( $props, array( 'stock_status' ) );
		}

		$problems = self::prop_differences( $source, $target, $props );

		if ( $source->is_type( 'variable' ) ) {
			$linked = self::linked_variations( $target );

			foreach ( $source->get_children() as $variation_id ) {
				if ( empty( $linked[ $variation_id ] ) ) {
					continue;
				}

				$variation   = wc_get_product( $variation_id );
				$counterpart = wc_get_product( $linked[ $variation_id ] );

				if ( ! $variation || ! $counterpart ) {
					continue;
				}

				foreach ( self::prop_differences( $variation, $counterpart, self::variation_props() ) as $diff ) {
					$diff['variation_id']            = (int) $variation_id;
					$diff['translated_variation_id'] = (int) $linked[ $variation_id ];
					$diff['variation']               = self::variation_name( $variation );
					$problems[]                      = $diff;
				}
			}
		}

		return $problems;
	}

	/**
	 * Properties that differ between two products or variations.
	 *
	 * @since 1.3.0
	 * @param \WC_Product $source Original.
	 * @param \WC_Product $target Translation.
	 * @param string[]    $props  Property names.
	 * @return array<int, array<string, mixed>> Differences with the property and both values.
	 */
	private static function prop_differences( $source, $target, array $props ) {
		$out = array();

		foreach ( $props as $prop ) {
			$getter = 'get_' . $prop;

			if ( ! method_exists( $source, $getter ) || ! method_exists( $target, $getter ) ) {
				continue;
			}

			$original = $source->$getter( 'edit' );
			$current  = $target->$getter( 'edit' );

			if ( self::normalize( $original ) === self::normalize( $current ) ) {
				continue;
			}

			$out[] = array(
				'property'    => (string) $prop,
				'original'    => self::show( $original ),
				'translation' => self::show( $current ),
			);
		}

		return $out;
	}

	/**
	 * Variations of the original that the translation lacks, and translated variations whose description was copied.
	 *
	 * @since 1.3.0
	 * @param \WC_Product $source Original product.
	 * @param \WC_Product $target Translated product.
	 * @return array<int, array<string, mixed>> Problems.
	 */
	private static function variation_problems( $source, $target ) {
		$out = array();

		if ( ! $source->is_type( 'variable' ) ) {
			return $out;
		}

		if ( ! $target->is_type( 'variable' ) ) {
			$out[] = array(
				'issue'  => 'type_differs',
				'detail' => sprintf( '%s -> %s', $source->get_type(), $target->get_type() ),
			);
		}

		$linked = self::linked_variations( $target );

		foreach ( $source->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}

			if ( empty( $linked[ $variation_id ] ) ) {
				$out[] = array(
					'issue'        => 'missing_variation',
					'variation_id' => (int) $variation_id,
					'variation'    => self::variation_name( $variation ),
				);
				continue;
			}

			$counterpart = wc_get_product( $linked[ $variation_id ] );
			$text        = trim( (string) $variation->get_description( 'edit' ) );

			if ( $counterpart && '' !== $text && $text === trim( (string) $counterpart->get_description( 'edit' ) ) ) {
				$out[] = array(
					'issue'                   => 'description_not_translated',
					'variation_id'            => (int) $variation_id,
					'translated_variation_id' => (int) $linked[ $variation_id ],
					'variation'               => self::variation_name( $variation ),
				);
			}
		}

		return $out;
	}

	/**
	 * Custom attributes of a translation that still match the original, by name and by every value.
	 *
	 * @since 1.3.0
	 * @param \WC_Product $source Original product.
	 * @param \WC_Product $target Translated product.
	 * @return array<int, array<string, mixed>> Attributes that still read like the original.
	 */
	private static function attribute_problems( $source, $target ) {
		$own = array();

		foreach ( $target->get_attributes() as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && ! $attribute->is_taxonomy() ) {
				$own[] = $attribute;
			}
		}

		$out   = array();
		$index = 0;

		foreach ( $source->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || $attribute->is_taxonomy() ) {
				continue;
			}

			$slot        = 'a' . $index;
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
				$out[] = array(
					'issue' => 'attribute_matches_original',
					'slot'  => $slot,
					'name'  => (string) $attribute->get_name(),
				);
			}
		}

		return $out;
	}

	/**
	 * Variations of a translated product, by the ID of the original variation they were copied from.
	 *
	 * @since 1.3.0
	 * @param \WC_Product $target Translated product.
	 * @return array<int, int> Original variation ID => translated variation ID.
	 */
	public static function linked_variations( $target ) {
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
	 * Short name of a variation, such as "Size: S".
	 *
	 * @since 1.3.0
	 * @param \WC_Product_Variation $variation Variation.
	 * @return string Name.
	 */
	private static function variation_name( $variation ) {
		$name = wp_strip_all_tags( (string) wc_get_formatted_variation( $variation, true, true ) );

		/* translators: %d: variation ID. */
		return '' !== $name ? $name : sprintf( __( 'Variation #%d', 'linguator-pro' ), $variation->get_id() );
	}

	/**
	 * Comparable form of a property value.
	 *
	 * @since 1.3.0
	 * @param mixed $value Value from a product getter.
	 * @return string Comparable text.
	 */
	private static function normalize( $value ) {
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
	 * Output form of a property value.
	 *
	 * @since 1.3.0
	 * @param mixed $value Value from a product getter.
	 * @return mixed Scalar for output.
	 */
	private static function show( $value ) {
		if ( $value instanceof \WC_DateTime ) {
			return $value->date( 'Y-m-d H:i:s' );
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			return sprintf( '%d item(s)', count( $value ) );
		}

		return null === $value ? '' : (string) $value;
	}

	/**
	 * Synchronizes a product group, or reports what a sync would change.
	 *
	 * @since 1.3.0
	 * @param int  $product_id Original or translated product ID.
	 * @param bool $dry_run    True to report without writing.
	 * @return array<string, mixed>|WP_Error Report, or an error.
	 */
	public static function sync( $product_id, $dry_run = true ) {
		$product = Woo_Guard::product( $product_id );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$group = Woo_Guard::group( $product->get_id() );

		if ( count( $group ) < 2 ) {
			return new WP_Error( 'lmat_wc_no_translations', __( 'The product has no translations to synchronize. Create them with linguator/translate-post first.', 'linguator-pro' ) );
		}

		$denied = Woo_Guard::check_can_edit( array_values( $group ) );

		if ( null !== $denied ) {
			return $denied;
		}

		$source_id = (int) Product_Sync::source_id( $product->get_id() );
		$before    = self::group_state( $source_id, $group );

		$out = array(
			'product_id'     => $product->get_id(),
			'sync_source_id' => $source_id,
			'source_language' => (string) linguator_get_post_language( $source_id ),
			'dry_run'        => (bool) $dry_run,
			'synced_props'   => array_values( array_map( 'strval', Product_Sync::product_props() ) ),
		);

		if ( $dry_run ) {
			$out['translations'] = $before;
			$out['would_change'] = false;

			foreach ( $before as $row ) {
				if ( ! $row['in_sync'] ) {
					$out['would_change'] = true;
				}
			}

			return $out;
		}

		Product_Sync::sync_product( $source_id );

		clean_post_cache( $source_id );

		foreach ( $group as $id ) {
			clean_post_cache( $id );
		}

		$after   = self::group_state( $source_id, $group );
		$changes = array();

		foreach ( $before as $slug => $row ) {
			$remaining = array_map(
				static function ( $diff ) {
					return ( $diff['property'] ?? '' ) . '|' . ( $diff['variation_id'] ?? '' );
				},
				$after[ $slug ]['differences']['fields']
			);
			$fixed     = array();

			foreach ( $row['differences']['fields'] as $diff ) {
				if ( ! in_array( ( $diff['property'] ?? '' ) . '|' . ( $diff['variation_id'] ?? '' ), $remaining, true ) ) {
					$fixed[] = $diff;
				}
			}

			$changes[ $slug ] = array(
				'id'                 => $row['id'],
				'fields_updated'     => $fixed,
				'variations_before'  => $row['variation_count'],
				'variations_after'   => $after[ $slug ]['variation_count'],
				'remaining'          => $after[ $slug ]['differences'],
				'text_issues_not_synced' => $after[ $slug ]['text_issues_not_synced'],
				'in_sync'            => $after[ $slug ]['in_sync'],
			);
		}

		$out['translations'] = $changes;
		$out['note']         = __( 'The sync writes to the product group only; text (title, descriptions, custom attribute wording) is never overwritten.', 'linguator-pro' );

		return $out;
	}

	/**
	 * Differences of every translation against the sync source.
	 *
	 * @since 1.3.0
	 * @param int                $source_id Sync source product ID.
	 * @param array<string, int> $group     Translation group.
	 * @return array<string, array<string, mixed>> Language => state.
	 */
	private static function group_state( $source_id, array $group ) {
		$source = wc_get_product( $source_id );
		$out    = array();

		if ( ! $source ) {
			return $out;
		}

		foreach ( $group as $slug => $id ) {
			if ( $id === $source_id ) {
				continue;
			}

			$target = wc_get_product( $id );

			if ( ! $target ) {
				continue;
			}

			$differences = self::differences( $source, $target );
			$pending     = array(
				'fields'     => $differences['fields'],
				'variations' => array(),
			);
			$text_issues = array(
				'variation_descriptions' => array(),
				'attributes'             => $differences['attributes'],
			);

			// Text (variation descriptions, attribute wording) is never synchronized, so it is reported apart.
			foreach ( $differences['variations'] as $problem ) {
				if ( 'description_not_translated' === ( $problem['issue'] ?? '' ) ) {
					$text_issues['variation_descriptions'][] = $problem;
				} else {
					$pending['variations'][] = $problem;
				}
			}

			$attributes_match = self::global_attributes_match( $source, $target );
			$out[ $slug ]     = array(
				'id'                       => $id,
				'differences'              => $pending,
				'global_attributes_differ' => ! $attributes_match,
				'text_issues_not_synced'   => $text_issues,
				'in_sync'                  => empty( $pending['fields'] ) && empty( $pending['variations'] ) && $attributes_match,
				'variation_count' => count( self::linked_variations( $target ) ),
			);
		}

		return $out;
	}

	/**
	 * Whether the global attributes of two products are identical.
	 *
	 * @since 1.3.0
	 * @param \WC_Product $source Source product.
	 * @param \WC_Product $target Target product.
	 * @return bool True when they match.
	 */
	private static function global_attributes_match( $source, $target ) {
		$collect = static function ( $product ) {
			$map = array();

			foreach ( $product->get_attributes() as $attribute ) {
				if ( $attribute instanceof \WC_Product_Attribute && $attribute->is_taxonomy() ) {
					$options = array_map( 'absint', (array) $attribute->get_options() );
					sort( $options );
					$map[ $attribute->get_name() ] = $options;
				}
			}

			ksort( $map );

			return $map;
		};

		return $collect( $source ) === $collect( $target );
	}
}
