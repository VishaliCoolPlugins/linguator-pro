<?php
/**
 * AutoPoly re-translation tracking, read and kept in step.
 *
 * @package AutoPoly
 * @since   1.11.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Services;

use WP_Error;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports translations whose source changed, and keeps MCP translations inside
 * AutoPoly's tracking.
 *
 * AutoPoly tracks a translation only when its translation log
 * (cpt_dashboard_data) lists it. Editing the source then stores a snapshot of
 * the source on each listed translation, and
 * ATFP_Re_Translation::retranslation_status() compares that snapshot with the
 * source as it is now. Everything here goes through those AutoPoly methods
 * rather than reading the snapshots itself, so the answer always matches
 * AutoPoly's own re-translate prompt.
 *
 * @since 1.11.0
 */
final class Retranslation_Service {

	/**
	 * Post meta naming the source a translation's snapshot was taken from.
	 *
	 * Written by ATFP_Re_Translation. Read here only to find tracked
	 * translations; the comparison itself is AutoPoly's.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const SOURCE_META = '_atfpp_updated_post_id';

	/**
	 * Default and maximum page sizes. Checking one item can load the source's
	 * whole page-builder document, so pages stay small.
	 *
	 * @since 1.11.0
	 * @var int
	 */
	const DEFAULT_LIMIT = 20;
	const MAX_LIMIT     = 50;

	/**
	 * Translation log prefix and the provider name MCP translations are logged under.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const DASHBOARD_PREFIX   = 'atfp';
	const DASHBOARD_PROVIDER = 'mcp';

	/**
	 * Whether AutoPoly's re-translation methods are loaded.
	 *
	 * @since 1.11.0
	 * @return bool True when they can be called.
	 */
	public static function is_available() {
		return is_callable( array( 'ATFP_Re_Translation', 'retranslation_status' ) )
			&& is_callable( array( 'ATFP_Re_Translation', 'get_updated_post_metas' ) );
	}

	/**
	 * Whether re-translation tracking is switched on.
	 *
	 * The same comparison ATFP_Re_Translation makes, so this never disagrees
	 * with the code that acts on the setting.
	 *
	 * @since 1.11.0
	 * @return bool True when tracking is on.
	 */
	public static function tracking_enabled() {
		return 'false' !== get_option( 'atfp_retranslation_enabled', true );
	}

	/**
	 * Lists tracked translations whose source changed.
	 *
	 * Items the caller cannot edit, or whose source the caller cannot edit, are
	 * left out before AutoPoly is asked about them, so nothing is read or
	 * cleaned up on their behalf.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $filters  language, post_type, source_post_id, limit, offset.
	 * @param callable             $can_edit Receives a post ID, returns whether the caller may edit it.
	 * @return array<string, mixed>|WP_Error Report, or an error.
	 */
	public static function find_outdated( array $filters, callable $can_edit ) {
		if ( ! Polylang_Guard::model_ready() || ! function_exists( 'pll_get_post_translations' ) || ! function_exists( 'pll_get_post_language' ) ) {
			return Polylang_Guard::unavailable_error();
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'atfpp_retranslation_unavailable',
				__( 'AutoPoly\'s re-translation tracking is not loaded in this request.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		$limit  = isset( $filters['limit'] ) ? max( 1, min( (int) $filters['limit'], self::MAX_LIMIT ) ) : self::DEFAULT_LIMIT;
		$offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;

		if ( ! self::tracking_enabled() ) {
			return array(
				'tracking_enabled' => false,
				'items'            => array(),
				'notice'           => __( 'Re-translation tracking is switched off in AutoPoly\'s settings, so AutoPoly records nothing about edited sources and no translation can be reported as out of date. This does not mean every translation is current. No AutoPoly tool can switch it on: tell the user a site administrator can enable it under Languages > AutoPoly > Settings > Enable Re-Translation.','autopoly-ai-translation-for-polylang-pro' ),
			);
		}

		$query_args = self::build_query_args( $filters, $limit, $offset );

		if ( is_wp_error( $query_args ) ) {
			return $query_args;
		}

		$query     = new WP_Query( $query_args );
		$posts     = is_array( $query->posts ) ? $query->posts : array();
		$items     = array();
		$skipped   = array();
		$current   = 0;
		$survivors = 0;

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$outcome = self::check( $post, $can_edit );

			if ( isset( $outcome['item'] ) ) {
				$items[] = $outcome['item'];
			} elseif ( isset( $outcome['skipped'] ) ) {
				$skipped[] = $outcome['skipped'];
			} elseif ( ! empty( $outcome['current'] ) ) {
				++$current;
			}

			// AutoPoly drops tracking for a current translation, which shifts later offsets.
			if ( '' !== (string) get_post_meta( (int) $post->ID, self::SOURCE_META, true ) ) {
				++$survivors;
			}
		}

		$total    = (int) $query->found_posts;
		$has_more = ( $offset + count( $posts ) ) < $total;

		$report = array(
			'tracking_enabled' => true,
			'items'            => $items,
			'skipped'          => $skipped,
			'found_current'    => $current,
			'checked'          => count( $posts ),
			'tracked_total'    => $total,
			'has_more'         => $has_more,
		);

		if ( $has_more ) {
			$report['next_offset'] = $offset + $survivors;
		}

		$report['notice'] = __( 'Only translations in AutoPoly\'s translation log are tracked: those made in AutoPoly\'s editor, by its bulk translation, or by translate-post. "changed" says which parts of the source differ from when the translation was last made. To update one, call get-content on source.id with target_language set to the item\'s language, then translate-post with that source_post_id and target_language; it updates the translation in place and removes it from this list. "found_current" counts tracked translations whose source edits turned out to be reverted; AutoPoly cleared their tracking. Paginate with next_offset, not offset + limit, because cleared items leave the list.', 'autopoly-ai-translation-for-polylang-pro' );

		return $report;
	}

	/**
	 * Builds the query selecting tracked translations.
	 *
	 * Ordered by ID so that pages stay stable while items leave the set.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $filters Filters.
	 * @param int                  $limit   Page size.
	 * @param int                  $offset  Items to skip.
	 * @return array<string, mixed>|WP_Error Query arguments, or an error for a bad filter.
	 */
	private static function build_query_args( array $filters, $limit, $offset ) {
		$post_types = array_values( (array) PLL()->model->get_translated_post_types() );
		$post_type  = isset( $filters['post_type'] ) ? sanitize_key( (string) $filters['post_type'] ) : '';

		if ( '' !== $post_type ) {
			if ( ! in_array( $post_type, $post_types, true ) ) {
				return new WP_Error(
					'atfpp_invalid_post_type',
					post_type_exists( $post_type )
						? Polylang_Guard::not_translated_message( 'post', $post_type )
						: sprintf(
							/* translators: %s: post type name. */
							__( 'Post type "%s" does not exist on this site. Call list-content-types for the valid names.', 'autopoly-ai-translation-for-polylang-pro' ),
							$post_type
						)
				);
			}

			$post_types = array( $post_type );
		}

		if ( array() === $post_types ) {
			return new WP_Error(
				'atfpp_no_translated_post_types',
				sprintf(
					/* translators: %s: settings location. */
					__( 'Polylang translates no post types on this site, so nothing can be tracked. Tell the user a site administrator chooses them under %s.', 'autopoly-ai-translation-for-polylang-pro' ),
					Polylang_Guard::content_types_setting_location()
				)
			);
		}

		$meta_query = array(
			'key'     => self::SOURCE_META,
			'compare' => 'EXISTS',
		);

		$source_id = isset( $filters['source_post_id'] ) ? absint( $filters['source_post_id'] ) : 0;

		if ( $source_id > 0 ) {
			$meta_query = array(
				'key'   => self::SOURCE_META,
				'value' => (string) $source_id,
			);
		}

		$args = array(
			'post_type'              => $post_types,
			'post_status'            => Content_Service::STATUSES,
			'posts_per_page'         => $limit,
			'offset'                 => $offset,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => false,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'lang'                   => '',
			'meta_query'             => array( $meta_query ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One indexed meta_key lookup, paginated.
		);

		$language_slug = isset( $filters['language'] ) ? (string) $filters['language'] : '';

		if ( '' !== trim( $language_slug ) ) {
			$language = Language_Service::get_by_slug( $language_slug );

			if ( null === $language ) {
				return new WP_Error(
					'atfpp_invalid_language',
					sprintf(
						/* translators: %s: language slug. */
						__( 'Language "%s" was not found.', 'autopoly-ai-translation-for-polylang-pro' ),
						sanitize_key( $language_slug )
					)
				);
			}

			$tt_id = method_exists( $language, 'get_tax_prop' ) ? (int) $language->get_tax_prop( 'language', 'term_taxonomy_id' ) : 0;

			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Indexed language lookup.
				0 !== $tt_id
					? array(
						'taxonomy' => 'language',
						'field'    => 'term_taxonomy_id',
						'terms'    => array( $tt_id ),
					)
					: array(
						'taxonomy' => 'language',
						'field'    => 'slug',
						'terms'    => array( $language->slug ),
					),
			);
		}

		return $args;
	}

	/**
	 * Asks AutoPoly whether one tracked translation is out of date.
	 *
	 * retranslation_status() is only ever called for a translation that has a
	 * valid, linked source recorded. Without one it falls back to guessing
	 * against the default-language post, which would report translations as
	 * out of date when nothing changed.
	 *
	 * @since 1.11.0
	 * @param WP_Post  $post     Tracked translation.
	 * @param callable $can_edit Permission check.
	 * @return array<string, mixed> One of: item, skipped, current, or empty when hidden.
	 */
	private static function check( WP_Post $post, callable $can_edit ) {
		$id = (int) $post->ID;

		if ( ! $can_edit( $id ) ) {
			return array();
		}

		$source_id = (int) get_post_meta( $id, self::SOURCE_META, true );

		if ( $source_id <= 0 ) {
			return array( 'skipped' => self::skip( $post, 0, 'invalid_tracking', __( 'AutoPoly\'s tracking data on this translation does not name a source post.', 'autopoly-ai-translation-for-polylang-pro' ) ) );
		}

		$source = get_post( $source_id );

		if ( ! $source instanceof WP_Post ) {
			return array( 'skipped' => self::skip( $post, $source_id, 'source_missing', __( 'The source post this translation was tracked against no longer exists.', 'autopoly-ai-translation-for-polylang-pro' ) ) );
		}

		if ( 'trash' === $source->post_status ) {
			return array( 'skipped' => self::skip( $post, $source_id, 'source_trashed', __( 'The source post this translation was tracked against is in the trash.', 'autopoly-ai-translation-for-polylang-pro' ) ) );
		}

		if ( ! $can_edit( $source_id ) ) {
			return array();
		}

		$group = array_map( 'intval', (array) pll_get_post_translations( $id ) );

		if ( ! in_array( $source_id, $group, true ) ) {
			return array( 'skipped' => self::skip( $post, $source_id, 'source_not_linked', __( 'The post this translation was tracked against is no longer linked to it as a translation, so it is not compared.', 'autopoly-ai-translation-for-polylang-pro' ) ) );
		}

		$status = \ATFP_Re_Translation::retranslation_status( $id );

		if ( is_array( $status ) && ! empty( $status['re_translation_status'] ) ) {
			return array( 'item' => self::describe_outdated( $post, $source, $status ) );
		}

		// AutoPoly clears the tracking of a translation it found current.
		if ( '' === (string) get_post_meta( $id, self::SOURCE_META, true ) ) {
			return array( 'current' => true );
		}

		return array( 'skipped' => self::skip( $post, $source_id, 'not_comparable', __( 'AutoPoly could not compare this translation with its source, for example because the page builder the source was tracked with is not active.', 'autopoly-ai-translation-for-polylang-pro' ) ) );
	}

	/**
	 * Describes an out-of-date translation.
	 *
	 * @since 1.11.0
	 * @param WP_Post              $post   Translation.
	 * @param WP_Post              $source Source.
	 * @param array<string, mixed> $status retranslation_status() result.
	 * @return array<string, mixed> Item.
	 */
	private static function describe_outdated( WP_Post $post, WP_Post $source, array $status ) {
		$item = self::describe_post( $post );

		$item['source']  = self::describe_post( $source );
		$item['changed'] = array(
			'title'         => ! empty( $status['re_translate_title'] ),
			'content'       => ! empty( $status['re_translate_content'] ),
			'excerpt'       => ! empty( $status['re_translate_excerpt'] ),
			'custom_fields' => ! empty( $status['re_translate_metaFields'] ),
		);

		if ( $item['changed']['custom_fields'] ) {
			// May be empty: AutoPoly also counts ACF field-structure changes, which have no single key.
			$item['changed_custom_fields'] = self::changed_meta_keys( (int) $post->ID, (int) $source->ID );
		}

		return $item;
	}

	/**
	 * Names the source custom fields that changed since the snapshot.
	 *
	 * @since 1.11.0
	 * @param int $translation_id Translation ID.
	 * @param int $source_id      Source ID.
	 * @return string[] Meta keys.
	 */
	private static function changed_meta_keys( $translation_id, $source_id ) {
		$changed = \ATFP_Re_Translation::get_updated_post_metas( $translation_id, $source_id );
		$keys    = array();

		foreach ( array_keys( is_array( $changed ) ? $changed : array() ) as $key ) {
			$key = (string) $key;

			// AutoPoly splits WooCommerce attributes into one entry per value.
			if ( 0 === strpos( $key, '_product_attributes_atfpp_' ) ) {
				$key = '_product_attributes';
			}

			$keys[ $key ] = true;
		}

		return array_keys( $keys );
	}

	/**
	 * Builds a skipped entry.
	 *
	 * @since 1.11.0
	 * @param WP_Post $post      Translation.
	 * @param int     $source_id Recorded source, 0 when none.
	 * @param string  $reason    Machine-readable reason.
	 * @param string  $message   Explanation.
	 * @return array<string, mixed> Entry.
	 */
	private static function skip( WP_Post $post, $source_id, $reason, $message ) {
		$entry = self::describe_post( $post );

		$entry['source_id'] = $source_id;
		$entry['reason']    = $reason;
		$entry['message']   = $message;

		return $entry;
	}

	/**
	 * Describes a post.
	 *
	 * @since 1.11.0
	 * @param WP_Post $post Post.
	 * @return array<string, mixed> Description.
	 */
	private static function describe_post( WP_Post $post ) {
		return array(
			'id'        => (int) $post->ID,
			'title'     => $post->post_title,
			'post_type' => $post->post_type,
			'status'    => $post->post_status,
			'language'  => (string) pll_get_post_language( (int) $post->ID, 'slug' ),
			'modified'  => $post->post_modified,
		);
	}

	/**
	 * Logs an MCP translation in AutoPoly's translation log.
	 *
	 * The log is what AutoPoly's re-translation tracking covers, so an
	 * unlogged translation is never reported as out of date. It also feeds the
	 * dashboard totals. Goes through Atfpp_Dashboard::store_options(), the call
	 * AutoPoly's editor flow makes, with the same fields.
	 *
	 * Counts are measured on plain text: characters, whitespace-separated words,
	 * and the number of non-empty strings.
	 *
	 * @since 1.11.0
	 * @param WP_Post  $source           Source post.
	 * @param int      $translation_id   Translation post ID.
	 * @param string   $source_lang      Source language slug.
	 * @param string   $target_lang      Target language slug.
	 * @param string[] $translated_texts Texts written to the translation.
	 * @return bool True when logged.
	 */
	public static function record_translation( WP_Post $source, $translation_id, $source_lang, $target_lang, array $translated_texts ) {
		if ( ! is_callable( array( 'Atfpp_Dashboard', 'store_options' ) ) ) {
			return false;
		}

		$translated = self::measure( $translated_texts );
		$original   = self::measure( array( $source->post_title, $source->post_content, $source->post_excerpt ) );

		\Atfpp_Dashboard::store_options(
			self::DASHBOARD_PREFIX,
			'post_id',
			'update',
			array(
				'post_id'                => (int) $translation_id,
				'service_provider'       => self::DASHBOARD_PROVIDER,
				'source_language'        => (string) $source_lang,
				'target_language'        => (string) $target_lang,
				'time_taken'             => 0,
				'string_count'           => $translated['strings'],
				'word_count'             => $translated['words'],
				'character_count'        => $translated['characters'],
				'source_string_count'    => $original['strings'],
				'source_word_count'      => $original['words'],
				'source_character_count' => $original['characters'],
				'editor_type'            => self::editor_type( $source ),
				'date_time'              => gmdate( 'Y-m-d H:i:s' ),
				'version_type'           => 'pro',
			)
		);

		return true;
	}

	/**
	 * Marks a translation as brought up to date.
	 *
	 * What AutoPoly's own flow does after a re-translation
	 * (ATFP_Register_Route, via delete_re_translation_data()). Needed because
	 * translate-post deliberately keeps AutoPoly's edit hook from firing.
	 *
	 * @since 1.11.0
	 * @param int $translation_id Translation post ID.
	 * @return void
	 */
	public static function clear_outdated( $translation_id ) {
		if ( is_callable( array( 'ATFP_Re_Translation', 'delete_re_translation_data' ) ) ) {
			\ATFP_Re_Translation::delete_re_translation_data( (int) $translation_id );
		}
	}

	/**
	 * The editor name AutoPoly's log uses for a post.
	 *
	 * @since 1.11.0
	 * @param WP_Post $source Source post.
	 * @return string Editor name.
	 */
	private static function editor_type( WP_Post $source ) {
		$builder = Builder_Service::detect_editor_type( (int) $source->ID );

		if ( '' !== $builder ) {
			return $builder;
		}

		return has_blocks( $source->post_content ) ? 'gutenberg' : 'classic';
	}

	/**
	 * Counts strings, words and characters of plain text.
	 *
	 * @since 1.11.0
	 * @param array<int, mixed> $texts Texts, possibly HTML.
	 * @return array{strings: int, words: int, characters: int} Counts.
	 */
	private static function measure( array $texts ) {
		$counts = array(
			'strings'    => 0,
			'words'      => 0,
			'characters' => 0,
		);

		foreach ( $texts as $text ) {
			if ( ! is_scalar( $text ) ) {
				continue;
			}

			$plain = trim( wp_strip_all_tags( (string) $text ) );

			if ( '' === $plain ) {
				continue;
			}

			$words = preg_split( '/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY );

			++$counts['strings'];
			$counts['words']      += is_array( $words ) ? count( $words ) : 0;
			$counts['characters'] += function_exists( 'mb_strlen' ) ? mb_strlen( $plain, 'UTF-8' ) : strlen( $plain );
		}

		return $counts;
	}
}
