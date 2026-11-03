<?php
/**
 * Copy Post To Language ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Translation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copy Post To Language.
 *
 * @since 1.3.0
 */
final class Copy_Post_To_Language extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'copy-post-to-language';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Copy Post To Language', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: create a linked draft copy of a post in another language WITHOUT translating it: same title, content, excerpt, featured image and the custom fields Linguator copies; terms are mapped to their translations and the parent to its translation. Use it to prepare a draft to edit by hand. Refused when a translation already exists, unless overwrite is true, which replaces only the title, content and excerpt of that translation.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'source_post_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the post to copy.', 'linguator-pro' ),
				),
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Target language slug, for example "fr" or "de".', 'linguator-pro' ),
				),
				'overwrite' => array(
					'type'        => 'boolean',
					'description' => __( 'Replace the title, content and excerpt of an existing translation. Defaults to false.', 'linguator-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'source_post_id', 'target_language' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		return Translation_Service::copy_post( $input );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		$source_id = isset( $input['source_post_id'] ) ? absint( $input['source_post_id'] ) : 0;

		if ( 0 === $source_id || ! self::user_can( 'edit_post', $input, $source_id ) ) {
			return false;
		}

		$target = isset( $input['target_language'] ) ? sanitize_key( (string) $input['target_language'] ) : '';

		if ( '' === $target ) {
			return false;
		}

		$existing = (int) ( linguator_get_post_translations( $source_id )[ $target ] ?? 0 );

		// A link can outlive a deleted translation. That is "no translation", not "not allowed".
		return ! ( $existing > 0 && null !== get_post( $existing ) && ! self::user_can( 'edit_post', $input, $existing ) );
	}
}
