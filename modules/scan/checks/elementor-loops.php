<?php
/**
 * Elementor Loop Grid and Loop Carousel template checks.
 *
 * @package Linguator
 */

namespace Linguator\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Linguator\Modules\Scan\Abstract_Check;
use Linguator\Modules\Scan\Elementor_Fields;
use Linguator\Modules\Scan\Result;

/**
 * Finds translated Elementor documents whose Loop Grid or Loop Carousel still uses the default-language Loop Item.
 *
 * A Loop Item template holds the card shown for each post. When the translation of a page keeps the original
 * language's Loop Item, every card in the loop shows the original language's text.
 *
 * @since 2.3.0
 */
class Elementor_Loops extends Abstract_Check {

	/**
	 * Default-language documents inspected.
	 */
	const DOCUMENT_LIMIT = 200;

	/**
	 * Detail rows kept.
	 */
	const DETAIL_LIMIT = 100;

	/**
	 * @var string
	 */
	protected $group = 'Elementor';

	/**
	 * @var string
	 */
	protected $section = 'elementor';

	/**
	 * Runs the check.
	 *
	 * @since 2.3.0
	 *
	 * @return Result[]
	 */
	public function run(): array {
		if ( ! Elementor::available() || ! $this->wants( 'elementor_loop' ) || count( $this->active_languages() ) < 2 ) {
			return array();
		}

		$default = $this->default_lang();
		$targets = array_filter(
			$this->active_languages(),
			static function ( $lang ) use ( $default ) {
				return $lang->slug !== $default;
			}
		);

		$ids = $this->posts_in_batches(
			array(
				'post_type'        => 'any',
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'posts_per_page'   => self::DOCUMENT_LIMIT,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'lang'             => '',
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_elementor_data',
						'value'   => '"loop-',
						'compare' => 'LIKE',
					),
				),
			)
		);

		$details = array();
		$checked = 0;

		foreach ( $ids as $source_id ) {
			$source_id = (int) $source_id;
			$language  = $this->model->post->get_language( $source_id );
			if ( ! $language || $language->slug !== $default ) {
				continue;
			}

			$source_loops = array();
			foreach ( Elementor_Fields::element_map( Elementor_Fields::tree( $source_id ) ) as $element_id => $element ) {
				if ( ! empty( $element['loops'] ) ) {
					$source_loops[ $element_id ] = $element;
				}
			}
			if ( empty( $source_loops ) ) {
				continue;
			}
			++$checked;

			$translations = $this->model->post->get_translations( $source_id );

			foreach ( $targets as $lang ) {
				$translation_id = (int) ( $translations[ $lang->slug ] ?? 0 );
				if ( $translation_id <= 0 || 'publish' !== get_post_status( $translation_id ) ) {
					continue;
				}

				$translation_map = Elementor_Fields::element_map( Elementor_Fields::tree( $translation_id ) );

				foreach ( $source_loops as $element_id => $element ) {
					foreach ( $element['loops'] as $field_key => $source_template ) {
						$expected = (int) $this->model->post->get_translation( $source_template, $lang->slug );
						$actual   = (int) ( $translation_map[ $element_id ]['loops'][ $field_key ] ?? 0 );
						$problem  = $this->problem( $expected, $actual, $source_template, $lang->name, Elementor_Fields::widget_label( $element['widget_type'] ) );

						if ( '' === $problem ) {
							continue;
						}

						$edit      = (string) get_edit_post_link( $translation_id, 'url' );
						$details[] = array(
							'label'        => sprintf(
								/* translators: 1: widget name, 2: page title, 3: language name */
								__( '%1$s on "%2$s" (%3$s)', 'linguator-pro' ),
								Elementor_Fields::widget_label( $element['widget_type'] ),
								get_the_title( $translation_id ),
								$lang->name
							),
							'message'      => $problem,
							'edit_url'     => $edit ? add_query_arg( 'action', 'elementor', $edit ) : '',
							'action_url'   => $edit ? add_query_arg( 'action', 'elementor', $edit ) : '',
							'action_label' => __( 'Edit in Elementor', 'linguator-pro' ),
							'ignore_key'   => 'elementor_loop:' . $source_id . ':' . $lang->slug . ':' . $element_id . ':' . md5( $field_key ),
							'language'     => $lang->slug,
							'post_type'    => get_post_type( $translation_id ),
						);
					}
				}
			}
		}

		if ( 0 === $checked ) {
			return array();
		}

		if ( empty( $details ) ) {
			return array(
				$this->result(
					'elementor_loop',
					Result::PASS,
					__( 'Loop templates match each language', 'linguator-pro' ),
					__( 'Every translated Loop Grid and Loop Carousel uses its own language\'s Loop Item.', 'linguator-pro' )
				),
			);
		}

		return array(
			$this->result(
				'elementor_loop',
				Result::WARNING,
				__( 'Loop Grid and Carousel Templates', 'linguator-pro' ),
				__( 'Some translated pages loop over the default language\'s Loop Item, so every card shows the original language. Select each language\'s own Loop Item.', 'linguator-pro' )
			)->with_details( array_slice( $details, 0, self::DETAIL_LIMIT ), count( $details ) > self::DETAIL_LIMIT ),
		);
	}

	/**
	 * Plain-language problem with a loop template selection, or an empty string when it is right.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $expected        Translated Loop Item, or 0 when it has no translation.
	 * @param int    $actual          Loop Item the translation uses, or 0.
	 * @param int    $source_template Default-language Loop Item.
	 * @param string $language        Language name.
	 * @param string $widget          Widget name.
	 * @return string
	 */
	private function problem( int $expected, int $actual, int $source_template, string $language, string $widget ): string {
		$source_title = get_the_title( $source_template );

		if ( $expected <= 0 ) {
			return sprintf(
				/* translators: 1: widget name, 2: loop template title, 3: language name */
				__( 'This %1$s uses template "%2$s". There is no %3$s translation for this template yet.', 'linguator-pro' ),
				$widget,
				$source_title,
				$language
			);
		}
		if ( $actual === $expected ) {
			return '';
		}
		if ( $actual <= 0 ) {
			return sprintf(
				/* translators: 1: widget name, 2: language name, 3: correct template */
				__( 'This %1$s has no %2$s template selected. Assign the %2$s loop template: %3$s', 'linguator-pro' ),
				$widget,
				$language,
				get_the_title( $expected )
			);
		}

		return sprintf(
			/* translators: 1: widget name, 2: current template, 3: language name, 4: correct template */
			__( 'This %1$s uses template "%2$s". Change it to the %3$s template: %4$s', 'linguator-pro' ),
			$widget,
			get_the_title( $actual ),
			$language,
			get_the_title( $expected )
		);
	}
}
