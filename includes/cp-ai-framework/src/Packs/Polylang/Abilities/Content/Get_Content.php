<?php
/**
 * Post content retrieval ability.
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
 * Returns a post with everything needed to translate it.
 *
 * @since 1.10.0
 */
final class Get_Content extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-content';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Content', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: get the full content of a post for translation, including title, body, excerpt, translatable meta fields, categories, tags, language and existing translations. Call this before translating. When the post is built with a page builder AutoPoly supports, editor_type names it and builder_storage says where the page lives: "meta" means builder_strings carries the copy to translate and the content field is omitted, "content" means the content field is what to translate. builder_strings carry text only, never link addresses: links in the layout are handled when translate-post saves the translation, which reports any it could not point at a translation. If a "glossary" field is present, those terms appear in this post and their translations are mandatory; apply them exactly.', 'autopoly-ai-translation-for-polylang-pro' );
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
				'post_id'         => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the post to retrieve.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Language slug you intend to translate into, for example "hi". Optional: it only narrows the returned glossary to that language. Omit it and every language is returned for each matching term.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
			),
			'required'   => array( 'post_id' ),
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
	 * @return array<string, mixed>|WP_Error Post payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$result  = Content_Service::get_post_content(
			$post_id,
			isset( $input['target_language'] ) ? (string) $input['target_language'] : ''
		);

		if ( null === $result ) {
			return new WP_Error(
				'atfpp_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d was not found.', 'autopoly-ai-translation-for-polylang-pro' ),
					$post_id
				)
			);
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Checks the specific post rather than a blanket edit_posts, so a contributor
	 * cannot read another author's drafts or private posts.
	 *
	 * @since 1.10.0
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
