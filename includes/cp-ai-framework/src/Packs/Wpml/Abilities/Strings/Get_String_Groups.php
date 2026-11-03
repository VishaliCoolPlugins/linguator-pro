<?php
/**
 * String group overview ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Strings;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Language_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\String_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists WPML String Translation domains with their translation progress.
 *
 * @since 1.5.0
 */
final class Get_String_Groups extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-string-groups';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get String Groups', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: list the string groups (WPML "domains") held by WPML String Translation, such as a theme\'s text domain, a plugin\'s text domain, "admin_texts_blogname" for the site title, or "Widgets". Each group reports how many strings it has and, per language, how many are translated, need an update because the original changed, or are untranslated. Use it to decide which domain to pass to get-strings. Page builder content is not included; translate that with get-content and translate-post.', 'wpml-translation-check-pro' );
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
					'description' => __( 'Report progress for one language code from list-languages. Omit for every active language.', 'wpml-translation-check-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'source_language' => array( 'type' => 'string' ),
				'groups'          => array( 'type' => 'array' ),
				'total_groups'    => array( 'type' => 'integer' ),
				'notice'          => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error Group report, or an error.
	 */
	public static function execute( array $input = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$language = isset( $input['target_language'] ) ? sanitize_text_field( (string) $input['target_language'] ) : '';

		if ( '' !== $language && ! Language_Service::is_active( $language ) ) {
			return Language_Service::inactive_error( $language );
		}

		return String_Service::get_groups( $language );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Matches the capability of AutoMLP's own String Translation bulk screen.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
