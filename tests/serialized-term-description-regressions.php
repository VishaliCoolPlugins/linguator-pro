<?php
/**
 * Standalone checks for serialized descriptions in internal taxonomies.
 * Run: php tests/serialized-term-description-regressions.php
 */

namespace Linguator\Includes\Models\Translatable {
	interface Linguator_Translatable_Object_With_Types_Interface {}
}

namespace {
	define( 'ABSPATH', __DIR__ );

	class WP_Term {
		public $description;

		public function __construct( $description ) {
			$this->description = $description;
		}
	}

	$GLOBALS['registered_filters'] = array();
	function register_taxonomy( $taxonomy, $object_type, $args ) {}
	function add_action( $hook, $callback ) {}
	function add_filter( $hook, $callback, $priority = 10 ) {
		$GLOBALS['registered_filters'][ $hook ] = array( $callback, $priority );
	}

	require_once dirname( __DIR__ ) . '/includes/models/translatable/translatable-object.php';
	require_once dirname( __DIR__ ) . '/includes/models/translated/translated-object.php';
	require_once dirname( __DIR__ ) . '/includes/models/translated/translated-post.php';

	class Test_Post_Descriptions extends \Linguator\Includes\Models\Translated\Linguator_Translated_Post {
		public function get_translated_object_types( $filter = true ) {
			return array( 'post' );
		}

		public function register_test_taxonomies() {
			$this->register_language_taxonomy();
			$this->linguator_register_translations_taxonomy();
		}
	}

	class Test_Term_Descriptions extends \Linguator\Includes\Models\Translated\Linguator_Translated_Object {
		protected $tax_language     = 'lmat_term_language';
		protected $tax_translations = 'lmat_term_translations';
		protected $object_type      = 'term';

		protected function get_db_infos() {
			return array();
		}

		public function register_test_taxonomies() {
			$this->register_language_taxonomy();
			$this->linguator_register_translations_taxonomy();
		}
	}

	$post = ( new \ReflectionClass( Test_Post_Descriptions::class ) )->newInstanceWithoutConstructor();
	$term = ( new \ReflectionClass( Test_Term_Descriptions::class ) )->newInstanceWithoutConstructor();
	$post->register_test_taxonomies();
	$term->register_test_taxonomies();

	foreach ( array( 'lmat_language', 'lmat_post_translations', 'lmat_term_language', 'lmat_term_translations' ) as $taxonomy ) {
		foreach ( array( "pre_{$taxonomy}_description", "get_{$taxonomy}" ) as $hook ) {
			if ( ! isset( $GLOBALS['registered_filters'][ $hook ] ) || 0 !== $GLOBALS['registered_filters'][ $hook ][1] ) {
				throw new \RuntimeException( "Missing early sanitizer for {$hook}" );
			}
		}
	}

	$allowed = array(
		serialize( array( 'en' => 12, 'fr' => 34 ) ),
		serialize( array( 'locale' => 'en_US', 'active' => true ) ),
		serialize( 'text' ),
		serialize( 42 ),
		'plain text',
	);
	foreach ( $allowed as $description ) {
		if ( $post->sanitize_description( $description ) !== $description ) {
			throw new \RuntimeException( 'Allowed description was rejected.' );
		}
	}

	$disallowed = array(
		serialize( (object) array( 'payload' => 'test' ) ),
		serialize( array( 'en' => 12, 'payload' => (object) array() ) ),
		serialize( null ),
		serialize( 1.5 ),
		'C:8:"stdClass":0:{}',
		'E:7:"Foo:Bar";',
		'a:1:{i:0;r:1;}',
	);
	foreach ( $disallowed as $description ) {
		if ( '' !== $post->sanitize_description( $description ) ) {
			throw new \RuntimeException( 'Disallowed serialized type was accepted.' );
		}

		$stored = new WP_Term( $description );
		$read   = new WP_Term( $description );
		$post->sanitize_term( $read );
		if ( '' !== $read->description || $stored->description !== $description ) {
			throw new \RuntimeException( 'Read sanitizer did not mask the description safely.' );
		}
	}

	foreach ( array( null, 42, true, array( 'en' => 12 ) ) as $description ) {
		if ( '' !== $post->sanitize_description( $description ) ) {
			throw new \RuntimeException( 'Non-string description was accepted.' );
		}
	}

	echo "Serialized term description regressions passed.\n";
}
