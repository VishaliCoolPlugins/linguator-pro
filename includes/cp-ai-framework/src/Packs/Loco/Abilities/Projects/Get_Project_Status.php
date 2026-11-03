<?php
/**
 * Project status ability.
 *
 * @package LocoAI
 * @since   2.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Loco\Abilities\Projects;

use CoolPlugins\AiFramework\Packs\Loco\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Loco\Services\Loco_Guard;
use CoolPlugins\AiFramework\Packs\Loco\Services\Project_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports a project's template and the progress of each translation file.
 *
 * @since 2.3.0
 */
final class Get_Project_Status extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-project-status';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Project Status', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'LocoAI: show one plugin, theme or core project in detail: its POT template, and every language file with its location (custom, system or author), total, translated, fuzzy and untranslated counts, percentage, and whether the web server can write it. Pass "locale" to report a single language. Custom-location files are loaded on top of system and author files, so they are what visitors see. "can_create_language" tells whether create-language-file will work.', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return [
			'type'       => 'object',
			'properties' => $this->project_properties() + [
				'locale' => $this->locale_property(),
			],
			'required'   => [ 'bundle' ],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|\WP_Error Status, or an error.
	 */
	public static function execute( $input = [] ) {
		if ( ! Loco_Guard::is_available() ) {
			return Loco_Guard::unavailable_error();
		}

		$input  = self::to_array( $input );
		$bundle = Loco_Guard::resolve_bundle( self::input_string( $input, 'bundle' ) );

		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		$project = Loco_Guard::resolve_project( $bundle, self::input_string( $input, 'domain' ) );

		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$locale = null;

		if ( '' !== self::input_string( $input, 'locale' ) ) {
			$locale = Loco_Guard::resolve_locale( self::input_string( $input, 'locale' ) );

			if ( is_wp_error( $locale ) ) {
				return $locale;
			}
		}

		return Project_Service::project_status( $bundle, $project, $locale );
	}
}
