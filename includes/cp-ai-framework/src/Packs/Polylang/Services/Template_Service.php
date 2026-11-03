<?php
/**
 * Elementor Theme Builder helpers: environment checks, Nav Menu widgets and cache.
 *
 * @package AutoPoly
 * @since   1.14.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and changes the pieces an Elementor header or footer depends on.
 *
 * A translated header is three separate things: the template post, the display
 * rule that swaps it in (Connect Polylang for Elementor), and the menu its Nav
 * Menu widget shows. translate-post writes the first; this class reports on all
 * three and can change the widget's menu.
 *
 * @since 1.14.0
 */
final class Template_Service {

	/**
	 * Elementor's Theme Builder template post type.
	 *
	 * @since 1.14.0
	 * @var string
	 */
	const TEMPLATE_POST_TYPE = 'elementor_library';

	/**
	 * Widget type of Elementor Pro's Nav Menu widget.
	 *
	 * @since 1.14.0
	 * @var string
	 */
	const MENU_WIDGET = 'nav-menu';

	/**
	 * Most templates scanned for Nav Menu widgets in one call.
	 *
	 * @since 1.14.0
	 * @var int
	 */
	const MAX_TEMPLATES = 50;

	/**
	 * Whether Elementor is loaded.
	 *
	 * @since 1.14.0
	 * @return bool True when active.
	 */
	public static function elementor_active() {
		return class_exists( '\Elementor\Plugin' ) && property_exists( '\Elementor\Plugin', 'instance' ) && null !== \Elementor\Plugin::$instance;
	}

	/**
	 * Site-wide settings an Elementor header or footer translation needs.
	 *
	 * @since 1.14.0
	 * @return array<string, mixed> Whether Elementor is active, plus one check per setting.
	 */
	public static function environment() {
		if ( ! self::elementor_active() ) {
			return array( 'elementor_active' => false );
		}

		$translatable = function_exists( 'pll_is_translated_post_type' ) && pll_is_translated_post_type( self::TEMPLATE_POST_TYPE );

		$checks = array(
			self::check(
				'templates_translatable',
				$translatable,
				__( 'Elementor templates (post type elementor_library) are translatable.', 'autopoly-ai-translation-for-polylang-pro' ),
				__( 'Elementor templates cannot be translated yet. An administrator must enable "My Templates" under Languages > Settings > Custom post types and Taxonomies in Polylang. Templates that already exist then need a language: set one with manage-translation-links (action "set_language").', 'autopoly-ai-translation-for-polylang-pro' )
			),
			self::check(
				'connect_polylang_active',
				Translation_Service::is_elementor_connector_active(),
				__( 'Connect Polylang for Elementor is active, so a translated template replaces the source on pages in its language.', 'autopoly-ai-translation-for-polylang-pro' ),
				__( 'Connect Polylang for Elementor is not active. Without it a translated header or footer is never shown to visitors. No AutoPoly tool can install it; ask the user to install and activate the free plugin from https://wordpress.org/plugins/connect-polylang-elementor/.', 'autopoly-ai-translation-for-polylang-pro' )
			),
		);

		return array(
			'elementor_active' => true,
			'ready'            => self::all_ok( $checks ),
			'checks'           => $checks,
		);
	}

	/**
	 * Everything to know before translating an Elementor header or footer, in one place.
	 *
	 * @since 1.14.0
	 * @param int    $post_id     Template (or any Elementor post) ID.
	 * @param string $target_lang Target language slug, or '' for none.
	 * @return array<string, mixed>|WP_Error The report, or an error.
	 */
	public static function preflight( $post_id, $target_lang = '' ) {
		$post_id     = absint( $post_id );
		$target_lang = sanitize_key( (string) $target_lang );
		$post        = get_post( $post_id );

		if ( null === $post ) {
			return new WP_Error(
				'atfpp_source_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d was not found.', 'autopoly-ai-translation-for-polylang-pro' ),
					$post_id
				)
			);
		}

		if ( ! self::elementor_active() || Builder_Service::BUILDER_ELEMENTOR !== Builder_Service::detect_editor_type( $post_id ) ) {
			return new WP_Error(
				'atfpp_not_elementor',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d is not built with Elementor, or Elementor is not active.', 'autopoly-ai-translation-for-polylang-pro' ),
					$post_id
				)
			);
		}

		$language = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id, 'slug' ) : '';
		$checks   = array(
			self::check(
				'post_type_translatable',
				function_exists( 'pll_is_translated_post_type' ) && pll_is_translated_post_type( $post->post_type ),
				__( 'This post type is translatable.', 'autopoly-ai-translation-for-polylang-pro' ),
				Polylang_Guard::not_translated_message( 'post', $post->post_type )
			),
			self::check(
				'source_language_assigned',
				'' !== $language,
				sprintf(
					/* translators: %s: language slug. */
					__( 'The post has a language (%s).', 'autopoly-ai-translation-for-polylang-pro' ),
					$language
				),
				__( 'The post has no language. Give it one with manage-translation-links (action "set_language").', 'autopoly-ai-translation-for-polylang-pro' )
			),
		);

		if ( '' !== $target_lang ) {
			$checks[] = self::check(
				'target_language_exists',
				null !== Language_Service::get_by_slug( $target_lang ),
				__( 'The target language exists.', 'autopoly-ai-translation-for-polylang-pro' ),
				sprintf(
					/* translators: %s: language slug. */
					__( 'Target language "%s" was not found.', 'autopoly-ai-translation-for-polylang-pro' ),
					$target_lang
				)
			);
		}

		$environment = self::environment();

		if ( isset( $environment['checks'] ) ) {
			foreach ( $environment['checks'] as $env_check ) {
				// For a template this says the same as post_type_translatable above, so it is not repeated.
				if ( 'templates_translatable' === $env_check['key'] && self::TEMPLATE_POST_TYPE === $post->post_type ) {
					continue;
				}

				$checks[] = $env_check;
			}
		}

		$widgets    = array();
		$next_steps = array();

		foreach ( self::find_menu_widgets( Builder_Service::get_elements( $post_id ) ) as $widget ) {
			$entry = self::describe_widget( $widget, $target_lang );

			if ( 0 === $entry['menu_id'] ) {
				$next_steps[] = sprintf(
					/* translators: 1: widget ID, 2: value stored in the widget. */
					__( 'The Nav Menu widget %1$s names a menu ("%2$s") that does not exist on this site, so it shows nothing. Create or restore that menu, or call set-template-menu to point the widget at an existing one.', 'autopoly-ai-translation-for-polylang-pro' ),
					$entry['widget_id'],
					$entry['stored_value']
				);
			} elseif ( '' !== $target_lang && $language !== $target_lang ) {
				if ( null === $entry['translated_menu'] ) {
					$next_steps[] = Menu_Service::is_enabled() ? sprintf(
						/* translators: 1: menu name, 2: widget ID, 3: language slug. */
						__( 'The Nav Menu widget %2$s shows menu "%1$s", and there is no %3$s menu for it yet. Run sync-menu for it (dry_run false), then translate this template and call set-template-menu.', 'autopoly-ai-translation-for-polylang-pro' ),
						$entry['menu_name'],
						$entry['widget_id'],
						$target_lang
					) : sprintf(
						/* translators: 1: menu name, 2: widget ID, 3: language slug. */
						__( 'The Nav Menu widget %2$s shows menu "%1$s", and there is no %3$s menu for it yet. This does not block translating the template. To show a separate %3$s menu afterwards, enable Menu Sync and run sync-menu, or create and assign a %3$s menu manually with set-template-menu.', 'autopoly-ai-translation-for-polylang-pro' ),
						$entry['menu_name'],
						$entry['widget_id'],
						$target_lang
					);
				} else {
					$next_steps[] = sprintf(
						/* translators: 1: widget ID, 2: translated menu ID, 3: language slug. */
						__( 'After translating this template, call set-template-menu on the %3$s template with widget_id %1$s and menu_id %2$d. Elementor does not switch menus by language on its own.', 'autopoly-ai-translation-for-polylang-pro' ),
						$entry['widget_id'],
						$entry['translated_menu']['id'],
						$target_lang
					);
				}
			}

			$widgets[] = $entry;
		}

		foreach ( $checks as $check ) {
			if ( ! $check['ok'] ) {
				$next_steps[] = $check['detail'];
			}
		}

		return array(
			'post_id'         => $post_id,
			'post_type'       => $post->post_type,
			'language'        => $language,
			'target_language' => $target_lang,
			'ready'           => self::all_ok( $checks ),
			'checks'          => $checks,
			'menu_widgets'    => $widgets,
			'next_steps'      => $next_steps,
		);
	}

	/**
	 * Finds the Nav Menu widgets in an element tree.
	 *
	 * @since 1.14.0
	 * @param array<int, mixed> $elements Element tree.
	 * @return array<int, array{widget_id: string, menu: string}> One entry per widget that names a menu.
	 */
	public static function find_menu_widgets( array $elements ) {
		$found = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['widgetType'] ) && self::MENU_WIDGET === $element['widgetType']
				&& isset( $element['settings']['menu'] ) && is_scalar( $element['settings']['menu'] ) && '' !== (string) $element['settings']['menu'] ) {
				$found[] = array(
					'widget_id' => isset( $element['id'] ) ? (string) $element['id'] : '',
					'menu'      => (string) $element['settings']['menu'],
				);
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = array_merge( $found, self::find_menu_widgets( $element['elements'] ) );
			}
		}

		return $found;
	}

	/**
	 * Carries the menus of an existing translation's Nav Menu widgets over to a rebuilt layout.
	 *
	 * translate-post rebuilds a translation from the source layout, which would
	 * put every Nav Menu widget back on the source menu and undo set-template-menu.
	 * A widget keeps its translation's menu only when that menu still exists.
	 *
	 * @since 1.14.0
	 * @param array<int, mixed> $new      Rebuilt element tree.
	 * @param array<int, mixed> $existing Element tree the translation had before.
	 * @return array{0: array<int, mixed>, 1: string[]} The tree, and the IDs of the widgets that kept their menu.
	 */
	public static function keep_existing_menus( array $new, array $existing ) {
		$kept = array();

		foreach ( self::find_menu_widgets( $existing ) as $old ) {
			if ( ! wp_get_nav_menu_object( $old['menu'] ) ) {
				continue;
			}

			foreach ( self::find_menu_widgets( $new ) as $widget ) {
				if ( $widget['widget_id'] === $old['widget_id'] && $widget['menu'] !== $old['menu'] ) {
					$new    = self::replace_menu( $new, $old['widget_id'], $old['menu'] );
					$kept[] = $old['widget_id'];
				}
			}
		}

		return array( $new, $kept );
	}

	/**
	 * Notes for Nav Menu widgets of a translated template that still show a menu that has a translation.
	 *
	 * @since 1.14.0
	 * @param int    $post_id     Translated template ID.
	 * @param string $target_lang Target language slug.
	 * @return string[] One sentence per widget; empty when every widget is fine.
	 */
	public static function unbound_menu_notices( $post_id, $target_lang ) {
		$notices = array();

		foreach ( self::find_menu_widgets( Builder_Service::get_elements( absint( $post_id ) ) ) as $widget ) {
			$menu = wp_get_nav_menu_object( $widget['menu'] );

			if ( ! $menu ) {
				continue;
			}

			$translated = Menu_Service::find_translation( (int) $menu->term_id, $target_lang );

			if ( null === $translated ) {
				continue;
			}

			$notices[] = sprintf(
				/* translators: 1: widget ID, 2: menu name, 3: language slug, 4: template ID, 5: translated menu ID. */
				__( 'The Nav Menu widget %1$s still shows the menu "%2$s", and a %3$s version of it exists. Elementor does not switch menus by language: call set-template-menu with template_id %4$d and menu_id %5$d.', 'autopoly-ai-translation-for-polylang-pro' ),
				$widget['widget_id'],
				$menu->name,
				$target_lang,
				absint( $post_id ),
				$translated['id']
			);
		}

		return $notices;
	}

	/**
	 * Points a template's Nav Menu widget at a menu.
	 *
	 * Writes through Elementor's document API, as translate-post does, and then
	 * clears Elementor's cache: without that the old menu's HTML keeps being
	 * served even though the saved setting is correct.
	 *
	 * @since 1.14.0
	 * @param int    $post_id   Template ID.
	 * @param int    $menu_id   Menu ID to show.
	 * @param string $widget_id Widget to change; required when the template has more than one Nav Menu widget.
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	public static function set_menu( $post_id, $menu_id, $widget_id = '' ) {
		$post_id   = absint( $post_id );
		$menu_id   = absint( $menu_id );
		$widget_id = sanitize_text_field( (string) $widget_id );

		if ( null === get_post( $post_id ) || ! self::elementor_active() || Builder_Service::BUILDER_ELEMENTOR !== Builder_Service::detect_editor_type( $post_id ) ) {
			return new WP_Error(
				'atfpp_not_elementor',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d was not found, is not built with Elementor, or Elementor is not active.', 'autopoly-ai-translation-for-polylang-pro' ),
					$post_id
				)
			);
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

		$post_lang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id, 'slug' ) : '';
		$info      = Menu_Service::menu_info( $menu_id );
		$menu_lang = null !== $info ? $info['language'] : '';

		// A menu with no language (none of its locations is assigned) is what sync-menu leaves on a site without locations, so it is allowed.
		if ( '' !== $post_lang && '' !== $menu_lang && $post_lang !== $menu_lang ) {
			return new WP_Error(
				'atfpp_menu_language_mismatch',
				sprintf(
					/* translators: 1: menu name, 2: menu language, 3: template language. */
					__( 'Menu "%1$s" belongs to language "%2$s", but this template is in "%3$s". Nothing was changed. Use the menu that sync-menu created for "%3$s".', 'autopoly-ai-translation-for-polylang-pro' ),
					$menu->name,
					$menu_lang,
					$post_lang
				)
			);
		}

		$elements = Builder_Service::get_elements( $post_id );
		$widgets  = self::find_menu_widgets( $elements );

		if ( array() === $widgets ) {
			return new WP_Error(
				'atfpp_no_menu_widget',
				__( 'This template has no Elementor Nav Menu widget that names a menu, so there is nothing to change. Nothing was changed.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		if ( '' === $widget_id ) {
			if ( count( $widgets ) > 1 ) {
				return new WP_Error(
					'atfpp_widget_required',
					sprintf(
						/* translators: %s: comma-separated widget IDs. */
						__( 'This template has more than one Nav Menu widget (%s). Pass widget_id to say which one to change. Nothing was changed.', 'autopoly-ai-translation-for-polylang-pro' ),
						implode( ', ', wp_list_pluck( $widgets, 'widget_id' ) )
					)
				);
			}

			$widget_id = $widgets[0]['widget_id'];
		}

		$previous = null;

		foreach ( $widgets as $widget ) {
			if ( $widget['widget_id'] === $widget_id ) {
				$previous = $widget['menu'];
			}
		}

		if ( null === $previous ) {
			return new WP_Error(
				'atfpp_widget_not_found',
				sprintf(
					/* translators: %s: widget ID. */
					__( 'No Nav Menu widget with ID "%s" in this template. Nothing was changed.', 'autopoly-ai-translation-for-polylang-pro' ),
					$widget_id
				)
			);
		}

		// A plain slug keeps the Elementor editor's menu dropdown working; the numeric ID is the fallback for slugs WordPress cannot resolve back to this menu, such as percent-encoded ones.
		$value  = (string) $menu_id;
		$stored = 'id';

		if ( 1 === preg_match( '/^[a-z0-9_-]+$/', (string) $menu->slug ) ) {
			$resolved = wp_get_nav_menu_object( (string) $menu->slug );

			if ( $resolved && (int) $resolved->term_id === $menu_id ) {
				$value  = (string) $menu->slug;
				$stored = 'slug';
			}
		}

		$changed = false;

		if ( self::menu_matches( $previous, $menu_id ) ) {
			$value   = $previous;
			$stored  = ctype_digit( $previous ) ? 'id' : 'slug';
		} else {
			$updated = self::replace_menu( $elements, $widget_id, $value );
			$changed = true;

			$document = \Elementor\Plugin::$instance->documents->get( $post_id );

			if ( ! $document || ! $document->save( array( 'elements' => $updated ) ) ) {
				return new WP_Error(
					'atfpp_save_failed',
					__( 'Elementor could not save the template. Nothing was changed.', 'autopoly-ai-translation-for-polylang-pro' )
				);
			}

			$after = self::find_menu_widgets( Builder_Service::get_elements( $post_id ) );
			$ok    = false;

			foreach ( $after as $widget ) {
				if ( $widget['widget_id'] === $widget_id && self::menu_matches( $widget['menu'], $menu_id ) ) {
					$ok = true;
				}
			}

			if ( ! $ok ) {
				return new WP_Error(
					'atfpp_menu_not_saved',
					__( 'Elementor saved the template, but the widget still does not show the new menu when read back. Check it in the editor.', 'autopoly-ai-translation-for-polylang-pro' )
				);
			}
		}

		$cleared = self::clear_element_cache();

		return array(
			'success'                => true,
			'changed'                => $changed,
			'template_id'            => $post_id,
			'widget_id'              => $widget_id,
			'previous_menu'          => $previous,
			'menu_id'                => $menu_id,
			'menu_name'              => (string) $menu->name,
			'stored_as'              => $stored,
			'stored_value'           => $value,
			'elementor_cache_cleared' => $cleared,
			'notice'                 => $changed
				? __( 'The widget now shows this menu, and Elementor\'s cache was cleared so the old menu is not served from it. Check the public page to confirm (translate-post does this with verify_public).', 'autopoly-ai-translation-for-polylang-pro' )
				: __( 'The widget already showed this menu, so the template was not changed. Elementor\'s cache was cleared in case an old menu was still being served.', 'autopoly-ai-translation-for-polylang-pro' ),
		);
	}

	/**
	 * Every Nav Menu widget in the Elementor templates of one language.
	 *
	 * @since 1.14.0
	 * @param string $lang Language slug.
	 * @return array<int, array<string, mixed>> One entry per widget, with the menu it resolves to (0 when it names none that exists).
	 */
	public static function nav_widgets_in_language( $lang ) {
		if ( ! self::elementor_active() || ! function_exists( 'pll_is_translated_post_type' ) || ! pll_is_translated_post_type( self::TEMPLATE_POST_TYPE ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'        => self::TEMPLATE_POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'posts_per_page'   => self::MAX_TEMPLATES,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'tax_query'        => array(
					array(
						'taxonomy' => 'language',
						'field'    => 'slug',
						'terms'    => sanitize_key( (string) $lang ),
					),
				),
			)
		);

		$found = array();

		foreach ( (array) $ids as $template_id ) {
			// Cheap check on the raw meta first, so templates without a Nav Menu widget are never decoded.
			if ( false === strpos( (string) get_post_meta( (int) $template_id, '_elementor_data', true ), self::MENU_WIDGET ) ) {
				continue;
			}

			foreach ( self::find_menu_widgets( Builder_Service::get_elements( (int) $template_id ) ) as $widget ) {
				$menu = wp_get_nav_menu_object( $widget['menu'] );

				$found[] = array(
					'template_id'    => (int) $template_id,
					'template_title' => get_the_title( (int) $template_id ),
					'widget_id'      => $widget['widget_id'],
					'menu_id'        => $menu ? (int) $menu->term_id : 0,
					'stored_value'   => $widget['menu'],
				);
			}
		}

		return $found;
	}

	/**
	 * Clears Elementor's generated files and element cache.
	 *
	 * The same call Elementor's own DELETE /elementor/v1/cache endpoint makes.
	 *
	 * @since 1.14.0
	 * @return bool True when the cache was cleared.
	 */
	public static function clear_element_cache() {
		if ( ! self::elementor_active() || ! isset( \Elementor\Plugin::$instance->files_manager ) ) {
			return false;
		}

		\Elementor\Plugin::$instance->files_manager->clear_cache();

		return true;
	}

	/**
	 * Whether a widget's stored menu value (ID, slug or name) is the given menu.
	 *
	 * @since 1.14.0
	 * @param string $stored  Value in the widget.
	 * @param int    $menu_id Menu ID.
	 * @return bool True when they are the same menu.
	 */
	private static function menu_matches( $stored, $menu_id ) {
		$object = wp_get_nav_menu_object( $stored );

		return $object && (int) $object->term_id === (int) $menu_id;
	}

	/**
	 * Returns an element tree with one Nav Menu widget's menu replaced.
	 *
	 * @since 1.14.0
	 * @param array<int, mixed> $elements  Element tree.
	 * @param string            $widget_id Widget to change.
	 * @param string            $value     New stored value.
	 * @return array<int, mixed> The tree.
	 */
	private static function replace_menu( array $elements, $widget_id, $value ) {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['id'], $element['widgetType'] ) && (string) $element['id'] === $widget_id && self::MENU_WIDGET === $element['widgetType'] ) {
				$elements[ $index ]['settings']['menu'] = $value;
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$elements[ $index ]['elements'] = self::replace_menu( $element['elements'], $widget_id, $value );
			}
		}

		return $elements;
	}

	/**
	 * Describes the menu one widget shows.
	 *
	 * @since 1.14.0
	 * @param array{widget_id: string, menu: string} $widget      Widget.
	 * @param string                                  $target_lang Target language slug, or ''.
	 * @return array<string, mixed> Descriptor.
	 */
	private static function describe_widget( array $widget, $target_lang ) {
		$menu = wp_get_nav_menu_object( $widget['menu'] );
		$info = $menu ? Menu_Service::menu_info( (int) $menu->term_id ) : null;

		$entry = array(
			'widget_id'         => $widget['widget_id'],
			'stored_value'      => $widget['menu'],
			'stored_as'         => ctype_digit( $widget['menu'] ) ? 'id' : 'slug_or_name',
			'menu_id'           => $menu ? (int) $menu->term_id : 0,
			'menu_name'         => $menu ? (string) $menu->name : '',
			'menu_language'     => null !== $info ? $info['language'] : '',
			'has_theme_location' => null !== $info && array() !== $info['locations'],
			'translated_menu'   => null,
		);

		if ( $menu && '' !== $target_lang ) {
			$entry['translated_menu'] = Menu_Service::find_translation( (int) $menu->term_id, $target_lang );
		}

		if ( ! $menu ) {
			$entry['problem'] = __( 'The widget names a menu that no longer exists.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		return $entry;
	}

	/**
	 * One preflight check.
	 *
	 * @since 1.14.0
	 * @param string $key     Stable check name.
	 * @param bool   $ok      Whether it passed.
	 * @param string $ok_text What to say when it passed.
	 * @param string $fix     What to do when it did not.
	 * @return array{key: string, ok: bool, detail: string} Check.
	 */
	private static function check( $key, $ok, $ok_text, $fix ) {
		return array(
			'key'    => $key,
			'ok'     => (bool) $ok,
			'detail' => $ok ? $ok_text : $fix,
		);
	}

	/**
	 * Whether every check passed.
	 *
	 * @since 1.14.0
	 * @param array<int, array{ok: bool}> $checks Checks.
	 * @return bool True when all passed.
	 */
	private static function all_ok( array $checks ) {
		foreach ( $checks as $check ) {
			if ( ! $check['ok'] ) {
				return false;
			}
		}

		return true;
	}
}
