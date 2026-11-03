<?php
/**
 * Term translation ability.
 *
 * @package AutoPoly
 * @since   1.10.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Translation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates or updates a translated taxonomy term.
 *
 * @since 1.10.0
 */
final class Translate_Term extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'translate-term';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Translate Term', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: create or update a translated version of a taxonomy term such as a category or tag. An existing translation in the target language is updated in place, and the translation is linked to the source term automatically.', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'source_term_id'         => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the source term to translate from.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'target_language'        => array(
					'type'        => 'string',
					'description' => __( 'Target language slug, for example "fr" or "de".', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'translated_name'        => array(
					'type'        => 'string',
					'description' => __( 'The translated term name.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'translated_description' => array(
					'type'        => 'string',
					'description' => __( 'The translated term description. Omit to leave an existing description untouched.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'translated_slug'        => array(
					'type'        => 'string',
					'description' => __( 'The translated URL slug. The saved slug rule of the site decides how it is used: "slug_translate" uses it, "title_translate" and "slug_keep" ignore it and say so in slug_notice (see slug_translation in get-translation-settings). Omit it when updating to leave the existing address untouched.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
			),
			'required'   => array( 'source_term_id', 'target_language', 'translated_name' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
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
	 * @since 1.10.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		return Translation_Service::translate_term( $input );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		$source_id = isset( $input['source_term_id'] ) ? absint( $input['source_term_id'] ) : 0;

		if ( 0 === $source_id ) {
			return false;
		}

		if ( ! self::user_can( 'edit_term', $input, $source_id ) ) {
			return false;
		}

		$term = get_term( $source_id );

		if ( null === $term || is_wp_error( $term ) ) {
			return false;
		}

		$taxonomy = get_taxonomy( $term->taxonomy );

		if ( false === $taxonomy ) {
			return false;
		}

		return self::user_can( $taxonomy->cap->edit_terms, $input );
	}
}
