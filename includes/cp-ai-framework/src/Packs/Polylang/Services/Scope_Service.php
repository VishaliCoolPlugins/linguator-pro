<?php
/**
 * Translation scope: what a "translate the site" request covers, and whether it is done.
 *
 * @package AutoPoly
 * @since   1.16.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the checklist for a site translation and checks the finished result.
 *
 * Read-only. It never writes a post, term, menu or option, so it is safe to
 * call as often as an agent likes, and a half-finished job can be resumed by
 * calling it again: the answer is rebuilt from Polylang's own data each time.
 *
 * @since 1.16.0
 */
final class Scope_Service {

	/**
	 * Most source posts verify() accepts in one call.
	 *
	 * Each published page is requested over HTTP, so the cap keeps one call
	 * inside the 60 second tool timeout some MCP clients apply.
	 *
	 * @since 1.16.0
	 * @var int
	 */
	const MAX_VERIFY = 5;

	/**
	 * Most header and footer templates listed in a plan.
	 *
	 * @since 1.16.0
	 * @var int
	 */
	const MAX_TEMPLATES = 20;

	/**
	 * Seconds one verify call may spend requesting pages.
	 *
	 * Some MCP clients stop a tool after 60 seconds. The share left of this
	 * budget caps each page's timeout, so a slow host gets longer than the
	 * default 10 seconds per page without the whole call overrunning.
	 *
	 * @since 1.16.0
	 * @var int
	 */
	const TIME_BUDGET = 50;

	/**
	 * Menu location names that usually hold the header menu.
	 *
	 * @since 1.16.0
	 * @var string
	 */
	const HEADER_LOCATION_PATTERN = '/primary|main|header|menu-1/i';

	/**
	 * Builds the checklist for translating the homepage, header, footer and a menu.
	 *
	 * @since 1.16.0
	 * @param string   $target_lang Target language slug.
	 * @param int      $menu_id     Menu to include, or 0 to use the header menu.
	 * @param string[] $include     Parts to include: homepage, header, footer, menu.
	 * @return array<string, mixed>|WP_Error The plan, or an error.
	 */
	public static function plan( $target_lang, $menu_id = 0, array $include = array() ) {
		if ( ! Polylang_Guard::model_ready() || ! function_exists( 'pll_default_language' ) ) {
			return Polylang_Guard::unavailable_error();
		}

		$target_lang = sanitize_key( (string) $target_lang );
		$default     = (string) pll_default_language( 'slug' );

		if ( null === Language_Service::get_by_slug( $target_lang ) ) {
			return new WP_Error(
				'atfpp_invalid_language',
				sprintf(
					/* translators: %s: language slug. */
					__( 'Target language "%s" was not found.', 'autopoly-ai-translation-for-polylang-pro' ),
					$target_lang
				)
			);
		}

		if ( $target_lang === $default ) {
			return new WP_Error(
				'atfpp_same_language',
				__( 'The target language is the default language, which is the source of the plan. Choose another language.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		$include  = array_values( array_intersect( array( 'homepage', 'header', 'footer', 'menu' ), array_map( 'sanitize_key', $include ) ) );
		$include  = array() === $include ? array( 'homepage', 'header', 'footer', 'menu' ) : $include;
		$blockers = array();
		$items    = array();
		$seen     = array();
		$notes    = array();

		foreach ( self::templates( $target_lang, $default, $include, $blockers ) as $item ) {
			$items[]                        = $item;
			$seen[ $item['source_post_id'] ] = true;
		}

		if ( in_array( 'homepage', $include, true ) ) {
			$front = self::front_page( $default );

			if ( isset( $front['source_post_id'] ) ) {
				$item                           = self::describe_post( 'homepage', (int) $front['source_post_id'], $target_lang );
				$items[]                        = $item;
				$seen[ $item['source_post_id'] ] = true;
			} else {
				$notes[] = __( 'The site shows latest posts on its home page, so there is no homepage page to translate. The language home page lists translated posts.', 'autopoly-ai-translation-for-polylang-pro' );
			}
		}

		$menu_block      = null;
		$available_menus = array();

		if ( in_array( 'menu', $include, true ) ) {
			$menu = self::menu( (int) $menu_id, $target_lang );

			$available_menus = $menu['available'];
			$menu_block      = $menu['menu'];

			if ( null === $menu_block ) {
				$notes[] = __( 'No navigation menu is assigned to a theme location, so no menu pages were listed. Pass menu_id (see available_menus or get-translation-settings) to include one.', 'autopoly-ai-translation-for-polylang-pro' );
			}

			if ( null !== $menu_block && ! $menu_block['menu_sync_enabled'] ) {
				$notes[] = __( 'Menu Sync is switched off in AutoPoly, so sync-menu will refuse to run and no target-language menu can be created with these tools. An administrator can enable it under Languages > AutoPoly > Settings > Menu Sync. Until then, tell the user the menu is not translated. The pages themselves can still be translated.', 'autopoly-ai-translation-for-polylang-pro' );
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

		$remaining = array();
		$done      = 0;
		$manual    = 0;
		$verify    = array();
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

			if ( isset( $item['state'] ) && 'no_language' === $item['state'] ) {
				$no_language[] = (int) $item['source_post_id'];
			}
		}

		if ( array() !== $no_language ) {
			$blockers[] = sprintf(
				/* translators: %s: comma-separated post IDs. */
				__( 'These posts have no language: %s. Give each one a language with manage-translation-links (action "set_language"), then translate it.', 'autopoly-ai-translation-for-polylang-pro' ),
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
				__( 'Source post %d has a translation that is not published. Publish it: translate-post with that source_post_id, the target language and status "publish" (send only the status). Then continue with the other remaining items.', 'autopoly-ai-translation-for-polylang-pro' ),
				(int) $next['source_post_id']
			);
		} elseif ( null !== $next ) {
			$next_action = sprintf(
				/* translators: 1: item role, 2: source post or term ID. */
				__( 'Translate the next item (%1$s, source ID %2$d): call get-content, then translate-post (or translate-term for a term). Do this for every remaining item in order.', 'autopoly-ai-translation-for-polylang-pro' ),
				(string) $next['role'],
				isset( $next['source_post_id'] ) ? (int) $next['source_post_id'] : (int) $next['source_term_id']
			);
		} else {
			$next_action = __( 'Every item has a published translation. That does not prove the pages work: run verify-translations with each batch in verify_batches.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		return array(
			'target_language'  => $target_lang,
			'default_language' => $default,
			'outcome'          => ( array() === $remaining && array() === $blockers ) ? 'ready_to_verify' : 'work_remaining',
			'summary'          => sprintf(
				/* translators: 1: translated count, 2: total count, 3: remaining count. */
				__( '%1$d of %2$d items already have a published translation; %3$d remain. A published translation is not proof that the page works: finish with verify-translations.', 'autopoly-ai-translation-for-polylang-pro' ),
				$done,
				$total,
				count( $remaining )
			),
			'counts'           => array(
				'total'     => $total,
				'translated' => $done,
				'remaining' => count( $remaining ),
				'manual'    => $manual,
			),
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
	 * @since 1.16.0
	 * @param string $target_lang Target language slug.
	 * @param int[]  $source_ids  Source post IDs, at most MAX_VERIFY.
	 * @param bool   $check_http  Whether to request each public page.
	 * @param int    $menu_id     Source menu to check as well, or 0.
	 * @return array<string, mixed>|WP_Error The report, or an error.
	 */
	public static function verify( $target_lang, array $source_ids, $check_http = true, $menu_id = 0 ) {
		if ( ! Polylang_Guard::model_ready() || ! function_exists( 'pll_get_post' ) ) {
			return Polylang_Guard::unavailable_error();
		}

		$target_lang = sanitize_key( (string) $target_lang );
		$language    = Language_Service::get_by_slug( $target_lang );

		if ( null === $language ) {
			return new WP_Error(
				'atfpp_invalid_language',
				sprintf(
					/* translators: %s: language slug. */
					__( 'Target language "%s" was not found.', 'autopoly-ai-translation-for-polylang-pro' ),
					$target_lang
				)
			);
		}

		$source_ids = array_values( array_unique( array_filter( array_map( 'absint', $source_ids ) ) ) );

		if ( array() === $source_ids ) {
			return new WP_Error( 'atfpp_no_posts', __( 'Pass at least one source post ID in source_post_ids.', 'autopoly-ai-translation-for-polylang-pro' ) );
		}

		if ( count( $source_ids ) > self::MAX_VERIFY ) {
			return new WP_Error(
				'atfpp_too_many_posts',
				sprintf(
					/* translators: %d: maximum number of posts. */
					__( 'Verify at most %d posts per call, because each page is requested over HTTP. Split the list and call again.', 'autopoly-ai-translation-for-polylang-pro' ),
					self::MAX_VERIFY
				)
			);
		}

		$locale_prefix = self::language_prefix( (string) $language->locale );
		$results       = array();
		$problems      = array();
		$deadline      = microtime( true ) + self::TIME_BUDGET;

		foreach ( $source_ids as $source_id ) {
			$result = self::verify_post( $source_id, $target_lang, $locale_prefix, (bool) $check_http, $deadline );

			foreach ( $result['problems'] as $problem ) {
				$problems[] = array_merge( array( 'source_post_id' => $source_id ), $problem );
			}

			$results[] = $result;
		}

		// Leave no "site cannot reach itself" flag behind: this call asked for the check explicitly, and a stale flag would skip the next write's check.
		delete_transient( Public_Page_Verifier::FAILED_TRANSIENT );

		$homepage = self::verify_homepage( $target_lang );

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
					__( 'All %d checked posts have a published translation in the target language and their public pages answered successfully. This covers the items you passed only; confirm the plan has no other remaining items.', 'autopoly-ai-translation-for-polylang-pro' ),
					count( $results )
				)
				: sprintf(
					/* translators: %d: number of problems. */
					__( '%d problem(s) found. Do not report this job as finished until each one is fixed or explained to the user.', 'autopoly-ai-translation-for-polylang-pro' ),
					count( $problems )
				),
			'http_checked'      => (bool) $check_http,
			'items'             => $results,
			'failed_source_ids' => $failed,
			'homepage'          => $homepage,
			'menu'              => $menu_report,
			'problems'          => $problems,
			'next_action'       => $ok
				? __( 'Report the verified URLs to the user, and list anything that was intentionally left untranslated.', 'autopoly-ai-translation-for-polylang-pro' )
				: self::fix_hint( (string) $problems[0]['code'] ),
		);
	}

	/**
	 * Header and footer templates in the default language.
	 *
	 * @since 1.16.0
	 * @param string   $target_lang Target language slug.
	 * @param string   $default     Default language slug.
	 * @param string[] $include     Parts requested.
	 * @param string[] $blockers    Collects problems that stop the work.
	 * @return array<int, array<string, mixed>> One entry per template.
	 */
	private static function templates( $target_lang, $default, array $include, array &$blockers ) {
		$types = array_values( array_intersect( array( 'header', 'footer' ), $include ) );

		if ( array() === $types || ! Template_Service::elementor_active() ) {
			return array();
		}

		if ( function_exists( 'pll_is_translated_post_type' ) && ! pll_is_translated_post_type( Template_Service::TEMPLATE_POST_TYPE ) ) {
			$blockers[] = Polylang_Guard::not_translated_message( 'post', Template_Service::TEMPLATE_POST_TYPE );
		}

		$ids = get_posts(
			array(
				'post_type'        => Template_Service::TEMPLATE_POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => self::MAX_TEMPLATES,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'lang'             => '', // All languages: a template with no language must still be found.
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
			$language = (string) pll_get_post_language( (int) $id, 'slug' );

			// A template already in another language is a translation, not a source.
			if ( '' !== $language && $language !== $default ) {
				continue;
			}

			$type    = (string) get_post_meta( (int) $id, '_elementor_template_type', true );
			$items[] = self::describe_post( 'template_' . $type, (int) $id, $target_lang, array( 'template_type' => $type ) );
		}

		if ( array() !== $items && ! Translation_Service::is_elementor_connector_active() ) {
			$blockers[] = __( 'Connect Polylang for Elementor is not active. A translated header or footer will not be shown on target-language pages without it. This plugin cannot install it: ask the user to install and activate it.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		return $items;
	}

	/**
	 * The static front page in the default language.
	 *
	 * @since 1.16.0
	 * @param string $default Default language slug.
	 * @return array<string, mixed> source_post_id when a static page is the home page, otherwise empty.
	 */
	private static function front_page( $default ) {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return array();
		}

		$id = (int) get_option( 'page_on_front' );

		if ( $id <= 0 ) {
			return array();
		}

		// The setting may point at any translation of the page; the source is the default-language one.
		$source = (int) pll_get_post( $id, $default );

		return array( 'source_post_id' => $source > 0 ? $source : $id );
	}

	/**
	 * A navigation menu and the content its items point to.
	 *
	 * @since 1.16.0
	 * @param int    $menu_id     Menu to use, or 0 to pick the header menu.
	 * @param string $target_lang Target language slug.
	 * @return array{menu: array<string, mixed>|null, available: array<int, array<string, mixed>>, entries: array<int, array<string, mixed>>} Menu data.
	 */
	private static function menu( $menu_id, $target_lang ) {
		$available = array();
		$chosen    = 0;
		$location  = '';

		foreach ( (array) get_nav_menu_locations() as $name => $id ) {
			// Polylang keeps other languages' menus under "location___lang".
			if ( (int) $id <= 0 || false !== strpos( (string) $name, '___' ) ) {
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
		$items  = $object ? wp_get_nav_menu_items( $chosen ) : array();

		if ( ! $object || ! is_array( $items ) ) {
			return array(
				'menu'      => null,
				'available' => $available,
				'entries'   => array(),
			);
		}

		$parents = array();

		foreach ( $items as $item ) {
			$parents[ (int) $item->ID ] = (int) $item->menu_item_parent;
		}

		$entries = array();

		foreach ( $items as $item ) {
			$depth   = 0;
			$cursor  = (int) $item->ID;
			$guard   = 0;

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
				$translated = function_exists( 'pll_get_term' ) ? (int) pll_get_term( $term_id, $target_lang ) : 0;
				$entries[]  = array_merge(
					array(
						'role'                => 'menu_term',
						'source_term_id'      => $term_id,
						'taxonomy'            => (string) $item->object,
						'title'               => (string) $item->title,
						'translation_term_id' => $translated,
						'state'               => $translated > 0 ? 'translated' : 'missing',
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
						'note'  => __( 'A custom link is not a translatable object. sync-menu repoints it only when it is a page on this site; check it in the dry run.', 'autopoly-ai-translation-for-polylang-pro' ),
					),
					$extra
				);
			}
		}

		$translated_menu = Menu_Service::find_translation( $chosen, $target_lang );

		return array(
			'menu'      => array(
				'menu_id'           => $chosen,
				'name'              => (string) $object->name,
				'location'          => $location,
				'item_count'        => count( $items ),
				'menu_sync_enabled' => Menu_Service::is_enabled(),
				'translated_menu'   => $translated_menu,
			),
			'available' => $available,
			'entries'   => $entries,
		);
	}

	/**
	 * Describes one source post and where its translation stands.
	 *
	 * @since 1.16.0
	 * @param string               $role        Why the post is in the plan.
	 * @param int                  $post_id     Source post ID.
	 * @param string               $target_lang Target language slug.
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

		$source_lang    = (string) pll_get_post_language( $post_id, 'slug' );
		$translation_id = (int) pll_get_post( $post_id, $target_lang );
		$translation    = $translation_id > 0 ? get_post( $translation_id ) : null;

		if ( null === $translation ) {
			$translation_id = 0;
		}

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
				'translation_id'     => $translation_id,
				'translation_status' => null !== $translation ? (string) $translation->post_status : '',
				'state'              => $state,
			),
			$extra
		);
	}

	/**
	 * The fixed order of work, tailored to whether a menu is involved.
	 *
	 * @since 1.16.0
	 * @param array<string, mixed>|null $menu Menu block, or null.
	 * @return string[] Ordered steps.
	 */
	private static function steps( $menu ) {
		$steps = array(
			__( '1. Resolve every entry in blockers first.', 'autopoly-ai-translation-for-polylang-pro' ),
			__( '2. For each header or footer template: preflight-template, get-content, then translate-post with ALL builder strings in one call.', 'autopoly-ai-translation-for-polylang-pro' ),
			__( '3. Translate the homepage and each menu page with get-content then translate-post. Read outcome, public_page and links_unresolved on every result.', 'autopoly-ai-translation-for-polylang-pro' ),
			__( '4. Translate menu_term items with translate-term.', 'autopoly-ai-translation-for-polylang-pro' ),
		);

		if ( null !== $menu ) {
			$steps[] = __( '5. Only after every menu page is translated and published: sync-menu with dry_run true, review it, then dry_run false. If a header Nav Menu widget should use the new menu, call set-template-menu.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		$steps[] = __( 'Last: run verify-translations for each batch in verify_batches (pass menu_id when a menu was synced). Report only what it confirms.', 'autopoly-ai-translation-for-polylang-pro' );

		return $steps;
	}

	/**
	 * Checks one source post's translation.
	 *
	 * @since 1.16.0
	 * @param int    $source_id     Source post ID.
	 * @param string $target_lang   Target language slug.
	 * @param string $locale_prefix Language part of the target locale, such as "fr".
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
			$result['problems'][] = self::problem( 'source_not_found', __( 'The source post was not found.', 'autopoly-ai-translation-for-polylang-pro' ) );

			return $result;
		}

		if ( '' === (string) pll_get_post_language( $source_id, 'slug' ) ) {
			$result['problems'][] = self::problem( 'source_no_language', __( 'The source post has no language, so it cannot have a translation yet.', 'autopoly-ai-translation-for-polylang-pro' ) );

			return $result;
		}

		$translation_id = (int) pll_get_post( $source_id, $target_lang );
		$translation    = $translation_id > 0 ? get_post( $translation_id ) : null;

		if ( null === $translation ) {
			$result['problems'][] = self::problem( 'no_translation', __( 'No translation exists in the target language.', 'autopoly-ai-translation-for-polylang-pro' ) );

			return $result;
		}

		$result['translation_id'] = $translation_id;
		$result['status']         = (string) $translation->post_status;

		if ( (string) pll_get_post_language( $translation_id, 'slug' ) !== $target_lang ) {
			$result['problems'][] = self::problem( 'wrong_language', __( 'The linked translation is not assigned to the target language.', 'autopoly-ai-translation-for-polylang-pro' ) );
		}

		if ( 'publish' !== $translation->post_status ) {
			$result['problems'][] = self::problem(
				'not_published',
				sprintf(
					/* translators: %s: post status. */
					__( 'The translation is "%s", so visitors cannot see it.', 'autopoly-ai-translation-for-polylang-pro' ),
					$translation->post_status
				)
			);
		}

		if ( Template_Service::TEMPLATE_POST_TYPE === $source->post_type && ! Translation_Service::is_elementor_connector_active() ) {
			$result['problems'][] = self::problem( 'connector_missing', __( 'Connect Polylang for Elementor is not active, so this translated template is not shown on target-language pages.', 'autopoly-ai-translation-for-polylang-pro' ) );
		}

		$source_title = trim( (string) $source->post_title );

		if ( strlen( $source_title ) > 3 && 0 === strcasecmp( $source_title, trim( (string) $translation->post_title ) ) ) {
			$result['warnings'][] = __( 'The translated title is identical to the source title. That is fine for a brand name; otherwise the title may not have been translated.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		if ( $check_http && 'publish' === $translation->post_status ) {
			// The verifier skips every request for ten minutes after one failure; one slow page must not fail the rest.
			delete_transient( Public_Page_Verifier::FAILED_TRANSIENT );

			$timeout = (int) max( 5, min( 20, floor( $deadline - microtime( true ) ) ) );
			$page    = Public_Page_Verifier::verify( $translation_id, $target_lang, array(), '', $timeout );

			if ( empty( $page['verified'] ) ) {
				$result['problems'][] = self::problem(
					'http_not_verified',
					isset( $page['reason'] ) ? (string) $page['reason'] : __( 'The public page could not be checked.', 'autopoly-ai-translation-for-polylang-pro' )
				);
			} else {
				$result['http_status'] = (int) $page['http_status'];
				$result['checked_url'] = (string) $page['checked_url'];
				$result['final_url']   = (string) $page['final_url'];

				if ( $page['http_status'] >= 400 ) {
					$result['problems'][] = self::problem(
						'http_error',
						sprintf(
							/* translators: 1: HTTP status, 2: address. */
							__( 'The public page answered HTTP %1$d at %2$s.', 'autopoly-ai-translation-for-polylang-pro' ),
							(int) $page['http_status'],
							(string) $page['final_url']
						)
					);
				} elseif ( ! empty( $page['html_lang'] ) && self::language_prefix( (string) $page['html_lang'] ) !== $locale_prefix ) {
					$result['problems'][] = self::problem(
						'page_language_mismatch',
						sprintf(
							/* translators: 1: language the page declares, 2: expected language. */
							__( 'The page declares language "%1$s" but "%2$s" was expected.', 'autopoly-ai-translation-for-polylang-pro' ),
							(string) $page['html_lang'],
							$locale_prefix
						)
					);
				}

				if ( Template_Service::TEMPLATE_POST_TYPE === $source->post_type && ! empty( $page['nav_labels'] ) ) {
					$result['page_nav_labels'] = array_slice( (array) $page['nav_labels'], 0, 15 );
				}
			}
		}

		$result['ok'] = array() === $result['problems'];

		return $result;
	}

	/**
	 * Checks the static front page mapping for the target language.
	 *
	 * @since 1.16.0
	 * @param string $target_lang Target language slug.
	 * @return array<string, mixed>|null Report, or null when the site shows latest posts.
	 */
	private static function verify_homepage( $target_lang ) {
		if ( 'page' !== get_option( 'show_on_front' ) || (int) get_option( 'page_on_front' ) <= 0 ) {
			return null;
		}

		$front       = (int) get_option( 'page_on_front' );
		$translation = (int) pll_get_post( $front, $target_lang );
		$post        = $translation > 0 ? get_post( $translation ) : null;
		$report      = array(
			'source_post_id' => $front,
			'translation_id' => null !== $post ? $translation : 0,
			'home_url'       => function_exists( 'pll_home_url' ) ? (string) pll_home_url( $target_lang ) : '',
			'problems'       => array(),
		);

		if ( null === $post ) {
			$report['problems'][] = self::problem( 'homepage_not_translated', __( 'The static front page has no translation in the target language, so the language home address has no page to show.', 'autopoly-ai-translation-for-polylang-pro' ) );
		} elseif ( 'publish' !== $post->post_status ) {
			$report['problems'][] = self::problem( 'homepage_not_published', __( 'The translated front page is not published.', 'autopoly-ai-translation-for-polylang-pro' ) );
		}

		$report['ok'] = array() === $report['problems'];

		return $report;
	}

	/**
	 * Checks that a menu has a target-language copy whose items point at translations.
	 *
	 * @since 1.16.0
	 * @param int    $menu_id     Source menu ID.
	 * @param string $target_lang Target language slug.
	 * @return array<string, mixed> Report.
	 */
	private static function verify_menu( $menu_id, $target_lang ) {
		$report = array(
			'source_menu_id'     => $menu_id,
			'translated_menu_id' => 0,
			'items_checked'      => 0,
			'custom_links'       => 0,
			'problems'           => array(),
		);

		$translated = Menu_Service::find_translation( $menu_id, $target_lang );

		if ( null === $translated ) {
			$report['problems'][] = self::problem( 'menu_not_translated', __( 'The menu has no copy for the target language yet.', 'autopoly-ai-translation-for-polylang-pro' ) );
			$report['ok']         = false;

			return $report;
		}

		$report['translated_menu_id'] = (int) $translated['id'];

		$items = wp_get_nav_menu_items( (int) $translated['id'] );

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( 'post_type' !== $item->type ) {
				if ( 'custom' === $item->type ) {
					++$report['custom_links'];
				}

				continue;
			}

			++$report['items_checked'];

			$object_id = (int) $item->object_id;
			$post      = get_post( $object_id );

			if ( (string) pll_get_post_language( $object_id, 'slug' ) !== $target_lang ) {
				$report['problems'][] = self::problem(
					'menu_points_to_source_language',
					sprintf(
						/* translators: 1: menu item label, 2: post ID. */
						__( 'Menu item "%1$s" points to post %2$d, which is not in the target language.', 'autopoly-ai-translation-for-polylang-pro' ),
						(string) $item->title,
						$object_id
					)
				);
			} elseif ( null === $post || 'publish' !== $post->post_status ) {
				$report['problems'][] = self::problem(
					'menu_points_to_unpublished',
					sprintf(
						/* translators: 1: menu item label, 2: post ID. */
						__( 'Menu item "%1$s" points to post %2$d, which is not published.', 'autopoly-ai-translation-for-polylang-pro' ),
						(string) $item->title,
						$object_id
					)
				);
			}
		}

		$report['ok'] = array() === $report['problems'];

		return $report;
	}

	/**
	 * A problem entry.
	 *
	 * @since 1.16.0
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
	 * The language part of a locale, so "fr_FR" and "fr-FR" compare equal.
	 *
	 * @since 1.16.0
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
	 * @since 1.16.0
	 * @param string $code Problem code.
	 * @return string Advice.
	 */
	private static function fix_hint( $code ) {
		switch ( $code ) {
			case 'no_translation':
			case 'homepage_not_translated':
				return __( 'Translate the missing post: get-content, then translate-post. Then verify again.', 'autopoly-ai-translation-for-polylang-pro' );
			case 'not_published':
			case 'homepage_not_published':
			case 'menu_points_to_unpublished':
				return __( 'Publish the translation with translate-post and status "publish" (send only the status), then verify again.', 'autopoly-ai-translation-for-polylang-pro' );
			case 'source_no_language':
				return __( 'Give the source post a language with manage-translation-links (action "set_language"). If its post type is not translatable, a site administrator must switch that on in Languages > Settings. Then translate it.', 'autopoly-ai-translation-for-polylang-pro' );
			case 'wrong_language':
				return __( 'Check the language assignment and links with manage-translation-links.', 'autopoly-ai-translation-for-polylang-pro' );
			case 'http_error':
				return __( 'The page answers an error. Do not change the slug of a static front page. Use the final_url, check the language home address, and ask the user to save Settings > Permalinks to refresh rewrite rules and clear any page cache. Then verify again.', 'autopoly-ai-translation-for-polylang-pro' );
			case 'http_not_verified':
				return __( 'The page could not be requested from the server. Tell the user it needs a check in a browser, and do not call it verified.', 'autopoly-ai-translation-for-polylang-pro' );
			case 'page_language_mismatch':
				return __( 'The page loads but not in the target language. Check the language home address and that the page is linked to the right language, then verify again.', 'autopoly-ai-translation-for-polylang-pro' );
			case 'connector_missing':
				return __( 'Ask the user to install and activate Connect Polylang for Elementor; these tools cannot do it.', 'autopoly-ai-translation-for-polylang-pro' );
			case 'menu_not_translated':
			case 'menu_points_to_source_language':
				return __( 'Translate the missing menu pages, then run sync-menu (dry run first) and verify again.', 'autopoly-ai-translation-for-polylang-pro' );
			default:
				return __( 'Fix the first problem listed, then verify again.', 'autopoly-ai-translation-for-polylang-pro' );
		}
	}
}
