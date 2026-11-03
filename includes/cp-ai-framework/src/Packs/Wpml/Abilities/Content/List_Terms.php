<?php
/**
 * List terms ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Content_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists taxonomy terms in an explicit language.
 *
 * @since 1.5.0
 */
final class List_Terms extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'list-terms';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'List Terms', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: list the terms of one taxonomy that exist in one specific language, such as the Spanish categories. The language is explicit rather than inherited, because WPML filters term queries by the current language. Empty terms are included, since an untranslated category often has no posts yet. If the taxonomy is switched off for translation in WPML its terms have no language at all, and the response explains that rather than returning an empty list.', 'wpml-translation-check-pro' );
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
				'language' => array(
					'type'        => 'string',
					'description' => __( 'Language code to list in, for example "es". Must be one of the codes from list-languages.', 'wpml-translation-check-pro' ),
				),
				'taxonomy' => array(
					'type'        => 'string',
					'description' => __( 'Taxonomy to list, for example "category" or "product_cat". Call list-content-types for the taxonomies on this site.', 'wpml-translation-check-pro' ),
				),
				'limit'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum items to return. Defaults to 20, maximum 100.', 'wpml-translation-check-pro' ),
					'default'     => Content_Service::DEFAULT_LIMIT,
					'minimum'     => 1,
					'maximum'     => Content_Service::MAX_LIMIT,
				),
				'offset'   => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to skip for pagination. Defaults to 0.', 'wpml-translation-check-pro' ),
					'default'     => 0,
					'minimum'     => 0,
				),
			),
			'required'   => array( 'language', 'taxonomy' ),
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
	 * @return array<string, mixed>|\WP_Error Items, total and a has_more flag.
	 */
	public static function execute( array $input = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		// Core validates the schema but does not coerce, so cast defensively.
		$language = isset( $input['language'] ) ? sanitize_text_field( (string) $input['language'] ) : '';
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$limit    = isset( $input['limit'] ) ? (int) $input['limit'] : Content_Service::DEFAULT_LIMIT;
		$offset   = isset( $input['offset'] ) ? (int) $input['offset'] : 0;

		return Content_Service::list_terms( $language, $taxonomy, $limit, $offset );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Gated on the taxonomy's own capability when it is known, so a taxonomy
	 * restricted to administrators stays restricted.
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
