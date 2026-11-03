<?php
/**
 * Checks the Linguator abilities depend on.
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
 * Resolves languages and rejects requests Linguator cannot serve.
 *
 * @since 1.2.0
 */
final class Linguator_Guard {

	/**
	 * Linguator's model, or null when Linguator is not loaded.
	 *
	 * @since 1.2.0
	 * @return object|null Model.
	 */
	public static function model() {
		return function_exists( 'LMAT' ) && is_object( LMAT() ) && isset( LMAT()->model ) ? LMAT()->model : null;
	}

	/**
	 * Language object for a slug.
	 *
	 * @since 1.2.0
	 * @param string $slug Language slug.
	 * @return object|WP_Error Language, or an error naming the valid slugs.
	 */
	public static function language( $slug ) {
		$model = self::model();

		if ( null === $model ) {
			return new WP_Error( 'lmat_not_ready', __( 'Linguator is not loaded.', 'linguator-pro' ) );
		}

		$slug     = sanitize_key( (string) $slug );
		$language = '' === $slug ? false : $model->get_language( $slug );

		if ( ! $language ) {
			return new WP_Error(
				'lmat_invalid_language',
				sprintf(
					/* translators: 1: language slug, 2: comma-separated list of valid slugs. */
					__( 'Language "%1$s" is not configured. Valid languages: %2$s.', 'linguator-pro' ),
					$slug,
					implode( ', ', (array) linguator_languages_list() )
				)
			);
		}

		return $language;
	}

	/**
	 * Runs a callback with a language as the current one.
	 *
	 * Linguator hides terms of other languages from term lookups, so work on the
	 * terms of a language needs that language to be current.
	 *
	 * @since 1.2.0
	 * @param object|null $language Language object, or null for no current language.
	 * @param callable    $callback Work to run.
	 * @return mixed Whatever the callback returns.
	 */
	public static function with_language( $language, $callback ) {
		$linguator = function_exists( 'LMAT' ) ? LMAT() : null;

		if ( ! is_object( $linguator ) ) {
			return call_user_func( $callback );
		}

		$previous = isset( $linguator->curlang ) ? $linguator->curlang : null;
		$linguator->curlang = $language;

		try {
			return call_user_func( $callback );
		} finally {
			$linguator->curlang = $previous;
		}
	}

	/**
	 * Whether a post type is translated by Linguator.
	 *
	 * @since 1.2.0
	 * @param string $post_type Post type.
	 * @return bool True when translated.
	 */
	public static function is_translated_post_type( $post_type ) {
		return linguator_is_translated_post_type( (string) $post_type );
	}

	/**
	 * Explains what to do about a post type Linguator does not translate.
	 *
	 * @since 1.2.0
	 * @param string $post_type Post type.
	 * @return string Message naming the post type and the setting that switches it on.
	 */
	public static function not_translated_message( $post_type ) {
		$object = get_post_type_object( (string) $post_type );
		$label  = $object ? (string) $object->labels->name : (string) $post_type;

		return sprintf(
			/* translators: 1: post type label, 2: post type name. */
			__( 'Linguator does not translate "%1$s" (%2$s), so these items have no language and cannot be translated yet. This is a site setting these tools cannot change: tell the user a site administrator must switch it on in Linguator settings, under Post types, and save. Existing items then still have no language: give each one a language with manage-translation-links (action "assign_language"), then translate them.', 'linguator-pro' ),
			$label,
			(string) $post_type
		);
	}
}
