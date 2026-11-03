<?php
/**
 * Lets the Linguator translation editor and bulk translation translate product attributes.
 *
 * @package LinguatorPro
 */

namespace LinguatorPro\Modules\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds `_product_attributes` to the Custom Fields settings, switched off until an administrator enables it. Once it
 * is enabled, the single page translation editor and bulk translation translate the texts of the product attributes
 * like any other custom field, and the translated texts are applied to the translated product.
 *
 * The raw attributes are never handed to the translation screens: they mix texts with settings, and the name of a
 * global attribute (`pa_size`) must not be translated. The screens receive only the texts, in this shape:
 *
 *   a{n}      Custom attribute number n: `name` and `values` (`v0`, `v1`...).
 *   pa_{slug} Global attribute: `label` (its name). Its values are terms shared by every language.
 *
 * @since 2.3.0
 */
class Attribute_Texts {

	/**
	 * The custom field an administrator enables in the Custom Fields settings.
	 */
	const FIELD = '_product_attributes';

	/**
	 * Where the translated texts are kept on a translated product until the product sync applies them.
	 */
	const META = '_lmat_wc_attribute_texts';

	/**
	 * Key that tells a texts array from a real attributes array when it is saved back.
	 */
	const MARKER = '__lmat_attribute_texts';

	/**
	 * Whether the translation screens are reading product fields right now.
	 *
	 * @var bool
	 */
	private static $reading = false;

	/**
	 * Whether the texts are being applied, to stop the writes it causes from running it again.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Whether the texts are being built, because reading the product for them reads its fields too.
	 *
	 * @var bool
	 */
	private static $building = false;

	/**
	 * Hooks the field.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'lmat/custom_fields/all_fields', array( __CLASS__, 'list_field' ), 10, 1 );

		add_action( 'wp_ajax_lmat_fetch_post_meta_fields', array( __CLASS__, 'start_reading' ), 1, 0 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'start_reading_rest' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'stop_reading_rest' ), 10, 1 );
		add_filter( 'get_post_metadata', array( __CLASS__, 'read' ), 10, 4 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'write' ), 10, 4 );

		add_action( 'added_post_meta', array( __CLASS__, 'saved' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'saved' ), 10, 4 );
	}

	/**
	 * Whether an administrator enabled the product attributes in the Custom Fields settings.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$saved = get_option( 'lmat_allowed_custom_fields', false );

		return is_array( $saved ) && isset( $saved[ self::FIELD ]['status'] ) && true === $saved[ self::FIELD ]['status'];
	}

	/**
	 * Shows the product attributes in the Custom Fields settings. They are never enabled by default.
	 *
	 * @since 2.3.0
	 *
	 * @param array $fields Field key => type and status.
	 * @return array
	 */
	public static function list_field( $fields ) {
		$fields[ self::FIELD ] = array(
			'type'   => 'array',
			'status' => self::is_enabled() ? 'Supported' : 'Unsupported',
		);

		return $fields;
	}

	/**
	 * Starts serving the texts to the single page translation editor.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function start_reading() {
		self::$reading = true;
	}

	/**
	 * Starts serving the texts to bulk translation.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed            $response Response so far.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function start_reading_rest( $response, $handler, $request ) {
		unset( $handler );
		if ( $request instanceof \WP_REST_Request && 1 === preg_match( '#:bulk-translate-entries$#', $request->get_route() ) ) {
			self::$reading = true;
		}

		return $response;
	}

	/**
	 * Stops serving the texts once the REST request has been handled.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $response Response.
	 * @return mixed
	 */
	public static function stop_reading_rest( $response ) {
		self::$reading = false;

		return $response;
	}

	/**
	 * Serves the texts of the attributes instead of the raw attributes, but only to the translation screens.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed  $check    Short-circuit value.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key. Only the read of all fields, which has no key, is served: the translation screens read that way, while WooCommerce reads the attributes by key.
	 * @param bool   $single   Whether a single value is read.
	 * @return mixed
	 */
	public static function read( $check, $post_id, $meta_key, $single ) {
		unset( $single );
		if ( null !== $check || ! self::$reading || self::$building || '' !== $meta_key || ! self::is_enabled() ) {
			return $check;
		}

		$post_id = (int) $post_id;
		if ( 'product' !== get_post_type( $post_id ) || Product_Sync::source_id( $post_id ) !== $post_id ) {
			return $check;
		}

		self::$building = true;
		try {
			$texts = self::build( $post_id );
		} finally {
			self::$building = false;
		}
		if ( empty( $texts ) ) {
			return $check;
		}
		$texts[ self::MARKER ] = 1;

		remove_filter( 'get_post_metadata', array( __CLASS__, 'read' ), 10 );
		$all = get_post_meta( $post_id );
		add_filter( 'get_post_metadata', array( __CLASS__, 'read' ), 10, 4 );

		$all[ self::FIELD ] = array( maybe_serialize( $texts ) );

		return $all;
	}

	/**
	 * Keeps the translated texts from replacing the real attributes of a translated product.
	 *
	 * The translation screens save the texts back under the attributes key. They are kept apart and applied by the
	 * product sync instead.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed  $check      Short-circuit value.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return mixed
	 */
	public static function write( $check, $post_id, $meta_key, $meta_value ) {
		if ( self::FIELD !== $meta_key || ! is_array( $meta_value ) || ! isset( $meta_value[ self::MARKER ] ) ) {
			return $check;
		}

		unset( $meta_value[ self::MARKER ] );
		if ( self::is_enabled() && 'product' === get_post_type( (int) $post_id ) ) {
			update_post_meta( (int) $post_id, self::META, $meta_value );
		}

		return true;
	}

	/**
	 * Collects the translatable texts of a product's attributes.
	 *
	 * @since 2.3.0
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function build( $product_id ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return array();
		}

		$labels = array();
		foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
			$labels[ wc_attribute_taxonomy_name( $taxonomy->attribute_name ) ] = (string) $taxonomy->attribute_label;
		}

		$texts  = array();
		$custom = 0;

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}

			if ( ! $attribute->is_taxonomy() ) {
				$values = array();
				foreach ( array_values( (array) $attribute->get_options() ) as $i => $value ) {
					$value = trim( (string) $value );
					if ( '' !== $value ) {
						$values[ 'v' . $i ] = $value;
					}
				}
				$texts[ 'a' . $custom ] = array(
					'name'   => (string) $attribute->get_name(),
					'values' => $values,
				);
				++$custom;
				continue;
			}

			$taxonomy = $attribute->get_name();
			$entry    = array();
			if ( ! empty( $labels[ $taxonomy ] ) ) {
				$entry['label'] = $labels[ $taxonomy ];
			}

			if ( $entry ) {
				$texts[ $taxonomy ] = $entry;
			}
		}

		return $texts;
	}

	/**
	 * Applies the texts once they are saved on a translated product.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public static function saved( $meta_id, $post_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		if ( self::META !== $meta_key || self::$busy || ! is_array( $meta_value ) ) {
			return;
		}

		$post_id   = (int) $post_id;
		$source_id = Product_Sync::source_id( $post_id );
		if ( 'product' !== get_post_type( $post_id ) || $source_id === $post_id ) {
			return;
		}

		self::$busy = true;
		try {
			self::apply_global( $source_id, $post_id, $meta_value );
		} finally {
			self::$busy = false;
		}

		// Custom attributes are applied by the product sync, which also keeps the variations in line.
		Product_Sync::sync_product( $post_id );
	}

	/**
	 * Saves the translated names of global attributes.
	 *
	 * The name of a global attribute is a string shared by the whole site, so an existing translation of it is never
	 * replaced.
	 *
	 * @since 2.3.0
	 *
	 * @param int   $source_id Original product ID.
	 * @param int   $target_id Translated product ID.
	 * @param array $texts     Texts from the editor.
	 * @return void
	 */
	private static function apply_global( $source_id, $target_id, array $texts ) {
		$lang = linguator_get_post_language( $target_id );
		if ( ! $lang ) {
			return;
		}

		$original = self::build( $source_id );

		foreach ( $texts as $taxonomy => $entry ) {
			if ( ! is_array( $entry ) || 0 !== strpos( (string) $taxonomy, 'pa_' ) || ! isset( $original[ $taxonomy ] ) || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			if ( isset( $entry['label'], $original[ $taxonomy ]['label'] ) ) {
				self::save_label( $original[ $taxonomy ]['label'], trim( (string) $entry['label'] ), $lang );
			}
		}
	}

	/**
	 * Saves the translation of a global attribute name on the strings screen, when it has none yet.
	 *
	 * @since 2.3.0
	 *
	 * @param string $source Name in the original language.
	 * @param string $name   Translated name.
	 * @param string $lang   Target language slug.
	 * @return void
	 */
	private static function save_label( $source, $name, $lang ) {
		if ( '' === $name || $name === $source || Attribute_Labels::translate_text( $source, $lang ) !== $source ) {
			return;
		}

		$language = LMAT()->model->get_language( $lang );
		if ( ! $language ) {
			return;
		}

		$mo = new \Linguator\Includes\Helpers\Linguator_MO();
		$mo->import_from_db( $language );
		$mo->add_entry( $mo->make_entry( $source, $name ) );
		$mo->export_to_db( $language );
	}
}
