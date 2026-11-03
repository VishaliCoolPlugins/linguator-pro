<?php
/**
 * Language file creation ability.
 *
 * @package LocoAI
 * @since   2.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Loco\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Loco\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Loco\Services\Language_File_Service;
use CoolPlugins\AiFramework\Packs\Loco\Services\Loco_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new, untranslated PO file for a language.
 *
 * @since 2.3.0
 */
final class Create_Language_File extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'create-language-file';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Create Language File', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'LocoAI: start translating a plugin, theme or core into a new language by creating its empty PO file, exactly like "New language" in Loco Translate. Strings come from the project\'s POT template, or are extracted from the source code when there is none. Location defaults to "custom" (wp-content/languages/loco), which survives plugin and language pack updates; only use "system" or "author" when the user asks. Refuses when a file for that language already exists, and never overwrites anything. Then call get-strings.', 'loco-automatic-translate-addon-pro' );
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
				'locale'   => $this->locale_property(),
				'location' => $this->location_property(
					__( 'Where to create the file. Default "custom": safe from updates. "system" is replaced by language pack updates; "author" is deleted when the plugin or theme updates.', 'loco-automatic-translate-addon-pro' ),
					[ 'custom', 'system', 'author' ]
				),
			],
			'required'   => [ 'bundle', 'locale' ],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return [
			'annotations'  => [
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			],
			'show_in_rest' => true,
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result, or an error.
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

		$locale = Loco_Guard::resolve_locale( self::input_string( $input, 'locale' ) );

		if ( is_wp_error( $locale ) ) {
			return $locale;
		}

		$location = self::input_string( $input, 'location' );
		$explicit = '' !== $location;

		if ( ! in_array( $location, [ 'custom', 'system', 'author' ], true ) ) {
			$location = 'custom';
		}

		return Language_File_Service::create( $bundle, $project, $locale, $location, $explicit );
	}
}
