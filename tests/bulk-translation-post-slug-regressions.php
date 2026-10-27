<?php
/**
 * Standalone checks for bulk translated post slugs.
 * Run: php tests/bulk-translation-post-slug-regressions.php
 */

namespace LinguatorPro\Includes\Other {
	class Linguator_Language {
		public $slug;

		public function __construct( $slug ) {
			$this->slug = $slug;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );

	function sanitize_title( $value ) {
		return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' );
	}

	$GLOBALS['occupied_slugs'] = array();
	$GLOBALS['checked_statuses'] = array();
	function wp_unique_post_slug( $slug, $post_id, $status, $type, $parent ) {
		$GLOBALS['checked_statuses'][] = $status;
		if ( in_array( $status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			return $slug;
		}
		$candidate = $slug;
		$suffix = 2;
		while ( in_array( $candidate, $GLOBALS['occupied_slugs'], true ) ) {
			$candidate = $slug . '-' . $suffix++;
		}
		return $candidate;
	}

	require_once dirname( __DIR__ ) . '/modules/rest/v1/bulk-translation.php';
	require_once dirname( __DIR__ ) . '/modules/sync/sync-content.php';

	$rest = new \LinguatorPro\Modules\REST\V1\Bulk_Translation( null );
	$method = new \ReflectionMethod( $rest, 'select_translated_post_slug' );
	$method->setAccessible( true );
	$cases = array(
		array( false, 'Bonjour Monde', 'hello-world', 'bonjour-monde' ),
		array( 'hello-world', 'Bonjour Monde', 'hello-world', 'bonjour-monde' ),
		array( 'Bonjour Monde', 'Bonjour Monde', 'hello-world', 'bonjour-monde' ),
		array( 'bonjour-personnalise', 'Bonjour Monde', 'hello-world', 'bonjour-personnalise' ),
		array( false, '', 'hello-world', 'hello-world' ),
	);
	foreach ( $cases as $case ) {
		$actual = $method->invoke( $rest, $case[0], $case[1], $case[2] );
		if ( $actual !== $case[3] ) {
			throw new \RuntimeException( "Wrong provider slug fallback: {$actual}" );
		}
	}

	class Slug_Test_Sync_Post extends \Linguator_Pro_Sync_Post {
		public function translate_content( $content, $target_post, \LinguatorPro\Includes\Other\Linguator_Language $from_language, \LinguatorPro\Includes\Other\Linguator_Language $target_language, $preserve_media_text = false ) {
			return $content;
		}
	}

	$language = new \LinguatorPro\Includes\Other\Linguator_Language( 'fr' );
	$linguator = new \stdClass();
	$linguator->options = array();
	$linguator->model = new class( $language ) {
		public $post;
		private $language;

		public function __construct( $language ) {
			$this->language = $language;
			$this->post = new class( $language ) {
				private $language;
				public function __construct( $language ) { $this->language = $language; }
				public function get_language( $id ) { return $this->language; }
			};
		}

		public function get_language( $language ) { return $this->language; }
	};
	$sync = new Slug_Test_Sync_Post( $linguator );
	$source = (object) array( 'ID' => 1, 'post_name' => 'hello-world', 'post_title' => 'Bonjour Monde', 'post_excerpt' => '', 'post_content' => '' );
	$target = (object) array( 'ID' => 2, 'post_status' => 'draft', 'post_type' => 'page', 'post_parent' => 0 );

	$GLOBALS['occupied_slugs'] = array( 'hello-world' );
	$actual = $sync->copy_content( $source, clone $target, $language );
	if ( 'hello-world-fr' !== $actual->post_name || $GLOBALS['checked_statuses'] !== array( 'publish', 'publish' ) ) {
		throw new \RuntimeException( 'Draft collision did not use the language suffix.' );
	}

	$GLOBALS['occupied_slugs'] = array( 'hello-world', 'hello-world-fr' );
	$actual = $sync->copy_content( $source, clone $target, $language );
	if ( 'hello-world-fr-2' !== $actual->post_name ) {
		throw new \RuntimeException( 'Language-suffixed slug collision was not resolved.' );
	}

	$source->post_name = 'bonjour-monde';
	$actual = $sync->copy_content( $source, clone $target, $language );
	if ( 'bonjour-monde' !== $actual->post_name ) {
		throw new \RuntimeException( 'A unique translated slug was changed.' );
	}

	echo "Bulk translated post slug regressions passed.\n";
}
