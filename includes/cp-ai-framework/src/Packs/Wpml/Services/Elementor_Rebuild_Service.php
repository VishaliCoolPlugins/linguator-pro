<?php
/**
 * Elementor data regeneration for the abilities subsystem.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Services;

use Elementor\Plugin as Elementor_Plugin;
use Throwable;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forces Elementor to re-save a post's own _elementor_data through Elementor's
 * own document-save pipeline.
 *
 * A translation AutoMLP creates copies _elementor_data as raw postmeta rather
 * than going through Elementor's editor, and it copies nothing else: not
 * _elementor_edit_mode, _elementor_template_type or _elementor_version. WPML
 * Auto Translate's own editor-type detection (WPML_AT_PRO_Helper::detect_editor_type())
 * decides a post is "Elementor" purely by checking _elementor_edit_mode === 'builder',
 * so without that one meta value both AutoMLP's content package and, in some
 * themes, Elementor's own frontend renderer treat the post as if it held no
 * builder content at all, even though _elementor_data is completely correct.
 * Re-running Elementor's own Document::save() alone does not add that meta:
 * Elementor only ever sets it when a post is first opened in the editor, not
 * on every save. So this copies the missing meta from the post's own
 * source-language sibling first, then runs the same
 * \Elementor\Core\Base\Document::save() a manual "open and click Save" would
 * trigger, which clears the cached per-post CSS file and Elementor's document
 * cache. Neither step changes the post's actual element tree.
 *
 * @since 1.5.0
 */
final class Elementor_Rebuild_Service {

	/**
	 * Post meta Elementor sets the first time a post is opened in its editor,
	 * which AutoMLP's translation writer never copies onto a new translation.
	 *
	 * @since 1.5.0
	 * @var string[]
	 */
	const EDITOR_META_KEYS = array(
		'_elementor_edit_mode',
		'_elementor_template_type',
		'_elementor_version',
		'_elementor_page_settings',
	);

	/**
	 * Re-saves one post's Elementor data through Elementor's own document.
	 *
	 * @since 1.5.0
	 * @param int $post_id Post to rebuild.
	 * @return array<string, mixed>|WP_Error Before/after report, or an error.
	 */
	public static function rebuild( $post_id ) {
		/*
		 * Elementor's document API runs third-party code and can throw anywhere,
		 * not only inside save(). A failed rebuild must come back as an error the
		 * caller can report; letting it escape would turn a saved translation into
		 * an HTTP 500.
		 */
		try {
			return self::run_rebuild( $post_id );
		} catch ( Throwable $e ) {
			return new WP_Error(
				'automlp_rebuild_failed',
				sprintf(
					/* translators: %s: underlying error message. */
					__( 'Elementor could not re-save this document: %s', 'wpml-translation-check-pro' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Does the work of rebuild().
	 *
	 * @since 1.6.2
	 * @param int $post_id Post to rebuild.
	 * @return array<string, mixed>|WP_Error Before/after report, or an error.
	 */
	private static function run_rebuild( $post_id ) {
		$post_id = absint( $post_id );
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

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return new WP_Error(
				'automlp_not_elementor',
				__( 'This post has no _elementor_data, so there is nothing to rebuild. This tool only applies to Elementor content.', 'wpml-translation-check-pro' )
			);
		}

		/*
		 * Document::save() writes whatever element list it is given. Data that is not
		 * valid JSON reads back as an empty list, so saving it would replace a layout
		 * that only needs repairing with an empty one. Stop before anything is written.
		 */
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return new WP_Error(
				'automlp_invalid_elementor_data',
				__( 'This post\'s _elementor_data is not valid JSON or holds no elements, so re-saving it would erase the layout. Nothing was changed. Copy the layout again from the source page before rebuilding.', 'wpml-translation-check-pro' )
			);
		}

		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return new WP_Error(
				'automlp_elementor_unavailable',
				__( 'Elementor is not loaded in this request, so its document API is not available.', 'wpml-translation-check-pro' )
			);
		}

		$before = self::describe_package( $post_id );

		$copied_meta = self::copy_editor_meta( $post_id, (string) $post->post_type );

		$document = Elementor_Plugin::$instance->documents->get( $post_id );

		if ( ! $document ) {
			return new WP_Error(
				'automlp_document_unavailable',
				__( 'Elementor could not load a document for this post.', 'wpml-translation-check-pro' )
			);
		}

		$elements = $document->get_elements_data();

		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return new WP_Error(
				'automlp_decode_failed',
				__( 'This post\'s Elementor data could not be read as an element tree.', 'wpml-translation-check-pro' )
			);
		}

		try {
			$saved = $document->save( array( 'elements' => $elements ) );
		} catch ( Throwable $e ) {
			return new WP_Error(
				'automlp_rebuild_failed',
				sprintf(
					/* translators: %s: underlying error message. */
					__( 'Elementor could not re-save this document: %s', 'wpml-translation-check-pro' ),
					$e->getMessage()
				)
			);
		}

		if ( false === $saved ) {
			return new WP_Error(
				'automlp_rebuild_refused',
				__( 'Elementor refused to save this document, most likely because the current user is not allowed to edit it.', 'wpml-translation-check-pro' )
			);
		}

		clean_post_cache( $post_id );

		$after = self::describe_package( $post_id );

		return array(
			'post_id'     => $post_id,
			'copied_meta' => $copied_meta,
			'before'      => $before,
			'after'       => $after,
			'message'     => __( 'Elementor re-saved this document\'s own data. The live page and get-content should now read it correctly; re-run audit-translation-integrity to confirm.', 'wpml-translation-check-pro' ),
		);
	}

	/**
	 * Copies the editor-identity meta Elementor normally sets on first open
	 * from this post's source-language sibling, for whichever of those keys
	 * differs from the source.
	 *
	 * Compares values, not just presence: AutoMLP's translation writer can
	 * leave a key present but wrong (e.g. inherited from whatever the new
	 * post defaulted to) rather than missing outright, and a value-equality
	 * check catches that too. Always writing the source's own value back is
	 * harmless to repeat.
	 *
	 * @since 1.5.0
	 * @param int    $post_id   Post to fill in meta for.
	 * @param string $post_type Its post type.
	 * @return string[] The meta keys that were actually changed.
	 */
	private static function copy_editor_meta( $post_id, $post_type ) {
		/*
		 * WPML_AT_PRO_Helper::get_post_source_language() returns a
		 * translation's OWN language, not the language it was translated
		 * from, so it cannot be used to find the original here. The
		 * trid-aware details (is_source / source_language_code) are the only
		 * reliable way to find the true source-language sibling.
		 */
		$details = Translation_Service::get_element_language_details( $post_id );

		if ( null === $details || $details['is_source'] || '' === $details['source_language_code'] ) {
			return array();
		}

		$source_language = $details['source_language_code'];

		$source_id = apply_filters( 'wpml_object_id', $post_id, $post_type, false, $source_language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$source_id = is_numeric( $source_id ) ? (int) $source_id : 0;

		if ( 0 === $source_id || $source_id === $post_id ) {
			return array();
		}

		$copied = array();

		foreach ( self::EDITOR_META_KEYS as $key ) {
			$source_value = get_post_meta( $source_id, $key, true );

			if ( '' === (string) $source_value ) {
				continue;
			}

			$target_value = get_post_meta( $post_id, $key, true );

			if ( $target_value === $source_value ) {
				continue;
			}

			update_post_meta( $post_id, $key, $source_value );
			$copied[] = $key;
		}

		return $copied;
	}

	/**
	 * A brief before/after snapshot of how AutoMLP currently reads this post,
	 * so the caller can see whether the rebuild actually changed anything.
	 *
	 * @since 1.5.0
	 * @param int $post_id Post to describe.
	 * @return array<string, mixed> Snapshot.
	 */
	private static function describe_package( $post_id ) {
		$post = get_post( $post_id );

		$language = '';

		if ( $post && class_exists( 'WPML_AT_PRO_Helper' ) ) {
			$language = (string) \WPML_AT_PRO_Helper::get_post_source_language( $post_id, (string) $post->post_type );
		}

		$package = Wpml_Context::get_package( $post_id, $language );

		if ( is_wp_error( $package ) ) {
			return array(
				'editor_type' => '',
				'field_count' => 0,
			);
		}

		return array(
			'editor_type' => $package['editor_type'],
			'field_count' => count( (array) $package['contents'] ),
		);
	}
}
