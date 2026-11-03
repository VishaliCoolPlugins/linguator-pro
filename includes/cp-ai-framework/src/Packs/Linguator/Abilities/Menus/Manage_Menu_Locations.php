<?php
/**
 * Manage Menu Locations ability.
 *
 * @package CoolPluginsAiFramework
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Menus;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Menu_Locations_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assigns or unassigns the menu shown at a theme location in a language.
 *
 * @since 1.3.0
 */
final class Manage_Menu_Locations extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'manage-menu-locations';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Manage Menu Locations', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: choose which navigation menu a theme location shows in a language, like the Manage Locations screen of Appearance > Menus with one column per language. action "assign" needs location, language and menu_id; the menu must exist. action "unassign" clears the location for that language. Locations are listed by list-menus. The default language is also written to the theme\'s own menu locations, which WordPress reads. Unassigning leaves the menu itself untouched. This is a dry run by default and returns the before and after; call again with dry_run false to save, and the response includes the assignment read back.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'   => array(
					'type'        => 'string',
					'enum'        => array( 'assign', 'unassign' ),
					'description' => __( 'assign a menu, or unassign the location for the language.', 'linguator-pro' ),
				),
				'location' => array(
					'type'        => 'string',
					'description' => __( 'Theme menu location slug, from list-menus, for example "primary".', 'linguator-pro' ),
				),
				'language' => array(
					'type'        => 'string',
					'description' => __( 'Language slug, for example "fr".', 'linguator-pro' ),
				),
				'menu_id'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the menu to assign (assign only), from list-menus.', 'linguator-pro' ),
				),
				'dry_run' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'When true, the default, report what would change and change nothing. Set to false to save.', 'linguator-pro' ),
				),
			),
			'required'   => array( 'action', 'location', 'language' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Meta array.
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
	 * @since 1.3.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		// Defaults to a dry run; only an explicit false writes.
		$dry_run = isset( $input['dry_run'] ) ? rest_sanitize_boolean( $input['dry_run'] ) : true;

		return Menu_Locations_Service::change(
			isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : '',
			isset( $input['location'] ) ? sanitize_text_field( (string) $input['location'] ) : '',
			isset( $input['language'] ) ? sanitize_key( (string) $input['language'] ) : '',
			isset( $input['menu_id'] ) ? absint( $input['menu_id'] ) : 0,
			$dry_run
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'edit_theme_options', $input );
	}
}
