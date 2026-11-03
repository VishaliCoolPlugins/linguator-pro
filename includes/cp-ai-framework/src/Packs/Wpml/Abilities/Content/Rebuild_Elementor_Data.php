<?php
/**
 * Elementor data rebuild ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Elementor_Rebuild_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forces Elementor to re-save a post's own Elementor data through Elementor's
 * document API, the same save Elementor runs when a person opens the post in
 * its editor and clicks Save.
 *
 * A translation AutoMLP creates never goes through that editor, so Elementor
 * never sets its own version meta, clears its cached per-post CSS file, or
 * clears its document cache for it. Until that happens the post can render
 * broken or unstyled on the frontend, and AutoMLP's own get-content can read
 * it as a generic block of text instead of its real per-widget fields, even
 * though the underlying translated text is correct. This ability does the
 * "open it and click Save" step without needing anyone to actually open it.
 *
 * @since 1.5.0
 */
final class Rebuild_Elementor_Data extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'rebuild-elementor-data';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Rebuild Elementor Data', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: fix a newly created Elementor translation that renders broken, unstyled, or as generic untranslated-looking text in get-content, even though its translated content is actually correct. AutoMLP\'s translation writer copies only the raw Elementor data onto a new translation, not the editor-identity meta Elementor normally sets the first time a post is opened (_elementor_edit_mode and similar); without it, both AutoMLP and sometimes the frontend treat the post as if it held no builder content. This copies that missing meta from the post\'s own source-language version, then re-saves the post\'s existing Elementor data through Elementor\'s own document API, the same save that happens when someone opens the post in the Elementor editor and clicks Save. It does not change any text or elements. Call this right after translate-post creates a new Elementor translation, or whenever audit-translation-integrity reports content that looks stale. Only applies to posts with Elementor data.', 'wpml-translation-check-pro' );
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
					'description' => __( 'The ID of the Elementor post to rebuild.', 'wpml-translation-check-pro' ),
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
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> Ability meta, overridden because this writes data.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	public static function execute( array $input = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( 0 === $post_id ) {
			return new WP_Error(
				'automlp_invalid_post',
				__( 'A post_id is required.', 'wpml-translation-check-pro' )
			);
		}

		return Elementor_Rebuild_Service::rebuild( $post_id );
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
