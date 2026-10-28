<?php
/** Standalone regression checks: php tests/scan-elementor.php. No WordPress data is changed. */
namespace LinguatorPro\Includes\Other {
	class Linguator_Model {
		public $post;
		public $options;
		public $translated_types = array();
		public function get_translated_post_types() { return $this->translated_types; }
		public function get_languages_list() { return array( (object) array( 'slug' => 'en', 'name' => 'English' ), (object) array( 'slug' => 'hi', 'name' => 'Hindi' ), (object) array( 'slug' => 'de', 'name' => 'German', 'active' => false ) ); }
		public function get_default_language() { return $this->get_languages_list()[0]; }
	}
}
namespace {
    function wp_list_pluck($rows,$field) { return array_column($rows,$field); }
	define( 'ABSPATH', __DIR__ );
	$active = true;
	class WP_Post {
		public $ID;
		public $post_type = 'elementor_library';
		public $post_status = 'publish';
		public function __construct( $id, $status = 'publish' ) { $this->ID = $id; $this->post_status = $status; }
	}
	$documents = array( 10 => new WP_Post( 10 ), 20 => new WP_Post( 20, 'draft' ), 30 => new WP_Post( 30 ), 40 => new WP_Post( 40 ), 50 => new WP_Post( 50 ), 60 => new WP_Post( 60 ), 70 => new WP_Post( 70 ) );
	$meta = array();
	foreach ( $documents as $id => $document ) $meta[$id] = array( '_elementor_template_type' => 'header', '_elementor_data' => '[{"id":"a","elType":"container","elements":[]}]' );
	$meta[10]['_elementor_data'] = '[{"elements":[{"elType":"widget","widgetType":"lmat_widget"}]}]';
	$meta[20]['_elementor_template_type'] = 'footer';
	$meta[60]['_elementor_template_type'] = 'kit';
	$meta[70]['_elementor_data'] = '{invalid';
	function __( $text, $domain = '' ) { return $text; }
	function _n( $single, $plural, $number, $domain = '' ) { return 1 === $number ? $single : $plural; }
	function get_post_type_object( $type ) { return (object) array( 'public' => true, 'show_ui' => true, 'labels' => (object) array( 'name' => 'Templates' ) ); }
	function did_action( $action ) { return $GLOBALS['active'] ? 1 : 0; }
	function post_type_exists( $type ) { return 'elementor_library' === $type; }
	function get_posts( $args ) {
		$GLOBALS['last_query'] = $args;
		if ( isset( $GLOBALS['batch_fixture'] ) ) {
			$GLOBALS['batch_queries'][] = $args;
			return array_slice( $GLOBALS['batch_fixture'], ( $args['paged'] - 1 ) * $args['posts_per_page'], $args['posts_per_page'] );
		}
		$documents = $GLOBALS['documents'];
		if ( isset( $args['post_type'] ) && 'any' !== $args['post_type'] ) $documents = array_filter( $documents, static function ( $post ) use ( $args ) { return in_array( $post->post_type, (array) $args['post_type'], true ); } );
		if ( ! empty( $args['post__in'] ) ) $documents = array_intersect_key( $documents, array_flip( $args['post__in'] ) );
		return isset( $args['fields'] ) ? ( isset( $args['meta_query'] ) ? array( 10 ) : array_keys( $documents ) ) : array_values( $documents );
	}
	function get_option( $name, $default = false ) { return $GLOBALS['options'][$name] ?? $default; }
	function update_option( $name, $value, $autoload = false ) { $GLOBALS['options'][$name] = $value; }
	function clean_post_cache( $id ) {}
	function get_post( $id ) { return $GLOBALS['documents'][$id] ?? null; }
	function get_post_type( $id ) { return get_post( $id )->post_type; }
	function get_post_status( $id ) { return get_post( $id )->post_status; }
	function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
	function get_edit_post_link( $id, $context = '' ) { return 'http://example.test/wp-admin/post.php?post=' . $id . '&action=edit'; }
	function get_the_title( $id ) { return 'Template ' . $id; }
	function admin_url( $path ) { return 'http://example.test/wp-admin/' . $path; }
	function sanitize_key( $key ) { return $key; }
	function wp_nonce_url( $url, $action ) { return $url . '&nonce=test'; }
	function wp_create_nonce( $action ) { return 'test'; }
	function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
	function apply_filters( $name, $value, ...$args ) { return $value; }
	function add_query_arg( ...$args ) { return is_array( $args[0] ) ? $args[1] . '?' . http_build_query( $args[0] ) : $args[2] . '&' . $args[0] . '=' . $args[1]; }
	require __DIR__ . '/../modules/scan/result.php';
	require __DIR__ . '/../modules/scan/abstract-check.php';
	require __DIR__ . '/../modules/scan/link-tools.php';
	require __DIR__ . '/../modules/scan/elementor-fields.php';
	require __DIR__ . '/../modules/scan/freshness.php';
	require __DIR__ . '/../modules/scan/frontend-inspector.php';
	require __DIR__ . '/../modules/scan/checks/elementor.php';
	require __DIR__ . '/../modules/scan/checks/switcher.php';
	require __DIR__ . '/../modules/scan/checks/content.php';
	require __DIR__ . '/../modules/scan/scanner.php';
	$model = new \LinguatorPro\Includes\Other\Linguator_Model();
	$model->post = new class {
		public function get_language( $id ) { return 30 === $id ? false : (object) array( 'slug' => in_array( $id, array( 20, 40 ), true ) ? 'hi' : 'en' ); }
		public function get_translations( $id ) { return $GLOBALS['translation_override'][$id] ?? ( in_array( $id, array( 10, 20 ), true ) ? array( 'en' => 10, 'hi' => 20 ) : array() ); }
	};
	$model->options = new class {
		public $enabled = true;
		public function get( $key ) { return $this->enabled ? array( 'elementor' ) : array(); }
	};
	function check( $condition, $message ) { if ( ! $condition ) throw new \RuntimeException( $message ); }
	$scan = new \LinguatorPro\Modules\Scan\Checks\Elementor( $model );
	$results = array();
	foreach ( $scan->run() as $result ) $results[$result->id] = $result->to_array();
	foreach ( array( 'missing', 'unpublished', 'language', 'layout', 'type', 'orphan' ) as $kind ) check( 'warning' === $results['elementor_' . $kind]['status'], 'Missing finding: ' . $kind );
	check( 2 === count( $results['elementor_missing']['details'] ), 'Disabled languages and kit documents must not produce missing translations.' );
	check( 20 === (int) explode( ':', $results['elementor_unpublished']['details'][0]['ignore_key'] )[2], 'Draft finding should point to the translation.' );
	$missing_by_key = array_column( $results['elementor_missing']['details'], null, 'ignore_key' );
	check( false !== strpos( $missing_by_key['elementor:missing:50:hi']['action_url'], 'from_post=50' ), 'Translation action must use Linguator source ID.' );
	$scan->only( 'elementor_layout' );
	check( 1 === count( $scan->run() ), 'Single issue rescans should return only the requested check.' );
	check( \LinguatorPro\Modules\Scan\Checks\Elementor::contains_switcher( json_decode( $meta[10]['_elementor_data'], true ) ), 'Nested switcher widget was missed.' );
	check( ! \LinguatorPro\Modules\Scan\Checks\Elementor::contains_switcher( array( array( 'widgetType' => 'heading', 'settings' => array( 'title' => 'lmat_widget' ) ) ) ), 'Widget name in text must not count as a switcher.' );
	$switcher = new \LinguatorPro\Modules\Scan\Checks\Switcher( $model );
	$detect = new \ReflectionMethod( $switcher, 'has_elementor_switcher' );
	$detect->setAccessible( true );
	check( $detect->invoke( $switcher ), 'Enabled Elementor switcher was not detected.' );
	$model->options->enabled = false;
	check( ! $detect->invoke( $switcher ), 'Disabled Elementor widget integration must not pass detection.' );
	$saved = array( 'time' => 100, 'partial_scans' => array( 'elementor' => 200 ), 'coverage' => array( array( 'slug' => 'hi', 'percent' => 10 ) ), 'results' => array_values( $results ) );
	$GLOBALS['options'][\LinguatorPro\Modules\Scan\Scanner::REPORT_OPTION] = $saved;
	$GLOBALS['translation_override'][50] = array( 'en' => 50, 'hi' => 20 );
	$runner = new \LinguatorPro\Modules\Scan\Scanner( $model );
	$updated = $runner->recheck_rows( 'elementor', array( 'elementor:missing:50:hi' ) );
	check( array( 50 ) === $GLOBALS['last_query']['post__in'], 'Row rescan queried unrelated templates.' );
	check( $updated['time'] === $saved['time'] && $updated['partial_scans'] === $saved['partial_scans'] && $updated['coverage'] === $saved['coverage'], 'Row rescans must preserve full/section scan times and coverage.' );
	$updated_by_id = array_column( $updated['results'], null, 'id' );
	check( 1 === count( $updated_by_id['elementor_missing']['details'] ), 'Resolved row was not removed on recheck.' );
	check( $missing_by_key['elementor:missing:70:hi'] === $updated_by_id['elementor_missing']['details'][0], 'Unrelated missing-translation row changed.' );
	check( $results['elementor_layout'] === $updated_by_id['elementor_layout'], 'Unrelated issue check changed.' );
	check( null === $runner->recheck_rows( 'elementor', array( 'elementor:missing:999:hi' ) ), 'Unknown rows must be rejected.' );
	$GLOBALS['translation_override'][70] = array( 'en' => 70, 'hi' => 20 );
	$all_resolved = $runner->recheck_rows( 'elementor', array( 'elementor:missing:70:hi' ) );
	check( 'pass' === array_column( $all_resolved['results'], null, 'id' )['elementor_missing']['status'], 'Resolving the last stored row should pass the check.' );
	$content = new \LinguatorPro\Modules\Scan\Checks\Content( $model );
	$content->scope_rows( array( 'content:50:hi' ) );
	$coverage = new \ReflectionProperty( $content, 'coverage' );
	$coverage->setAccessible( true );
	$coverage->setValue( $content, array( 'hi' => array( 'total' => 0, 'translated' => 0 ) ) );
	$missing = new \ReflectionMethod( $content, 'missing_translations_results' );
	$missing->setAccessible( true );
	$content_result = $missing->invoke( $content, 'elementor_library', (object) array( 'term_id' => 1 ), array( (object) array( 'slug' => 'hi', 'name' => 'Hindi' ) ) );
	check( 'pass' === $content_result[0]->status, 'Resolved missing content translation was not recognized.' );
	check( 'warning' === $content_result[1]->status && 1 === count( $content_result[1]->details ), 'Unpublished translation was not reported separately.' );
	$meta[50]['_elementor_data'] = '[{"id":"heading1","widgetType":"heading","settings":{"title":"Welcome"}},{"id":"heading2","widgetType":"heading","settings":{"title":"Services"}}]';
	$meta[20]['_elementor_data'] = '[{"id":"heading1","widgetType":"heading","settings":{"title":""}},{"id":"heading2","widgetType":"heading","settings":{"title":""}}]';
	$compare = new \ReflectionMethod( $scan, 'compare_widgets' );
	$compare->setAccessible( true );
	$buckets = array( 'fields' => array(), 'untranslated' => array(), 'repeater' => array(), 'links' => array() );
	$target = (object) array( 'slug' => 'hi', 'name' => 'Hindi' );
	$args = array( $documents[50], $documents[20], $target, &$buckets );
	$compare->invokeArgs( $scan, $args );
	check( 2 === count( $buckets['fields'] ), 'Empty translated widgets must both be reported.' );
	check( $buckets['fields'][0]['ignore_key'] !== $buckets['fields'][1]['ignore_key'], 'Distinct widget fields need distinct rescan keys.' );
	$model->translated_types = array( 'page' );
	$documents[80] = new WP_Post( 80 );
	$documents[80]->post_type = 'page';
	$meta[80]['_elementor_data'] = '{invalid';
	$documents[81] = new WP_Post( 81 );
	$documents[81]->post_type = 'page';
	$scan->only( 'elementor_layout' );
	$layout_rows = $scan->run()[0]->details;
	check( in_array( 'elementor:layout:80:en', array_column( $layout_rows, 'ignore_key' ), true ), 'Elementor-built pages must be scanned for layout issues.' );
	check( ! in_array( 'elementor:layout:81:en', array_column( $layout_rows, 'ignore_key' ), true ), 'Plain pages must not be treated as broken Elementor layouts.' );
	$GLOBALS['batch_fixture'] = array( 1, 2, 3, 4, 5 );
	$batched = new \ReflectionMethod( $scan, 'posts_in_batches' );
	$batched->setAccessible( true );
	check( array( 1, 2, 3, 4, 5 ) === $batched->invoke( $scan, array( 'posts_per_page' => 2, 'lang' => '', 'suppress_filters' => true ) ), 'Older documents beyond the first batch were skipped.' );
	check( 3 === count( $GLOBALS['batch_queries'] ) && 3 === $GLOBALS['batch_queries'][2]['paged'], 'The scanner must advance through bounded queries.' );
	unset( $GLOBALS['batch_fixture'] );
	$active = false;
	check( array() === $scan->run(), 'Inactive Elementor must produce no template results.' );
	echo "Passed Elementor findings, widget detection, scoped queries, row-only report updates, resolution, unknown-row rejection and preservation of unrelated checks, coverage and scan times.\n";

    $menu_report = \LinguatorPro\Modules\Scan\Scanner::apply_ignored(array('results'=>array(array('id'=>'menu_header','status'=>'warning','section'=>'site','group'=>'Menus','label'=>'Header','message'=>'Missing menu','details'=>array()))));
    check('critical' === $menu_report['results'][0]['status'], 'Existing missing-menu reports must appear in Overview as critical.');
    $menu_health = \LinguatorPro\Modules\Scan\Scanner::health($menu_report['results'], array(array('percent'=>96)));
    check(75 === $menu_health['setup'], 'Critical navigation should cost setup points once.');
    check(96 === $menu_health['coverage'], 'Linked draft coverage must not be reset to published-only coverage.');
    echo "Navigation parity: existing reports become critical and setup penalties apply once.\n";
}
