<?php
/**
 * Identical-content checks.
 *
 * @package Linguator
 */

namespace Linguator\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Linguator\Modules\Scan\Abstract_Check;
use Linguator\Modules\Scan\Elementor_Fields;
use Linguator\Modules\Scan\Frontend_Inspector;
use Linguator\Modules\Scan\Result;
use Linguator\Modules\Scan\Untranslated_Collector;

/**
 * Flags published translations whose title or body still matches the default language.
 *
 * Elementor-built posts are skipped; the Elementor scan owns those.
 *
 * @since 2.3.0
 */
class Content_Identical extends Abstract_Check {

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
		$types     = $this->translated_post_types();

		if ( '' === $default || count( $languages ) < 2 || empty( $types ) ) {
			return array();
		}

		$default_obj = null;
		$targets     = array();
		foreach ( $languages as $lang ) {
			if ( $lang->slug === $default ) {
				$default_obj = $lang;
				continue;
			}
			if ( $this->wants( 'content_identical_' . $lang->slug ) ) {
				$targets[ $lang->slug ] = $lang;
			}
		}
		if ( ! $default_obj || empty( $targets ) ) {
			return array();
		}

		$rows      = array_fill_keys( array_keys( $targets ), array() );
		$totals    = array_fill_keys( array_keys( $targets ), 0 );
		$truncated = array_fill_keys( array_keys( $targets ), false );

		foreach ( $types as $type ) {
			$post_ids = $this->default_language_post_ids( $type, $default_obj );

			foreach ( $post_ids as $post_id ) {
				$post_id        = (int) $post_id;
				$source_builder = Elementor_Fields::is_built( $post_id );

				$source       = get_post( $post_id );
				$translations = $this->model->post->get_translations( $post_id );
				if ( ! $source instanceof \WP_Post ) {
					continue;
				}

				$fields = array(
					'title'   => Untranslated_Collector::normalize_text( $source->post_title ),
					'content' => Untranslated_Collector::normalize_text( $source->post_content ),
				);

				foreach ( $targets as $slug => $lang ) {
					$translation_id = (int) ( $translations[ $slug ] ?? 0 );
					if ( $translation_id <= 0 || 'publish' !== get_post_status( $translation_id ) ) {
						continue;
					}

					$translation_builder = Elementor_Fields::is_built( $translation_id );
					if ( $source_builder || $translation_builder ) {
						// Layouts built with Elementor are compared widget by widget; a builder page and a plain page are not comparable.
						if ( $source_builder && $translation_builder ) {
							foreach ( $this->widget_rows( $post_id, $translation_id, $slug, $lang, $type ) as $widget_row ) {
								++$totals[ $slug ];
								if ( count( $rows[ $slug ] ) >= self::DETAIL_LIMIT ) {
									$truncated[ $slug ] = true;
									continue;
								}
								$rows[ $slug ][] = $widget_row;
							}
						}
						continue;
					}

					$translation = get_post( $translation_id );
					if ( ! $translation instanceof \WP_Post ) {
						continue;
					}

					$translated = array(
						'title'   => Untranslated_Collector::normalize_text( $translation->post_title ),
						'content' => Untranslated_Collector::normalize_text( $translation->post_content ),
					);

					foreach ( $fields as $field => $original ) {
						if ( '' === $original || $original !== $translated[ $field ] ) {
							continue;
						}
						// Counters, prices and symbols read the same in every language.
						if ( preg_match( '/^[\d\s\.\,\%\$€£₹\/\-\+]+$/u', $original ) ) {
							continue;
						}

						++$totals[ $slug ];
						if ( count( $rows[ $slug ] ) >= self::DETAIL_LIMIT ) {
							$truncated[ $slug ] = true;
							continue;
						}

						$title         = '' !== $translation->post_title ? $translation->post_title : __( '(no title)', 'linguator-pro' );
						$rows[ $slug ][] = array(
							'label'        => sprintf(
								/* translators: 1: field name, 2: post title, 3: language name */
								__( '%1$s of "%2$s" (%3$s)', 'linguator-pro' ),
								'title' === $field ? __( 'Title', 'linguator-pro' ) : __( 'Content', 'linguator-pro' ),
								$title,
								$lang->name
							),
							'message'      => sprintf(
								/* translators: %s: text that still matches the original language */
								__( 'Not translated yet — still shows: "%s"', 'linguator-pro' ),
								Elementor_Fields::snippet( $original, 80 )
							),
							'action_url'   => (string) get_edit_post_link( $translation_id, 'url' ),
							'action_label' => __( 'Edit translation', 'linguator-pro' ),
							'ai_url'       => admin_url( 'edit.php?post_type=' . rawurlencode( $type ) ),
							'ignore_key'   => 'content_identical:' . $post_id . ':' . $slug . ':' . $field,
							'language'     => $slug,
							'post_type'    => $type,
						);
					}
				}
			}
		}

		$results = array();
		foreach ( $targets as $slug => $lang ) {
			$id    = 'content_identical_' . $slug;
			$label = $this->lang_label( $lang );

			if ( empty( $rows[ $slug ] ) ) {
				$results[] = $this->result(
					$id,
					Result::PASS,
					/* translators: %s: language name with code */
					sprintf( __( 'Translated content differs in "%s"', 'linguator-pro' ), $label ),
					__( 'Linked translations use different title and body text.', 'linguator-pro' )
				);
				continue;
			}

			$results[] = $this->result(
				$id,
				Result::WARNING,
				/* translators: %s: language name with code */
				sprintf( __( 'Identical content in "%s"', 'linguator-pro' ), $label ),
				sprintf(
					/* translators: 1: number of items, 2: language name with code */
					_n(
						'%1$d translated item still matches the original text in "%2$s".',
						'%1$d translated items still match the original text in "%2$s".',
						$totals[ $slug ],
						'linguator-pro'
					),
					$totals[ $slug ],
					$label
				)
			)->with_details( $rows[ $slug ], $truncated[ $slug ] );
		}

		return $results;
	}

	/**
	 * Rows for Elementor widget text that a translation still shares with its source.
	 *
	 * Widgets are matched by element ID, links and values that read the same in every language are skipped,
	 * and an empty translated field is a different problem.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $source_id      Source post ID.
	 * @param int    $translation_id Translation post ID.
	 * @param string $slug           Target language slug.
	 * @param object $lang           Target language.
	 * @param string $type           Post type.
	 * @return array[]
	 */
	private function widget_rows( int $source_id, int $translation_id, string $slug, $lang, string $type ): array {
		$source_map      = Elementor_Fields::element_map( Elementor_Fields::tree( $source_id ) );
		$translation_map = Elementor_Fields::element_map( Elementor_Fields::tree( $translation_id ) );
		$edit            = (string) get_edit_post_link( $translation_id, 'url' );
		$title           = get_the_title( $translation_id );
		$rows            = array();

		foreach ( $source_map as $element_id => $element ) {
			if ( empty( $translation_map[ $element_id ] ) ) {
				continue;
			}

			foreach ( $element['fields'] as $field_key => $original ) {
				$translated = $translation_map[ $element_id ]['fields'][ $field_key ] ?? '';
				if ( ! Elementor_Fields::is_untranslated( $field_key, $original, $translated, $slug ) ) {
					continue;
				}

				$row            = Elementor_Fields::same_text_row(
					$element['widget_type'],
					(string) $field_key,
					$original,
					(string) $title,
					(string) $lang->name,
					array(
						'action_url' => $edit ? add_query_arg( array( 'action' => 'elementor', Frontend_Inspector::FOCUS_ARG => $element_id ), $edit ) : '',
						'ai_url'     => admin_url( 'edit.php?post_type=' . rawurlencode( $type ) ),
						'ignore_key' => 'content_identical:' . $source_id . ':' . $slug . ':el_' . $element_id . '_' . md5( $field_key ),
						'language'   => $slug,
						'post_type'  => $type,
					)
				);
				$rows[] = $row;
			}
		}

		return $rows;
	}

}
