<?php
/**
 * Translated-post URL fixes for the abilities subsystem.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixes a translated post's slug and category so its URL does not keep
 * source-language parts, e.g. an "es" post still under an English category
 * slug or still carrying its English post_name.
 *
 * A newly created translation inherits its source's slug and categories
 * verbatim, and nothing about WPML's own translation flow changes them
 * afterward; this is a manual step site owners are expected to do by hand.
 *
 * @since 1.5.0
 */
final class Url_Service {

	/**
	 * Updates one translated post's slug and/or category assignment.
	 *
	 * Runs inside the post's own language context, because a category slug
	 * assigned outside of it would attach the source-language term rather
	 * than its translation.
	 *
	 * @since 1.5.0
	 * @param int                  $post_id Translated post to update.
	 * @param array<string, mixed> $args    slug, category_ids, primary_category_id.
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	public static function update( $post_id, array $args ) {
		$post_id = absint( $post_id );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return new WP_Error(
				'automlp_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d was not found.', 'wpml-translation-check-pro' ),
					$post_id
				)
			);
		}

		$details = Translation_Service::get_element_language_details( $post_id );

		if ( null === $details || '' === $details['language_code'] ) {
			return new WP_Error(
				'automlp_no_language',
				__( 'This post has no WPML language details, so there is no language context to update its URL in. Call get-element-language-details first to confirm it is linked into a translation group.', 'wpml-translation-check-pro' )
			);
		}

		$language = (string) $details['language_code'];
		$post_type = (string) $post->post_type;

		$slug = isset( $args['slug'] ) ? sanitize_title( (string) $args['slug'] ) : '';

		$category_ids = array();

		if ( isset( $args['category_ids'] ) && is_array( $args['category_ids'] ) ) {
			$category_ids = array_values( array_filter( array_map( 'absint', $args['category_ids'] ) ) );
		}

		$primary_category_id = isset( $args['primary_category_id'] ) ? absint( $args['primary_category_id'] ) : 0;

		if ( '' === $slug && empty( $category_ids ) && 0 === $primary_category_id ) {
			return new WP_Error(
				'automlp_nothing_to_update',
				__( 'Provide at least one of slug, category_ids or primary_category_id.', 'wpml-translation-check-pro' )
			);
		}

		do_action( 'wpml_switch_language', $language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$result = self::apply( $post_id, $post_type, $slug, $category_ids, $primary_category_id );

		do_action( 'wpml_switch_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['post_id']  = $post_id;
		$result['language'] = $language;

		return $result;
	}

	/**
	 * The actual mutation, run inside the target language context.
	 *
	 * @since 1.5.0
	 * @param int      $post_id             Post being changed.
	 * @param string   $post_type           Its post type.
	 * @param string   $slug                New slug, or '' to leave unchanged.
	 * @param int[]    $category_ids        Categories to set (post type "post" only).
	 * @param int      $primary_category_id Category to also mark primary for SEO plugins.
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	private static function apply( $post_id, $post_type, $slug, array $category_ids, $primary_category_id ) {
		global $wpdb;

		$before = get_post( $post_id );
		$before_link = (string) get_permalink( $post_id );
		$before_slug = $before ? (string) $before->post_name : '';

		if ( '' !== $slug ) {
			$updated = wp_update_post( wp_slash( array(
				'ID'        => $post_id,
				'post_name' => $slug,
			) ), true );

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}

			$after_update = get_post( $post_id );

			/*
			 * wp_update_post() runs the slug through wp_unique_post_slug(),
			 * which can silently append -2 or fall back to the post ID when it
			 * thinks the requested slug collides (including with the post's
			 * own previous slug in some edge cases). A direct write is the
			 * only way to guarantee the exact translated slug the caller
			 * asked for actually lands, matching how AutoMLP's other writes
			 * bypass the same filter chain when it disagrees with an explicit
			 * value.
			 */
			if ( $after_update && (string) $after_update->post_name !== $slug ) {
				$wpdb->update( $wpdb->posts, array( 'post_name' => $slug ), array( 'ID' => $post_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			}

			delete_post_meta( $post_id, '_wp_old_slug' );
		}

		if ( ! empty( $category_ids ) && 'post' === $post_type ) {
			wp_set_post_categories( $post_id, $category_ids, false );
		}

		if ( $primary_category_id > 0 && 'post' === $post_type ) {
			if ( ! has_term( $primary_category_id, 'category', $post_id ) ) {
				wp_set_object_terms( $post_id, array( $primary_category_id ), 'category', true );
			}

			update_post_meta( $post_id, '_yoast_wpseo_primary_category', (string) $primary_category_id );
			update_post_meta( $post_id, 'rank_math_primary_category', (string) $primary_category_id );
		}

		clean_post_cache( $post_id );
		flush_rewrite_rules( false );

		$after = get_post( $post_id );
		$categories = array();

		if ( 'post' === $post_type ) {
			$terms = wp_get_post_categories( $post_id, array( 'fields' => 'all' ) );

			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$categories[] = array(
						'id'   => (int) $term->term_id,
						'name' => (string) $term->name,
						'slug' => (string) $term->slug,
					);
				}
			}
		}

		return array(
			'before_slug'          => $before_slug,
			'after_slug'           => $after ? (string) $after->post_name : '',
			'before_link'          => $before_link,
			'after_link'           => (string) get_permalink( $post_id ),
			'categories'           => $categories,
			'primary_category_id'  => $primary_category_id,
		);
	}
}
