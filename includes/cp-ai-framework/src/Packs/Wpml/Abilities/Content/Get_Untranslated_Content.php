<?php
/**
 * Untranslated content ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Content_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists content with no translation yet in a target language.
 *
 * @since 1.5.0
 */
final class Get_Untranslated_Content extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-untranslated-content';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Untranslated Content', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: list source-language content that has no usable translation in the given target language. This uses the same coverage rule as AutoMLP\'s bulk translate screen, so it also returns items whose translation exists but whose source has been edited since, which are the ones worth translating again. Each item carries its "editor_type", so you know which page builder holds its content. A "notice" in the response means the answer is incomplete for a stated reason: read it out rather than reporting the site as fully translated.', 'wpml-translation-check-pro' );
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
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Language code to find missing translations for, for example "es" or "de". Must be one of the codes from list-languages.', 'wpml-translation-check-pro' ),
				),
				'content_type'    => array(
					'type'        => 'string',
					'description' => __( 'Filter to one post type. Omit for every translatable type. A post type WPML does not translate returns no items and a "notice" explaining how the user can switch it on.', 'wpml-translation-check-pro' ),
				),
				'limit'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum items to return. Defaults to 20, maximum 100.', 'wpml-translation-check-pro' ),
					'default'     => Content_Service::DEFAULT_LIMIT,
					'minimum'     => 1,
					'maximum'     => Content_Service::MAX_LIMIT,
				),
				'offset'          => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to skip for pagination. Defaults to 0.', 'wpml-translation-check-pro' ),
					'default'     => 0,
					'minimum'     => 0,
				),
			),
			'required'   => array( 'target_language' ),
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
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Items, totals and a has_more flag.
	 */
	public static function execute( array $input = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		// Core validates the schema but does not coerce, so cast defensively.
		$target       = isset( $input['target_language'] ) ? sanitize_text_field( (string) $input['target_language'] ) : '';
		$content_type = isset( $input['content_type'] ) ? sanitize_key( (string) $input['content_type'] ) : null;
		$limit        = isset( $input['limit'] ) ? (int) $input['limit'] : Content_Service::DEFAULT_LIMIT;
		$offset       = isset( $input['offset'] ) ? (int) $input['offset'] : 0;

		return Content_Service::get_untranslated( $target, $content_type, $limit, $offset );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'edit_posts', $input );
	}
}
