<?php
/**
 * List posts ability.
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
 * Lists posts in an explicit language.
 *
 * @since 1.5.0
 */
final class List_Posts extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'list-posts';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'List Posts', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: list posts, pages or custom post type items that exist in one specific language, newest first. The language is explicit rather than inherited, because WPML filters every query by the current language and the answer would otherwise depend on which language the request happened to be in. Each item carries its "editor_type", so you know which page builder holds its content before reading it. Use this to see what a language actually contains; use get-untranslated-content to see what it is missing.', 'wpml-translation-check-pro' );
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
				'language'     => array(
					'type'        => 'string',
					'description' => __( 'Language code to list in, for example "es". Must be one of the codes from list-languages.', 'wpml-translation-check-pro' ),
				),
				'content_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter to one post type. Omit for every translatable type.', 'wpml-translation-check-pro' ),
				),
				'search'       => array(
					'type'        => 'string',
					'description' => __( 'Optional keyword search across title and content.', 'wpml-translation-check-pro' ),
				),
				'limit'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum items to return. Defaults to 20, maximum 100.', 'wpml-translation-check-pro' ),
					'default'     => Content_Service::DEFAULT_LIMIT,
					'minimum'     => 1,
					'maximum'     => Content_Service::MAX_LIMIT,
				),
				'offset'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to skip for pagination. Defaults to 0.', 'wpml-translation-check-pro' ),
					'default'     => 0,
					'minimum'     => 0,
				),
			),
			'required'   => array( 'language' ),
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
	 * @return array<string, mixed>|\WP_Error Items, total and a has_more flag.
	 */
	public static function execute( array $input = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		// Core validates the schema but does not coerce, so cast defensively.
		$language     = isset( $input['language'] ) ? sanitize_text_field( (string) $input['language'] ) : '';
		$content_type = isset( $input['content_type'] ) ? sanitize_key( (string) $input['content_type'] ) : null;
		$search       = isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';
		$limit        = isset( $input['limit'] ) ? (int) $input['limit'] : Content_Service::DEFAULT_LIMIT;
		$offset       = isset( $input['offset'] ) ? (int) $input['offset'] : 0;

		return Content_Service::list_posts( $language, $content_type, $limit, $offset, $search );
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
