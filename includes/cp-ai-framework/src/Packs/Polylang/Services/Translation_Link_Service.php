<?php
/**
 * Translation group maintenance: assign a language, link, unlink.
 *
 * Polylang records which posts (or terms) translate one another as a
 * "translation group": a post_translations / term_translations term whose
 * description holds a language-keyed map of object IDs. Its own UI edits these
 * groups from the Language box in the editor. This service does the same
 * through Polylang's model, and adds the guards an automated caller needs,
 * because several Polylang operations change more than the object named:
 *
 *   - save_translations() unlinks every member of every involved group that is
 *     missing from the new map, so a partial map silently breaks links. Maps
 *     are therefore always built by merging into the current group, never
 *     taken from the caller as they are.
 *   - It works out what to unlink from a language-keyed merge of every
 *     involved group. When two groups both hold, say, an English member, one
 *     hides the other, which is then never unlinked and keeps pointing at a
 *     group that no longer lists it. Objects leaving a group are therefore
 *     detached one at a time with delete_translation() before the final save.
 *   - validate_translations() drops, without error, any entry whose language
 *     key does not match the object's language. Every entry is checked up
 *     front instead, so the caller gets a reason rather than a partial save.
 *   - set_language() keeps the object in its group; when the group already has
 *     a member in the new language, one of the two silently falls out. That is
 *     refused.
 *   - Polylang does not check that linked objects share a post type or
 *     taxonomy. This does.
 *
 * Every change is planned first. The plan lists every object whose group
 * changes, so the caller's rights can be checked on each before anything is
 * shown or written, and a dry run returns the plan unchanged. After a write
 * the groups are re-read and compared with the plan, so a partial outcome is
 * reported rather than assumed.
 *
 * @package AutoPoly
 * @since   1.11.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Services;

use PLL_Language;
use WP_Error;
use WP_Post;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plans and applies changes to Polylang translation groups.
 *
 * @since 1.11.0
 */
final class Translation_Link_Service {

	/**
	 * Supported actions.
	 *
	 * @since 1.11.0
	 * @var string[]
	 */
	const ACTIONS = array( 'set_language', 'link', 'unlink', 'assign_language' );

	/**
	 * Most items one assign_language call may touch.
	 *
	 * @since 1.13.0
	 * @var int
	 */
	const ASSIGN_MAX = 100;

	/**
	 * Supported object types.
	 *
	 * @since 1.11.0
	 * @var string[]
	 */
	const OBJECT_TYPES = array( 'post', 'term' );

	/**
	 * AutoPoly post meta naming the source a translation is tracked against.
	 *
	 * Read only to decide whether ATFP_Re_Translation's own cleanup is needed.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const TRACKING_SOURCE_META = '_atfpp_updated_post_id';

	/**
	 * Checks that Polylang's translation models can be used.
	 *
	 * @since 1.11.0
	 * @return true|WP_Error True when ready.
	 */
	public static function check_ready() {
		if ( ! Polylang_Guard::model_ready() || ! function_exists( 'pll_is_translated_post_type' ) || ! function_exists( 'pll_is_translated_taxonomy' ) ) {
			return Polylang_Guard::unavailable_error();
		}

		$model = PLL()->model;

		if ( ! isset( $model->post, $model->term ) || ! is_object( $model->post ) || ! is_object( $model->term ) ) {
			return Polylang_Guard::unavailable_error();
		}

		return true;
	}

	/**
	 * Plans a change of language for one object.
	 *
	 * @since 1.11.0
	 * @param string $type     "post" or "term".
	 * @param int    $id       Object ID.
	 * @param string $language Target language slug.
	 * @return array<string, mixed>|WP_Error The plan, or an error.
	 */
	public static function plan_set_language( $type, $id, $language ) {
		$ready = self::check_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$object = self::load( $type, $id );

		if ( is_wp_error( $object ) ) {
			return $object;
		}

		$target = Language_Service::get_by_slug( (string) $language );

		if ( null === $target ) {
			return new WP_Error(
				'atfpp_links_unknown_language',
				sprintf(
					/* translators: %s: language slug. */
					__( 'No Polylang language has the slug "%s".', 'autopoly-ai-translation-for-polylang-pro' ),
					sanitize_key( (string) $language )
				)
			);
		}

		$id      = $object['id'];
		$slug    = (string) $target->slug;
		$current = self::language_of( $type, $id );
		$group   = self::group_of( $type, $id );
		$plan    = self::new_plan( 'set_language', $type, $object, $group );

		$plan['language'] = $slug;

		if ( $current === $slug ) {
			$plan['noop']    = true;
			$plan['message'] = sprintf(
				/* translators: %s: language slug. */
				__( 'The item is already in language "%s". Nothing to change.', 'autopoly-ai-translation-for-polylang-pro' ),
				$slug
			);

			return $plan;
		}

		if ( 'trash' === $object['status'] ) {
			return self::trashed_error( $id );
		}

		$others = self::without( $group, $id );

		$plan['involved'] = self::merge_ids( array( $id ), array_values( $group ) );

		self::check_integrity( $plan, $type, $id, $group );

		if ( isset( $others[ $slug ] ) ) {
			$plan['conflicts'][] = self::conflict(
				'language_taken',
				sprintf(
					/* translators: 1: language slug, 2: object ID. */
					__( 'This item\'s translation group already has a "%1$s" member (ID %2$d). Changing the language would drop one of them from the group. Unlink one of them first.', 'autopoly-ai-translation-for-polylang-pro' ),
					$slug,
					$others[ $slug ]
				),
				array( $others[ $slug ] )
			);
		}

		/*
		 * Only an item that already has a language can be moved out of its group.
		 * A front page or posts page with no language yet has no group to leave,
		 * and giving it its first language is the repair, not a break.
		 */
		if ( '' !== $current ) {
			self::check_protected( $plan, $type, array( $id ) );
		}

		if ( 'term' === $type ) {
			self::check_term_slug( $plan, $object, $slug );
		}

		$expected          = $others;
		$expected[ $slug ] = $id;
		ksort( $expected );

		$plan['after']   = $expected;
		$plan['changes'] = array(
			'language_from' => $current,
			'language_to'   => $slug,
		);

		if ( 'post' === $type && 'publish' === $object['status'] ) {
			$plan['warnings'][] = __( 'This item is published, so its URL moves to the new language\'s section of the site, and menus built for the old language no longer lead to it.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		if ( $object['parent'] > 0 ) {
			$parent_lang = self::language_of( $type, $object['parent'] );

			if ( '' !== $parent_lang && $parent_lang !== $slug ) {
				$plan['warnings'][] = sprintf(
					/* translators: 1: parent ID, 2: parent language slug. */
					__( 'Its parent (ID %1$d) is in "%2$s", a different language. Polylang expects parents and children to share a language.', 'autopoly-ai-translation-for-polylang-pro' ),
					$object['parent'],
					$parent_lang
				);
			}
		}

		return $plan;
	}

	/**
	 * Plans giving a language to items that have none.
	 *
	 * Only ever acts on items without a language. An item that already has one
	 * is reported under "skipped" and left alone, so a batch can never move a
	 * published page to another language or break a translation group; that
	 * stays a deliberate, one-item set_language. Each remaining item is planned
	 * with plan_set_language(), so it goes through exactly the same checks.
	 *
	 * @since 1.13.0
	 * @param string $type     "post" or "term".
	 * @param int[]  $ids      Items to assign.
	 * @param string $language Language slug to assign.
	 * @return array<string, mixed>|WP_Error The batch plan, or an error.
	 */
	public static function plan_assign_language( $type, array $ids, $language ) {
		$ready = self::check_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$target = Language_Service::get_by_slug( (string) $language );

		if ( null === $target ) {
			return new WP_Error(
				'atfpp_links_unknown_language',
				sprintf(
					/* translators: %s: language slug. */
					__( 'No Polylang language has the slug "%s".', 'autopoly-ai-translation-for-polylang-pro' ),
					sanitize_key( (string) $language )
				)
			);
		}

		$candidates = self::merge_ids( $ids );

		if ( array() === $candidates ) {
			return new WP_Error(
				'atfpp_links_invalid_input',
				__( 'Action "assign_language" needs "ids": the posts or terms to give a language. List posts without one with get-untranslated-content and language_state "no_language".', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		if ( count( $candidates ) > self::ASSIGN_MAX ) {
			return new WP_Error(
				'atfpp_links_too_many',
				sprintf(
					/* translators: %d: maximum number of items. */
					__( 'At most %d items can be assigned in one call. Split the list.', 'autopoly-ai-translation-for-polylang-pro' ),
					self::ASSIGN_MAX
				)
			);
		}

		$slug    = (string) $target->slug;
		$plans   = array();
		$skipped = array();

		foreach ( $candidates as $id ) {
			// A missing or untranslatable item has no language here, and plan_set_language() reports why.
			$current = self::language_of( $type, $id );

			if ( '' !== $current ) {
				$skipped[] = self::skip(
					$id,
					'has_language',
					sprintf(
						/* translators: %s: language slug. */
						__( 'This item already has the language "%s", so it was left unchanged. assign_language only gives a language to items that have none; use set_language to change one.', 'autopoly-ai-translation-for-polylang-pro' ),
						$current
					)
				);
				continue;
			}

			$plan = self::plan_set_language( $type, $id, $slug );

			if ( is_wp_error( $plan ) ) {
				$skipped[] = self::skip( $id, $plan->get_error_code(), $plan->get_error_message() );
				continue;
			}

			$plans[] = $plan;
		}

		return array(
			'action'      => 'assign_language',
			'object_type' => $type,
			'language'    => $slug,
			'plans'       => $plans,
			'skipped'     => $skipped,
		);
	}

	/**
	 * Describes a batch plan for the caller.
	 *
	 * check_permission() has already checked the caller's rights on every ID,
	 * so titles and statuses can be shown.
	 *
	 * @since 1.13.0
	 * @param array<string, mixed> $batch A plan from plan_assign_language().
	 * @return array<string, mixed> Payload.
	 */
	public static function present_assignment( array $batch ) {
		$type     = $batch['object_type'];
		$items    = array();
		$warnings = array();
		$ready    = 0;

		foreach ( $batch['plans'] as $plan ) {
			$items[] = array(
				'object'    => self::describe( $type, (int) $plan['object']['id'] ),
				'conflicts' => $plan['conflicts'],
			);

			if ( empty( $plan['conflicts'] ) ) {
				++$ready;
			}

			$warnings = array_merge( $warnings, $plan['warnings'] );
		}

		return array(
			'action'      => 'assign_language',
			'object_type' => $type,
			'language'    => $batch['language'],
			'items'       => $items,
			'ready'       => $ready,
			'skipped'     => $batch['skipped'],
			'warnings'    => array_values( array_unique( $warnings ) ),
		);
	}

	/**
	 * Applies every conflict-free plan in a batch, each verified as set_language verifies it.
	 *
	 * Items are written one at a time through apply(), so one failure is
	 * reported against its item and does not stop or undo the others.
	 *
	 * @since 1.13.0
	 * @param array<string, mixed> $batch A plan from plan_assign_language().
	 * @return array<string, mixed> Result payload.
	 */
	public static function apply_assignment( array $batch ) {
		$type     = $batch['object_type'];
		$assigned = array();
		$failed   = array();
		$skipped  = $batch['skipped'];

		foreach ( $batch['plans'] as $plan ) {
			$id = (int) $plan['object']['id'];

			if ( ! empty( $plan['conflicts'] ) ) {
				$skipped[] = self::skip( $id, 'conflict', implode( ' ', wp_list_pluck( $plan['conflicts'], 'message' ) ) );
				continue;
			}

			$result = self::apply( $plan );

			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'object'  => self::describe( $type, $id ),
					'reason'  => $result->get_error_code(),
					'message' => $result->get_error_message(),
				);
				continue;
			}

			$assigned[] = self::describe( $type, $id );
		}

		return array(
			'success'     => array() === $failed,
			'action'      => 'assign_language',
			'object_type' => $type,
			'language'    => $batch['language'],
			'assigned'    => $assigned,
			'failed'      => $failed,
			'skipped'     => $skipped,
		);
	}

	/**
	 * An item left out of a batch, and why.
	 *
	 * @since 1.13.0
	 * @param int    $id      Object ID.
	 * @param string $reason  Reason code.
	 * @param string $message Explanation.
	 * @return array<string, mixed> Entry.
	 */
	private static function skip( $id, $reason, $message ) {
		return array(
			'id'      => (int) $id,
			'reason'  => (string) $reason,
			'message' => (string) $message,
		);
	}

	/**
	 * Plans linking objects into one translation group.
	 *
	 * @since 1.11.0
	 * @param string          $type                  "post" or "term".
	 * @param int             $id                    Object whose group is extended.
	 * @param array<mixed>    $requested             Language slug => object ID.
	 * @param bool            $replace               Allow displacing a member already in a requested language.
	 * @param bool            $move_from_other_group Allow taking an object out of another group.
	 * @return array<string, mixed>|WP_Error The plan, or an error.
	 */
	public static function plan_link( $type, $id, array $requested, $replace, $move_from_other_group ) {
		$ready = self::check_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$anchor = self::load( $type, $id );

		if ( is_wp_error( $anchor ) ) {
			return $anchor;
		}

		$id          = $anchor['id'];
		$anchor_lang = self::language_of( $type, $id );

		if ( '' === $anchor_lang ) {
			return new WP_Error(
				'atfpp_links_no_language',
				sprintf(
					/* translators: %d: object ID. */
					__( 'Item %d has no language, so it cannot be linked. Give it one with action "set_language" first.', 'autopoly-ai-translation-for-polylang-pro' ),
					$id
				)
			);
		}

		if ( 'trash' === $anchor['status'] ) {
			return self::trashed_error( $id );
		}

		$clean = self::validate_requested( $type, $anchor, $anchor_lang, $requested );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$current = self::group_of( $type, $id );
		$plan    = self::new_plan( 'link', $type, $anchor, $current );

		self::check_integrity( $plan, $type, $id, $current );

		$added       = array();
		$displaced   = array();
		$movers      = array();
		$left_behind = array();

		foreach ( $clean as $slug => $member ) {
			if ( isset( $current[ $slug ] ) && $current[ $slug ] === $member ) {
				continue;
			}

			$added[] = $member;

			if ( isset( $current[ $slug ] ) ) {
				$displaced[] = $current[ $slug ];
			}

			$previous = self::without( self::group_of( $type, $member ), $member );

			if ( array() !== $previous ) {
				$movers[]    = $member;
				$left_behind = self::merge_ids( $left_behind, array_values( $previous ) );
			}
		}

		// Members of a mover's old group that are themselves moving are not left behind.
		$left_behind = array_values( array_diff( $left_behind, array_values( $clean ), array_values( $current ), array( $id ) ) );

		if ( array() !== $displaced && ! $replace ) {
			$plan['conflicts'][] = self::conflict(
				'language_already_linked',
				__( 'The group already has a member in a language you asked to link, and linking would unlink that member. Pass "replace": true to displace it.', 'autopoly-ai-translation-for-polylang-pro' ),
				$displaced
			);
		}

		if ( array() !== $movers && ! $move_from_other_group ) {
			$plan['conflicts'][] = self::conflict(
				'in_other_group',
				__( 'An item you asked to link is already linked to other translations, and linking would take it out of that group. Pass "move_from_other_group": true to move it; the rest of its old group stays linked together.', 'autopoly-ai-translation-for-polylang-pro' ),
				$movers
			);
		}

		self::check_protected( $plan, $type, self::merge_ids( $displaced, $movers ) );

		$expected = $current;

		foreach ( $expected as $slug => $member ) {
			if ( in_array( $member, $displaced, true ) ) {
				unset( $expected[ $slug ] );
			}
		}

		foreach ( $clean as $slug => $member ) {
			$expected[ $slug ] = $member;
		}

		ksort( $expected );

		$plan['after']    = $expected;
		$plan['detach']   = self::merge_ids( $displaced, $movers );
		$plan['involved'] = self::merge_ids( array( $id ), array_values( $current ), array_values( $clean ), $displaced, $left_behind );
		$plan['changes']  = array(
			'added'                  => $added,
			'displaced'              => $displaced,
			'moved_from_other_group' => $movers,
			'left_behind'            => $left_behind,
		);

		if ( array() === $added ) {
			$plan['noop']    = true;
			$plan['message'] = __( 'Every requested item is already linked in this group. Nothing to change.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		return $plan;
	}

	/**
	 * Plans detaching one object from its translation group.
	 *
	 * Skips the full integrity check, because detaching is how a one-sided,
	 * stale group entry is removed and delete_translation() does that safely.
	 * The one case it cannot handle is refused: an entry for the object stored
	 * under a language other than its own. delete_translation() looks the
	 * entry up by the object's real language, finds nothing, and leaves the
	 * rest of the group still pointing at the object.
	 *
	 * @since 1.11.0
	 * @param string $type "post" or "term".
	 * @param int    $id   Object ID.
	 * @return array<string, mixed>|WP_Error The plan, or an error.
	 */
	public static function plan_unlink( $type, $id ) {
		$ready = self::check_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$object = self::load( $type, $id );

		if ( is_wp_error( $object ) ) {
			return $object;
		}

		$id     = $object['id'];
		$group  = self::group_of( $type, $id );
		$others = self::without( $group, $id );
		$plan   = self::new_plan( 'unlink', $type, $object, $group );

		$plan['involved'] = self::merge_ids( array( $id ), array_values( $group ) );

		if ( array() === $others ) {
			$plan['noop']    = true;
			$plan['message'] = __( 'The item is not linked to any translation. Nothing to change.', 'autopoly-ai-translation-for-polylang-pro' );

			return $plan;
		}

		$own = self::language_of( $type, $id );

		foreach ( $group as $slug => $member ) {
			if ( $member === $id && (string) $slug !== $own ) {
				$plan['conflicts'][] = self::conflict(
					'broken_group',
					sprintf(
						/* translators: 1: object ID, 2: language slug. */
						__( 'This group records item %1$d under "%2$s", which is not its language. Polylang cannot remove an entry stored under the wrong language, so unlinking would leave the rest of the group still pointing at it. Fix its translations in the editor instead.', 'autopoly-ai-translation-for-polylang-pro' ),
						$id,
						(string) $slug
					),
					array( $id )
				);
			}
		}

		self::check_protected( $plan, $type, array( $id ) );

		$plan['after']   = '' !== $own ? array( $own => $id ) : array();
		$plan['changes'] = array(
			'unlinked'      => array( $id ),
			'still_linked'  => array_values( $others ),
		);

		return $plan;
	}

	/**
	 * Writes a plan that has no conflicts, then verifies the result.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $plan A plan from one of the plan_* methods.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function apply( array $plan ) {
		$ready = self::check_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( ! empty( $plan['conflicts'] ) || ! empty( $plan['noop'] ) ) {
			return new WP_Error(
				'atfpp_links_not_applicable',
				__( 'This change has conflicts or nothing to do, so it was not applied.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		$type  = $plan['object_type'];
		$id    = (int) $plan['object']['id'];
		$model = self::model( $type );

		switch ( $plan['action'] ) {
			case 'set_language':
				$language = Language_Service::get_by_slug( (string) $plan['language'] );

				if ( null === $language || ! $model->set_language( $id, $language ) ) {
					return new WP_Error(
						'atfpp_links_write_failed',
						__( 'Polylang did not change the language.', 'autopoly-ai-translation-for-polylang-pro' )
					);
				}
				break;

			case 'link':
				foreach ( $plan['detach'] as $leaving ) {
					$model->delete_translation( (int) $leaving );
				}

				$model->save_translations( $id, $plan['after'] );
				break;

			case 'unlink':
				$model->delete_translation( $id );
				break;

			default:
				return new WP_Error( 'atfpp_links_invalid_action', __( 'Unknown action.', 'autopoly-ai-translation-for-polylang-pro' ) );
		}

		$failure = self::verify( $plan );

		$result = array(
			'success'          => null === $failure,
			'action'           => $plan['action'],
			'object_type'      => $type,
			'object'           => self::describe( $type, $id ),
			'before'           => self::describe_group( $type, $plan['before'] ),
			'after'            => self::describe_group( $type, self::group_of( $type, $id ) ),
			'changes'          => self::describe_changes( $type, $plan['changes'] ),
			'warnings'         => $plan['warnings'],
			'tracking_cleared' => self::clear_stale_tracking( $type, $plan['involved'] ),
		);

		if ( null !== $failure ) {
			return new WP_Error(
				'atfpp_links_verification_failed',
				$failure,
				array( 'result' => $result )
			);
		}

		return $result;
	}

	/**
	 * Converts a plan into the payload shown to the caller.
	 *
	 * Only called after the caller's rights on every involved object have been
	 * checked, because it exposes titles and statuses.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $plan A plan.
	 * @return array<string, mixed> Payload.
	 */
	public static function present( array $plan ) {
		$type = $plan['object_type'];

		$payload = array(
			'action'      => $plan['action'],
			'object_type' => $type,
			'object'      => self::describe( $type, (int) $plan['object']['id'] ),
			'before'      => self::describe_group( $type, $plan['before'] ),
			'after'       => self::describe_group( $type, $plan['after'] ),
			'changes'     => self::describe_changes( $type, $plan['changes'] ),
			'conflicts'   => $plan['conflicts'],
			'warnings'    => $plan['warnings'],
		);

		if ( ! empty( $plan['noop'] ) ) {
			$payload['message'] = $plan['message'];
		}

		return $payload;
	}

	/**
	 * Validates the language => ID map passed to link.
	 *
	 * @since 1.11.0
	 * @param string               $type        Object type.
	 * @param array<string, mixed> $anchor      Loaded anchor object.
	 * @param string               $anchor_lang Anchor language slug.
	 * @param array<mixed>         $requested   Raw map.
	 * @return array<string, int>|WP_Error Clean map without the anchor, or an error.
	 */
	private static function validate_requested( $type, array $anchor, $anchor_lang, array $requested ) {
		$clean = array();

		foreach ( $requested as $slug => $member ) {
			$slug   = sanitize_key( (string) $slug );
			$member = (int) $member;

			if ( '' === $slug || $member <= 0 ) {
				return new WP_Error(
					'atfpp_links_invalid_input',
					__( '"translations" must map language slugs to positive IDs, for example {"hi":63}.', 'autopoly-ai-translation-for-polylang-pro' )
				);
			}

			if ( null === Language_Service::get_by_slug( $slug ) ) {
				return new WP_Error(
					'atfpp_links_unknown_language',
					sprintf(
						/* translators: %s: language slug. */
						__( 'No Polylang language has the slug "%s".', 'autopoly-ai-translation-for-polylang-pro' ),
						$slug
					)
				);
			}

			if ( isset( $clean[ $slug ] ) || in_array( $member, $clean, true ) ) {
				return new WP_Error(
					'atfpp_links_invalid_input',
					__( 'Each language and each ID may appear only once in "translations".', 'autopoly-ai-translation-for-polylang-pro' )
				);
			}

			$clean[ $slug ] = $member;
		}

		if ( isset( $clean[ $anchor_lang ] ) ) {
			if ( $clean[ $anchor_lang ] !== $anchor['id'] ) {
				return new WP_Error(
					'atfpp_links_anchor_language',
					sprintf(
						/* translators: 1: object ID, 2: language slug. */
						__( 'Item %1$d is itself the "%2$s" member of its group, so another "%2$s" item cannot be linked to it. Call link from the other item, or unlink one of them.', 'autopoly-ai-translation-for-polylang-pro' ),
						$anchor['id'],
						$anchor_lang
					)
				);
			}

			unset( $clean[ $anchor_lang ] );
		}

		if ( in_array( $anchor['id'], $clean, true ) ) {
			return new WP_Error(
				'atfpp_links_language_mismatch',
				sprintf(
					/* translators: 1: object ID, 2: language slug. */
					__( 'Item %1$d is in "%2$s" and cannot be listed under another language.', 'autopoly-ai-translation-for-polylang-pro' ),
					$anchor['id'],
					$anchor_lang
				)
			);
		}

		if ( array() === $clean ) {
			return new WP_Error(
				'atfpp_links_nothing_to_link',
				__( '"translations" names no item other than the one being linked from.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		foreach ( $clean as $slug => $member ) {
			$object = self::load( $type, $member );

			if ( is_wp_error( $object ) ) {
				return $object;
			}

			if ( $object['subtype'] !== $anchor['subtype'] ) {
				return new WP_Error(
					'atfpp_links_type_mismatch',
					sprintf(
						/* translators: 1: object ID, 2: its type, 3: expected type. */
						__( 'Item %1$d is a "%2$s", but only another "%3$s" can be its translation.', 'autopoly-ai-translation-for-polylang-pro' ),
						$member,
						$object['subtype'],
						$anchor['subtype']
					)
				);
			}

			if ( 'trash' === $object['status'] ) {
				return self::trashed_error( $member );
			}

			$actual = self::language_of( $type, $member );

			if ( $actual !== $slug ) {
				return new WP_Error(
					'atfpp_links_language_mismatch',
					'' === $actual
						? sprintf(
							/* translators: 1: object ID, 2: language slug. */
							__( 'Item %1$d has no language, so it cannot be linked as "%2$s". Give it that language with action "set_language" first.', 'autopoly-ai-translation-for-polylang-pro' ),
							$member,
							$slug
						)
						: sprintf(
							/* translators: 1: object ID, 2: actual language, 3: requested language. */
							__( 'Item %1$d is in "%2$s", not "%3$s". Link it under "%2$s", or change its language with action "set_language" first.', 'autopoly-ai-translation-for-polylang-pro' ),
							$member,
							$actual,
							$slug
						)
				);
			}
		}

		return $clean;
	}

	/**
	 * Re-reads the groups after a write and compares them with the plan.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $plan The applied plan.
	 * @return string|null A description of the mismatch, or null when it matches.
	 */
	private static function verify( array $plan ) {
		$type   = $plan['object_type'];
		$id     = (int) $plan['object']['id'];
		$actual = self::group_of( $type, $id );

		if ( 'set_language' === $plan['action'] && self::language_of( $type, $id ) !== $plan['language'] ) {
			return __( 'The language was not changed as planned.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		if ( $actual !== $plan['after'] ) {
			return __( 'The translation group does not match the planned result. See "result" for the group as it now stands.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		// Every member must see the same group, or a one-sided entry was left behind.
		if ( 'unlink' !== $plan['action'] ) {
			foreach ( $plan['after'] as $member ) {
				if ( self::group_of( $type, (int) $member ) !== $plan['after'] ) {
					return sprintf(
						/* translators: %d: object ID. */
						__( 'Item %d does not see the same translation group as the other members.', 'autopoly-ai-translation-for-polylang-pro' ),
						(int) $member
					);
				}
			}
		}

		/*
		 * Items that should no longer share a group with the object: after a
		 * link, the displaced members and the old groups movers left; after an
		 * unlink, everything the object was linked to.
		 */
		$gone = array();

		if ( 'link' === $plan['action'] ) {
			$gone = array_merge(
				array_diff( $plan['detach'], array_values( $plan['after'] ) ),
				$plan['changes']['left_behind']
			);
		} elseif ( 'unlink' === $plan['action'] ) {
			$gone = $plan['changes']['still_linked'];
		}

		foreach ( $gone as $other ) {
			if ( in_array( $id, self::group_of( $type, (int) $other ), true ) ) {
				return sprintf(
					/* translators: %d: object ID. */
					__( 'Item %d is still linked to the group it should have left.', 'autopoly-ai-translation-for-polylang-pro' ),
					(int) $other
				);
			}
		}

		return null;
	}

	/**
	 * Clears AutoPoly outdated-translation tracking that now points outside its group.
	 *
	 * ATFP_Re_Translation stores, on a translation, the source it compares
	 * against. Once linking or unlinking moves either of them, that comparison
	 * is between unrelated posts, so the tracking is removed with AutoPoly's own
	 * cleanup method rather than left to produce false "outdated" flags.
	 *
	 * @since 1.11.0
	 * @param string $type Object type.
	 * @param int[]  $ids  Objects involved in the change.
	 * @return int[] Objects whose tracking was cleared.
	 */
	private static function clear_stale_tracking( $type, array $ids ) {
		$cleared = array();

		if ( 'post' !== $type || ! is_callable( array( 'ATFP_Re_Translation', 'delete_re_translation_data' ) ) ) {
			return $cleared;
		}

		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$source = (int) get_post_meta( $id, self::TRACKING_SOURCE_META, true );

			if ( $source <= 0 || in_array( $source, self::group_of( $type, $id ), true ) ) {
				continue;
			}

			// Returns false when AutoPoly's retranslation tracking is switched off.
			if ( false === \ATFP_Re_Translation::delete_re_translation_data( $id ) ) {
				continue;
			}

			$cleared[] = $id;
		}

		return $cleared;
	}

	/**
	 * Flags a group whose stored languages disagree with its members.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $plan  Plan, updated in place.
	 * @param string               $type  Object type.
	 * @param int                  $id    Object the group was read from.
	 * @param array<string, int>   $group The group.
	 * @return void
	 */
	private static function check_integrity( array &$plan, $type, $id, array $group ) {
		$bad = array();

		foreach ( $group as $slug => $member ) {
			if ( self::language_of( $type, $member ) !== (string) $slug ) {
				$bad[] = $member;
			} elseif ( $member !== (int) $id && self::group_of( $type, $member ) !== $group ) {
				/*
				 * One-sided: this group lists the member, but the member belongs
				 * to a different group. Saving would merge both groups and
				 * unlink members of the other one.
				 */
				$bad[] = $member;
			}
		}

		$own = self::language_of( $type, $id );

		if ( '' !== $own && ( ! isset( $group[ $own ] ) || $group[ $own ] !== $id ) ) {
			$bad[] = $id;
		}

		if ( array() !== $bad ) {
			$plan['conflicts'][] = self::conflict(
				'broken_group',
				__( 'This translation group is inconsistent: what it records does not agree with the listed items\' own languages or groups. Unlink the listed items to repair it, then link again.', 'autopoly-ai-translation-for-polylang-pro' ),
				$bad
			);
		}
	}

	/**
	 * Refuses to move objects whose group backs a front page, posts page or default category.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $plan Plan, updated in place.
	 * @param string               $type Object type.
	 * @param int[]                $ids  Objects that would leave or change language.
	 * @return void
	 */
	private static function check_protected( array &$plan, $type, array $ids ) {
		foreach ( $ids as $id ) {
			$role = self::protected_role( $type, (int) $id );

			if ( '' === $role ) {
				continue;
			}

			$plan['conflicts'][] = self::conflict(
				'protected',
				sprintf(
					/* translators: 1: object ID, 2: role such as "front page". */
					__( 'Item %1$d is the %2$s in one of the site\'s languages. Moving it out of its group or changing its language would break that. Change it from Settings > Reading or Polylang\'s settings instead.', 'autopoly-ai-translation-for-polylang-pro' ),
					(int) $id,
					$role
				),
				array( (int) $id )
			);
		}
	}

	/**
	 * Names the site-level role an object plays through its translation group, if any.
	 *
	 * @since 1.11.0
	 * @param string $type Object type.
	 * @param int    $id   Object ID.
	 * @return string The role, or an empty string.
	 */
	private static function protected_role( $type, $id ) {
		if ( 'post' === $type ) {
			if ( 'page' !== get_option( 'show_on_front' ) ) {
				return '';
			}

			$roles = array(
				'page_on_front'  => __( 'static front page', 'autopoly-ai-translation-for-polylang-pro' ),
				'page_for_posts' => __( 'posts page', 'autopoly-ai-translation-for-polylang-pro' ),
			);

			foreach ( $roles as $option => $label ) {
				$page = (int) get_option( $option );

				if ( $page > 0 && ( $page === $id || in_array( $id, self::group_of( 'post', $page ), true ) ) ) {
					return $label;
				}
			}

			return '';
		}

		$default = (int) get_option( 'default_category' );

		if ( $default > 0 && ( $default === $id || in_array( $id, self::group_of( 'term', $default ), true ) ) ) {
			return __( 'default category', 'autopoly-ai-translation-for-polylang-pro' );
		}

		return '';
	}

	/**
	 * Refuses a language change that would give two terms the same slug in one language.
	 *
	 * Only possible where slugs are shared across languages, but cheap to rule out.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $plan   Plan, updated in place.
	 * @param array<string, mixed> $object Loaded term.
	 * @param string               $slug   Target language slug.
	 * @return void
	 */
	private static function check_term_slug( array &$plan, array $object, $slug ) {
		$model = PLL()->model;

		if ( ! is_callable( array( $model, 'term_exists_by_slug' ) ) ) {
			return;
		}

		$existing = (int) $model->term_exists_by_slug( $object['slug'], $slug, $object['subtype'] );

		if ( $existing > 0 && $existing !== $object['id'] ) {
			$plan['conflicts'][] = self::conflict(
				'slug_taken',
				sprintf(
					/* translators: 1: term slug, 2: language slug, 3: term ID. */
					__( 'Another term already uses the slug "%1$s" in "%2$s" (ID %3$d).', 'autopoly-ai-translation-for-polylang-pro' ),
					$object['slug'],
					$slug,
					$existing
				),
				array( $existing )
			);
		}
	}

	/**
	 * Loads a translatable post or term.
	 *
	 * @since 1.11.0
	 * @param string $type "post" or "term".
	 * @param int    $id   Object ID.
	 * @return array<string, mixed>|WP_Error Object facts, or an error.
	 */
	private static function load( $type, $id ) {
		$id = (int) $id;

		if ( 'term' === $type ) {
			$term = $id > 0 ? get_term( $id ) : null;

			if ( ! $term instanceof WP_Term ) {
				return self::not_found_error( $id );
			}

			if ( ! pll_is_translated_taxonomy( $term->taxonomy ) ) {
				return self::not_translatable_error( 'term', $id, $term->taxonomy );
			}

			return array(
				'id'      => (int) $term->term_id,
				'subtype' => (string) $term->taxonomy,
				'parent'  => (int) $term->parent,
				'status'  => '',
				'slug'    => (string) $term->slug,
			);
		}

		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post instanceof WP_Post || 'auto-draft' === $post->post_status ) {
			return self::not_found_error( $id );
		}

		if ( ! pll_is_translated_post_type( $post->post_type ) ) {
			return self::not_translatable_error( 'post', $id, $post->post_type );
		}

		return array(
			'id'      => (int) $post->ID,
			'subtype' => (string) $post->post_type,
			'parent'  => (int) $post->post_parent,
			'status'  => (string) $post->post_status,
			'slug'    => (string) $post->post_name,
		);
	}

	/**
	 * Polylang's sub-model for an object type.
	 *
	 * @since 1.11.0
	 * @param string $type "post" or "term".
	 * @return \PLL_Translated_Post|\PLL_Translated_Term The sub-model.
	 */
	private static function model( $type ) {
		return 'term' === $type ? PLL()->model->term : PLL()->model->post;
	}

	/**
	 * An object's language slug.
	 *
	 * @since 1.11.0
	 * @param string $type Object type.
	 * @param int    $id   Object ID.
	 * @return string Language slug, or an empty string when it has none.
	 */
	private static function language_of( $type, $id ) {
		$language = self::model( $type )->get_language( (int) $id );

		return $language instanceof PLL_Language ? (string) $language->slug : '';
	}

	/**
	 * An object's translation group, sorted by language.
	 *
	 * Polylang always includes the object itself when it has a language.
	 *
	 * @since 1.11.0
	 * @param string $type Object type.
	 * @param int    $id   Object ID.
	 * @return array<string, int> Language slug => object ID.
	 */
	private static function group_of( $type, $id ) {
		$group = array();

		foreach ( (array) self::model( $type )->get_translations( (int) $id ) as $slug => $member ) {
			$member = (int) $member;

			if ( $member > 0 ) {
				$group[ (string) $slug ] = $member;
			}
		}

		ksort( $group );

		return $group;
	}

	/**
	 * A group without one member.
	 *
	 * @since 1.11.0
	 * @param array<string, int> $group Group.
	 * @param int                $id    Member to remove.
	 * @return array<string, int> The rest of the group.
	 */
	private static function without( array $group, $id ) {
		foreach ( $group as $slug => $member ) {
			if ( $member === (int) $id ) {
				unset( $group[ $slug ] );
			}
		}

		return $group;
	}

	/**
	 * Merges ID lists into one list of unique positive integers.
	 *
	 * @since 1.11.0
	 * @param array<int|string, mixed> ...$lists ID lists.
	 * @return int[] Unique IDs.
	 */
	private static function merge_ids( array ...$lists ) {
		$ids = array();

		foreach ( $lists as $list ) {
			foreach ( $list as $id ) {
				$id = (int) $id;

				if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
		}

		return $ids;
	}

	/**
	 * A blank plan.
	 *
	 * @since 1.11.0
	 * @param string               $action Action.
	 * @param string               $type   Object type.
	 * @param array<string, mixed> $object Loaded object.
	 * @param array<string, int>   $before Group before the change.
	 * @return array<string, mixed> Plan.
	 */
	private static function new_plan( $action, $type, array $object, array $before ) {
		return array(
			'action'      => $action,
			'object_type' => $type,
			'object'      => $object,
			'before'      => $before,
			'after'       => $before,
			'involved'    => array( $object['id'] ),
			'detach'      => array(),
			'language'    => '',
			'changes'     => array(),
			'conflicts'   => array(),
			'warnings'    => array(),
			'noop'        => false,
			'message'     => '',
		);
	}

	/**
	 * A conflict entry.
	 *
	 * @since 1.11.0
	 * @param string $reason  Reason code.
	 * @param string $message Explanation.
	 * @param int[]  $ids     Objects concerned.
	 * @return array<string, mixed> Conflict.
	 */
	private static function conflict( $reason, $message, array $ids ) {
		return array(
			'reason'  => $reason,
			'message' => $message,
			'ids'     => self::merge_ids( $ids ),
		);
	}

	/**
	 * A compact description of an object.
	 *
	 * @since 1.11.0
	 * @param string $type Object type.
	 * @param int    $id   Object ID.
	 * @return array<string, mixed> Descriptor.
	 */
	private static function describe( $type, $id ) {
		$id = (int) $id;

		if ( 'term' === $type ) {
			$term = get_term( $id );

			return array(
				'id'       => $id,
				'name'     => $term instanceof WP_Term ? (string) $term->name : '',
				'taxonomy' => $term instanceof WP_Term ? (string) $term->taxonomy : '',
				'language' => self::language_of( $type, $id ),
			);
		}

		$post = get_post( $id );

		return array(
			'id'        => $id,
			'title'     => $post instanceof WP_Post ? (string) $post->post_title : '',
			'post_type' => $post instanceof WP_Post ? (string) $post->post_type : '',
			'status'    => $post instanceof WP_Post ? (string) $post->post_status : '',
			'language'  => self::language_of( $type, $id ),
		);
	}

	/**
	 * Describes every member of a group.
	 *
	 * @since 1.11.0
	 * @param string             $type  Object type.
	 * @param array<string, int> $group Group.
	 * @return object Language slug => descriptor; an object so an empty group is {}.
	 */
	private static function describe_group( $type, array $group ) {
		$described = array();

		foreach ( $group as $slug => $member ) {
			$described[ $slug ] = self::describe( $type, $member );
		}

		return (object) $described;
	}

	/**
	 * Describes the ID lists in a changes summary; other values pass through.
	 *
	 * @since 1.11.0
	 * @param string               $type    Object type.
	 * @param array<string, mixed> $changes Changes.
	 * @return array<string, mixed> Described changes.
	 */
	private static function describe_changes( $type, array $changes ) {
		foreach ( $changes as $key => $value ) {
			if ( is_array( $value ) ) {
				$changes[ $key ] = array();

				foreach ( $value as $id ) {
					$changes[ $key ][] = self::describe( $type, (int) $id );
				}
			}
		}

		return $changes;
	}

	/**
	 * Error for a missing or unusable object.
	 *
	 * @since 1.11.0
	 * @param int $id Object ID.
	 * @return WP_Error The error.
	 */
	private static function not_found_error( $id ) {
		return new WP_Error(
			'atfpp_links_not_found',
			sprintf(
				/* translators: %d: object ID. */
				__( 'No item with ID %d.', 'autopoly-ai-translation-for-polylang-pro' ),
				(int) $id
			)
		);
	}

	/**
	 * Error for an object Polylang does not translate.
	 *
	 * @since 1.11.0
	 * @since 1.12.0 Takes the object type, and names the setting that decides it.
	 * @param string $type    "post" or "term".
	 * @param int    $id      Object ID.
	 * @param string $subtype Post type or taxonomy.
	 * @return WP_Error The error.
	 */
	private static function not_translatable_error( $type, $id, $subtype ) {
		return new WP_Error(
			'atfpp_links_not_translatable',
			sprintf(
				/* translators: 1: object ID, 2: explanation and remedy. */
				__( 'Item %1$d cannot be given a language or linked. %2$s', 'autopoly-ai-translation-for-polylang-pro' ),
				(int) $id,
				Polylang_Guard::not_translated_message( (string) $type, (string) $subtype )
			)
		);
	}

	/**
	 * Error for a trashed post.
	 *
	 * @since 1.11.0
	 * @param int $id Post ID.
	 * @return WP_Error The error.
	 */
	private static function trashed_error( $id ) {
		return new WP_Error(
			'atfpp_links_trashed',
			sprintf(
				/* translators: %d: post ID. */
				__( 'Item %d is in the trash. Restore it first; only unlinking is allowed on trashed items.', 'autopoly-ai-translation-for-polylang-pro' ),
				(int) $id
			)
		);
	}
}
