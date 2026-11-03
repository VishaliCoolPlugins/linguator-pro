<?php
/**
 * Translate Term ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Translation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translate Term.
 *
 * @since 1.2.0
 */
final class Translate_Term extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'translate-term';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Translate Term', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: create the translation of a term in the target language, or update it when one exists, and link it to the original. The parent term is mapped to its translation.', 'linguator-pro' );
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
				'source_term_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the source term to translate from.', 'linguator-pro' ),
				),
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Target language slug, for example "fr".', 'linguator-pro' ),
				),
				'translated_name' => array(
					'type'        => 'string',
					'description' => __( 'The translated term name. Required when creating a translation.', 'linguator-pro' ),
				),
				'translated_description' => array(
					'type'        => 'string',
					'description' => __( 'The translated term description. Omit to leave it untouched.', 'linguator-pro' ),
				),
				'translated_slug' => array(
					'type'        => 'string',
					'description' => __( 'The translated slug. Omit to let WordPress derive one from the name.', 'linguator-pro' ),
				),
			),
			'required'   => array( 'source_term_id', 'target_language' ),
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
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		return Translation_Service::translate_term( $input );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		$source_id = isset( $input['source_term_id'] ) ? absint( $input['source_term_id'] ) : 0;

		return 0 !== $source_id && self::user_can( 'edit_term', $input, $source_id );
	}
}
