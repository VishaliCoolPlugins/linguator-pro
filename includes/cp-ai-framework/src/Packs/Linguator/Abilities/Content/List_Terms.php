<?php
/**
 * List Terms ability.
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
 * List Terms.
 *
 * @since 1.3.0
 */
final class List_Terms extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'list-terms';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'List Terms', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: list the terms of a translated taxonomy (category by default), filtered by language and search term, with paging. Each item has the ID, name, slug, language, post count, parent and translations (language slug to term ID). Language "any" or omitted lists every language.', 'linguator-pro' );
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
				'taxonomy' => array(
					'type'        => 'string',
					'description' => __( 'A taxonomy Linguator translates, for example "category" or "post_tag". Defaults to "category".', 'linguator-pro' ),
				),
				'language' => array(
					'type'        => 'string',
					'description' => __( 'Language slug, for example "fr". "any" or omitted means all languages.', 'linguator-pro' ),
				),
				'search' => array(
					'type'        => 'string',
					'description' => __( 'Search term matched against term names.', 'linguator-pro' ),
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
		return Browse_Service::list_terms( $input );
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
