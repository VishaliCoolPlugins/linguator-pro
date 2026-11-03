<?php
/**
 * Create language ability.
 *
 * @package CoolPluginsAiFramework
 * @since 1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Languages;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Language_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a new language to Linguator.
 *
 * @since 1.2.0
 */
final class Create_Language extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'create-language';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Create Language', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: add a new language to Linguator. Only the locale is required; name, slug, text direction and flag default from Linguator\'s built-in language list when omitted. When another language already uses the slug, Linguator uses the locale as the slug instead and the response says so (slug_notice). Adding a language changes the URL structure of the site (rewrite rules are refreshed) and shows a new language in the switcher.', 'linguator-pro' );
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
				'locale' => array(
					'type'        => 'string',
					'description' => __( 'WordPress locale code, for example "fr_FR", "de_DE" or "nl_NL".', 'linguator-pro' ),
				),
				'name'   => array(
					'type'        => 'string',
					'description' => __( 'Display name for the language. Defaults from Linguator\'s predefined list.', 'linguator-pro' ),
				),
				'slug'   => array(
					'type'        => 'string',
					'description' => __( 'Language slug, for example "fr" or "de". Defaults from the locale.', 'linguator-pro' ),
				),
				'rtl'    => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the language is written right to left.', 'linguator-pro' ),
				),
				'flag'   => array(
					'type'        => 'string',
					'description' => __( 'ISO 3166-1 country code for the flag, for example "fr", "de" or "us". Defaults from the locale when omitted; if none is found, the response includes a notice and update-language can set it.', 'linguator-pro' ),
				),
			),
			'required'   => array( 'locale' ),
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
				'idempotent'  => false,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Language descriptor, or an error.
	 */
	public static function execute( array $input = array() ) {
		$result = Language_Service::create( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// A locale missing from Linguator's list gets no default flag; say how to fix it.
		if ( '' === $result['flag'] ) {
			$result['notice'] = sprintf(
				/* translators: %s: language slug. */
				__( 'The language was created without a flag, so it will show no flag in language switchers. Call update-language with slug "%s" and a flag country code (for example "jp" or "us") to set one.', 'linguator-pro' ),
				$result['slug']
			);
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
