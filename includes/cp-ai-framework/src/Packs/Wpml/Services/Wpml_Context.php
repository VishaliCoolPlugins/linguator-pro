<?php
/**
 * Translation-context shim for the abilities subsystem.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Services;

use Exception;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets abilities reuse AutoMLP's content-collection engine.
 *
 * Get_Package_Content refuses to build unless one of AutoMLP's DOING_* request
 * constants is set, because it is written to run inside the bulk-translate REST
 * flow. Its constructor returns early rather than throwing, which leaves a
 * half-built object that fatals on first use, so the constant has to be set
 * before instantiating rather than handled afterwards.
 *
 * BULK_POST_TRANSLATION is the collection path. Every consumer of it is
 * read-only: the Get_Package_Content guard, and two branches in
 * Update_Block_Config that widen WPML's Gutenberg config and trim the emitted
 * string shape. Nothing writes based on it, so defining it for a read-only
 * ability call has no side effects on stored data.
 *
 * Constants cannot be undefined, so this is a one-way switch for the remainder
 * of the request. That is the same lifetime the REST routes give it.
 *
 * @since 1.5.0
 */
final class Wpml_Context {

	/**
	 * Request constant marking AutoMLP's content-collection path.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const COLLECT_CONSTANT = 'DOING_AUTOMLP_PRO_WPML_BULK_POST_TRANSLATION';

	/**
	 * Enters the content-collection context for the rest of the request.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public static function enter_collect() {
		if ( ! defined( self::COLLECT_CONSTANT ) ) {
			define( self::COLLECT_CONSTANT, true );
		}
	}

	/**
	 * Whether AutoMLP's WPML classes are loaded and usable.
	 *
	 * They are required by automlp-pro.php on plugins_loaded, but only when WPML
	 * is active, so an ability must not assume they exist.
	 *
	 * @since 1.5.0
	 * @return bool True when the collection engine is available.
	 */
	public static function engine_available() {
		return class_exists( '\AUTOMLP_PRO_WPML\Includes\Wpml\Get_Package_Content' );
	}

	/**
	 * Builds the translatable-string package for a post.
	 *
	 * Wraps Get_Package_Content so every caller gets the same context handling
	 * and the same error shape instead of a raw Exception.
	 *
	 * @since 1.5.0
	 * @param int    $post_id     Post to decompose.
	 * @param string $source_lang Source language code.
	 * @return array{editor_type:string, title:string, excerpt:string, contents:array<string, mixed>}|WP_Error Package, or an error.
	 */
	public static function get_package( $post_id, $source_lang ) {
		$post_id = absint( $post_id );

		if ( 0 === $post_id ) {
			return new WP_Error(
				'automlp_invalid_post',
				__( 'A post ID is required.', 'wpml-translation-check-pro' )
			);
		}

		if ( ! self::engine_available() ) {
			return new WP_Error(
				'automlp_engine_unavailable',
				__( 'AutoMLP\'s content-collection classes are not loaded in this request, which normally means WPML or WPML String Translation is inactive. Check with get-site-info before retrying.', 'wpml-translation-check-pro' )
			);
		}

		self::enter_collect();

		$class = '\AUTOMLP_PRO_WPML\Includes\Wpml\Get_Package_Content';

		try {
			$package = new $class( $post_id, (string) $source_lang );
			$result  = $package->get_translatable_strings();
		} catch ( Exception $e ) {
			return new WP_Error(
				'automlp_package_failed',
				sprintf(
					/* translators: %s: underlying error message. */
					__( 'WPML could not build a translatable-string package for this post: %s', 'wpml-translation-check-pro' ),
					$e->getMessage()
				)
			);
		}

		if ( ! is_array( $result ) ) {
			$result = array();
		}

		$contents = isset( $result['contents'] ) && is_array( $result['contents'] ) ? $result['contents'] : array();

		return array(
			'editor_type' => (string) $package->get_editor_type(),
			'title'       => isset( $result['title'] ) ? (string) $result['title'] : '',
			'excerpt'     => isset( $result['excerpt'] ) ? (string) $result['excerpt'] : '',
			'contents'    => $contents,
		);
	}
}
