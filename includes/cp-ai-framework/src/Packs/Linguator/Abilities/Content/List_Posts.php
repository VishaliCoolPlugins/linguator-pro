<?php
/**
 * List Posts ability.
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
 * List Posts.
 *
 * @since 1.3.0
 */
final class List_Posts extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'list-posts';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'List Posts', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: list posts of the translated post types, filtered by post type, language, status and search term, with paging. Each item has the ID, title, type, status, language, modified date, translations (language slug to post ID) and edit URL. Language "any" or omitted lists every language. Use total and has_more to page with offset.', 'linguator-pro' );
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
				'post_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter to one translated post type, for example "post" or "page". Omit for all translated types.', 'linguator-pro' ),
				),
				'language' => array(
					'type'        => 'string',
					'description' => __( 'Language slug, for example "fr". "any" or omitted means all languages.', 'linguator-pro' ),
				),
				'status' => array(
					'type'        => 'string',
					'description' => __( 'Post status: any (default), publish, draft, pending, private, future or trash.', 'linguator-pro' ),
					'enum'        => Browse_Service::LIST_STATUSES,
					'default'     => 'any',
				),
				'search' => array(
					'type'        => 'string',
					'description' => __( 'Search term matched against title and content.', 'linguator-pro' ),
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum items to return. Defaults to 20, maximum 100.', 'linguator-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'offset' => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to skip for pagination. Defaults to 0.', 'linguator-pro' ),
					'default'     => 0,
					'minimum'     => 0,
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
		return Browse_Service::list_posts( $input );
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
