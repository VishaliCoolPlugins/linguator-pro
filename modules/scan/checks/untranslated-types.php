<?php
/**
 * Untranslated post type and taxonomy checks.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Modules\Scan\Abstract_Check;
use LinguatorPro\Modules\Scan\Result;

/**
 * Finds public custom post types and taxonomies that Linguator does not translate.
 *
 * @since 2.3.0
 */
class Untranslated_Types extends Abstract_Check {

	/**
	 * @var string
	 */
	protected $group = 'Language Setup';

	/**
	 * @var string
	 */
	protected $section = 'site';

	/**
	 * Runs the check.
	 *
	 * @since 2.3.0
	 *
	 * @return Result[]
	 */
	public function run(): array {
		if ( count( $this->active_languages() ) < 2 || ! $this->wants( 'lang_untranslated_types' ) ) {
			return array();
		}

		$settings = admin_url( 'admin.php?page=lmat_settings&tab=general' );
		$names    = array();

		$post_types = get_post_types( array( 'public' => true, '_builtin' => false ) );
		/** This filter is documented in includes/models/translated/translated-post.php */
		foreach ( array_unique( (array) apply_filters( 'lmat_get_post_types', $post_types, true ) ) as $slug ) {
			if ( 'attachment' === $slug || 'elementor_library' === $slug || $this->model->is_translated_post_type( $slug ) ) {
				continue;
			}
			$object  = get_post_type_object( $slug );
			$names[] = sprintf( '"%1$s" (%2$s)', $object && ! empty( $object->labels->name ) ? $object->labels->name : $slug, $slug );
		}

		$taxonomies = get_taxonomies( array( 'public' => true, '_builtin' => false ) );
		/** This filter is documented in includes/models/translated/translated-term.php */
		foreach ( array_unique( (array) apply_filters( 'lmat_get_taxonomies', $taxonomies, true ) ) as $slug ) {
			if ( in_array( $slug, array( 'nav_menu', 'link_category', 'post_format' ), true ) || ! taxonomy_exists( $slug ) || $this->model->is_translated_taxonomy( $slug ) ) {
				continue;
			}
			$object  = get_taxonomy( $slug );
			$names[] = sprintf( '"%1$s" (%2$s)', $object && ! empty( $object->labels->name ) ? $object->labels->name : $slug, $slug );
		}

		if ( empty( $names ) ) {
			return array(
				$this->result(
					'lang_untranslated_types',
					Result::PASS,
					__( 'All post types & taxonomies are translatable', 'linguator-pro' ),
					__( 'All public post types and taxonomies are enabled for translation in Linguator.', 'linguator-pro' )
				),
			);
		}

		$list = implode( ', ', $names );

		return array(
			$this->result(
				'lang_untranslated_types',
				Result::WARNING,
				__( 'Post types or taxonomies not set as translatable', 'linguator-pro' ),
				sprintf(
					/* translators: %s: comma separated names */
					__( 'The following content types/taxonomies are not set as translatable: %s. Enable them in Linguator settings so translations can be managed.', 'linguator-pro' ),
					$list
				)
			)
				->with_fix( $settings, __( 'Linguator settings', 'linguator-pro' ) )
				->with_details(
					array(
						array(
							'label'        => sprintf(
								/* translators: %s: comma separated names */
								__( 'Not set as translatable: %s', 'linguator-pro' ),
								$list
							),
							'message'      => __( 'Linguator cannot store translations for these until they are switched on in its settings. Anything already saved in them stays in one language.', 'linguator-pro' ),
							'action_url'   => $settings,
							'action_label' => __( 'Linguator settings', 'linguator-pro' ),
						),
					)
				),
		);
	}
}
