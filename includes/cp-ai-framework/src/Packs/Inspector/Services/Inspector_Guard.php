<?php
/**
 * Readiness guards shared by the Inspector abilities.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Inspector\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralises the "can the Inspector answer this right now" checks.
 *
 * Abilities run in REST, MCP and WP-CLI contexts. Every refusal says what the
 * caller should do next, because the caller is an AI relaying to a user.
 *
 * @since 1.1.0
 */
final class Inspector_Guard {

	/**
	 * Toolkit for Polylang version that first has the methods listed below.
	 *
	 * @since 1.1.1
	 * @var string
	 */
	const MIN_ENGINE_VERSION = '2.3.0';

	/**
	 * Methods of DUPCAP_Dashboard the abilities call.
	 *
	 * Toolkit for Polylang added them for these tools in 2.3.0. An older copy has
	 * the class but not the methods, and calling one is a fatal error that reaches
	 * the caller as raw PHP text. Checking the methods rather than the version
	 * number also covers development builds and forks.
	 *
	 * @since 1.1.1
	 * @var string[]
	 */
	const REQUIRED_ENGINE_METHODS = array(
		'get_report_context',
		'export_report',
		'run_url_test',
		'run_section_scan',
		'assign_default_language',
		'refresh_after_language_change',
		'recheck_elementor_issue',
		'recheck_content_issue',
		'recheck_elementor_library_issue',
	);

	/**
	 * Whether the Inspector engine and Polylang are loaded, and the engine is new enough.
	 *
	 * @since 1.1.0
	 * @return bool True when scans and reports can be used.
	 */
	public static function engine_ready() {
		return class_exists( 'DUPCAP_Scanner' )
			&& function_exists( 'PLL' )
			&& is_object( PLL() )
			&& isset( PLL()->model )
			&& array() === self::missing_engine_methods();
	}

	/**
	 * The required DUPCAP_Dashboard methods the installed Toolkit for Polylang lacks.
	 *
	 * @since 1.1.1
	 * @return string[] Method names; empty when the engine is new enough.
	 */
	public static function missing_engine_methods() {
		if ( ! class_exists( 'DUPCAP_Dashboard' ) ) {
			return self::REQUIRED_ENGINE_METHODS;
		}

		$missing = array();

		foreach ( self::REQUIRED_ENGINE_METHODS as $method ) {
			if ( ! method_exists( 'DUPCAP_Dashboard', $method ) ) {
				$missing[] = $method;
			}
		}

		return $missing;
	}

	/**
	 * Error for an engine that is not loaded, or is too old for these tools.
	 *
	 * @since 1.1.0
	 * @return WP_Error Error explaining the likely cause.
	 */
	public static function engine_error() {
		$missing = class_exists( 'DUPCAP_Scanner' ) ? self::missing_engine_methods() : array();

		if ( array() !== $missing ) {
			$installed = defined( 'DUPCAP_VERSION' ) ? (string) DUPCAP_VERSION : 'unknown';

			return new WP_Error(
				'dupcap_inspector_outdated',
				sprintf(
					/* translators: 1: installed Toolkit for Polylang version, 2: version these tools need. */
					__( 'The installed Toolkit for Polylang (version %1$s) is too old for these tools: it needs version %2$s or later. Tell the user to update Toolkit for Polylang in Plugins, then call translation-inspector/get-inspector-status again.', 'duplicate-content-addon-for-polylang' ),
					$installed,
					self::MIN_ENGINE_VERSION
				),
				array(
					'status'            => 503,
					'installed_version' => $installed,
					'required_version'  => self::MIN_ENGINE_VERSION,
					'missing_methods'   => $missing,
				)
			);
		}

		return new WP_Error(
			'dupcap_inspector_unavailable',
			__( 'Translation Inspector is not available on this request: Polylang must be installed and active. Tell the user to activate Polylang in Plugins, then call translation-inspector/get-inspector-status again.', 'duplicate-content-addon-for-polylang' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Returns an error when no scan has been saved yet, or null when one has.
	 *
	 * Report-reading abilities call this first: an empty issue list must never
	 * stand in for "the site was never scanned".
	 *
	 * @since 1.1.0
	 * @return WP_Error|null Error, or null when a saved scan exists.
	 */
	public static function require_saved_scan() {
		if ( ! self::engine_ready() ) {
			return self::engine_error();
		}

		if ( \DUPCAP_Scanner::has_saved_scan() ) {
			return null;
		}

		return new WP_Error(
			'dupcap_no_saved_scan',
			__( 'This site has not been scanned yet, so there are no issues to report. Call translation-inspector/run-scan first, then call this tool again.', 'duplicate-content-addon-for-polylang' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Whether AutoPoly's abilities are registered, so a fix plan can point at them.
	 *
	 * @since 1.1.0
	 * @return bool True when autopoly/* abilities can be called.
	 */
	public static function autopoly_abilities_available() {
		return function_exists( 'wp_has_ability' ) && wp_has_ability( 'autopoly/translate-post' );
	}
}
