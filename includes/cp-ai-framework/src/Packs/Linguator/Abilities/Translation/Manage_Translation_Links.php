<?php
/**
 * Manage Translation Links ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Translation_Link_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Translation Links.
 *
 * @since 1.2.0
 */
final class Manage_Translation_Links extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'manage-translation-links';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Manage Translation Links', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: read or change how posts and terms are linked as translations. Actions: "get" (id), "link" (translations: language slug => object ID, at least two), "unlink" (id) and "assign_language" (ids and language, for content that has no language).', 'linguator-pro' );
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
				'action' => array(
					'type'        => 'string',
					'description' => __( 'One of get, link, unlink, assign_language.', 'linguator-pro' ),
					'enum'        => array( 'get', 'link', 'unlink', 'assign_language' ),
				),
				'object_type' => array(
					'type'        => 'string',
					'description' => __( '"post" (default) or "term".', 'linguator-pro' ),
					'enum'        => array( 'post', 'term' ),
					'default'     => 'post',
				),
				'id' => array(
					'type'        => 'integer',
					'description' => __( 'Object ID for get and unlink.', 'linguator-pro' ),
				),
				'ids' => array(
					'type'        => 'array',
					'description' => __( 'Object IDs for assign_language.', 'linguator-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'language' => array(
					'type'        => 'string',
					'description' => __( 'Language slug for assign_language.', 'linguator-pro' ),
				),
				'translations' => array(
					'type'        => 'object',
					'description' => __( 'Language slug => object ID, for link.', 'linguator-pro' ),
				),
			),
			'required'   => array( 'action' ),
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
		return Translation_Link_Service::run( $input );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		$is_term = isset( $input['object_type'] ) && 'term' === $input['object_type'];

		return self::user_can( $is_term ? 'manage_categories' : 'edit_posts', $input );
	}
}
