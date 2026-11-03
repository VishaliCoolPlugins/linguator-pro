<?php
/**
 * Get Untranslated Content ability.
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
 * Get Untranslated Content.
 *
 * @since 1.2.0
 */
final class Get_Untranslated_Content extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-untranslated-content';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Untranslated Content', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: get a paginated list of default-language content missing a translation in the given target language. Returns the post ID, title, type, status, source language and modified date. Pass language_state "no_language" to list content that has no language at all instead: it cannot be translated until it is given one with manage-translation-links, action "assign_language".', 'linguator-pro' );
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
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Language slug to find missing translations for, for example "fr" or "de".', 'linguator-pro' ),
				),
				'content_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter to one post type. Omit for all translated types. A type Linguator does not translate returns no items and a "notice" explaining how to switch it on; pass that on rather than reporting the type as fully translated.', 'linguator-pro' ),
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
				'language_state' => array(
					'type'        => 'string',
					'description' => __( '"missing_translation", the default, lists content with no translation in target_language. "no_language" lists content without a language.', 'linguator-pro' ),
					'enum'        => Content_Service::LANGUAGE_STATES,
					'default'     => 'missing_translation',
				),
			),
			'required'   => array( 'target_language' ),
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
		$target = isset( $input['target_language'] ) ? sanitize_key( (string) $input['target_language'] ) : '';
		$type   = isset( $input['content_type'] ) ? sanitize_key( (string) $input['content_type'] ) : null;
		$state  = isset( $input['language_state'] ) && 'no_language' === $input['language_state'] ? 'no_language' : 'missing_translation';

		return Content_Service::get_untranslated(
			$target,
			$type,
			isset( $input['limit'] ) ? (int) $input['limit'] : 20,
			isset( $input['offset'] ) ? (int) $input['offset'] : 0,
			$state
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'edit_posts', $input );
	}
}
