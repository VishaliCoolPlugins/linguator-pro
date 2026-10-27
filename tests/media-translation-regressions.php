<?php
/**
 * Standalone regression checks with an in-memory WordPress model.
 * Run: php tests/media-translation-regressions.php
 */
namespace Linguator\Includes\Base {
	class Linguator_Base {
		public $model;
		public $options = array( 'media_support' => true );
	}
}
namespace Linguator\Includes\Other {
	class Linguator_Language {
		public $slug = 'fr';
	}
}
namespace {
	define( 'ABSPATH', __DIR__ );
	class WP_Post {
		public $ID;
		public $post_type = 'attachment';
		public $post_content = '';
		public $post_excerpt = '';
		public $post_title = '';
		public function __construct( $id ) { $this->ID = $id; }
	}
	class WP_Error {}
	$posts = array();
	$denied = array();
	$urls = array();
	$metadata = array();
	$meta = array();
	$writes = 0;
	$uploads = true;
	function get_post( $id ) { global $posts; return isset( $posts[ $id ] ) ? $posts[ $id ] : null; }
	function current_user_can( $cap, $id = 0 ) { global $denied, $uploads; return 'upload_files' === $cap ? $uploads : ! in_array( $id, $denied, true ); }
	function __( $text, $domain ) { return $text; }
	function absint( $id ) { return abs( (int) $id ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function has_blocks( $content ) { return false; }
	function wp_get_attachment_url( $id ) { global $urls; return isset( $urls[ $id ] ) ? $urls[ $id ] : false; }
	function wp_get_attachment_metadata( $id ) { global $metadata; return isset( $metadata[ $id ] ) ? $metadata[ $id ] : array(); }
	function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
	function get_post_meta( $id, $key, $single ) { global $meta; return isset( $meta[ $id ][ $key ] ) ? $meta[ $id ][ $key ] : ''; }
	function get_post_thumbnail_id( $id ) { return 10; }
	function sanitize_text_field( $value ) { return strip_tags( $value ); }
	function wp_kses_post( $value ) { return $value; }
	function wp_slash( $value ) { return addslashes( $value ); }
	function wp_update_post( $value, $error ) { global $writes; ++$writes; return $value['ID']; }
	function update_post_meta( $id, $key, $value ) { global $writes, $meta; ++$writes; $meta[ $id ][ $key ] = stripslashes( $value ); return true; }
	function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_url( $value ) { return esc_attr( $value ); }
	function wp_html_split( $html ) { return preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE ); }
	function wp_kses_attr_parse( $tag ) {
		preg_match_all( '/[^\s=]+=(?:"[^"]*"|\x27[^\x27]*\x27)\s*/', $tag, $attributes );
		return array_merge( array( '<img ' ), $attributes[0], array( '>' ) );
	}
	function add_shortcode( $tag, $callback ) {}
	function do_shortcode( $content ) { return $content; }
	function shortcode_parse_atts( $text ) {
		$attributes = array();
		preg_match_all( '/([a-zA-Z0-9_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s]+))/', $text, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$attributes[ strtolower( $match[1] ) ] = '' !== $match[2] ? $match[2] : ( '' !== $match[3] ? $match[3] : $match[4] );
		}
		return $attributes;
	}
	require dirname( __DIR__ ) . '/includes/services/media/media-translation-service.php';
	require dirname( __DIR__ ) . '/modules/sync/sync-content.php';
	use Linguator\Includes\Services\Media\Media_Translation_Service;
	class Media_Model {
		public $created = 0;
		public function get_translation( $id, $language ) { return 10 === $id ? 20 : 0; }
		public function get( $id, $language ) { return 10 === (int) $id ? 20 : 0; }
		public function get_language( $id ) { return new \Linguator\Includes\Other\Linguator_Language(); }
		public function create_media_translation( $id, $language ) { ++$this->created; return 0; }
	}
	class Test_Sync extends \Linguator_Sync_Post {
		protected function translate_media( $id ) { return 10 === (int) $id ? 20 : $id; }
		public function block( $block ) { return $this->translate_media_block( $block ); }
	}
	$checks = 0;
	function check( $condition, $message ) {
		global $checks;
		if ( ! $condition ) { throw new \RuntimeException( $message ); }
		++$checks;
	}
	foreach ( array( 1, 2, 10, 11, 20, 99 ) as $id ) { $posts[ $id ] = new WP_Post( $id ); }
	$posts[1]->post_type = $posts[2]->post_type = 'post';
	$posts[1]->post_content = '<img class="wp-image-10"><img class="wp-image-11">';
	$posts[20]->post_excerpt = 'Manual caption';
	$meta[20]['_wp_attachment_image_alt'] = 'Manual alt';
	$urls[10] = $urls[20] = 'https://example.org/uploads/photo.jpg';
	$linguator = new \Linguator\Includes\Base\Linguator_Base();
	$linguator->model = (object) array( 'post' => new Media_Model() );
	$service = new Media_Translation_Service( $linguator );
	$sync = new Test_Sync( $linguator );
	$language = new \Linguator\Includes\Other\Linguator_Language();
	$shortcode_tags = array();
	$html = '<figure><img class="wp-image-10" src="https://example.org/uploads/photo-300x200.jpg?x=1" alt="AI alt"><figcaption>AI caption</figcaption></figure>';
	$manual = $sync->translate_content( $html, $posts[2], $language, $language );
	check( false !== strpos( $manual, 'alt="AI alt"' ), 'Content alt must not be overwritten by attachment meta.' );
	check( false !== strpos( $manual, '<figcaption>AI caption</figcaption>' ), 'Content figcaption must not be overwritten by attachment excerpt.' );
	check( false !== strpos( $manual, 'wp-image-20' ), 'Ordinary copying must still remap attachment IDs.' );
	check( false !== strpos( $manual, 'photo-300x200.jpg?x=1' ), 'Shared attachment must retain thumbnail URL.' );
	$ai = $sync->translate_content( $html, $posts[2], $language, $language, true );
	check( false !== strpos( $ai, 'alt="AI alt"' ) && false !== strpos( $ai, '<figcaption>AI caption</figcaption>' ), 'AI inline text must survive remapping.' );
	$manual = $sync->translate_content( $html, $posts[2], $language, $language );
	check( false !== strpos( $manual, 'alt="AI alt"' ), 'Subsequent ordinary copy still keeps content alt.' );
	$meta[10]['_wp_attachment_image_alt'] = 'Source alt';
	$classic = $sync->translate_content( '<img class="wp-image-10" alt="Source alt"><img class="wp-image-10" alt="AI alt">', $posts[2], $language, $language, true );
	check( false !== strpos( $classic, 'alt="Manual alt"' ) && false !== strpos( $classic, 'alt="AI alt"' ) && false === strpos( $classic, 'Source alt' ), 'Inline alt equal to the source attachment alt must follow the translated attachment; other alt is kept.' );
	unset( $meta[10] );
	$block = $sync->block( array( 'blockName' => 'core/audio', 'attrs' => array( 'id' => 10, 'src' => $urls[10] . '?download=1' ) ) );
	check( 20 === $block['attrs']['id'] && $urls[10] . '?download=1' === $block['attrs']['src'], 'Block remapping must retain shared URL parameters.' );
	$urls[20] = 'https://example.org/uploads/translated.jpg';
	$metadata[10]['sizes']['medium'] = array( 'file' => 'photo-300x200.jpg' );
	$metadata[20]['sizes']['medium'] = array( 'file' => 'translated-300x200.jpg' );
	check( 'https://example.org/uploads/translated-300x200.jpg?x=1#image' === Media_Translation_Service::remap_attachment_url( 10, 20, 'https://example.org/uploads/photo-300x200.jpg?x=1#image' ), 'Replacement file must use matching thumbnail and preserve suffix.' );
	check( $urls[20] === Media_Translation_Service::remap_attachment_url( 10, 20, $urls[10] ), 'Known full-size URL must remap.' );
	$responsive = $sync->translate_content( '<img class="wp-image-10" src="https://example.org/uploads/photo-300x200.jpg" srcset="https://example.org/uploads/photo-300x200.jpg 300w, https://example.org/uploads/photo.jpg 1200w" alt="AI alt">', $posts[2], $language, $language, true );
	check( false !== strpos( $responsive, 'src="https://example.org/uploads/translated-300x200.jpg"' ) && false !== strpos( $responsive, 'srcset="https://example.org/uploads/translated-300x200.jpg 300w, https://example.org/uploads/translated.jpg 1200w"' ), 'Responsive candidates must remap consistently without losing sizes.' );
	check( 'https://cdn.example.org/custom.webp' === Media_Translation_Service::remap_attachment_url( 10, 20, 'https://cdn.example.org/custom.webp' ), 'Custom URL must remain unchanged.' );
	unset( $metadata[20]['sizes'] );
	check( 'https://example.org/uploads/photo-300x200.jpg' === Media_Translation_Service::remap_attachment_url( 10, 20, 'https://example.org/uploads/photo-300x200.jpg' ), 'Missing translated size must not promote to full-size.' );
	$payload = array( 'content_media' => array( array( 'id' => 10, 'alt' => 'Translated' ) ) );
	check( true === $service->validate_media_payload( 1, $language, $payload ), 'Referenced authorized attachment must pass.' );
	$unrelated = array( 'content_media' => array( array( 'id' => 99, 'alt' => 'Injected' ) ) );
	check( is_wp_error( $service->validate_media_payload( 1, $language, $unrelated ) ), 'Unrelated attachment must be rejected.' );
	check( array() === $service->apply_content_media_translations( 2, $unrelated['content_media'], 1 ) && 0 === $writes, 'Unrelated attachment must not cause writes.' );
	$mixed = array_merge( $payload['content_media'], $unrelated['content_media'] );
	check( array() === $service->apply_content_media_translations( 2, $mixed, 1 ) && 0 === $writes, 'A mixed valid/invalid payload must be rejected before any write.' );
	$denied = array( 10 );
	check( is_wp_error( $service->validate_media_payload( 1, $language, $payload ) ) && 0 === $service->resolve_translated_attachment( 10, $language ), 'Source attachment permission must be enforced.' );
	$denied = array( 20 );
	check( is_wp_error( $service->validate_media_payload( 1, $language, $payload ) ), 'Target attachment permission must be enforced.' );
	check( false === $service->write_attachment_translations( 20, array( 'alt' => 'Injected' ), 10 ) && 0 === $writes, 'Denied target must never be written.' );
	$denied = array();
	$uploads = false;
	check( is_wp_error( $service->validate_media_payload( 1, $language, array( 'content_media' => array( array( 'id' => 11, 'alt' => 'New' ) ) ) ) ), 'Creating an attachment requires upload permission.' );
	check( true === $service->validate_media_payload( 1, $language, $payload ), 'Updating existing media does not require upload permission.' );
	$uploads = true;
	$urls[20] = $urls[10];
	$elementor = $service->remap_elementor_media( array( 'settings' => array( 'image' => array( 'id' => 10, 'url' => 'https://example.org/uploads/photo-300x200.jpg' ) ) ), $language );
	check( 20 === $elementor['settings']['image']['id'] && 'https://example.org/uploads/photo-300x200.jpg' === $elementor['settings']['image']['url'], 'Elementor must retain the selected shared image size.' );
	$denied = array( 20 );
	$elementor = $service->remap_elementor_media( array( 'id' => 10, 'url' => $urls[10] ), $language, $payload['content_media'] );
	check( 10 === $elementor['id'] && 0 === $writes, 'Elementor remapping must not write an unauthorized attachment.' );
	$denied = array();
	$map = $service->apply_content_media_translations( 2, $payload['content_media'], 1 );
	check( isset( $map[10] ) && 20 === $map[10] && 1 === $writes, 'Valid media save must still succeed.' );
	$posts[2]->post_content = '<img class="wp-image-10" src="https://example.org/uploads/photo.jpg" alt="AI alt">';
	$remapped = $service->remap_content( $posts[2]->post_content, $map );
	check( false !== strpos( $remapped, 'wp-image-20' ), 'remap_content must rewrite wp-image class to the translated attachment.' );
	$caption = $service->remap_content( '[caption id="attachment_10" align="alignnone"]<img class="wp-image-10" src="https://example.org/uploads/photo.jpg"> Cap[/caption][caption id="attachment_100"]x[/caption]', $map );
	check( false !== strpos( $caption, '[caption id="attachment_20" align="alignnone"]' ) && false !== strpos( $caption, 'id="attachment_100"' ), 'remap_content must remap caption shortcode ids like caption_shortcode() and leave others.' );
	$linguator->options['media_support'] = false;
	$service_off = new Media_Translation_Service( $linguator );
	$writes_before = $writes;
	check( array() === $service_off->apply_content_media_translations( 2, $payload['content_media'], 1 ) && $writes === $writes_before, 'Media OFF must skip content media writes.' );
	check( $posts[2]->post_content === $service_off->remap_content( $posts[2]->post_content, $map ), 'Media OFF must not remap content.' );
	echo "Passed $checks media regression checks.\n";
}
