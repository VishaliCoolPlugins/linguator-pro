<?php
/**
 * Translation settings ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Environment;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Settings_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports the WPML and AutoMLP settings that change translation behaviour.
 *
 * @since 1.5.0
 */
final class Get_Translation_Settings extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-translation-settings';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Translation Settings', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: read the WPML and AutoMLP settings that decide how a translation behaves. "wpml.url_format" is how translated URLs are built, "wpml.translated_document_slug" whether a translation gets a translated slug or a copy of the original, and "custom_fields" how WPML handles each post meta field. "automlp" reports which AI providers have a key saved and what status new translations are created with. WPML stores these as bare numbers whose meaning is not self-evident, so they are returned as words here. Read this before explaining why a translation came out a particular way; the settings, not the translation, are usually the cause.', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return $this->empty_object_schema();
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
	 * @return array<string, mixed> Settings payload.
	 */
	public static function execute( array $input = array() ) {
		return Settings_Service::get_all();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Site configuration, so administrator-only.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
