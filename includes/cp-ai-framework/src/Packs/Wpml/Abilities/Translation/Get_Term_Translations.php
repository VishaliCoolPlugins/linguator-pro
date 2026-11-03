<?php
/**
 * Term translation group ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Translation_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the WPML translation group for a taxonomy term.
 *
 * @since 1.5.0
 */
final class Get_Term_Translations extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-term-translations';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Term Translations', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: read every language version of one taxonomy term, such as a category or tag. Returns each language with its term ID, name, slug and post count, plus "missing_languages" for the active languages that have no version yet. Both a term_id and its taxonomy are required, because the same numeric id can exist in more than one taxonomy. If the taxonomy is switched off for translation in WPML its terms have no language at all, and the response says so rather than returning an empty group.', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'term_id'  => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the term to inspect.', 'wpml-translation-check-pro' ),
				),
				'taxonomy' => array(
					'type'        => 'string',
					'description' => __( 'The taxonomy the term belongs to, for example "category" or "product_cat". Call list-content-types for the taxonomies on this site.', 'wpml-translation-check-pro' ),
				),
			),
			'required'   => array( 'term_id', 'taxonomy' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error Translation group, or an error.
	 */
	public static function execute( array $input = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$term_id  = isset( $input['term_id'] ) ? absint( $input['term_id'] ) : 0;
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';

		if ( '' !== $taxonomy && ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'automlp_taxonomy_not_found',
				sprintf(
					/* translators: %s: taxonomy slug. */
					__( 'The taxonomy "%s" does not exist on this site. Call list-content-types for the taxonomies that do.', 'wpml-translation-check-pro' ),
					$taxonomy
				)
			);
		}

		if ( '' !== $taxonomy && ! (bool) apply_filters( 'wpml_is_translated_taxonomy', false, $taxonomy ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			return new WP_Error(
				'automlp_taxonomy_not_translated',
				sprintf(
					/* translators: %s: taxonomy slug. */
					__( 'WPML does not translate the taxonomy "%s", so its terms have no language and no translation group. Tell the user a site administrator has to switch it on under WPML > Settings > Taxonomies Translation and save. Do not retry until they confirm it is done.', 'wpml-translation-check-pro' ),
					$taxonomy
				)
			);
		}

		$result = Translation_Service::get_term_translations( $term_id, $taxonomy );

		if ( null === $result ) {
			return new WP_Error(
				'automlp_not_found',
				sprintf(
					/* translators: 1: term ID, 2: taxonomy slug. */
					__( 'Term %1$d was not found in the taxonomy "%2$s".', 'wpml-translation-check-pro' ),
					$term_id,
					$taxonomy
				)
			);
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Term reads are gated on the taxonomy's own edit capability when it is
	 * known, so a taxonomy restricted to administrators stays restricted.
	 *
	 * @since 1.5.0
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
