<?php
/**
 * Standalone regression checks for untranslated taxonomy slug query vars.
 * Run: php tests/auto-translate-slug-regressions.php
 */

define( 'ABSPATH', __DIR__ );

function get_taxonomies() {
	return array( 'trtax' );
}

function get_taxonomy( $taxonomy ) {
	return (object) array( 'query_var' => $taxonomy );
}

require_once dirname( __DIR__ ) . '/frontend/controllers/frontend-auto-translate.php';

$translator          = ( new ReflectionClass( \Linguator\Frontend\Controllers\Linguator_Frontend_Auto_Translate::class ) )->newInstanceWithoutConstructor();
$translator->curlang = (object) array( 'slug' => 'fr' );
$translator->model   = new class() {
	public $term;

	public function __construct() {
		$this->term = new class() {
			public function get_by( $field, $slug, $language, $taxonomy ) {
				return 'source' === $slug ? 'translated-' . $taxonomy : false;
			}
		};
	}

	public function is_translated_post_type( $post_type ) {
		return true;
	}

	public function get_translated_taxonomies() {
		return array( 'trtax' );
	}
};

$query = new class() {
	public $query_vars = array(
		'category_name' => 'source,missing',
		'tag_slug__in'  => array( 'source', 'missing' ),
		'tag_slug__and' => array( 'source', 'missing' ),
		'tag'           => 'source+missing',
		'trtax'         => 'source,missing',
	);

	public function is_main_query() {
		return false;
	}
};

$translator->linguator_translate_included_ids_in_query( $query );

$expected = array(
	'category_name' => 'translated-category,missing',
	'tag_slug__in'  => array( 'translated-post_tag', 'missing' ),
	'tag_slug__and' => array( 'translated-post_tag', 'missing' ),
	'tag'           => 'translated-post_tag+missing',
	'trtax'         => 'translated-trtax,missing',
);

foreach ( $expected as $key => $value ) {
	if ( $query->query_vars[ $key ] !== $value ) {
		throw new RuntimeException( "Unexpected value for {$key}" );
	}
}

echo "Auto-translate slug regressions passed.\n";
