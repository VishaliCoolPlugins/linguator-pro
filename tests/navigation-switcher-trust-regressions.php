<?php
/**
 * Standalone checks that navigation labels come only from generated blocks.
 * Run: php tests/navigation-switcher-trust-regressions.php
 */

define( 'ABSPATH', __DIR__ );

class WP_Block {
	public $name;
	public $attributes;
	public $context;

	public function __construct( $parsed_block, $context = array() ) {
		$this->name       = $parsed_block['blockName'];
		$this->attributes = $parsed_block['attrs'];
		$this->context    = $context;
	}
}

class WP_HTML_Tag_Processor {
	private $html;

	public function __construct( $html ) {
		$this->html = $html;
	}

	public function next_tag( $query ) {
		return false !== stripos( $this->html, '<' . $query['tag_name'] );
	}

	public function set_attribute( $name, $value ) {
		$this->html = preg_replace( '/<a\b/', '<a ' . $name . '="' . htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ) . '"', $this->html, 1 );
	}

	public function get_updated_html() {
		return $this->html;
	}
}

function esc_url_raw( $value ) { return $value; }
function sanitize_html_class( $value ) { return preg_replace( '/[^a-zA-Z0-9_-]/', '', $value ); }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function wp_kses( $value, $allowed ) { return $value; }
function __( $value, $domain ) { return $value; }

require_once dirname( __DIR__ ) . '/includes/switcher/element/abstract-element.php';
require_once dirname( __DIR__ ) . '/modules/blocks/language-switcher/abstract-language-switcher-block.php';
require_once dirname( __DIR__ ) . '/modules/blocks/language-switcher/navigation-language-switcher-block.php';

class Test_Switcher_Element extends \Linguator\Includes\Switcher\Element\Abstract_Element {
	public function __construct() {}
	public function get(): string { return ''; }
	public function get_label(): string { return esc_html( $this->label ); }
}

$element               = new Test_Switcher_Element();
$element->url          = 'https://example.com/fr';
$element->locale       = 'fr-FR';
$element->label        = 'French';
$element->item_classes = array();

$linguator = (object) array( 'model' => new stdClass(), 'links' => new stdClass() );
$switcher  = new \Linguator\Modules\Blocks\Linguator_Navigation_Language_Switcher_Block( $linguator );

$create = new ReflectionMethod( $switcher, 'create_inner_block' );
$created = $create->invoke(
	$switcher,
	'core/navigation-link',
	array( 'show_flags' => false, 'show_labels' => 'names', 'className' => '' ),
	$element,
	array()
);

if ( array_keys( $created->attributes ) !== array( 'label', 'url', 'className' ) ) {
	throw new RuntimeException( 'Generated block contains private language data in attributes.' );
}

$payload = '<img src="x" onerror="alert(document.domain)">';
$stored  = new WP_Block(
	array(
		'blockName' => 'core/navigation-link',
		'attrs'     => array( 'label' => '%lmat%', 'lmat_name' => $payload, 'lmat_show_names' => true ),
	)
);
$html = '<a>%lmat%</a>';
if ( $switcher->linguator_render_custom_attributes( $html, array(), $stored ) !== $html ) {
	throw new RuntimeException( 'Saved core navigation block was modified by the switcher.' );
}

$created->attributes['lmat_name'] = $payload;
$created->attributes['lang']      = '" onfocus="alert(document.domain)';
$rendered = $switcher->linguator_render_custom_attributes( $html, array(), $created );
if ( false === strpos( $rendered, 'French' ) || false === strpos( $rendered, 'lang="fr-FR"' ) || false !== strpos( $rendered, 'alert(' ) || false !== strpos( $rendered, '%lmat%' ) ) {
	throw new RuntimeException( 'Generated navigation block did not use its trusted label and locale.' );
}

if ( $switcher->linguator_render_custom_attributes( $html, array(), $created ) !== $html ) {
	throw new RuntimeException( 'Trusted block data was reused after rendering.' );
}

echo "Navigation switcher trust regressions passed.\n";
