<?php
/**
 * Fix Issue ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Fixes;

use CoolPlugins\AiFramework\Packs\Linguator\Services\Translation_Link_Service;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Content_Service;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Inspector_Guard;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Issue_Service;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Report_Service;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fix Issue.
 *
 * Applies the fixes Linguator can make safely on its own. Every action is a dry
 * run unless dry_run is false, and none of them rewrites text: translating
 * stays with the linguator/* abilities.
 *
 * @since 1.2.0
 */
final class Fix_Issue extends Abstract_Ability {

	/**
	 * Actions and the issue types each one fixes. An empty list means the
	 * action is not tied to one issue type.
	 *
	 * @since 1.2.0
	 * @var array<string, string[]>
	 */
	const ACTIONS = array(
		'assign_default_language'  => array( 'content_nolang' ),
		'publish_translation'      => array( 'content_draft' ),
		'unpublish_translation'    => array(),
		'sync_woocommerce_product' => array( 'woo_variations', 'woo_fields' ),
		'mark_synced'              => array( 'content_source_updated' ),
		'link_translations'        => array(),
		'unlink_translation'       => array(),
	);

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'fix-issue';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Fix Issue', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator Site Scan: apply a fix the Inspector makes itself, never rewriting any text. Actions: "assign_default_language" gives the default language to posts, pages and terms that have none (the "without a language" issue; items that already have a language are never changed). "publish_translation" publishes draft or pending translations, only when their original is published (the "unpublished translations" issue; give post_id, or source_post_id and language, or an issue_key for all its rows). "unpublish_translation" sets a published translation back to draft, only when its original is not published. "sync_woocommerce_product" copies the original product\'s variations and shared settings (SKU, stock, tax, visibility, prices) to its translations (the WooCommerce product data issues; give product_id or an issue_key). "mark_synced" marks a translation as up to date with its original without changing it (the "default language was updated" issue; only after the translation was updated or the user confirms it is still right). "link_translations" and "unlink_translation" change how posts or terms are linked as translations. Dry run by default: call it first with dry_run true, show the user what would change, then call again with dry_run false. Returns the items changed or that would change, and any skipped with the reason. For missing, copied or outdated translations, terms and strings use the linguator/* tool that get-fix-plan names; other issues are manual fixes. Confirm with recheck-issue afterwards.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'required'   => array( 'action' ),
			'properties' => array(
				'action'         => array(
					'type'        => 'string',
					'enum'        => array_keys( self::ACTIONS ),
					'description' => __( 'The fix to apply.', 'linguator-pro' ),
				),
				'issue_key'      => array(
					'type'        => 'string',
					'description' => __( 'Optional: the issue_key this fix is for, checked against the action. For publish_translation, sync_woocommerce_product and mark_synced it also selects every row of that issue when no target is given.', 'linguator-pro' ),
				),
				'object_type'    => array(
					'type'        => 'string',
					'enum'        => array( 'post', 'term', 'all' ),
					'description' => __( 'For assign_default_language: which items to fix (default all). For link_translations and unlink_translation: post (default) or term.', 'linguator-pro' ),
				),
				'post_type'      => array(
					'type'        => 'string',
					'description' => __( 'For assign_default_language: only this post type. Omit for every translated post type.', 'linguator-pro' ),
				),
				'taxonomy'       => array(
					'type'        => 'string',
					'description' => __( 'For assign_default_language: only this taxonomy. Omit for every translated taxonomy.', 'linguator-pro' ),
				),
				'post_id'        => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'For publish_translation, unpublish_translation and mark_synced: the translation post ID.', 'linguator-pro' ),
				),
				'source_post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'For publish_translation and unpublish_translation: the original post ID, together with language, instead of post_id.', 'linguator-pro' ),
				),
				'language'       => array(
					'type'        => 'string',
					'description' => __( 'With source_post_id: the translation language slug.', 'linguator-pro' ),
				),
				'product_id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'For sync_woocommerce_product: the product ID, the original or any translation.', 'linguator-pro' ),
				),
				'id'             => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'For unlink_translation: the post or term ID to remove from its translation group.', 'linguator-pro' ),
				),
				'translations'   => array(
					'type'        => 'object',
					'description' => __( 'For link_translations: language slug => post or term ID, at least two.', 'linguator-pro' ),
				),
				'dry_run'        => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Only report what would change.', 'linguator-pro' ),
				),
				'limit'          => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'default'     => 100,
					'description' => __( 'Maximum items to change in one call.', 'linguator-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param mixed $input Validated input, or null.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function execute( $input = array() ) {
		$input = self::input_array( $input );

		if ( ! Inspector_Guard::engine_ready() ) {
			return Inspector_Guard::engine_error();
		}

		$action = isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : '';

		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			return new WP_Error(
				'lmat_invalid_action',
				sprintf(
					/* translators: %s: comma-separated actions. */
					__( 'Unknown action. Use one of: %s.', 'linguator-pro' ),
					implode( ', ', array_keys( self::ACTIONS ) )
				),
				array( 'status' => 400 )
			);
		}

		$issue_key = isset( $input['issue_key'] ) ? sanitize_text_field( (string) $input['issue_key'] ) : '';
		$types     = self::ACTIONS[ $action ];

		if ( '' !== $issue_key && ! empty( $types ) && ! in_array( Issue_Service::type_for( $issue_key ), $types, true ) ) {
			return new WP_Error(
				'lmat_fix_wrong_issue',
				sprintf(
					/* translators: 1: action, 2: issue types it fixes. */
					__( 'Action "%1$s" fixes issues of type %2$s only. For other issues, follow the fix that get-issues or get-fix-plan gives.', 'linguator-pro' ),
					$action,
					implode( ', ', $types )
				),
				array( 'status' => 400 )
			);
		}

		$dry_run = ! array_key_exists( 'dry_run', $input ) || ! empty( $input['dry_run'] );
		$limit   = min( 500, max( 1, (int) ( $input['limit'] ?? 100 ) ) );

		switch ( $action ) {
			case 'assign_default_language':
				return self::assign_default_language( $input, $dry_run, $limit );
			case 'publish_translation':
			case 'unpublish_translation':
				return self::change_status( $action, $input, $issue_key, $dry_run, $limit );
			case 'sync_woocommerce_product':
				return self::sync_product( $input, $issue_key, $dry_run, $limit );
			case 'mark_synced':
				return self::mark_synced( $input, $issue_key, $dry_run, $limit );
			case 'link_translations':
				return self::link_translations( $input, $dry_run );
			default:
				return self::unlink_translation( $input, $dry_run );
		}
	}

	/**
	 * Gives the default language to posts and terms that have none.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input   Ability input.
	 * @param bool                 $dry_run Whether to only report.
	 * @param int                  $limit   Most items to change.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function assign_default_language( array $input, $dry_run, $limit ) {
		$model   = Inspector_Guard::model();
		$default = (string) linguator_default_language();

		if ( '' === $default ) {
			return new WP_Error(
				'lmat_no_default_language',
				__( 'Linguator has no default language, so there is nothing to assign. Guide the user to the Linguator language settings to add one and mark it as default.', 'linguator-pro' ),
				array( 'status' => 409 )
			);
		}

		$object_type = isset( $input['object_type'] ) ? sanitize_key( (string) $input['object_type'] ) : 'all';
		$post_types  = (array) $model->get_translated_post_types();
		$taxonomies  = array_values( array_diff( (array) $model->get_translated_taxonomies(), array( 'nav_menu', 'link_category', 'post_format' ) ) );

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

		// Ask for more than the limit to know whether more are waiting; the
		// model also returns revisions and trashed items, which are filtered out.
		$fetch    = $limit + 51;
		$post_ids = ( 'term' !== $object_type && $post_types ) ? array_map( 'intval', (array) $model->get_posts_with_no_lang( array_values( $post_types ), $fetch ) ) : array();
		$term_ids = ( 'post' !== $object_type && $taxonomies ) ? array_map( 'intval', (array) $model->get_terms_with_no_lang( array_values( $taxonomies ), $fetch ) ) : array();

		$items = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && in_array( $post->post_type, $post_types, true ) && ! in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
				$items[] = array( 'post', $post_id );
			}
		}
		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id );
			if ( $term instanceof \WP_Term && in_array( $term->taxonomy, $taxonomies, true ) ) {
				$items[] = array( 'term', $term_id );
			}
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
				$row['reason'] = __( 'You cannot edit this item.', 'linguator-pro' );
				$skipped[]     = $row;
				continue;
			}

			if ( ! $dry_run ) {
				if ( 'post' === $kind ) {
					linguator_set_post_language( $id, $default );
				} else {
					linguator_set_term_language( $id, $default );
				}
			}

			$changed[] = $row;
		}

		if ( ! $dry_run && ! empty( $changed ) ) {
			Report_Service::forget_context();
		}

		if ( empty( $changed ) && empty( $skipped ) ) {
			$next = __( 'Every translatable post and term already has a language. Nothing to do.', 'linguator-pro' );
		} elseif ( $dry_run ) {
			$next = __( 'Nothing was changed. Show the user this list and, once they agree, call again with dry_run false.', 'linguator-pro' );
		} elseif ( $more ) {
			$next = __( 'Done for this batch. More items remain: call again with dry_run false to continue.', 'linguator-pro' );
		} else {
			$next = __( 'Done. Call linguator-inspector/run-scan to scan the site now that every item has a language.', 'linguator-pro' );
		}

		return array(
			'action'    => 'assign_default_language',
			'dry_run'   => $dry_run,
			'language'  => $default,
			'count'     => count( $changed ),
			'items'     => $changed,
			'skipped'   => $skipped,
			'more'      => $more,
			'next_step' => $next,
		);
	}

	/**
	 * Publishes a draft translation, or unpublishes a published one.
	 *
	 * A translation is only published while its original is published, and only
	 * unpublished while its original is not.
	 *
	 * @since 1.2.0
	 * @param string               $action    publish_translation or unpublish_translation.
	 * @param array<string, mixed> $input     Ability input.
	 * @param string               $issue_key Issue key, or ''.
	 * @param bool                 $dry_run   Whether to only report.
	 * @param int                  $limit     Most items to change.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function change_status( $action, array $input, $issue_key, $dry_run, $limit ) {
		$publish = 'publish_translation' === $action;
		$targets = self::translation_targets( $input, $issue_key, $limit );

		if ( is_wp_error( $targets ) ) {
			return $targets;
		}

		$default = (string) linguator_default_language();
		$changed = array();
		$skipped = array();

		foreach ( $targets['ids'] as $id ) {
			$row    = self::describe_post( $id );
			$post   = get_post( $id );
			$reason = '';

			if ( ! $post ) {
				$reason = __( 'No post has this ID.', 'linguator-pro' );
			} elseif ( $default === $row['language'] || '' === $row['language'] ) {
				$reason = __( 'This is not a translation: it is in the default language or has no language.', 'linguator-pro' );
			} else {
				$group  = (array) linguator_get_post_translations( $id );
				$source = isset( $group[ $default ] ) ? get_post( (int) $group[ $default ] ) : null;

				if ( ! $source ) {
					$reason = __( 'No original in the default language is linked to this translation.', 'linguator-pro' );
				} elseif ( $publish && 'publish' !== $source->post_status ) {
					$reason = __( 'The original is not published, so its translation stays unpublished.', 'linguator-pro' );
				} elseif ( ! $publish && 'publish' === $source->post_status ) {
					$reason = __( 'The original is published, so its translation is left published.', 'linguator-pro' );
				} elseif ( $publish && ! in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
					$reason = sprintf(
						/* translators: %s: post status. */
						__( 'The translation is "%s", not a draft or pending post.', 'linguator-pro' ),
						$post->post_status
					);
				} elseif ( ! $publish && 'publish' !== $post->post_status ) {
					$reason = __( 'The translation is not published.', 'linguator-pro' );
				} elseif ( ! current_user_can( 'edit_post', $id ) || ( $publish && ! current_user_can( 'publish_post', $id ) ) ) {
					$reason = __( 'You cannot change this item.', 'linguator-pro' );
				}
			}

			if ( '' !== $reason ) {
				$row['reason'] = $reason;
				$skipped[]     = $row;
				continue;
			}

			if ( ! $dry_run ) {
				$done = wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => $publish ? 'publish' : 'draft',
					),
					true
				);
				if ( is_wp_error( $done ) || 0 === (int) $done ) {
					$row['reason'] = is_wp_error( $done ) ? $done->get_error_message() : __( 'WordPress could not save the post.', 'linguator-pro' );
					$skipped[]     = $row;
					continue;
				}
				$row['status'] = $publish ? 'publish' : 'draft';
			}

			$changed[] = $row;
		}

		$skipped = array_merge( $targets['skipped'], $skipped );

		if ( ! $dry_run && ! empty( $changed ) ) {
			Report_Service::forget_context();
		}

		return array(
			'action'    => $action,
			'dry_run'   => $dry_run,
			'count'     => count( $changed ),
			'items'     => $changed,
			'skipped'   => $skipped,
			'more'      => $targets['more'],
			'next_step' => self::next_step( $dry_run, $changed, $skipped, __( 'Done. Confirm with linguator-inspector/recheck-issue.', 'linguator-pro' ) ),
		);
	}

	/**
	 * Copies the original product's shared data to its translations.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input     Ability input.
	 * @param string               $issue_key Issue key, or ''.
	 * @param bool                 $dry_run   Whether to only report.
	 * @param int                  $limit     Most products to sync.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function sync_product( array $input, $issue_key, $dry_run, $limit ) {
		$sync = '\LinguatorPro\Modules\WooCommerce\Product_Sync';

		if ( ! class_exists( $sync ) || ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error(
				'lmat_woocommerce_missing',
				__( 'WooCommerce is not active, so there are no products to sync.', 'linguator-pro' ),
				array( 'status' => 409 )
			);
		}

		$ids  = array();
		$more = false;

		if ( ! empty( $input['product_id'] ) ) {
			$ids[] = (int) $input['product_id'];
		} elseif ( '' !== $issue_key ) {
			$issue = Issue_Service::find( $issue_key );
			if ( null === $issue ) {
				return new WP_Error(
					'lmat_issue_not_found',
					__( 'No open issue has this key. Call linguator-inspector/get-issues to see the current keys.', 'linguator-pro' ),
					array( 'status' => 404 )
				);
			}
			foreach ( $issue['rows'] as $row ) {
				if ( ! empty( $row['post_id'] ) ) {
					$ids[] = (int) $row['post_id'];
				}
			}
		} else {
			return new WP_Error(
				'lmat_fix_target_missing',
				__( 'Pass product_id, or an issue_key of a WooCommerce product issue.', 'linguator-pro' ),
				array( 'status' => 400 )
			);
		}

		$ids  = array_values( array_unique( array_filter( $ids ) ) );
		$more = count( $ids ) > $limit;
		$ids  = array_slice( $ids, 0, $limit );

		$changed = array();
		$skipped = array();

		foreach ( $ids as $id ) {
			$row    = self::describe_post( $id );
			$reason = '';
			$group  = array();

			if ( 'product' !== $row['type'] ) {
				$reason = __( 'This is not a WooCommerce product.', 'linguator-pro' );
			} else {
				$group = array_map( 'intval', (array) linguator_get_post_translations( $id ) );
				if ( count( $group ) < 2 ) {
					$reason = __( 'This product has no translations to sync.', 'linguator-pro' );
				} else {
					foreach ( $group as $member ) {
						if ( ! current_user_can( 'edit_post', $member ) ) {
							$reason = __( 'You cannot edit this product or one of its translations.', 'linguator-pro' );
							break;
						}
					}
				}
			}

			if ( '' !== $reason ) {
				$row['reason'] = $reason;
				$skipped[]     = $row;
				continue;
			}

			$source_id          = (int) $sync::source_id( $id );
			$row['source_id']   = $source_id;
			$row['will_update'] = array_diff_key( $group, array( (string) linguator_get_post_language( $source_id ) => true ) );

			if ( ! $dry_run ) {
				$sync::sync_product( $id );
			}

			$changed[] = $row;
		}

		if ( ! $dry_run && ! empty( $changed ) ) {
			Report_Service::forget_context();
		}

		return array(
			'action'    => 'sync_woocommerce_product',
			'dry_run'   => $dry_run,
			'count'     => count( $changed ),
			'items'     => $changed,
			'skipped'   => $skipped,
			'more'      => $more,
			'next_step' => self::next_step( $dry_run, $changed, $skipped, __( 'Done. Confirm with linguator-inspector/recheck-issue.', 'linguator-pro' ) ),
		);
	}

	/**
	 * Marks translations as up to date with their original.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input     Ability input.
	 * @param string               $issue_key Issue key, or ''.
	 * @param bool                 $dry_run   Whether to only report.
	 * @param int                  $limit     Most items to change.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function mark_synced( array $input, $issue_key, $dry_run, $limit ) {
		$class = '\LinguatorPro\Modules\Scan\Freshness';
		$model = Inspector_Guard::model();

		if ( ! class_exists( $class ) ) {
			return Inspector_Guard::engine_error();
		}

		$targets = self::translation_targets( $input, $issue_key, $limit );
		if ( is_wp_error( $targets ) ) {
			return $targets;
		}

		$fresh   = new $class( $model );
		$changed = array();
		$skipped = array();
		$keys    = array();

		foreach ( $targets['ids'] as $id ) {
			$row     = self::describe_post( $id );
			$context = $fresh->context( $id );

			if ( ! $context ) {
				$row['reason'] = __( 'Could not update the translation. Check that it is linked to a post in the default language.', 'linguator-pro' );
				$skipped[]     = $row;
				continue;
			}

			if ( ! current_user_can( 'edit_post', $id ) ) {
				$row['reason'] = __( 'You cannot edit this item.', 'linguator-pro' );
				$skipped[]     = $row;
				continue;
			}

			if ( ! $dry_run ) {
				$done = $fresh->mark_synced( $id );
				if ( ! $done ) {
					$row['reason'] = __( 'Could not update the translation.', 'linguator-pro' );
					$skipped[]     = $row;
					continue;
				}
				$keys[] = 'content_updated:' . $done['source_id'] . ':' . $done['lang'];
			}

			$row['source_id'] = (int) $context['source_id'];
			$changed[]        = $row;
		}

		$skipped = array_merge( $targets['skipped'], $skipped );

		if ( ! empty( $keys ) && null === Inspector_Guard::scan_blocked() ) {
			try {
				Report_Service::scanner()->recheck_rows( 'content', $keys );
			} catch ( \Throwable $e ) {
				unset( $e ); // The report is refreshed by the next recheck or scan.
			}
		}

		if ( ! $dry_run && ! empty( $changed ) ) {
			Report_Service::forget_context();
		}

		return array(
			'action'    => 'mark_synced',
			'dry_run'   => $dry_run,
			'count'     => count( $changed ),
			'items'     => $changed,
			'skipped'   => $skipped,
			'more'      => $targets['more'],
			'next_step' => self::next_step( $dry_run, $changed, $skipped, __( 'Done. Confirm with linguator-inspector/recheck-issue.', 'linguator-pro' ) ),
		);
	}

	/**
	 * Links posts or terms into one translation group.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input   Ability input.
	 * @param bool                 $dry_run Whether to only report.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function link_translations( array $input, $dry_run ) {
		$is_term      = isset( $input['object_type'] ) && 'term' === $input['object_type'];
		$translations = isset( $input['translations'] ) && is_array( $input['translations'] ) ? $input['translations'] : array();
		$group        = array();
		$items        = array();

		foreach ( $translations as $slug => $id ) {
			$slug     = sanitize_key( (string) $slug );
			$id       = absint( $id );
			$language = $is_term ? (string) linguator_get_term_language( $id ) : (string) linguator_get_post_language( $id );

			if ( $id < 1 || '' === $slug ) {
				continue;
			}

			if ( $language !== $slug ) {
				return new WP_Error(
					'lmat_language_mismatch',
					sprintf(
						/* translators: 1: object ID, 2: language slug. */
						__( 'Object %1$d is not in language "%2$s". Assign its language first.', 'linguator-pro' ),
						$id,
						$slug
					),
					array( 'status' => 400 )
				);
			}

			if ( ! ( $is_term ? current_user_can( 'edit_term', $id ) : current_user_can( 'edit_post', $id ) ) ) {
				return new WP_Error( 'lmat_forbidden', __( 'You are not allowed to change one of these objects.', 'linguator-pro' ), array( 'status' => 403 ) );
			}

			$group[ $slug ] = $id;
			$items[]        = $is_term ? self::describe_term( $id ) : self::describe_post( $id );
		}

		if ( count( $group ) < 2 ) {
			return new WP_Error(
				'lmat_too_few',
				__( 'translations needs at least two objects in different languages: {"fr": 12, "en": 10}.', 'linguator-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $dry_run ) {
			$saved = Translation_Link_Service::run(
				array(
					'action'       => 'link',
					'object_type'  => $is_term ? 'term' : 'post',
					'translations' => $group,
				)
			);
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			Report_Service::forget_context();
		}

		return array(
			'action'    => 'link_translations',
			'dry_run'   => $dry_run,
			'count'     => count( $items ),
			'items'     => $items,
			'skipped'   => array(),
			'more'      => false,
			'next_step' => $dry_run
				? __( 'Nothing was changed. Show the user this group and, once they agree, call again with dry_run false.', 'linguator-pro' )
				: __( 'Linked. Confirm with linguator-inspector/recheck-issue.', 'linguator-pro' ),
		);
	}

	/**
	 * Removes a post or term from its translation group.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input   Ability input.
	 * @param bool                 $dry_run Whether to only report.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function unlink_translation( array $input, $dry_run ) {
		$is_term = isset( $input['object_type'] ) && 'term' === $input['object_type'];
		$id      = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id < 1 ) {
			return new WP_Error( 'lmat_missing_id', __( 'id is required.', 'linguator-pro' ), array( 'status' => 400 ) );
		}

		if ( ! ( $is_term ? current_user_can( 'edit_term', $id ) : current_user_can( 'edit_post', $id ) ) ) {
			return new WP_Error( 'lmat_forbidden', __( 'You are not allowed to change this object.', 'linguator-pro' ), array( 'status' => 403 ) );
		}

		$group = $is_term ? (array) linguator_get_term_translations( $id ) : (array) linguator_get_post_translations( $id );
		$item  = $is_term ? self::describe_term( $id ) : self::describe_post( $id );

		$item['linked_translations'] = array_diff_key( $group, array( $item['language'] => true ) );

		if ( ! $dry_run ) {
			$saved = Translation_Link_Service::run(
				array(
					'action'      => 'unlink',
					'object_type' => $is_term ? 'term' : 'post',
					'id'          => $id,
				)
			);
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			Report_Service::forget_context();
		}

		return array(
			'action'    => 'unlink_translation',
			'dry_run'   => $dry_run,
			'count'     => 1,
			'items'     => array( $item ),
			'skipped'   => array(),
			'more'      => false,
			'next_step' => $dry_run
				? __( 'Nothing was changed. Show the user which translations would be unlinked and, once they agree, call again with dry_run false.', 'linguator-pro' )
				: __( 'Unlinked. The item stays in its language but is no longer a translation of the others.', 'linguator-pro' ),
		);
	}

	/**
	 * The translation posts an action should look at.
	 *
	 * From post_id, from source_post_id and language, or from every row of an issue.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input     Ability input.
	 * @param string               $issue_key Issue key, or ''.
	 * @param int                  $limit     Most posts.
	 * @return array{ids: int[], skipped: array<int, array<string, mixed>>, more: bool}|WP_Error
	 */
	private static function translation_targets( array $input, $issue_key, $limit ) {
		$ids     = array();
		$skipped = array();

		if ( ! empty( $input['post_id'] ) ) {
			$ids[] = (int) $input['post_id'];
		} elseif ( ! empty( $input['source_post_id'] ) && ! empty( $input['language'] ) ) {
			$slug  = sanitize_key( (string) $input['language'] );
			$group = (array) linguator_get_post_translations( (int) $input['source_post_id'] );
			if ( empty( $group[ $slug ] ) ) {
				return new WP_Error(
					'lmat_translation_not_found',
					__( 'The original has no translation in that language.', 'linguator-pro' ),
					array( 'status' => 404 )
				);
			}
			$ids[] = (int) $group[ $slug ];
		} elseif ( '' !== $issue_key ) {
			$issue = Issue_Service::find( $issue_key );
			if ( null === $issue ) {
				return new WP_Error(
					'lmat_issue_not_found',
					__( 'No open issue has this key. Call linguator-inspector/get-issues to see the current keys.', 'linguator-pro' ),
					array( 'status' => 404 )
				);
			}
			foreach ( $issue['rows'] as $row ) {
				$translation = ! empty( $row['translation_id'] ) ? (int) $row['translation_id'] : 0;
				if ( $translation < 1 && ! empty( $row['post_id'] ) && ! empty( $row['language'] ) ) {
					$group       = (array) linguator_get_post_translations( (int) $row['post_id'] );
					$translation = (int) ( $group[ (string) $row['language'] ] ?? 0 );
				}
				if ( $translation > 0 ) {
					$ids[] = $translation;
				} else {
					$skipped[] = array(
						'label'  => isset( $row['label'] ) ? wp_strip_all_tags( (string) $row['label'] ) : '',
						'reason' => __( 'The translation of this row could not be found.', 'linguator-pro' ),
					);
				}
			}
		} else {
			return new WP_Error(
				'lmat_fix_target_missing',
				__( 'Say what to fix: pass post_id (the translation), source_post_id with language, or an issue_key.', 'linguator-pro' ),
				array( 'status' => 400 )
			);
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );

		return array(
			'ids'     => array_slice( $ids, 0, $limit ),
			'skipped' => $skipped,
			'more'    => count( $ids ) > $limit,
		);
	}

	/**
	 * What to tell the AI to do next.
	 *
	 * @since 1.2.0
	 * @param bool                             $dry_run Whether nothing was written.
	 * @param array<int, array<string, mixed>> $changed Items changed or that would change.
	 * @param array<int, array<string, mixed>> $skipped Items skipped.
	 * @param string                           $done    Text for a finished write.
	 * @return string
	 */
	private static function next_step( $dry_run, array $changed, array $skipped, $done ) {
		if ( empty( $changed ) && empty( $skipped ) ) {
			return __( 'Nothing to do.', 'linguator-pro' );
		}

		if ( empty( $changed ) ) {
			return __( 'Every item was skipped; each has a reason. This is a manual fix, or needs the original to change first.', 'linguator-pro' );
		}

		if ( $dry_run ) {
			return __( 'Nothing was changed. Show the user this list and, once they agree, call again with dry_run false.', 'linguator-pro' );
		}

		return $done;
	}

	/**
	 * A post in the shape fix-issue reports items.
	 *
	 * @since 1.2.0
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
			'language'    => $post['language'],
			'edit_url'    => $post['edit_url'],
		);
	}

	/**
	 * A term in the shape fix-issue reports items.
	 *
	 * @since 1.2.0
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
			'language'    => $term['language'],
			'edit_url'    => $term['edit_url'],
		);
	}

	/**
	 * Error for a post type or taxonomy Linguator does not translate.
	 *
	 * @since 1.2.0
	 * @param string $what post_type or taxonomy.
	 * @param string $slug Its name.
	 * @return WP_Error
	 */
	private static function not_translatable( $what, $slug ) {
		return new WP_Error(
			'lmat_not_translatable',
			sprintf(
				/* translators: 1: post_type or taxonomy, 2: slug. */
				__( '%1$s "%2$s" is not translated by Linguator, so its items cannot get a language. It must first be switched on in the Linguator settings.', 'linguator-pro' ),
				$what,
				$slug
			),
			array( 'status' => 400 )
		);
	}
}
