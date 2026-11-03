<?php
/**
 * Translate post ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Translation_Writer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes a translation of a post from translations the caller supplies.
 *
 * The caller is the translator here. It reads the strings with get-content,
 * translates them itself, and passes them back under the same keys. That is
 * deliberately different from AutoMLP's own bulk screen, which sends strings to
 * OpenAI or Gemini: an assistant calling this already has a language model, so
 * routing the text through a second, smaller one would cost more and translate
 * worse. It also means this works with no API key configured.
 *
 * @since 1.5.0
 */
final class Translate_Post extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'translate-post';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Translate Post', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: create or update the translation of one post, using translations you supply. Call get-content first, translate the values it returns yourself, then pass them back in "contents" under exactly the keys it gave you: those keys are how WPML puts each string back into the right place, so never invent, rename or renumber one, and omit a key rather than guessing it. Keep every HTML tag that was in the source string. The translation is saved the same way AutoMLP\'s own bulk translation saves it, through a WPML translation job, so it is linked to the source as a proper WPML translation and the job is marked complete. A new translation needs every key get-content returned; when updating, keys you leave out keep their current translation. Calling it again for the same language updates that translation instead of creating a second one, and a call that carries only post_id, target_language and post_status changes just the status of the existing translation (the way to publish a draft). Read outcome, summary and next_action in the result. If there is no linked translation but an unlinked post in the target language looks like one, nothing is written and the error lists it, so a duplicate is not created by accident. New translations get the status from WPML\'s translated document status setting (draft, or the same as the source) unless post_status is passed here. For an Elementor/Divi/Cornerstone translation, call rebuild-elementor-data afterward so the page builder recognizes the new content correctly.', 'wpml-translation-check-pro' );
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
				'post_id'         => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the source post to translate. Pass the original, not an existing translation.', 'wpml-translation-check-pro' ),
				),
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Language code to write, for example "es". Must be one of the codes from list-languages, and not the post\'s own language.', 'wpml-translation-check-pro' ),
				),
				'title'           => array(
					'type'        => 'string',
					'description' => __( 'Translated post title.', 'wpml-translation-check-pro' ),
				),
				'excerpt'         => array(
					'type'        => 'string',
					'description' => __( 'Translated excerpt. Omit when the source has none.', 'wpml-translation-check-pro' ),
				),
				'contents'        => array(
					'type'        => 'object',
					'description' => __( 'Translated strings as an object keyed exactly as get-content returned them, each value the translated text. Required for page-builder content, where the text does not live in the post body.', 'wpml-translation-check-pro' ),
				),
				'post_name'       => array(
					'type'        => 'string',
					'description' => __( 'Translated slug. Omit to let WPML decide from its own "translated document slug" setting, which get-translation-settings reports. It is only applied when that setting is "translate"; otherwise it is left out and the result says so.', 'wpml-translation-check-pro' ),
				),
				'editor_type'     => array(
					'type'        => 'string',
					'description' => __( 'Override the detected page builder. Omit this: AutoMLP resolves it from WPML\'s own string package, which is more reliable than guessing.', 'wpml-translation-check-pro' ),
				),
				'post_status'     => array(
					'type'        => 'string',
					'description' => __( 'Status to set the translation to, e.g. "publish". Applied after writing the translation, whether this call created it or updated an existing one. Omit to let WPML\'s translated document status setting decide for new translations and leave existing ones unchanged. Publishing needs the publish capability for this post type.', 'wpml-translation-check-pro' ),
					'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
				),
				'skip_duplicate_check' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Leave false. When the post has no linked translation yet, the call stops if an unlinked post in the target language looks like one, and lists it. Pass true only after the user has confirmed none of the listed posts is a version of this one.', 'wpml-translation-check-pro' ),
				),
			),
			'required'   => array( 'post_id', 'target_language' ),
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
	 * Writes data, and is idempotent: a second call for the same language
	 * updates the existing translation rather than creating a duplicate.
	 *
	 * @since 1.5.0
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
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		// Core validates the schema but does not coerce, so cast defensively.
		$contents = isset( $input['contents'] ) ? $input['contents'] : array();

		if ( is_object( $contents ) ) {
			$contents = (array) $contents;
		}

		return Translation_Writer::write(
			array(
				'post_id'         => isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0,
				'target_language' => isset( $input['target_language'] ) ? (string) $input['target_language'] : '',
				'title'           => isset( $input['title'] ) ? (string) $input['title'] : '',
				'excerpt'         => isset( $input['excerpt'] ) ? (string) $input['excerpt'] : '',
				'contents'        => is_array( $contents ) ? $contents : array(),
				'post_name'       => isset( $input['post_name'] ) ? (string) $input['post_name'] : '',
				'editor_type'     => isset( $input['editor_type'] ) ? (string) $input['editor_type'] : '',
				'post_status'     => isset( $input['post_status'] ) ? (string) $input['post_status'] : '',
				'skip_duplicate_check' => ! empty( $input['skip_duplicate_check'] ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Checked against the specific source post, not a blanket edit_posts.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( 0 === $post_id ) {
			return self::user_can( 'edit_posts', $input );
		}

		return self::user_can( 'edit_post', $input, $post_id );
	}
}
