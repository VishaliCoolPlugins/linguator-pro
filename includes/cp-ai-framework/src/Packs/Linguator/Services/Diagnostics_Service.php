<?php
/**
 * Site health, AI settings and license reads.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports Linguator's health tests, AI provider setup and license state.
 *
 * Nothing here returns a key, token or the license key itself.
 *
 * @since 1.3.0
 */
final class Diagnostics_Service {

	/**
	 * Runs Linguator's direct Site Health tests.
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Tests, skipped async tests and a status summary.
	 */
	public static function site_health() {
		$registered = self::collect_tests();
		$results    = array();
		$skipped    = array();
		$counts     = array(
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
			'error'       => 0,
		);

		foreach ( $registered['async'] as $id => $test ) {
			$skipped[] = array(
				'id'     => (string) $id,
				'label'  => isset( $test['label'] ) ? wp_strip_all_tags( (string) $test['label'] ) : (string) $id,
				'reason' => 'async',
			);
		}

		foreach ( $registered['direct'] as $id => $test ) {
			$label    = isset( $test['label'] ) ? wp_strip_all_tags( (string) $test['label'] ) : (string) $id;
			$callback = $test['test'] ?? null;

			if ( ! is_callable( $callback ) ) {
				$skipped[] = array(
					'id'     => (string) $id,
					'label'  => $label,
					'reason' => 'not_callable',
				);
				continue;
			}

			try {
				$raw = call_user_func( $callback );
			} catch ( \Throwable $e ) {
				++$counts['error'];
				$results[] = array(
					'id'          => (string) $id,
					'label'       => $label,
					'status'      => 'error',
					'description' => __( 'The test failed to run.', 'linguator-pro' ),
					'actions'     => array(),
				);
				continue;
			}

			if ( ! is_array( $raw ) ) {
				$skipped[] = array(
					'id'     => (string) $id,
					'label'  => $label,
					'reason' => 'no_result',
				);
				continue;
			}

			$status = isset( $raw['status'] ) ? sanitize_key( (string) $raw['status'] ) : 'recommended';

			if ( ! isset( $counts[ $status ] ) ) {
				$status = 'recommended';
			}

			++$counts[ $status ];
			$results[] = array(
				'id'          => (string) $id,
				'label'       => wp_strip_all_tags( (string) ( $raw['label'] ?? $label ) ),
				'status'      => $status,
				'description' => trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $raw['description'] ?? '' ) ) ) ),
				'actions'     => self::extract_links( (string) ( $raw['actions'] ?? '' ) ),
			);
		}

		$report = array(
			'tests'   => $results,
			'skipped' => $skipped,
			'summary' => $counts,
		);

		if ( array() === $results && array() === $skipped ) {
			$report['note'] = __( 'No Linguator Site Health tests apply right now. The homepage test only exists when the site uses a static front page.', 'linguator-pro' );
		}

		return $report;
	}

	/**
	 * Collects Linguator's registered Site Health tests.
	 *
	 * The Linguator tests are registered by an admin-only module, so on a REST
	 * or MCP request they are registered here when the module can be built.
	 *
	 * @since 1.3.0
	 * @return array{direct: array<string, array<string, mixed>>, async: array<string, array<string, mixed>>} Tests.
	 */
	private static function collect_tests() {
		$tests = apply_filters(
			'site_status_tests',
			array(
				'direct' => array(),
				'async'  => array(),
			)
		);

		if ( ! self::has_linguator_test( $tests ) ) {
			$tests = self::register_homepage_test( $tests );
		}

		$out = array(
			'direct' => array(),
			'async'  => array(),
		);

		foreach ( array( 'direct', 'async' ) as $type ) {
			if ( empty( $tests[ $type ] ) || ! is_array( $tests[ $type ] ) ) {
				continue;
			}

			foreach ( $tests[ $type ] as $id => $test ) {
				if ( is_array( $test ) && self::is_linguator_test( (string) $id, $test ) ) {
					$out[ $type ][ (string) $id ] = $test;
				}
			}
		}

		return $out;
	}

	/**
	 * Whether a test belongs to Linguator.
	 *
	 * @since 1.3.0
	 * @param string               $id   Test id.
	 * @param array<string, mixed> $test Test declaration.
	 * @return bool True for a Linguator test.
	 */
	private static function is_linguator_test( $id, array $test ) {
		if ( 0 === strpos( $id, 'lmat' ) || 0 === strpos( $id, 'linguator' ) ) {
			return true;
		}

		$callback = $test['test'] ?? null;

		return is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) && 0 === stripos( get_class( $callback[0] ), 'Linguator' );
	}

	/**
	 * Whether the test list already holds a Linguator test.
	 *
	 * @since 1.3.0
	 * @param mixed $tests Test list.
	 * @return bool True when one is present.
	 */
	private static function has_linguator_test( $tests ) {
		foreach ( array( 'direct', 'async' ) as $type ) {
			if ( empty( $tests[ $type ] ) || ! is_array( $tests[ $type ] ) ) {
				continue;
			}

			foreach ( $tests[ $type ] as $id => $test ) {
				if ( is_array( $test ) && self::is_linguator_test( (string) $id, $test ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Registers the homepage test when the admin module is not loaded.
	 *
	 * @since 1.3.0
	 * @param mixed $tests Test list.
	 * @return mixed Test list, with the homepage test when it could be built.
	 */
	private static function register_homepage_test( $tests ) {
		$model = Linguator_Guard::model();

		if ( null === $model || 'page' !== get_option( 'show_on_front' ) || ! defined( 'LINGUATOR_DIR' ) ) {
			return $tests;
		}

		$page_id = get_option( 'page_on_front' );

		if ( ! is_numeric( $page_id ) || ! get_post( (int) $page_id ) ) {
			return $tests;
		}

		$file = LINGUATOR_DIR . '/modules/site-health/admin-site-health.php';

		if ( ! class_exists( 'Linguator_Admin_Site_Health', false ) && is_readable( $file ) ) {
			require_once $file;
		}

		$static = function_exists( 'LMAT' ) && is_object( LMAT() ) && isset( LMAT()->static_pages ) ? LMAT()->static_pages : null;

		if ( ! class_exists( 'Linguator_Admin_Site_Health', false ) || ! is_object( $static ) || ! method_exists( $static, 'get_must_translate_message' ) ) {
			return $tests;
		}

		$holder               = new \stdClass();
		$holder->model        = $model;
		$holder->static_pages = $static;
		$health               = new \Linguator_Admin_Site_Health( $holder );

		return $health->status_tests( is_array( $tests ) ? $tests : array() );
	}

	/**
	 * Pulls links out of an actions HTML string.
	 *
	 * @since 1.3.0
	 * @param string $html Actions markup.
	 * @return array<int, array<string, string>> Links with label and url.
	 */
	private static function extract_links( $html ) {
		$links = array();

		if ( '' === $html || ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			return $links;
		}

		foreach ( $matches as $match ) {
			$links[] = array(
				'label' => trim( wp_strip_all_tags( $match[2] ) ),
				'url'   => esc_url_raw( html_entity_decode( $match[1] ) ),
			);
		}

		return $links;
	}

	/**
	 * AI translation provider setup, without any key.
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Providers and defaults.
	 */
	public static function ai_settings() {
		$options = function_exists( 'LMAT' ) && is_object( LMAT() ) && isset( LMAT()->options ) ? LMAT()->options : array();
		$config  = isset( $options['ai_translation_configuration'] ) && is_array( $options['ai_translation_configuration'] ) ? $options['ai_translation_configuration'] : array();
		$enabled = isset( $config['provider'] ) && is_array( $config['provider'] ) ? $config['provider'] : array();
		$allowed = function_exists( 'linguator_get_allowed_ai_providers' ) ? (array) linguator_get_allowed_ai_providers() : array();
		$keys    = isset( $options['api_keys'] ) && is_array( $options['api_keys'] ) ? $options['api_keys'] : array();
		$gemini  = '' !== trim( (string) get_option( 'connectors_ai_google_api_key', '' ) );
		$labels  = array(
			'google'          => 'Google Translate',
			'chrome_local_ai' => 'Chrome built-in AI',
			'edge_local_ai'   => 'Edge built-in AI',
			'gemini'          => 'Gemini',
		);
		$result  = array();

		foreach ( $labels as $key => $label ) {
			$is_enabled = ! empty( $enabled[ $key ] );
			$allowed_ok = in_array( $key, $allowed, true );
			$row        = array(
				'provider'   => $key,
				'label'      => $label,
				'allowed'    => $allowed_ok,
				'enabled'    => $is_enabled,
				'configured' => 'gemini' === $key ? $gemini : true,
				'usable'     => $allowed_ok && $is_enabled && ( 'gemini' !== $key || $gemini ),
			);

			if ( 'gemini' === $key ) {
				$row['model'] = isset( $keys['gemini_model'] ) ? sanitize_text_field( (string) $keys['gemini_model'] ) : '';
			}

			$result[] = $row;
		}

		$default = isset( $config['default_provider'] ) ? (string) $config['default_provider'] : 'google';
		$choice  = function_exists( 'linguator_get_cpfm_opt_in_choice' ) ? linguator_get_cpfm_opt_in_choice() : false;

		return array(
			'providers'                  => $result,
			'default_provider'           => $default,
			'default_provider_usable'    => '' !== $default && ! empty( $enabled[ $default ] ) && in_array( $default, $allowed, true ),
			'slug_translation_option'    => isset( $config['slug_translation_option'] ) ? sanitize_key( (string) $config['slug_translation_option'] ) : 'title_translate',
			'bulk_post_status'           => isset( $config['bulk_translation_post_status'] ) ? sanitize_key( (string) $config['bulk_translation_post_status'] ) : 'draft',
			'menu_label_machine_translation' => ! empty( $enabled['google'] ),
			'menu_label_note'            => 'Menu labels are machine translated with Google Translate, so this follows the Google provider switch.',
			'usage_tracking_opt_in'      => false === $choice ? 'not_chosen' : sanitize_key( (string) $choice ),
		);
	}

	/**
	 * License state and scan readiness, without the key.
	 *
	 * @since 1.3.0
	 * @return array<string, mixed>|WP_Error License details.
	 */
	public static function license_status() {
		$base = '\LinguatorPro\Admin\Register\Linguator_Pro_Base';

		if ( ! class_exists( $base ) ) {
			return new WP_Error( 'lmat_license_unavailable', __( 'The Linguator Pro license module is not loaded.', 'linguator-pro' ), array( 'status' => 503 ) );
		}

		$active  = $base::is_license_active();
		$info    = $base::get_register_info();
		$reg     = '\LinguatorPro\Admin\Register\Linguator_Pro_Register';
		$has_key = ( is_object( $info ) && ! empty( $info->license_key ) ) || ( class_exists( $reg ) && '' !== (string) get_option( $reg::lmatp_license_key_option(), '' ) );
		$state   = 'inactive';
		$expiry  = '';
		$support = '';
		$plan    = '';

		if ( is_object( $info ) ) {
			$expiry  = isset( $info->expire_date ) ? sanitize_text_field( (string) $info->expire_date ) : '';
			$support = isset( $info->support_end ) ? sanitize_text_field( (string) $info->support_end ) : '';
			$plan    = isset( $info->license_title ) ? sanitize_text_field( (string) $info->license_title ) : '';

			if ( ! empty( $info->is_valid ) ) {
				$state = 'license_expired' === $info->is_valid ? 'expired' : 'active';
			}
		}

		if ( 'active' === $state && '' !== $expiry && 'no expiry' !== strtolower( $expiry ) && false !== strtotime( $expiry ) && strtotime( $expiry ) < time() ) {
			$state = 'expired';
		}

		$scan_blocker = null;

		if ( class_exists( '\CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Inspector_Guard' ) ) {
			$blocked = \CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Inspector_Guard::scan_blocked();

			if ( is_wp_error( $blocked ) ) {
				$scan_blocker = array(
					'code'    => $blocked->get_error_code(),
					'message' => $blocked->get_error_message(),
				);
			}
		} elseif ( ! $active ) {
			$scan_blocker = array(
				'code'    => 'lmat_license_required',
				'message' => __( 'Linguator Pro scans need an active license.', 'linguator-pro' ),
			);
		}

		return array(
			'state'               => $state,
			'license_active'      => $active,
			'has_key'             => $has_key,
			'plan'                => $plan,
			'expires'             => $expiry,
			'support_ends'        => $support,
			'site_activations'    => null,
			'linguator_version'   => defined( 'LINGUATOR_VERSION' ) ? (string) LINGUATOR_VERSION : '',
			'linguator_pro_version' => defined( 'LINGUATOR_PRO_VERSION' ) ? (string) LINGUATOR_PRO_VERSION : '',
			'scan_can_run'        => null === $scan_blocker,
			'scan_blocker'        => $scan_blocker,
		);
	}
}
