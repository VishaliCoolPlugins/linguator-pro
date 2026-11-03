<?php
/**
 * Navigation menu synchronisation through AutoPoly's own Menu Sync feature.
 *
 * AutoPoly copies a menu's structure into other languages from the
 * "Menu Synchronization" button on Appearance > Menus
 * (includes/menu-sync/class-atfpp-menu-sync.php). This service drives that
 * same code so a menu synced over MCP and one synced from wp-admin can never
 * come out differently. The sync itself is always
 * ATFPP_Menu_Sync::sync_menu_to_languages(); nothing here writes a menu.
 *
 * Every ATFPP_Menu_Sync method relied on is listed in LEGACY_METHODS and
 * checked before anything runs, so a future AutoPoly that renames one makes
 * the ability report itself unavailable instead of failing half way through a
 * write.
 *
 * What is added on top of the legacy behaviour is purely protective:
 *
 *   - A dry run that reports, per language, which menu would be overwritten or
 *     created, which items would be dropped, and which existing items would be
 *     deleted.
 *   - Refusal of targets the legacy code would destroy. When one menu is
 *     assigned to a location in two languages, or a caller names the menu's
 *     own language, ATFPP_Menu_Sync resolves the source menu as its own
 *     target, deletes its items and rebuilds it in the other language. The
 *     admin modal hides the second case; nothing guards the first.
 *   - Refusal of targets that could never succeed, such as a location that
 *     still points at a deleted menu.
 *
 * And, only for syncs run through this service (never the wp-admin button),
 * two translations the legacy code leaves out, applied through the
 * atfpp_menu_sync_item_data filter while the sync runs:
 *
 *   - A custom label, which the legacy code copies in the source language, takes
 *     its Polylang string translation when one exists (set with translate-string).
 *   - A custom link to a page on this site is pointed at that page's translation.
 *
 * @package AutoPoly
 * @since   1.11.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps ATFPP_Menu_Sync for the abilities layer.
 *
 * @since 1.11.0
 */
final class Menu_Service {

	/**
	 * AutoPoly's menu sync controller.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const LEGACY_CLASS = 'ATFPP_Menu_Sync';

	/**
	 * Public ATFPP_Menu_Sync methods this service calls.
	 *
	 * @since 1.11.0
	 * @var string[]
	 */
	const LEGACY_METHODS = array(
		'get_languages',
		'get_menu_language',
		'get_menu_locations',
		'get_target_menu_id_from_locations',
		'menu_has_syncable_item',
		'can_sync_item',
		'format_menu_name',
		'generate_unique_menu_name',
		'menu_name_can_be_reused',
		'get_post_type_item_title',
		'get_taxonomy_item_title',
		'sync_menu_to_languages',
	);

	/**
	 * Filter ATFPP_Menu_Sync applies to each item it writes.
	 *
	 * @since 1.13.0
	 * @var string
	 */
	const ITEM_DATA_FILTER = 'atfpp_menu_sync_item_data';

	/**
	 * Polylang string translations, one PLL_MO per language slug, for this request.
	 *
	 * False marks a slug with no Polylang language.
	 *
	 * @since 1.13.0
	 * @var array<string, \PLL_MO|false>
	 */
	private static $string_translations = array();

	/**
	 * Whether AutoPoly's Menu Sync setting is switched on.
	 *
	 * @since 1.11.0
	 * @return bool True when enabled.
	 */
	public static function is_enabled() {
		return class_exists( self::LEGACY_CLASS ) && \ATFPP_Menu_Sync::is_enabled();
	}

	/**
	 * AutoPoly's menu sync instance.
	 *
	 * Instantiating it outside wp-admin is harmless: its constructor only
	 * hooks admin_init and load-nav-menus.php, which do not fire there, and
	 * both callbacks re-check the setting before doing anything.
	 *
	 * @since 1.11.0
	 * @return \ATFPP_Menu_Sync The instance.
	 */
	private static function legacy() {
		return \ATFPP_Menu_Sync::get_instance();
	}

	/**
	 * Whether the legacy class and every method relied on can be called.
	 *
	 * is_callable() against the instance, rather than method_exists(), so a
	 * method that exists but is not public counts as unavailable.
	 *
	 * @since 1.11.0
	 * @return bool True when the legacy code can be driven.
	 */
	private static function is_compatible() {
		if ( ! class_exists( self::LEGACY_CLASS ) || ! is_callable( array( self::LEGACY_CLASS, 'get_instance' ) ) ) {
			return false;
		}

		$instance = self::legacy();

		foreach ( self::LEGACY_METHODS as $method ) {
			if ( ! is_callable( array( $instance, $method ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether Polylang's public API functions used by the legacy code exist.
	 *
	 * Mirrors ATFPP_Menu_Sync::can_load().
	 *
	 * @since 1.11.0
	 * @return bool True when available.
	 */
	private static function polylang_ready() {
		return function_exists( 'pll_languages_list' )
			&& function_exists( 'pll_get_post' )
			&& function_exists( 'pll_get_term' );
	}

	/**
	 * Checks everything a sync or dry run needs.
	 *
	 * The AutoPoly setting is honoured for dry runs too: when an administrator
	 * has switched Menu Sync off, it is off everywhere.
	 *
	 * @since 1.11.0
	 * @return true|WP_Error True when ready, otherwise the reason.
	 */
	public static function check_ready() {
		if ( ! self::is_compatible() ) {
			return new WP_Error(
				'atfpp_menu_sync_unavailable',
				__( 'AutoPoly\'s menu sync feature is not available in this version of the plugin.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		if ( ! self::polylang_ready() ) {
			return Polylang_Guard::unavailable_error();
		}

		if ( ! self::is_enabled() ) {
			return new WP_Error(
				'atfpp_menu_sync_disabled',
				__( 'Menu Sync is switched off in AutoPoly. An administrator can enable it under Languages > AutoPoly > Settings > Menu Sync.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		return true;
	}

	/**
	 * Lists every navigation menu with the language and locations Polylang gives it.
	 *
	 * Read-only, and available even while Menu Sync is switched off, because
	 * it answers "which menu is shown for which language" in its own right.
	 *
	 * @since 1.11.0
	 * @return array<int, array<string, mixed>> One descriptor per menu.
	 */
	public static function list_menus() {
		if ( ! self::is_compatible() || ! self::polylang_ready() ) {
			return array();
		}

		$menus = wp_get_nav_menus();

		if ( ! is_array( $menus ) || array() === $menus ) {
			return array();
		}

		$sync      = self::legacy();
		$languages = $sync->get_languages();
		$list      = array();

		foreach ( $menus as $menu ) {
			$menu_id  = (int) $menu->term_id;
			$language = (string) $sync->get_menu_language( $menu_id );
			$others   = array();
			$shared   = array();

			foreach ( $languages as $candidate ) {
				if ( $candidate['slug'] === $language ) {
					continue;
				}

				$target = (int) $sync->get_target_menu_id_from_locations( $menu_id, $candidate['slug'] );

				if ( $target === $menu_id ) {
					$shared[] = $candidate['slug'];
				} elseif ( $target > 0 ) {
					$others[ $candidate['slug'] ] = $target;
				}
			}

			$list[] = array(
				'id'                    => $menu_id,
				'name'                  => (string) $menu->name,
				'item_count'            => (int) $menu->count,
				'language'              => $language,
				'locations'             => $sync->get_menu_locations( $menu_id ),
				// Cast so an empty map serialises as {} rather than [].
				'language_menus'        => (object) $others,
				'shared_with_languages' => $shared,
			);
		}

		return $list;
	}

	/**
	 * The language and theme locations Polylang gives one menu.
	 *
	 * @since 1.14.0
	 * @param int $menu_id Menu ID.
	 * @return array{language: string, locations: array<int|string, mixed>}|null Null when the legacy code cannot be driven.
	 */
	public static function menu_info( $menu_id ) {
		if ( ! self::is_compatible() || ! self::polylang_ready() ) {
			return null;
		}

		return array(
			'language'  => (string) self::legacy()->get_menu_language( (int) $menu_id ),
			'locations' => (array) self::legacy()->get_menu_locations( (int) $menu_id ),
		);
	}

	/**
	 * The menu a language already has for a source menu, without creating anything.
	 *
	 * @since 1.14.0
	 * @param int    $menu_id Source menu ID.
	 * @param string $slug    Target language slug.
	 * @return array{id: int, name: string, matched_by: string}|null Null when there is none yet.
	 */
	public static function find_translation( $menu_id, $slug ) {
		if ( ! self::is_compatible() || ! self::polylang_ready() ) {
			return null;
		}

		$menu     = wp_get_nav_menu_object( (int) $menu_id );
		$language = Language_Service::get_by_slug( (string) $slug );

		if ( ! $menu || null === $language ) {
			return null;
		}

		$target = self::resolve_target( $menu, array( 'slug' => $language->slug, 'name' => $language->name ) );

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
	 * Reports what a sync would do, without changing anything.
	 *
	 * @since 1.11.0
	 * @param int      $menu_id   Source menu ID.
	 * @param string[] $requested Target language slugs, or empty for every other language.
	 * @return array<string, mixed>|WP_Error Preview payload, or an error.
	 */
	public static function preview( $menu_id, array $requested ) {
		$plan = self::plan( (int) $menu_id, $requested );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$languages   = array();
		$source_lang = self::resolve_source_language( $plan['menu']['language'] );

		foreach ( $plan['eligible'] as $slug => $entry ) {
			$languages[] = array_merge(
				array(
					'slug'        => $slug,
					'name'        => $entry['language']['name'],
					'target_menu' => $entry['target'],
				),
				self::analyze_items( $plan['items'], $slug, $source_lang ),
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
	 * Syncs a menu into the given languages through ATFPP_Menu_Sync.
	 *
	 * @since 1.11.0
	 * @param int      $menu_id   Source menu ID.
	 * @param string[] $requested Target language slugs, or empty for every other language.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function sync( $menu_id, array $requested ) {
		$plan = self::plan( (int) $menu_id, $requested );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( array() === $plan['eligible'] ) {
			return new WP_Error(
				'atfpp_menu_sync_nothing_to_do',
				__( 'None of the requested languages can be synced. See "refused" for the reason given for each.', 'autopoly-ai-translation-for-polylang-pro' ),
				array( 'refused' => $plan['refused'] )
			);
		}

		// Counted beforehand: the legacy code deletes them before rebuilding.
		$replaced = array();

		foreach ( $plan['eligible'] as $slug => $entry ) {
			$replaced[ $slug ] = count( self::describe_existing_items( $entry['target']['id'] ) );
		}

		/*
		 * Hooked for this call only and always removed, so the sync from
		 * Appearance > Menus, which runs the same legacy method, is unaffected.
		 */
		$source_lang = self::resolve_source_language( $plan['menu']['language'] );
		$callback    = static function ( $item_data, $item, $target_lang ) use ( $source_lang ) {
			if ( ! is_array( $item_data ) || ! is_object( $item ) ) {
				return $item_data;
			}

			return self::translate_item_data( $item_data, $item, (string) $target_lang, $source_lang );
		};

		add_filter( self::ITEM_DATA_FILTER, $callback, 10, 3 );

		try {
			$result = self::legacy()->sync_menu_to_languages( (int) $plan['menu']['id'], array_keys( $plan['eligible'] ) );
		} finally {
			remove_filter( self::ITEM_DATA_FILTER, $callback, 10 );
		}

		$result = is_array( $result ) ? $result : array();

		$details   = isset( $result['details'] ) && is_array( $result['details'] ) ? $result['details'] : array();
		$languages = array();
		$warnings  = $plan['warnings'];

		foreach ( $plan['eligible'] as $slug => $entry ) {
			$detail    = isset( $details[ $slug ] ) && is_array( $details[ $slug ] ) ? $details[ $slug ] : array();
			$synced    = isset( $detail['synced'] ) ? (int) $detail['synced'] : 0;
			$target_id = isset( $detail['menu_id'] ) ? (int) $detail['menu_id'] : 0;
			$target    = $target_id ? wp_get_nav_menu_object( $target_id ) : false;

			/*
			 * When no item could be written, ATFPP_Menu_Sync deletes the target
			 * menu, including one that existed before the sync. Rare, because
			 * plan() only passes languages with at least one syncable item, but
			 * it must never go unreported.
			 */
			if ( 0 === $synced && 'overwrite' === $entry['target']['action'] && ! wp_get_nav_menu_object( $entry['target']['id'] ) ) {
				$warnings[] = sprintf(
					/* translators: 1: menu name, 2: language name. */
					__( 'No item could be written to "%1$s", so AutoPoly deleted that menu and %2$s now has no menu for these locations.', 'autopoly-ai-translation-for-polylang-pro' ),
					$entry['target']['name'],
					$entry['language']['name']
				);
			}

			$language_entry = array(
				'slug'           => $slug,
				'name'           => $entry['language']['name'],
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
				'atfpp_menu_sync_' . ( '' !== $code ? $code : 'failed' ),
				isset( $result['message'] ) ? (string) $result['message'] : __( 'The menu could not be synced.', 'autopoly-ai-translation-for-polylang-pro' ),
				array(
					'languages' => $languages,
					'refused'   => $plan['refused'],
					'warnings'  => $warnings,
				)
			);
		}

		return array(
			'success'                 => true,
			'dry_run'                 => false,
			'menu'                    => $plan['menu'],
			'languages'               => $languages,
			'refused'                 => $plan['refused'],
			'warnings'                => $warnings,
			// The rebuilt menu is what a cached Elementor header would keep serving in its old form.
			'elementor_cache_cleared' => Template_Service::clear_element_cache(),
		);
	}

	/**
	 * Validates a request and decides which languages may be synced.
	 *
	 * Shared by preview() and sync() so a dry run reports exactly what a real
	 * run would do.
	 *
	 * @since 1.11.0
	 * @param int      $menu_id   Source menu ID.
	 * @param string[] $requested Target language slugs, or empty for every other language.
	 * @return array<string, mixed>|WP_Error The plan, or an error.
	 */
	private static function plan( $menu_id, array $requested ) {
		$ready = self::check_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$menu = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : false;

		if ( ! $menu ) {
			return new WP_Error(
				'atfpp_menu_not_found',
				sprintf(
					/* translators: %d: menu ID. */
					__( 'No navigation menu with ID %d. Call get-translation-settings to list menus under polylang.menus.', 'autopoly-ai-translation-for-polylang-pro' ),
					$menu_id
				)
			);
		}

		$menu_id = (int) $menu->term_id;
		$items   = wp_get_nav_menu_items( $menu_id );

		if ( empty( $items ) || ! is_array( $items ) ) {
			return new WP_Error(
				'atfpp_menu_empty',
				__( 'The source menu is empty. Please add menu items before syncing.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		$sync      = self::legacy();
		$languages = array();

		foreach ( $sync->get_languages() as $language ) {
			$languages[ $language['slug'] ] = $language;
		}

		$source_lang = (string) $sync->get_menu_language( $menu_id );
		$locations   = $sync->get_menu_locations( $menu_id );

		$slugs = array();

		foreach ( $requested as $slug ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' !== $slug ) {
				$slugs[ $slug ] = true;
			}
		}

		$slugs = array_keys( $slugs );

		if ( array() === $slugs ) {
			/*
			 * With no language on the menu there is no safe "every other
			 * language": the default language would be included, and syncing a
			 * menu into its own language copies or overwrites it.
			 */
			if ( '' === $source_lang ) {
				return new WP_Error(
					'atfpp_menu_language_unknown',
					sprintf(
						/* translators: %s: menu name. */
						__( 'Menu "%s" is not assigned to a theme location for any language, so its language is unknown. Pass target_languages explicitly, or first assign it to a location for its language in Appearance > Menus > Manage Locations.', 'autopoly-ai-translation-for-polylang-pro' ),
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
				$refused[] = self::refusal( $slug, 'unknown_language', __( 'No Polylang language has this slug.', 'autopoly-ai-translation-for-polylang-pro' ) );
				continue;
			}

			if ( $slug === $source_lang ) {
				$refused[] = self::refusal( $slug, 'source_language', __( 'This is the menu\'s own language. Syncing into it would overwrite the source menu.', 'autopoly-ai-translation-for-polylang-pro' ) );
				continue;
			}

			if ( ! $sync->menu_has_syncable_item( $items, $slug ) ) {
				$refused[] = self::refusal( $slug, 'nothing_to_sync', __( 'No item in this menu has a published translation in this language, and the menu has no custom links. Translate the linked content first.', 'autopoly-ai-translation-for-polylang-pro' ) );
				continue;
			}

			$target = self::resolve_target( $menu, $languages[ $slug ] );

			if ( $target['id'] === $menu_id ) {
				$refused[] = self::refusal( $slug, 'target_is_source', __( 'This menu is also assigned to a theme location for this language, so AutoPoly would treat it as its own translation and overwrite it. In Appearance > Menus > Manage Locations, clear that language\'s location or assign it another menu, then sync again.', 'autopoly-ai-translation-for-polylang-pro' ) );
				continue;
			}

			if ( 'stale' === $target['action'] ) {
				$refused[] = self::refusal(
					$slug,
					'stale_location',
					sprintf(
						/* translators: %d: menu ID. */
						__( 'Polylang still assigns deleted menu ID %d to this language\'s location, and AutoPoly cannot write to it. Re-save the locations in Appearance > Menus > Manage Locations, then sync again.', 'autopoly-ai-translation-for-polylang-pro' ),
						$target['id']
					)
				);
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
				$warnings[] = __( 'The source menu is not assigned to a theme location. That only matters for menus a theme prints from a location. An Elementor Nav Menu widget picks its menu inside the widget, so assigning a location will not make an Elementor header show the synced menu. Use set-template-menu on the translated header; the result lists the widgets under elementor_header.', 'autopoly-ai-translation-for-polylang-pro' );
			} else {
				$warnings[] = __( 'The source menu is not assigned to any theme location, so the synced menus will be created but not shown on the site until they are assigned in Appearance > Menus > Manage Locations.', 'autopoly-ai-translation-for-polylang-pro' );
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
	 * Works out which menu the legacy code will write for a language.
	 *
	 * Mirrors ATFPP_Menu_Sync::get_or_create_target_menu_id(), which creates the
	 * menu as a side effect and so cannot be called for a dry run. Only its
	 * control flow is repeated here; every decision inside it is delegated to
	 * the legacy methods it uses.
	 *
	 * @since 1.11.0
	 * @param \WP_Term             $menu     Source menu.
	 * @param array<string, mixed> $language Target language descriptor.
	 * @return array<string, mixed> Target id, name, action and how it was matched.
	 */
	private static function resolve_target( $menu, array $language ) {
		$sync    = self::legacy();
		$located = (int) $sync->get_target_menu_id_from_locations( (int) $menu->term_id, $language['slug'] );

		if ( $located > 0 ) {
			$existing = wp_get_nav_menu_object( $located );

			return array(
				'id'         => $located,
				'name'       => $existing ? (string) $existing->name : '',
				'action'     => $existing ? 'overwrite' : 'stale',
				'matched_by' => 'location',
			);
		}

		$name     = (string) $sync->format_menu_name( $menu->name, $language['name'] );
		$existing = wp_get_nav_menu_object( $name );

		if ( $existing && $sync->menu_name_can_be_reused( (int) $existing->term_id, $language['slug'] ) ) {
			return array(
				'id'         => (int) $existing->term_id,
				'name'       => (string) $existing->name,
				'action'     => 'overwrite',
				'matched_by' => 'name',
			);
		}

		if ( $existing ) {
			$name = (string) $sync->generate_unique_menu_name( $menu->name, $language['name'] );
		}

		return array(
			'id'         => 0,
			'name'       => $name,
			'action'     => 'create',
			'matched_by' => '',
		);
	}

	/**
	 * Predicts what happens to each source item in one language.
	 *
	 * The keep-or-skip decision and the resulting label come from the legacy
	 * methods. The parent check follows sync_menu_item(): an item keeps its
	 * parent only when that parent was written earlier in the same pass.
	 *
	 * @since 1.11.0
	 * @since 1.13.0 Added $source_lang, labels_translated and links_remapped.
	 * @param array<int, \WP_Post> $items       Source menu items, in menu order.
	 * @param string               $slug        Target language slug.
	 * @param string               $source_lang Language the menu's links are in.
	 * @return array<string, mixed> Counts and item lists.
	 */
	private static function analyze_items( array $items, $slug, $source_lang ) {
		$sync       = self::legacy();
		$written    = array();
		$skipped    = array();
		$moved      = array();
		$unchanged  = array();
		$translated = array();
		$remapped   = array();

		foreach ( $items as $item ) {
			if ( ! $sync->can_sync_item( $item, $slug ) ) {
				$skipped[] = self::describe_item( $item ) + array( 'reason' => self::skip_reason( $item, $slug ) );
				continue;
			}

			$written[ (int) $item->ID ] = true;

			if ( ! empty( $item->menu_item_parent ) && ! isset( $written[ (int) $item->menu_item_parent ] ) ) {
				$moved[] = self::describe_item( $item );
			}

			// Same decisions, in the same order, as translate_item_data() makes during the sync.
			if ( self::target_label( $item, $slug ) === (string) $item->title ) {
				$label = self::translated_label( $item, $slug );

				if ( '' !== $label ) {
					$translated[] = self::describe_item( $item ) + array( 'label' => $label );
				} else {
					$unchanged[] = self::describe_item( $item );
				}
			}

			if ( 'custom' === $item->type ) {
				$url = self::translated_custom_url( (string) $item->url, $slug, $source_lang );

				if ( $url !== (string) $item->url ) {
					$remapped[] = self::describe_item( $item ) + array( 'url_to' => $url );
				}
			}
		}

		return array(
			'items_to_sync'      => count( $written ),
			'items_skipped'      => $skipped,
			'moved_to_top_level' => $moved,
			'labels_unchanged'   => $unchanged,
			'labels_translated'  => $translated,
			'labels'             => self::label_sources( $items, $slug ),
			'links_remapped'     => $remapped,
		);
	}

	/**
	 * Where each item's label in a language comes from.
	 *
	 * Follows the same order the sync uses: a translated page or term title
	 * first, then a Polylang string translation, otherwise the source label.
	 *
	 * @since 1.14.0
	 * @param array<int, \WP_Post> $items Source menu items.
	 * @param string               $slug  Target language slug.
	 * @return array<int, array<string, mixed>> One entry per item that will be written.
	 */
	private static function label_sources( array $items, $slug ) {
		$sync = self::legacy();
		$out  = array();

		foreach ( $items as $item ) {
			if ( ! $sync->can_sync_item( $item, $slug ) ) {
				continue;
			}

			$target = self::target_label( $item, $slug );

			if ( $target !== (string) $item->title ) {
				$label  = $target;
				$origin = 'taxonomy' === $item->type ? 'term_name' : 'page_title';
			} else {
				$translated = self::translated_label( $item, $slug );
				$label      = '' !== $translated ? $translated : (string) $item->title;
				$origin     = '' !== $translated ? 'string_translation' : 'left_in_source';
			}

			$out[] = array(
				'item_id'      => (int) $item->ID,
				'source_label' => (string) $item->title,
				'label'        => $label,
				'label_source' => $origin,
			);
		}

		return $out;
	}

	/**
	 * Says whether Elementor Nav Menu widgets in a language's templates show the synced menu.
	 *
	 * @since 1.14.0
	 * @param array<int, array<string, mixed>> $widgets   Widgets in that language's templates.
	 * @param int                              $source_id Source menu ID.
	 * @param int                              $target_id Synced menu ID.
	 * @param string                           $slug      Language slug.
	 * @return array<string, mixed> A status, the widgets involved and what to do.
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
						__( 'Call set-template-menu with template_id %1$d, widget_id %2$s and menu_id %3$d.', 'autopoly-ai-translation-for-polylang-pro' ),
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
				__( 'The %s header or footer still shows the source menu. Elementor does not switch menus by language; follow the action on each widget.', 'autopoly-ai-translation-for-polylang-pro' ),
				$slug
			);
		} elseif ( $shows ) {
			$status = 'shows_synced_menu';
			$note   = __( 'An Elementor Nav Menu widget in this language already shows the synced menu.', 'autopoly-ai-translation-for-polylang-pro' );
		} else {
			$status = 'no_widget_found';
			$note   = __( 'No Elementor Nav Menu widget in a template of this language shows the source or synced menu. If the site uses an Elementor header, translate it first (translate-post), then call set-template-menu.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		return array(
			'status'  => $status,
			'widgets' => $involved,
			'note'    => $note,
		);
	}

	/**
	 * Applies label and link translations to one item as the legacy sync writes it.
	 *
	 * Hooked to ITEM_DATA_FILTER by sync() only. Anything that cannot be
	 * translated is left exactly as the legacy code built it.
	 *
	 * @since 1.13.0
	 * @param array<string, mixed> $item_data   Arguments for wp_update_nav_menu_item(), slashed.
	 * @param \WP_Post             $item        Source menu item.
	 * @param string               $target_lang Target language slug.
	 * @param string               $source_lang Language the menu's links are in.
	 * @return array<string, mixed> The arguments, translated where possible.
	 */
	private static function translate_item_data( array $item_data, $item, $target_lang, $source_lang ) {
		$title = isset( $item_data['menu-item-title'] ) ? wp_unslash( (string) $item_data['menu-item-title'] ) : null;

		// Only a label the legacy code kept in the source language is replaced.
		if ( (string) $item->title === $title ) {
			$label = self::translated_label( $item, $target_lang );

			if ( '' !== $label ) {
				$item_data['menu-item-title'] = wp_slash( $label );
			}
		}

		if ( 'custom' === $item->type ) {
			$url = self::translated_custom_url( (string) $item->url, $target_lang, $source_lang );

			if ( $url !== (string) $item->url ) {
				$item_data['menu-item-url'] = esc_url_raw( $url );
			}
		}

		return $item_data;
	}

	/**
	 * The Polylang string translation of an item's label.
	 *
	 * Callers only ask for a label the legacy code kept in the source language,
	 * which is exactly a custom or hand-edited one.
	 *
	 * @since 1.13.0
	 * @param \WP_Post $item Menu item.
	 * @param string   $slug Target language slug.
	 * @return string The translation, or '' when there is none.
	 */
	private static function translated_label( $item, $slug ) {
		$title = (string) $item->title;

		if ( '' === trim( $title ) || ! class_exists( 'PLL_MO' ) ) {
			return '';
		}

		if ( ! isset( self::$string_translations[ $slug ] ) ) {
			$language = Language_Service::get_by_slug( (string) $slug );
			$mo       = false;

			if ( null !== $language ) {
				$mo = new \PLL_MO();
				$mo->import_from_db( $language );
			}

			self::$string_translations[ $slug ] = $mo;
		}

		$mo = self::$string_translations[ $slug ];

		if ( false === $mo ) {
			return '';
		}

		$translated = (string) $mo->translate( $title );

		return ( '' !== $translated && $translated !== $title ) ? $translated : '';
	}

	/**
	 * The translated equivalent of a custom link, in the same form, absolute or root-relative.
	 *
	 * Resolved through ATFPP_Helper::replace_links_with_translations(), as
	 * translate-post does for page builder links. The query string and fragment
	 * are set aside while the path is resolved. A link to the home page goes to
	 * the target language's home page.
	 *
	 * @since 1.13.0
	 * @param string $url         Link as stored on the item.
	 * @param string $target_lang Target language slug.
	 * @param string $source_lang Language the link is in.
	 * @return string The translated link, or $url unchanged.
	 */
	private static function translated_custom_url( $url, $target_lang, $source_lang ) {
		$can_remap = '' !== (string) $target_lang
			&& '' !== (string) $source_lang
			&& $target_lang !== $source_lang
			&& self::is_internal_url( $url )
			&& class_exists( 'ATFPP_Helper' )
			&& is_callable( array( 'ATFPP_Helper', 'replace_links_with_translations' ) );

		if ( ! $can_remap ) {
			return $url;
		}

		$home     = untrailingslashit( get_home_url() );
		$relative = '/' === $url[0];
		$absolute = $relative ? $home . $url : $url;
		$split    = strcspn( $absolute, '?#' );
		$base     = (string) substr( $absolute, 0, $split );
		$suffix   = (string) substr( $absolute, $split );

		if ( '' === trim( (string) substr( $base, strlen( $home ) ), '/' ) ) {
			$translated = function_exists( 'pll_home_url' ) ? pll_home_url( $target_lang ) : '';
		} else {
			$translated = \ATFPP_Helper::replace_links_with_translations( $base, $target_lang, $source_lang );
		}

		if ( ! is_string( $translated ) || '' === $translated || $translated === $base || 0 !== strpos( $translated, $home ) ) {
			return $url;
		}

		if ( $relative ) {
			$path = (string) substr( $translated, strlen( $home ) );

			return ( '' === $path ? '/' : $path ) . $suffix;
		}

		return $translated . $suffix;
	}

	/**
	 * Whether a link points at this site: root-relative, or absolute on the home URL.
	 *
	 * @since 1.13.0
	 * @param string $url Link.
	 * @return bool True for an internal link.
	 */
	private static function is_internal_url( $url ) {
		if ( '' === $url ) {
			return false;
		}

		// "//host/path" is protocol-relative and points elsewhere.
		if ( '/' === $url[0] ) {
			return ! ( isset( $url[1] ) && '/' === $url[1] );
		}

		$home = untrailingslashit( get_home_url() );

		return 0 === strcasecmp( $url, $home ) || 0 === stripos( $url, $home . '/' );
	}

	/**
	 * The language a menu's links are in: the menu's own, or the default language when it has none.
	 *
	 * @since 1.13.0
	 * @param string $menu_lang The menu's language slug, possibly empty.
	 * @return string Language slug, or '' when Polylang has no default.
	 */
	private static function resolve_source_language( $menu_lang ) {
		if ( '' !== (string) $menu_lang ) {
			return (string) $menu_lang;
		}

		return function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
	}

	/**
	 * The label an item will carry in the target language.
	 *
	 * @since 1.11.0
	 * @param \WP_Post $item Source menu item.
	 * @param string   $slug Target language slug.
	 * @return string The label.
	 */
	private static function target_label( $item, $slug ) {
		if ( 'post_type' === $item->type ) {
			$translated = get_post( (int) pll_get_post( (int) $item->object_id, $slug ) );

			return $translated ? (string) self::legacy()->get_post_type_item_title( $item, $translated ) : (string) $item->title;
		}

		if ( 'taxonomy' === $item->type ) {
			$translated = get_term( (int) pll_get_term( (int) $item->object_id, $slug ), $item->object );

			return ( $translated && ! is_wp_error( $translated ) ) ? (string) self::legacy()->get_taxonomy_item_title( $item, $translated ) : (string) $item->title;
		}

		return (string) $item->title;
	}

	/**
	 * Explains why an item will be left out of a language.
	 *
	 * @since 1.11.0
	 * @param \WP_Post $item Source menu item.
	 * @param string   $slug Target language slug.
	 * @return string Reason code.
	 */
	private static function skip_reason( $item, $slug ) {
		if ( 'post_type' === $item->type && pll_get_post( (int) $item->object_id, $slug ) ) {
			return 'translation_not_published';
		}

		return 'no_translation';
	}

	/**
	 * The items currently in a menu that a sync would delete.
	 *
	 * @since 1.11.0
	 * @param int $menu_id Target menu ID, or 0 when it will be created.
	 * @return array<int, array<string, mixed>> Item descriptors.
	 */
	private static function describe_existing_items( $menu_id ) {
		if ( $menu_id <= 0 ) {
			return array();
		}

		$items = wp_get_nav_menu_items( $menu_id );

		return is_array( $items ) ? array_map( array( self::class, 'describe_item' ), $items ) : array();
	}

	/**
	 * A compact description of a menu item.
	 *
	 * @since 1.11.0
	 * @param \WP_Post $item Menu item.
	 * @return array<string, mixed> Descriptor.
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
	 * A refused-language entry.
	 *
	 * @since 1.11.0
	 * @param string $slug    Language slug.
	 * @param string $reason  Reason code.
	 * @param string $message Human-readable explanation.
	 * @return array<string, string> Entry.
	 */
	private static function refusal( $slug, $reason, $message ) {
		return array(
			'slug'    => (string) $slug,
			'reason'  => $reason,
			'message' => $message,
		);
	}
}
