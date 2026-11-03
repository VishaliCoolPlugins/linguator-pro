<?php
/**
 * Project listing ability.
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
 * Lists the plugins, themes and core packages Loco Translate can translate.
 *
 * @since 2.3.0
 */
final class List_Projects extends Abstract_Ability {

	/**
	 * Largest page size accepted.
	 *
	 * @since 2.3.0
	 * @var int
	 */
	const MAX_PER_PAGE = 50;

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'list-projects';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'List Translation Projects', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'LocoAI: list the plugins, themes and WordPress core that Loco Translate can translate. Each bundle has a "bundle" ID and one or more projects, each with a "domain" value; pass both to every other tool. "languages" lists the translation files that already exist, with their location. Pass "locale" to get "has_locale" per project, "search" to find a plugin by name or text domain, and "active_only" to skip inactive plugins and themes. A bundle with an empty "projects" list has no detectable text domain and cannot be translated until the user configures it in Loco Translate.', 'loco-automatic-translate-addon-pro' );
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
			'default'    => (object) [],
			'properties' => [
				'type'        => [
					'type'        => 'string',
					'enum'        => [ 'all', 'plugin', 'theme', 'core' ],
					'default'     => 'all',
					'description' => __( 'Which kind of bundle to list. Default "all".', 'loco-automatic-translate-addon-pro' ),
				],
				'search'      => [
					'type'        => 'string',
					'description' => __( 'Case-insensitive filter on name, folder or text domain.', 'loco-automatic-translate-addon-pro' ),
				],
				'active_only' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Only list active plugins, must-use plugins, the active theme and core.', 'loco-automatic-translate-addon-pro' ),
				],
				'locale'      => $this->locale_property(),
				'page'        => [
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				],
				'per_page'    => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_PER_PAGE,
					'default'     => 20,
					'description' => __( 'Bundles per page, at most 50.', 'loco-automatic-translate-addon-pro' ),
				],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|\WP_Error Listing, or an error.
	 */
	public static function execute( $input = [] ) {
		if ( ! Loco_Guard::is_available() ) {
			return Loco_Guard::unavailable_error();
		}

		$input  = self::to_array( $input );
		$type   = self::input_string( $input, 'type', 'all' );
		$locale = null;

		if ( ! in_array( $type, [ 'all', 'plugin', 'theme', 'core' ], true ) ) {
			$type = 'all';
		}

		if ( '' !== self::input_string( $input, 'locale' ) ) {
			$locale = Loco_Guard::resolve_locale( self::input_string( $input, 'locale' ) );

			if ( is_wp_error( $locale ) ) {
				return $locale;
			}
		}

		return Project_Service::list_projects(
			$type,
			self::input_string( $input, 'search' ),
			self::input_bool( $input, 'active_only', false ),
			$locale,
			self::input_int( $input, 'page', 1, 1, PHP_INT_MAX ),
			self::input_int( $input, 'per_page', 20, 1, self::MAX_PER_PAGE )
		);
	}
}
