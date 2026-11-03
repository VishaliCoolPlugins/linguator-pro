<?php
/**
 * Translate term ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.6.2
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Translation_Writer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes a translation of a taxonomy term from translations the caller supplies.
 *
 * Mirrors translate-post: the caller reads the source name with list-terms,
 * translates it itself and passes it back. The term is saved through the same
 * queue row and WPML writer AutoMLP's own bulk screen uses for terms.
 *
 * @since 1.6.2
 */
final class Translate_Term extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.6.2
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'translate-term';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.6.2
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Translate Term', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.6.2
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: create the translation of one taxonomy term, such as a category or tag, using a translation you supply. Read the source name (and description, when list-terms returns one) with list-terms, translate it yourself, then pass it back as "name" (and "description"). The term is saved the same way AutoMLP\'s own bulk translation saves terms, so WPML links it to the source and keeps the parent relationship. A term that already has a translation in that language is left unchanged and the call returns an error naming it. Do this before translating the posts that use the term, so those posts point to translated terms. Confirm with get-term-translations.', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.6.2
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'term_id'         => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the source term to translate. Pass the original, not an existing translation.', 'wpml-translation-check-pro' ),
				),
				'taxonomy'        => array(
					'type'        => 'string',
					'description' => __( 'The taxonomy the term belongs to, for example "category" or "product_cat". Call list-content-types for the taxonomies on this site.', 'wpml-translation-check-pro' ),
				),
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Language code to write, for example "es". Must be one of the codes from list-languages, and not the term\'s own language.', 'wpml-translation-check-pro' ),
				),
				'name'            => array(
					'type'        => 'string',
					'description' => __( 'Translated term name.', 'wpml-translation-check-pro' ),
				),
				'description'     => array(
					'type'        => 'string',
					'description' => __( 'Translated term description. Omit when the source term has none.', 'wpml-translation-check-pro' ),
				),
			),
			'required'   => array( 'term_id', 'taxonomy', 'target_language', 'name' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.6.2
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Writes data. Not destructive: an existing translation is never overwritten.
	 *
	 * @since 1.6.2
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.6.2
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		return Translation_Writer::write_term(
			array(
				'term_id'         => isset( $input['term_id'] ) ? absint( $input['term_id'] ) : 0,
				'taxonomy'        => isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '',
				'target_language' => isset( $input['target_language'] ) ? (string) $input['target_language'] : '',
				'name'            => isset( $input['name'] ) ? (string) $input['name'] : '',
				'description'     => isset( $input['description'] ) ? (string) $input['description'] : '',
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Gated on the taxonomy's own edit capability when it is known, so a
	 * taxonomy restricted to administrators stays restricted.
	 *
	 * @since 1.6.2
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';

		if ( '' !== $taxonomy ) {
			$object = get_taxonomy( $taxonomy );

			if ( $object && isset( $object->cap->edit_terms ) ) {
				return self::user_can( (string) $object->cap->edit_terms, $input );
			}
		}

		return self::user_can( 'edit_posts', $input );
	}
}
