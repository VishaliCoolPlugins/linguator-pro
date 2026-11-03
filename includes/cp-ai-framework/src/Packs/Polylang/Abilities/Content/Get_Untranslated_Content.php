<?php
/**
 * Untranslated content listing ability.
 *
 * @package AutoPoly
 * @since   1.10.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Content;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Content_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists content missing a translation in a target language.
 *
 * @since 1.10.0
 */
final class Get_Untranslated_Content extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-untranslated-content';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Untranslated Content', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: get a paginated list of content missing a translation in the given target language. Returns the post ID, title, type, status, source language and modified date for each item, plus template_type (such as "header" or "footer") for Elementor templates. Pass language_state "no_language" instead to list content that has no language at all: it cannot be translated until it is given one, and is left out of the default listing and of the translated and untranslated counts. get-translation-status reports how many such items each type has as without_language.', 'autopoly-ai-translation-for-polylang-pro' );
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
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Language slug to find missing translations for, for example "fr" or "de".', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'content_type'    => array(
					'type'        => 'string',
					'description' => __( 'Filter to a specific post type. Omit for all translatable types. A post type Polylang does not translate returns no items and a "notice" explaining how the user can switch it on; pass that on rather than reporting the type as fully translated.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'limit'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum items to return. Defaults to 20, maximum 100.', 'autopoly-ai-translation-for-polylang-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'offset'          => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to skip for pagination. Defaults to 0.', 'autopoly-ai-translation-for-polylang-pro' ),
					'default'     => 0,
					'minimum'     => 0,
				),
				'language_state'  => array(
					'type'        => 'string',
					'enum'        => Content_Service::LANGUAGE_STATES,
					'default'     => 'missing_translation',
					'description' => __( '"missing_translation", the default, lists content in another language with no translation in target_language. "no_language" lists content that has no language assigned, for example Elementor headers and footers created before their type was switched on for translation; give those a language with manage-translation-links action "assign_language", passing their IDs, before translating them.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
			),
			'required'   => array( 'target_language' ),
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
	 * @return array<string, mixed> Items, total and has_more flag.
	 */
	public static function execute( array $input = array() ) {
		// Core validates the schema but does not coerce, so cast defensively.
		$target       = isset( $input['target_language'] ) ? sanitize_key( (string) $input['target_language'] ) : '';
		$content_type = isset( $input['content_type'] ) ? sanitize_key( (string) $input['content_type'] ) : null;
		$limit        = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
		$offset       = isset( $input['offset'] ) ? (int) $input['offset'] : 0;
		$state        = isset( $input['language_state'] ) && 'no_language' === $input['language_state'] ? 'no_language' : 'missing_translation';

		return Content_Service::get_untranslated( $target, $content_type, $limit, $offset, $state );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'edit_posts', $input );
	}
}
