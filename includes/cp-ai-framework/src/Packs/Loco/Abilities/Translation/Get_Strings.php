<?php
/**
 * String listing ability.
 *
 * @package LocoAI
 * @since   2.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Loco\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Loco\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Loco\Services\Loco_Guard;
use CoolPlugins\AiFramework\Packs\Loco\Services\Project_Service;
use CoolPlugins\AiFramework\Packs\Loco\Services\String_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the strings of a language file, filtered and paginated.
 *
 * @since 2.3.0
 */
final class Get_Strings extends Abstract_Ability {

	/**
	 * Largest page size accepted.
	 *
	 * @since 2.3.0
	 * @var int
	 */
	const MAX_PER_PAGE = 200;

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-strings';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Strings', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'LocoAI: read the strings of one language file. Defaults to untranslated strings, 50 per page. Each item has "source", "context" and either "translation" or, for plural strings, "plural_source" and "translations" (one per plural form, in the order of locale.plural_labels). Keep every item in "placeholders" (such as %s or %1$d) and "html_tags" intact in your translation, and use "developer_notes" and "context" to disambiguate. Send translations back with save-translations using source and context exactly as returned. A large file needs several rounds: repeat get-strings and save-translations until no untranslated strings remain, without stopping to ask the user between batches. When several files exist for the language, the custom-location one is used unless "location" is given.', 'loco-automatic-translate-addon-pro' );
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
					__( 'Which file to read when several exist for the language. Default: the custom file, then system, then author.', 'loco-automatic-translate-addon-pro' ),
					[ 'custom', 'system', 'author', 'other' ]
				),
				'status'   => [
					'type'        => 'string',
					'enum'        => String_Service::STATUSES,
					'default'     => 'untranslated',
					'description' => __( '"untranslated" (default, includes plural strings with any empty form), "fuzzy" (needs review), "translated" or "all".', 'loco-automatic-translate-addon-pro' ),
				],
				'search'   => [
					'type'        => 'string',
					'description' => __( 'Case-insensitive filter on source, context and translation text.', 'loco-automatic-translate-addon-pro' ),
				],
				'page'     => [
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				],
				'per_page' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_PER_PAGE,
					'default'     => 50,
					'description' => __( 'Strings per page, at most 200.', 'loco-automatic-translate-addon-pro' ),
				],
			],
			'required'   => [ 'bundle', 'locale' ],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|\WP_Error Strings, or an error.
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

		$status = self::input_string( $input, 'status', 'untranslated' );

		if ( ! in_array( $status, String_Service::STATUSES, true ) ) {
			$status = 'untranslated';
		}

		$result = String_Service::get_strings(
			$selection['file'],
			$locale,
			$status,
			self::input_string( $input, 'search' ),
			self::input_int( $input, 'page', 1, 1, PHP_INT_MAX ),
			self::input_int( $input, 'per_page', 50, 1, self::MAX_PER_PAGE )
		);

		if ( is_array( $result ) && [] !== $selection['others'] ) {
			$result['other_locations'] = array_map( [ Loco_Guard::class, 'location_key' ], $selection['others'] );
		}

		return $result;
	}
}
