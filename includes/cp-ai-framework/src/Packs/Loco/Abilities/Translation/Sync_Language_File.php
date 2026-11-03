<?php
/**
 * Language file sync ability.
 *
 * @package LocoAI
 * @since   2.4.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Loco\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Loco\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Loco\Services\Loco_Guard;
use CoolPlugins\AiFramework\Packs\Loco\Services\Project_Service;
use CoolPlugins\AiFramework\Packs\Loco\Services\Sync_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brings a language file back in step with the strings its plugin or theme
 * currently defines, without touching the translations that still apply.
 *
 * @since 2.4.0
 */
final class Sync_Language_File extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.4.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'sync-language-file';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.4.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Sync Language File', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.4.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'LocoAI: update a language file to match the strings the plugin or theme defines now, exactly like "Sync" in Loco Translate. Translates nothing: existing translations are kept, strings the plugin added arrive untranslated, strings whose source text changed keep their old translation and are flagged fuzzy for review, and strings the plugin removed are deleted. Call this after a plugin or theme update, or when save-translations reports entries as "not_found". The report gives counts and examples per change; follow it with get-strings using status "untranslated" and then "fuzzy". Pass "dry_run" to see the changes without writing. A sync that would delete more than a tenth of the finished translations stops and reports instead, because a wrong template or a failed extraction looks exactly like a large removal; re-send with "confirm_removals" once the report looks right. Loco Translate keeps a backup of the previous PO file.', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.4.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return [
			'type'       => 'object',
			'properties' => $this->project_properties() + [
				'locale'            => $this->locale_property(),
				'location'          => $this->location_property(
					__( 'Which file to sync when several exist for the language. Default: the custom file, then system, then author.', 'loco-automatic-translate-addon-pro' ),
					[ 'custom', 'system', 'author', 'other' ]
				),
				'dry_run'           => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Report what would change and write nothing. Use this first when the file matters and the plugin changed a lot.', 'loco-automatic-translate-addon-pro' ),
				],
				'confirm_removals' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Approve a sync that deletes a large share of the finished translations. Only send this after reading a report that showed the removals, and never to retry a blocked sync blindly.', 'loco-automatic-translate-addon-pro' ),
				],
			],
			'required'   => [ 'bundle', 'locale' ],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * Destructive because a sync deletes entries whose source strings are gone.
	 * Idempotent because running it again against unchanged source is a no-op.
	 *
	 * @since 2.4.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return [
			'annotations'  => [
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.4.0
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|\WP_Error Report, or an error.
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

		if ( ! in_array( $location, Loco_Guard::LOCATION_ORDER, true ) ) {
			$location = '';
		}

		$selection = Project_Service::pick_po_file( $project, $locale, $location );

		if ( is_wp_error( $selection ) ) {
			return $selection;
		}

		$result = Sync_Service::sync(
			$bundle,
			$project,
			$selection['file'],
			$locale,
			self::input_bool( $input, 'dry_run', false ),
			self::input_bool( $input, 'confirm_removals', false )
		);

		if ( is_array( $result ) && [] !== $selection['others'] ) {
			$result['other_locations'] = array_map( [ Loco_Guard::class, 'location_key' ], $selection['others'] );
		}

		return $result;
	}
}
