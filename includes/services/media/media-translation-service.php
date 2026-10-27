<?php
/**
 * Media translation service.
 *
 * @package Linguator
 */

namespace Linguator\Includes\Services\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Linguator\Includes\Base\Linguator_Base;
use WP_Post;

/**
 * Extracts media strings and applies translated attachment metadata.
 *
 * This class intentionally registers no hooks. Translation entry points can
 * share it without changing existing media behavior until they opt in.
 */
class Media_Translation_Service {

	/**
	 * Main Linguator model.
	 *
	 * @var \Linguator\Includes\Other\Linguator_Model
	 */
	private $model;

	/**
	 * Linguator options.
	 *
	 * @var array|\ArrayAccess
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param Linguator_Base $linguator Main Linguator object.
	 */
	public function __construct( Linguator_Base $linguator ) {
		$this->model   = $linguator->model;
		$this->options = $linguator->options;
	}

	/**
	 * Whether media translation is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return ! empty( $this->options['media_support'] );
	}

	/**
	 * Remaps a known attachment URL without promoting a thumbnail to full size.
	 * Shared files and unknown/custom URLs retain their existing URL and suffix.
	 *
	 * @param int    $source_id Source attachment ID.
	 * @param int    $target_id Target attachment ID.
	 * @param string $url       Existing URL.
	 * @return string
	 */
	public static function remap_attachment_url( $source_id, $target_id, $url ) {
		$source_url = wp_get_attachment_url( $source_id );
		$target_url = wp_get_attachment_url( $target_id );
		if ( ! is_string( $url ) || ! $source_url || ! $target_url || $source_url === $target_url ) {
			return $url;
		}

		$parts = preg_split( '/(?=[?#])/', $url, 2 );
		$base = $parts[0];
		$suffix = isset( $parts[1] ) ? $parts[1] : '';
		if ( $base === $source_url ) {
			return $target_url . $suffix;
		}

		$source_meta = wp_get_attachment_metadata( $source_id );
		$target_meta = wp_get_attachment_metadata( $target_id );
		if ( ! empty( $source_meta['sizes'] ) && ! empty( $target_meta['sizes'] ) ) {
			foreach ( $source_meta['sizes'] as $size => $image ) {
				if ( empty( $image['file'] ) || empty( $target_meta['sizes'][ $size ]['file'] ) ) {
					continue;
				}
				if ( $base === trailingslashit( dirname( $source_url ) ) . $image['file'] ) {
					return trailingslashit( dirname( $target_url ) ) . $target_meta['sizes'][ $size ]['file'] . $suffix;
				}
			}
		}

		return $url;
	}

	/**
	 * Authorizes all submitted media before any metadata is written.
	 * Referenced media may be shared or unattached, so post_parent is not used.
	 *
	 * @param int          $source_post_id Source page ID.
	 * @param mixed        $language       Target language.
	 * @param array        $payload        Decoded media payload.
	 * @return true|\WP_Error
	 */
	public function validate_media_payload( $source_post_id, $language, array $payload ) {
		$source = get_post( $source_post_id );
		if ( ! $source instanceof WP_Post || ! current_user_can( 'edit_post', $source_post_id ) || empty( $language ) ) {
			return new \WP_Error( 'media_forbidden', __( 'You are not authorized to translate this media.', 'linguator-pro' ), array( 'status' => 403 ) );
		}

		$allowed = $this->collect_content_attachment_ids( $source->post_content );
		foreach ( $this->get_elementor_media_strings( $source_post_id ) as $media ) {
			$allowed[] = (int) $media['id'];
		}
		$ids = array();
		foreach ( array( 'content_media', 'elementor_media' ) as $key ) {
			if ( isset( $payload[ $key ] ) ) {
				$ids = array_merge( $ids, array_keys( $this->normalize_translations( $payload[ $key ] ) ) );
			}
		}
		foreach ( $ids as $id ) {
			if ( ! in_array( $id, $allowed, true ) ) {
				return new \WP_Error( 'unrelated_media', __( 'The submitted media is not referenced by the source post.', 'linguator-pro' ), array( 'status' => 403 ) );
			}
		}
		if ( ! empty( $payload['featured_image'] ) ) {
			$ids[] = (int) get_post_thumbnail_id( $source_post_id );
		}
		foreach ( array_unique( $ids ) as $id ) {
			$attachment = get_post( $id );
			$target_id = (int) $this->model->post->get_translation( $id, $language );
			$target = $target_id ? get_post( $target_id ) : null;
			if (
				! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type
				|| ! current_user_can( 'edit_post', $id )
				|| ( $target instanceof WP_Post && ! current_user_can( 'edit_post', $target_id ) )
				|| ( ! $target instanceof WP_Post && ! current_user_can( 'upload_files' ) )
			) {
				return new \WP_Error( 'media_forbidden', __( 'You are not authorized to translate this media.', 'linguator-pro' ), array( 'status' => 403 ) );
			}
		}

		return true;
	}

	/**
	 * Gets the translatable strings stored on an attachment.
	 *
	 * Empty fields are omitted so they are not sent to an AI provider.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array{id?: int, title?: string, alt?: string, caption?: string, description?: string}
	 */
	public function get_attachment_strings( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( 0 === $attachment_id ) {
			return array();
		}

		$attachment    = get_post( $attachment_id );

		if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
			return array();
		}

		$strings = array( 'id' => $attachment_id );
		$fields  = array(
			'title'       => $attachment->post_title,
			'caption'     => $attachment->post_excerpt,
			'description' => $attachment->post_content,
			'alt'         => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);

		foreach ( $fields as $name => $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$strings[ $name ] = $value;
			}
		}

		return 1 === count( $strings ) ? array() : $strings;
	}

	/**
	 * Gets the featured-image strings for a post.
	 *
	 * @param int $post_id Source post ID.
	 * @return array{id?: int, title?: string, alt?: string, caption?: string, description?: string}
	 */
	public function get_featured_image_strings( $post_id ) {
		$post_id = absint( $post_id );

		if ( 0 === $post_id || ! $this->is_enabled() ) {
			return array();
		}

		return $this->get_attachment_strings( get_post_thumbnail_id( $post_id ) );
	}

	/**
	 * Collects attachment IDs referenced by Gutenberg blocks or classic HTML.
	 *
	 * @param string $content Post content.
	 * @return int[] Unique attachment IDs.
	 */
	public function collect_content_attachment_ids( $content ) {
		$ids = array();

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return array();
		}

		if ( has_blocks( $content ) ) {
			$blocks = parse_blocks( $content );
			$this->collect_block_attachment_ids( $blocks, $ids );
		}

		if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
			$this->add_attachment_ids( $matches[1], $ids );
		}

		if ( preg_match_all( '/\b(?:data-id|data-media-id)=["\'](\d+)/', $content, $matches ) ) {
			$this->add_attachment_ids( $matches[1], $ids );
		}

		if ( preg_match_all( '/(?<!\[)\[(gallery|playlist)\b([^\]]*)\]/i', $content, $shortcodes, PREG_SET_ORDER ) ) {
			foreach ( $shortcodes as $shortcode ) {
				$attributes = shortcode_parse_atts( $shortcode[2] );
				if ( is_array( $attributes ) && isset( $attributes['ids'] ) && ( is_string( $attributes['ids'] ) || is_numeric( $attributes['ids'] ) ) ) {
					$this->add_attachment_ids( explode( ',', (string) $attributes['ids'] ), $ids );
				}
			}
		}

		return array_map( 'intval', array_keys( $ids ) );
	}

	/**
	 * Gets strings for attachments referenced inside post content.
	 *
	 * The featured image is excluded because it is translated separately.
	 *
	 * @param int $post_id Source post ID.
	 * @return array<int, array{id: int, title?: string, alt?: string, caption?: string, description?: string}>
	 */
	public function get_content_media_strings( $post_id ) {
		$post_id = absint( $post_id );

		if ( 0 === $post_id || ! $this->is_enabled() ) {
			return array();
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		return $this->get_media_strings(
			$this->collect_content_attachment_ids( $post->post_content ),
			(int) get_post_thumbnail_id( $post_id )
		);
	}

	/**
	 * Collects attachment IDs from Elementor media-control values.
	 *
	 * Elementor media values use an array containing a numeric ID and URL.
	 *
	 * @param mixed $data Elementor elements or settings tree.
	 * @return int[] Unique attachment IDs.
	 */
	public function collect_elementor_attachment_ids( $data ) {
		$ids = array();
		$this->walk_elementor_attachment_ids( $data, $ids );

		return array_values( $ids );
	}

	/**
	 * Gets strings for attachments referenced by Elementor.
	 *
	 * @param int $post_id Source post ID.
	 * @return array<int, array{id: int, title?: string, alt?: string, caption?: string, description?: string}>
	 */
	public function get_elementor_media_strings( $post_id ) {
		$post_id = absint( $post_id );

		if ( 0 === $post_id || ! $this->is_enabled() ) {
			return array();
		}

		if ( ! get_post( $post_id ) instanceof WP_Post ) {
			return array();
		}

		$elements = array();

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
			if ( $document && method_exists( $document, 'get_elements_data' ) ) {
				$elements = $document->get_elements_data();
			}
		}

		if ( empty( $elements ) ) {
			$elements = get_post_meta( $post_id, '_elementor_data', true );

			if ( is_string( $elements ) && '' !== $elements ) {
				$elements = json_decode( $elements, true );
			}
		}

		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return array();
		}

		return $this->get_media_strings(
			$this->collect_elementor_attachment_ids( $elements ),
			(int) get_post_thumbnail_id( $post_id )
		);
	}

	/**
	 * Normalizes translated media strings from either a list or ID-keyed map.
	 *
	 * @param mixed $translations Decoded translation payload.
	 * @return array<int, array{title?: string, alt?: string, caption?: string, description?: string}>
	 */
	public function normalize_translations( $translations ) {
		if ( ! is_array( $translations ) || empty( $translations ) ) {
			return array();
		}

		$normalized = array();
		$is_list    = array_keys( $translations ) === range( 0, count( $translations ) - 1 );

		foreach ( $translations as $key => $translation ) {
			if ( ! is_array( $translation ) ) {
				continue;
			}

			$attachment_id = $is_list && isset( $translation['id'] ) ? absint( $translation['id'] ) : absint( $key );
			if ( 0 === $attachment_id ) {
				continue;
			}

			$fields = array();
			foreach ( array( 'title', 'alt', 'caption', 'description' ) as $field ) {
				if (
					isset( $translation[ $field ] )
					&& is_string( $translation[ $field ] )
					&& '' !== trim( $translation[ $field ] )
				) {
					$fields[ $field ] = $translation[ $field ];
				}
			}

			if ( ! empty( $fields ) ) {
				$normalized[ $attachment_id ] = $fields;
			}
		}

		return $normalized;
	}

	/**
	 * Resolves or creates an attachment translation.
	 *
	 * The source attachment is never returned because writing translated
	 * metadata to a shared attachment would overwrite the source language.
	 *
	 * @param int                                            $source_attachment_id Source attachment ID.
	 * @param \Linguator\Includes\Other\Linguator_Language|string $language             Target language.
	 * @return int Translated attachment ID, or 0 on failure.
	 */
	public function resolve_translated_attachment( $source_attachment_id, $language ) {
		$source_attachment_id = absint( $source_attachment_id );
		if ( 0 === $source_attachment_id ) {
			return 0;
		}

		$source_attachment    = get_post( $source_attachment_id );

		if (
			! $this->is_enabled()
			|| ! $source_attachment instanceof WP_Post
			|| 'attachment' !== $source_attachment->post_type
			|| empty( $language )
			|| ! current_user_can( 'edit_post', $source_attachment_id )
		) {
			return 0;
		}

		// Resolve the existing translation first, then create it when missing.
		$translated_id = (int) $this->model->post->get( $source_attachment_id, $language );
		$translated_attachment = $translated_id ? get_post( $translated_id ) : null;
		if ( $translated_attachment instanceof WP_Post && ! current_user_can( 'edit_post', $translated_id ) ) {
			return 0;
		}

		// Translation links can outlive deleted attachments. Replace stale links
		// instead of treating a missing media record as an existing translation.
		if (
			0 === $translated_id
			|| ! $translated_attachment instanceof WP_Post
			|| 'attachment' !== $translated_attachment->post_type
		) {
			if ( ! current_user_can( 'upload_files' ) ) {
				return 0;
			}
			$translated_id = (int) $this->model->post->create_media_translation( $source_attachment_id, $language );
		}

		if ( 0 === $translated_id || $source_attachment_id === $translated_id ) {
			return 0;
		}

		$translated_attachment = get_post( $translated_id );

		if ( ! $translated_attachment instanceof WP_Post || 'attachment' !== $translated_attachment->post_type ) {
			return 0;
		}

		return $translated_id;
	}

	/**
	 * Writes translated strings to a translated attachment.
	 *
	 * Empty values are ignored. The method refuses to update the source
	 * attachment when no separate language-specific record exists.
	 *
	 * @param int   $target_attachment_id Target attachment ID.
	 * @param array $translations         Translated attachment strings.
	 * @param int   $source_attachment_id Source attachment ID.
	 * @return bool Whether at least one field was written.
	 */
	public function write_attachment_translations( $target_attachment_id, $translations, $source_attachment_id = 0 ) {
		$target_attachment_id = absint( $target_attachment_id );
		$source_attachment_id = absint( $source_attachment_id );

		if ( 0 === $target_attachment_id ) {
			return false;
		}

		$target_attachment    = get_post( $target_attachment_id );
		$source_attachment    = 0 < $source_attachment_id ? get_post( $source_attachment_id ) : null;

		if (
			! is_array( $translations )
			|| empty( $translations )
			|| ! $target_attachment instanceof WP_Post
			|| 'attachment' !== $target_attachment->post_type
			|| ! current_user_can( 'edit_post', $target_attachment_id )
			|| ( $source_attachment_id && ! current_user_can( 'edit_post', $source_attachment_id ) )
			|| ( 0 < $source_attachment_id && $source_attachment_id === $target_attachment_id )
			|| (
				0 < $source_attachment_id
				&& ( ! $source_attachment instanceof WP_Post || 'attachment' !== $source_attachment->post_type )
			)
		) {
			return false;
		}

		$post_update = array( 'ID' => $target_attachment_id );

		if (
			isset( $translations['title'] )
			&& is_string( $translations['title'] )
			&& '' !== trim( $translations['title'] )
		) {
			$title = sanitize_text_field( $translations['title'] );
			if ( '' !== trim( $title ) ) {
				$post_update['post_title'] = $title;
			}
		}

		if (
			isset( $translations['caption'] )
			&& is_string( $translations['caption'] )
			&& '' !== trim( $translations['caption'] )
		) {
			$caption = wp_kses_post( $translations['caption'] );
			if ( '' !== trim( $caption ) ) {
				$post_update['post_excerpt'] = $caption;
			}
		}

		if (
			isset( $translations['description'] )
			&& is_string( $translations['description'] )
			&& '' !== trim( $translations['description'] )
		) {
			$description = wp_kses_post( $translations['description'] );
			if ( '' !== trim( $description ) ) {
				$post_update['post_content'] = $description;
			}
		}

		$updated = false;

		if ( 1 < count( $post_update ) ) {
			$result = wp_update_post( wp_slash( $post_update ), true );
			if ( is_wp_error( $result ) ) {
				return false;
			}
			$updated = true;
		}

		if ( isset( $translations['alt'] ) && is_string( $translations['alt'] ) && '' !== trim( $translations['alt'] ) ) {
			$alt = sanitize_text_field( $translations['alt'] );
			if ( '' !== trim( $alt ) ) {
				update_post_meta( $target_attachment_id, '_wp_attachment_image_alt', wp_slash( $alt ) );
				$updated = true;
			}
		}

		return $updated;
	}

	/**
	 * Applies translations to attachments referenced by a translated post.
	 *
	 * @param int   $target_post_id Target post ID.
	 * @param mixed $translations   Decoded translation payload.
	 * @param int   $source_post_id Source post ID (authorization allowlist).
	 * @return array<int, int> Source attachment ID => translated attachment ID.
	 */
	public function apply_content_media_translations( $target_post_id, $translations, $source_post_id = 0 ) {
		$target_post_id = absint( $target_post_id );

		if ( 0 === $target_post_id || ! $this->is_enabled() ) {
			return array();
		}

		$language = $this->model->post->get_language( $target_post_id );
		$items    = $this->normalize_translations( $translations );

		if ( empty( $language ) || empty( $items ) ) {
			return array();
		}
		if ( ! $source_post_id || ! current_user_can( 'edit_post', $target_post_id ) || is_wp_error( $this->validate_media_payload( $source_post_id, $language, array( 'content_media' => $translations ) ) ) ) {
			return array();
		}

		$media_map = array();

		foreach ( $items as $source_attachment_id => $fields ) {
			$translated_id = $this->resolve_translated_attachment( $source_attachment_id, $language );

			if ( 0 >= $translated_id ) {
				continue;
			}

			$media_map[ (int) $source_attachment_id ] = (int) $translated_id;
			$this->write_attachment_translations( $translated_id, $fields, $source_attachment_id );
		}

		return $media_map;
	}

	/**
	 * Applies translated metadata to a translated post's featured image.
	 *
	 * @param int   $source_post_id Source post ID.
	 * @param int   $target_post_id Target post ID.
	 * @param array $translations   Translated attachment strings.
	 * @return bool Whether attachment metadata was updated.
	 */
	public function apply_featured_image_translations( $source_post_id, $target_post_id, $translations ) {
		$source_post_id = absint( $source_post_id );
		$target_post_id = absint( $target_post_id );

		if (
			0 === $source_post_id
			|| 0 === $target_post_id
			|| ! is_array( $translations )
			|| empty( $translations )
			|| ! $this->is_enabled()
		) {
			return false;
		}

		$source_attachment_id = (int) get_post_thumbnail_id( $source_post_id );
		$language             = $this->model->post->get_language( $target_post_id );

		if ( 0 === $source_attachment_id || empty( $language ) ) {
			return false;
		}
		if ( ! current_user_can( 'edit_post', $target_post_id ) || is_wp_error( $this->validate_media_payload( $source_post_id, $language, array( 'featured_image' => $translations ) ) ) ) {
			return false;
		}

		$translated_id = $this->resolve_translated_attachment( $source_attachment_id, $language );
		if ( 0 === $translated_id ) {
			return false;
		}

		if ( (int) get_post_thumbnail_id( $target_post_id ) !== $translated_id ) {
			set_post_thumbnail( $target_post_id, $translated_id );
		}

		return $this->write_attachment_translations( $translated_id, $translations, $source_attachment_id );
	}

	/**
	 * Remaps Elementor media controls to translated attachments.
	 *
	 * @param array                                          $elements     Elementor elements tree.
	 * @param \Linguator\Includes\Other\Linguator_Language|string $language     Target language.
	 * @param mixed                                          $translations Optional translated strings.
	 * @return array Remapped Elementor elements.
	 */
	public function remap_elementor_media( $elements, $language, $translations = array() ) {
		if ( ! is_array( $elements ) || empty( $elements ) || empty( $language ) || ! $this->is_enabled() ) {
			return is_array( $elements ) ? $elements : array();
		}

		$translations = $this->normalize_translations( $translations );
		$this->walk_remap_elementor_media( $elements, $language, $translations );

		return $elements;
	}

	/**
	 * Remaps Gutenberg / classic content attachment IDs and URLs using a known map.
	 *
	 * Does not create translations — callers must resolve the map first
	 * (e.g. via apply_content_media_translations / resolve_translated_attachment).
	 *
	 * @param string          $content   Post content.
	 * @param array<int, int> $media_map Source attachment ID => translated ID.
	 * @return string Remapped content.
	 */
	public function remap_content( $content, array $media_map ) {
		if ( ! is_string( $content ) || '' === $content || empty( $media_map ) || ! $this->is_enabled() ) {
			return is_string( $content ) ? $content : '';
		}

		$map = array();
		foreach ( $media_map as $source_id => $target_id ) {
			$source_id = absint( $source_id );
			$target_id = absint( $target_id );
			if ( $source_id > 0 && $target_id > 0 && $source_id !== $target_id ) {
				$map[ $source_id ] = $target_id;
			}
		}

		if ( empty( $map ) ) {
			return $content;
		}

		if ( has_blocks( $content ) ) {
			$blocks = parse_blocks( $content );
			$blocks = $this->remap_blocks( $blocks, $map );
			return serialize_blocks( $blocks );
		}

		return $this->remap_html( $content, $map );
	}

	/**
	 * Persists remapped post_content when it differs from the stored value.
	 *
	 * @param int             $post_id   Target post ID.
	 * @param array<int, int> $media_map Source => translated attachment IDs.
	 * @return bool Whether the post content was updated.
	 */
	public function remap_post_content( $post_id, array $media_map ) {
		$post_id = absint( $post_id );
		if ( 0 === $post_id || empty( $media_map ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! is_string( $post->post_content ) || '' === $post->post_content ) {
			return false;
		}

		$remapped = $this->remap_content( $post->post_content, $media_map );
		if ( $remapped === $post->post_content ) {
			return false;
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $remapped,
			),
			true
		);

		return ! is_wp_error( $result );
	}

	/**
	 * Gets strings for a list of attachment IDs.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @param int   $excluded_id    Attachment ID to omit.
	 * @return array<int, array{id: int, title?: string, alt?: string, caption?: string, description?: string}>
	 */
	private function get_media_strings( $attachment_ids, $excluded_id = 0 ) {
		$strings = array();

		foreach ( array_unique( array_map( 'absint', $attachment_ids ) ) as $attachment_id ) {
			if ( 0 === $attachment_id || $attachment_id === $excluded_id ) {
				continue;
			}

			$attachment_strings = $this->get_attachment_strings( $attachment_id );
			if ( ! empty( $attachment_strings ) ) {
				$strings[] = $attachment_strings;
			}
		}

		return $strings;
	}

	/**
	 * Adds valid IDs to an ID-keyed collection.
	 *
	 * @param array $attachment_ids Candidate attachment IDs.
	 * @param array $ids            Collected IDs.
	 * @return void
	 */
	private function add_attachment_ids( $attachment_ids, array &$ids ) {
		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			if ( 0 < $attachment_id ) {
				$ids[ $attachment_id ] = true;
			}
		}
	}

	/**
	 * Recursively collects attachment IDs from parsed blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $ids    Collected IDs.
	 * @return void
	 */
	private function collect_block_attachment_ids( $blocks, array &$ids ) {
		if ( ! is_array( $blocks ) ) {
			return;
		}

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$attributes = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

			switch ( $block_name ) {
				case 'core/image':
				case 'core/cover':
				case 'core/audio':
				case 'core/video':
				case 'core/file':
					if ( isset( $attributes['id'] ) ) {
						$this->add_attachment_ids( array( $attributes['id'] ), $ids );
					}
					break;

				case 'core/gallery':
					if ( isset( $attributes['ids'] ) && is_array( $attributes['ids'] ) ) {
						$this->add_attachment_ids( $attributes['ids'], $ids );
					}
					break;

				case 'core/media-text':
					if ( isset( $attributes['mediaId'] ) ) {
						$this->add_attachment_ids( array( $attributes['mediaId'] ), $ids );
					}
					break;
			}

			if (
				isset( $block['innerHTML'] )
				&& is_string( $block['innerHTML'] )
				&& preg_match_all( '/wp-image-(\d+)/', $block['innerHTML'], $matches )
			) {
				$this->add_attachment_ids( $matches[1], $ids );
			}

			if ( isset( $block['innerBlocks'] ) ) {
				$this->collect_block_attachment_ids( $block['innerBlocks'], $ids );
			}
		}
	}

	/**
	 * Recursively collects Elementor media-control attachment IDs.
	 *
	 * @param mixed $data Elementor tree node.
	 * @param array $ids  Collected IDs.
	 * @return void
	 */
	private function walk_elementor_attachment_ids( $data, array &$ids ) {
		if ( ! is_array( $data ) ) {
			return;
		}

		if (
			isset( $data['id'], $data['url'] )
			&& is_numeric( $data['id'] )
			&& is_string( $data['url'] )
			&& '' !== $data['url']
		) {
			$attachment_id = absint( $data['id'] );
			if ( 0 < $attachment_id ) {
				$ids[ $attachment_id ] = $attachment_id;
			}
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$this->walk_elementor_attachment_ids( $value, $ids );
			}
		}
	}

	/**
	 * Recursively remaps Elementor media-control attachment IDs and URLs.
	 *
	 * @param array                                          $data         Elementor tree node.
	 * @param \Linguator\Includes\Other\Linguator_Language|string $language     Target language.
	 * @param array                                          $translations Translations keyed by source attachment ID.
	 * @return void
	 */
	private function walk_remap_elementor_media( array &$data, $language, array $translations ) {
		if (
			isset( $data['id'], $data['url'] )
			&& is_numeric( $data['id'] )
			&& is_string( $data['url'] )
			&& '' !== $data['url']
		) {
			$source_attachment_id = absint( $data['id'] );
			$translated_id        = $this->resolve_translated_attachment( $source_attachment_id, $language );

			if ( 0 < $translated_id ) {
				$data['id'] = $translated_id;
				$url        = self::remap_attachment_url( $source_attachment_id, $translated_id, $data['url'] );

				if ( is_string( $url ) && '' !== $url ) {
					$data['url'] = $url;
				}

				if ( isset( $translations[ $source_attachment_id ] ) && is_array( $translations[ $source_attachment_id ] ) ) {
					$this->write_attachment_translations(
						$translated_id,
						$translations[ $source_attachment_id ],
						$source_attachment_id
					);
				} elseif ( isset( $translations[ (string) $source_attachment_id ] ) && is_array( $translations[ (string) $source_attachment_id ] ) ) {
					$this->write_attachment_translations(
						$translated_id,
						$translations[ (string) $source_attachment_id ],
						$source_attachment_id
					);
				}
			}
		}

		foreach ( $data as &$value ) {
			if ( is_array( $value ) ) {
				$this->walk_remap_elementor_media( $value, $language, $translations );
			}
		}
		unset( $value );
	}

	/**
	 * Remaps attachment IDs inside parsed blocks.
	 *
	 * @param array           $blocks Parsed blocks.
	 * @param array<int, int> $map    Source => translated IDs.
	 * @return array
	 */
	private function remap_blocks( array $blocks, array $map ) {
		foreach ( $blocks as $k => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$name  = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

			if ( in_array( $name, array( 'core/image', 'core/cover', 'core/audio', 'core/video', 'core/file' ), true ) && isset( $attrs['id'] ) ) {
				$source_id = absint( $attrs['id'] );
				if ( isset( $map[ $source_id ] ) ) {
					$target_id   = $map[ $source_id ];
					$attrs['id'] = $target_id;
					foreach ( array( 'url', 'src', 'href' ) as $url_key ) {
						if ( ! empty( $attrs[ $url_key ] ) && is_string( $attrs[ $url_key ] ) ) {
							$attrs[ $url_key ] = self::remap_attachment_url( $source_id, $target_id, $attrs[ $url_key ] );
						}
					}
				}
			}

			if ( 'core/gallery' === $name && ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
				foreach ( $attrs['ids'] as $n => $id ) {
					$id = absint( $id );
					if ( isset( $map[ $id ] ) ) {
						$attrs['ids'][ $n ] = $map[ $id ];
					}
				}
			}

			if ( 'core/media-text' === $name && isset( $attrs['mediaId'] ) ) {
				$source_id = absint( $attrs['mediaId'] );
				if ( isset( $map[ $source_id ] ) ) {
					$target_id        = $map[ $source_id ];
					$attrs['mediaId'] = $target_id;
					if ( ! empty( $attrs['mediaUrl'] ) && is_string( $attrs['mediaUrl'] ) ) {
						$attrs['mediaUrl'] = self::remap_attachment_url( $source_id, $target_id, $attrs['mediaUrl'] );
					}
				}
			}

			$block['attrs'] = $attrs;

			if ( ! empty( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
				$block['innerHTML'] = $this->remap_html( $block['innerHTML'], $map );
			}

			if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				foreach ( $block['innerContent'] as $i => $chunk ) {
					if ( is_string( $chunk ) && '' !== $chunk ) {
						$block['innerContent'][ $i ] = $this->remap_html( $chunk, $map );
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->remap_blocks( $block['innerBlocks'], $map );
			}

			$blocks[ $k ] = $block;
		}

		return $blocks;
	}

	/**
	 * Remaps wp-image / data-id / caption markers and attachment URLs in HTML.
	 *
	 * @param string          $html HTML fragment.
	 * @param array<int, int> $map  Source => translated IDs.
	 * @return string
	 */
	private function remap_html( $html, array $map ) {
		if ( ! is_string( $html ) || '' === $html || empty( $map ) ) {
			return is_string( $html ) ? $html : '';
		}

		$textarr = wp_html_split( $html );
		foreach ( $textarr as $i => $text ) {
			if ( 0 !== strpos( $text, '<img' ) ) {
				continue;
			}

			$attributes = wp_kses_attr_parse( $text );
			if ( ! is_array( $attributes ) ) {
				continue;
			}

			$source_id = 0;
			$target_id = 0;

			foreach ( $attributes as $k => $attr ) {
				if ( 0 === strpos( $attr, 'class' ) && preg_match( '#wp\-image\-([0-9]+)#', $attr, $matches ) ) {
					$candidate = absint( $matches[1] );
					if ( isset( $map[ $candidate ] ) ) {
						$source_id        = $candidate;
						$target_id        = $map[ $candidate ];
						$attributes[ $k ] = str_replace( 'wp-image-' . $candidate, 'wp-image-' . $target_id, $attr );
					}
				}

				if ( preg_match( '#^data\-id="([0-9]+)#', $attr, $matches ) ) {
					$candidate = absint( $matches[1] );
					if ( isset( $map[ $candidate ] ) ) {
						$source_id        = $candidate;
						$target_id        = $map[ $candidate ];
						$attributes[ $k ] = str_replace( 'data-id="' . $candidate, 'data-id="' . $target_id, $attr );
					}
				}

				if ( 0 === strpos( $attr, 'data-link' ) && preg_match( '#attachment_id=([0-9]+)#', $attr, $matches ) ) {
					$candidate = absint( $matches[1] );
					if ( isset( $map[ $candidate ] ) ) {
						$source_id        = $candidate;
						$target_id        = $map[ $candidate ];
						$attributes[ $k ] = str_replace( 'attachment_id=' . $candidate, 'attachment_id=' . $target_id, $attr );
					}
				}
			}

			if ( $source_id > 0 && $target_id > 0 ) {
				foreach ( $attributes as $key => $attribute ) {
					if ( 0 === strpos( $attribute, 'src=' ) && preg_match( '#src=(["\'])([^"\']*)\1#', $attribute, $matches ) ) {
						$url                = self::remap_attachment_url( $source_id, $target_id, html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' ) );
						$attributes[ $key ] = str_replace( $matches[2], esc_url( $url ), $attribute );
					}
					if ( preg_match( '#^srcset=(["\'])(.*?)\1#', $attribute, $matches ) ) {
						$srcset             = html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' );
						$srcset             = preg_replace_callback(
							'/(^|,\s*)(\S+)/',
							static function ( $candidate ) use ( $source_id, $target_id ) {
								return $candidate[1] . Media_Translation_Service::remap_attachment_url( $source_id, $target_id, $candidate[2] );
							},
							$srcset
						);
						$attributes[ $key ] = str_replace( $matches[2], esc_attr( $srcset ), $attribute );
					}
				}
			}

			$textarr[ $i ] = implode( $attributes );
		}

		// Caption shortcode ids, as Linguator_Sync_Content::caption_shortcode() remaps them on copy.
		return (string) preg_replace_callback(
			'/(?<![\w-])id=(["\'])attachment_(\d+)\1/',
			static function ( $matches ) use ( $map ) {
				$source_id = absint( $matches[2] );
				return isset( $map[ $source_id ] ) ? 'id=' . $matches[1] . 'attachment_' . $map[ $source_id ] . $matches[1] : $matches[0];
			},
			implode( $textarr )
		);
	}


}
