<?php
/**
 * Translation status ability.
 *
 * @package AutoPoly
 * @since   1.10.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Environment;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Content_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports translation coverage across content types and languages.
 *
 * @since 1.10.0
 */
final class Get_Translation_Status extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-translation-status';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Translation Status', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: get an overview of translation progress across content types and languages, with translated, untranslated and total counts plus percentages. Each type also reports without_language: items with no language assigned, which are in none of the other counts and must be given a language before they can be translated (a type whose items all lack one shows 0 of 0 as 100%). Use this to plan translation work. Only types Polylang translates are counted: call list-content-types to see types that are switched off, and a content_type Polylang does not translate returns a "notice" explaining how the user can switch it on instead of counts.', 'autopoly-ai-translation-for-polylang-pro' );
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
				'content_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter to a specific post type, for example "post" or "page". Omit for all types.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'language'     => array(
					'type'        => 'string',
					'description' => __( 'Filter to a specific target language slug. Omit for all languages.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
			),
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
	 * @return array<string, mixed> Coverage report.
	 */
	public static function execute( array $input = array() ) {
		$content_type = isset( $input['content_type'] ) ? sanitize_key( (string) $input['content_type'] ) : null;
		$language     = isset( $input['language'] ) ? sanitize_key( (string) $input['language'] ) : null;

		return Content_Service::get_translation_status( $content_type, $language );
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
