<?php
/**
 * List languages ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Languages;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Language_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the active WPML languages.
 *
 * @since 1.5.0
 */
final class List_Languages extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'list-languages';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'List Languages', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: list the WPML languages active on this site, with the site\'s own source language flagged as is_default. Every other tool that takes a language expects one of these "code" values, so read this before passing a language anywhere. An empty list means WPML has no languages configured yet and the user must finish WPML\'s setup first.', 'wpml-translation-check-pro' );
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
		return array(
			'type'       => 'object',
			'properties' => array(
				'default_language' => array( 'type' => 'string' ),
				'languages'        => array( 'type' => 'array' ),
				'total'            => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Languages, or an error.
	 */
	public static function execute( array $input = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$languages = Language_Service::get_all();

		return array(
			'default_language' => Language_Service::get_default_code(),
			'languages'        => $languages,
			'total'            => count( $languages ),
		);
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
