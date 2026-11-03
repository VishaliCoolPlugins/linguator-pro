<?php
/**
 * String translation write ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Strings;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\String_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saves translations for WPML String Translation strings.
 *
 * @since 1.5.0
 */
final class Translate_Strings extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'translate-strings';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Translate Strings', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: save translations for theme, plugin and admin-text strings in WPML String Translation. You supply the translated text: call get-strings, translate each "value", and pass it back with its "string_id", up to 100 per call. Saving the same text again overwrites it, so a retry is safe. Each string is saved on its own, so one bad entry is listed under "errors" without stopping the rest. Only basic inline HTML (a, br, em, strong, p, ul, ol, li) is kept. The translation appears on the site immediately, except menu labels and links (groups named "<menu name> menu"): those reach the translated menu only after a site administrator applies them under WPML > Translations > Menus (older WPML: WPML > WP Menus Sync), so tell the user.', 'wpml-translation-check-pro' );
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
					'description' => __( 'Language code to write, for example "hi". Must be one of the codes from list-languages.', 'wpml-translation-check-pro' ),
				),
				'translations'    => array(
					'type'        => 'array',
					'maxItems'    => String_Service::MAX_WRITE,
					'description' => __( 'The translations to save, each with the "string_id" from get-strings and the translated "value".', 'wpml-translation-check-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'string_id' => array( 'type' => 'integer' ),
							'value'     => array( 'type' => 'string' ),
						),
						'required'   => array( 'string_id', 'value' ),
					),
				),
			),
			'required'   => array( 'target_language', 'translations' ),
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
				'target_language' => array( 'type' => 'string' ),
				'saved_count'     => array( 'type' => 'integer' ),
				'saved'           => array( 'type' => 'array' ),
				'skipped'         => array( 'type' => 'array' ),
				'errors'          => array( 'type' => 'array' ),
				'notice'          => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Writes, but only translations: nothing is deleted and saving the same
	 * text twice leaves the same result.
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> Ability meta.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error Write report, or an error.
	 */
	public static function execute( array $input = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$language     = isset( $input['target_language'] ) ? sanitize_text_field( (string) $input['target_language'] ) : '';
		$translations = isset( $input['translations'] ) ? $input['translations'] : array();
		$invalid      = String_Service::validate_write( $language, $translations );

		if ( null !== $invalid ) {
			return $invalid;
		}

		return String_Service::save( $language, $translations );
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
