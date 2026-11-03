<?php
/**
 * Translation scope: what a "translate the site" request covers, and whether it is done.
 *
 * @package WPML_Auto_Translate
 * @since   1.6.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the checklist for a site translation and checks the finished result.
 *
 * Read-only. It never writes a post, term, menu or option, so it is safe to
 * call as often as an agent likes, and a half-finished job can be resumed by
 * calling it again: the answer is rebuilt from WPML's own data each time.
 *
 * @since 1.6.0
 */
final class Scope_Service {

	/**
	 * Most source posts verify() accepts in one call.
	 *
	 * Each published page is requested over HTTP, so the cap keeps one call
	 * inside the 60 second tool timeout some MCP clients apply.
	 *
	 * @since 1.6.0
	 * @var int
	 */
	const MAX_VERIFY = 5;

	/**
	 * Seconds one verify call may spend requesting pages.
	 *
	 * @since 1.6.0
	 * @var int
	 */
	const TIME_BUDGET = 50;

	/**
	 * Most header and footer templates listed in a plan.
	 *
	 * @since 1.6.0
	 * @var int
	 */
	const MAX_TEMPLATES = 20;

	/**
	 * Elementor template post type.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	const TEMPLATE_POST_TYPE = 'elementor_library';

	/**
	 * Menu location names that usually hold the header menu.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	const HEADER_LOCATION_PATTERN = '/primary|main|header|menu-1/i';

	/**
	 * Builds the checklist for translating the homepage, header, footer and a menu.
	 *
	 * @since 1.6.0
	 * @param string   $target_lang Target language code.
	 * @param int      $menu_id     Menu to include, or 0 to use the header menu.
	 * @param string[] $include     Parts to include: homepage, header, footer, menu.
	 * @return array<string, mixed>|WP_Error The plan, or an error.
	 */
	public static function plan( $target_lang, $menu_id = 0, array $include = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$target_lang = sanitize_text_field( (string) $target_lang );

		if ( ! Language_Service::is_active( $target_lang ) ) {
			return Language_Service::inactive_error( $target_lang );
		}

		$default = Language_Service::get_default_code();

		if ( $target_lang === $default ) {
			return new WP_Error(
				'automlp_same_language',
				__( 'The target language is the default language, which is the source of the plan. Choose another language.', 'wpml-translation-check-pro' )
			);
		}

		$include  = array_values( array_intersect( array( 'homepage', 'header', 'footer', 'menu' ), array_map( 'sanitize_key', $include ) ) );
		$include  = array() === $include ? array( 'homepage', 'header', 'footer', 'menu' ) : $include;
		$blockers = array();
		$notes    = array();
		$items    = array();
		$seen     = array();

		foreach ( self::templates( $target_lang, $default, $include, $blockers ) as $item ) {
			$items[]                         = $item;
			$seen[ $item['source_post_id'] ] = true;
		}

		if ( in_array( 'homepage', $include, true ) ) {
			$front = self::front_page( $default );

			if ( $front > 0 ) {
				$item                            = self::describe_post( 'homepage', $front, $target_lang );
				$items[]                         = $item;
				$seen[ $item['source_post_id'] ] = true;
			} else {
				$notes[] = __( 'The site shows latest posts on its home page, so there is no homepage page to translate.', 'wpml-translation-check-pro' );
			}
		}

		$menu_block      = null;
		$available_menus = array();

		if ( in_array( 'menu', $include, true ) ) {
			$menu            = self::menu( (int) $menu_id, $target_lang, $default );
			$available_menus = $menu['available'];
			$menu_block      = $menu['menu'];

			if ( null === $menu_block ) {
				$notes[] = __( 'No navigation menu is assigned to a theme location, so no menu pages were listed. Pass menu_id (see available_menus) to include one.', 'wpml-translation-check-pro' );
			} elseif ( 0 === $menu_block['translated_menu_id'] ) {
				$notes[] = sprintf(
					/* translators: %s: language code. */
					__( 'The menu has no %s copy yet. WPML builds it from WPML > Translations > Menus (older WPML: WPML > WP Menus Sync), which only an administrator can run: these tools cannot. Translate the menu pages first, then tell the user to run it. Menu labels are WPML strings: get-string-groups lists the group named "<menu name> menu", and translate-strings saves them.', 'wpml-translation-check-pro' ),
					$target_lang
				);
			}

			foreach ( $menu['entries'] as $entry ) {
				if ( isset( $entry['source_post_id'] ) ) {
					if ( isset( $seen[ $entry['source_post_id'] ] ) ) {
						continue;
					}

					$seen[ $entry['source_post_id'] ] = true;
				}

				$items[] = $entry;
			}
		}

		$remaining   = array();
		$done        = 0;
		$manual      = 0;
		$verify      = array();
		$no_language = array();

		foreach ( $items as $index => $item ) {
			$items[ $index ]['order'] = $index + 1;

			if ( 'translated' === $item['state'] ) {
				++$done;
			} elseif ( 'check_manually' === $item['state'] ) {
				++$manual;
			} else {
				$remaining[] = $items[ $index ];
			}

			if ( isset( $item['source_post_id'] ) && 'source_missing' !== $item['state'] ) {
				$verify[] = (int) $item['source_post_id'];
			}

			if ( 'no_language' === $item['state'] ) {
				$no_language[] = (int) $item['source_post_id'];
			}
		}

		if ( array() !== $no_language ) {
			$blockers[] = sprintf(
				/* translators: %s: comma-separated post IDs. */
				__( 'These posts have no language: %s. Give each one a language with manage-translation-links (action "set_language"), then translate it.', 'wpml-translation-check-pro' ),
				implode( ', ', $no_language )
			);
		}

		$blockers = array_values( array_unique( $blockers ) );
		$total    = count( $items ) - $manual;
		$next     = array() !== $remaining ? $remaining[0] : null;

		// A post that cannot be translated yet (no language) is a blocker, not the next job: put real work first.
		foreach ( $remaining as $candidate ) {
			if ( 'no_language' !== $candidate['state'] ) {
				$next = $candidate;
				break;
			}
		}

		if ( array() !== $blockers ) {
			$next_action = $blockers[0];
		} elseif ( null !== $next && 'unpublished' === $next['state'] ) {
			$next_action = sprintf(
				/* translators: %d: source post ID. */
				__( 'Source post %d has a translation that is not published, so visitors get a 404. Publish it: translate-post with that post_id, the target language and post_status "publish" (nothing else is needed). Then continue with the other remaining items.', 'wpml-translation-check-pro' ),
				(int) $next['source_post_id']
			);
		} elseif ( null !== $next ) {
			$next_action = sprintf(
				/* translators: 1: item role, 2: source post or term ID. */
				__( 'Translate the next item (%1$s, source ID %2$d): call get-content, then translate-post with post_status "publish" when the user wants it live (new translations are otherwise created as %3$s). Do this for every remaining item in order.', 'wpml-translation-check-pro' ),
				(string) $next['role'],
				isset( $next['source_post_id'] ) ? (int) $next['source_post_id'] : (int) $next['source_term_id'],
				self::new_post_status()
			);
		} else {
			$next_action = __( 'Every item has a published translation. That does not prove the pages work: run verify-translations with each batch in verify_batches.', 'wpml-translation-check-pro' );
		}

		return array(
			'target_language'  => $target_lang,
			'default_language' => $default,
			'outcome'          => ( array() === $remaining && array() === $blockers ) ? 'ready_to_verify' : 'work_remaining',
			'summary'          => sprintf(
				/* translators: 1: translated count, 2: total count, 3: remaining count. */
				__( '%1$d of %2$d items already have a published translation; %3$d remain. A published translation is not proof that the page works: finish with verify-translations.', 'wpml-translation-check-pro' ),
				$done,
				$total,
				count( $remaining )
			),
			'counts'           => array(
				'total'      => $total,
				'translated' => $done,
				'remaining'  => count( $remaining ),
				'manual'     => $manual,
			),
			'new_post_status'  => self::new_post_status(),
			'blockers'         => $blockers,
			'notes'            => $notes,
			'items'            => $items,
			'next_item'        => $next,
			'menu'             => $menu_block,
			'available_menus'  => $available_menus,
			'steps'            => self::steps( $menu_block ),
			'verify_batches'   => array_chunk( $verify, self::MAX_VERIFY ),
			'next_action'      => $next_action,
		);
	}

	/**
	 * Checks that the translations of the given source posts exist and work.
	 *
	 * @since 1.6.0
	 * @param string $target_lang Target language code.
	 * @param int[]  $source_ids  Source post IDs, at most MAX_VERIFY.
	 * @param bool   $check_http  Whether to request each public page.
	 * @param int    $menu_id     Source menu to check as well, or 0.
	 * @return array<string, mixed>|WP_Error The report, or an error.
	 */
	public static function verify( $target_lang, array $source_ids, $check_http = true, $menu_id = 0 ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$target_lang = sanitize_text_field( (string) $target_lang );

		if ( ! Language_Service::is_active( $target_lang ) ) {
			return Language_Service::inactive_error( $target_lang );
		}

		$source_ids = array_values( array_unique( array_filter( array_map( 'absint', $source_ids ) ) ) );

		if ( array() === $source_ids ) {
			return new WP_Error( 'automlp_no_posts', __( 'Pass at least one source post ID in source_post_ids.', 'wpml-translation-check-pro' ) );
		}

		if ( count( $source_ids ) > self::MAX_VERIFY ) {
			return new WP_Error(
				'automlp_too_many_posts',
				sprintf(
					/* translators: %d: maximum number of posts. */
					__( 'Verify at most %d posts per call, because each page is requested over HTTP. Split the list and call again.', 'wpml-translation-check-pro' ),
					self::MAX_VERIFY
				)
			);
		}

		$locale_prefix = self::language_prefix( self::locale_of( $target_lang ) );
		$deadline      = microtime( true ) + self::TIME_BUDGET;
		$results       = array();
		$problems      = array();

		foreach ( $source_ids as $source_id ) {
			$result = self::verify_post( $source_id, $target_lang, $locale_prefix, (bool) $check_http, $deadline );

			foreach ( $result['problems'] as $problem ) {
				$problems[] = array_merge( array( 'source_post_id' => $source_id ), $problem );
			}

			$results[] = $result;
		}

		$homepage = self::verify_homepage( $target_lang, $locale_prefix, (bool) $check_http, $deadline );

		if ( null !== $homepage ) {
			foreach ( $homepage['problems'] as $problem ) {
				$problems[] = array_merge( array( 'source_post_id' => $homepage['source_post_id'] ), $problem );
			}
		}

		$menu_report = null;

		if ( (int) $menu_id > 0 ) {
			$menu_report = self::verify_menu( (int) $menu_id, $target_lang );

			foreach ( $menu_report['problems'] as $problem ) {
				$problems[] = $problem;
			}
		}

		$failed = array();

		foreach ( $results as $result ) {
			if ( ! $result['ok'] ) {
				$failed[] = $result['source_post_id'];
			}
		}

		$ok = array() === $problems;

		return array(
			'target_language'   => $target_lang,
			'complete'          => $ok,
			'outcome'           => $ok ? 'complete' : 'needs_attention',
			'summary'           => $ok
				? sprintf(
					/* translators: %d: number of posts. */
					__( 'All %d checked posts have a published translation in the target language and their public pages answered successfully. This covers the items you passed only; confirm the plan has no other remaining items.', 'wpml-translation-check-pro' ),
					count( $results )
				)
				: sprintf(
					/* translators: %d: number of problems. */
					__( '%d problem(s) found. Do not report this job as finished until each one is fixed or explained to the user.', 'wpml-translation-check-pro' ),
					count( $problems )
				),
			'http_checked'      => (bool) $check_http,
			'items'             => $results,
			'failed_source_ids' => $failed,
			'homepage'          => $homepage,
			'menu'              => $menu_report,
			'problems'          => $problems,
			'next_action'       => $ok
				? __( 'Report the verified URLs to the user, and list anything that was intentionally left untranslated.', 'wpml-translation-check-pro' )
				: self::fix_hint( (string) $problems[0]['code'] ),
		);
	}

	/**
	 * Header and footer templates in the default language.
	 *
	 * @since 1.6.0
	 * @param string   $target_lang Target language code.
	 * @param string   $default     Default language code.
	 * @param string[] $include     Parts requested.
	 * @param string[] $blockers    Collects problems that stop the work.
	 * @return array<int, array<string, mixed>> One entry per template.
	 */
	private static function templates( $target_lang, $default, array $include, array &$blockers ) {
		$types = array_values( array_intersect( array( 'header', 'footer' ), $include ) );

		if ( array() === $types || ! did_action( 'elementor/loaded' ) ) {
			return array();
		}

		if ( ! Content_Service::is_post_type_translatable( self::TEMPLATE_POST_TYPE ) ) {
			$blockers[] = __( 'WPML does not translate "My Templates" (elementor_library), so headers and footers have no language and cannot be translated yet. This is a site setting no AutoMLP tool can change. Tell the user a site administrator must set it to "Translatable" under WPML > Settings > Post Types Translation and save.', 'wpml-translation-check-pro' );

			return array();
		}

		$ids = get_posts(
			array(
				'post_type'        => self::TEMPLATE_POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => self::MAX_TEMPLATES,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true, // All languages: WPML would otherwise show only the current one.
				'meta_query'       => array(
					array(
						'key'     => '_elementor_template_type',
						'value'   => $types,
						'compare' => 'IN',
					),
				),
			)
		);

		$items = array();

		foreach ( (array) $ids as $id ) {
			$details  = Translation_Service::get_element_language_details( (int) $id );
			$language = null === $details ? '' : (string) $details['language_code'];

			// A template already in another language is a translation, not a source.
			if ( '' !== $language && $language !== $default ) {
				continue;
			}

			$type    = (string) get_post_meta( (int) $id, '_elementor_template_type', true );
			$items[] = self::describe_post( 'template_' . $type, (int) $id, $target_lang, array( 'template_type' => $type ) );
		}

		return $items;
	}

	/**
	 * The static front page, as its default-language original.
	 *
	 * @since 1.6.0
	 * @param string $default Default language code.
	 * @return int Source post ID, or 0 when the site shows latest posts.
	 */
	private static function front_page( $default ) {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return 0;
		}

		$id = (int) get_option( 'page_on_front' );

		if ( $id <= 0 ) {
			return 0;
		}

		$original = apply_filters( 'wpml_object_id', $id, 'page', true, $default ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return $original ? (int) $original : $id;
	}

	/**
	 * A navigation menu and the content its items point to.
	 *
	 * @since 1.6.0
	 * @param int    $menu_id     Menu to use, or 0 to pick the header menu.
	 * @param string $target_lang Target language code.
	 * @param string $default     Default language code.
	 * @return array{menu: array<string, mixed>|null, available: array<int, array<string, mixed>>, entries: array<int, array<string, mixed>>} Menu data.
	 */
	private static function menu( $menu_id, $target_lang, $default ) {
		$available = array();
		$chosen    = 0;
		$location  = '';

		foreach ( (array) get_nav_menu_locations() as $name => $id ) {
			if ( (int) $id <= 0 ) {
				continue;
			}

			$object      = wp_get_nav_menu_object( (int) $id );
			$available[] = array(
				'location' => (string) $name,
				'menu_id'  => (int) $id,
				'name'     => $object ? (string) $object->name : '',
			);
		}

		if ( $menu_id > 0 ) {
			$chosen = $menu_id;

			foreach ( $available as $entry ) {
				if ( $entry['menu_id'] === $menu_id ) {
					$location = $entry['location'];
				}
			}
		} else {
			foreach ( $available as $entry ) {
				if ( 0 === $chosen ) {
					$chosen   = $entry['menu_id'];
					$location = $entry['location'];
				}

				if ( preg_match( self::HEADER_LOCATION_PATTERN, $entry['location'] ) ) {
					$chosen   = $entry['menu_id'];
					$location = $entry['location'];
					break;
				}
			}
		}

		$object = $chosen > 0 ? wp_get_nav_menu_object( $chosen ) : false;

		if ( ! $object ) {
			return array(
				'menu'      => null,
				'available' => $available,
				'entries'   => array(),
			);
		}

		$items = self::menu_items_in( $chosen, $default );

		$parents = array();

		foreach ( $items as $item ) {
			$parents[ (int) $item->ID ] = (int) $item->menu_item_parent;
		}

		$entries = array();

		foreach ( $items as $item ) {
			$depth  = 0;
			$cursor = (int) $item->ID;
			$guard  = 0;

			while ( ! empty( $parents[ $cursor ] ) && $guard < 10 ) {
				++$depth;
				++$guard;
				$cursor = $parents[ $cursor ];
			}

			$extra = array(
				'menu_label' => (string) $item->title,
				'menu_depth' => $depth,
			);

			if ( 'post_type' === $item->type ) {
				$entries[] = self::describe_post( 'menu_page', (int) $item->object_id, $target_lang, $extra );
			} elseif ( 'taxonomy' === $item->type ) {
				$term_id    = (int) $item->object_id;
				$translated = apply_filters( 'wpml_object_id', $term_id, (string) $item->object, false, $target_lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				$entries[]  = array_merge(
					array(
						'role'                => 'menu_term',
						'source_term_id'      => $term_id,
						'taxonomy'            => (string) $item->object,
						'title'               => (string) $item->title,
						'translation_term_id' => $translated ? (int) $translated : 0,
						'state'               => $translated ? 'translated' : 'missing',
					),
					$extra
				);
			} else {
				$entries[] = array_merge(
					array(
						'role'  => 'menu_custom_link',
						'title' => (string) $item->title,
						'url'   => (string) $item->url,
						'state' => 'check_manually',
						'note'  => __( 'A custom link is not a translatable object. Check where it leads in the target language.', 'wpml-translation-check-pro' ),
					),
					$extra
				);
			}
		}

		$translated_menu = apply_filters( 'wpml_object_id', $chosen, 'nav_menu', false, $target_lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return array(
			'menu'      => array(
				'menu_id'            => $chosen,
				'name'               => (string) $object->name,
				'location'           => $location,
				'item_count'         => count( $items ),
				'translated_menu_id' => $translated_menu ? (int) $translated_menu : 0,
				'synced_by'          => 'administrator, under WPML > Translations > Menus (older WPML: WPML > WP Menus Sync)',
			),
			'available' => $available,
			'entries'   => $entries,
		);
	}

	/**
	 * Items of a menu, read in a given language.
	 *
	 * WPML filters menu items by the language the request is in, so the
	 * language is set explicitly and put back afterwards.
	 *
	 * @since 1.6.0
	 * @param int    $menu_id  Menu ID.
	 * @param string $language Language code to read in.
	 * @return array<int, object> Menu items.
	 */
	private static function menu_items_in( $menu_id, $language ) {
		$previous = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		do_action( 'wpml_switch_language', $language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		try {
			$items = wp_get_nav_menu_items( (int) $menu_id );
		} finally {
			do_action( 'wpml_switch_language', $previous ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}

		return is_array( $items ) ? $items : array();
	}

	/**
	 * Describes one source post and where its translation stands.
	 *
	 * @since 1.6.0
	 * @param string               $role        Why the post is in the plan.
	 * @param int                  $post_id     Source post ID.
	 * @param string               $target_lang Target language code.
	 * @param array<string, mixed> $extra       More fields to attach.
	 * @return array<string, mixed> Description.
	 */
	private static function describe_post( $role, $post_id, $target_lang, array $extra = array() ) {
		$post = get_post( $post_id );

		if ( null === $post ) {
			return array_merge(
				array(
					'role'           => $role,
					'source_post_id' => $post_id,
					'state'          => 'source_missing',
				),
				$extra
			);
		}

		$details     = Translation_Service::get_element_language_details( $post_id );
		$source_lang = null === $details ? '' : (string) $details['language_code'];
		$translation = self::find_translation( $post_id, (string) $post->post_type, $target_lang );

		if ( '' === $source_lang ) {
			$state = 'no_language';
		} elseif ( null === $translation ) {
			$state = 'missing';
		} elseif ( 'publish' !== $translation->post_status ) {
			$state = 'unpublished';
		} else {
			$state = 'translated';
		}

		return array_merge(
			array(
				'role'               => $role,
				'source_post_id'     => $post_id,
				'title'              => (string) $post->post_title,
				'post_type'          => (string) $post->post_type,
				'source_status'      => (string) $post->post_status,
				'source_language'    => $source_lang,
				'translation_id'     => null === $translation ? 0 : (int) $translation->ID,
				'translation_status' => null === $translation ? '' : (string) $translation->post_status,
				'state'              => $state,
			),
			$extra
		);
	}

	/**
	 * The translation of a post in a language, whatever its status.
	 *
	 * @since 1.6.0
	 * @param int    $post_id   Source post ID.
	 * @param string $post_type Its post type.
	 * @param string $language  Language code.
	 * @return \WP_Post|null The translation, or null when there is none.
	 */
	private static function find_translation( $post_id, $post_type, $language ) {
		$group = Translation_Service::get_post_translations( $post_id );

		if ( is_array( $group ) && isset( $group['translations'][ $language ]['post_id'] ) ) {
			$post = get_post( (int) $group['translations'][ $language ]['post_id'] );

			if ( $post instanceof \WP_Post ) {
				return $post;
			}
		}

		$id = apply_filters( 'wpml_object_id', $post_id, $post_type, false, $language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( $id && (int) $id !== (int) $post_id ) {
			$post = get_post( (int) $id );

			return $post instanceof \WP_Post ? $post : null;
		}

		return null;
	}

	/**
	 * The status new translations are created with when post_status is not passed.
	 *
	 * Follows WPML's "translated document status" setting.
	 *
	 * @since 1.6.0
	 * @return string "draft" or "same_as_source".
	 */
	private static function new_post_status() {
		return Translation_Writer::wpml_new_post_status();
	}

	/**
	 * The fixed order of work, tailored to whether a menu is involved.
	 *
	 * @since 1.6.0
	 * @param array<string, mixed>|null $menu Menu block, or null.
	 * @return string[] Ordered steps.
	 */
	private static function steps( $menu ) {
		$steps = array(
			__( '1. Resolve every entry in blockers first.', 'wpml-translation-check-pro' ),
			__( '2. For each header or footer template, and then the homepage and each menu page: get-content, then translate-post with every string under the exact keys given and post_status "publish" when the user wants it live. Read outcome, summary and next_action on every result.', 'wpml-translation-check-pro' ),
			__( '3. Translate menu_term items with the term tools of your client if available; otherwise report them.', 'wpml-translation-check-pro' ),
		);

		if ( null !== $menu ) {
			$steps[] = __( '4. Menu: after the menu pages are translated and published, an administrator must run WPML > Translations > Menus (older WPML: WPML > WP Menus Sync). These tools cannot. Menu labels are WPML strings (get-string-groups, get-strings, translate-strings). Tell the user.', 'wpml-translation-check-pro' );
		}

		$steps[] = __( 'Last: run verify-translations for each batch in verify_batches (pass menu_id when the menu was synced). Report only what it confirms.', 'wpml-translation-check-pro' );

		return $steps;
	}

	/**
	 * Checks one source post's translation.
	 *
	 * @since 1.6.0
	 * @param int    $source_id     Source post ID.
	 * @param string $target_lang   Target language code.
	 * @param string $locale_prefix Language part of the target locale, such as "pt".
	 * @param bool   $check_http    Whether to request the public page.
	 * @param float  $deadline      Microtime by which the whole verify call should finish.
	 * @return array<string, mixed> Result with ok, problems and warnings.
	 */
	private static function verify_post( $source_id, $target_lang, $locale_prefix, $check_http, $deadline ) {
		$source = get_post( $source_id );
		$result = array(
			'source_post_id' => $source_id,
			'translation_id' => 0,
			'ok'             => false,
			'problems'       => array(),
			'warnings'       => array(),
		);

		if ( null === $source ) {
			$result['problems'][] = self::problem( 'source_not_found', __( 'The source post was not found.', 'wpml-translation-check-pro' ) );

			return $result;
		}

		$details = Translation_Service::get_element_language_details( $source_id );

		if ( null === $details || '' === (string) $details['language_code'] ) {
			$result['problems'][] = self::problem( 'source_no_language', __( 'The source post has no language, so it cannot have a translation yet.', 'wpml-translation-check-pro' ) );

			return $result;
		}

		$translation = self::find_translation( $source_id, (string) $source->post_type, $target_lang );

		if ( null === $translation ) {
			$result['problems'][] = self::problem( 'no_translation', __( 'No translation exists in the target language.', 'wpml-translation-check-pro' ) );

			return $result;
		}

		$translation_id           = (int) $translation->ID;
		$result['translation_id'] = $translation_id;
		$result['status']         = (string) $translation->post_status;

		$translated_details = Translation_Service::get_element_language_details( $translation_id );

		if ( null === $translated_details || (string) $translated_details['language_code'] !== $target_lang ) {
			$result['problems'][] = self::problem( 'wrong_language', __( 'The linked translation is not assigned to the target language.', 'wpml-translation-check-pro' ) );
		}

		if ( 'publish' !== $translation->post_status ) {
			$result['problems'][] = self::problem(
				'not_published',
				sprintf(
					/* translators: %s: post status. */
					__( 'The translation is "%s", so visitors get a 404.', 'wpml-translation-check-pro' ),
					$translation->post_status
				)
			);
		}

		$source_title = trim( (string) $source->post_title );

		if ( strlen( $source_title ) > 3 && 0 === strcasecmp( $source_title, trim( (string) $translation->post_title ) ) ) {
			$result['warnings'][] = __( 'The translated title is identical to the source title. That is fine for a brand name; otherwise the title may not have been translated.', 'wpml-translation-check-pro' );
		}

		// WPML's own "complete" flag is set by whoever wrote the translation; the audit looks at the content itself.
		$audit = Integrity_Service::audit( $translation_id, array( 'content', 'links', 'assets' ) );

		if ( is_array( $audit ) && ! empty( $audit['issues'] ) ) {
			foreach ( (array) $audit['issues'] as $issue ) {
				$type    = isset( $issue['type'] ) ? (string) $issue['type'] : '';
				$message = isset( $issue['message'] ) ? (string) $issue['message'] : $type;

				if ( 'untranslated_content' === $type ) {
					$result['warnings'][] = $message;
				} else {
					$result['problems'][] = self::problem( 'audit_' . $type, $message );
				}
			}
		}

		if ( $check_http && 'publish' === $translation->post_status ) {
			$is_template = self::TEMPLATE_POST_TYPE === $source->post_type;
			$url         = $is_template ? self::language_home( $target_lang ) : self::permalink_in( $translation_id, $target_lang );
			$page        = self::fetch_page( $url, self::request_timeout( $deadline ) );

			if ( empty( $page['verified'] ) ) {
				$result['problems'][] = self::problem( 'http_not_verified', (string) $page['reason'] );
			} else {
				$result['http_status'] = (int) $page['http_status'];
				$result['checked_url'] = $url;
				$result['final_url']   = (string) $page['final_url'];

				if ( $page['http_status'] >= 400 ) {
					$result['problems'][] = self::problem(
						'http_error',
						sprintf(
							/* translators: 1: HTTP status, 2: address. */
							__( 'The public page answered HTTP %1$d at %2$s.', 'wpml-translation-check-pro' ),
							(int) $page['http_status'],
							(string) $page['final_url']
						)
					);
				} elseif ( '' !== $page['html_lang'] && self::language_prefix( $page['html_lang'] ) !== $locale_prefix ) {
					$result['problems'][] = self::problem(
						'page_language_mismatch',
						sprintf(
							/* translators: 1: language the page declares, 2: expected language. */
							__( 'The page declares language "%1$s" but "%2$s" was expected.', 'wpml-translation-check-pro' ),
							(string) $page['html_lang'],
							$locale_prefix
						)
					);
				}
			}
		}

		$result['ok'] = array() === $result['problems'];

		return $result;
	}

	/**
	 * Checks the static front page for the target language and the language home address.
	 *
	 * @since 1.6.0
	 * @param string $target_lang   Target language code.
	 * @param string $locale_prefix Language part of the target locale.
	 * @param bool   $check_http    Whether to request the language home page.
	 * @param float  $deadline      Microtime by which the whole verify call should finish.
	 * @return array<string, mixed>|null Report, or null when the site shows latest posts.
	 */
	private static function verify_homepage( $target_lang, $locale_prefix, $check_http, $deadline ) {
		$default = Language_Service::get_default_code();
		$front   = self::front_page( $default );

		if ( $front <= 0 ) {
			return null;
		}

		$post        = get_post( $front );
		$translation = null === $post ? null : self::find_translation( $front, (string) $post->post_type, $target_lang );
		$report      = array(
			'source_post_id' => $front,
			'translation_id' => null === $translation ? 0 : (int) $translation->ID,
			'home_url'       => self::language_home( $target_lang ),
			'problems'       => array(),
		);

		if ( null === $translation ) {
			$report['problems'][] = self::problem( 'homepage_not_translated', __( 'The static front page has no translation in the target language, so the language home address has no page to show.', 'wpml-translation-check-pro' ) );
		} elseif ( 'publish' !== $translation->post_status ) {
			$report['problems'][] = self::problem( 'homepage_not_published', __( 'The translated front page is not published, so the language home address answers an error.', 'wpml-translation-check-pro' ) );
		} elseif ( $check_http ) {
			$page = self::fetch_page( $report['home_url'], self::request_timeout( $deadline ) );

			if ( empty( $page['verified'] ) ) {
				$report['problems'][] = self::problem( 'http_not_verified', (string) $page['reason'] );
			} else {
				$report['http_status'] = (int) $page['http_status'];

				if ( $page['http_status'] >= 400 ) {
					$report['problems'][] = self::problem(
						'homepage_http_error',
						sprintf(
							/* translators: 1: HTTP status, 2: address. */
							__( 'The language home page answered HTTP %1$d at %2$s.', 'wpml-translation-check-pro' ),
							(int) $page['http_status'],
							$report['home_url']
						)
					);
				} elseif ( '' !== $page['html_lang'] && self::language_prefix( $page['html_lang'] ) !== $locale_prefix ) {
					$report['problems'][] = self::problem( 'page_language_mismatch', __( 'The language home page does not declare the target language.', 'wpml-translation-check-pro' ) );
				}
			}
		}

		$report['ok'] = array() === $report['problems'];

		return $report;
	}

	/**
	 * Checks that a menu has a target-language copy that carries every item of the source menu.
	 *
	 * WPML hides a menu item whose page has no translation when the menu is read
	 * in the target language, so a translated menu never shows an item pointing
	 * at the source language. The failure that can happen is an item that is
	 * silently missing, so each source item is matched to its translation.
	 *
	 * @since 1.6.0
	 * @param int    $menu_id     Source menu ID.
	 * @param string $target_lang Target language code.
	 * @return array<string, mixed> Report.
	 */
	private static function verify_menu( $menu_id, $target_lang ) {
		$report = array(
			'source_menu_id'     => $menu_id,
			'translated_menu_id' => 0,
			'items_checked'      => 0,
			'items_missing'      => 0,
			'custom_links'       => 0,
			'problems'           => array(),
		);

		$translated = apply_filters( 'wpml_object_id', $menu_id, 'nav_menu', false, $target_lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( ! $translated ) {
			$report['problems'][] = self::problem( 'menu_not_translated', __( 'The menu has no copy for the target language yet. An administrator must run WPML > Translations > Menus (older WPML: WPML > WP Menus Sync).', 'wpml-translation-check-pro' ) );
			$report['ok']         = false;

			return $report;
		}

		$report['translated_menu_id'] = (int) $translated;

		// Objects the translated menu shows, whatever label they were given.
		$shown = array();

		foreach ( self::menu_items_in( (int) $translated, $target_lang ) as $item ) {
			if ( 'post_type' === $item->type ) {
				$shown[ (int) $item->object_id ] = true;
			}
		}

		foreach ( self::menu_items_in( $menu_id, Language_Service::get_default_code() ) as $item ) {
			if ( 'custom' === $item->type ) {
				++$report['custom_links'];

				continue;
			}

			if ( 'post_type' !== $item->type ) {
				continue;
			}

			++$report['items_checked'];

			$post = get_post( (int) $item->object_id );
			$copy = null === $post ? null : self::find_translation( (int) $post->ID, (string) $post->post_type, $target_lang );

			if ( null === $copy ) {
				++$report['items_missing'];
				$report['problems'][] = self::problem(
					'menu_item_page_untranslated',
					sprintf(
						/* translators: 1: menu item label, 2: post ID. */
						__( 'Menu item "%1$s" points to post %2$d, which has no translation, so WPML leaves the item out of the target menu.', 'wpml-translation-check-pro' ),
						(string) $item->title,
						(int) $item->object_id
					)
				);
			} elseif ( 'publish' !== $copy->post_status ) {
				$report['problems'][] = self::problem(
					'menu_points_to_unpublished',
					sprintf(
						/* translators: 1: menu item label, 2: post ID. */
						__( 'Menu item "%1$s" points to post %2$d, whose translation is not published.', 'wpml-translation-check-pro' ),
						(string) $item->title,
						(int) $item->object_id
					)
				);
			} elseif ( ! isset( $shown[ (int) $copy->ID ] ) ) {
				++$report['items_missing'];
				$report['problems'][] = self::problem(
					'menu_item_missing',
					sprintf(
						/* translators: 1: menu item label, 2: post ID. */
						__( 'The translated page of menu item "%1$s" (post %2$d) is not in the target menu. An administrator must run WPML > Translations > Menus (older WPML: WPML > WP Menus Sync) again.', 'wpml-translation-check-pro' ),
						(string) $item->title,
						(int) $item->object_id
					)
				);
			}
		}

		$report['ok'] = array() === $report['problems'];

		return $report;
	}

	/**
	 * Requests a public page and reads its status and language.
	 *
	 * @since 1.6.0
	 * @param string $url     Address to request.
	 * @param int    $timeout Seconds to wait.
	 * @return array<string, mixed> Report; "verified" is false with a "reason" when the page could not be requested.
	 */
	private static function fetch_page( $url, $timeout ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$own  = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		if ( '' === $url || ! is_string( $host ) || ! is_string( $own ) || 0 !== strcasecmp( $host, $own ) ) {
			return array(
				'verified' => false,
				'reason'   => __( 'This post has no public address on this site.', 'wpml-translation-check-pro' ),
			);
		}

		$args = array(
			'timeout'     => $timeout,
			'redirection' => 5,
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
			'user-agent'  => 'AutoMLP-Verify/1.0; ' . home_url( '/' ),
			'headers'     => array(
				'Cache-Control' => 'no-cache',
				'Pragma'        => 'no-cache',
			),
		);

		// A request can hang when the server has few PHP workers, so give up early once and try again.
		$args['timeout'] = min( $timeout, 8 );
		$response        = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) && $timeout > 8 ) {
			$args['timeout'] = $timeout - 8;
			$response        = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'verified' => false,
				'reason'   => sprintf(
					/* translators: %s: error message. */
					__( 'The page could not be requested (%s). Check it in a browser.', 'wpml-translation-check-pro' ),
					$response->get_error_message()
				),
			);
		}

		$body  = (string) wp_remote_retrieve_body( $response );
		$final = $url;

		if ( isset( $response['http_response'] ) && is_object( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ) {
			$object = $response['http_response']->get_response_object();

			if ( is_object( $object ) && isset( $object->url ) && is_string( $object->url ) && '' !== $object->url ) {
				$final = $object->url;
			}
		}

		$html_lang = '';

		if ( 1 === preg_match( '/<html[^>]*\blang=["\']([^"\']+)["\']/i', $body, $match ) ) {
			$html_lang = (string) $match[1];
		}

		return array(
			'verified'    => true,
			'http_status' => (int) wp_remote_retrieve_response_code( $response ),
			'final_url'   => $final,
			'html_lang'   => $html_lang,
		);
	}

	/**
	 * Seconds one request may take, from what is left of the call's time budget.
	 *
	 * @since 1.6.0
	 * @param float $deadline Microtime by which the call should finish.
	 * @return int Timeout in seconds, between 5 and 20.
	 */
	private static function request_timeout( $deadline ) {
		return (int) max( 5, min( 20, floor( $deadline - microtime( true ) ) ) );
	}

	/**
	 * The public address of a post, read in the language the post belongs to.
	 *
	 * WPML builds a permalink from the language the request is in, so asking
	 * for a translation's address while the request is in the default language
	 * returns the address of the original page. Without this, a check would
	 * request the source page and report it as the translation.
	 *
	 * @since 1.6.0
	 * @param int    $post_id  Post ID.
	 * @param string $language Language the post belongs to.
	 * @return string Address.
	 */
	private static function permalink_in( $post_id, $language ) {
		$previous = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		do_action( 'wpml_switch_language', $language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		try {
			$url = (string) get_permalink( (int) $post_id );
		} finally {
			do_action( 'wpml_switch_language', $previous ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}

		return $url;
	}

	/**
	 * The language home address.
	 *
	 * @since 1.6.0
	 * @param string $language Language code.
	 * @return string Address.
	 */
	private static function language_home( $language ) {
		return (string) apply_filters( 'wpml_permalink', home_url( '/' ), $language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * The locale WPML gives a language code.
	 *
	 * @since 1.6.0
	 * @param string $code Language code.
	 * @return string Locale such as "pt_BR", or '' when unknown.
	 */
	private static function locale_of( $code ) {
		foreach ( Language_Service::get_all() as $language ) {
			if ( isset( $language['code'], $language['locale'] ) && $language['code'] === $code ) {
				return (string) $language['locale'];
			}
		}

		return '';
	}

	/**
	 * A problem entry.
	 *
	 * @since 1.6.0
	 * @param string $code    Machine-readable code.
	 * @param string $message Plain explanation.
	 * @return array{code: string, message: string} Problem.
	 */
	private static function problem( $code, $message ) {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * The language part of a locale, so "pt_BR" and "pt-BR" compare equal.
	 *
	 * @since 1.6.0
	 * @param string $locale Locale or HTML lang value.
	 * @return string Lower-case language code.
	 */
	private static function language_prefix( $locale ) {
		$parts = explode( '-', strtolower( str_replace( '_', '-', trim( $locale ) ) ) );

		return (string) $parts[0];
	}

	/**
	 * What to do about a problem code.
	 *
	 * @since 1.6.0
	 * @param string $code Problem code.
	 * @return string Advice.
	 */
	private static function fix_hint( $code ) {
		switch ( $code ) {
			case 'no_translation':
			case 'homepage_not_translated':
				return __( 'Translate the missing post: get-content, then translate-post with post_status "publish". Then verify again.', 'wpml-translation-check-pro' );
			case 'not_published':
			case 'homepage_not_published':
			case 'menu_points_to_unpublished':
				return __( 'Publish the translation: translate-post with the same post_id, the target language and post_status "publish" (nothing else is needed). Then verify again.', 'wpml-translation-check-pro' );
			case 'source_no_language':
				return __( 'Give the source post a language with manage-translation-links (action "set_language"). If its post type is not translatable, a site administrator must switch that on under WPML > Settings > Post Types Translation. Then translate it.', 'wpml-translation-check-pro' );
			case 'wrong_language':
				return __( 'Check the language assignment and links with get-post-translations and manage-translation-links.', 'wpml-translation-check-pro' );
			case 'http_error':
			case 'homepage_http_error':
				return __( 'The page answers an error although it is published. Open final_url, ask the user to save Settings > Permalinks to refresh rewrite rules and clear any page cache, then verify again.', 'wpml-translation-check-pro' );
			case 'http_not_verified':
				return __( 'The page could not be requested from the server. Tell the user it needs a check in a browser, and do not call it verified.', 'wpml-translation-check-pro' );
			case 'page_language_mismatch':
				return __( 'The page loads but not in the target language. Check the language URL format in get-translation-settings and that the page is linked to the right language, then verify again.', 'wpml-translation-check-pro' );
			case 'menu_not_translated':
			case 'menu_item_missing':
			case 'menu_item_page_untranslated':
				return __( 'Translate the missing menu pages, then ask the user (an administrator) to run WPML > Translations > Menus (older WPML: WPML > WP Menus Sync), and verify again.', 'wpml-translation-check-pro' );
			default:
				if ( 0 === strpos( $code, 'audit_' ) ) {
					return __( 'Run audit-translation-integrity on the translated post for details, repair what it names, then verify again.', 'wpml-translation-check-pro' );
				}

				return __( 'Fix the first problem listed, then verify again.', 'wpml-translation-check-pro' );
		}
	}
}
