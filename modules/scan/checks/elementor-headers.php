<?php
/**
 * Elementor Theme Builder header coverage.
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
 * Warns when no published default-language Theme Builder header covers the whole site.
 *
 * Without an "Entire Site" (or other include) condition on a published default-language header, many pages show
 * no header. Linguator routes connected translated headers by language, so only the default-language header needs
 * the condition. Display conditions are an Elementor Pro feature, so the check runs only when Elementor Pro is active.
 *
 * @since 2.3.0
 */
class Elementor_Headers extends Abstract_Check {

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
		if ( ! Elementor::available() || ! defined( 'ELEMENTOR_PRO_VERSION' ) || ! post_type_exists( 'elementor_library' ) || ! $this->wants( 'elementor_header' ) || count( $this->active_languages() ) < 2 ) {
			return array();
		}

		$header_ids = $this->posts_in_batches(
			array(
				'post_type'        => 'elementor_library',
				'post_status'      => 'publish',
				'posts_per_page'   => 100,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'lang'             => '',
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_elementor_template_type',
						'value' => 'header',
					),
				),
			)
		);
		if ( empty( $header_ids ) ) {
			return array();
		}

		$default    = $this->default_lang();
		$lang       = $this->model->get_language( $default );
		$lang_name  = $lang && ! empty( $lang->name ) ? $lang->name : $default;
		$defaults   = array();
		$any_include = false;

		foreach ( $header_ids as $id ) {
			$language = $this->model->post->get_language( (int) $id );
			if ( $language && $language->slug !== $default ) {
				continue;
			}

			$defaults[]  = (int) $id;
			$conditions  = (array) get_post_meta( (int) $id, '_elementor_conditions', true );
			foreach ( $conditions as $condition ) {
				if ( 'include/general' === $condition ) {
					return array(
						$this->result(
							'elementor_header',
							Result::PASS,
							__( 'Default-language header covers the whole site', 'linguator-pro' ),
							__( 'A published Theme Builder header uses Entire Site for the default language.', 'linguator-pro' )
						),
					);
				}
				if ( is_string( $condition ) && 0 === strpos( $condition, 'include/' ) ) {
					$any_include = true;
				}
			}
		}

		$list_url = admin_url( 'edit.php?post_type=elementor_library&elementor_library_type=header' );

		if ( empty( $defaults ) ) {
			$message = sprintf(
				/* translators: %s: default language name */
				__( 'Published Elementor headers exist in other languages, but none are assigned to %s. Default-language pages may show no header. Publish a header for the default language and set its display conditions to Entire Site.', 'linguator-pro' ),
				$lang_name
			);
			$label   = sprintf(
				/* translators: %s: default language name */
				__( 'No published Theme Builder header for %s', 'linguator-pro' ),
				$lang_name
			);
			$fix     = $list_url;
		} else {
			$label   = $any_include
				? __( 'No published Theme Builder header uses Entire Site for the default language', 'linguator-pro' )
				: __( 'No published Theme Builder header covers Entire Site / default language', 'linguator-pro' );
			$message = $any_include
				? sprintf(
					/* translators: %s: default language name */
					__( 'Your %s headers only use page- or archive-specific display conditions. Pages that are not listed may show no header. Set the default-language header to Entire Site; connected translated headers follow the language automatically.', 'linguator-pro' ),
					$lang_name
				)
				: sprintf(
					/* translators: %s: default language name */
					__( 'Published %s headers exist, but none have Elementor display conditions. Without an Entire Site (or other include) rule, those headers never appear on the front end. Open the header in Theme Builder and set display conditions to Entire Site.', 'linguator-pro' ),
					$lang_name
				);
			$fix     = (string) get_edit_post_link( $defaults[0], 'url' );
			$fix     = $fix ? add_query_arg( 'action', 'elementor', $fix ) : $list_url;
		}

		return array(
			$this->result( 'elementor_header', Result::WARNING, $label, $message )
				->with_details(
					array(
						array(
							'label'        => $label,
							'message'      => $message,
							'action_url'   => $fix,
							'action_label' => __( 'Set display conditions', 'linguator-pro' ),
							'ignore_key'   => 'elementor_header:' . $default,
						),
					)
				),
		);
	}
}
