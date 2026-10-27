<?php
/**
 * Standalone regression check for admin-bar category links without a current post.
 * Run: php tests/admin-bar-category-regressions.php
 */

namespace Linguator\Includes\Base {
	class Linguator_Base {
		public $model;
	}
}

namespace Linguator\Includes\Other {
	class Linguator_Language {
		public $slug = 'fr';
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );

	function add_query_arg( $key, $value, $url = '' ) {
		$url = $url ?: 'edit.php?category_name=source';
		return $url . '&' . $key . '=' . $value;
	}

	function remove_query_arg( $key, $url = '' ) {
		return $url ?: 'edit.php?category_name=source';
	}

	function get_post_type() {
		return false; // No current post on an empty list screen.
	}

	function get_object_taxonomies( $post_type, $output ) {
		return 'post' === $post_type ? array( (object) array( 'name' => 'category', 'query_var' => 'category_name' ) ) : array();
	}

	function get_query_var( $key ) {
		return 'category_name' === $key ? 'source' : '';
	}

	require_once dirname( __DIR__ ) . '/admin/controllers/admin-base.php';

	class Test_Admin_Base extends \Linguator\Admin\Controllers\Linguator_Admin_Base {
		public function get_language_url( $language ) {
			return $this->get_admin_bar_menu_url( $language );
		}
	}

	$admin = ( new \ReflectionClass( Test_Admin_Base::class ) )->newInstanceWithoutConstructor();
	$admin->model = new class() {
		public $term;

		public function __construct() {
			$this->term = new class() {
				public function get_by( $field, $slug, $language, $taxonomy ) {
					return 'translated';
				}
			};
		}

		public function is_translated_taxonomy( $taxonomy ) {
			return 'category' === $taxonomy;
		}
	};

	$GLOBALS['pagenow']   = 'edit.php';
	$GLOBALS['post_type'] = 'post';
	$url = $admin->get_language_url( new \Linguator\Includes\Other\Linguator_Language() );

	if ( false === strpos( $url, 'category_name=translated' ) ) {
		throw new \RuntimeException( 'Admin-bar link did not translate the category without a current post.' );
	}

	echo "Admin-bar category regression passed.\n";
}
