<?php
/**
 * Content browsing: lists of posts and terms with their translations.
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
 * Lists posts and terms, and reports which translations each one has.
 *
 * @since 1.3.0
 */
final class Browse_Service {

	/**
	 * Post statuses a listing can filter on.
	 *
	 * @since 1.3.0
	 * @var string[]
	 */
	const LIST_STATUSES = array( 'any', 'publish', 'draft', 'pending', 'private', 'future', 'trash' );

	/**
	 * Most posts accepted by get-post-translations in one call.
	 *
	 * @since 1.3.0
	 * @var int
	 */
	const MAX_POST_IDS = 50;

	/**
	 * Resolves a language filter.
	 *
	 * @since 1.3.0
	 * @param string $slug Language slug, "any" or an empty string.
	 * @return object|string|WP_Error Language object, an empty string for every language, or an error.
	 */
	private static function language_filter( $slug ) {
		$slug = sanitize_key( (string) $slug );

		if ( '' === $slug || 'any' === $slug ) {
			return '';
		}

		return Linguator_Guard::language( $slug );
	}

	/**
	 * Lists posts with language and translations.
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error Items, total and has_more, or an error.
	 */
	public static function list_posts( array $input ) {
		if ( null === Linguator_Guard::model() ) {
			return new WP_Error( 'lmat_not_ready', __( 'Linguator is not loaded.', 'linguator-pro' ) );
		}

		$language = self::language_filter( $input['language'] ?? '' );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$types     = Settings_Service::post_types();
		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';

		if ( '' !== $post_type ) {
			if ( ! in_array( $post_type, $types, true ) ) {
				return new WP_Error( 'lmat_untranslated_type', Linguator_Guard::not_translated_message( $post_type ) );
			}

			$types = array( $post_type );
		}

		if ( array() === $types ) {
			return array(
				'items'    => array(),
				'total'    => 0,
				'has_more' => false,
			);
		}

		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'any';

		if ( ! in_array( $status, self::LIST_STATUSES, true ) ) {
			return new WP_Error(
				'lmat_invalid_status',
				sprintf(
					/* translators: %s: comma-separated list of statuses. */
					__( 'Invalid status. Use one of: %s.', 'linguator-pro' ),
					implode( ', ', self::LIST_STATUSES )
				)
			);
		}

		$limit  = max( 1, min( 100, isset( $input['limit'] ) ? (int) $input['limit'] : 20 ) );
		$offset = max( 0, isset( $input['offset'] ) ? (int) $input['offset'] : 0 );
		$args   = array(
			'post_type'           => $types,
			'post_status'         => $status,
			'perm'                => 'readable',
			'lang'                => '',
			'posts_per_page'      => $limit,
			'offset'              => $offset,
			'orderby'             => 'ID',
			'order'               => 'ASC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
			'suppress_filters'    => false,
		);

		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['s'] = sanitize_text_field( (string) $input['search'] );
		}

		if ( is_object( $language ) ) {
			// Matched by the language taxonomy term: the "lang" query variable can fall back to another language.
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One indexed language lookup, paged.
				array(
					'taxonomy' => 'lmat_language',
					'field'    => 'term_taxonomy_id',
					'terms'    => array( (int) $language->get_tax_prop( 'lmat_language', 'term_taxonomy_id' ) ),
				),
			);
		}

		$query = new \WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'           => (int) $post->ID,
				'title'        => get_the_title( $post ),
				'type'         => $post->post_type,
				'status'       => $post->post_status,
				'language'     => (string) linguator_get_post_language( (int) $post->ID ),
				'modified'     => $post->post_modified_gmt,
				'translations' => (object) linguator_get_post_translations( (int) $post->ID ),
				'edit_url'     => (string) get_edit_post_link( (int) $post->ID, 'url' ),
			);
		}

		$total = (int) $query->found_posts;

		return array(
			'items'    => $items,
			'total'    => $total,
			'has_more' => $offset + $limit < $total,
		);
	}

	/**
	 * Lists the terms of a translated taxonomy with language and translations.
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error Items, total and has_more, or an error.
	 */
	public static function list_terms( array $input ) {
		if ( null === Linguator_Guard::model() ) {
			return new WP_Error( 'lmat_not_ready', __( 'Linguator is not loaded.', 'linguator-pro' ) );
		}

		$taxonomy   = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : 'category';
		$taxonomies = Settings_Service::taxonomies();

		if ( ! in_array( $taxonomy, $taxonomies, true ) ) {
			return new WP_Error(
				'lmat_untranslated_taxonomy',
				sprintf(
					/* translators: 1: taxonomy slug, 2: comma-separated list of translated taxonomies. */
					__( 'Linguator does not translate the taxonomy "%1$s". Translated taxonomies: %2$s. A site administrator can switch others on in Linguator settings.', 'linguator-pro' ),
					$taxonomy,
					implode( ', ', $taxonomies )
				)
			);
		}

		$language = self::language_filter( $input['language'] ?? '' );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$limit  = max( 1, min( 100, isset( $input['limit'] ) ? (int) $input['limit'] : 20 ) );
		$offset = max( 0, isset( $input['offset'] ) ? (int) $input['offset'] : 0 );
		$args   = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'term_id',
			'order'      => 'ASC',
			'lang'       => is_object( $language ) ? (string) $language->slug : '',
		);

		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['search'] = sanitize_text_field( (string) $input['search'] );
		}

		// Linguator hides terms of other languages unless that language is the current one.
		$found = Linguator_Guard::with_language(
			is_object( $language ) ? $language : null,
			static function () use ( $args, $limit, $offset ) {
				$terms = get_terms( array_merge( $args, array( 'number' => $limit, 'offset' => $offset ) ) );
				$total = get_terms( array_merge( $args, array( 'fields' => 'count' ) ) );

				return array( $terms, $total );
			}
		);

		list( $terms, $total ) = $found;

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$items = array();

		foreach ( (array) $terms as $term ) {
			$items[] = array(
				'id'           => (int) $term->term_id,
				'name'         => $term->name,
				'slug'         => $term->slug,
				'language'     => (string) linguator_get_term_language( (int) $term->term_id ),
				'count'        => (int) $term->count,
				'parent'       => (int) $term->parent,
				'translations' => (object) linguator_get_term_translations( (int) $term->term_id ),
			);
		}

		$total = is_wp_error( $total ) ? count( $items ) : (int) $total;

		return array(
			'items'    => $items,
			'total'    => $total,
			'has_more' => $offset + $limit < $total,
		);
	}

	/**
	 * Translation details of one or several posts.
	 *
	 * Posts the user may not edit are reported with an error entry, not their data.
	 *
	 * @since 1.3.0
	 * @param int[] $post_ids Post IDs, at most MAX_POST_IDS.
	 * @return array<string, mixed>|WP_Error Results, or an error.
	 */
	public static function get_post_translations( array $post_ids ) {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );

		if ( array() === $post_ids ) {
			return new WP_Error( 'lmat_missing_post', __( 'Give post_id or post_ids.', 'linguator-pro' ) );
		}

		if ( count( $post_ids ) > self::MAX_POST_IDS ) {
			return new WP_Error(
				'lmat_too_many_posts',
				sprintf(
					/* translators: %d: maximum number of posts. */
					__( 'Give at most %d posts per call.', 'linguator-pro' ),
					self::MAX_POST_IDS
				)
			);
		}

		if ( null === Linguator_Guard::model() ) {
			return new WP_Error( 'lmat_not_ready', __( 'Linguator is not loaded.', 'linguator-pro' ) );
		}

		$all     = array_map( 'strval', (array) linguator_languages_list() );
		$results = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				$results[] = array(
					'id'    => $post_id,
					'error' => __( 'Post not found.', 'linguator-pro' ),
				);
				continue;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$results[] = array(
					'id'    => $post_id,
					'error' => __( 'You are not allowed to access this post.', 'linguator-pro' ),
				);
				continue;
			}

			$language     = (string) linguator_get_post_language( $post_id );
			$translations = array();
			$present      = array();
			$linked       = (array) linguator_get_post_translations( $post_id );

			foreach ( $linked as $slug => $translation_id ) {
				$translation_id = (int) $translation_id;
				$translation    = $translation_id > 0 ? get_post( $translation_id ) : null;

				if ( ! $translation ) {
					continue; // A link can outlive a deleted translation.
				}

				$present[ (string) $slug ] = true;

				if ( ! current_user_can( 'edit_post', $translation_id ) ) {
					continue;
				}

				$translations[ (string) $slug ] = array(
					'id'       => $translation_id,
					'status'   => $translation->post_status,
					'title'    => get_the_title( $translation ),
					'edit_url' => (string) get_edit_post_link( $translation_id, 'url' ),
				);
			}

			$missing = array();

			foreach ( $all as $slug ) {
				if ( $slug !== $language && ! isset( $present[ $slug ] ) ) {
					$missing[] = $slug;
				}
			}

			$results[] = array(
				'id'           => $post_id,
				'type'         => $post->post_type,
				'language'     => $language,
				'translations' => (object) $translations,
				'missing'      => '' === $language ? array() : $missing,
			);
		}

		return array( 'results' => $results );
	}
}
