<?php
/**
 * Ensure translation ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Translation_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Translation_Writer;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;
use WP_Error;
use WPML_AT_PRO_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates an untranslated placeholder in a target language, linked to the source.
 *
 * Useful when the structure needs to exist before the text does: a menu that
 * has to resolve, or a batch of pages to be translated later. The placeholder
 * is a copy of the source, so it is readable rather than blank, but it is not a
 * translation of anything yet.
 *
 * @since 1.5.0
 */
final class Ensure_Translation extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'ensure-translation';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Ensure Translation', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: make sure a post has a version in a target language, without translating it. If a linked translation already exists this changes nothing and returns it, so it is safe to call repeatedly. If none exists, it creates one as a copy of the source, linked to it as a proper WPML translation. The copy is in the source language until something translates it: use this only when the structure has to exist before the text does, and prefer translate-post when you are ready to write real translations. Returns "created" so you can tell which happened.', 'wpml-translation-check-pro' );
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
					'description' => __( 'The ID of the source post. Pass the original, not an existing translation.', 'wpml-translation-check-pro' ),
				),
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Language code the version should exist in, for example "es". Must be one of the codes from list-languages.', 'wpml-translation-check-pro' ),
				),
				'skip_duplicate_check' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Leave false. When no linked version exists, the call stops if an unlinked post in the target language looks like one, and lists it. Pass true only after the user has confirmed none of the listed posts is a version of this one.', 'wpml-translation-check-pro' ),
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
	 * Writes data, but reuses an existing translation rather than duplicating.
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
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$post_id         = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$target_language = isset( $input['target_language'] ) ? sanitize_text_field( (string) $input['target_language'] ) : '';

		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return new WP_Error(
				'automlp_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d was not found.', 'wpml-translation-check-pro' ),
					$post_id
				)
			);
		}

		// Reuse before creating: this is what makes the ability idempotent.
		$existing = class_exists( 'WPML_AT_PRO_Helper' )
			? absint( WPML_AT_PRO_Helper::get_existing_translation_id( $post_id, (string) $post->post_type, $target_language ) )
			: 0;

		if ( $existing > 0 ) {
			return array(
				'source_post_id'     => $post_id,
				'translated_post_id' => $existing,
				'created'            => false,
				'target_language'    => $target_language,
				'title'              => (string) get_the_title( $existing ),
				'post_status'        => (string) get_post_status( $existing ),
				'edit_link'          => html_entity_decode( (string) get_edit_post_link( $existing, 'raw' ) ),
				'group'              => Translation_Service::get_post_translations( $existing ),
				'message'            => __( 'A linked translation already existed, so nothing was created.', 'wpml-translation-check-pro' ),
			);
		}

		/*
		 * The writer refuses a call with nothing to write, so the source title
		 * is passed through. That is what makes the new post a readable copy
		 * rather than an empty shell.
		 */
		$result = Translation_Writer::write(
			array(
				'post_id'         => $post_id,
				'target_language' => $target_language,
				'title'           => (string) get_post_field( 'post_title', $post_id, 'raw' ),
				'excerpt'         => (string) get_post_field( 'post_excerpt', $post_id, 'raw' ),
				'contents'        => array(),
				'copy_source'     => true,
				'skip_duplicate_check' => ! empty( $input['skip_duplicate_check'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = __( 'A linked version was created as a copy of the source. Its text is still in the source language; call translate-post to translate it.', 'wpml-translation-check-pro' );

		return $result;
	}

	/**
	 * {@inheritDoc}
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
