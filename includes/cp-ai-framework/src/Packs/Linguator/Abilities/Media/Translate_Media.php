<?php
/**
 * Translate Media ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Media;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Media_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translate Media.
 *
 * @since 1.3.0
 */
final class Translate_Media extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'translate-media';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Translate Media', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: create the translation of a media library item in the target language, or update it when it exists. The translation shares the file, gets its own title, caption, description and alt text, is linked to the original and has the target language. Needs Linguator\'s media support setting; when it is off an error explains how to switch it on. Fields you omit keep the original\'s text on create and stay untouched on update.', 'linguator-pro' );
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
				'source_media_id' => array(
					'type'        => 'integer',
					'description' => __( 'The attachment ID of the original media item.', 'linguator-pro' ),
				),
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Target language slug, for example "fr".', 'linguator-pro' ),
				),
				'translated_title' => array(
					'type'        => 'string',
					'description' => __( 'The translated media title.', 'linguator-pro' ),
				),
				'translated_caption' => array(
					'type'        => 'string',
					'description' => __( 'The translated caption (stored as the attachment excerpt).', 'linguator-pro' ),
				),
				'translated_description' => array(
					'type'        => 'string',
					'description' => __( 'The translated description (stored as the attachment content).', 'linguator-pro' ),
				),
				'translated_alt_text' => array(
					'type'        => 'string',
					'description' => __( 'The translated alternative text.', 'linguator-pro' ),
				),
			),
			'required'   => array( 'source_media_id', 'target_language' ),
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
				'idempotent'  => true,
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
		return Media_Service::translate( $input );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		$source_id = isset( $input['source_media_id'] ) ? absint( $input['source_media_id'] ) : 0;

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
