<?php
/**
 * Create language ability.
 *
 * @package AutoPoly
 * @since   1.10.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Languages;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Language_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a new language to Polylang.
 *
 * @since 1.10.0
 */
final class Create_Language extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'create-language';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Create Language', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: add a new language to Polylang. Only the locale is required; name, slug, text direction and flag default from Polylang\'s built-in language list when omitted.', 'autopoly-ai-translation-for-polylang-pro' );
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
				'locale' => array(
					'type'        => 'string',
					'description' => __( 'WordPress locale code, for example "fr_FR", "de_DE" or "nl_NL".', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'name'   => array(
					'type'        => 'string',
					'description' => __( 'Display name for the language. Defaults from Polylang\'s predefined list.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'slug'   => array(
					'type'        => 'string',
					'description' => __( 'Language slug, for example "fr" or "de". Defaults from the locale.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'rtl'    => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the language is written right to left.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'flag'   => array(
					'type'        => 'string',
					'description' => __( 'ISO 3166-1 country code for the flag, for example "fr", "de" or "us". Defaults from the locale when omitted; if none is found, the response includes a notice and update-language can set it.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
			),
			'required'   => array( 'locale' ),
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
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Language descriptor, or an error.
	 */
	public static function execute( array $input = array() ) {
		$result = Language_Service::create( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// A locale missing from Polylang's list gets no default flag; say how to fix it.
		if ( '' === $result['flag'] ) {
			$result['notice'] = sprintf(
				/* translators: %s: language slug. */
				__( 'The language was created without a flag, so it will show no flag in language switchers. Call update-language with slug "%s" and a flag country code (for example "jp" or "us") to set one.', 'autopoly-ai-translation-for-polylang-pro' ),
				$result['slug']
			);
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
