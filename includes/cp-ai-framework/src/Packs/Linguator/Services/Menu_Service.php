<?php
/**
 * Menu reads and Linguator's Menu Synchronization.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Previews and runs Linguator's Menu Synchronization, which copies a menu into other languages.
 *
 * The write itself is Linguator's own code (Linguator_Admin_Menu_Sync, the class behind the
 * Menu Synchronization button in Appearance > Menus), called through reflection because its
 * methods are private. Only the dry run is computed here, from the same rules, so that it can
 * report what would happen without changing anything.
 *
 * @since 1.2.0
 */
final class Menu_Service {

	/**
	 * Linguator's menu sync controller.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const SYNC_CLASS = 'Linguator\Admin\Controllers\Linguator_Admin_Menu_Sync';

	/**
	 * Private methods of the controller this service calls; a missing one means a different version.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const SYNC_METHODS = array(
		'linguator_can_sync_item',
		'linguator_sync_menu_to_languages',
	);

	/**
	 * Controller instance, created on first use.
	 *
	 * @since 1.2.0
	 * @var object|null
	 */
	private static $sync = null;

	/**
	 * Whether the Menu Synchronization feature is switched on in Linguator's settings.
	 *
	 * @since 1.2.0
	 * @return bool True when on.
	 */
	public static function is_enabled() {
		$linguator = function_exists( 'LMAT' ) ? LMAT() : null;

		return is_object( $linguator ) && isset( $linguator->options ) && (bool) $linguator->options->get( 'menu_sync_visibility' );
	}

	/**
	 * Menu assignments to theme locations: theme location => language slug => menu ID.
	 *
	 * @since 1.2.0
	 * @return array<string, array<string, int>> Assignments for the active theme.
	 */
	private static function assignments() {
		$linguator = function_exists( 'LMAT' ) ? LMAT() : null;

		if ( ! is_object( $linguator ) || ! isset( $linguator->options ) ) {
			return array();
		}

		$nav_menus = $linguator->options->get( 'nav_menus' );
		$theme     = (string) get_option( 'stylesheet' );

		return is_array( $nav_menus ) && isset( $nav_menus[ $theme ] ) && is_array( $nav_menus[ $theme ] ) ? $nav_menus[ $theme ] : array();
	}

	/**
	 * Language and theme locations of a menu, as Linguator records them.
	 *
	 * A menu has a language only when it is assigned to a theme location for that language.
	 *
	 * @since 1.2.0
	 * @param int $menu_id Menu ID.
	 * @return array{language: string, locations: string[]} Language slug (empty when unknown) and locations.
	 */
	public static function menu_info( $menu_id ) {
		$language  = '';
		$locations = array();

		foreach ( self::assignments() as $location => $languages ) {
			if ( ! is_array( $languages ) ) {
				continue;
			}

			foreach ( $languages as $slug => $assigned ) {
				if ( (int) $assigned === (int) $menu_id ) {
					$locations[] = (string) $location;

					if ( '' === $language ) {
						$language = (string) $slug;
					}

					break;
				}
			}
		}

		return array(
			'language'  => $language,
			'locations' => $locations,
		);
	}

	/**
	 * Every navigation menu with its language, locations and per-language copies.
	 *
	 * @since 1.2.0
	 * @return array<int, array<string, mixed>> Menus.
	 */
	public static function list_menus() {
		$menus = wp_get_nav_menus();
		$list  = array();

		foreach ( is_array( $menus ) ? $menus : array() as $menu ) {
			$info   = self::menu_info( (int) $menu->term_id );
			$others = array();

			foreach ( self::assignments() as $location => $languages ) {
				if ( ! in_array( (string) $location, $info['locations'], true ) || ! is_array( $languages ) ) {
					continue;
				}

				foreach ( $languages as $slug => $assigned ) {
					if ( (int) $assigned > 0 && (int) $assigned !== (int) $menu->term_id ) {
						$others[ (string) $slug ] = (int) $assigned;
					}
				}
			}

			$list[] = array(
				'id'             => (int) $menu->term_id,
				'name'           => (string) $menu->name,
				'item_count'     => (int) $menu->count,
				'language'       => $info['language'],
				'locations'      => $info['locations'],
				'language_menus' => (object) $others,
			);
		}

		return $list;
	}

	/**
	 * The copy of a menu in another language, when one exists.
	 *
	 * Looked up by theme location first, then by the "Name (Language)" naming Menu Synchronization uses.
	 *
	 * @since 1.2.0
	 * @param int    $menu_id Source menu ID.
	 * @param string $slug    Target language slug.
	 * @return array<string, mixed>|null ID, name and how it was found, or null.
	 */
	public static function find_translation( $menu_id, $slug ) {
		$menu     = wp_get_nav_menu_object( (int) $menu_id );
		$language = Linguator_Guard::language( (string) $slug );

		if ( ! $menu || is_wp_error( $language ) ) {
			return null;
		}

		$target = self::resolve_target( $menu, $language );

		if ( 'overwrite' !== $target['action'] || $target['id'] <= 0 || $target['id'] === (int) $menu_id ) {
			return null;
		}

		return array(
			'id'         => (int) $target['id'],
			'name'       => (string) $target['name'],
			'matched_by' => (string) $target['matched_by'],
		);
	}

	/**
	 * Whether the sync feature can be reached in this version of Linguator.
	 *
	 * @since 1.2.0
	 * @return true|WP_Error True when ready, or why not.
	 */
	public static function check_ready() {
		if ( ! class_exists( self::SYNC_CLASS ) ) {
			return new WP_Error(
				'lmat_menu_sync_unavailable',
				__( 'Linguator\'s Menu Synchronization is not available in this version of the plugin.', 'linguator-pro' )
			);
		}

		foreach ( self::SYNC_METHODS as $method ) {
			if ( ! method_exists( self::SYNC_CLASS, $method ) ) {
				return new WP_Error(
					'lmat_menu_sync_unavailable',
					__( 'Linguator\'s Menu Synchronization is not available in this version of the plugin.', 'linguator-pro' )
				);
			}
		}

		if ( ! self::is_enabled() ) {
			return new WP_Error(
				'lmat_menu_sync_disabled',
				__( 'Menu Synchronization is switched off in Linguator. A site administrator can switch it on in Linguator settings (Menu Synchronization), then try again.', 'linguator-pro' )
			);
		}

		return true;
	}

	/**
	 * Calls a private method of Linguator's menu sync controller.
	 *
	 * @since 1.2.0
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed Whatever the method returns.
	 */
	private static function call( $method, ...$args ) {
		if ( null === self::$sync ) {
			$linguator  = LMAT();
			$class      = self::SYNC_CLASS;
			self::$sync = new $class( $linguator, true );
		}

		$reflection = new \ReflectionMethod( self::$sync, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( self::$sync, $args );
	}

	/**
	 * Reports what syncing a menu would do, and changes nothing.
	 *
	 * @since 1.2.0
	 * @param int      $menu_id   Source menu ID.
	 * @param string[] $requested Target language slugs, or none for every other language.
	 * @return array<string, mixed>|WP_Error Preview, or an error.
	 */
	public static function preview( $menu_id, array $requested ) {
		$plan = self::plan( (int) $menu_id, $requested );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$languages = array();

		foreach ( $plan['eligible'] as $slug => $entry ) {
			$languages[] = array_merge(
				array(
					'slug'        => $slug,
					'name'        => (string) $entry['language']->name,
					'target_menu' => $entry['target'],
				),
				self::analyze_items( $plan['items'], $slug ),
				array( 'items_replaced' => self::describe_existing_items( $entry['target']['id'] ) )
			);
		}

		return array(
			'dry_run'   => true,
			'menu'      => $plan['menu'],
			'languages' => $languages,
			'refused'   => $plan['refused'],
			'warnings'  => $plan['warnings'],
		);
	}

	/**
	 * Syncs a menu into other languages with Linguator's own code.
	 *
	 * The target menu of each language is replaced: its items are deleted and rebuilt.
	 *
	 * @since 1.2.0
	 * @param int      $menu_id   Source menu ID.
	 * @param string[] $requested Target language slugs, or none for every other language.
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	public static function sync( $menu_id, array $requested ) {
		$plan = self::plan( (int) $menu_id, $requested );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( array() === $plan['eligible'] ) {
			return new WP_Error(
				'lmat_menu_sync_nothing_to_do',
				__( 'None of the requested languages can be synced. See "refused" for the reason given for each.', 'linguator-pro' ),
				array( 'refused' => $plan['refused'] )
			);
		}

		$replaced = array();

		foreach ( $plan['eligible'] as $slug => $entry ) {
			$replaced[ $slug ] = count( self::describe_existing_items( $entry['target']['id'] ) );
		}

		$result    = self::call( 'linguator_sync_menu_to_languages', (int) $plan['menu']['id'], array_keys( $plan['eligible'] ) );
		$result    = is_array( $result ) ? $result : array();
		$details   = isset( $result['details'] ) && is_array( $result['details'] ) ? $result['details'] : array();
		$languages = array();

		foreach ( $plan['eligible'] as $slug => $entry ) {
			$detail    = isset( $details[ $slug ] ) && is_array( $details[ $slug ] ) ? $details[ $slug ] : array();
			$synced    = isset( $detail['synced'] ) ? (int) $detail['synced'] : 0;
			$target_id = isset( $detail['menu_id'] ) ? (int) $detail['menu_id'] : 0;
			$target    = $target_id ? wp_get_nav_menu_object( $target_id ) : false;

			$language_entry = array(
				'slug'           => $slug,
				'name'           => (string) $entry['language']->name,
				'menu_id'        => $target_id,
				'menu_name'      => $target ? (string) $target->name : '',
				'action'         => 'overwrite' === $entry['target']['action'] ? 'overwritten' : 'created',
				'items_synced'   => $synced,
				'items_skipped'  => isset( $detail['skipped'] ) ? (int) $detail['skipped'] : 0,
				'items_replaced' => $replaced[ $slug ],
				'labels'         => self::label_sources( $plan['items'], $slug ),
			);

			if ( $target_id > 0 && Template_Service::elementor_active() ) {
				$language_entry['elementor_header'] = self::describe_widget_binding(
					Template_Service::nav_widgets_in_language( $slug ),
					(int) $plan['menu']['id'],
					$target_id,
					$slug
				);
			}

			$languages[] = $language_entry;
		}

		if ( empty( $result['success'] ) ) {
			$code = isset( $result['error_code'] ) ? sanitize_key( (string) $result['error_code'] ) : 'failed';

			return new WP_Error(
				'lmat_menu_sync_' . ( '' !== $code ? $code : 'failed' ),
				isset( $result['message'] ) ? (string) $result['message'] : __( 'The menu could not be synced.', 'linguator-pro' ),
				array(
					'languages' => $languages,
					'refused'   => $plan['refused'],
					'warnings'  => $plan['warnings'],
				)
			);
		}

		return array(
			'success'                 => true,
			'dry_run'                 => false,
			'menu'                    => $plan['menu'],
			'languages'               => $languages,
			'refused'                 => $plan['refused'],
			'warnings'                => $plan['warnings'],
			'elementor_cache_cleared' => Template_Service::clear_element_cache(),
		);
	}

	/**
	 * Decides which of the requested languages can be synced and why the others cannot.
	 *
	 * @since 1.2.0
	 * @param int      $menu_id   Source menu ID.
	 * @param string[] $requested Requested language slugs.
	 * @return array<string, mixed>|WP_Error Plan, or an error.
	 */
	private static function plan( $menu_id, array $requested ) {
		$ready = self::check_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$menu = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : false;

		if ( ! $menu ) {
			return new WP_Error(
				'lmat_menu_not_found',
				sprintf(
					/* translators: %d: menu ID. */
					__( 'No navigation menu with ID %d. Call get-translation-plan to list menus under available_menus.', 'linguator-pro' ),
					$menu_id
				)
			);
		}

		$menu_id = (int) $menu->term_id;
		$items   = wp_get_nav_menu_items( $menu_id );

		if ( empty( $items ) || ! is_array( $items ) ) {
			return new WP_Error(
				'lmat_menu_empty',
				__( 'The source menu is empty. Please add menu items before syncing.', 'linguator-pro' )
			);
		}

		$languages = array();

		foreach ( (array) Linguator_Guard::model()->get_languages_list() as $language ) {
			$languages[ (string) $language->slug ] = $language;
		}

		$info        = self::menu_info( $menu_id );
		$source_lang = $info['language'];
		$locations   = $info['locations'];
		$slugs       = array();

		foreach ( $requested as $slug ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' !== $slug ) {
				$slugs[ $slug ] = true;
			}
		}

		$slugs = array_keys( $slugs );

		if ( array() === $slugs ) {
			// Without a known language, "every language but its own" would include the menu's own.
			if ( '' === $source_lang ) {
				return new WP_Error(
					'lmat_menu_language_unknown',
					sprintf(
						/* translators: %s: menu name. */
						__( 'Menu "%s" is not assigned to a theme location for any language, so its language is unknown. Pass target_languages explicitly, or first assign it to a location for its language in Appearance > Menus > Manage Locations.', 'linguator-pro' ),
						$menu->name
					)
				);
			}

			$slugs = array_values( array_diff( array_keys( $languages ), array( $source_lang ) ) );
		}

		$eligible = array();
		$refused  = array();

		foreach ( $slugs as $slug ) {
			if ( ! isset( $languages[ $slug ] ) ) {
				$refused[] = self::refusal( $slug, 'unknown_language', __( 'No Linguator language has this slug.', 'linguator-pro' ) );
				continue;
			}

			if ( $slug === $source_lang ) {
				$refused[] = self::refusal( $slug, 'source_language', __( 'This is the menu\'s own language. Syncing into it would overwrite the source menu.', 'linguator-pro' ) );
				continue;
			}

			if ( ! self::has_syncable_item( $items, $languages[ $slug ] ) ) {
				$refused[] = self::refusal( $slug, 'nothing_to_sync', __( 'No item in this menu has a published translation in this language, and the menu has no custom links. Translate the linked content first.', 'linguator-pro' ) );
				continue;
			}

			$target = self::resolve_target( $menu, $languages[ $slug ] );

			if ( $target['id'] === $menu_id ) {
				$refused[] = self::refusal( $slug, 'target_is_source', __( 'The menu Linguator would overwrite for this language is the source menu itself. Rename the source menu or sync from a different menu.', 'linguator-pro' ) );
				continue;
			}

			$eligible[ $slug ] = array(
				'language' => $languages[ $slug ],
				'target'   => $target,
			);
		}

		$warnings = array();

		if ( array() === $locations ) {
			if ( Template_Service::elementor_active() ) {
				$warnings[] = __( 'The source menu is not assigned to a theme location. That only matters for menus a theme prints from a location, because Linguator assigns each synced menu to the locations of the source. An Elementor Nav Menu widget picks its menu inside the widget, so assigning a location will not make an Elementor header show the synced menu. Use set-template-menu on the translated header; the result lists the widgets under elementor_header.', 'linguator-pro' );
			} else {
				$warnings[] = __( 'The source menu is not assigned to any theme location, so the synced menus will be created but not shown on the site until they are assigned in Appearance > Menus > Manage Locations.', 'linguator-pro' );
			}
		}

		return array(
			'menu'     => array(
				'id'        => $menu_id,
				'name'      => (string) $menu->name,
				'language'  => $source_lang,
				'locations' => $locations,
			),
			'items'    => $items,
			'eligible' => $eligible,
			'refused'  => $refused,
			'warnings' => $warnings,
		);
	}

	/**
	 * Whether at least one item of a menu can be synced into a language.
	 *
	 * @since 1.2.0
	 * @param array<int, object> $items    Menu items.
	 * @param object             $language Target language.
	 * @return bool True when one can.
	 */
	private static function has_syncable_item( array $items, $language ) {
		foreach ( $items as $item ) {
			if ( self::can_sync_item( $item, $language ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether Linguator's sync can copy an item into a language.
	 *
	 * @since 1.2.0
	 * @param object $item     Menu item.
	 * @param object $language Target language.
	 * @return bool True when it can.
	 */
	private static function can_sync_item( $item, $language ) {
		return (bool) self::call( 'linguator_can_sync_item', $item, $language );
	}

	/**
	 * Finds the menu Linguator would write into for a language.
	 *
	 * Mirrors the naming of Menu Synchronization: "Name (Language)". An existing menu of that name is
	 * reused (and emptied) unless it belongs to another language.
	 *
	 * @since 1.2.0
	 * @param object $menu     Source menu.
	 * @param object $language Target language.
	 * @return array<string, mixed> ID (0 when new), name, action "overwrite" or "create", and how it was matched.
	 */
	private static function resolve_target( $menu, $language ) {
		$slug = (string) $language->slug;

		// A menu already assigned to the same location for this language is that language's copy.
		foreach ( self::menu_info( (int) $menu->term_id )['locations'] as $location ) {
			$assigned = (int) ( self::assignments()[ $location ][ $slug ] ?? 0 );
			$existing = $assigned > 0 ? wp_get_nav_menu_object( $assigned ) : false;

			if ( $existing && $assigned !== (int) $menu->term_id ) {
				return array(
					'id'         => $assigned,
					'name'       => (string) $existing->name,
					'action'     => 'overwrite',
					'matched_by' => 'location',
				);
			}
		}

		$name     = self::format_menu_name( (string) $menu->name, (string) $language->name );
		$existing = wp_get_nav_menu_object( $name );

		if ( $existing ) {
			$existing_lang = self::menu_info( (int) $existing->term_id )['language'];

			if ( '' === $existing_lang || $existing_lang === $slug ) {
				return array(
					'id'         => (int) $existing->term_id,
					'name'       => (string) $existing->name,
					'action'     => 'overwrite',
					'matched_by' => 'name',
				);
			}

			$name = self::unique_menu_name( (string) $menu->name, (string) $language->name );
		}

		return array(
			'id'         => 0,
			'name'       => $name,
			'action'     => 'create',
			'matched_by' => '',
		);
	}

	/**
	 * Formats a menu name as "Base (Language)" within WordPress's 200-character term name limit.
	 *
	 * @since 1.2.0
	 * @param string $base_name Base menu name.
	 * @param string $lang_name Language name.
	 * @param string $extra     Optional uniqueness suffix.
	 * @return string Menu name.
	 */
	private static function format_menu_name( $base_name, $lang_name, $extra = '' ) {
		$base_name = sanitize_text_field( $base_name );
		$lang_name = sanitize_text_field( $lang_name );
		$extra     = sanitize_text_field( $extra );
		$suffix    = ' (' . $lang_name . ')' . ( '' !== $extra ? ' ' . $extra : '' );

		if ( strlen( $suffix ) >= 200 ) {
			return substr( $suffix, 0, 200 );
		}

		$available = 200 - strlen( $suffix );

		if ( strlen( $base_name ) > $available ) {
			$base_name = rtrim( substr( $base_name, 0, $available ) );
		}

		return $base_name . $suffix;
	}

	/**
	 * A menu name no existing menu uses.
	 *
	 * @since 1.2.0
	 * @param string $base_name Base menu name.
	 * @param string $lang_name Language name.
	 * @return string Unique name.
	 */
	private static function unique_menu_name( $base_name, $lang_name ) {
		$name    = self::format_menu_name( $base_name, $lang_name );
		$counter = 1;

		while ( wp_get_nav_menu_object( $name ) ) {
			$name = self::format_menu_name( $base_name, $lang_name, (string) $counter );
			++$counter;

			if ( $counter > 100 ) {
				return self::format_menu_name( $base_name, $lang_name, (string) time() );
			}
		}

		return $name;
	}

	/**
	 * Reports what syncing does to every item of a menu, for one language.
	 *
	 * @since 1.2.0
	 * @param array<int, object> $items Source menu items.
	 * @param string             $slug  Target language slug.
	 * @return array<string, mixed> Items to sync, skipped, moved, label handling and custom links.
	 */
	private static function analyze_items( array $items, $slug ) {
		$language  = Linguator_Guard::language( $slug );
		$written   = array();
		$skipped   = array();
		$moved     = array();
		$unchanged = array();
		$labelled  = array();
		$custom    = array();

		foreach ( $items as $item ) {
			if ( ! self::can_sync_item( $item, $language ) ) {
				$skipped[] = self::describe_item( $item ) + array( 'reason' => self::skip_reason( $item, $slug ) );
				continue;
			}

			$written[ (int) $item->ID ] = true;

			if ( ! empty( $item->menu_item_parent ) && ! isset( $written[ (int) $item->menu_item_parent ] ) ) {
				$moved[] = self::describe_item( $item );
			}

			$label = self::label_for( $item, $slug );

			if ( 'left_in_source' === $label['source'] ) {
				$unchanged[] = self::describe_item( $item );
			} elseif ( in_array( $label['source'], array( 'glossary', 'machine_translation' ), true ) ) {
				$labelled[] = self::describe_item( $item ) + array(
					'label'        => $label['label'],
					'label_source' => $label['source'],
				);
			}

			if ( 'custom' === $item->type ) {
				$custom[] = self::describe_item( $item );
			}
		}

		return array(
			'items_to_sync'      => count( $written ),
			'items_skipped'      => $skipped,
			'moved_to_top_level' => $moved,
			'labels_unchanged'   => $unchanged,
			'labels_translated'  => $labelled,
			'labels'             => self::label_sources( $items, $slug ),
			'custom_links_kept'  => $custom,
		);
	}

	/**
	 * The label each syncable item gets in a language, and where it comes from.
	 *
	 * @since 1.2.0
	 * @param array<int, object> $items Source menu items.
	 * @param string             $slug  Target language slug.
	 * @return array<int, array<string, mixed>> One entry per syncable item.
	 */
	private static function label_sources( array $items, $slug ) {
		$language = Linguator_Guard::language( $slug );
		$out      = array();

		foreach ( $items as $item ) {
			if ( is_wp_error( $language ) || ! self::can_sync_item( $item, $language ) ) {
				continue;
			}

			$label = self::label_for( $item, $slug );
			$out[] = array(
				'item_id'      => (int) $item->ID,
				'source_label' => (string) $item->title,
				'label'        => $label['label'],
				'label_source' => $label['source'],
			);
		}

		return $out;
	}

	/**
	 * Works out the label of one item in a language without translating anything.
	 *
	 * Mirrors Menu Synchronization: an item that shows the linked page or term title takes the title
	 * of the translation; a custom label takes a glossary translation when there is one, otherwise it
	 * goes to machine translation when that is switched on, otherwise it stays in the source language.
	 *
	 * @since 1.2.0
	 * @param object $item Source menu item.
	 * @param string $slug Target language slug.
	 * @return array{label: string, source: string} Label and its origin: page_title, term_name, glossary, machine_translation or left_in_source.
	 */
	private static function label_for( $item, $slug ) {
		$title = (string) $item->title;

		if ( 'post_type' === $item->type ) {
			$translated = (int) ( linguator_get_post_translations( (int) $item->object_id )[ $slug ] ?? 0 );
			$original   = get_post( (int) $item->object_id );
			$post       = $translated > 0 ? get_post( $translated ) : null;

			if ( $post && $original && $title === (string) $original->post_title ) {
				return array(
					'label'  => (string) $post->post_title,
					'source' => 'page_title',
				);
			}
		} elseif ( 'taxonomy' === $item->type ) {
			$translated = (int) ( linguator_get_term_translations( (int) $item->object_id )[ $slug ] ?? 0 );
			$original   = get_term( (int) $item->object_id );
			$term       = $translated > 0 ? get_term( $translated ) : null;

			if ( $term && ! is_wp_error( $term ) && $original && ! is_wp_error( $original ) && $title === (string) $original->name ) {
				return array(
					'label'  => (string) $term->name,
					'source' => 'term_name',
				);
			}
		}

		$default = (string) linguator_default_language();
		$entries = Glossary_Service::is_available() ? Glossary_Service::get_entries( $default, array( $slug ), '', '', 500, 0 )['entries'] : array();

		foreach ( $entries as $entry ) {
			if ( 0 === strcasecmp( trim( (string) $entry['term'] ), trim( $title ) ) && isset( $entry['translations'][ $slug ] ) ) {
				return array(
					'label'  => (string) $entry['translations'][ $slug ],
					'source' => 'glossary',
				);
			}
		}

		$linguator = LMAT();
		$ai_config = isset( $linguator->options ) ? $linguator->options->get( 'ai_translation_configuration' ) : array();

		if ( is_array( $ai_config ) && ! empty( $ai_config['provider']['google'] ) ) {
			return array(
				'label'  => $title,
				'source' => 'machine_translation',
			);
		}

		return array(
			'label'  => $title,
			'source' => 'left_in_source',
		);
	}

	/**
	 * Says whether the header or footer of a language will show a synced menu.
	 *
	 * @since 1.2.0
	 * @param array<int, array<string, mixed>> $widgets   Nav Menu widgets of the language's templates.
	 * @param int                              $source_id Source menu ID.
	 * @param int                              $target_id Synced menu ID.
	 * @param string                           $slug      Language slug.
	 * @return array<string, mixed> Status, widgets involved and a note.
	 */
	private static function describe_widget_binding( array $widgets, $source_id, $target_id, $slug ) {
		$involved = array();
		$needs    = false;
		$shows    = false;

		foreach ( $widgets as $widget ) {
			if ( $widget['menu_id'] === $target_id ) {
				$shows      = true;
				$involved[] = array(
					'template_id' => $widget['template_id'],
					'widget_id'   => $widget['widget_id'],
					'shows'       => 'synced_menu',
				);
			} elseif ( $widget['menu_id'] === $source_id ) {
				$needs      = true;
				$involved[] = array(
					'template_id' => $widget['template_id'],
					'widget_id'   => $widget['widget_id'],
					'shows'       => 'source_menu',
					'action'      => sprintf(
						/* translators: 1: template ID, 2: widget ID, 3: menu ID. */
						__( 'Call set-template-menu with template_id %1$d, widget_id %2$s and menu_id %3$d.', 'linguator-pro' ),
						$widget['template_id'],
						$widget['widget_id'],
						$target_id
					),
				);
			}
		}

		if ( $needs ) {
			$status = 'needs_set_template_menu';
			$note   = sprintf(
				/* translators: %s: language slug. */
				__( 'The %s header or footer still shows the source menu. Elementor does not switch menus by language; follow the action on each widget.', 'linguator-pro' ),
				$slug
			);
		} elseif ( $shows ) {
			$status = 'shows_synced_menu';
			$note   = __( 'An Elementor Nav Menu widget in this language already shows the synced menu.', 'linguator-pro' );
		} else {
			$status = 'no_widget_found';
			$note   = __( 'No Elementor Nav Menu widget in a template of this language shows the source or synced menu. If the site uses an Elementor header, translate it first (translate-post), then call set-template-menu.', 'linguator-pro' );
		}

		return array(
			'status'  => $status,
			'widgets' => $involved,
			'note'    => $note,
		);
	}

	/**
	 * Why an item is left out of a language.
	 *
	 * @since 1.2.0
	 * @param object $item Menu item.
	 * @param string $slug Language slug.
	 * @return string Reason code.
	 */
	private static function skip_reason( $item, $slug ) {
		if ( 'post_type' === $item->type && (int) ( linguator_get_post_translations( (int) $item->object_id )[ $slug ] ?? 0 ) > 0 ) {
			return 'translation_not_published';
		}

		return 'no_translation';
	}

	/**
	 * The items a sync would delete from an existing target menu.
	 *
	 * @since 1.2.0
	 * @param int $menu_id Target menu ID, or 0 for a menu that does not exist yet.
	 * @return array<int, array<string, mixed>> Items.
	 */
	private static function describe_existing_items( $menu_id ) {
		if ( $menu_id <= 0 ) {
			return array();
		}

		$items = wp_get_nav_menu_items( $menu_id );

		return is_array( $items ) ? array_map( array( self::class, 'describe_item' ), $items ) : array();
	}

	/**
	 * A short description of a menu item.
	 *
	 * @since 1.2.0
	 * @param object $item Menu item.
	 * @return array<string, mixed> Item ID, title, type, object and URL or object ID.
	 */
	private static function describe_item( $item ) {
		$description = array(
			'item_id' => (int) $item->ID,
			'title'   => (string) $item->title,
			'type'    => (string) $item->type,
			'object'  => (string) $item->object,
		);

		if ( 'custom' === $item->type ) {
			$description['url'] = (string) $item->url;
		} else {
			$description['object_id'] = (int) $item->object_id;
		}

		return $description;
	}

	/**
	 * A language the sync will not touch, with the reason.
	 *
	 * @since 1.2.0
	 * @param string $slug    Language slug.
	 * @param string $reason  Reason code.
	 * @param string $message Explanation.
	 * @return array<string, string> Refusal.
	 */
	private static function refusal( $slug, $reason, $message ) {
		return array(
			'slug'    => (string) $slug,
			'reason'  => $reason,
			'message' => $message,
		);
	}
}
