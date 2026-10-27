<?php
/**
 * Tracks whether a translation is still up to date with its default-language source.
 *
 * @package Linguator
 */

namespace Linguator\Modules\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Linguator\Includes\Other\Linguator_Model;

/**
 * Content change markers, "last up to date" snapshots and the "what changed" diff.
 *
 * A post's change time moves only when its text really changes (not on every save), so a
 * translation counts as outdated when the source text changed after the translation's own.
 * A plain-text snapshot of the source is kept on each translation to show what changed.
 *
 * @since 2.3.0
 */
class Freshness {

	const META_FP        = '_lmat_content_fp';
	const META_CHANGED   = '_lmat_content_changed_at';
	const META_PAIR_SYNC = '_lmat_pair_sync';
	const META_SNAPSHOT  = '_lmat_sync_snapshot';

	/**
	 * Seconds of lag below which two posts count as in sync.
	 */
	const THRESHOLD = 60;

	/**
	 * Elementor setting keys that hold text a translator edits.
	 */
	const ELEMENTOR_TEXT_KEYS = array( 'title', 'editor', 'text', 'description', 'content', 'sub_title', 'title_text', 'description_text', 'button_text', 'heading', 'caption', 'html', 'alert_title', 'alert_description', 'tab_title', 'tab_content', 'item_description', 'testimonial_content' );

	/**
	 * HTML kept from wp_text_diff().
	 *
	 * @var array
	 */
	private static $diff_allowed_html = array(
		'table'    => array( 'class' => true ),
		'colgroup' => array(),
		'col'      => array( 'class' => true ),
		'thead'    => array(),
		'tbody'    => array(),
		'tr'       => array(),
		'th'       => array(
			'class' => true,
			'scope' => true,
		),
		'td'       => array(
			'class'   => true,
			'colspan' => true,
		),
		'del'      => array(),
		'ins'      => array(),
		'span'     => array(
			'class'       => true,
			'aria-hidden' => true,
		),
	);

	/**
	 * Linguator model.
	 *
	 * @var Linguator_Model
	 */
	private $model;

	/**
	 * Post ids already captured during this request.
	 *
	 * @var array<int,bool>
	 */
	private static $captured = array();

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Linguator_Model $model Linguator model.
	 */
	public function __construct( Linguator_Model $model ) {
		$this->model = $model;
	}

	/**
	 * Hooks that keep a copy of the source before it is edited.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'pre_post_update', array( __CLASS__, 'capture_before_update' ), 10, 1 );
	}

	/**
	 * Before a default-language post is saved, keep a copy for every translation that has none yet.
	 *
	 * @since 2.3.0
	 *
	 * @param int $post_id Post being updated.
	 * @return void
	 */
	public static function capture_before_update( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || isset( self::$captured[ $post_id ] ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! function_exists( 'LMAT' ) ) {
			return;
		}

		$lmat = LMAT();
		if ( empty( $lmat->model ) ) {
			return;
		}

		$model   = $lmat->model;
		$default = $model->get_default_language();
		$lang    = $model->post->get_language( $post_id );
		if ( ! $default || ! $lang || $lang->slug !== $default->slug || ! in_array( get_post_type( $post_id ), (array) $model->get_translated_post_types(), true ) ) {
			return;
		}

		self::$captured[ $post_id ] = true;
		$fresh                      = new self( $model );

		foreach ( (array) $model->post->get_translations( $post_id ) as $slug => $translation_id ) {
			$translation_id = (int) $translation_id;
			if ( $slug === $default->slug || $translation_id <= 0 || $translation_id === $post_id || $fresh->get_snapshot( $translation_id ) ) {
				continue;
			}
			$fresh->save_snapshot( $post_id, $translation_id );
		}
	}

	/**
	 * Unix GMT time of the post's last real text change.
	 *
	 * The first time a post is seen its own modified time is used, so an edit made before the
	 * first scan is still visible.
	 *
	 * @since 2.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return int 0 when the post does not exist.
	 */
	public function changed_at( int $post_id ): int {
		clean_post_cache( $post_id );
		wp_cache_delete( $post_id, 'post_meta' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return 0;
		}

		$modified = 0;
		if ( ! empty( $post->post_modified_gmt ) && '0000-00-00 00:00:00' !== $post->post_modified_gmt ) {
			$modified = (int) strtotime( $post->post_modified_gmt . ' UTC' );
		}

		$fingerprint = $this->fingerprint( $post );
		$stored_fp   = (string) get_post_meta( $post_id, self::META_FP, true );
		$stored_at   = (int) get_post_meta( $post_id, self::META_CHANGED, true );

		if ( '' !== $stored_fp && hash_equals( $stored_fp, $fingerprint ) && $stored_at > 0 ) {
			return $stored_at;
		}

		// Stamp with the post's own modified time, never "now": a scan that only now notices an old
		// edit must not make it look fresh, or both sides of a pair would land inside the threshold.
		$changed = $modified > 0 ? $modified : time();
		update_post_meta( $post_id, self::META_FP, $fingerprint );
		update_post_meta( $post_id, self::META_CHANGED, $changed );

		return $changed;
	}

	/**
	 * Verdict for a default/translation pair.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $root_id      Default-language post ID.
	 * @param string $lang         Translation language slug.
	 * @param int    $default_ts   Source change time.
	 * @param int    $secondary_ts Translation change time.
	 * @return string `ok` or `source_updated`.
	 */
	public function classify( int $root_id, string $lang, int $default_ts, int $secondary_ts ): string {
		if ( $root_id <= 0 || '' === $lang || $default_ts <= 0 || $secondary_ts <= 0 ) {
			return 'ok';
		}

		if ( abs( $default_ts - $secondary_ts ) <= self::THRESHOLD ) {
			$this->set_pair_sync( $root_id, $lang, max( $default_ts, $secondary_ts ) );
			return 'ok';
		}

		if ( ( $secondary_ts + self::THRESHOLD ) < $default_ts ) {
			// Anchor the baseline at the lagging translation so a later catch-up is not misread.
			if ( $this->get_pair_sync( $root_id, $lang ) <= 0 ) {
				$this->set_pair_sync( $root_id, $lang, $secondary_ts );
			}
			return 'source_updated';
		}

		// Translation newer than source (machine or manual translate): in sync.
		$this->set_pair_sync( $root_id, $lang, max( $default_ts, $secondary_ts ) );
		return 'ok';
	}

	/**
	 * Marks a translation as up to date with its source.
	 *
	 * The translation's change time moves to now (never before the source's) and the source text
	 * becomes the new "last up to date" snapshot.
	 *
	 * @since 2.3.0
	 *
	 * @param int $translation_id Translation post ID.
	 * @return array{source_id:int,lang:string}|false
	 */
	public function mark_synced( int $translation_id ) {
		$context = $this->context( $translation_id );
		if ( ! $context ) {
			return false;
		}

		$source_ts = $this->changed_at( $context['source_id'] );
		$this->changed_at( $translation_id );
		$now = max( time(), $source_ts + 1 );

		$this->set_pair_sync( $context['source_id'], $context['lang'], $now );
		update_post_meta( $translation_id, self::META_CHANGED, $now );
		$this->save_snapshot( $context['source_id'], $translation_id, true );

		clean_post_cache( $context['source_id'] );
		clean_post_cache( $translation_id );

		return array(
			'source_id' => $context['source_id'],
			'lang'      => $context['lang'],
		);
	}

	/**
	 * Data for the "what changed" dialog.
	 *
	 * @since 2.3.0
	 *
	 * @param int  $translation_id Translation post ID.
	 * @param bool $split          Side-by-side (true) or inline (false).
	 * @return array|\WP_Error
	 */
	public function diff( int $translation_id, bool $split = true ) {
		$context = $this->context( $translation_id );
		if ( ! $context ) {
			return new \WP_Error( 'lmat_scan_diff_context', __( 'This translation is not linked to a post in the default language.', 'linguator-pro' ), array( 'status' => 400 ) );
		}

		$source = get_post( $context['source_id'] );
		$target = get_post( $translation_id );
		if ( ! $source || ! $target ) {
			return new \WP_Error( 'lmat_scan_diff_missing', __( 'Source post not found.', 'linguator-pro' ), array( 'status' => 404 ) );
		}

		$info   = $this->compute_changes( $context['source_id'], $translation_id, $context['lang'] );
		$fields = array();

		foreach ( $info['changes'] as $change ) {
			$key       = $change['key'];
			$diff_html = wp_text_diff(
				isset( $info['old'][ $key ] ) ? (string) $info['old'][ $key ] : '',
				isset( $info['new'][ $key ] ) ? (string) $info['new'][ $key ] : '',
				array(
					'title_left'      => __( 'At last sync', 'linguator-pro' ),
					'title_right'     => __( 'Now', 'linguator-pro' ),
					'show_split_view' => $split,
				)
			);

			$fields[] = array(
				'key'       => $key,
				'label'     => $change['label'],
				'type'      => $change['type'],
				'diff'      => '' !== $diff_html ? wp_kses( $diff_html, self::$diff_allowed_html ) : '',
				'truncated' => in_array( $key, $info['truncated'], true ),
			);
		}

		$baseline_ts = (int) $info['baseline_ts'];

		return array(
			'translation_id'  => $translation_id,
			'source_id'       => $context['source_id'],
			'source_title'    => '' !== $source->post_title ? $source->post_title : __( '(no title)', 'linguator-pro' ),
			'source_edit_url' => (string) get_edit_post_link( $context['source_id'], 'url' ),
			'edit_url'        => (string) get_edit_post_link( $translation_id, 'url' ),
			'has_baseline'    => $info['known'],
			'partial'         => $info['partial'],
			'baseline_human'  => $baseline_ts > 0 ? human_time_diff( $baseline_ts ) : '',
			'baseline_date'   => $baseline_ts > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $baseline_ts ) : '',
			'fields'          => $fields,
			'can_sync'        => current_user_can( 'edit_post', $translation_id ),
		);
	}

	/**
	 * Translation status of a post for the editor sidebar.
	 *
	 * A default-language post lists its translations with their freshness; a translation reports
	 * whether it is up to date with its source, what changed and which text is still untranslated.
	 *
	 * @since 2.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function editor_status( int $post_id ): array {
		$post   = get_post( $post_id );
		$status = array(
			'post_id'        => $post_id,
			'role'           => 'none',
			'status'         => 'unknown',
			'status_label'   => '',
			'status_badge'   => 'info',
			'status_icon'    => 'info',
			'notice'         => '',
			'source'         => null,
			'translations'   => new \stdClass(),
			'changes'        => array(),
			'partial'        => false,
			'still_same'     => array(),
			'baseline_human' => '',
			'has_diff'       => false,
			'can_sync'       => current_user_can( 'edit_post', $post_id ),
		);

		$default = $this->model->get_default_language();
		$lang    = $post ? $this->model->post->get_language( $post_id ) : false;
		if ( ! $post || ! $default || ! $lang ) {
			return $status;
		}

		$translations = $this->model->post->get_translations( $post_id );

		if ( $lang->slug === $default->slug ) {
			$status['role']         = 'source';
			$status['status']       = 'source';
			$status['status_label'] = __( 'Source', 'linguator-pro' );
			$status['status_badge'] = 'pass';
			$list                   = array();

			foreach ( $this->model->get_languages_list() as $target ) {
				if ( $target->slug === $default->slug ) {
					continue;
				}

				$entry = array(
					'language_name' => $target->name,
					'flag_url'      => esc_url_raw( $target->custom_flag_url ?: $target->flag_url ),
				);
				$id    = (int) ( $translations[ $target->slug ] ?? 0 );

				if ( $id > 0 && get_post( $id ) ) {
					$pair                  = $this->pair_status( $post_id, $id, $target->slug );
					$list[ $target->slug ] = $entry + array(
						'status_label' => $pair['label'],
						'status_badge' => $pair['badge'],
						'edit_url'     => (string) get_edit_post_link( $id, 'url' ),
					);
				} else {
					$list[ $target->slug ] = $entry + array(
						'status_label' => __( 'Missing', 'linguator-pro' ),
						'status_badge' => 'crit',
						'add_url'      => add_query_arg(
							array(
								'post_type' => $post->post_type,
								'from_post' => $post_id,
								'new_lang'  => $target->slug,
								'_wpnonce'  => wp_create_nonce( 'new-post-translation' ),
							),
							admin_url( 'post-new.php' )
						),
					);
				}
			}

			$status['translations'] = (object) $list;
			return $status;
		}

		$source_id = (int) ( $translations[ $default->slug ] ?? 0 );
		if ( $source_id <= 0 || ! get_post( $source_id ) ) {
			$status['role']   = 'translation';
			$status['status'] = 'no_source';
			$status['notice'] = __( 'This post is not linked to a post in the default language yet, so there is nothing to compare with.', 'linguator-pro' );
			return $status;
		}

		$source = get_post( $source_id );
		$pair   = $this->pair_status( $source_id, $post_id, $lang->slug );

		$status['role']         = 'translation';
		$status['status']       = $pair['status'];
		$status['status_label'] = $pair['label'];
		$status['status_badge'] = $pair['badge'];
		$status['status_icon']  = $pair['icon'];
		$status['notice']       = $pair['notice'];
		$status['still_same']   = $pair['still_same'];

		if ( 'outdated' === $pair['status'] ) {
			$info = $this->compute_changes( $source_id, $post_id, $lang->slug );

			$status['changes']        = $info['changes'];
			$status['partial']        = $info['partial'];
			$status['has_diff']       = $info['known'] && ! empty( $info['changes'] );
			$status['baseline_human'] = $info['baseline_ts'] > 0 ? human_time_diff( $info['baseline_ts'] ) : '';

			if ( ! $info['known'] ) {
				$status['notice'] = __( 'The source changed after this translation was last up to date. No saved copy of the earlier source exists, so the exact changes cannot be shown. After you mark it as up to date, future changes will be shown.', 'linguator-pro' );
			} elseif ( empty( $info['changes'] ) ) {
				$status['notice'] = __( 'The source was saved after this translation was last up to date, but no text difference was found. If nothing needs translating, mark it as up to date.', 'linguator-pro' );
			} else {
				$status['notice'] = __( 'The source changed after this translation was last up to date. Review what changed, update the translation, then mark it as up to date.', 'linguator-pro' );
			}
		} else {
			// In sync: keep the saved copy of the source current for the next change.
			$this->save_snapshot( $source_id, $post_id );
		}

		$modified        = (int) get_post_modified_time( 'U', true, $source_id );
		$source_language = $this->model->get_language( $default->slug );

		$status['source'] = array(
			'title'          => '' !== $source->post_title ? $source->post_title : __( '(no title)', 'linguator-pro' ),
			'edit_url'       => (string) get_edit_post_link( $source_id, 'url' ),
			'flag_url'       => $source_language ? esc_url_raw( $source_language->custom_flag_url ?: $source_language->flag_url ) : '',
			'modified_human' => $modified > 0 ? human_time_diff( $modified ) : '',
		);

		return $status;
	}

	/**
	 * Freshness and untranslated text of one source/translation pair.
	 *
	 * @param int    $source_id      Source post ID.
	 * @param int    $translation_id Translation post ID.
	 * @param string $lang           Translation language slug.
	 * @return array{status:string,label:string,badge:string,icon:string,notice:string,still_same:string[]}
	 */
	private function pair_status( int $source_id, int $translation_id, string $lang ): array {
		$verdict    = $this->classify( $source_id, $lang, $this->changed_at( $source_id ), $this->changed_at( $translation_id ) );
		$still_same = $this->still_same( $source_id, $translation_id );

		if ( 'source_updated' === $verdict ) {
			return array(
				'status'     => 'outdated',
				'label'      => __( 'Outdated', 'linguator-pro' ),
				'badge'      => 'warn',
				'icon'       => 'warning',
				'notice'     => __( 'The source changed after this translation was last up to date.', 'linguator-pro' ),
				'still_same' => $still_same,
			);
		}

		if ( $still_same ) {
			return array(
				'status'     => 'identical',
				'label'      => __( 'Untranslated text', 'linguator-pro' ),
				'badge'      => 'warn',
				'icon'       => 'warning',
				'notice'     => __( 'This translation is up to date, but some text is still the same as the source language.', 'linguator-pro' ),
				'still_same' => $still_same,
			);
		}

		return array(
			'status'     => 'in_sync',
			'label'      => __( 'Up to date', 'linguator-pro' ),
			'badge'      => 'pass',
			'icon'       => 'yes-alt',
			'notice'     => __( 'This translation matches the source.', 'linguator-pro' ),
			'still_same' => array(),
		);
	}

	/**
	 * Labels of the title, body and Elementor texts that still equal the source.
	 *
	 * @param int $source_id      Source post ID.
	 * @param int $translation_id Translation post ID.
	 * @return string[]
	 */
	private function still_same( int $source_id, int $translation_id ): array {
		$source = $this->collect_fields( $source_id );
		$target = $this->collect_fields( $translation_id );
		$labels = array();

		foreach ( $source['fields'] as $key => $text ) {
			if ( '' !== $text && isset( $target['fields'][ $key ] ) && $target['fields'][ $key ] === $text ) {
				$labels[] = $source['labels'][ $key ] ?? $key;
			}
		}

		return $labels;
	}

	/**
	 * Source id and language of a translation.
	 *
	 * @since 2.3.0
	 *
	 * @param int $translation_id Translation post ID.
	 * @return array{source_id:int,lang:string}|null
	 */
	public function context( int $translation_id ): ?array {
		$default = $this->model->get_default_language();
		$lang    = $this->model->post->get_language( $translation_id );
		if ( ! $default || ! $lang || $lang->slug === $default->slug ) {
			return null;
		}

		$source_id = (int) $this->model->post->get_translation( $translation_id, $default->slug );
		if ( $source_id <= 0 || $source_id === $translation_id ) {
			return null;
		}

		return array(
			'source_id' => $source_id,
			'lang'      => (string) $lang->slug,
		);
	}

	/**
	 * Saved copy of the source as it was when the translation was last up to date.
	 *
	 * @since 2.3.0
	 *
	 * @param int $translation_id Translation post ID.
	 * @return array|null
	 */
	public function get_snapshot( int $translation_id ): ?array {
		$snapshot = get_post_meta( $translation_id, self::META_SNAPSHOT, true );
		return ( is_array( $snapshot ) && isset( $snapshot['fields'] ) && is_array( $snapshot['fields'] ) ) ? $snapshot : null;
	}

	/**
	 * Saves the source's current text as the translation's "last up to date" copy.
	 *
	 * @since 2.3.0
	 *
	 * @param int  $source_id      Source post ID.
	 * @param int  $translation_id Translation post ID.
	 * @param bool $force          Write even when the text did not change.
	 * @return void
	 */
	public function save_snapshot( int $source_id, int $translation_id, bool $force = false ): void {
		if ( $source_id <= 0 || $translation_id <= 0 ) {
			return;
		}

		$data = $this->collect_fields( $source_id );
		$hash = md5( (string) wp_json_encode( $data['fields'] ) );

		$current = $this->get_snapshot( $translation_id );
		if ( ! $force && $current && isset( $current['hash'] ) && $current['hash'] === $hash ) {
			return;
		}

		update_post_meta(
			$translation_id,
			self::META_SNAPSHOT,
			array(
				'v'         => 1,
				'taken_at'  => time(),
				'source_id' => $source_id,
				'hash'      => $hash,
				'fields'    => $data['fields'],
				'labels'    => $data['labels'],
				'truncated' => $data['truncated'],
			)
		);
	}

	/**
	 * Plain text of what a translator reads in a post: title and body, or Elementor widget text.
	 *
	 * @since 2.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return array{fields:array<string,string>,labels:array<string,string>,truncated:string[]}
	 */
	public function collect_fields( int $post_id ): array {
		$out  = array(
			'fields'    => array(),
			'labels'    => array(),
			'truncated' => array(),
		);
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $out;
		}

		$out['fields']['title'] = $this->readable( $post->post_title );
		$out['labels']['title'] = __( 'Title', 'linguator-pro' );

		if ( $this->is_elementor_built( $post_id ) ) {
			foreach ( $this->elementor_texts( $post_id ) as $key => $text ) {
				$out['fields'][ 'el:' . $key ] = $text;
				$out['labels'][ 'el:' . $key ] = sprintf(
					/* translators: %s: Elementor setting name */
					__( 'Elementor — %s', 'linguator-pro' ),
					ucfirst( str_replace( '_', ' ', (string) preg_replace( '/^.*:/', '', $key ) ) )
				);
			}
		} else {
			$out['fields']['content'] = $this->readable( $post->post_content );
			$out['labels']['content'] = __( 'Content', 'linguator-pro' );
		}

		/**
		 * Max characters of one field kept in a snapshot.
		 *
		 * @since 2.3.0
		 *
		 * @param int $limit Character limit.
		 */
		$limit = max( 200, (int) apply_filters( 'lmat_scan_snapshot_field_limit', 20000 ) );
		/**
		 * Max fields kept in a snapshot.
		 *
		 * @since 2.3.0
		 *
		 * @param int $max Field limit.
		 */
		$max = max( 10, (int) apply_filters( 'lmat_scan_snapshot_max_fields', 400 ) );

		$out['fields'] = array_slice( $out['fields'], 0, $max, true );
		foreach ( $out['fields'] as $key => $text ) {
			if ( mb_strlen( $text ) > $limit ) {
				$out['fields'][ $key ] = mb_substr( $text, 0, $limit );
				$out['truncated'][]    = $key;
			}
		}

		return $out;
	}

	/**
	 * Fields of the source that differ from the saved copy.
	 *
	 * Without a snapshot the newest source revision from before the last sync supplies the title and
	 * body; without either the earlier text is unknown.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $source_id      Source post ID.
	 * @param int    $translation_id Translation post ID.
	 * @param string $lang           Target language slug.
	 * @return array
	 */
	private function compute_changes( int $source_id, int $translation_id, string $lang ): array {
		$current = $this->collect_fields( $source_id );
		$result  = array(
			'known'       => false,
			'partial'     => false,
			'baseline_ts' => 0,
			'changes'     => array(),
			'old'         => array(),
			'new'         => $current['fields'],
			'truncated'   => array(),
		);

		$snapshot = $this->get_snapshot( $translation_id );
		$old      = array();
		$labels   = $current['labels'];

		if ( $snapshot ) {
			$old                   = $snapshot['fields'];
			$labels                = array_merge( isset( $snapshot['labels'] ) && is_array( $snapshot['labels'] ) ? $snapshot['labels'] : array(), $labels );
			$result['known']       = true;
			$result['baseline_ts'] = isset( $snapshot['taken_at'] ) ? (int) $snapshot['taken_at'] : 0;
			$result['truncated']   = isset( $snapshot['truncated'] ) && is_array( $snapshot['truncated'] ) ? $snapshot['truncated'] : array();
		} else {
			$revision = $this->baseline_revision( $source_id, $this->get_pair_sync( $source_id, $lang ) );
			if ( $revision ) {
				$old['title'] = $this->readable( $revision->post_title );
				if ( ! $this->is_elementor_built( $source_id ) ) {
					$old['content'] = $this->readable( $revision->post_content );
				}
				$result['known']       = true;
				$result['partial']     = true;
				$result['baseline_ts'] = (int) get_post_modified_time( 'U', true, $revision->ID );
			}
		}

		if ( ! $result['known'] ) {
			return $result;
		}

		$result['old'] = $old;

		foreach ( array_unique( array_merge( array_keys( $current['fields'] ), array_keys( $old ) ) ) as $key ) {
			if ( $result['partial'] && ! in_array( $key, array( 'title', 'content' ), true ) ) {
				continue;
			}

			$in_old = array_key_exists( $key, $old );
			$in_new = array_key_exists( $key, $current['fields'] );

			if ( $in_old && $in_new ) {
				if ( $old[ $key ] === $current['fields'][ $key ] ) {
					continue;
				}
				$type = 'changed';
			} elseif ( $in_new ) {
				if ( '' === $current['fields'][ $key ] ) {
					continue;
				}
				$type = 'added';
			} else {
				if ( '' === $old[ $key ] ) {
					continue;
				}
				$type = 'removed';
			}

			$result['changes'][] = array(
				'key'   => (string) $key,
				'label' => isset( $labels[ $key ] ) ? (string) $labels[ $key ] : (string) $key,
				'type'  => $type,
			);
		}

		return $result;
	}

	/**
	 * Newest revision of the source saved at or before the sync time.
	 *
	 * @param int $source_id Source post ID.
	 * @param int $sync_ts   Sync time, Unix GMT.
	 * @return \WP_Post|null
	 */
	private function baseline_revision( int $source_id, int $sync_ts ): ?\WP_Post {
		if ( $sync_ts <= 0 ) {
			return null;
		}

		foreach ( wp_get_post_revisions( $source_id ) as $revision ) {
			if ( (int) get_post_modified_time( 'U', true, $revision->ID ) <= $sync_ts ) {
				return $revision;
			}
		}

		return null;
	}

	/**
	 * Last sync time of a pair, stored on the source.
	 *
	 * @param int    $root_id Source post ID.
	 * @param string $lang    Language slug.
	 * @return int
	 */
	private function get_pair_sync( int $root_id, string $lang ): int {
		$map = get_post_meta( $root_id, self::META_PAIR_SYNC, true );
		return ( is_array( $map ) && ! empty( $map[ $lang ] ) ) ? (int) $map[ $lang ] : 0;
	}

	/**
	 * Stores the sync time of a pair (no write when unchanged).
	 *
	 * @param int    $root_id Source post ID.
	 * @param string $lang    Language slug.
	 * @param int    $ts      Unix GMT.
	 * @return void
	 */
	private function set_pair_sync( int $root_id, string $lang, int $ts ): void {
		if ( $ts <= 0 || $this->get_pair_sync( $root_id, $lang ) === $ts ) {
			return;
		}

		$map = get_post_meta( $root_id, self::META_PAIR_SYNC, true );
		$map = is_array( $map ) ? $map : array();

		$map[ $lang ] = $ts;
		update_post_meta( $root_id, self::META_PAIR_SYNC, $map );
	}

	/**
	 * Hash of the text a translator reads.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function fingerprint( \WP_Post $post ): string {
		$parts = array( $this->readable( $post->post_title ) );
		if ( $this->is_elementor_built( $post->ID ) ) {
			$parts[] = (string) wp_json_encode( $this->elementor_texts( $post->ID ) );
		} else {
			$parts[] = $this->readable( $post->post_content );
		}

		return 'v1:' . md5( implode( "\0", $parts ) );
	}

	/**
	 * Whether the post is built with Elementor.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_elementor_built( int $post_id ): bool {
		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
	}

	/**
	 * Editable text of an Elementor document, keyed by element id and setting.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string>
	 */
	private function elementor_texts( int $post_id ): array {
		$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
		$out  = array();
		if ( is_array( $data ) ) {
			$this->walk_elementor( $data, $out );
		}

		return $out;
	}

	/**
	 * Collects text settings from Elementor elements.
	 *
	 * @param array $elements Elements.
	 * @param array $out      Collected text, by reference.
	 * @return void
	 */
	private function walk_elementor( array $elements, array &$out ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$id = isset( $element['id'] ) ? (string) $element['id'] : '';
			if ( '' !== $id && ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				foreach ( $element['settings'] as $key => $value ) {
					if ( is_string( $value ) && in_array( $key, self::ELEMENTOR_TEXT_KEYS, true ) ) {
						$text = $this->readable( $value );
						if ( '' !== $text ) {
							$out[ $id . ':' . $key ] = $text;
						}
					}
				}
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->walk_elementor( $element['elements'], $out );
			}
		}
	}

	/**
	 * Turns post or widget HTML into plain text, one block per line.
	 *
	 * @param string $html Title, post content or widget HTML.
	 * @return string
	 */
	private function readable( string $html ): string {
		$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$html = (string) preg_replace( '#<\s*br\s*/?>#i', "\n", $html );
		$html = (string) preg_replace( '#</\s*(p|div|h[1-6]|li|ul|ol|blockquote|tr|table|figure|figcaption|pre|section|article)\s*>#i', "\n", $html );
		$html = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$lines = array();
		foreach ( (array) preg_split( '/\R/u', $html ) as $line ) {
			$line = trim( (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', (string) $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return implode( "\n", $lines );
	}
}
