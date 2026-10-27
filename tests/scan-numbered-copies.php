<?php
/** Run with php tests/scan-numbered-copies.php. */
namespace LinguatorPro\Includes\Other {
	class Linguator_Model {
		public $post;
		public function get_translated_post_types() { return array( 'page' ); }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ );
	function __( $s, $domain = '' ) { return $s; }
	function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
	function get_post_type_object( $type ) { return (object) array( 'public' => true, 'show_ui' => true ); }
	function get_permalink( $id ) { return 'https://example.test/' . $id; }
	function get_edit_post_link( $id, $context = '' ) { return '/edit/' . $id; }
	function get_posts( $args ) {
		$posts = array_filter( $GLOBALS['posts'], static function ( $p ) use ( $args ) {
			return 'publish' === $p->post_status && in_array( $p->post_type, (array) $args['post_type'], true )
				&& ( ! isset( $args['name'] ) || $args['name'] === $p->post_name )
				&& ( ! isset( $args['post_parent'] ) || $args['post_parent'] === $p->post_parent );
		} );
		return array_slice( array_values( $posts ), 0, $args['posts_per_page'] );
	}
	require __DIR__ . '/../modules/scan/result.php';
	require __DIR__ . '/../modules/scan/abstract-check.php';
	require __DIR__ . '/../modules/scan/checks/url-numbered-copies.php';
	$model = new \LinguatorPro\Includes\Other\Linguator_Model();
	$model->post = new class {
		public function get_language( $id ) { return (object) array( 'slug' => 4 === $id ? 'fr' : 'en', 'name' => 'Language' ); }
	};
	function item( $id, $slug, $title, $parent = 0, $status = 'publish' ) {
		return (object) array( 'ID' => $id, 'post_name' => $slug, 'post_title' => $title, 'post_content' => '', 'post_parent' => $parent, 'post_status' => $status, 'post_type' => 'page' );
	}
	$GLOBALS['posts'] = array(
		item( 1, 'test', 'Test page' ), item( 2, 'test-2', 'Test page' ),
		item( 3, 'test-3', 'Different page' ), item( 4, 'test-4', 'Test page' ),
		item( 5, 'test-5', 'Test page', 8 ), item( 6, 'test-6', 'Test page', 0, 'draft' ),
		item( 7, 'year-2026', 'Year' ),
	);
	$check = new \LinguatorPro\Modules\Scan\Checks\Url_Numbered_Copies( $model );
	$result = $check->run()[0];
	if ( 'warning' !== $result->status || 1 !== count( $result->details ) || 'url:numbered_copy:1:2' !== $result->details[0]['ignore_key'] ) {
		throw new \RuntimeException( 'Expected only the matching same-language sibling pair.' );
	}
	$check->only( 'another_check' );
	if ( array() !== $check->run() ) throw new \RuntimeException( 'Single-check filtering failed.' );
	$check->only( null );
	$GLOBALS['posts'][1]->post_title = 'Intentionally different';
	if ( 'pass' !== $check->run()[0]->status ) throw new \RuntimeException( 'A numbered slug alone must not warn.' );
	$GLOBALS['posts'][0]->post_content = '<p>Same content</p>';
	$GLOBALS['posts'][1]->post_content = '<div>Same content</div>';
	if ( 'warning' !== $check->run()[0]->status ) throw new \RuntimeException( 'Identical content was missed.' );
	echo "Numbered-copy scan regression checks passed.\n";
}
