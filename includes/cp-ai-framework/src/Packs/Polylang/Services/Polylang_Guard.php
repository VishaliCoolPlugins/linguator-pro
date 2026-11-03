<?php
/**
 * Polylang availability guards shared by the ability services.
 *
 * @package AutoPoly
 * @since   1.10.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralises the "is Polylang usable right now" checks.
 *
 * Abilities can run in REST and WP-CLI contexts where Polylang's model may not
 * be constructed, so every service call site guards before dereferencing PLL().
 *
 * @since 1.10.0
 */
final class Polylang_Guard {

	/**
	 * Whether PLL() and its model are usable.
	 *
	 * @since 1.10.0
	 * @return bool True when Polylang's model can be dereferenced.
	 */
	public static function model_ready() {
		if ( ! function_exists( 'PLL' ) ) {
			return false;
		}

		$pll = PLL();

		if ( ! is_object( $pll ) || ! property_exists( $pll, 'model' ) ) {
			return false;
		}

		return is_object( $pll->model );
	}

	/**
	 * Whether Polylang's language writer API is available.
	 *
	 * PLL()->model->languages arrived in Polylang 3.4; older releases would
	 * fatal on the calls used by Language_Service.
	 *
	 * @since 1.10.0
	 * @return bool True when languages can be created or deleted.
	 */
	public static function languages_writable() {
		if ( ! self::model_ready() ) {
			return false;
		}

		$model = PLL()->model;

		return isset( $model->languages ) && is_object( $model->languages );
	}

	/**
	 * A WP_Error describing an unavailable Polylang.
	 *
	 * @since 1.10.0
	 * @return WP_Error Error suitable for returning from an ability.
	 */
	public static function unavailable_error() {
		return new WP_Error(
			'atfpp_polylang_unavailable',
			__( 'Polylang is not available in this request. Check with get-site-info that Polylang is installed and active; if it is not, tell the user it must be activated under Plugins before anything can be translated. If it is active, retry the call once.', 'autopoly-ai-translation-for-polylang-pro' )
		);
	}

	/**
	 * Where Polylang's content type setting lives, for messages.
	 *
	 * @since 1.12.0
	 * @return string Menu path and admin URL.
	 */
	public static function content_types_setting_location() {
		return sprintf(
			/* translators: %s: admin URL of Polylang's settings screen. */
			__( 'Languages > Settings > Custom post types and Taxonomies (%s)', 'autopoly-ai-translation-for-polylang-pro' ),
			admin_url( 'admin.php?page=mlang_settings' )
		);
	}

	/**
	 * Post types Polylang's settings screen lets an administrator switch on.
	 *
	 * Mirrors PLL_Settings_CPT::__construct(), so a message never suggests
	 * ticking a box that screen does not show.
	 *
	 * @since 1.12.0
	 * @return string[] Post type names.
	 */
	public static function optional_post_types() {
		$post_types = get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			)
		);

		/** This filter is documented in Polylang's src/model.php. */
		return array_values( array_unique( (array) apply_filters( 'pll_get_post_types', $post_types, true ) ) );
	}

	/**
	 * Taxonomies Polylang's settings screen lets an administrator switch on.
	 *
	 * Mirrors PLL_Settings_CPT::__construct().
	 *
	 * @since 1.12.0
	 * @return string[] Taxonomy names.
	 */
	public static function optional_taxonomies() {
		$taxonomies = get_taxonomies(
			array(
				'public'   => true,
				'_builtin' => false,
			)
		);
		$taxonomies = array_diff( $taxonomies, get_taxonomies( array( '_pll' => true ) ) );

		/** This filter is documented in Polylang's src/model.php. */
		return array_values( array_unique( (array) apply_filters( 'pll_get_taxonomies', $taxonomies, true ) ) );
	}

	/**
	 * Explains why a post type or taxonomy cannot be translated, and what unblocks it.
	 *
	 * Written for an AI caller to act on and pass on: it names the one setting
	 * that decides it, says no ability can change it, and gives the steps that
	 * follow once it is changed. Without this the caller only saw "no language
	 * assigned", and tried fixes that cannot work.
	 *
	 * @since 1.12.0
	 * @param string $object_type "post" or "term".
	 * @param string $subtype     Post type or taxonomy name.
	 * @return string Message.
	 */
	public static function not_translated_message( $object_type, $subtype ) {
		$is_term = 'term' === $object_type;

		// Media is built in, so it is not among the content type checkboxes: Polylang's separate Media module decides it.
		if ( ! $is_term && 'attachment' === $subtype ) {
			return sprintf(
				/* translators: %s: admin URL of Polylang's settings screen. */
				__( 'Polylang does not translate Media (attachment) because its Media module is switched off, so media items have no language and their titles, alt text and captions cannot be translated. This is a site setting no AutoPoly tool can change. Tell the user a site administrator must activate "Media" under Languages > Settings (%s). The files themselves are shared between languages either way; only their text is translated.', 'autopoly-ai-translation-for-polylang-pro' ),
				admin_url( 'admin.php?page=mlang_settings' )
			);
		}

		$object   = $is_term ? get_taxonomy( $subtype ) : get_post_type_object( $subtype );
		$label    = $object ? (string) $object->labels->name : (string) $subtype;
		$optional = in_array( $subtype, $is_term ? self::optional_taxonomies() : self::optional_post_types(), true );

		if ( ! $optional ) {
			return sprintf(
				/* translators: 1: post type or taxonomy label, 2: its name. */
				__( 'Polylang does not translate "%1$s" (%2$s), and its settings do not offer it: Polylang lists only public custom post types and taxonomies, plus any a plugin adds through the pll_get_post_types or pll_get_taxonomies filters. This content cannot be translated with Polylang. Tell the user so; do not retry.', 'autopoly-ai-translation-for-polylang-pro' ),
				$label,
				$subtype
			);
		}

		return sprintf(
			/* translators: 1: post type or taxonomy label, 2: its name, 3: settings location. */
			__( 'Polylang does not translate "%1$s" (%2$s), so these items have no language and cannot be translated yet. This is a site setting no AutoPoly tool can change. Tell the user a site administrator must tick "%1$s (%2$s)" under %3$s and save. After that, existing items still have no language: give each one a language with manage-translation-links (action "set_language"), or use the option Polylang offers on its Languages screen to assign the default language to content without one. Then translate them.', 'autopoly-ai-translation-for-polylang-pro' ),
			$label,
			$subtype,
			self::content_types_setting_location()
		);
	}
}
