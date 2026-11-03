<?php
/**
 * Readiness guards shared by the Linguator Inspector abilities.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorInspector\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralises the "can the Site Scan answer this right now" checks.
 *
 * Abilities run in REST, MCP and WP-CLI contexts. Every refusal says what the
 * caller should do next, because the caller is an AI relaying to a user.
 *
 * @since 1.2.0
 */
final class Inspector_Guard {

	/**
	 * Linguator's model, or null when Linguator is not loaded.
	 *
	 * @since 1.2.0
	 * @return object|null Model.
	 */
	public static function model() {
		return function_exists( 'LMAT' ) && is_object( LMAT() ) && isset( LMAT()->model ) ? LMAT()->model : null;
	}

	/**
	 * Whether the Site Scan engine and Linguator are loaded.
	 *
	 * @since 1.2.0
	 * @return bool True when scans and reports can be used.
	 */
	public static function engine_ready() {
		return class_exists( '\LinguatorPro\Modules\Scan\Scanner' ) && null !== self::model();
	}

	/**
	 * Error for an engine that is not loaded.
	 *
	 * @since 1.2.0
	 * @return WP_Error Error explaining the likely cause.
	 */
	public static function engine_error() {
		return new WP_Error(
			'lmat_inspector_unavailable',
			__( 'Linguator Site Scan is not available on this request: Linguator and Linguator Pro must both be active. Tell the user to activate them in Plugins, then call linguator-inspector/get-inspector-status again.', 'linguator-pro' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Returns an error when no scan has been saved yet, or null when one has.
	 *
	 * Report-reading abilities call this first: an empty issue list must never
	 * stand in for "the site was never scanned".
	 *
	 * @since 1.2.0
	 * @return WP_Error|null Error, or null when a saved scan exists.
	 */
	public static function require_saved_scan() {
		if ( ! self::engine_ready() ) {
			return self::engine_error();
		}

		if ( null !== \LinguatorPro\Modules\Scan\Scanner::get_saved_report() ) {
			return null;
		}

		return new WP_Error(
			'lmat_no_saved_scan',
			__( 'This site has not been scanned yet, so there are no issues to report. Call linguator-inspector/run-scan first, then call this tool again.', 'linguator-pro' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Error when scanning is not allowed, or null when it is.
	 *
	 * Linguator Pro runs scans only with an active license, and refuses to
	 * scan while some content still has no language (the scan would misreport
	 * it). The Site Scan REST routes apply the same two gates.
	 *
	 * @since 1.2.0
	 * @return WP_Error|null Error, or null when a scan may run.
	 */
	public static function scan_blocked() {
		$base = '\LinguatorPro\Admin\Register\Linguator_Pro_Base';

		if ( class_exists( $base ) && ! $base::is_license_active() ) {
			return new WP_Error(
				'lmat_license_required',
				__( 'Linguator Pro scans need an active license. Tell the user to add their Linguator Pro license key, then try again. Reading the saved report does not need a license.', 'linguator-pro' ),
				array( 'status' => 403 )
			);
		}

		$model = self::model();

		if ( null === $model || ! $model->has_languages() ) {
			return new WP_Error(
				'lmat_scan_no_languages',
				__( 'Add at least one language in Linguator before scanning.', 'linguator-pro' ),
				array( 'status' => 409 )
			);
		}

		if ( \LinguatorPro\Modules\Scan\Scanner::has_unassigned_content( $model ) ) {
			return new WP_Error(
				'lmat_scan_unassigned',
				__( 'Some content has no language, and Linguator will not scan until it does. Call linguator-inspector/fix-issue with action "assign_default_language" (dry_run first), then scan again.', 'linguator-pro' ),
				array( 'status' => 409 )
			);
		}

		return null;
	}

	/**
	 * Whether the Linguator pack's abilities are registered, so a fix plan can point at them.
	 *
	 * @since 1.2.0
	 * @return bool True when linguator/* abilities can be called.
	 */
	public static function linguator_abilities_available() {
		return function_exists( 'wp_has_ability' ) && wp_has_ability( 'linguator/translate-post' );
	}
}
