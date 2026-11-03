<?php
/**
 * Translation-group writes for the abilities subsystem.
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
 * Assigns languages and rewires WPML translation groups.
 *
 * Every change goes through the wpml_set_element_language_details action, the
 * same one Create_Translated_Post fires, so WPML owns the icl_translations
 * write. Nothing here touches the table directly.
 *
 * Each operation plans first and reports that plan, applies only when the
 * caller passes dry_run false, and re-reads the group afterwards so the result
 * is observed rather than assumed.
 *
 * @since 1.5.0
 */
final class Link_Service {

	/**
	 * Assigns a language to a post.
	 *
	 * Intended for content that has no language yet, which happens when a post
	 * type is switched on for translation after the content already existed.
	 * Changing the language of a post that is already in a group would detach
	 * it, so that is refused unless the caller asks for it explicitly.
	 *
	 * @since 1.5.0
	 * @param int    $post_id  Post to assign.
	 * @param string $language Language code to assign.
	 * @param bool   $dry_run  When true, report the plan without applying it.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function set_language( $post_id, $language, $dry_run = true ) {
		$prepared = self::prepare( $post_id, $language );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$post    = $prepared['post'];
		$details = $prepared['details'];

		/*
		 * A post with no translation group (trid 0) still reports the site's default
		 * language, because the language is read through a fallback. Treating that
		 * as "already assigned" would leave it without a group for good, so only a
		 * post that really belongs to a group is left alone.
		 */
		if ( '' !== $details['language_code'] && $details['language_code'] === $language && 0 !== (int) $details['trid'] ) {
			return array(
				'action'   => 'set_language',
				'applied'  => false,
				'post_id'  => $prepared['post_id'],
				'language' => $language,
				'message'  => sprintf(
					/* translators: %s: language code. */
					__( 'That post is already in "%s". Nothing to change.', 'wpml-translation-check-pro' ),
					$language
				),
			);
		}

		if ( '' !== $details['language_code'] ) {
			return new WP_Error(
				'automlp_already_has_language',
				sprintf(
					/* translators: 1: current language code, 2: requested language code. */
					__( 'That post is already in "%1$s" and belongs to a translation group. Changing it to "%2$s" would detach it from its translations, which is almost never what is wanted. If the post really is in the wrong language, tell the user what would happen and use the "unlink" action deliberately instead.', 'wpml-translation-check-pro' ),
					$details['language_code'],
					$language
				)
			);
		}

		$plan = array(
			'action'       => 'set_language',
			'post_id'      => $prepared['post_id'],
			'post_title'   => (string) get_the_title( $prepared['post_id'] ),
			'element_type' => 'post_' . $post->post_type,
			'from'         => '',
			'to'           => $language,
			'new_group'    => true,
		);

		if ( $dry_run ) {
			return self::as_dry_run( $plan );
		}

		self::apply( $prepared['post_id'], (string) $post->post_type, null, $language, '' );

		return self::as_applied( $plan, $prepared['post_id'] );
	}

	/**
	 * Links a post into another post's translation group.
	 *
	 * @since 1.5.0
	 * @param int  $post_id    Post to link.
	 * @param int  $link_to_id Post whose group to join.
	 * @param bool $dry_run    When true, report the plan without applying it.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function link( $post_id, $link_to_id, $dry_run = true ) {
		$post_id    = absint( $post_id );
		$link_to_id = absint( $link_to_id );

		if ( $post_id === $link_to_id ) {
			return new WP_Error(
				'automlp_link_to_self',
				__( 'A post cannot be linked to itself. Pass the post in the other language as link_to_post_id.', 'wpml-translation-check-pro' )
			);
		}

		$source = Translation_Service::get_element_language_details( $post_id );
		$target = Translation_Service::get_element_language_details( $link_to_id );

		if ( null === $source || null === $target ) {
			return new WP_Error(
				'automlp_not_found',
				__( 'One of those posts was not found. Both post_id and link_to_post_id must exist.', 'wpml-translation-check-pro' )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'edit_post', $link_to_id ) ) {
			return new WP_Error(
				'automlp_forbidden',
				__( 'The current user must be able to edit both posts to change how they are linked.', 'wpml-translation-check-pro' )
			);
		}

		if ( $source['post_type'] !== $target['post_type'] ) {
			return new WP_Error(
				'automlp_type_mismatch',
				sprintf(
					/* translators: 1: first post type, 2: second post type. */
					__( 'WPML only links content of the same type. Those posts are "%1$s" and "%2$s".', 'wpml-translation-check-pro' ),
					$source['post_type'],
					$target['post_type']
				)
			);
		}

		if ( '' === $source['language_code'] ) {
			return new WP_Error(
				'automlp_no_language',
				__( 'The post being linked has no language assigned, so WPML cannot place it in a group. Give it a language first with the "set_language" action.', 'wpml-translation-check-pro' )
			);
		}

		if ( 0 === $target['trid'] ) {
			return new WP_Error(
				'automlp_no_target_group',
				__( 'The post to link to has no translation group of its own yet. Give it a language first with the "set_language" action.', 'wpml-translation-check-pro' )
			);
		}

		if ( $source['language_code'] === $target['language_code'] ) {
			return new WP_Error(
				'automlp_same_language',
				sprintf(
					/* translators: %s: language code. */
					__( 'Both posts are in "%s". A translation group holds one post per language, so linking them would make one replace the other.', 'wpml-translation-check-pro' ),
					$source['language_code']
				)
			);
		}

		// A group holds one post per language; refuse to silently displace one.
		$group = Translation_Service::get_post_translations( $link_to_id );

		if ( is_array( $group ) && isset( $group['translations'][ $source['language_code'] ] ) ) {
			$occupant = $group['translations'][ $source['language_code'] ];

			if ( (int) $occupant['post_id'] !== $post_id ) {
				return new WP_Error(
					'automlp_language_occupied',
					sprintf(
						/* translators: 1: language code, 2: occupying post ID, 3: its title. */
						__( 'That group already has a "%1$s" version: post %2$d, "%3$s". Linking this post would displace it. Tell the user which one they want to keep, and unlink the other first.', 'wpml-translation-check-pro' ),
						$source['language_code'],
						(int) $occupant['post_id'],
						(string) $occupant['title']
					)
				);
			}
		}

		$plan = array(
			'action'          => 'link',
			'post_id'         => $post_id,
			'post_title'      => $source['title'],
			'link_to_post_id' => $link_to_id,
			'language'        => $source['language_code'],
			'from_trid'       => $source['trid'],
			'to_trid'         => $target['trid'],
			'source_language' => $target['language_code'],
		);

		if ( $source['trid'] === $target['trid'] ) {
			return array(
				'action'  => 'link',
				'applied' => false,
				'post_id' => $post_id,
				'message' => __( 'Those posts are already in the same translation group. Nothing to change.', 'wpml-translation-check-pro' ),
			);
		}

		if ( $dry_run ) {
			return self::as_dry_run( $plan );
		}

		self::apply(
			$post_id,
			$source['post_type'],
			$target['trid'],
			$source['language_code'],
			$target['language_code']
		);

		return self::as_applied( $plan, $post_id );
	}

	/**
	 * Detaches a post from its translation group.
	 *
	 * The post keeps its language and its content; it simply stops being a
	 * translation of anything. Nothing is deleted.
	 *
	 * @since 1.5.0
	 * @param int  $post_id Post to detach.
	 * @param bool $dry_run When true, report the plan without applying it.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function unlink( $post_id, $dry_run = true ) {
		$post_id = absint( $post_id );
		$details = Translation_Service::get_element_language_details( $post_id );

		if ( null === $details ) {
			return new WP_Error(
				'automlp_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d was not found.', 'wpml-translation-check-pro' ),
					$post_id
				)
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'automlp_forbidden',
				__( 'The current user cannot edit that post.', 'wpml-translation-check-pro' )
			);
		}

		if ( '' === $details['language_code'] ) {
			return new WP_Error(
				'automlp_no_language',
				__( 'That post has no language assigned, so it is not in a translation group and there is nothing to unlink.', 'wpml-translation-check-pro' )
			);
		}

		$group = Translation_Service::get_post_translations( $post_id );
		$size  = is_array( $group ) ? (int) $group['total'] : 0;

		if ( $size <= 1 ) {
			return array(
				'action'  => 'unlink',
				'applied' => false,
				'post_id' => $post_id,
				'message' => __( 'That post is not linked to any other language, so there is nothing to unlink.', 'wpml-translation-check-pro' ),
			);
		}

		$plan = array(
			'action'          => 'unlink',
			'post_id'         => $post_id,
			'post_title'      => $details['title'],
			'language'        => $details['language_code'],
			'from_trid'       => $details['trid'],
			'group_size'      => $size,
			'remaining_after' => $size - 1,
			'note'            => __( 'The post keeps its language and content. It stops being a translation of the others; nothing is deleted.', 'wpml-translation-check-pro' ),
		);

		if ( $dry_run ) {
			return self::as_dry_run( $plan );
		}

		self::apply( $post_id, $details['post_type'], null, $details['language_code'], '' );

		return self::as_applied( $plan, $post_id );
	}

	/**
	 * Shared validation for single-post language operations.
	 *
	 * @since 1.5.0
	 * @param int    $post_id  Post to validate.
	 * @param string $language Language code to validate.
	 * @return array<string, mixed>|WP_Error Prepared state, or an error.
	 */
	private static function prepare( $post_id, $language ) {
		$post_id  = absint( $post_id );
		$language = sanitize_text_field( (string) $language );

		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$post = $post_id > 0 ? get_post( $post_id ) : null;

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

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'automlp_forbidden',
				__( 'The current user cannot edit that post.', 'wpml-translation-check-pro' )
			);
		}

		if ( ! Language_Service::is_active( $language ) ) {
			return new WP_Error(
				'automlp_language_inactive',
				sprintf(
					/* translators: 1: requested language code, 2: comma-separated active codes. */
					__( '"%1$s" is not an active language on this site. Active languages are: %2$s. Call list-languages and use one of those codes.', 'wpml-translation-check-pro' ),
					$language,
					implode( ', ', Language_Service::get_codes() )
				)
			);
		}

		if ( ! Content_Service::is_post_type_translatable( $post->post_type ) ) {
			return new WP_Error(
				'automlp_type_not_translated',
				sprintf(
					/* translators: %s: post type slug. */
					__( 'WPML is not set to translate "%s", so its content cannot be given a language. Call list-content-types and pass on its notice: a site administrator has to switch the type on under WPML > Settings > Post Types Translation.', 'wpml-translation-check-pro' ),
					$post->post_type
				)
			);
		}

		$details = Translation_Service::get_element_language_details( $post_id );

		if ( null === $details ) {
			return new WP_Error(
				'automlp_details_unavailable',
				__( 'WPML could not report that post\'s language details, so nothing was changed.', 'wpml-translation-check-pro' )
			);
		}

		return array(
			'post_id' => $post_id,
			'post'    => $post,
			'details' => $details,
		);
	}

	/**
	 * Fires WPML's language-details write.
	 *
	 * @since 1.5.0
	 * @param int      $post_id     Element to write.
	 * @param string   $post_type   Its post type.
	 * @param int|null $trid        Group to join, or null for a new group.
	 * @param string   $language    Language code for the element.
	 * @param string   $source_lang Source language, or '' for an original.
	 * @return void
	 */
	private static function apply( $post_id, $post_type, $trid, $language, $source_lang ) {
		do_action( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'wpml_set_element_language_details',
			array(
				'element_id'           => absint( $post_id ),
				'element_type'         => 'post_' . $post_type,
				'trid'                 => null === $trid ? null : absint( $trid ),
				'language_code'        => (string) $language,
				'source_language_code' => '' === (string) $source_lang ? null : (string) $source_lang,
			)
		);

		clean_post_cache( absint( $post_id ) );
	}

	/**
	 * Wraps a plan as an unapplied dry-run result.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $plan The planned change.
	 * @return array<string, mixed> Dry-run payload.
	 */
	private static function as_dry_run( array $plan ) {
		return array_merge(
			$plan,
			array(
				'applied' => false,
				'dry_run' => true,
				'message' => __( 'Nothing was changed. This is what would happen. Show it to the user and call again with dry_run false to apply it.', 'wpml-translation-check-pro' ),
			)
		);
	}

	/**
	 * Wraps a plan as an applied result, with the group re-read afterwards.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $plan    The change that was made.
	 * @param int                  $post_id Post to re-read.
	 * @return array<string, mixed> Applied payload.
	 */
	private static function as_applied( array $plan, $post_id ) {
		/*
		 * The group was read (and cached by WPML) while building the plan, so
		 * without this the "result" shows the group as it was before the write.
		 * Found on a live run: a successful link reported the post as missing.
		 */
		do_action( 'wpml_cache_clear' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$group = Translation_Service::get_post_translations( $post_id );

		return array_merge(
			$plan,
			array(
				'applied' => true,
				'dry_run' => false,
				'result'  => is_array( $group ) ? $group : array(),
			)
		);
	}
}
