<?php
/**
 * Fix Issue ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Inspector\Abilities\Fixes;

use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Content_Service;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Inspector_Guard;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Report_Service;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies a fix the Inspector can make itself, with a dry run first.
 *
 * Each fix is an `action` value rather than its own tool, so a new fix is one
 * more entry in ACTIONS. Translating, linking translations, menus and strings
 * belong to AutoPoly's tools and are never duplicated here.
 *
 * @since 1.1.0
 */
final class Fix_Issue extends Abstract_Ability {

	/**
	 * Fixes this tool applies, keyed by action, with the issue type each one
	 * resolves (DUPCAP_Issue_Types values, written out so this class loads
	 * without the engine). Get_Fix_Plan reads this map to point at fix-issue.
	 *
	 * @since 1.1.0
	 * @var array<string, string>
	 */
	const ACTIONS = array(
		'assign_default_language' => 'content_nolang',
	);

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'fix-issue';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Fix Issue', 'duplicate-content-addon-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Translation Inspector: apply a fix the Inspector makes itself. action "assign_default_language" gives the default language to posts, pages and terms that have none (the "without a language" issue); items that already have a language are never changed. Dry run by default: call it first with dry_run true, show the user what would change, then call again with dry_run false. Returns the items changed or that would change, and any skipped with the reason. For translations, translation links, menus and strings use the autopoly/* tool that get-fix-plan names instead. Confirm with recheck-issue afterwards.', 'duplicate-content-addon-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'required'   => array( 'action' ),
			'properties' => array(
				'action' => array(
					'type'        => 'string',
					'enum'        => array_keys( self::ACTIONS ),
					'description' => __( 'The fix to apply.', 'duplicate-content-addon-for-polylang' ),
				),
				'issue_key' => array(
					'type'        => 'string',
					'description' => __( 'Optional: the issue_key this fix is for, checked against the action (assign_default_language fixes content_nolang).', 'duplicate-content-addon-for-polylang' ),
				),
				'object_type' => array(
					'type'        => 'string',
					'enum'        => array( 'post', 'term', 'all' ),
					'default'     => 'all',
					'description' => __( 'For assign_default_language: which items to fix.', 'duplicate-content-addon-for-polylang' ),
				),
				'post_type' => array(
					'type'        => 'string',
					'description' => __( 'For assign_default_language: only this post type. Omit for every translated post type.', 'duplicate-content-addon-for-polylang' ),
				),
				'taxonomy' => array(
					'type'        => 'string',
					'description' => __( 'For assign_default_language: only this taxonomy. Omit for every translated taxonomy.', 'duplicate-content-addon-for-polylang' ),
				),
				'dry_run' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Only report what would change.', 'duplicate-content-addon-for-polylang' ),
				),
				'limit' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'default'     => 100,
					'description' => __( 'Maximum items to change in one call.', 'duplicate-content-addon-for-polylang' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return $this->write_meta();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @param mixed $input Validated input, or null.
	 * @return array<string, mixed>|WP_Error Items changed, or that would change.
	 */
	public static function execute( $input = array() ) {
		$input = self::input_array( $input );

		if ( ! Inspector_Guard::engine_ready() ) {
			return Inspector_Guard::engine_error();
		}

		$action = isset( $input['action'] ) ? (string) $input['action'] : '';

		if ( ! empty( $input['issue_key'] ) && ( self::ACTIONS[ $action ] ?? '' ) !== (string) $input['issue_key'] ) {
			return new WP_Error(
				'dupcap_fix_wrong_issue',
				sprintf(
					/* translators: 1: action, 2: issue key it fixes. */
					__( 'Action "%1$s" fixes the issue "%2$s" only. For other issues, follow the fix that get-issues or get-fix-plan gives.', 'duplicate-content-addon-for-polylang' ),
					$action,
					self::ACTIONS[ $action ] ?? ''
				),
				array( 'status' => 400 )
			);
		}

		// Only one action today; each new one gets its own method here.
		return self::assign_default_language( $input );
	}

	/**
	 * Give the default language to posts and terms that have none.
	 *
	 * Finds them with Polylang's own queries and assigns with its public API,
	 * one item at a time so edit rights are checked per item. Then refreshes
	 * the Content and Elementor sections of the report, as the dashboard's
	 * button does.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function assign_default_language( array $input ) {
		$model   = PLL()->model;
		$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '';

		if ( '' === $default ) {
			return new WP_Error(
				'dupcap_no_default_language',
				__( 'Polylang has no default language, so there is nothing to assign. Guide the user to Languages > Languages to add one and mark it as default.', 'duplicate-content-addon-for-polylang' ),
				array( 'status' => 409 )
			);
		}

		if ( ! method_exists( $model, 'get_posts_with_no_lang' ) || ! method_exists( $model, 'get_terms_with_no_lang' ) ) {
			return new WP_Error(
				'dupcap_polylang_too_old',
				__( 'This Polylang version cannot list items without a language. Guide the user to the "Assign default language" button on the Translation Inspector dashboard instead.', 'duplicate-content-addon-for-polylang' ),
				array( 'status' => 501 )
			);
		}

		$object_type = isset( $input['object_type'] ) ? (string) $input['object_type'] : 'all';
		$dry_run     = ! array_key_exists( 'dry_run', $input ) || ! empty( $input['dry_run'] );
		$limit       = min( 500, max( 1, (int) ( $input['limit'] ?? 100 ) ) );

		$post_types = (array) $model->get_translated_post_types();
		$taxonomies = (array) $model->get_translated_taxonomies();

		if ( ! empty( $input['post_type'] ) ) {
			$post_type = sanitize_key( (string) $input['post_type'] );
			if ( ! in_array( $post_type, $post_types, true ) ) {
				return self::not_translatable( 'post_type', $post_type );
			}
			$post_types = array( $post_type );
		}

		if ( ! empty( $input['taxonomy'] ) ) {
			$taxonomy = sanitize_key( (string) $input['taxonomy'] );
			if ( ! in_array( $taxonomy, $taxonomies, true ) ) {
				return self::not_translatable( 'taxonomy', $taxonomy );
			}
			$taxonomies = array( $taxonomy );
		}

		// Ask for one more than the limit to know whether more are waiting.
		$post_ids = ( 'term' !== $object_type && $post_types ) ? array_map( 'intval', (array) $model->get_posts_with_no_lang( array_values( $post_types ), $limit + 1 ) ) : array();
		$term_ids = ( 'post' !== $object_type && $taxonomies ) ? array_map( 'intval', (array) $model->get_terms_with_no_lang( array_values( $taxonomies ), $limit + 1 ) ) : array();

		$items = array();
		foreach ( $post_ids as $post_id ) {
			$items[] = array( 'post', $post_id );
		}
		foreach ( $term_ids as $term_id ) {
			$items[] = array( 'term', $term_id );
		}

		$more  = count( $items ) > $limit;
		$items = array_slice( $items, 0, $limit );

		$changed = array();
		$skipped = array();
		foreach ( $items as $item ) {
			list( $kind, $id ) = $item;
			$row               = 'post' === $kind ? self::describe_post( $id ) : self::describe_term( $id );

			$can = 'post' === $kind ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_term', $id );
			if ( ! $can ) {
				$row['reason'] = __( 'You cannot edit this item.', 'duplicate-content-addon-for-polylang' );
				$skipped[]     = $row;
				continue;
			}

			if ( ! $dry_run ) {
				if ( 'post' === $kind ) {
					pll_set_post_language( $id, $default );
				} else {
					pll_set_term_language( $id, $default );
				}
			}

			$changed[] = $row;
		}

		if ( ! $dry_run && ! empty( $changed ) ) {
			\DUPCAP_Dashboard::instance()->refresh_after_language_change();
			Report_Service::forget_context();
		}

		if ( empty( $changed ) && empty( $skipped ) ) {
			$next = __( 'Every translatable post and term already has a language. Nothing to do.', 'duplicate-content-addon-for-polylang' );
		} elseif ( $dry_run ) {
			$next = __( 'Nothing was changed. Show the user this list and, once they agree, call again with dry_run false.', 'duplicate-content-addon-for-polylang' );
		} elseif ( $more ) {
			$next = __( 'Done for this batch. More items remain: call again with dry_run false to continue.', 'duplicate-content-addon-for-polylang' );
		} else {
			$next = __( 'Done. The Content and Elementor sections of the report were refreshed; call translation-inspector/get-issues to confirm the "without a language" issue is gone.', 'duplicate-content-addon-for-polylang' );
		}

		return array(
			'action'   => 'assign_default_language',
			'dry_run'  => $dry_run,
			'language' => $default,
			'count'    => count( $changed ),
			'items'    => $changed,
			'skipped'  => $skipped,
			'more'     => $more,
			'next_step' => $next,
		);
	}

	/**
	 * A post as listed in the result.
	 *
	 * @since 1.1.0
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private static function describe_post( $post_id ) {
		$post = Content_Service::describe_post( $post_id );

		return array(
			'object_type' => 'post',
			'id'          => $post['id'],
			'type'        => $post['type'],
			'title'       => $post['title'],
			'status'      => $post['status'],
			'edit_url'    => $post['edit_url'],
		);
	}

	/**
	 * A term as listed in the result.
	 *
	 * @since 1.1.0
	 * @param int $term_id Term ID.
	 * @return array<string, mixed>
	 */
	private static function describe_term( $term_id ) {
		$term = Content_Service::describe_term( $term_id );

		return array(
			'object_type' => 'term',
			'id'          => $term['id'],
			'type'        => $term['taxonomy'],
			'title'       => $term['title'],
			'edit_url'    => $term['edit_url'],
		);
	}

	/**
	 * Error for a post type or taxonomy Polylang does not translate.
	 *
	 * @since 1.1.0
	 * @param string $what post_type or taxonomy.
	 * @param string $slug Requested slug.
	 * @return WP_Error
	 */
	private static function not_translatable( $what, $slug ) {
		return new WP_Error(
			'dupcap_not_translatable',
			sprintf(
				/* translators: 1: post_type or taxonomy, 2: slug. */
				__( '%1$s "%2$s" is not translated by Polylang, so its items cannot get a language. It must first be enabled in Languages > Settings > Custom post types and Taxonomies.', 'duplicate-content-addon-for-polylang' ),
				$what,
				$slug
			),
			array( 'status' => 400 )
		);
	}
}
