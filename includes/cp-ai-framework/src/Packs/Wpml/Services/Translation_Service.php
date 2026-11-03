<?php
/**
 * Translation-group reads for the abilities subsystem.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Services;

use WP_Query;
use WPML_AT_PRO_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads WPML translation groups for posts and terms.
 *
 * Everything here goes through WPML's public filters (wpml_element_trid,
 * wpml_get_element_translations, wpml_element_type) rather than querying
 * icl_translations directly, so WPML stays free to change its schema.
 *
 * @since 1.5.0
 */
final class Translation_Service {

	/**
	 * Post statuses counted as real content throughout this subsystem.
	 *
	 * @since 1.5.0
	 * @var string[]
	 */
	const STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * The translation group for one post.
	 *
	 * @since 1.5.0
	 * @param int $post_id Post to inspect.
	 * @return array<string, mixed>|null Group payload, or null when the post is gone.
	 */
	public static function get_post_translations( $post_id ) {
		$post_id = absint( $post_id );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return null;
		}

		$post_type = (string) $post->post_type;
		$details   = self::get_element_language_details( $post_id );

		/*
		 * WPML leaves source_language_code empty on an original, so asking a
		 * source post for its source language would return nothing. At group
		 * level the question is "what was this authored in", which for an
		 * original is its own language. It also decides is_source on every row,
		 * which must not depend on which member of the group was asked about.
		 */
		$source_language = '';

		if ( null !== $details ) {
			$source_language = $details['is_source']
				? $details['language_code']
				: $details['source_language_code'];
		}

		$rows = array();

		if ( class_exists( 'WPML_AT_PRO_Helper' ) ) {
			$translations = WPML_AT_PRO_Helper::get_post_translations( $post_id, $post_type );

			if ( is_array( $translations ) ) {
				foreach ( $translations as $translation ) {
					$info = WPML_AT_PRO_Helper::extract_translation_info( $translation );

					if ( '' === $info['code'] || 0 === $info['element_id'] ) {
						continue;
					}

					$rows[ $info['code'] ] = self::describe_translation( $info['element_id'], $info['code'], $source_language );
				}
			}
		}

		ksort( $rows );

		$missing = array_values( array_diff( Language_Service::get_codes(), array_keys( $rows ) ) );

		return array(
			'post_id'           => $post_id,
			'post_type'         => $post_type,
			'trid'              => null === $details ? 0 : $details['trid'],
			'source_language'   => $source_language,
			'language'          => null === $details ? '' : $details['language_code'],
			'translations'      => $rows,
			'missing_languages' => $missing,
			'total'             => count( $rows ),
		);
	}

	/**
	 * Normalized WPML language details for one post.
	 *
	 * @since 1.5.0
	 * @param int $post_id Post to inspect.
	 * @return array<string, mixed>|null Details, or null when unresolvable.
	 */
	public static function get_element_language_details( $post_id ) {
		$post_id = absint( $post_id );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return null;
		}

		$post_type    = (string) $post->post_type;
		$element_type = 'post_' . $post_type;

		$trid = apply_filters( 'wpml_element_trid', null, $post_id, $element_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$language = '';
		$source   = '';

		if ( class_exists( 'WPML_AT_PRO_Helper' ) ) {
			$language = (string) WPML_AT_PRO_Helper::get_post_source_language( $post_id, $post_type );
		}

		$details = apply_filters( 'wpml_element_language_details', null, array( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'element_id'   => $post_id,
			'element_type' => $element_type,
		) );

		if ( is_object( $details ) ) {
			$language = isset( $details->language_code ) ? (string) $details->language_code : $language;
			$source   = isset( $details->source_language_code ) ? (string) $details->source_language_code : '';
		}

		return array(
			'post_id'              => $post_id,
			'post_type'            => $post_type,
			'element_type'         => $element_type,
			'post_status'          => (string) $post->post_status,
			'title'                => (string) get_the_title( $post_id ),
			'trid'                 => is_numeric( $trid ) ? (int) $trid : 0,
			'language_code'        => $language,
			'source_language_code' => $source,
			'is_source'            => '' === $source,
		);
	}

	/**
	 * The translation group for one taxonomy term.
	 *
	 * WPML keys taxonomy elements by term_taxonomy_id, not term_id, so the
	 * caller's term_id is resolved through get_term() first.
	 *
	 * @since 1.5.0
	 * @param int    $term_id  Term to inspect.
	 * @param string $taxonomy Taxonomy the term belongs to.
	 * @return array<string, mixed>|null Group payload, or null when the term is gone.
	 */
	public static function get_term_translations( $term_id, $taxonomy ) {
		$term_id  = absint( $term_id );
		$taxonomy = (string) $taxonomy;
		$term     = $term_id > 0 && '' !== $taxonomy ? get_term( $term_id, $taxonomy ) : null;

		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		$element_type = 'tax_' . $taxonomy;
		$ttid         = (int) $term->term_taxonomy_id;

		$trid         = apply_filters( 'wpml_element_trid', null, $ttid, $element_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$translations = apply_filters( 'wpml_get_element_translations', array(), $trid, $element_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$rows = array();

		if ( is_array( $translations ) ) {
			foreach ( $translations as $translation ) {
				$code = is_object( $translation ) && isset( $translation->language_code )
					? (string) $translation->language_code
					: '';
				$tid  = is_object( $translation ) && isset( $translation->term_id )
					? (int) $translation->term_id
					: 0;

				if ( '' === $code || 0 === $tid ) {
					continue;
				}

				$translated = get_term( $tid, $taxonomy );

				$rows[ $code ] = array(
					'term_id'  => $tid,
					'language' => $code,
					'name'     => ( $translated && ! is_wp_error( $translated ) ) ? (string) $translated->name : '',
					'slug'     => ( $translated && ! is_wp_error( $translated ) ) ? (string) $translated->slug : '',
					'count'    => ( $translated && ! is_wp_error( $translated ) ) ? (int) $translated->count : 0,
				);
			}
		}

		ksort( $rows );

		return array(
			'term_id'           => $term_id,
			'taxonomy'          => $taxonomy,
			'name'              => (string) $term->name,
			'trid'              => is_numeric( $trid ) ? (int) $trid : 0,
			'translations'      => $rows,
			'missing_languages' => array_values( array_diff( Language_Service::get_codes(), array_keys( $rows ) ) ),
			'total'             => count( $rows ),
		);
	}

	/**
	 * Per-post-type translation coverage across every active language.
	 *
	 * Counts are per-language totals from WPML's own query filtering, which is
	 * cheap. That is not the same as "translations of the source items": a
	 * target language could hold content that was authored there directly. Use
	 * get-untranslated-content for an item-level answer.
	 *
	 * @since 1.5.0
	 * @param string|null $content_type Post type filter, or null for all.
	 * @return array<string, mixed> Status report.
	 */
	public static function get_status( $content_type = null ) {
		$languages = Language_Service::get_all();
		$source    = Language_Service::get_default_code();

		$post_types = array();

		if ( null !== $content_type && '' !== $content_type ) {
			if ( ! Content_Service::is_post_type_translatable( $content_type ) ) {
				return array(
					'post_types' => array(),
					'notice'     => sprintf(
						/* translators: %s: requested post type. */
						__( 'WPML does not translate "%s", so it has no translation status. Call list-content-types and pass on its notice: a site administrator has to switch the type on under WPML > Settings > Post Types Translation.', 'wpml-translation-check-pro' ),
						(string) $content_type
					),
				);
			}

			$post_types = array( (string) $content_type );
		} else {
			foreach ( get_post_types( array(), 'names' ) as $post_type ) {
				if ( Content_Service::is_post_type_translatable( $post_type ) ) {
					$post_types[] = $post_type;
				}
			}
		}

		$rows = array();

		foreach ( $post_types as $post_type ) {
			$object       = get_post_type_object( $post_type );
			$source_count = self::count_in_language( $post_type, $source );

			$per_language = array();

			foreach ( $languages as $language ) {
				if ( $language['code'] === $source ) {
					continue;
				}

				$count = self::count_in_language( $post_type, $language['code'] );

				$per_language[ $language['code'] ] = array(
					'language'   => $language['code'],
					'count'      => $count,
					'missing'    => max( 0, $source_count - $count ),
					'percentage' => $source_count > 0 ? (int) round( ( min( $count, $source_count ) / $source_count ) * 100 ) : 100,
				);
			}

			$rows[ $post_type ] = array(
				'post_type'    => $post_type,
				'label'        => ( $object && isset( $object->labels->name ) ) ? (string) $object->labels->name : $post_type,
				'source_count' => $source_count,
				'languages'    => $per_language,
			);
		}

		return array(
			'source_language' => $source,
			'statuses'        => self::STATUSES,
			'post_types'      => $rows,
		);
	}

	/**
	 * Counts items of a post type in one language.
	 *
	 * @since 1.5.0
	 * @param string $post_type Post type to count.
	 * @param string $language  Language code to count in.
	 * @return int Item count.
	 */
	private static function count_in_language( $post_type, $language ) {
		if ( '' === (string) $language ) {
			return 0;
		}

		do_action( 'wpml_switch_language', $language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => self::STATUSES,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$found = (int) $query->found_posts;

		do_action( 'wpml_switch_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return $found;
	}

	/**
	 * A compact description of one translation in a group.
	 *
	 * @since 1.5.0
	 * @param int                       $post_id        Translation post ID.
	 * @param string                    $language       Its language code.
	 * @param string                    $source_language The group's original language.
	 * @return array<string, mixed> Translation row.
	 */
	private static function describe_translation( $post_id, $language, $source_language ) {
		$post = get_post( $post_id );

		$row = array(
			'post_id'     => (int) $post_id,
			'language'    => (string) $language,
			'title'       => (string) get_the_title( $post_id ),
			'post_status' => $post ? (string) $post->post_status : '',
			'is_source'   => '' !== (string) $source_language && (string) $language === (string) $source_language,
			'edit_link'   => html_entity_decode( (string) get_edit_post_link( $post_id, 'raw' ) ),
		);

		/*
		 * WPML's own completeness flag. AutoMLP sets it to 10 (ICL_TM_COMPLETE)
		 * on every translation it creates, so a translation sitting at a lower
		 * value was made some other way.
		 */
		if ( function_exists( 'wpml_get_post_status_helper' ) ) {
			$helper = wpml_get_post_status_helper();

			if ( is_object( $helper ) && method_exists( $helper, 'get_status' ) ) {
				$row['wpml_status'] = (int) $helper->get_status( $post_id );
			}
		}

		return $row;
	}
}
