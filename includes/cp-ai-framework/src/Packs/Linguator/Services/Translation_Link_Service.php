<?php
/**
 * Links, unlinks and assigns languages to posts and terms.
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
 * Manages translation groups.
 *
 * @since 1.2.0
 */
final class Translation_Link_Service {

	/**
	 * Actions the ability accepts.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const ACTIONS = array( 'get', 'link', 'unlink', 'assign_language' );

	/**
	 * Runs one action.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function run( array $input ) {
		$is_term = isset( $input['object_type'] ) && 'term' === $input['object_type'];
		$action  = isset( $input['action'] ) ? (string) $input['action'] : '';

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new WP_Error( 'lmat_invalid_action', __( 'action must be one of: get, link, unlink, assign_language.', 'linguator-pro' ) );
		}

		switch ( $action ) {
			case 'get':
				return self::get( $is_term, isset( $input['id'] ) ? absint( $input['id'] ) : 0 );
			case 'link':
				return self::link( $is_term, isset( $input['translations'] ) && is_array( $input['translations'] ) ? $input['translations'] : array() );
			case 'unlink':
				return self::unlink( $is_term, isset( $input['id'] ) ? absint( $input['id'] ) : 0 );
			default:
				return self::assign_language( $is_term, isset( $input['ids'] ) ? $input['ids'] : array(), isset( $input['language'] ) ? (string) $input['language'] : '' );
		}
	}

	/**
	 * Language of an object.
	 *
	 * @since 1.2.0
	 * @param bool $is_term Whether the object is a term.
	 * @param int  $id      Object ID.
	 * @return string Language slug, or an empty string.
	 */
	private static function language_of( $is_term, $id ) {
		return (string) ( $is_term ? linguator_get_term_language( $id ) : linguator_get_post_language( $id ) );
	}

	/**
	 * Whether the current user may change an object.
	 *
	 * @since 1.2.0
	 * @param bool $is_term Whether the object is a term.
	 * @param int  $id      Object ID.
	 * @return bool True when allowed.
	 */
	private static function can_edit( $is_term, $id ) {
		return $is_term ? current_user_can( 'edit_term', $id ) : current_user_can( 'edit_post', $id );
	}

	/**
	 * The translation group of an object.
	 *
	 * @since 1.2.0
	 * @param bool $is_term Whether the object is a term.
	 * @param int  $id      Object ID.
	 * @return array<string, mixed>|WP_Error Group.
	 */
	private static function get( $is_term, $id ) {
		if ( $id < 1 ) {
			return new WP_Error( 'lmat_missing_id', __( 'id is required.', 'linguator-pro' ) );
		}

		return array(
			'id'           => $id,
			'language'     => self::language_of( $is_term, $id ),
			'translations' => $is_term ? linguator_get_term_translations( $id ) : linguator_get_post_translations( $id ),
		);
	}

	/**
	 * Links objects into one translation group.
	 *
	 * @since 1.2.0
	 * @param bool                $is_term      Whether the objects are terms.
	 * @param array<string, mixed> $translations Language slug => object ID.
	 * @return array<string, mixed>|WP_Error Saved group.
	 */
	private static function link( $is_term, array $translations ) {
		$group = array();

		foreach ( $translations as $slug => $id ) {
			$slug = sanitize_key( (string) $slug );
			$id   = absint( $id );

			if ( $id < 1 ) {
				continue;
			}

			if ( self::language_of( $is_term, $id ) !== $slug ) {
				return new WP_Error(
					'lmat_language_mismatch',
					sprintf(
						/* translators: 1: object ID, 2: language slug. */
						__( 'Object %1$d is not in language "%2$s". Assign its language first.', 'linguator-pro' ),
						$id,
						$slug
					)
				);
			}

			if ( ! self::can_edit( $is_term, $id ) ) {
				return new WP_Error( 'lmat_forbidden', __( 'You are not allowed to change one of these objects.', 'linguator-pro' ) );
			}

			$group[ $slug ] = $id;
		}

		if ( count( $group ) < 2 ) {
			return new WP_Error( 'lmat_too_few', __( 'translations needs at least two objects in different languages.', 'linguator-pro' ) );
		}

		$saved = $is_term ? linguator_save_term_translations( $group ) : linguator_save_post_translations( $group );

		return array( 'translations' => $saved );
	}

	/**
	 * Removes an object from its translation group.
	 *
	 * @since 1.2.0
	 * @param bool $is_term Whether the object is a term.
	 * @param int  $id      Object ID.
	 * @return array<string, mixed>|WP_Error Result.
	 */
	private static function unlink( $is_term, $id ) {
		if ( $id < 1 ) {
			return new WP_Error( 'lmat_missing_id', __( 'id is required.', 'linguator-pro' ) );
		}

		if ( ! self::can_edit( $is_term, $id ) ) {
			return new WP_Error( 'lmat_forbidden', __( 'You are not allowed to change this object.', 'linguator-pro' ) );
		}

		$language = self::language_of( $is_term, $id );

		if ( '' === $language ) {
			return new WP_Error( 'lmat_no_language', __( 'The object has no language.', 'linguator-pro' ) );
		}

		// A group that holds only the object itself is the object on its own.
		$alone = array( $language => $id );
		$saved = $is_term ? linguator_save_term_translations( $alone ) : linguator_save_post_translations( $alone );

		return array(
			'id'           => $id,
			'translations' => $saved,
		);
	}

	/**
	 * Gives objects a language.
	 *
	 * @since 1.2.0
	 * @param bool   $is_term  Whether the objects are terms.
	 * @param mixed  $ids      Object IDs, as a list or comma-separated.
	 * @param string $language Language slug.
	 * @return array<string, mixed>|WP_Error Result.
	 */
	private static function assign_language( $is_term, $ids, $language ) {
		$target = Linguator_Guard::language( $language );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$ids = is_array( $ids ) ? $ids : array_filter( array_map( 'trim', explode( ',', (string) $ids ) ) );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) ) {
			return new WP_Error( 'lmat_missing_ids', __( 'ids is required.', 'linguator-pro' ) );
		}

		$assigned = array();
		$refused  = array();

		foreach ( $ids as $id ) {
			if ( ! self::can_edit( $is_term, $id ) ) {
				$refused[] = $id;
				continue;
			}

			$is_term ? linguator_set_term_language( $id, $target ) : linguator_set_post_language( $id, $target );
			$assigned[] = $id;
		}

		return array(
			'language' => (string) $target->slug,
			'assigned' => $assigned,
			'refused'  => $refused,
		);
	}
}
