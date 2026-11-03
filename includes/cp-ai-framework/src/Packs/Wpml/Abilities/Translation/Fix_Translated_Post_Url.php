<?php
/**
 * Translated post URL fix ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Url_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixes a translated post's slug and/or category so its URL does not keep
 * source-language parts. A newly created translation inherits its source's
 * slug and categories verbatim, and nothing in WPML's translation flow
 * changes them afterward: an "es" post can end up published at a URL that
 * still reads like English, or filed under an English category.
 *
 * @since 1.5.0
 */
final class Fix_Translated_Post_Url extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'fix-translated-post-url';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Fix Translated Post URL', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: fix a translated post\'s slug and/or category so its URL stops carrying source-language parts. A newly created translation keeps its source post\'s slug and categories exactly as they were; this changes them for one post at a time. category_ids and primary_category_id only apply to the "post" post type, since pages have no categories. Pass the translated post\'s own ID, not the source post\'s ID. The change is applied inside that post\'s own WPML language context, so a category passed here must already be the target-language version of the category, not the source one.', 'wpml-translation-check-pro' );
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
				'post_id'              => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the translated post to fix. This must be the translation, not the source-language original.', 'wpml-translation-check-pro' ),
				),
				'slug'                 => array(
					'type'        => 'string',
					'description' => __( 'New slug for this post, in the target language. Omit to leave the slug unchanged.', 'wpml-translation-check-pro' ),
				),
				'category_ids'         => array(
					'type'        => 'array',
					'description' => __( 'Target-language category term IDs to set on this post, replacing its current categories. Post type "post" only.', 'wpml-translation-check-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'primary_category_id'  => array(
					'type'        => 'integer',
					'description' => __( 'A target-language category term ID to mark as this post\'s primary category for Yoast SEO and Rank Math, in addition to whatever category_ids sets. Post type "post" only.', 'wpml-translation-check-pro' ),
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
	 * @return array<string, mixed> Ability meta, overridden because this writes data.
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
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	public static function execute( array $input = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( 0 === $post_id ) {
			return new WP_Error(
				'automlp_invalid_post',
				__( 'A post_id is required.', 'wpml-translation-check-pro' )
			);
		}

		return Url_Service::update( $post_id, $input );
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
