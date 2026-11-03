<?php
/**
 * Static front page and posts page, per language.
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
 * Reads the static pages and links a translated page as a language's front page.
 *
 * WordPress holds one front page and one posts page. Linguator shows, in each
 * language, the translation of that page in the same translation group, so
 * setting a language's front page means putting a page into that group.
 *
 * @since 1.3.0
 */
final class Static_Pages_Service {

	/**
	 * Static page types and the WordPress option behind each.
	 *
	 * @since 1.3.0
	 * @return array<string, string> Type => option name.
	 */
	public static function types() {
		return array(
			'front_page' => 'page_on_front',
			'posts_page' => 'page_for_posts',
		);
	}

	/**
	 * Raw ID stored in a WordPress reading option, without Linguator's per-language swap.
	 *
	 * @since 1.3.0
	 * @param string $option "page_on_front" or "page_for_posts".
	 * @return int Page ID, or 0.
	 */
	private static function base_id( $option ) {
		return Linguator_Guard::with_language(
			null,
			static function () use ( $option ) {
				return absint( get_option( $option, 0 ) );
			}
		);
	}

	/**
	 * Static pages and their translation in every language.
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Static pages.
	 */
	public static function get() {
		$show      = (string) get_option( 'show_on_front', 'posts' );
		$languages = (array) linguator_languages_list();
		$result    = array(
			'show_on_front' => $show,
			'front_page'    => null,
			'posts_page'    => null,
			'languages'     => array(),
			'missing'       => array(),
		);

		foreach ( self::types() as $type => $option ) {
			$base = self::base_id( $option );

			if ( $base > 0 && ( 'page' === $show || 'posts_page' === $type ) ) {
				$result[ $type ] = self::describe( $base );
			}
		}

		foreach ( $languages as $slug ) {
			$row = array( 'language' => (string) $slug );

			foreach ( self::types() as $type => $option ) {
				$base = self::base_id( $option );
				$id   = $base > 0 ? self::translation_of( $base, (string) $slug ) : 0;

				$row[ $type ] = $id > 0 ? self::describe( $id ) : null;

				if ( $base > 0 && 0 === $id && ( 'posts_page' === $type || 'page' === $show ) ) {
					$result['missing'][] = array(
						'language' => (string) $slug,
						'type'     => $type,
					);
				}
			}

			$result['languages'][] = $row;
		}

		$result['notice'] = 'page' === $show
			? __( 'The front page and posts page are set once in Settings > Reading; each language shows its own translation of them. "missing" lists languages that have no translated page yet: translate the page (translate-post) or link an existing one with set-language-front-page.', 'linguator-pro' )
			: __( 'The site shows the latest posts on its front page, so no static front page applies. A posts page may still be set.', 'linguator-pro' );

		return $result;
	}

	/**
	 * Links a page as the front page or posts page of a language.
	 *
	 * @since 1.3.0
	 * @param string $language Language slug.
	 * @param int    $page_id  Page ID.
	 * @param string $type     "front_page" or "posts_page".
	 * @param bool   $dry_run  Whether to change nothing.
	 * @return array<string, mixed>|WP_Error Report, or an error.
	 */
	public static function set( $language, $page_id, $type, $dry_run ) {
		$types = self::types();

		if ( ! isset( $types[ $type ] ) ) {
			return new WP_Error( 'lmat_invalid_type', __( 'type must be "front_page" or "posts_page".', 'linguator-pro' ) );
		}

		$lang = Linguator_Guard::language( $language );

		if ( is_wp_error( $lang ) ) {
			return $lang;
		}

		$model = Linguator_Guard::model();
		$slug  = (string) $lang->slug;
		$base  = self::base_id( $types[ $type ] );

		if ( 'front_page' === $type && 'page' !== get_option( 'show_on_front' ) ) {
			return new WP_Error( 'lmat_no_static_front_page', __( 'The site does not use a static front page (Settings > Reading shows the latest posts). Set "Your homepage displays" to a static page there first; that choice is a core WordPress setting these tools do not change.', 'linguator-pro' ) );
		}

		if ( $base < 1 || 'publish' !== get_post_status( $base ) ) {
			return new WP_Error(
				'lmat_no_base_page',
				'front_page' === $type
					? __( 'No published static front page is set in Settings > Reading. Choose one there first.', 'linguator-pro' )
					: __( 'No posts page is set in Settings > Reading. Choose one there first.', 'linguator-pro' )
			);
		}

		$page = $page_id > 0 ? get_post( $page_id ) : null;

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'lmat_invalid_page', __( 'page_id must be the ID of an existing page (not a post or other content type).', 'linguator-pro' ) );
		}

		if ( 'publish' !== $page->post_status ) {
			return new WP_Error(
				'lmat_page_not_published',
				sprintf(
					/* translators: %s: post status. */
					__( 'The page is "%s", not published, so visitors would get an error page. Publish it first.', 'linguator-pro' ),
					$page->post_status
				)
			);
		}

		if ( ! current_user_can( 'edit_post', $page->ID ) ) {
			return new WP_Error( 'lmat_forbidden', __( 'You are not allowed to change this page.', 'linguator-pro' ) );
		}

		$page_language = (string) linguator_get_post_language( $page->ID );

		if ( $page_language !== $slug ) {
			return new WP_Error(
				'lmat_language_mismatch',
				sprintf(
					/* translators: 1: page language or "no language", 2: language slug. */
					__( 'The page is in %1$s, not "%2$s". Use a page written in the language you are setting, or give it that language with manage-translation-links.', 'linguator-pro' ),
					'' === $page_language ? __( 'no language', 'linguator-pro' ) : '"' . $page_language . '"',
					$slug
				)
			);
		}

		$group       = (array) $model->post->get_raw_translations( $base );
		$base_lang   = (string) linguator_get_post_language( $base );
		$group[ $base_lang ] = $base;
		$current     = isset( $group[ $slug ] ) ? (int) $group[ $slug ] : 0;

		$report = array(
			'dry_run'  => (bool) $dry_run,
			'changed'  => false,
			'type'     => $type,
			'language' => $slug,
			'base'     => self::describe( $base ),
			'before'   => $current > 0 ? self::describe( $current ) : null,
			'after'    => self::describe( $page->ID ),
			'warnings' => array(),
		);

		if ( $current === (int) $page->ID ) {
			$report['notice'] = __( 'This page is already the one shown in this language; nothing to change.', 'linguator-pro' );

			return $report;
		}

		if ( $base_lang === $slug ) {
			return new WP_Error(
				'lmat_is_base_language',
				sprintf(
					/* translators: %s: language slug. */
					__( 'The page chosen in Settings > Reading is in "%s", so that language already shows it and it can only be changed there (a core WordPress setting).', 'linguator-pro' ),
					$slug
				)
			);
		}

		$own = array_diff( (array) $model->post->get_raw_translations( $page->ID ), array( $page->ID ) );

		if ( ! empty( $own ) ) {
			return new WP_Error(
				'lmat_page_has_translations',
				sprintf(
					/* translators: %s: translation page IDs. */
					__( 'This page already belongs to another translation group (pages %s). Unlink it first with manage-translation-links (action "unlink"), then set it here.', 'linguator-pro' ),
					implode( ', ', array_map( 'intval', $own ) )
				)
			);
		}

		if ( $current > 0 ) {
			$report['warnings'][] = sprintf(
				/* translators: %d: page ID. */
				__( 'Page %d was linked in this language and is unlinked from the group; it is not deleted.', 'linguator-pro' ),
				$current
			);
		}

		if ( $dry_run ) {
			return $report;
		}

		$group[ $slug ] = (int) $page->ID;
		$group          = array_filter( array_map( 'absint', $group ) );

		linguator_save_post_translations( $group );
		$model->clean_languages_cache();

		$saved = self::translation_of( $base, $slug );

		$report['dry_run'] = false;
		$report['changed'] = $saved === (int) $page->ID;
		$report['saved']   = $saved > 0 ? self::describe( $saved ) : null;

		return $report;
	}

	/**
	 * ID of the translation of a page in a language.
	 *
	 * @since 1.3.0
	 * @param int    $base_id Page ID.
	 * @param string $slug    Language slug.
	 * @return int Page ID, or 0.
	 */
	private static function translation_of( $base_id, $slug ) {
		$model = Linguator_Guard::model();

		if ( null === $model ) {
			return 0;
		}

		$id = (int) $model->post->get( $base_id, $slug );

		return $id > 0 && 'trash' !== get_post_status( $id ) ? $id : 0;
	}

	/**
	 * Short description of a page.
	 *
	 * @since 1.3.0
	 * @param int $page_id Page ID.
	 * @return array<string, mixed> Page.
	 */
	private static function describe( $page_id ) {
		$status = get_post_status( $page_id );

		return array(
			'id'        => (int) $page_id,
			'title'     => wp_strip_all_tags( get_the_title( $page_id ) ),
			'status'    => false === $status ? 'missing' : (string) $status,
			'language'  => (string) linguator_get_post_language( $page_id ),
			'url'       => 'publish' === $status ? (string) get_permalink( $page_id ) : '',
			'published' => 'publish' === $status,
		);
	}
}
