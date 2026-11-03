<?php
/**
 * Get Term ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Content;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Content_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Term.
 *
 * @since 1.2.0
 */
final class Get_Term extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-term';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Term', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: get a term (category, tag or other translated taxonomy term) for translation: name, slug, description, parent, language and existing translations.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'term_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the term to retrieve.', 'linguator-pro' ),
				),
			),
			'required'   => array( 'term_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		$term_id = isset( $input['term_id'] ) ? absint( $input['term_id'] ) : 0;
		$result  = Content_Service::get_term_content( $term_id );

		if ( null === $result ) {
			return new \WP_Error(
				'lmat_not_found',
				sprintf(
					/* translators: %d: term ID. */
					__( 'Term %d was not found.', 'linguator-pro' ),
					$term_id
				)
			);
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		$term_id = isset( $input['term_id'] ) ? absint( $input['term_id'] ) : 0;

		return 0 === $term_id ? self::user_can( 'edit_posts', $input ) : self::user_can( 'edit_term', $input, $term_id );
	}
}
