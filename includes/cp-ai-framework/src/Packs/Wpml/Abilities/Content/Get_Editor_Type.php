<?php
/**
 * Editor type ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;
use WP_Error;
use WPML_AT_PRO_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports which page builder owns a post's content.
 *
 * WPML-specific: Polylang has no equivalent, because it has no notion of a
 * string package "kind". Knowing the builder before translating matters,
 * because it decides which decomposition path AutoMLP will take and therefore
 * what the string keys will look like.
 *
 * @since 1.5.0
 */
final class Get_Editor_Type extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-editor-type';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Editor Type', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: report which page builder holds a post\'s content, and whether AutoMLP can decompose it. "editor_type" is the builder detected from the post\'s own meta; "supported" says whether AutoMLP handles it. A page built with Elementor, Divi or Cornerstone keeps its text in post meta rather than post_content, so reading post_content alone would show it as empty; use get-content instead, which returns the builder\'s text as translatable strings. Call this when you need to know what you are dealing with before reading or translating a page.', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the post, page or custom post type item to inspect.', 'wpml-translation-check-pro' ),
				),
			),
			'required'   => array( 'post_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'            => array( 'type' => 'integer' ),
				'post_type'          => array( 'type' => 'string' ),
				'editor_type'        => array( 'type' => 'string' ),
				'supported'          => array( 'type' => 'boolean' ),
				'has_blocks'         => array( 'type' => 'boolean' ),
				'content_in_post_meta' => array( 'type' => 'boolean' ),
				'supported_editors'  => array( 'type' => 'array' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error Editor report, or an error.
	 */
	public static function execute( array $input = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return new WP_Error(
				'automlp_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d was not found.', 'wpml-translation-check-pro' ),
					$post_id
				)
			);
		}

		if ( ! class_exists( 'WPML_AT_PRO_Helper' ) ) {
			return new WP_Error(
				'automlp_engine_unavailable',
				__( 'AutoMLP\'s helper classes are not loaded in this request, which normally means WPML is inactive. Check with get-site-info before retrying.', 'wpml-translation-check-pro' )
			);
		}

		$has_blocks  = has_blocks( $post->post_content );
		$editor_type = WPML_AT_PRO_Helper::detect_editor_type( $post_id, $has_blocks ? 'Gutenberg' : 'Classic' );
		$supported   = WPML_AT_PRO_Helper::supported_editors();

		return array(
			'post_id'              => $post_id,
			'post_type'            => (string) $post->post_type,
			'editor_type'          => $editor_type,
			'supported'            => in_array( $editor_type, $supported, true ),
			'has_blocks'           => $has_blocks,
			'content_in_post_meta' => in_array( $editor_type, array( 'Elementor', 'Divi', 'Cornerstone' ), true ),
			'supported_editors'    => $supported,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( 0 === $post_id ) {
			return self::user_can( 'edit_posts', $input );
		}

		return self::user_can( 'edit_post', $input, $post_id );
	}
}
