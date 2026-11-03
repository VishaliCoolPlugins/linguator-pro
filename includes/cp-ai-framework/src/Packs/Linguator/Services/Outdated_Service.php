<?php
/**
 * Finds translations that are out of date with their source post.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Services;

use LinguatorPro\Modules\Scan\Freshness;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares the change time of a source post with that of each of its translations.
 *
 * Linguator has no re-translation log. Linguator Pro does track, in its scan module, the time of
 * the last real text change of every post (a fingerprint of title and body, not the raw modified
 * date) and a saved copy of the source as it was when the translation was last up to date. This
 * service reuses that: a translation is outdated when its source text changed after its own.
 *
 * @since 1.2.0
 */
final class Outdated_Service {

	/**
	 * Translations checked per call when none is asked for.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const DEFAULT_LIMIT = 20;

	/**
	 * Most translations checked per call.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MAX_LIMIT = 50;

	/**
	 * Whether the freshness tracking of Linguator Pro is loaded.
	 *
	 * @since 1.2.0
	 * @return bool True when it is.
	 */
	public static function is_available() {
		return class_exists( Freshness::class );
	}

	/**
	 * Lists translations whose source changed after they were last up to date.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $filters  language, post_type, source_post_id, limit and offset.
	 * @param callable             $can_edit Called with a post ID; true when the user may edit it.
	 * @return array<string, mixed>|WP_Error Report, or an error.
	 */
	public static function find_outdated( array $filters, callable $can_edit ) {
		$model = Linguator_Guard::model();

		if ( null === $model ) {
			return new WP_Error( 'lmat_not_ready', __( 'Linguator is not loaded.', 'linguator-pro' ) );
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'lmat_freshness_unavailable',
				__( 'Linguator Pro\'s content change tracking is not loaded in this request, so outdated translations cannot be determined.', 'linguator-pro' )
			);
		}

		$limit   = isset( $filters['limit'] ) ? max( 1, min( (int) $filters['limit'], self::MAX_LIMIT ) ) : self::DEFAULT_LIMIT;
		$offset  = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;
		$default = (string) linguator_default_language();
		$targets = Language_Service::target_slugs();

		$language = isset( $filters['language'] ) ? sanitize_key( (string) $filters['language'] ) : '';

		if ( '' !== $language ) {
			$known = Linguator_Guard::language( $language );

			if ( is_wp_error( $known ) ) {
				return $known;
			}

			if ( $language === $default ) {
				return new WP_Error(
					'lmat_same_language',
					__( 'The default language holds the source posts, so none of its posts is a translation. Choose another language.', 'linguator-pro' )
				);
			}

			$targets = array( $language );
		}

		$types     = Settings_Service::post_types();
		$post_type = isset( $filters['post_type'] ) ? sanitize_key( (string) $filters['post_type'] ) : '';

		if ( '' !== $post_type ) {
			if ( ! in_array( $post_type, $types, true ) ) {
				return new WP_Error(
					'lmat_invalid_post_type',
					post_type_exists( $post_type )
						? Linguator_Guard::not_translated_message( $post_type )
						: sprintf(
							/* translators: %s: post type name. */
							__( 'Post type "%s" does not exist on this site. Call list-content-types for the valid names.', 'linguator-pro' ),
							$post_type
						)
				);
			}

			$types = array( $post_type );
		}

		$source_filter = isset( $filters['source_post_id'] ) ? absint( $filters['source_post_id'] ) : 0;
		$sources       = array();

		if ( $source_filter > 0 ) {
			$source = get_post( $source_filter );

			if ( ! $source instanceof WP_Post || ! in_array( $source->post_type, $types, true ) || $default !== (string) linguator_get_post_language( $source_filter ) ) {
				return new WP_Error(
					'lmat_invalid_source',
					__( 'source_post_id must be a post in the default language, of a translated post type.', 'linguator-pro' )
				);
			}

			$sources = array( $source_filter );
		} else {
			foreach ( $types as $type ) {
				$sources = array_merge( $sources, Content_Service::post_ids( $type, $default ) );
			}
		}

		// Every source and target pair that has a translation, in a stable order.
		$pairs = array();

		foreach ( $sources as $source_id ) {
			$translations = linguator_get_post_translations( (int) $source_id );

			foreach ( $targets as $slug ) {
				$translation_id = (int) ( $translations[ $slug ] ?? 0 );
				$status         = $translation_id > 0 ? get_post_status( $translation_id ) : false;

				if ( $translation_id > 0 && $translation_id !== (int) $source_id && $status && 'trash' !== $status ) {
					$pairs[] = array( (int) $source_id, $translation_id, (string) $slug );
				}
			}
		}

		$total   = count( $pairs );
		$window  = array_slice( $pairs, $offset, $limit );
		$fresh   = new Freshness( $model );
		$items   = array();
		$current = 0;

		foreach ( $window as $pair ) {
			list( $source_id, $translation_id, $slug ) = $pair;

			if ( ! $can_edit( $translation_id ) || ! $can_edit( $source_id ) ) {
				continue;
			}

			$source_at      = $fresh->changed_at( $source_id );
			$translation_at = $fresh->changed_at( $translation_id );

			if ( 'source_updated' !== $fresh->classify( $source_id, $slug, $source_at, $translation_at ) ) {
				++$current;
				continue;
			}

			$items[] = self::describe_outdated( $fresh, $source_id, $translation_id, $slug, $source_at, $translation_at );
		}

		$checked  = count( $window );
		$has_more = ( $offset + $checked ) < $total;
		$report   = array(
			'method'        => 'source_change_time',
			'items'         => $items,
			'found_current' => $current,
			'checked'       => $checked,
			'pairs_total'   => $total,
			'has_more'      => $has_more,
		);

		if ( $has_more ) {
			$report['next_offset'] = $offset + $checked;
		}

		$report['notice'] = __( 'Linguator keeps no re-translation log, so this compares when the text of each source post last really changed (title and body, or Elementor text; a save that changes nothing does not count) with the same time for its translation. A translation is outdated when its source changed more than a minute after its own last change. "changed" says which parts differ from the saved copy of the source taken when the translation was last up to date: true or false when that copy exists, null when it cannot be determined (no copy was saved, or the field is not tracked). Excerpt is checked only against a revision of the source, and custom fields are never compared, so null there means "unknown", not "unchanged". To update one, call get-content on source.id with target_language set to the item\'s language, then translate-post with that source_post_id and target_language; saving the translation makes it newer than its source and removes it from this list. Only translations linked to a default-language source are compared; terms are not tracked. Paginate with next_offset. Reading this list stamps a change time on posts seen for the first time, which is the only thing written.', 'linguator-pro' );

		return $report;
	}

	/**
	 * Describes one outdated translation and what changed in its source.
	 *
	 * @since 1.2.0
	 * @param Freshness $fresh          Freshness tracker.
	 * @param int       $source_id      Source post ID.
	 * @param int       $translation_id Translation post ID.
	 * @param string    $slug           Translation language slug.
	 * @param int       $source_at      Source change time, Unix GMT.
	 * @param int       $translation_at Translation change time, Unix GMT.
	 * @return array<string, mixed> Item.
	 */
	private static function describe_outdated( Freshness $fresh, $source_id, $translation_id, $slug, $source_at, $translation_at ) {
		$source      = get_post( $source_id );
		$translation = get_post( $translation_id );
		$info        = self::read_changes( $fresh, $source_id, $translation_id, $slug );
		$changes     = $info['changes'];
		$known       = $info['known'];

		$title   = null;
		$content = null;

		if ( $known ) {
			$title   = false;
			$content = false;

			foreach ( $changes as $change ) {
				if ( 'title' === $change['key'] ) {
					$title = true;
				} else {
					$content = true;
				}
			}
		}

		// Without a saved copy only the title and plain body can be compared, never Elementor text.
		if ( $known && $info['partial'] && Template_Service::is_elementor_post( $source_id ) ) {
			$content = null;
		}

		$item           = self::describe_post( $translation );
		$item['source'] = self::describe_post( $source );

		$item['source_changed_at']      = gmdate( 'Y-m-d H:i:s', $source_at );
		$item['translation_changed_at'] = gmdate( 'Y-m-d H:i:s', $translation_at );
		$item['lag_seconds']            = max( 0, $source_at - $translation_at );
		$item['changed']                = array(
			'title'         => $title,
			'content'       => $content,
			'excerpt'       => $info['excerpt'],
			'custom_fields' => null,
		);
		$item['changed_fields']         = array_values( array_map( 'strval', wp_list_pluck( $changes, 'label' ) ) );
		$item['baseline']               = $known ? ( $info['partial'] ? 'source_revision' : 'saved_copy' ) : 'none';

		if ( ! $known ) {
			$item['note'] = __( 'The source was saved after this translation was last up to date, but no earlier copy of the source exists, so what changed cannot be determined. Compare the source with the translation.', 'linguator-pro' );
		} elseif ( array() === $changes ) {
			$item['note'] = __( 'The source was saved after this translation was last up to date, but no difference was found in the tracked text. Re-translate it only if you know the source changed in a field that is not tracked.', 'linguator-pro' );
		}

		return $item;
	}

	/**
	 * Reads which tracked fields of the source differ from the baseline.
	 *
	 * Uses Freshness's own comparison, which is private, so the result always agrees with the
	 * "what changed" panel of the translation editor.
	 *
	 * @since 1.2.0
	 * @param Freshness $fresh          Freshness tracker.
	 * @param int       $source_id      Source post ID.
	 * @param int       $translation_id Translation post ID.
	 * @param string    $slug           Translation language slug.
	 * @return array{known: bool, partial: bool, changes: array<int, array<string, string>>, excerpt: bool|null} Changes.
	 */
	private static function read_changes( Freshness $fresh, $source_id, $translation_id, $slug ) {
		$result = array(
			'known'   => false,
			'partial' => false,
			'changes' => array(),
			'excerpt' => null,
		);

		try {
			$compute = new \ReflectionMethod( $fresh, 'compute_changes' );
			$compute->setAccessible( true );
			$info = $compute->invoke( $fresh, $source_id, $translation_id, $slug );

			if ( is_array( $info ) ) {
				$result['known']   = ! empty( $info['known'] );
				$result['partial'] = ! empty( $info['partial'] );
				$result['changes'] = isset( $info['changes'] ) && is_array( $info['changes'] ) ? $info['changes'] : array();
			}

			$pair = new \ReflectionMethod( $fresh, 'get_pair_sync' );
			$pair->setAccessible( true );
			$sync_ts = (int) $pair->invoke( $fresh, $source_id, $slug );

			$baseline = new \ReflectionMethod( $fresh, 'baseline_revision' );
			$baseline->setAccessible( true );
			$revision = $baseline->invoke( $fresh, $source_id, $sync_ts );
			$source   = get_post( $source_id );

			if ( $revision instanceof WP_Post && $source instanceof WP_Post ) {
				$result['excerpt'] = (string) $revision->post_excerpt !== (string) $source->post_excerpt;
			}
		} catch ( \ReflectionException $e ) {
			return $result;
		}

		return $result;
	}

	/**
	 * A short description of a post.
	 *
	 * @since 1.2.0
	 * @param WP_Post|null $post Post.
	 * @return array<string, mixed> Descriptor.
	 */
	private static function describe_post( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		return array(
			'id'        => (int) $post->ID,
			'title'     => $post->post_title,
			'post_type' => $post->post_type,
			'status'    => $post->post_status,
			'language'  => (string) linguator_get_post_language( (int) $post->ID ),
			'modified'  => $post->post_modified,
		);
	}
}
