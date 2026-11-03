<?php
/**
 * Out-of-date translation listing ability.
 *
 * @package CoolPluginsAiFramework
 * @since 1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Content;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Outdated_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists translations whose source was edited after they were translated.
 *
 * @since 1.2.0
 */
final class Get_Outdated_Translations extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-outdated-translations';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Outdated Translations', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: list translations that are out of date because their source post (default language) was edited after the translation was last up to date. Linguator keeps no re-translation log, so this compares the time of the last real text change of the source with that of its translation, using the change tracking of Linguator Pro\'s scan module. Each item gives the translation, its source, and which parts of the source changed where that can be determined: title, content, excerpt (custom fields are not compared, so they are reported as null = unknown). To update one, call get-content on the source, then translate-post for the item\'s language; saving the translation makes it newer than its source and removes it from this list. Posts of translated post types only; terms are not tracked. The only thing written is a change-time stamp on posts seen for the first time.', 'linguator-pro' );
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
				'language'       => array(
					'type'        => 'string',
					'description' => __( 'Only translations in this language slug, for example "fr".', 'linguator-pro' ),
				),
				'post_type'      => array(
					'type'        => 'string',
					'description' => __( 'Only this post type, for example "page". Omit for every translated post type.', 'linguator-pro' ),
				),
				'source_post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Only translations of this source post.', 'linguator-pro' ),
				),
				'limit'          => array(
					'type'        => 'integer',
					'default'     => Outdated_Service::DEFAULT_LIMIT,
					'minimum'     => 1,
					'maximum'     => Outdated_Service::MAX_LIMIT,
					'description' => __( 'Tracked translations to check in this call. Defaults to 20, maximum 50.', 'linguator-pro' ),
				),
				'offset'         => array(
					'type'        => 'integer',
					'default'     => 0,
					'minimum'     => 0,
					'description' => __( 'Where to continue from. Pass next_offset from the previous call.', 'linguator-pro' ),
				),
			),
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
	 * Not read-only: the first time a post is looked at its change time is stamped on it.
	 * That is the only thing written, and repeating a call is safe.
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
	 * @return array<string, mixed>|\WP_Error Report, or an error.
	 */
	public static function execute( array $input = array() ) {
		$can_edit = static function ( $post_id ) use ( $input ) {
			return self::user_can( 'edit_post', $input, (int) $post_id );
		};

		return Outdated_Service::find_outdated(
			array(
				'language'       => isset( $input['language'] ) ? (string) $input['language'] : '',
				'post_type'      => isset( $input['post_type'] ) ? (string) $input['post_type'] : '',
				'source_post_id' => isset( $input['source_post_id'] ) ? absint( $input['source_post_id'] ) : 0,
				'limit'          => isset( $input['limit'] ) ? (int) $input['limit'] : Outdated_Service::DEFAULT_LIMIT,
				'offset'         => isset( $input['offset'] ) ? (int) $input['offset'] : 0,
			),
			$can_edit
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Listing needs edit_posts; each item is then checked individually, and a
	 * source filter requires edit rights on that source.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		if ( ! self::user_can( 'edit_posts', $input ) ) {
			return false;
		}

		$source_id = isset( $input['source_post_id'] ) ? absint( $input['source_post_id'] ) : 0;

		return 0 === $source_id || self::user_can( 'edit_post', $input, $source_id );
	}
}
