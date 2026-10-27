<?php
/**
 * @package Linguator
 */
namespace Linguator\Modules\Full_Site_Editing;

defined( 'ABSPATH' ) || exit;

/**
 * Main class that handles the translation of the templates in full site editing.
 *
 */
class Linguator_FSE_Tools {

	/**
	 * Returns the name of the template post types that are translated by Linguator.
	 *
	 *
	 * @return string[] Array keys and array values are identical.
	 */
	public static function linguator_get_template_post_types() {
		return array(
			'wp_template_part' => 'wp_template_part',
		);
	}

	/**
	 * Tells if the given post type is a template post type that is translated by Linguator.
	 *
	 *
	 * @param string $post_type A post type name.
	 * @return bool
	 */
	public static function is_template_post_type( string $post_type ) {
		return in_array( $post_type, self::linguator_get_template_post_types(), true );
	}

	/**
	 * Tells if we're in the site editor.
	 *
	 *
	 * @global string $pagenow
	 *
	 * @return bool
	 */
	public static function is_site_editor() {
		return isset( $GLOBALS['pagenow'] ) && 'site-editor.php' === $GLOBALS['pagenow'];
	}
}
