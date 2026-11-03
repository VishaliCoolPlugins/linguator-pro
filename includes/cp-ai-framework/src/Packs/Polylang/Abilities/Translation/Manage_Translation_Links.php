<?php
/**
 * Translation group maintenance ability.
 *
 * @package AutoPoly
 * @since   1.11.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Translation_Link_Service;
use WP_Error;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets an item's language, links items as translations, or unlinks one.
 *
 * @since 1.11.0
 */
final class Manage_Translation_Links extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'manage-translation-links';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Manage Translation Links', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: repair how Polylang links content across languages, for content that translate-post did not create. Action "set_language" changes the language of one post or term. Action "link" adds items to the translation group of "id", given as {"hi":63,"fr":210}. Action "unlink" detaches "id" from its group and leaves the rest linked. Changes are merged into the current group, never replace it, and are refused wherever Polylang would silently drop another link: a language already taken in the group (pass "replace": true to displace that member), an item already linked elsewhere (pass "move_from_other_group": true to move it), a language key that does not match the item\'s real language, or a different post type or taxonomy. An item that is a language\'s static front page, posts page or default category cannot be moved. Action "assign_language" gives a language to the items listed in "ids" that have none, such as Elementor templates created before their type was switched on for translation (list those with get-untranslated-content, language_state "no_language"). It never changes an item that already has a language; those are listed under "skipped". This is a dry run by default: it shows the group before and after and changes nothing; call again with dry_run false to apply. Changing a published page\'s language moves its URL.', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'                => array(
					'type'        => 'string',
					'enum'        => Translation_Link_Service::ACTIONS,
					'description' => __( '"set_language", "link", "unlink" or "assign_language".', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'object_type'           => array(
					'type'        => 'string',
					'enum'        => Translation_Link_Service::OBJECT_TYPES,
					'description' => __( '"post" for posts, pages and other post types; "term" for categories, tags and other taxonomies.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'id'                    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The post or term to act on. For "link", the item whose translation group is extended. Required by every action except "assign_language".', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'language'              => array(
					'type'        => 'string',
					'description' => __( 'Language slug to assign. Used by "set_language" and "assign_language".', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'ids'                   => array(
					// String first; see the note on Get_Glossary::target_langs.
					'type'        => array( 'string', 'array' ),
					'items'       => array( 'type' => 'integer' ),
					'description' => __( 'Used by "assign_language" only: the posts or terms to give a language, at most 100. Items that already have a language are skipped, never changed.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'translations'          => array(
					// String first; see the note on Get_Glossary::target_langs.
					'type'                 => array( 'string', 'object' ),
					'additionalProperties' => array( 'type' => array( 'integer', 'string' ) ),
					'description'          => __( 'Used by "link" only: items to link, keyed by their language slug, for example {"hi":63,"fr":210}. Each item must already be in that language and of the same post type or taxonomy as "id". Members of the group you do not list stay linked.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'replace'               => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'For "link": when the group already has a member in a listed language, unlink that member and link the new one. Defaults to false, which refuses.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'move_from_other_group' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'For "link": when a listed item is already linked to other translations, move it into this group. Its old group stays linked together without it. Defaults to false, which refuses.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'dry_run'               => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'When true, the default, report what would change and change nothing. Set to false to apply.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
			),
			// "id" is enforced in execute() for the actions that use it; assign_language does not.
			'required'   => array( 'action', 'object_type' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Destructive: unlinking removes links, and linking with "replace" or
	 * "move_from_other_group" removes others.
	 *
	 * @since 1.11.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error Preview or result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		$action  = isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : '';
		$type    = isset( $input['object_type'] ) ? sanitize_key( (string) $input['object_type'] ) : '';
		$id      = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$dry_run = isset( $input['dry_run'] ) ? rest_sanitize_boolean( $input['dry_run'] ) : true;

		if ( 'assign_language' === $action && in_array( $type, Translation_Link_Service::OBJECT_TYPES, true ) ) {
			return self::execute_assign_language( $input, $type, $dry_run );
		}

		if ( ! in_array( $action, Translation_Link_Service::ACTIONS, true ) || ! in_array( $type, Translation_Link_Service::OBJECT_TYPES, true ) || 0 === $id ) {
			return new WP_Error(
				'atfpp_links_invalid_input',
				__( '"action", "object_type" and a positive "id" are required.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		if ( 'set_language' === $action ) {
			$language = isset( $input['language'] ) ? (string) $input['language'] : '';

			if ( '' === trim( $language ) ) {
				return new WP_Error(
					'atfpp_links_invalid_input',
					__( '"language" is required for action "set_language".', 'autopoly-ai-translation-for-polylang-pro' )
				);
			}

			$plan = Translation_Link_Service::plan_set_language( $type, $id, $language );
		} elseif ( 'link' === $action ) {
			$translations = self::decode_translations( isset( $input['translations'] ) ? $input['translations'] : null );

			if ( null === $translations ) {
				return new WP_Error(
					'atfpp_links_invalid_input',
					__( '"translations" is required for action "link" and must map language slugs to positive integer IDs, for example {"hi":63}.', 'autopoly-ai-translation-for-polylang-pro' )
				);
			}

			$plan = Translation_Link_Service::plan_link(
				$type,
				$id,
				$translations,
				! empty( $input['replace'] ) && rest_sanitize_boolean( $input['replace'] ),
				! empty( $input['move_from_other_group'] ) && rest_sanitize_boolean( $input['move_from_other_group'] )
			);
		} else {
			$plan = Translation_Link_Service::plan_unlink( $type, $id );
		}

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		/*
		 * check_permission() could only see the IDs in the input. The plan also
		 * names every group member the change touches, and the caller must be
		 * able to edit each of them before anything about them is shown or
		 * written.
		 */
		$capability = 'term' === $type ? 'edit_term' : 'edit_post';

		foreach ( $plan['involved'] as $involved ) {
			if ( ! self::user_can( $capability, $input, (int) $involved ) ) {
				// No ID in the message: it would disclose items the caller cannot edit.
				return new WP_Error(
					'atfpp_links_forbidden',
					__( 'This change would also affect linked items you are not allowed to edit, so it was refused.', 'autopoly-ai-translation-for-polylang-pro' ),
					array( 'status' => 403 )
				);
			}
		}

		$payload = Translation_Link_Service::present( $plan );

		if ( $dry_run ) {
			$payload['dry_run']     = true;
			$payload['would_apply'] = empty( $plan['noop'] ) && empty( $plan['conflicts'] );
			$payload['notice']      = __( 'Dry run: nothing was changed. "before" and "after" show the translation group; "changes" lists what would be added, displaced, moved or unlinked; anything in "conflicts" must be resolved first, and "would_apply" says whether it can be applied as it stands. Call again with dry_run false to apply.', 'autopoly-ai-translation-for-polylang-pro' );

			return $payload;
		}

		if ( ! empty( $plan['noop'] ) ) {
			$payload['success'] = true;
			$payload['changed'] = false;

			return $payload;
		}

		if ( ! empty( $plan['conflicts'] ) ) {
			return new WP_Error(
				'atfpp_links_conflict',
				implode( ' ', wp_list_pluck( $plan['conflicts'], 'message' ) ),
				array( 'conflicts' => $plan['conflicts'] )
			);
		}

		return Translation_Link_Service::apply( $plan );
	}

	/**
	 * Runs action "assign_language".
	 *
	 * @since 1.13.0
	 * @param array<string, mixed> $input   Validated input.
	 * @param string               $type    "post" or "term".
	 * @param bool                 $dry_run Whether to only report.
	 * @return array<string, mixed>|WP_Error Preview or result payload, or an error.
	 */
	private static function execute_assign_language( array $input, $type, $dry_run ) {
		$language = isset( $input['language'] ) ? (string) $input['language'] : '';

		if ( '' === trim( $language ) ) {
			return new WP_Error(
				'atfpp_links_invalid_input',
				__( '"language" is required for action "assign_language".', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		/*
		 * No second permission pass is needed: check_permission() checked every
		 * ID, and an item without a language has no translation group, so a
		 * plan never involves any other item.
		 */
		$batch = Translation_Link_Service::plan_assign_language(
			$type,
			self::decode_ids( isset( $input['ids'] ) ? $input['ids'] : null ),
			$language
		);

		if ( is_wp_error( $batch ) ) {
			return $batch;
		}

		if ( ! $dry_run ) {
			return Translation_Link_Service::apply_assignment( $batch );
		}

		$payload = Translation_Link_Service::present_assignment( $batch );

		$payload['dry_run']     = true;
		$payload['would_apply'] = $payload['ready'] > 0;
		$payload['notice']      = __( 'Dry run: nothing was changed. "items" lists what would be given the language; an item with "conflicts" will be skipped. "skipped" lists items left alone, including any that already have a language. Call again with dry_run false to apply.', 'autopoly-ai-translation-for-polylang-pro' );

		return $payload;
	}

	/**
	 * Decodes the "ids" list, however the client encoded it.
	 *
	 * @since 1.13.0
	 * @param mixed $value Raw input.
	 * @return int[] Positive integer IDs; empty when absent.
	 */
	private static function decode_ids( $value ) {
		return null === $value ? array() : array_values( array_unique( array_filter( array_map( 'absint', self::normalize_list( $value ) ) ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Edit rights on the item and on every item named in "translations",
	 * matching translate-post and translate-term. execute() then checks every
	 * other group member the change would touch, which cannot be known here.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		$type = isset( $input['object_type'] ) ? sanitize_key( (string) $input['object_type'] ) : '';
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		// The same check as set_language, on each listed item.
		if ( isset( $input['action'] ) && 'assign_language' === sanitize_key( (string) $input['action'] ) ) {
			$ids = self::decode_ids( isset( $input['ids'] ) ? $input['ids'] : null );

			foreach ( $ids as $object_id ) {
				$single = array(
					'action'      => 'set_language',
					'object_type' => $type,
					'id'          => $object_id,
				);

				if ( ! self::check_permission( $single ) ) {
					return false;
				}
			}

			return array() !== $ids;
		}

		if ( 0 === $id || ! in_array( $type, Translation_Link_Service::OBJECT_TYPES, true ) ) {
			return false;
		}

		$ids          = array( $id );
		$translations = self::decode_translations( isset( $input['translations'] ) ? $input['translations'] : null );

		if ( null !== $translations ) {
			foreach ( $translations as $member ) {
				$ids[] = (int) $member;
			}
		}

		$capability = 'term' === $type ? 'edit_term' : 'edit_post';

		foreach ( array_unique( $ids ) as $object_id ) {
			if ( ! self::user_can( $capability, $input, $object_id ) ) {
				return false;
			}
		}

		if ( 'term' !== $type ) {
			return true;
		}

		$term = get_term( $id );

		if ( ! $term instanceof WP_Term ) {
			return false;
		}

		$taxonomy = get_taxonomy( $term->taxonomy );

		return false !== $taxonomy && self::user_can( $taxonomy->cap->edit_terms, $input );
	}

	/**
	 * Decodes the "translations" map, however the client encoded it.
	 *
	 * Rejects anything that is not a language => positive integer map, rather
	 * than guessing, because the values name the items to be relinked.
	 *
	 * @since 1.11.0
	 * @param mixed $value Raw input.
	 * @return array<string, int>|null Map, or null when absent or malformed.
	 */
	private static function decode_translations( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		} elseif ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}

		if ( ! is_array( $value ) || array() === $value ) {
			return null;
		}

		$map = array();

		foreach ( $value as $slug => $member ) {
			if ( is_int( $member ) ) {
				$member_id = $member;
			} elseif ( is_string( $member ) && ctype_digit( trim( $member ) ) ) {
				$member_id = (int) trim( $member );
			} else {
				return null;
			}

			if ( $member_id <= 0 || ! is_string( $slug ) ) {
				return null;
			}

			$map[ $slug ] = $member_id;
		}

		return $map;
	}
}
