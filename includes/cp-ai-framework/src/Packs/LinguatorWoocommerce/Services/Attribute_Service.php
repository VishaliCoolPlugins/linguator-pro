<?php
/**
 * Translates product attribute texts and global attribute names.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorWoocommerce\Services;

use CoolPlugins\AiFramework\Packs\Linguator\Services\Linguator_Guard;
use CoolPlugins\AiFramework\Packs\Linguator\Services\String_Service;
use LinguatorPro\Modules\WooCommerce\Attribute_Labels;
use LinguatorPro\Modules\WooCommerce\Attribute_Texts;
use LinguatorPro\Modules\WooCommerce\Product_Sync;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes attribute texts the way the Linguator translation editor does, and
 * saves global attribute names as Linguator strings.
 *
 * @since 1.3.0
 */
final class Attribute_Service {

	/**
	 * The global attribute with a taxonomy name or a slug.
	 *
	 * @since 1.3.0
	 * @param string $slug     Attribute slug such as "size" (or "pa_size").
	 * @param string $taxonomy Taxonomy such as "pa_size".
	 * @return object|null Row of wc_get_attribute_taxonomies(), or null.
	 */
	public static function find_global( $slug, $taxonomy = '' ) {
		$slug     = sanitize_title( (string) $slug );
		$taxonomy = sanitize_key( (string) $taxonomy );

		if ( '' === $slug && '' !== $taxonomy ) {
			$slug = $taxonomy;
		}

		if ( 0 === strpos( $slug, 'pa_' ) ) {
			$slug = substr( $slug, 3 );
		}

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( '' !== $slug && (string) $attribute->attribute_name === $slug ) {
				return $attribute;
			}

			if ( '' !== $taxonomy && wc_attribute_taxonomy_name( (string) $attribute->attribute_name ) === $taxonomy ) {
				return $attribute;
			}
		}

		return null;
	}

	/**
	 * The stored name of a global attribute, before any translation.
	 *
	 * @since 1.3.0
	 * @param string $taxonomy Taxonomy such as "pa_size".
	 * @return string Name, or the taxonomy when it is unknown.
	 */
	public static function global_label( $taxonomy ) {
		$attribute = self::find_global( '', (string) $taxonomy );

		return $attribute ? (string) $attribute->attribute_label : (string) $taxonomy;
	}

	/**
	 * Stores translated attribute texts on a translation, as the editor flow does.
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	public static function translate_attributes( array $input ) {
		$source_id = isset( $input['source_product_id'] ) ? absint( $input['source_product_id'] ) : 0;
		$target    = isset( $input['target_language'] ) ? sanitize_key( (string) $input['target_language'] ) : '';
		$product   = Woo_Guard::product( $source_id );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$language = Linguator_Guard::language( $target );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$group     = Woo_Guard::group( $source_id );
		$target_id = (int) ( $group[ $target ] ?? 0 );

		if ( $source_id === $target_id ) {
			return new WP_Error( 'lmat_wc_same_language', __( 'The product is already in the target language. Pass the original product as source_product_id.', 'linguator-pro' ) );
		}

		if ( $target_id < 1 ) {
			return new WP_Error(
				'lmat_wc_no_translation',
				sprintf(
					/* translators: 1: product ID, 2: language slug. */
					__( 'Product %1$d has no translation in "%2$s" yet. Create it first with linguator/translate-post (source_post_id = %1$d, target_language = "%2$s"), then run this ability again.', 'linguator-pro' ),
					$source_id,
					$target
				)
			);
		}

		$denied = Woo_Guard::check_can_edit( array( $source_id, $target_id ) );

		if ( null !== $denied ) {
			return $denied;
		}

		// The editor translates the texts of the product the sync copies from.
		$origin = (int) Product_Sync::source_id( $target_id );
		$origin = $origin === $target_id ? $source_id : $origin;
		$slots  = Attribute_Texts::build( $origin );

		$clean   = self::clean_attributes( isset( $input['attributes'] ) ? $input['attributes'] : array(), $slots );
		$skipped = $clean['skipped'];
		$texts   = $clean['texts'];

		if ( empty( $texts ) ) {
			return new WP_Error(
				'lmat_wc_no_attribute_texts',
				__( 'No usable attribute texts were given. Pass attributes as {"a0":{"name":"...","values":{"v0":"..."}},"pa_size":{"label":"..."}} using the slots listed under translatable_texts.attributes of get-product-translation.', 'linguator-pro' ),
				array(
					'skipped'         => $skipped,
					'available_slots' => $slots,
				)
			);
		}

		// Names of global attributes are shared by the whole site, so they need the capability to edit shop settings.
		$labels = array();

		foreach ( array_keys( $texts ) as $slot ) {
			if ( 0 !== strpos( (string) $slot, 'pa_' ) ) {
				continue;
			}

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				$skipped[] = array(
					'slot'   => $slot,
					'reason' => 'Translating a global attribute name needs the manage_woocommerce capability.',
				);
				unset( $texts[ $slot ] );
				continue;
			}

			$source_label    = (string) ( $slots[ $slot ]['label'] ?? '' );
			$labels[ $slot ] = array(
				'source' => $source_label,
				'before' => Attribute_Labels::translate_text( $source_label, $target ),
			);
		}

		if ( empty( $texts ) ) {
			return new WP_Error( 'lmat_wc_no_attribute_texts', __( 'Nothing to store: every slot was refused.', 'linguator-pro' ), array( 'skipped' => $skipped ) );
		}

		$stored = get_post_meta( $target_id, Attribute_Texts::META, true );
		$stored = is_array( $stored ) ? $stored : array();
		$merged = self::merge( $stored, $texts );

		// The meta update runs Attribute_Texts::saved(), which saves global names that have no
		// translation yet and runs the product sync that writes the custom attribute texts.
		$updated = update_post_meta( $target_id, Attribute_Texts::META, $merged );

		if ( false === $updated ) {
			// Unchanged texts fire no hook, so apply them explicitly.
			Attribute_Texts::saved( 0, $target_id, Attribute_Texts::META, $merged );
		}

		Product_Sync::sync_product( $target_id );

		clean_post_cache( $target_id );

		$fresh   = wc_get_product( $target_id );
		$results = array();

		foreach ( $labels as $slot => $info ) {
			$after = Attribute_Labels::translate_text( $info['source'], $target );

			if ( $info['before'] !== $info['source'] ) {
				$state = 'kept_existing_translation';
			} elseif ( $after !== $info['source'] ) {
				$state = 'saved';
			} else {
				$state = 'not_saved';
			}

			$results[ $slot ] = array(
				'source'      => $info['source'],
				'translation' => $after,
				'state'       => $state,
			);
		}

		$out = array(
			'source_product_id'            => $source_id,
			'translated_product_id'        => $target_id,
			'language'                     => $target,
			'attribute_texts_enabled'      => Attribute_Texts::is_enabled(),
			'stored_texts'                 => $merged,
			'global_attribute_labels'      => $results,
			'attributes_after'             => $fresh ? Product_Service::attributes( $fresh, $target ) : array(),
			'still_matching_the_original' => $fresh && $product ? Product_Service::differences( wc_get_product( $origin ), $fresh )['attributes'] : array(),
			'skipped'                      => $skipped,
		);

		if ( ! empty( $results ) ) {
			$out['note'] = __( 'A global attribute name is a site-wide string: an existing translation is kept. Replace it with translate-attribute-label.', 'linguator-pro' );
		}

		return $out;
	}

	/**
	 * Validates the requested texts against the slots the product really has.
	 *
	 * @since 1.3.0
	 * @param mixed                $attributes Requested attributes.
	 * @param array<string, mixed> $slots      Slots from Attribute_Texts::build().
	 * @return array{texts: array<string, mixed>, skipped: array<int, array<string, string>>} Clean texts and what was refused.
	 */
	private static function clean_attributes( $attributes, array $slots ) {
		$texts   = array();
		$skipped = array();

		if ( is_string( $attributes ) ) {
			$decoded    = json_decode( $attributes, true );
			$attributes = is_array( $decoded ) ? $decoded : array();
		}

		if ( is_object( $attributes ) ) {
			$attributes = (array) $attributes;
		}

		foreach ( (array) $attributes as $slot => $entry ) {
			$slot  = (string) $slot;
			$entry = is_object( $entry ) ? (array) $entry : $entry;

			if ( ! is_array( $entry ) || ! isset( $slots[ $slot ] ) ) {
				$skipped[] = array(
					'slot'   => $slot,
					'reason' => 'The product has no such attribute slot.',
				);
				continue;
			}

			if ( 0 === strpos( $slot, 'pa_' ) ) {
				$label = isset( $entry['label'] ) ? self::text( $entry['label'] ) : '';

				if ( '' === $label ) {
					$skipped[] = array(
						'slot'   => $slot,
						'reason' => 'A global attribute needs a non-empty "label".',
					);
					continue;
				}

				$texts[ $slot ] = array( 'label' => $label );
				continue;
			}

			$row  = array();
			$name = isset( $entry['name'] ) ? self::text( $entry['name'] ) : '';

			if ( '' !== $name ) {
				if ( false !== strpos( $name, '|' ) ) {
					$skipped[] = array(
						'slot'   => $slot . '.name',
						'reason' => 'A "|" cannot be part of an attribute name.',
					);
				} else {
					$row['name'] = $name;
				}
			}

			$values = isset( $entry['values'] ) ? $entry['values'] : array();
			$values = is_object( $values ) ? (array) $values : $values;

			foreach ( is_array( $values ) ? $values : array() as $value_key => $value ) {
				$value_key = (string) $value_key;
				$value     = self::text( $value );

				if ( ! isset( $slots[ $slot ]['values'][ $value_key ] ) ) {
					$skipped[] = array(
						'slot'   => $slot . '.values.' . $value_key,
						'reason' => 'The attribute has no such value slot.',
					);
					continue;
				}

				if ( '' === $value || false !== strpos( $value, '|' ) ) {
					$skipped[] = array(
						'slot'   => $slot . '.values.' . $value_key,
						'reason' => 'Values must be non-empty and cannot contain "|".',
					);
					continue;
				}

				$row['values'][ $value_key ] = $value;
			}

			if ( ! empty( $row ) ) {
				$texts[ $slot ] = $row;
			}
		}

		return array(
			'texts'   => $texts,
			'skipped' => $skipped,
		);
	}

	/**
	 * Sanitizes one text.
	 *
	 * @since 1.3.0
	 * @param mixed $value Raw value.
	 * @return string Single-line text.
	 */
	private static function text( $value ) {
		return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
	}

	/**
	 * Merges new attribute texts into the stored ones, slot by slot.
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $stored Stored texts.
	 * @param array<string, mixed> $texts  New texts.
	 * @return array<string, mixed> Merged texts.
	 */
	private static function merge( array $stored, array $texts ) {
		foreach ( $texts as $slot => $entry ) {
			$current = isset( $stored[ $slot ] ) && is_array( $stored[ $slot ] ) ? $stored[ $slot ] : array();

			if ( isset( $entry['values'] ) ) {
				$entry['values'] = array_merge( isset( $current['values'] ) && is_array( $current['values'] ) ? $current['values'] : array(), $entry['values'] );
			}

			$stored[ $slot ] = array_merge( $current, $entry );
		}

		return $stored;
	}

	/**
	 * Saves the translation of a global attribute name as a Linguator string.
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	public static function translate_label( array $input ) {
		$ready = Woo_Guard::check_ready();

		if ( null !== $ready ) {
			return $ready;
		}

		$slug      = isset( $input['attribute_slug'] ) ? (string) $input['attribute_slug'] : '';
		$taxonomy  = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
		$target    = isset( $input['target_language'] ) ? sanitize_key( (string) $input['target_language'] ) : '';
		$label     = isset( $input['label'] ) ? self::text( $input['label'] ) : '';
		$attribute = self::find_global( $slug, $taxonomy );

		if ( ! $attribute ) {
			$known = array();

			foreach ( wc_get_attribute_taxonomies() as $row ) {
				$known[] = wc_attribute_taxonomy_name( (string) $row->attribute_name );
			}

			return new WP_Error(
				'lmat_wc_unknown_attribute',
				sprintf(
					/* translators: %s: comma-separated attribute taxonomies. */
					__( 'No global attribute matches. Pass attribute_slug (for example "size") or taxonomy (for example "pa_size"). Global attributes: %s.', 'linguator-pro' ),
					implode( ', ', $known )
				)
			);
		}

		$language = Linguator_Guard::language( $target );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		if ( (string) linguator_default_language() === $target ) {
			return new WP_Error( 'lmat_wc_default_language', __( 'The attribute names are written in the default language; translate them into another language.', 'linguator-pro' ) );
		}

		if ( '' === $label ) {
			return new WP_Error( 'lmat_wc_missing_label', __( 'label is required.', 'linguator-pro' ) );
		}

		$source   = (string) $attribute->attribute_label;
		$previous = Attribute_Labels::translate_text( $source, $target );
		$previous = $previous === $source ? '' : $previous;

		if ( '' !== $previous && isset( $input['overwrite'] ) && false === (bool) $input['overwrite'] ) {
			return new WP_Error(
				'lmat_wc_label_exists',
				__( 'This name already has a translation in that language; pass overwrite = true to replace it.', 'linguator-pro' ),
				array( 'translation' => $previous )
			);
		}

		$saved = String_Service::save( $source, $target, $label );

		if ( is_wp_error( $saved ) && 'lmat_unknown_string' === $saved->get_error_code() ) {
			// The strings screen registers names only while an admin page loads; the string is the attribute's own name.
			$saved = self::save_string( $source, $language, $label );
		}

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'attribute_slug'       => (string) $attribute->attribute_name,
			'taxonomy'             => wc_attribute_taxonomy_name( (string) $attribute->attribute_name ),
			'source_label'         => $source,
			'language'             => $target,
			'translation'          => (string) ( $saved['translation'] ?? $label ),
			'previous_translation' => $previous,
			'string_group'         => Attribute_Labels::CONTEXT,
			'read_back'            => Attribute_Labels::translate_text( $source, $target ),
		);
	}

	/**
	 * Saves a string translation directly, as the strings screen does.
	 *
	 * @since 1.3.0
	 * @param string $source      Source string.
	 * @param object $language    Target language object.
	 * @param string $translation Translation.
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	private static function save_string( $source, $language, $translation ) {
		$class = '\Linguator\Includes\Helpers\Linguator_MO';

		if ( ! class_exists( $class ) ) {
			return new WP_Error( 'lmat_wc_strings_unavailable', __( 'Linguator string translations are not available.', 'linguator-pro' ) );
		}

		$screen = '\Linguator\Admin\Controllers\Linguator_Admin_Strings';

		if ( method_exists( $screen, 'sanitize_string_translation' ) ) {
			$translation = (string) $screen::sanitize_string_translation( $translation, 'Attribute' );
		}

		$mo = new $class();
		$mo->import_from_db( $language );
		$mo->add_entry( $mo->make_entry( $source, $translation ) );
		$mo->export_to_db( $language );

		return array(
			'translation' => $translation,
			'language'    => (string) $language->slug,
		);
	}
}
