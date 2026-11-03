<?php
/**
 * Content and translation-coverage reads for the abilities subsystem.
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
 * Answers "what can be translated" and "what still needs translating".
 *
 * Coverage is resolved through Automlp_Wpml_Bulk_Translation_Queue, the same
 * class the bulk-translate REST route uses, so an agent and the admin UI never
 * disagree about which languages are outstanding. A post whose translation
 * exists is reported as done unless WPML flags that translation as needing
 * an update because its source changed.
 *
 * @since 1.5.0
 */
final class Content_Service {

	/**
	 * Default page size for coverage listings.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const DEFAULT_LIMIT = 20;

	/**
	 * Maximum page size for coverage listings.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const MAX_LIMIT = 100;

	/**
	 * Translatable and non-translatable post types and taxonomies.
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> Content-type report.
	 */
	public static function get_content_types() {
		$translatable     = array();
		$not_translatable = array();

		foreach ( get_post_types( array(), 'objects' ) as $post_type ) {
			if ( in_array( $post_type->name, array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset' ), true ) ) {
				continue;
			}

			$row = array(
				'slug'  => $post_type->name,
				'label' => isset( $post_type->labels->name ) ? (string) $post_type->labels->name : $post_type->name,
				'count' => self::count_posts( $post_type->name ),
			);

			if ( self::is_post_type_translatable( $post_type->name ) ) {
				$translatable[] = $row;
			} elseif ( ! empty( $post_type->public ) || ! empty( $post_type->show_ui ) ) {
				$not_translatable[] = $row;
			}
		}

		$tax_translatable     = array();
		$tax_not_translatable = array();

		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			$row = array(
				'slug'  => $taxonomy->name,
				'label' => isset( $taxonomy->labels->name ) ? (string) $taxonomy->labels->name : $taxonomy->name,
			);

			if ( (bool) apply_filters( 'wpml_is_translated_taxonomy', false, $taxonomy->name ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				$tax_translatable[] = $row;
			} elseif ( ! empty( $taxonomy->public ) || ! empty( $taxonomy->show_ui ) ) {
				$tax_not_translatable[] = $row;
			}
		}

		return array(
			'post_types'              => $translatable,
			'taxonomies'              => $tax_translatable,
			'not_translated_post_types' => $not_translatable,
			'not_translated_taxonomies' => $tax_not_translatable,
			'notice'                  => __( 'Anything under not_translated_post_types or not_translated_taxonomies is switched off for translation in WPML, so it has no language and cannot be translated yet. That is a site setting no AutoMLP tool can change: tell the user a site administrator must set the type to "Translatable" under WPML > Settings > Post Types Translation (or Taxonomies Translation) and save. Elementor templates, headers and footers are the ones most often left switched off, so check for them before reporting a site as fully translated.', 'wpml-translation-check-pro' ),
		);
	}

	/**
	 * Content that has no translation yet in a target language.
	 *
	 * @since 1.5.0
	 * @param string      $target_language Language code to check coverage for.
	 * @param string|null $content_type    Post type filter, or null for all.
	 * @param int         $limit           Page size.
	 * @param int         $offset          Items to skip.
	 * @return array<string, mixed> Items, totals and a has_more flag.
	 */
	public static function get_untranslated( $target_language, $content_type = null, $limit = self::DEFAULT_LIMIT, $offset = 0 ) {
		$target_language = (string) $target_language;
		$limit           = max( 1, min( self::MAX_LIMIT, (int) $limit ) );
		$offset          = max( 0, (int) $offset );

		if ( ! Language_Service::is_active( $target_language ) ) {
			return self::inactive_language_result( $target_language );
		}

		$post_types = self::resolve_post_types( $content_type );

		if ( empty( $post_types ) ) {
			return array(
				'items'  => array(),
				'total'  => 0,
				'has_more' => false,
				'notice' => sprintf(
					/* translators: %s: requested post type. */
					__( 'WPML does not translate "%s", so its content has no language and cannot be translated yet. Call list-content-types and pass on its notice: a site administrator has to switch the type on under WPML > Settings > Post Types Translation. Do not report the type as fully translated.', 'wpml-translation-check-pro' ),
					(string) $content_type
				),
			);
		}

		$source_language = Language_Service::get_default_code();

		if ( $target_language === $source_language ) {
			return array(
				'items'  => array(),
				'total'  => 0,
				'has_more' => false,
				'notice' => __( 'That is the site\'s own source language, so there is nothing to translate into it. Pass one of the other codes from list-languages.', 'wpml-translation-check-pro' ),
			);
		}

		$candidates = self::query_source_posts( $post_types, $source_language );

		$items = array();

		foreach ( $candidates as $post_id ) {
			$pending = self::pending_languages( (int) $post_id, array( $target_language ) );

			if ( empty( $pending ) ) {
				continue;
			}

			$items[] = self::describe_post( (int) $post_id );
		}

		$total = count( $items );
		$page  = array_slice( $items, $offset, $limit );

		return array(
			'items'           => $page,
			'total'           => $total,
			'has_more'        => ( $offset + count( $page ) ) < $total,
			'target_language' => $target_language,
			'source_language' => $source_language,
		);
	}

	/**
	 * Target languages still outstanding for one post.
	 *
	 * Reuses the bulk-translate queue rule: a translation that exists but that
	 * WPML flags as needing an update (its source changed) counts as
	 * outstanding again.
	 *
	 * @since 1.5.0
	 * @param int      $post_id          Post to check.
	 * @param string[] $target_languages Candidate language codes.
	 * @return string[] Outstanding language codes.
	 */
	public static function pending_languages( $post_id, array $target_languages ) {
		$class = '\AUTOMLP_PRO_WPML\Includes\Wpml\Automlp_Wpml_Bulk_Translation_Queue';

		if ( ! class_exists( $class ) ) {
			return array();
		}

		$post_id      = absint( $post_id );
		$element_type = apply_filters( 'wpml_element_type', get_post_type( $post_id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$trid         = apply_filters( 'wpml_element_trid', null, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return $class::pending_target_languages(
			$post_id,
			array_values( $target_languages ),
			is_array( $translations ) ? $translations : array()
		);
	}

	/**
	 * Posts of a type in one explicit language.
	 *
	 * The language is switched explicitly rather than inherited from the
	 * request, because WPML filters every query by the ambient language and an
	 * ability has no meaningful ambient language of its own.
	 *
	 * @since 1.5.0
	 * @param string      $language     Language code to list in.
	 * @param string|null $content_type Post type, or null for every translatable type.
	 * @param int         $limit        Page size.
	 * @param int         $offset       Items to skip.
	 * @param string      $search       Optional title/content search.
	 * @return array<string, mixed> Items, total and a has_more flag.
	 */
	public static function list_posts( $language, $content_type = null, $limit = self::DEFAULT_LIMIT, $offset = 0, $search = '' ) {
		$language = (string) $language;
		$limit    = max( 1, min( self::MAX_LIMIT, (int) $limit ) );
		$offset   = max( 0, (int) $offset );

		if ( ! Language_Service::is_active( $language ) ) {
			return self::inactive_language_result( $language );
		}

		$post_types = self::resolve_post_types( $content_type );

		if ( empty( $post_types ) ) {
			return array(
				'items'    => array(),
				'total'    => 0,
				'has_more' => false,
				'notice'   => sprintf(
					/* translators: %s: requested post type. */
					__( 'WPML does not translate "%s", so its content has no language and cannot be listed per language. Call list-content-types and pass on its notice: a site administrator has to switch the type on under WPML > Settings > Post Types Translation.', 'wpml-translation-check-pro' ),
					(string) $content_type
				),
			);
		}

		$args = array(
			'post_type'              => $post_types,
			'post_status'            => Translation_Service::STATUSES,
			'posts_per_page'         => $limit,
			'offset'                 => $offset,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( '' !== (string) $search ) {
			$args['s'] = (string) $search;
		}

		do_action( 'wpml_switch_language', $language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$query = new WP_Query( $args );
		$ids   = array_map( 'absint', (array) $query->posts );
		$total = (int) $query->found_posts;

		do_action( 'wpml_switch_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$items = array();

		foreach ( $ids as $post_id ) {
			$items[] = self::describe_post( $post_id );
		}

		return array(
			'language' => $language,
			'items'    => $items,
			'total'    => $total,
			'has_more' => ( $offset + count( $items ) ) < $total,
		);
	}

	/**
	 * Terms of a taxonomy in one explicit language.
	 *
	 * @since 1.5.0
	 * @param string $language Language code to list in.
	 * @param string $taxonomy Taxonomy to list.
	 * @param int    $limit    Page size.
	 * @param int    $offset   Items to skip.
	 * @return array<string, mixed> Items, total and a has_more flag.
	 */
	public static function list_terms( $language, $taxonomy, $limit = self::DEFAULT_LIMIT, $offset = 0 ) {
		$language = (string) $language;
		$taxonomy = (string) $taxonomy;
		$limit    = max( 1, min( self::MAX_LIMIT, (int) $limit ) );
		$offset   = max( 0, (int) $offset );

		if ( ! Language_Service::is_active( $language ) ) {
			return self::inactive_language_result( $language );
		}

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return array(
				'items'    => array(),
				'total'    => 0,
				'has_more' => false,
				'notice'   => sprintf(
					/* translators: %s: requested taxonomy. */
					__( 'The taxonomy "%s" does not exist on this site. Call list-content-types for the taxonomies that do.', 'wpml-translation-check-pro' ),
					$taxonomy
				),
			);
		}

		if ( ! (bool) apply_filters( 'wpml_is_translated_taxonomy', false, $taxonomy ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			return array(
				'items'    => array(),
				'total'    => 0,
				'has_more' => false,
				'notice'   => sprintf(
					/* translators: %s: requested taxonomy. */
					__( 'WPML does not translate the taxonomy "%s", so its terms have no language. Tell the user a site administrator has to switch it on under WPML > Settings > Taxonomies Translation and save.', 'wpml-translation-check-pro' ),
					$taxonomy
				),
			);
		}

		do_action( 'wpml_switch_language', $language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$total = (int) wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => $limit,
				'offset'     => $offset,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		do_action( 'wpml_switch_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$items = array();

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$item = array(
					'term_id'  => (int) $term->term_id,
					'name'     => (string) $term->name,
					'slug'     => (string) $term->slug,
					'taxonomy' => (string) $term->taxonomy,
					'count'    => (int) $term->count,
					'parent'   => (int) $term->parent,
				);

				// Only present when the term has one, so translate-term can translate it too.
				if ( '' !== trim( (string) $term->description ) ) {
					$item['description'] = (string) $term->description;
				}

				$items[] = $item;
			}
		}

		return array(
			'language' => $language,
			'taxonomy' => $taxonomy,
			'items'    => $items,
			'total'    => $total,
			'has_more' => ( $offset + count( $items ) ) < $total,
		);
	}

	/**
	 * The shared "that language is not active here" result.
	 *
	 * @since 1.5.0
	 * @param string $language Requested language code.
	 * @return array<string, mixed> Empty result with an explanatory notice.
	 */
	private static function inactive_language_result( $language ) {
		return array(
			'items'    => array(),
			'total'    => 0,
			'has_more' => false,
			'notice'   => sprintf(
				/* translators: 1: requested language code, 2: comma-separated active codes. */
				__( '"%1$s" is not an active language on this site. Active languages are: %2$s. Call list-languages and use one of those codes.', 'wpml-translation-check-pro' ),
				(string) $language,
				implode( ', ', Language_Service::get_codes() )
			),
		);
	}

	/**
	 * A compact description of one post for a listing.
	 *
	 * @since 1.5.0
	 * @param int $post_id Post to describe.
	 * @return array<string, mixed> Post row.
	 */
	public static function describe_post( $post_id ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return array( 'id' => $post_id );
		}

		return array(
			'id'          => $post_id,
			'title'       => (string) get_the_title( $post_id ),
			'post_type'   => (string) $post->post_type,
			'post_status' => (string) $post->post_status,
			'editor_type' => class_exists( 'WPML_AT_PRO_Helper' )
				? WPML_AT_PRO_Helper::detect_editor_type( $post_id, has_blocks( $post->post_content ) ? 'Gutenberg' : 'Classic' )
				: '',
			'edit_link'   => html_entity_decode( (string) get_edit_post_link( $post_id, 'raw' ) ),
		);
	}

	/**
	 * Whether WPML is configured to translate a post type.
	 *
	 * @since 1.5.0
	 * @param string $post_type Post type slug.
	 * @return bool True when translatable.
	 */
	public static function is_post_type_translatable( $post_type ) {
		if ( class_exists( 'WPML_AT_PRO_Helper' ) ) {
			return (bool) WPML_AT_PRO_Helper::is_post_type_translatable( $post_type );
		}

		return (bool) apply_filters( 'wpml_is_translated_post_type', false, $post_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Translatable post types, narrowed to one type when asked.
	 *
	 * @since 1.5.0
	 * @param string|null $content_type Requested post type, or null for all.
	 * @return string[] Post type slugs.
	 */
	private static function resolve_post_types( $content_type ) {
		if ( null !== $content_type && '' !== $content_type ) {
			return self::is_post_type_translatable( $content_type ) ? array( (string) $content_type ) : array();
		}

		$types = array();

		foreach ( get_post_types( array(), 'names' ) as $post_type ) {
			if ( self::is_post_type_translatable( $post_type ) ) {
				$types[] = $post_type;
			}
		}

		return $types;
	}

	/**
	 * Post IDs in the source language for the given types.
	 *
	 * suppress_filters stays off so WPML's own language filtering applies; the
	 * explicit language switch is what makes the result deterministic rather
	 * than dependent on whatever language the request happens to be in.
	 *
	 * @since 1.5.0
	 * @param string[] $post_types      Post types to query.
	 * @param string   $source_language Language to query in.
	 * @return int[] Post IDs.
	 */
	private static function query_source_posts( array $post_types, $source_language ) {
		if ( '' !== (string) $source_language ) {
			do_action( 'wpml_switch_language', $source_language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => self::MAX_LIMIT * 5,
				'fields'                 => 'ids',
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$ids = array_map( 'absint', (array) $query->posts );

		if ( '' !== (string) $source_language ) {
			do_action( 'wpml_switch_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}

		return $ids;
	}

	/**
	 * Published-and-draft count for a post type.
	 *
	 * @since 1.5.0
	 * @param string $post_type Post type slug.
	 * @return int Item count.
	 */
	private static function count_posts( $post_type ) {
		$counts = wp_count_posts( $post_type );

		if ( ! is_object( $counts ) ) {
			return 0;
		}

		$total = 0;

		foreach ( array( 'publish', 'draft', 'pending', 'private' ) as $status ) {
			$total += isset( $counts->$status ) ? (int) $counts->$status : 0;
		}

		return $total;
	}
}
