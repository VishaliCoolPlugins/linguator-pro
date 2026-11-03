<?php
/**
 * Get Post Translations ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Content;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Browse_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Post Translations.
 *
 * @since 1.3.0
 */
final class Get_Post_Translations extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-post-translations';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Post Translations', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: for a post (post_id) or up to 50 posts (post_ids), return the language and the translations in every language with ID, status, title and edit URL, plus the languages that are still missing. Posts you cannot edit are reported with an error entry.', 'linguator-pro' );
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
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'A single post ID.', 'linguator-pro' ),
				),
				'post_ids' => array(
					'type'        => 'array',
					'description' => __( 'Up to 50 post IDs.', 'linguator-pro' ),
					'items'       => array( 'type' => 'integer' ),
					'maxItems'    => 50,
				),
			),
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
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		$ids = isset( $input['post_ids'] ) && is_array( $input['post_ids'] ) ? $input['post_ids'] : array();

		if ( isset( $input['post_id'] ) ) {
			$ids[] = $input['post_id'];
		}

		return Browse_Service::get_post_translations( array_map( 'absint', $ids ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'edit_posts', $input );
	}
}
