<?php
/**
 * Term retrieval ability.
 *
 * @package AutoPoly
 * @since   1.10.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Content;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Content_Service;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns a taxonomy term with everything needed to translate it.
 *
 * @since 1.10.0
 */
final class Get_Term extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-term';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Term', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: get a taxonomy term for translation, including name, slug, description, taxonomy, parent, translatable meta, language and existing translations.', 'autopoly-ai-translation-for-polylang-pro' );
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
				'term_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the term to retrieve.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
			),
			'required'   => array( 'term_id' ),
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
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error Term payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		$term_id = isset( $input['term_id'] ) ? absint( $input['term_id'] ) : 0;
		$result  = Content_Service::get_term_content( $term_id );

		if ( null === $result ) {
			return new WP_Error(
				'atfpp_not_found',
				sprintf(
					/* translators: %d: term ID. */
					__( 'Term %d was not found.', 'autopoly-ai-translation-for-polylang-pro' ),
					$term_id
				)
			);
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		$term_id = isset( $input['term_id'] ) ? absint( $input['term_id'] ) : 0;

		if ( 0 === $term_id ) {
			return self::user_can( 'edit_posts', $input );
		}

		return self::user_can( 'edit_term', $input, $term_id );
	}
}
