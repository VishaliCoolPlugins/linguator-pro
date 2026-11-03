<?php
/**
 * Media translation: untranslated attachments and attachment translations.
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
 * Attachments only have a language and a translation group when Linguator's
 * "media support" setting is on. A translated attachment shares the file of the
 * original and has its own title, caption, description and alternative text.
 *
 * @since 1.3.0
 */
final class Media_Service {

	/**
	 * Whether Linguator translates media.
	 *
	 * @since 1.3.0
	 * @return bool True when media support is on.
	 */
	public static function is_enabled() {
		return function_exists( 'LMAT' ) && is_object( LMAT() ) && ! empty( LMAT()->options['media_support'] );
	}

	/**
	 * Error explaining how to switch media support on.
	 *
	 * @since 1.3.0
	 * @return WP_Error Error.
	 */
	public static function disabled_error() {
		return new WP_Error(
			'lmat_media_support_off',
			__( 'Linguator does not translate media, so attachments have no language and no translations. This is a site setting these tools cannot change: tell the user a site administrator must switch on media translation in Linguator settings (the media_support option) and save. Existing media then still have no language: give each one a language with manage-translation-links (action "assign_language"), then translate it with translate-media.', 'linguator-pro' )
		);
	}

	/**
	 * Describes an attachment for a listing.
	 *
	 * @since 1.3.0
	 * @param \WP_Post $attachment Attachment.
	 * @return array<string, mixed> Item.
	 */
	private static function describe( $attachment ) {
		$id = (int) $attachment->ID;

		return array(
			'id'           => $id,
			'title'        => $attachment->post_title,
			'filename'     => wp_basename( (string) get_attached_file( $id ) ),
			'mime_type'    => $attachment->post_mime_type,
			'url'          => (string) wp_get_attachment_url( $id ),
			'alt_text'     => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'      => $attachment->post_excerpt,
			'language'     => (string) linguator_get_post_language( $id ),
			'translations' => (object) linguator_get_post_translations( $id ),
			'edit_url'     => (string) get_edit_post_link( $id, 'url' ),
		);
	}

	/**
	 * Attachments of a language that have no translation in the target language.
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error Items, total and has_more, or an error.
	 */
	public static function list_untranslated( array $input ) {
		if ( ! self::is_enabled() ) {
			return self::disabled_error();
		}

		$target = isset( $input['target_language'] ) ? sanitize_key( (string) $input['target_language'] ) : '';
		$to     = Linguator_Guard::language( $target );

		if ( is_wp_error( $to ) ) {
			return $to;
		}

		$source_slug = isset( $input['source_language'] ) && '' !== $input['source_language'] ? sanitize_key( (string) $input['source_language'] ) : (string) linguator_default_language();
		$from        = Linguator_Guard::language( $source_slug );

		if ( is_wp_error( $from ) ) {
			return $from;
		}

		if ( $from->slug === $to->slug ) {
			return array(
				'items'    => array(),
				'total'    => 0,
				'has_more' => false,
				'notice'   => __( 'The target language is the language to translate from, so there is nothing to translate.', 'linguator-pro' ),
			);
		}

		$limit  = max( 1, min( 100, isset( $input['limit'] ) ? (int) $input['limit'] : 20 ) );
		$offset = max( 0, isset( $input['offset'] ) ? (int) $input['offset'] : 0 );
		$mime   = isset( $input['mime_type'] ) ? preg_replace( '#[^a-z0-9/.+-]#', '', strtolower( (string) $input['mime_type'] ) ) : '';

		$args = array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'lang'             => '',
			'fields'           => 'ids',
			'posts_per_page'   => Content_Service::SCAN_LIMIT,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One indexed language lookup, bounded.
				array(
					'taxonomy' => 'lmat_language',
					'field'    => 'term_taxonomy_id',
					'terms'    => array( (int) $from->get_tax_prop( 'lmat_language', 'term_taxonomy_id' ) ),
				),
			),
		);

		if ( '' !== $mime ) {
			$args['post_mime_type'] = $mime;
		}

		$matches = array();

		foreach ( (array) get_posts( $args ) as $id ) {
			$id = (int) $id;

			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}

			$translated = (int) ( linguator_get_post_translations( $id )[ $to->slug ] ?? 0 );

			if ( $translated < 1 || ! get_post( $translated ) ) {
				$matches[] = $id;
			}
		}

		$items = array();

		foreach ( array_slice( $matches, $offset, $limit ) as $id ) {
			$items[] = self::describe( get_post( $id ) );
		}

		return array(
			'items'    => $items,
			'total'    => count( $matches ),
			'has_more' => $offset + $limit < count( $matches ),
		);
	}

	/**
	 * Creates the translation of an attachment, or updates the one that exists.
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function translate( array $input ) {
		if ( ! self::is_enabled() ) {
			return self::disabled_error();
		}

		$source_id = isset( $input['source_media_id'] ) ? absint( $input['source_media_id'] ) : 0;
		$source    = $source_id > 0 ? get_post( $source_id ) : null;

		if ( ! $source || 'attachment' !== $source->post_type ) {
			return new WP_Error( 'lmat_not_found', __( 'The source media item was not found.', 'linguator-pro' ) );
		}

		$target   = isset( $input['target_language'] ) ? sanitize_key( (string) $input['target_language'] ) : '';
		$language = Linguator_Guard::language( $target );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$source_language = (string) linguator_get_post_language( $source_id );

		if ( '' === $source_language ) {
			return new WP_Error( 'lmat_no_language', __( 'The media item has no language. Give it one with manage-translation-links, action "assign_language".', 'linguator-pro' ) );
		}

		if ( $source_language === $language->slug ) {
			return new WP_Error( 'lmat_same_language', __( 'The target language is the language of the source media item.', 'linguator-pro' ) );
		}

		$translations = (array) linguator_get_post_translations( $source_id );
		$existing     = (int) ( $translations[ $language->slug ] ?? 0 );
		$existing     = $existing > 0 && get_post( $existing ) ? $existing : 0;

		if ( $existing > 0 && ! current_user_can( 'edit_post', $existing ) ) {
			return new WP_Error( 'lmat_forbidden', __( 'You are not allowed to edit the existing translation.', 'linguator-pro' ) );
		}

		$created = 0 === $existing;
		$post_id = $existing;

		if ( $created ) {
			$model   = Linguator_Guard::model();
			$post_id = (int) $model->post->create_media_translation( $source_id, $language );

			if ( $post_id < 1 ) {
				return new WP_Error( 'lmat_insert_failed', __( 'The media translation could not be created.', 'linguator-pro' ) );
			}

			// Linguator links the new item to its group; make sure the language and the link hold whatever the request language is.
			linguator_set_post_language( $post_id, $language->slug );
			linguator_save_post_translations( array_merge( $translations, array( $language->slug => $post_id ) ) );
		}

		$postarr = array( 'ID' => $post_id );
		$fields  = array(
			'translated_title'       => 'post_title',
			'translated_caption'     => 'post_excerpt',
			'translated_description' => 'post_content',
		);

		foreach ( $fields as $key => $column ) {
			if ( isset( $input[ $key ] ) ) {
				$postarr[ $column ] = (string) $input[ $key ];
			}
		}

		if ( count( $postarr ) > 1 ) {
			$updated = wp_update_post( wp_slash( $postarr ), true );

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		if ( isset( $input['translated_alt_text'] ) ) {
			update_post_meta( $post_id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( (string) $input['translated_alt_text'] ) ) );
		}

		return array_merge(
			array( 'created' => $created ),
			self::describe( get_post( $post_id ) )
		);
	}
}
