<?php
/**
 * Category and tag translation checks.
 *
 * @package Linguator
 */

namespace Linguator\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Linguator\Modules\Scan\Abstract_Check;
use Linguator\Modules\Scan\Result;

/**
 * Finds default-language terms (categories, tags, custom taxonomies) with no translation in an active language.
 *
 * @since 2.3.0
 */
class Content_Terms extends Abstract_Check {

	/**
	 * @var string
	 */
	protected $group = 'Content';

	/**
	 * @var string
	 */
	protected $section = 'content';

	/**
	 * Runs the check.
	 *
	 * @since 2.3.0
	 *
	 * @return Result[]
	 */
	public function run(): array {
		$default   = $this->default_lang();
		$languages = $this->active_languages();

		if ( '' === $default || count( $languages ) < 2 ) {
			return array();
		}

		$taxonomies = array_values( array_diff( (array) $this->model->get_translated_taxonomies(), array( 'nav_menu', 'link_category', 'post_format' ) ) );
		$taxonomies = array_values( array_filter( $taxonomies, 'taxonomy_exists' ) );
		if ( empty( $taxonomies ) ) {
			return array();
		}

		$targets = array();
		foreach ( $languages as $lang ) {
			if ( $lang->slug !== $default && $this->wants( 'term_missing_' . $lang->slug ) ) {
				$targets[ $lang->slug ] = $lang;
			}
		}
		if ( empty( $targets ) ) {
			return array();
		}

		$rows   = array_fill_keys( array_keys( $targets ), array() );
		$totals = array_fill_keys( array_keys( $targets ), 0 );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'lang'       => $default,
					'hide_empty' => false,
					'number'     => 0,
					'orderby'    => 'name',
					'order'      => 'ASC',
				)
			);
			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}

			$object    = get_taxonomy( $taxonomy );
			$post_type = $object && ! empty( $object->object_type ) ? (string) $object->object_type[0] : 'post';

			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}

				$translations = $this->model->term->get_translations( $term->term_id );

				foreach ( $targets as $slug => $lang ) {
					if ( ! empty( $translations[ $slug ] ) ) {
						continue;
					}

					++$totals[ $slug ];
					if ( count( $rows[ $slug ] ) >= self::DETAIL_LIMIT ) {
						continue;
					}

					$rows[ $slug ][] = array(
						'label'        => '' !== $term->name ? $term->name : __( '(no name)', 'linguator-pro' ),
						'message'      => sprintf(
							/* translators: %s: language name with code */
							__( 'Not translated into %s.', 'linguator-pro' ),
							$this->lang_label( $lang )
						),
						'edit_url'     => (string) get_edit_term_link( $term->term_id, $taxonomy ),
						'action_url'   => $this->new_term_translation_url( $term->term_id, $taxonomy, $post_type, $slug ),
						'action_label' => sprintf(
							/* translators: %s: language name */
							__( 'Translate %s', 'linguator-pro' ),
							$lang->name
						),
						'ignore_key'   => 'term:' . $term->term_id . ':' . $taxonomy . ':' . $slug,
						'language'     => $slug,
						'post_type'    => $object && ! empty( $object->labels->singular_name ) ? $object->labels->singular_name : $taxonomy,
					);
				}
			}
		}

		$results = array();
		foreach ( $targets as $slug => $lang ) {
			$id = 'term_missing_' . $slug;

			if ( 0 === $totals[ $slug ] ) {
				$results[] = $this->result(
					$id,
					Result::PASS,
					/* translators: %s: language name with code */
					sprintf( __( 'Terms complete in "%s"', 'linguator-pro' ), $this->lang_label( $lang ) ),
					__( 'All categories and tags have a translation.', 'linguator-pro' )
				);
				continue;
			}

			$results[] = $this->result(
				$id,
				Result::WARNING,
				/* translators: %s: language name with code */
				sprintf( __( 'Terms missing in "%s"', 'linguator-pro' ), $this->lang_label( $lang ) ),
				sprintf(
					/* translators: 1: number of terms, 2: language name with code */
					_n( '%1$d term is not yet translated into "%2$s".', '%1$d terms are not yet translated into "%2$s".', $totals[ $slug ], 'linguator-pro' ),
					$totals[ $slug ],
					$this->lang_label( $lang )
				)
			)
				->with_fix( admin_url( 'edit-tags.php?taxonomy=' . rawurlencode( $taxonomies[0] ) ), __( 'Translate', 'linguator-pro' ) )
				->with_details( $rows[ $slug ], $totals[ $slug ] > count( $rows[ $slug ] ) );
		}

		return $results;
	}

	/**
	 * URL that starts a new translation of a term, as the term list "+" icon does.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $term_id   Source term ID.
	 * @param string $taxonomy  Taxonomy.
	 * @param string $post_type Post type the taxonomy screen is opened for.
	 * @param string $slug      Target language slug.
	 * @return string
	 */
	private function new_term_translation_url( int $term_id, string $taxonomy, string $post_type, string $slug ): string {
		return add_query_arg(
			array(
				'taxonomy'  => $taxonomy,
				'post_type' => $post_type,
				'from_tag'  => $term_id,
				'new_lang'  => sanitize_key( $slug ),
			),
			admin_url( 'edit-tags.php' )
		);
	}
}
