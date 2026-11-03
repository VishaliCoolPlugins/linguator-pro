<?php
/**
 * Translate Post ability.
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
 * Translate Post.
 *
 * @since 1.2.0
 */
final class Translate_Post extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'translate-post';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Translate Post', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: create the translation of a post in the target language, or update it when one exists, and link it to the original. The featured image and terms of the original are carried over (terms through their translations). Call get-content first. Only meta keys listed by get-translation-settings are saved; the rest are reported in meta_skipped.', 'linguator-pro' );
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
				'source_post_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the source post to translate from.', 'linguator-pro' ),
				),
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Target language slug, for example "fr" or "de".', 'linguator-pro' ),
				),
				'translated_title' => array(
					'type'        => 'string',
					'description' => __( 'The translated post title. Required when creating a translation; omit it on update to keep the current title.', 'linguator-pro' ),
				),
				'translated_content' => array(
					'type'        => 'string',
					'description' => __( 'The translated post content as HTML. Block comment delimiters are preserved. Required when creating a translation; omit it on update to keep the current content.', 'linguator-pro' ),
				),
				'translated_excerpt' => array(
					'type'        => 'string',
					'description' => __( 'The translated post excerpt. Omit to leave an existing excerpt untouched.', 'linguator-pro' ),
				),
				'translated_slug' => array(
					'type'        => 'string',
					'description' => __( 'The translated URL slug. Omit it to let WordPress derive one from the title, or to leave an existing address untouched.', 'linguator-pro' ),
				),
				'translated_meta' => array(
					'type'        => 'object',
					'description' => __( 'Key-value pairs of translated meta fields. Keys that Linguator does not translate are rejected and reported in meta_skipped.', 'linguator-pro' ),
				),
				'status' => array(
					'type'        => 'string',
					'description' => __( 'Post status for the translation. Downgraded to draft when the current user cannot publish.', 'linguator-pro' ),
					'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
					'default'     => 'publish',
				),
			),
			'required'   => array( 'source_post_id', 'target_language' ),
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
		return Translation_Service::translate_post( $input );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
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
