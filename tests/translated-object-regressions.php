<?php
/**
 * Standalone regression checks for translation group cleanup.
 * Run: php tests/translated-object-regressions.php
 */

namespace Linguator\Includes\Models\Translatable {
	class Linguator_Translatable_Object {
		protected function get_object_term( $id, $taxonomy ) {
			return $GLOBALS['test_term'];
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );

	function linguator_sanitize_id( $id ) {
		return (int) $id;
	}

	function linguator_maybe_unserialize( $value ) {
		return unserialize( $value );
	}

	function maybe_serialize( $value ) {
		return serialize( $value );
	}

	function wp_delete_term( $id, $taxonomy ) {
		$GLOBALS['deleted_group'] = $id;
	}

	function wp_update_term( $id, $taxonomy, $args ) {
		$GLOBALS['updated_group'] = unserialize( $args['description'] );
	}

	require_once dirname( __DIR__ ) . '/includes/models/translated/translated-object.php';

	class Test_Translated_Object extends \Linguator\Includes\Models\Translated\Linguator_Translated_Object {
		protected $tax_translations = 'test_translations';
		public $translation_ids = array( 'en' => 7, 'de' => 7 );

		public function get_translations( $id ) {
			return $this->translation_ids;
		}
	}

	$object = ( new \ReflectionClass( Test_Translated_Object::class ) )->newInstanceWithoutConstructor();

	// Remove both language keys while preserving another plugin's data and another translation.
	$GLOBALS['test_term'] = (object) array(
		'term_id'     => 10,
		'description' => serialize( array( 'en' => 7, 'de' => 7, 'fr' => 8, 'plugin' => 7 ) ),
	);
	$object->delete_translation( 7 );
	if ( $GLOBALS['updated_group'] !== array( 'fr' => 8, 'plugin' => 7 ) ) {
		throw new \RuntimeException( 'Translation group retained an obsolete language or lost unrelated data.' );
	}

	// An empty group should be deleted rather than left orphaned.
	$GLOBALS['test_term']->description = serialize( array( 'en' => 7, 'de' => 7 ) );
	$object->delete_translation( 7 );
	if ( $GLOBALS['deleted_group'] !== 10 ) {
		throw new \RuntimeException( 'Empty translation group was not deleted.' );
	}

	echo "Translated object regressions passed.\n";
}
