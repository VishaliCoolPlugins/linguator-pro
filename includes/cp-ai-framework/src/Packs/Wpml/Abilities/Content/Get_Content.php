<?php
/**
 * Post content ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Content_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Language_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Translation_Writer;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Context;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;
use AUTOMLP_PRO_WPML\Includes\Wpml\Job_Sender;
use WP_Error;
use WPML_AT_PRO_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns a post decomposed into WPML's translatable strings.
 *
 * @since 1.5.0
 */
final class Get_Content extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-content';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Content', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: read one post broken into the individual translatable strings WPML sees, the same set AutoMLP itself sends for translation. "contents" is keyed by WPML\'s own string names and each entry carries the text plus its HTML; those keys are what a translation has to be written back under, so keep them exactly as given and never invent or renumber one. With AutoMLP\'s background translation on (the default) they are the fields of the WPML translation job, such as "title", "body" or a "package-string-" key, and translate-post needs every one of them for a new translation. "editor_type" says which page builder holds the content. A page built with Elementor or Divi returns its text here even though its post_content looks empty, because the builder keeps the text in post meta instead.', 'wpml-translation-check-pro' );
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
					'description' => __( 'The ID of the post, page or custom post type item to read.', 'wpml-translation-check-pro' ),
				),
				'source_language' => array(
					'type'        => 'string',
					'description' => __( 'Language the post is written in. Optional: WPML resolves it from the post itself when omitted, which is almost always what you want.', 'wpml-translation-check-pro' ),
				),
			),
			'required'   => array( 'post_id' ),
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
	 * @return array<string, mixed>|WP_Error Post payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

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

		if ( ! Content_Service::is_post_type_translatable( $post->post_type ) ) {
			return new WP_Error(
				'automlp_type_not_translated',
				sprintf(
					/* translators: %s: post type slug. */
					__( 'WPML is not set to translate "%s", so this item has no language and cannot be decomposed into translatable strings. Call list-content-types and pass on its notice: a site administrator has to switch the type on under WPML > Settings > Post Types Translation. Do not retry until they confirm it is done.', 'wpml-translation-check-pro' ),
					$post->post_type
				)
			);
		}

		$source_language = isset( $input['source_language'] ) ? sanitize_text_field( (string) $input['source_language'] ) : '';

		if ( '' === $source_language && class_exists( 'WPML_AT_PRO_Helper' ) ) {
			$source_language = (string) WPML_AT_PRO_Helper::get_post_source_language( $post_id, $post->post_type );
		}

		if ( '' === $source_language ) {
			$source_language = Language_Service::get_default_code();
		}

		if ( Translation_Writer::uses_wpml_jobs() ) {
			return self::job_fields_payload( $post, $source_language );
		}

		$package = Wpml_Context::get_package( $post_id, $source_language );

		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$contents = $package['contents'];

		return array(
			'post_id'         => $post_id,
			'post_type'       => (string) $post->post_type,
			'post_status'     => (string) $post->post_status,
			'source_language' => $source_language,
			'editor_type'     => $package['editor_type'],
			'title'           => '' !== $package['title'] ? $package['title'] : (string) get_the_title( $post_id ),
			'excerpt'         => $package['excerpt'],
			'contents'        => $contents,
			'string_count'    => count( $contents ),
			'edit_link'       => html_entity_decode( (string) get_edit_post_link( $post_id, 'raw' ) ),
		);
	}

	/**
	 * The post as the fields of the WPML translation job translate-post saves.
	 *
	 * Uses the same WPML translation package the bulk flow sends, so every key
	 * is a WPML job field name (for example "title", "body" or a
	 * "package-string-" key for page-builder text) and translate-post can save
	 * the values back to exactly those fields.
	 *
	 * @since 1.6.1
	 * @param \WP_Post $post            Source post.
	 * @param string   $source_language Source language code.
	 * @return array<string, mixed>|WP_Error Post payload, or an error.
	 */
	private static function job_fields_payload( $post, $source_language ) {
		$fields = Job_Sender::package_fields( $post );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$contents = array();

		foreach ( $fields as $name => $text ) {
			$contents[ (string) $name ] = array(
				'text' => (string) $text,
				'html' => (string) $text,
			);
		}

		$post_id = (int) $post->ID;

		return array(
			'post_id'         => $post_id,
			'post_type'       => (string) $post->post_type,
			'post_status'     => (string) $post->post_status,
			'source_language' => $source_language,
			'editor_type'     => Translation_Writer::resolve_editor_type( $post_id, $post ),
			'title'           => isset( $fields['title'] ) ? (string) $fields['title'] : (string) get_the_title( $post_id ),
			'excerpt'         => isset( $fields['excerpt'] ) ? (string) $fields['excerpt'] : '',
			'contents'        => $contents,
			'string_count'    => count( $contents ),
			'edit_link'       => html_entity_decode( (string) get_edit_post_link( $post_id, 'raw' ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Checks the specific post rather than a blanket edit_posts, so a
	 * contributor cannot read another author's drafts or private posts.
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
