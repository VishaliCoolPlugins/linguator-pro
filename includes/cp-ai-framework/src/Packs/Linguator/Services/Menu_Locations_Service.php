<?php
/**
 * Theme menu locations and the menu assigned to each language.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and changes which menu a theme location shows in each language.
 *
 * Linguator keeps the assignments of the active theme in its "nav_menus" option
 * (theme => location => language => menu ID). The default language is also kept
 * in the theme's own menu locations, which is what WordPress reads.
 *
 * @since 1.3.0
 */
final class Menu_Locations_Service {

	/**
	 * Option holding Linguator's menu assignments.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const OPTION = 'nav_menus';

	/**
	 * Theme locations with the menu assigned to each language.
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Theme, locations and menus.
	 */
	public static function overview() {
		$theme     = (string) get_option( 'stylesheet' );
		$languages = (array) linguator_languages_list();
		$default   = (string) linguator_default_language();
		$locations = array();

		foreach ( get_registered_nav_menus() as $location => $description ) {
			$assigned = array();

			foreach ( $languages as $slug ) {
				$id = self::assigned_id( $theme, (string) $location, (string) $slug, $default );

				$assigned[ (string) $slug ] = $id > 0 ? self::menu_summary( $id ) : null;
			}

			$locations[] = array(
				'location'    => (string) $location,
				'description' => (string) $description,
				'menus'       => (object) $assigned,
			);
		}

		return array(
			'theme'            => $theme,
			'default_language' => $default,
			'languages'        => array_values( array_map( 'strval', $languages ) ),
			'locations'        => $locations,
			'menus'            => Menu_Service::list_menus(),
		);
	}

	/**
	 * Assigns or unassigns a menu for a location and language.
	 *
	 * @since 1.3.0
	 * @param string $action   "assign" or "unassign".
	 * @param string $location Theme location.
	 * @param string $language Language slug.
	 * @param int    $menu_id  Menu ID (assign only).
	 * @param bool   $dry_run  Whether to change nothing.
	 * @return array<string, mixed>|WP_Error Report, or an error.
	 */
	public static function change( $action, $location, $language, $menu_id, $dry_run ) {
		$options = Settings_Service::options();

		if ( null === $options ) {
			return new WP_Error( 'lmat_not_ready', __( 'Linguator is not loaded.', 'linguator-pro' ) );
		}

		if ( ! in_array( $action, array( 'assign', 'unassign' ), true ) ) {
			return new WP_Error( 'lmat_invalid_action', __( 'action must be "assign" or "unassign".', 'linguator-pro' ) );
		}

		$registered = get_registered_nav_menus();
		$location   = is_string( $location ) ? $location : '';

		if ( '' === $location || ! isset( $registered[ $location ] ) ) {
			return new WP_Error(
				'lmat_invalid_location',
				sprintf(
					/* translators: %s: comma-separated list of locations. */
					__( 'Unknown theme menu location. This theme has: %s.', 'linguator-pro' ),
					implode( ', ', array_keys( $registered ) )
				)
			);
		}

		$lang = Linguator_Guard::language( $language );

		if ( is_wp_error( $lang ) ) {
			return $lang;
		}

		$slug = (string) $lang->slug;
		$menu = null;

		if ( 'assign' === $action ) {
			$menu = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : false;

			if ( ! $menu ) {
				return new WP_Error(
					'lmat_menu_not_found',
					sprintf(
						/* translators: %d: menu ID. */
						__( 'Menu %d does not exist. Call list-menus to see the menus.', 'linguator-pro' ),
						$menu_id
					)
				);
			}
		}

		$theme   = (string) get_option( 'stylesheet' );
		$default = (string) linguator_default_language();
		$before  = self::assigned_id( $theme, $location, $slug, $default );
		$after   = 'assign' === $action ? (int) $menu->term_id : 0;

		$report = array(
			'dry_run'  => (bool) $dry_run,
			'changed'  => false,
			'action'   => $action,
			'theme'    => $theme,
			'location' => $location,
			'language' => $slug,
			'before'   => $before > 0 ? self::menu_summary( $before ) : null,
			'after'    => $after > 0 ? self::menu_summary( $after ) : null,
			'warnings' => array(),
		);

		if ( $before === $after ) {
			$report['notice'] = __( 'Already set this way; nothing to change.', 'linguator-pro' );

			return $report;
		}

		if ( $after > 0 ) {
			$elsewhere = self::languages_using( $theme, $after, $location, $slug );

			if ( ! empty( $elsewhere ) ) {
				$report['warnings'][] = sprintf(
					/* translators: %s: language slugs. */
					__( 'This menu is also assigned, at another location or for another language, to: %s. Menus are normally kept one per language.', 'linguator-pro' ),
					implode( ', ', $elsewhere )
				);
			}
		}

		if ( $dry_run ) {
			return $report;
		}

		$nav_menus = $options->get( self::OPTION );
		$nav_menus = is_array( $nav_menus ) ? $nav_menus : array();

		if ( $after > 0 ) {
			$nav_menus[ $theme ][ $location ][ $slug ] = $after;
		} else {
			unset( $nav_menus[ $theme ][ $location ][ $slug ] );
		}

		$errors = $options->set( self::OPTION, $nav_menus );

		if ( $errors->has_errors() ) {
			return new WP_Error( 'lmat_setting_rejected', __( 'Linguator did not accept the menu assignment.', 'linguator-pro' ) . ' ' . implode( ' ', $errors->get_error_messages() ) );
		}

		$options->save();

		if ( $slug === $default ) {
			$mods = get_option( 'theme_mods_' . $theme, array() );
			$mods = is_array( $mods ) ? $mods : array();

			if ( ! isset( $mods['nav_menu_locations'] ) || ! is_array( $mods['nav_menu_locations'] ) ) {
				$mods['nav_menu_locations'] = array();
			}

			if ( $after > 0 ) {
				$mods['nav_menu_locations'][ $location ] = $after;
			} else {
				unset( $mods['nav_menu_locations'][ $location ] );
			}

			update_option( 'theme_mods_' . $theme, $mods );
		}

		$saved = self::stored_id( $theme, $location, $slug, $default );

		$report['dry_run'] = false;
		$report['changed'] = true;
		$report['saved']   = $saved > 0 ? self::menu_summary( $saved ) : null;

		return $report;
	}

	/**
	 * Menu ID assigned to a location for a language, as read from the database.
	 *
	 * @since 1.3.0
	 * @param string $theme    Theme slug.
	 * @param string $location Location.
	 * @param string $slug     Language slug.
	 * @param string $default  Default language slug.
	 * @return int Menu ID, or 0.
	 */
	private static function stored_id( $theme, $location, $slug, $default ) {
		$linguator = get_option( 'linguator', array() );
		$id        = is_array( $linguator ) && isset( $linguator[ self::OPTION ][ $theme ][ $location ][ $slug ] ) ? (int) $linguator[ self::OPTION ][ $theme ][ $location ][ $slug ] : 0;

		if ( 0 === $id && $slug === $default ) {
			$mods = get_option( 'theme_mods_' . $theme, array() );
			$id   = is_array( $mods ) && isset( $mods['nav_menu_locations'][ $location ] ) ? (int) $mods['nav_menu_locations'][ $location ] : 0;
		}

		return self::menu_exists( $id ) ? $id : 0;
	}

	/**
	 * Menu ID assigned to a location for a language.
	 *
	 * Reads Linguator's option first, then, for the default language, the
	 * theme's own menu locations.
	 *
	 * @since 1.3.0
	 * @param string $theme    Theme slug.
	 * @param string $location Location.
	 * @param string $slug     Language slug.
	 * @param string $default  Default language slug.
	 * @return int Menu ID, or 0.
	 */
	private static function assigned_id( $theme, $location, $slug, $default ) {
		$nav_menus = Settings_Service::options();
		$nav_menus = null === $nav_menus ? array() : $nav_menus->get( self::OPTION );
		$id        = is_array( $nav_menus ) && isset( $nav_menus[ $theme ][ $location ][ $slug ] ) ? (int) $nav_menus[ $theme ][ $location ][ $slug ] : 0;

		if ( 0 === $id && $slug === $default ) {
			$mods = get_option( 'theme_mods_' . $theme, array() );
			$id   = is_array( $mods ) && isset( $mods['nav_menu_locations'][ $location ] ) ? (int) $mods['nav_menu_locations'][ $location ] : 0;
		}

		return self::menu_exists( $id ) ? $id : 0;
	}

	/**
	 * Languages a menu is assigned to, apart from one location and language.
	 *
	 * @since 1.3.0
	 * @param string $theme    Theme slug.
	 * @param int    $menu_id  Menu ID.
	 * @param string $location Location being changed.
	 * @param string $slug     Language being changed.
	 * @return string[] "location:language" pairs.
	 */
	private static function languages_using( $theme, $menu_id, $location, $slug ) {
		$options   = Settings_Service::options();
		$nav_menus = null === $options ? array() : $options->get( self::OPTION );
		$found     = array();

		if ( ! is_array( $nav_menus ) || empty( $nav_menus[ $theme ] ) || ! is_array( $nav_menus[ $theme ] ) ) {
			return $found;
		}

		foreach ( $nav_menus[ $theme ] as $loc => $by_language ) {
			foreach ( (array) $by_language as $lang_slug => $id ) {
				if ( (int) $id === $menu_id && ! ( $loc === $location && $lang_slug === $slug ) ) {
					$found[] = $loc . ':' . $lang_slug;
				}
			}
		}

		return $found;
	}

	/**
	 * Whether a menu exists.
	 *
	 * @since 1.3.0
	 * @param int $menu_id Menu ID.
	 * @return bool True when it exists.
	 */
	private static function menu_exists( $menu_id ) {
		return $menu_id > 0 && false !== wp_get_nav_menu_object( $menu_id );
	}

	/**
	 * Short description of a menu.
	 *
	 * @since 1.3.0
	 * @param int $menu_id Menu ID.
	 * @return array<string, mixed>|null Menu ID, name and item count.
	 */
	private static function menu_summary( $menu_id ) {
		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return null;
		}

		return array(
			'id'         => (int) $menu->term_id,
			'name'       => (string) $menu->name,
			'item_count' => (int) $menu->count,
		);
	}
}
