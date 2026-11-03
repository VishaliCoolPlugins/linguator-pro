<?php
/**
 * POT template creation ability.
 *
 * @package LocoAI
 * @since   2.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Loco\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Loco\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Loco\Services\Loco_Guard;
use CoolPlugins\AiFramework\Packs\Loco\Services\Template_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a project's POT template from its source code.
 *
 * @since 2.5.0
 */
final class Create_Template extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'create-template';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Create Template', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'LocoAI: build the POT template for a plugin or theme that has none, by scanning its source code for translatable strings, exactly like "Create template" in Loco Translate. A template holds no translations and is never read by WordPress; it is the string list every language file starts from. Call this when get-project-status reports no template and the user is about to translate into several languages, because each language then starts from this file instead of re-scanning the source, or when create-language-file refuses because the settings require a template. Refuses when a template already exists, and never overwrites one. Then call create-language-file.', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return [
			'type'       => 'object',
			'properties' => $this->project_properties(),
			'required'   => [ 'bundle' ],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * Not destructive: an existing template is reported back, never replaced.
	 * Not idempotent: a second call refuses rather than repeating the write.
	 *
	 * @since 2.5.0
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
	 * @since 2.5.0
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

		return Template_Service::create( $bundle, $project );
	}
}
