<?php
/**
 * Creates and updates translated posts and terms.
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
 * Writes translations through Linguator's own insert and update functions, so a
 * translation is always linked to its original.
 *
 * @since 1.2.0
 */
final class Translation_Service {

	/**
	 * Statuses a caller may ask for.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Creates the translation of a post, or updates the one that exists.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function translate_post( array $input ) {
		$source_id = isset( $input['source_post_id'] ) ? absint( $input['source_post_id'] ) : 0;
		$target    = isset( $input['target_language'] ) ? sanitize_key( (string) $input['target_language'] ) : '';
		$source    = $source_id > 0 ? get_post( $source_id ) : null;

		if ( ! $source ) {
			return new WP_Error( 'lmat_not_found', __( 'The source post was not found.', 'linguator-pro' ) );
		}

		$language = Linguator_Guard::language( $target );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		if ( ! Linguator_Guard::is_translated_post_type( $source->post_type ) ) {
			return new WP_Error( 'lmat_untranslated_type', __( 'Linguator does not translate this post type. Switch it on in Linguator settings first.', 'linguator-pro' ) );
		}

		$source_language = (string) linguator_get_post_language( $source_id );

		if ( '' === $source_language ) {
			return new WP_Error( 'lmat_no_language', __( 'The source post has no language. Give it one with manage-translation-links, action "assign_language".', 'linguator-pro' ) );
		}

		if ( $source_language === $target ) {
			return new WP_Error( 'lmat_same_language', __( 'The target language is the language of the source post.', 'linguator-pro' ) );
		}

		$translations = linguator_get_post_translations( $source_id );
		$existing     = (int) ( $translations[ $target ] ?? 0 );
		$existing     = $existing > 0 && get_post( $existing ) ? $existing : 0;

		$result = $existing > 0
			? self::update_post( $existing, $input )
			: self::create_post( $source, $target, $translations, $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = (int) $result;
		$skipped = self::save_meta( $post_id, isset( $input['translated_meta'] ) && is_array( $input['translated_meta'] ) ? $input['translated_meta'] : array() );

		return array(
			'post_id'      => $post_id,
			'created'      => 0 === $existing,
			'status'       => get_post_status( $post_id ),
			'permalink'    => (string) get_permalink( $post_id ),
			'edit_url'     => (string) get_edit_post_link( $post_id, 'url' ),
			'slug'         => (string) get_post_field( 'post_name', $post_id ),
			'language'     => (string) linguator_get_post_language( $post_id ),
			'translations' => linguator_get_post_translations( $post_id ),
			'meta_skipped' => $skipped,
		);
	}

	/**
	 * Creates a linked draft copy of a post in another language, without translating it.
	 *
	 * Title, content, excerpt, featured image, parent and the custom fields Linguator copies are
	 * carried over, and terms are mapped to their translations. When a translation exists the call
	 * is refused, unless overwrite is true: then only its title, content and excerpt are replaced.
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function copy_post( array $input ) {
		$source_id = isset( $input['source_post_id'] ) ? absint( $input['source_post_id'] ) : 0;
		$target    = isset( $input['target_language'] ) ? sanitize_key( (string) $input['target_language'] ) : '';
		$source    = $source_id > 0 ? get_post( $source_id ) : null;

		if ( ! $source ) {
			return new WP_Error( 'lmat_not_found', __( 'The source post was not found.', 'linguator-pro' ) );
		}

		if ( 'attachment' === $source->post_type ) {
			return new WP_Error( 'lmat_use_translate_media', __( 'Media items are not copied as posts. Use translate-media.', 'linguator-pro' ) );
		}

		$language = Linguator_Guard::language( $target );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		if ( ! Linguator_Guard::is_translated_post_type( $source->post_type ) ) {
			return new WP_Error( 'lmat_untranslated_type', Linguator_Guard::not_translated_message( $source->post_type ) );
		}

		$source_language = (string) linguator_get_post_language( $source_id );

		if ( '' === $source_language ) {
			return new WP_Error( 'lmat_no_language', __( 'The source post has no language. Give it one with manage-translation-links, action "assign_language".', 'linguator-pro' ) );
		}

		if ( $source_language === $target ) {
			return new WP_Error( 'lmat_same_language', __( 'The target language is the language of the source post.', 'linguator-pro' ) );
		}

		$translations = linguator_get_post_translations( $source_id );
		$existing     = (int) ( $translations[ $target ] ?? 0 );
		$existing     = $existing > 0 && get_post( $existing ) ? $existing : 0;
		$overwrite    = ! empty( $input['overwrite'] );

		if ( $existing > 0 && ! $overwrite ) {
			return new WP_Error(
				'lmat_translation_exists',
				sprintf(
					/* translators: 1: translation post ID, 2: language slug. */
					__( 'Post %1$d is already the %2$s translation. Pass overwrite true to replace its title, content and excerpt with those of the original.', 'linguator-pro' ),
					$existing,
					$target
				)
			);
		}

		if ( $existing > 0 ) {
			$copied = self::update_post(
				$existing,
				array(
					'translated_title'   => $source->post_title,
					'translated_content' => $source->post_content,
					'translated_excerpt' => $source->post_excerpt,
				)
			);

			if ( is_wp_error( $copied ) ) {
				return $copied;
			}

			$post_id = (int) $copied;
		} else {
			$parent  = $source->post_parent > 0 ? (int) ( linguator_get_post_translations( $source->post_parent )[ $target ] ?? 0 ) : 0;
			$postarr = array(
				'post_type'      => $source->post_type,
				'post_title'     => $source->post_title,
				'post_content'   => $source->post_content,
				'post_excerpt'   => $source->post_excerpt,
				'post_status'    => 'draft',
				'post_author'    => (int) $source->post_author,
				'post_parent'    => $parent,
				'menu_order'     => (int) $source->menu_order,
				'comment_status' => $source->comment_status,
				'ping_status'    => $source->ping_status,
				'translations'   => $translations,
			);

			$new_id = linguator_insert_post( wp_slash( $postarr ), $target );

			if ( is_wp_error( $new_id ) || (int) $new_id < 1 ) {
				return is_wp_error( $new_id ) ? $new_id : new WP_Error( 'lmat_insert_failed', __( 'The copy could not be created.', 'linguator-pro' ) );
			}

			$post_id = (int) $new_id;

			// Linguator's save hook gives a new post the language of the current request, which is not the target.
			linguator_set_post_language( $post_id, $target );
			linguator_save_post_translations( array_merge( $translations, array( $target => $post_id ) ) );

			self::copy_shared( $source, $post_id, $target );
			self::copy_synced_meta( $source_id, $post_id, $target );
		}

		return array(
			'post_id'      => $post_id,
			'created'      => 0 === $existing,
			'overwritten'  => $existing > 0,
			'status'       => get_post_status( $post_id ),
			'permalink'    => (string) get_permalink( $post_id ),
			'edit_url'     => (string) get_edit_post_link( $post_id, 'url' ),
			'language'     => (string) linguator_get_post_language( $post_id ),
			'translations' => linguator_get_post_translations( $post_id ),
		);
	}

	/**
	 * Copies the custom fields (and featured image) Linguator copies to a new translation.
	 *
	 * @since 1.3.0
	 * @param int    $from   Original post ID.
	 * @param int    $to     New translation ID.
	 * @param string $target Target language slug.
	 * @return void
	 */
	private static function copy_synced_meta( $from, $to, $target ) {
		$linguator = function_exists( 'LMAT' ) ? LMAT() : null;

		if ( is_object( $linguator ) && isset( $linguator->sync->post_metas ) && is_object( $linguator->sync->post_metas ) ) {
			$linguator->sync->post_metas->copy( $from, $to, $target, false );
		}
	}

	/**
	 * Creates a translation and carries over what is shared with the original.
	 *
	 * @since 1.2.0
	 * @param \WP_Post             $source       Original post.
	 * @param string               $target       Target language slug.
	 * @param array<string, int>   $translations Translation group of the original.
	 * @param array<string, mixed> $input        Ability input.
	 * @return int|WP_Error New post ID, or an error.
	 */
	private static function create_post( $source, $target, array $translations, array $input ) {
		$title   = isset( $input['translated_title'] ) ? trim( (string) $input['translated_title'] ) : '';
		$content = isset( $input['translated_content'] ) ? (string) $input['translated_content'] : '';

		if ( '' === $title ) {
			return new WP_Error( 'lmat_missing_title', __( 'translated_title is required to create a translation.', 'linguator-pro' ) );
		}

		if ( '' === trim( $content ) && '' !== trim( $source->post_content ) ) {
			return new WP_Error( 'lmat_missing_content', __( 'translated_content is required to create a translation.', 'linguator-pro' ) );
		}

		$parent = 0;

		if ( $source->post_parent > 0 ) {
			$parent = (int) ( linguator_get_post_translations( $source->post_parent )[ $target ] ?? 0 );
		}

		$postarr = array(
			'post_type'      => $source->post_type,
			'post_title'     => $title,
			'post_content'   => $content,
			'post_excerpt'   => isset( $input['translated_excerpt'] ) ? (string) $input['translated_excerpt'] : '',
			'post_status'    => self::status( $input ),
			'post_author'    => (int) $source->post_author,
			'post_parent'    => $parent,
			'menu_order'     => (int) $source->menu_order,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
			'translations'   => $translations,
		);

		if ( ! empty( $input['translated_slug'] ) ) {
			$postarr['post_name'] = sanitize_title( (string) $input['translated_slug'] );
		}

		$new_id = linguator_insert_post( wp_slash( $postarr ), $target );

		if ( is_wp_error( $new_id ) || (int) $new_id < 1 ) {
			return is_wp_error( $new_id ) ? $new_id : new WP_Error( 'lmat_insert_failed', __( 'The translation could not be created.', 'linguator-pro' ) );
		}

		// Linguator's save hook gives a new post the language of the current request, which is not the target.
		linguator_set_post_language( (int) $new_id, $target );
		linguator_save_post_translations( array_merge( $translations, array( $target => (int) $new_id ) ) );

		self::copy_shared( $source, (int) $new_id, $target );

		return (int) $new_id;
	}

	/**
	 * Updates the fields that were sent on an existing translation.
	 *
	 * @since 1.2.0
	 * @param int                  $post_id Translation ID.
	 * @param array<string, mixed> $input   Ability input.
	 * @return int|WP_Error Post ID, or an error.
	 */
	private static function update_post( $post_id, array $input ) {
		$postarr = array( 'ID' => $post_id );
		$fields  = array(
			'translated_title'   => 'post_title',
			'translated_content' => 'post_content',
			'translated_excerpt' => 'post_excerpt',
		);

		foreach ( $fields as $key => $column ) {
			if ( isset( $input[ $key ] ) ) {
				$postarr[ $column ] = (string) $input[ $key ];
			}
		}

		if ( ! empty( $input['translated_slug'] ) ) {
			$postarr['post_name'] = sanitize_title( (string) $input['translated_slug'] );
		}

		if ( isset( $input['status'] ) ) {
			$postarr['post_status'] = self::status( $input );
		}

		$updated = linguator_update_post( wp_slash( $postarr ) );

		return is_wp_error( $updated ) || (int) $updated < 1
			? ( is_wp_error( $updated ) ? $updated : new WP_Error( 'lmat_update_failed', __( 'The translation could not be updated.', 'linguator-pro' ) ) )
			: (int) $updated;
	}

	/**
	 * Copies the featured image and the terms of the original to a new translation.
	 *
	 * Terms of a translated taxonomy are mapped to their translation. Linguator
	 * hides terms of other languages from lookups, so the original's terms are
	 * read with its language current and the translation's are set with the target
	 * language current.
	 *
	 * @since 1.2.0
	 * @param \WP_Post $source  Original post.
	 * @param int      $new_id  New translation ID.
	 * @param string   $target  Target language slug.
	 * @return void
	 */
	private static function copy_shared( $source, $new_id, $target ) {
		$thumbnail = (int) get_post_thumbnail_id( $source->ID );

		if ( $thumbnail > 0 ) {
			set_post_thumbnail( $new_id, $thumbnail );
		}

		$source_language = Linguator_Guard::language( (string) linguator_get_post_language( $source->ID ) );
		$target_language = Linguator_Guard::language( $target );

		if ( is_wp_error( $source_language ) || is_wp_error( $target_language ) ) {
			return;
		}

		$translated = Settings_Service::taxonomies();
		$plan       = array();

		// Linguator keeps the language and the translation group in hidden taxonomies; copying those would undo the language.
		foreach ( get_object_taxonomies( $source->post_type, 'objects' ) as $taxonomy => $object ) {
			if ( empty( $object->public ) && empty( $object->show_ui ) ) {
				continue;
			}

			$terms = Linguator_Guard::with_language(
				$source_language,
				function () use ( $source, $taxonomy ) {
					return wp_get_object_terms( $source->ID, $taxonomy, array( 'fields' => 'ids' ) );
				}
			);

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			if ( in_array( $taxonomy, $translated, true ) ) {
				$mapped = array();

				foreach ( $terms as $term_id ) {
					$counterpart = (int) ( linguator_get_term_translations( (int) $term_id )[ $target ] ?? 0 );

					if ( $counterpart > 0 ) {
						$mapped[] = $counterpart;
					}
				}

				$terms = $mapped;
			}

			if ( ! empty( $terms ) ) {
				$plan[ $taxonomy ] = array_map( 'intval', $terms );
			}
		}

		Linguator_Guard::with_language(
			$target_language,
			function () use ( $new_id, $plan ) {
				foreach ( $plan as $taxonomy => $term_ids ) {
					wp_set_object_terms( $new_id, $term_ids, $taxonomy );
				}
			}
		);

		self::drop_foreign_terms( $new_id, $target, $translated );
	}

	/**
	 * Removes terms of other languages from a new translation.
	 *
	 * WordPress gives a new post the default category of the request's language,
	 * which is not the language of the translation.
	 *
	 * @since 1.2.0
	 * @param int      $post_id    Translation ID.
	 * @param string   $target     Target language slug.
	 * @param string[] $taxonomies Translated taxonomies.
	 * @return void
	 */
	private static function drop_foreign_terms( $post_id, $target, array $taxonomies ) {
		foreach ( $taxonomies as $taxonomy ) {
			$term_ids = wp_get_object_terms(
				$post_id,
				$taxonomy,
				array(
					'fields' => 'ids',
					'lang'   => '',
				)
			);

			if ( is_wp_error( $term_ids ) ) {
				continue;
			}

			foreach ( $term_ids as $term_id ) {
				$language = (string) linguator_get_term_language( (int) $term_id );

				if ( '' === $language || $language === $target ) {
					continue;
				}

				wp_remove_object_terms( $post_id, (int) $term_id, $taxonomy );
			}
		}
	}

	/**
	 * Saves the translated meta fields that the site allows.
	 *
	 * @since 1.2.0
	 * @param int                  $post_id Translation ID.
	 * @param array<string, mixed> $meta    Key-value pairs from the caller.
	 * @return string[] Keys that were refused.
	 */
	private static function save_meta( $post_id, array $meta ) {
		$allowed = Settings_Service::allowed_meta_keys();
		$skipped = array();

		foreach ( $meta as $key => $value ) {
			$key = (string) $key;

			if ( ! in_array( $key, $allowed, true ) ) {
				$skipped[] = $key;
				continue;
			}

			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}

		return $skipped;
	}

	/**
	 * Post status for a write, downgraded to draft when the user cannot publish.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Ability input.
	 * @return string Status.
	 */
	private static function status( array $input ) {
		$status = isset( $input['status'] ) && in_array( $input['status'], self::STATUSES, true ) ? (string) $input['status'] : 'publish';

		return in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( 'publish_posts' ) ? 'draft' : $status;
	}

	/**
	 * Creates the translation of a term, or updates the one that exists.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function translate_term( array $input ) {
		$source_id = isset( $input['source_term_id'] ) ? absint( $input['source_term_id'] ) : 0;
		$target    = isset( $input['target_language'] ) ? sanitize_key( (string) $input['target_language'] ) : '';
		$source    = $source_id > 0 ? get_term( $source_id ) : null;

		if ( ! $source || is_wp_error( $source ) ) {
			return new WP_Error( 'lmat_not_found', __( 'The source term was not found.', 'linguator-pro' ) );
		}

		$language = Linguator_Guard::language( $target );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		if ( ! linguator_is_translated_taxonomy( $source->taxonomy ) ) {
			return new WP_Error( 'lmat_untranslated_taxonomy', __( 'Linguator does not translate this taxonomy. Switch it on in Linguator settings first.', 'linguator-pro' ) );
		}

		$source_language = (string) linguator_get_term_language( $source_id );

		if ( '' === $source_language || $source_language === $target ) {
			return new WP_Error( 'lmat_invalid_source_language', __( 'The source term needs a language that differs from the target language.', 'linguator-pro' ) );
		}

		$name         = isset( $input['translated_name'] ) ? trim( (string) $input['translated_name'] ) : '';
		$translations = linguator_get_term_translations( $source_id );
		$existing     = (int) ( $translations[ $target ] ?? 0 );
		$existing     = $existing > 0 && get_term( $existing ) && ! is_wp_error( get_term( $existing ) ) ? $existing : 0;
		$args         = array();

		if ( isset( $input['translated_description'] ) ) {
			$args['description'] = (string) $input['translated_description'];
		}

		if ( ! empty( $input['translated_slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $input['translated_slug'] );
		}

		if ( $existing > 0 ) {
			if ( '' !== $name ) {
				$args['name'] = $name;
			}

			$done = linguator_update_term( $existing, $args );
		} else {
			if ( '' === $name ) {
				return new WP_Error( 'lmat_missing_name', __( 'translated_name is required to create a translation.', 'linguator-pro' ) );
			}

			if ( $source->parent > 0 ) {
				$args['parent'] = (int) ( linguator_get_term_translations( $source->parent )[ $target ] ?? 0 );
			}

			$args['translations'] = $translations;
			$done                 = linguator_insert_term( $name, $source->taxonomy, $target, $args );
		}

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		$term_id = (int) ( $done['term_id'] ?? $existing );

		return array(
			'term_id'      => $term_id,
			'created'      => 0 === $existing,
			'taxonomy'     => $source->taxonomy,
			'language'     => (string) linguator_get_term_language( $term_id ),
			'translations' => linguator_get_term_translations( $term_id ),
		);
	}
}
