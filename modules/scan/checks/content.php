<?php
/**
 * Content translation checks.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Modules\Scan\Abstract_Check;
use LinguatorPro\Modules\Scan\Freshness;
use LinguatorPro\Modules\Scan\Result;

/**
 * Finds content with no language and default-language content with no translation.
 *
 * @since 2.3.0
 */
class Content extends Abstract_Check {

	/**
	 * @var string
	 */
	protected $group = 'Content';

	/**
	 * @var string
	 */
	protected $section = 'content';

	/**
	 * Per-language coverage collected by the last run.
	 *
	 * @var array[]
	 */
	private $coverage = array();

	/**
	 * Coverage rows for the Coverage tab.
	 *
	 * @since 2.3.0
	 *
	 * @return array
	 */
	public function extra(): array {
		return array( 'coverage' => array_values( $this->coverage ) );
	}

	/**
	 * Runs the check.
	 *
	 * @since 2.3.0
	 *
	 * @return Result[]
	 */
	public function run(): array {
		$languages = $this->active_languages();
		$default   = $this->default_lang();
		$types     = $this->translated_post_types();

		if ( count( $languages ) < 2 || '' === $default || empty( $types ) ) {
			return array();
		}

		// Content with no language is a notice above the scan, not an issue: the scan waits until it is assigned.
		$results = array();

		$default_lang = $this->model->get_language( $default );
		if ( ! $default_lang ) {
			return $results;
		}

		$targets = array_values(
			array_filter(
				$languages,
				static function ( $lang ) use ( $default ) {
					return $lang->slug !== $default;
				}
			)
		);

		foreach ( $targets as $lang ) {
			$this->coverage[ $lang->slug ] = array(
				'slug'       => $lang->slug,
				'name'       => $lang->name,
				'total'      => 0,
				'translated' => 0,
				'percent'    => 100,
				'sampled'    => false,
			);
		}

		foreach ( $types as $type ) {
			if ( $this->wants( 'content_' . $type ) || $this->wants( 'content_unpublished_' . $type ) ) {
				$results = array_merge( $results, $this->missing_translations_results( $type, $default_lang, $targets ) );
			}
		}

		if ( $this->wants( 'content_source_updated' ) ) {
			$results[] = $this->source_updated_result( $types, $default_lang, $targets );
		}

        // Coverage measures linked translations, including terms, as Toolkit does.
        if ( ! $this->row_keys && null === $this->only ) {
            foreach ( (array) $this->model->get_translated_taxonomies() as $taxonomy ) {
                if ( in_array( $taxonomy, array('nav_menu', 'link_category', 'post_format'), true ) || ! taxonomy_exists($taxonomy) ) continue;
                $terms = get_terms(array('taxonomy'=>$taxonomy,'lang'=>$default,'hide_empty'=>false,'number'=>0));
                if ( is_wp_error($terms) ) continue;
                foreach ( $terms as $term ) {
                    $translations = $this->model->term->get_translations((int)$term->term_id);
                    foreach ( $targets as $lang ) {
                        ++$this->coverage[$lang->slug]['total'];
                        if ( !empty($translations[$lang->slug]) ) ++$this->coverage[$lang->slug]['translated'];
                    }
                }
            }
        }
		foreach ( $this->coverage as $slug => $row ) {
			$this->coverage[ $slug ]['percent'] = $row['total'] > 0 ? (int) floor( 100 * $row['translated'] / $row['total'] ) : 100;
		}

		return $results;
	}

	/**
	 * Lists default-language posts that lack a translation, or whose translation is unpublished.
	 *
	 * Missing and unpublished translations are reported as separate results.
	 *
	 * @since 2.3.0
	 *
	 * @param string   $type         Post type.
	 * @param object   $default_lang Default language.
	 * @param object[] $targets      Languages to translate into.
	 * @return Result[]
	 */
	private function missing_translations_results( string $type, $default_lang, array $targets ): array {
		$object = get_post_type_object( $type );
		$name   = $object ? $object->labels->name : $type;
		$id     = 'content_' . $type;
		$draft  = 'content_unpublished_' . $type;

		$scoped   = $this->scoped_ids( 'content' );
		$post_ids = $scoped ? array_values( array_filter( array_keys( $scoped ), static function ( $id ) use ( $type ) {
			return get_post_type( $id ) === $type;
		} ) ) : $this->default_language_post_ids( $type, $default_lang );

		$details          = array();
		$drafts           = array();
		$missing          = 0;
		$unpub            = 0;
		$truncated        = false;
		$truncated_drafts = $truncated;

		foreach ( $post_ids as $post_id ) {
			$translations = $this->model->post->get_translations( (int) $post_id );

			foreach ( $targets as $lang ) {
				if ( $scoped ) {
					// A row recheck only revisits the languages of the rows it was asked about.
					if ( ! in_array( $lang->slug, $scoped[ (int) $post_id ] ?? array(), true ) ) {
						continue;
					}
				} else {
					++$this->coverage[ $lang->slug ]['total'];
					$this->coverage[ $lang->slug ]['sampled'] = $truncated;
				}

				$translation_id = (int) ( $translations[ $lang->slug ] ?? 0 );
				$status = $translation_id > 0 ? get_post_status( $translation_id ) : false;
                if ( ! $status || in_array( $status, array( 'trash', 'auto-draft' ), true ) ) $translation_id = 0;
                $unpublished = $translation_id > 0 && 'publish' !== $status;
                if ( $translation_id > 0 && ! $scoped ) ++$this->coverage[ $lang->slug ]['translated'];

				if ( $translation_id > 0 && ! $unpublished ) {
					continue;
				}

				if ( $unpublished ) {
					++$unpub;
				} else {
					++$missing;
				}

				$title = get_the_title( $post_id );
				$row   = array(
					'label'      => '' !== $title ? $title : __( '(no title)', 'linguator-pro' ),
					'ignore_key' => 'content:' . $post_id . ':' . $lang->slug,
					'post_type'  => $type,
					'language'   => $lang->slug,
				);

				if ( $unpublished ) {
					$drafts[] = $row + array(
						'message'      => sprintf(
							/* translators: %s: language name with code */
							__( 'The %s translation is not published.', 'linguator-pro' ),
							$this->lang_label( $lang )
						),
						'action_url'   => (string) get_edit_post_link( $translation_id, 'url' ),
						'action_label' => sprintf(
							/* translators: %s: language name */
							__( 'Edit %s', 'linguator-pro' ),
							$lang->name
						),
					);
				} else {
					$details[] = $row + array(
						'message'      => sprintf(
							/* translators: %s: language name with code */
							__( 'Not translated into %s.', 'linguator-pro' ),
							$this->lang_label( $lang )
						),
						'action_url'   => $this->new_translation_url( (int) $post_id, $lang->slug ),
						'action_label' => sprintf(
							/* translators: %s: language name */
							__( 'Translate %s', 'linguator-pro' ),
							$lang->name
						),
					);
				}
			}
		}

		$results = array();

		if ( $this->wants( $id ) ) {
			if ( 0 === $missing ) {
				$results[] = $this->result(
					$id,
					Result::PASS,
					/* translators: %s: post type plural label */
					sprintf( __( '%s fully translated', 'linguator-pro' ), $name ),
					__( 'Every published item has a translation in each language.', 'linguator-pro' )
				);
			} else {
				$results[] = $this->result(
					$id,
					Result::WARNING,
					/* translators: %s: post type plural label */
					sprintf( __( '%s missing translations', 'linguator-pro' ), $name ),
					sprintf(
						/* translators: %d: number of missing translations */
						_n( '%d translation is missing.', '%d translations are missing.', $missing, 'linguator-pro' ),
						$missing
					)
				)
					->with_fix( admin_url( 'edit.php?post_type=' . rawurlencode( $type ) ), __( 'Bulk translate', 'linguator-pro' ) )
					->with_details( $details, $truncated );
			}
		}

		if ( $unpub > 0 && $this->wants( $draft ) ) {
			$results[] = $this->result(
				$draft,
				Result::WARNING,
				/* translators: %s: post type plural label */
				sprintf( __( '%s with unpublished translations', 'linguator-pro' ), $name ),
				sprintf(
					/* translators: %d: number of unpublished translations */
					_n( '%d translation exists but is not published yet (for example, still in draft).', '%d translations exist but are not published yet (for example, still in draft).', $unpub, 'linguator-pro' ),
					$unpub
				)
			)
				->with_fix( admin_url( 'edit.php?post_type=' . rawurlencode( $type ) . '&post_status=draft' ), __( 'View drafts', 'linguator-pro' ) )
				->with_details( $drafts, $truncated_drafts );
		}

		return $results;
	}

	/**
	 * Post ids (and languages) named by the row keys of a scoped recheck.
	 *
	 * @since 2.3.0
	 *
	 * @param string $prefix Row key prefix, such as `content` for `content:12:fr`.
	 * @return array<int,string[]> Languages by post id; empty when the run is not scoped to rows.
	 */
	private function scoped_ids( string $prefix ): array {
		$out = array();
		foreach ( $this->row_keys as $key ) {
			if ( preg_match( '/^' . preg_quote( $prefix, '/' ) . ':(\d+)(?::([a-z0-9_-]+))?$/i', (string) $key, $parts ) ) {
				$out[ (int) $parts[1] ] = array_merge( $out[ (int) $parts[1] ] ?? array(), isset( $parts[2] ) ? array( $parts[2] ) : array() );
			}
		}

		return $out;
	}

	/**
	 * Lists translations that are older than the default-language text they were made from.
	 *
	 * @since 2.3.0
	 *
	 * @param string[] $types        Translated post types.
	 * @param object   $default_lang Default language.
	 * @param object[] $targets      Languages to translate into.
	 * @return Result
	 */
	private function source_updated_result( array $types, $default_lang, array $targets ): Result {
		$fresh  = new Freshness( $this->model );
		$scoped = $this->scoped_ids( 'content_updated' );
		$rows   = array();

		foreach ( $types as $type ) {
			$post_ids = $scoped ? array_values( array_filter( array_keys( $scoped ), static function ( $id ) use ( $type ) {
				return get_post_type( $id ) === $type;
			} ) ) : $this->default_language_post_ids( $type, $default_lang );

			foreach ( $post_ids as $post_id ) {
				$post_id      = (int) $post_id;
				$translations = $this->model->post->get_translations( $post_id );
				$source_ts    = 0;

				foreach ( $targets as $lang ) {
					if ( $scoped && ! in_array( $lang->slug, $scoped[ $post_id ] ?? array(), true ) ) {
						continue;
					}

					$translation_id = (int) ( $translations[ $lang->slug ] ?? 0 );
					if ( $translation_id <= 0 || 'publish' !== get_post_status( $translation_id ) || 'publish' !== get_post_status( $post_id ) ) {
						continue;
					}

					$source_ts = $source_ts > 0 ? $source_ts : $fresh->changed_at( $post_id );
					if ( 'source_updated' !== $fresh->classify( $post_id, $lang->slug, $source_ts, $fresh->changed_at( $translation_id ) ) ) {
						continue;
					}

					$title  = get_the_title( $post_id );
					$rows[] = array(
						'label'          => '' !== $title ? $title : __( '(no title)', 'linguator-pro' ),
						'message'        => sprintf(
							/* translators: %s: language name with code */
							__( 'The default language changed after the %s translation was last up to date.', 'linguator-pro' ),
							$this->lang_label( $lang )
						),
						'action_url'     => (string) get_edit_post_link( $translation_id, 'url' ),
						'edit_url'       => (string) get_edit_post_link( $post_id, 'url' ),
						'action_label'   => sprintf(
							/* translators: %s: language name */
							__( 'Edit %s', 'linguator-pro' ),
							$lang->name
						),
						'ignore_key'     => 'content_updated:' . $post_id . ':' . $lang->slug,
						'post_type'      => $type,
						'language'       => $lang->slug,
						'translation_id' => $translation_id,
					);
				}
			}
		}

		if ( empty( $rows ) ) {
			return $this->result(
				'content_source_updated',
				Result::PASS,
				__( 'Translations are up to date with the default language', 'linguator-pro' ),
				__( 'No translation looks older than the default-language text.', 'linguator-pro' )
			);
		}

		$truncated = count( $rows ) > self::DETAIL_LIMIT;

		return $this->result(
			'content_source_updated',
			Result::WARNING,
			__( 'Default language content was updated', 'linguator-pro' ),
			sprintf(
				/* translators: %d: number of outdated translations */
				_n( '%d translation may need updating after the default language changed.', '%d translations may need updating after the default language changed.', count( $rows ), 'linguator-pro' ),
				count( $rows )
			)
		)->with_details( $scoped ? $rows : array_slice( $rows, 0, self::DETAIL_LIMIT ), $truncated && ! $scoped );
	}
}
