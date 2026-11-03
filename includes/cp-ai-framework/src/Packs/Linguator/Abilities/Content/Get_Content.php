<?php
/**
 * Get Content ability.
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
 * Get Content.
 *
 * @since 1.2.0
 */
final class Get_Content extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-content';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Content', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: get the full content of a post for translation: title, body, excerpt, slug, translatable meta fields, terms with their translation in the target language, language and existing translations. Call this before translate-post. editor_type names the editor; when builder_storage is "meta" the page layout lives outside post_content and translate-post cannot translate it. If a "glossary" field is present, those terms appear in this post and their translations are mandatory; apply them exactly.', 'linguator-pro' );
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
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the post to retrieve.', 'linguator-pro' ),
				),
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Language slug you intend to translate into, for example "hi". Optional: it adds each term\'s translation_id for that language and the existing translation of the post.', 'linguator-pro' ),
				),
			),
			'required'   => array( 'post_id' ),
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
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$result  = Content_Service::get_post_content( $post_id, isset( $input['target_language'] ) ? (string) $input['target_language'] : '' );

		if ( null === $result ) {
			return new \WP_Error(
				'lmat_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d was not found.', 'linguator-pro' ),
					$post_id
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
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		return 0 === $post_id ? self::user_can( 'edit_posts', $input ) : self::user_can( 'edit_post', $input, $post_id );
	}
}
