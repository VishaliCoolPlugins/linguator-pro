<?php
/**
 * Elementor templates: translatability checks and Nav Menu widget changes.
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
 * Checks whether an Elementor header, footer or page can be translated and shown,
 * and changes the menu its Nav Menu widgets show.
 *
 * Linguator routes a translated Theme Builder template to pages of its language
 * itself (its Elementor integration swaps the template ID), so unlike a setup that
 * needs a connector plugin, nothing extra has to be installed. What Linguator does
 * not do is switch the menu of an Elementor Nav Menu widget by language: that is
 * what set_menu() is for.
 *
 * @since 1.2.0
 */
final class Template_Service {

	/**
	 * Post type Elementor keeps its templates in.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const TEMPLATE_POST_TYPE = 'elementor_library';

	/**
	 * Elementor widget type of the Nav Menu widget.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const MENU_WIDGET = 'nav-menu';

	/**
	 * Most templates looked at when searching for Nav Menu widgets.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MAX_TEMPLATES = 50;

	/**
	 * Whether Elementor is loaded.
	 *
	 * @since 1.2.0
	 * @return bool True when active.
	 */
	public static function elementor_active() {
		return class_exists( '\Elementor\Plugin' ) && property_exists( '\Elementor\Plugin', 'instance' ) && null !== \Elementor\Plugin::$instance;
	}

	/**
	 * Whether Linguator's Elementor integration, which routes translated templates, is loaded.
	 *
	 * @since 1.2.0
	 * @return bool True when it is loaded.
	 */
	public static function routing_active() {
		return class_exists( 'Linguator\Integrations\elementor\Linguator_Template_Translation' );
	}

	/**
	 * Whether a post is built with Elementor.
	 *
	 * @since 1.2.0
	 * @param int $post_id Post ID.
	 * @return bool True when Elementor is active and the post uses its editor.
	 */
	public static function is_elementor_post( $post_id ) {
		return self::elementor_active() && 'builder' === (string) get_post_meta( absint( $post_id ), '_elementor_edit_mode', true );
	}

	/**
	 * The Elementor element tree of a post.
	 *
	 * @since 1.2.0
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, mixed>> Elements, or an empty array.
	 */
	public static function get_elements( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! self::is_elementor_post( $post_id ) ) {
			return array();
		}

		$document = \Elementor\Plugin::$instance->documents->get( $post_id );

		if ( $document ) {
			$elements = $document->get_elements_data();

			if ( is_array( $elements ) ) {
				return $elements;
			}
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( is_array( $raw ) ) {
			return $raw;
		}

		$decoded = json_decode( (string) $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Site-wide checks for translating Elementor templates.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Whether Elementor is active, whether all is ready and each check.
	 */
	public static function environment() {
		if ( ! self::elementor_active() ) {
			return array( 'elementor_active' => false );
		}

		$checks = array(
			self::check(
				'templates_translatable',
				Linguator_Guard::is_translated_post_type( self::TEMPLATE_POST_TYPE ),
				__( 'Elementor templates (post type elementor_library) are translatable.', 'linguator-pro' ),
				__( 'Elementor templates cannot be translated yet. A site administrator must switch on "My Templates" (elementor_library) in Linguator settings, under Post types. Templates that already exist then need a language: set one with manage-translation-links (action "assign_language").', 'linguator-pro' )
			),
			self::check(
				'template_routing_active',
				self::routing_active(),
				__( 'Linguator\'s Elementor integration is loaded, so a translated template replaces the source on pages in its language.', 'linguator-pro' ),
				__( 'Linguator\'s Elementor integration is not loaded, so a translated header or footer is not routed to pages of its language. Ask the user to check that Elementor was active when Linguator started.', 'linguator-pro' )
			),
		);

		return array(
			'elementor_active' => true,
			'ready'            => self::all_ok( $checks ),
			'checks'           => $checks,
		);
	}

	/**
	 * Everything that decides whether an Elementor post can be translated and shown.
	 *
	 * @since 1.2.0
	 * @param int    $post_id     Source Elementor template or page.
	 * @param string $target_lang Optional target language slug.
	 * @return array<string, mixed>|WP_Error Report, or an error when the post is not an Elementor post.
	 */
	public static function preflight( $post_id, $target_lang = '' ) {
		$post_id     = absint( $post_id );
		$target_lang = sanitize_key( (string) $target_lang );
		$post        = get_post( $post_id );

		if ( null === $post ) {
			return new WP_Error(
				'lmat_source_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d was not found.', 'linguator-pro' ),
					$post_id
				)
			);
		}

		if ( ! self::is_elementor_post( $post_id ) ) {
			return new WP_Error(
				'lmat_not_elementor',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d is not built with Elementor, or Elementor is not active.', 'linguator-pro' ),
					$post_id
				)
			);
		}

		$language = (string) linguator_get_post_language( $post_id );
		$default  = (string) linguator_default_language();
		$checks   = array(
			self::check(
				'post_type_translatable',
				Linguator_Guard::is_translated_post_type( $post->post_type ),
				__( 'This post type is translatable.', 'linguator-pro' ),
				Linguator_Guard::not_translated_message( $post->post_type )
			),
			self::check(
				'source_language_assigned',
				'' !== $language,
				sprintf(
					/* translators: %s: language slug. */
					__( 'The post has a language (%s).', 'linguator-pro' ),
					$language
				),
				__( 'The post has no language. Give it one with manage-translation-links (action "assign_language").', 'linguator-pro' )
			),
		);

		if ( '' !== $target_lang ) {
			$known    = Linguator_Guard::language( $target_lang );
			$checks[] = self::check(
				'target_language_exists',
				! is_wp_error( $known ),
				__( 'The target language exists.', 'linguator-pro' ),
				is_wp_error( $known ) ? $known->get_error_message() : ''
			);
		}

		$environment = self::environment();

		if ( isset( $environment['checks'] ) ) {
			foreach ( $environment['checks'] as $env_check ) {
				// For a template, post_type_translatable above already covers this.
				if ( 'templates_translatable' === $env_check['key'] && self::TEMPLATE_POST_TYPE === $post->post_type ) {
					continue;
				}

				$checks[] = $env_check;
			}
		}

		$template_type = self::TEMPLATE_POST_TYPE === $post->post_type ? (string) get_post_meta( $post_id, '_elementor_template_type', true ) : '';

		if ( in_array( $template_type, array( 'header', 'footer' ), true ) && defined( 'ELEMENTOR_PRO_VERSION' ) && ( '' === $language || $language === $default ) ) {
			$includes = array_filter(
				(array) get_post_meta( $post_id, '_elementor_conditions', true ),
				static function ( $condition ) {
					return is_string( $condition ) && 0 === strpos( $condition, 'include/' );
				}
			);

			$checks[] = self::check(
				'display_conditions',
				array() !== $includes,
				__( 'The default-language template has Elementor display conditions, and translated templates follow the language automatically.', 'linguator-pro' ),
				__( 'This default-language template has no "include" display condition in Elementor Theme Builder, so it is not shown on pages at all. Ask the user to set its display conditions (for example Entire Site); translated versions then follow the language.', 'linguator-pro' )
			);
		}

		$widgets    = array();
		$next_steps = array();

		foreach ( self::find_menu_widgets( self::get_elements( $post_id ) ) as $widget ) {
			$entry = self::describe_widget( $widget, $target_lang );

			if ( 0 === $entry['menu_id'] ) {
				$next_steps[] = sprintf(
					/* translators: 1: widget ID, 2: value stored in the widget. */
					__( 'The Nav Menu widget %1$s names a menu ("%2$s") that does not exist on this site, so it shows nothing. Create or restore that menu, or call set-template-menu to point the widget at an existing one.', 'linguator-pro' ),
					$entry['widget_id'],
					$entry['stored_value']
				);
			} elseif ( '' !== $target_lang && $language !== $target_lang ) {
				if ( null === $entry['translated_menu'] ) {
					$next_steps[] = Menu_Service::is_enabled() ? sprintf(
						/* translators: 1: menu name, 2: widget ID, 3: language slug. */
						__( 'The Nav Menu widget %2$s shows menu "%1$s", and there is no %3$s menu for it yet. Run sync-menu for it (dry_run false), then translate this template and call set-template-menu.', 'linguator-pro' ),
						$entry['menu_name'],
						$entry['widget_id'],
						$target_lang
					) : sprintf(
						/* translators: 1: menu name, 2: widget ID, 3: language slug. */
						__( 'The Nav Menu widget %2$s shows menu "%1$s", and there is no %3$s menu for it yet. This does not block translating the template. To show a separate %3$s menu afterwards, ask a site administrator to switch on Menu Synchronization in Linguator settings and run sync-menu, or create a %3$s menu manually and use set-template-menu.', 'linguator-pro' ),
						$entry['menu_name'],
						$entry['widget_id'],
						$target_lang
					);
				} else {
					$next_steps[] = sprintf(
						/* translators: 1: widget ID, 2: translated menu ID, 3: language slug. */
						__( 'After translating this template, call set-template-menu on the %3$s template with widget_id %1$s and menu_id %2$d. Elementor does not switch menus by language on its own.', 'linguator-pro' ),
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
	 * Nav Menu widgets in an element tree that name a menu.
	 *
	 * @since 1.2.0
	 * @param array<int, mixed> $elements Elementor elements.
	 * @return array<int, array<string, string>> Widgets with their widget_id and stored menu value.
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
	 * Changes the menu one Nav Menu widget of an Elementor template shows.
	 *
	 * @since 1.2.0
	 * @param int    $post_id   Template ID.
	 * @param int    $menu_id   Menu the widget should show.
	 * @param string $widget_id Widget to change; needed only when there is more than one.
	 * @return array<string, mixed>|WP_Error What was changed, or an error.
	 */
	public static function set_menu( $post_id, $menu_id, $widget_id = '' ) {
		$post_id   = absint( $post_id );
		$menu_id   = absint( $menu_id );
		$widget_id = sanitize_text_field( (string) $widget_id );

		if ( null === get_post( $post_id ) || ! self::is_elementor_post( $post_id ) ) {
			return new WP_Error(
				'lmat_not_elementor',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d was not found, is not built with Elementor, or Elementor is not active.', 'linguator-pro' ),
					$post_id
				)
			);
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

		$post_lang = (string) linguator_get_post_language( $post_id );
		$info      = Menu_Service::menu_info( $menu_id );
		$menu_lang = $info['language'];

		if ( '' !== $post_lang && '' !== $menu_lang && $post_lang !== $menu_lang ) {
			return new WP_Error(
				'lmat_menu_language_mismatch',
				sprintf(
					/* translators: 1: menu name, 2: menu language, 3: template language. */
					__( 'Menu "%1$s" belongs to language "%2$s", but this template is in "%3$s". Nothing was changed. Use the menu that sync-menu created for "%3$s".', 'linguator-pro' ),
					$menu->name,
					$menu_lang,
					$post_lang
				)
			);
		}

		$elements = self::get_elements( $post_id );
		$widgets  = self::find_menu_widgets( $elements );

		if ( array() === $widgets ) {
			return new WP_Error(
				'lmat_no_menu_widget',
				__( 'This template has no Elementor Nav Menu widget that names a menu, so there is nothing to change. Nothing was changed.', 'linguator-pro' )
			);
		}

		if ( '' === $widget_id ) {
			if ( count( $widgets ) > 1 ) {
				return new WP_Error(
					'lmat_widget_required',
					sprintf(
						/* translators: %s: comma-separated widget IDs. */
						__( 'This template has more than one Nav Menu widget (%s). Pass widget_id to say which one to change. Nothing was changed.', 'linguator-pro' ),
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
				'lmat_widget_not_found',
				sprintf(
					/* translators: %s: widget ID. */
					__( 'No Nav Menu widget with ID "%s" in this template. Nothing was changed.', 'linguator-pro' ),
					$widget_id
				)
			);
		}

		// Elementor stores the menu slug; keep that form when the slug resolves back to this menu.
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
			$value  = $previous;
			$stored = ctype_digit( $previous ) ? 'id' : 'slug';
		} else {
			$updated = self::replace_menu( $elements, $widget_id, $value );
			$changed = true;

			$document = \Elementor\Plugin::$instance->documents->get( $post_id );

			if ( ! $document || ! $document->save( array( 'elements' => $updated ) ) ) {
				return new WP_Error(
					'lmat_save_failed',
					__( 'Elementor could not save the template. Nothing was changed.', 'linguator-pro' )
				);
			}

			$ok = false;

			foreach ( self::find_menu_widgets( self::get_elements( $post_id ) ) as $widget ) {
				if ( $widget['widget_id'] === $widget_id && self::menu_matches( $widget['menu'], $menu_id ) ) {
					$ok = true;
				}
			}

			if ( ! $ok ) {
				return new WP_Error(
					'lmat_menu_not_saved',
					__( 'Elementor saved the template, but the widget still does not show the new menu when read back. Check it in the editor.', 'linguator-pro' )
				);
			}
		}

		$cleared = self::clear_element_cache();

		return array(
			'success'                 => true,
			'changed'                 => $changed,
			'template_id'             => $post_id,
			'widget_id'               => $widget_id,
			'previous_menu'           => $previous,
			'menu_id'                 => $menu_id,
			'menu_name'               => (string) $menu->name,
			'stored_as'               => $stored,
			'stored_value'            => $value,
			'elementor_cache_cleared' => $cleared,
			'notice'                  => $changed
				? __( 'The widget now shows this menu, and Elementor\'s cache was cleared so the old menu is not served from it. Check the public page to confirm (verify-translations requests it).', 'linguator-pro' )
				: __( 'The widget already showed this menu, so the template was not changed. Elementor\'s cache was cleared in case an old menu was still being served.', 'linguator-pro' ),
		);
	}

	/**
	 * Nav Menu widgets in the templates of one language.
	 *
	 * @since 1.2.0
	 * @param string $lang Language slug.
	 * @return array<int, array<string, mixed>> Template and widget IDs with the menu each shows.
	 */
	public static function nav_widgets_in_language( $lang ) {
		if ( ! self::elementor_active() || ! Linguator_Guard::is_translated_post_type( self::TEMPLATE_POST_TYPE ) ) {
			return array();
		}

		$language = Linguator_Guard::language( (string) $lang );

		if ( is_wp_error( $language ) ) {
			return array();
		}

		// The language is matched by its taxonomy term, not by the "lang" query variable, so the lookup cannot fall back to the current language.
		$ids = get_posts(
			array(
				'post_type'      => self::TEMPLATE_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => self::MAX_TEMPLATES,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'lang'           => '',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One indexed language lookup, bounded by posts_per_page.
					array(
						'taxonomy' => 'lmat_language',
						'field'    => 'term_taxonomy_id',
						'terms'    => array( (int) $language->get_tax_prop( 'lmat_language', 'term_taxonomy_id' ) ),
					),
				),
			)
		);

		$found = array();

		foreach ( (array) $ids as $template_id ) {
			if ( false === strpos( (string) get_post_meta( (int) $template_id, '_elementor_data', true ), self::MENU_WIDGET ) ) {
				continue;
			}

			foreach ( self::find_menu_widgets( self::get_elements( (int) $template_id ) ) as $widget ) {
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
	 * Clears Elementor's generated CSS and element cache.
	 *
	 * @since 1.2.0
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
	 * Whether a stored widget value points at a menu.
	 *
	 * @since 1.2.0
	 * @param string $stored  Value stored in the widget.
	 * @param int    $menu_id Menu ID.
	 * @return bool True when they match.
	 */
	private static function menu_matches( $stored, $menu_id ) {
		$object = wp_get_nav_menu_object( $stored );

		return $object && (int) $object->term_id === (int) $menu_id;
	}

	/**
	 * Returns an element tree with one Nav Menu widget pointed at another menu.
	 *
	 * @since 1.2.0
	 * @param array<int, mixed> $elements  Elementor elements.
	 * @param string            $widget_id Widget to change.
	 * @param string            $value     New stored menu value.
	 * @return array<int, mixed> Changed tree.
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
	 * Describes one Nav Menu widget and the menu it shows.
	 *
	 * @since 1.2.0
	 * @param array<string, string> $widget      Widget ID and stored value.
	 * @param string                $target_lang Optional target language slug.
	 * @return array<string, mixed> Widget description.
	 */
	private static function describe_widget( array $widget, $target_lang ) {
		$menu = wp_get_nav_menu_object( $widget['menu'] );
		$info = $menu ? Menu_Service::menu_info( (int) $menu->term_id ) : null;

		$entry = array(
			'widget_id'          => $widget['widget_id'],
			'stored_value'       => $widget['menu'],
			'stored_as'          => ctype_digit( $widget['menu'] ) ? 'id' : 'slug_or_name',
			'menu_id'            => $menu ? (int) $menu->term_id : 0,
			'menu_name'          => $menu ? (string) $menu->name : '',
			'menu_language'      => null !== $info ? $info['language'] : '',
			'has_theme_location' => null !== $info && array() !== $info['locations'],
			'translated_menu'    => null,
		);

		if ( $menu && '' !== $target_lang ) {
			$entry['translated_menu'] = Menu_Service::find_translation( (int) $menu->term_id, $target_lang );
		}

		if ( ! $menu ) {
			$entry['problem'] = __( 'The widget names a menu that no longer exists.', 'linguator-pro' );
		}

		return $entry;
	}

	/**
	 * Builds one check entry.
	 *
	 * @since 1.2.0
	 * @param string $key     Check key.
	 * @param bool   $ok      Whether it passed.
	 * @param string $ok_text Text when it passed.
	 * @param string $fix     Text when it failed.
	 * @return array<string, mixed> Check.
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
	 * @since 1.2.0
	 * @param array<int, array<string, mixed>> $checks Checks.
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
