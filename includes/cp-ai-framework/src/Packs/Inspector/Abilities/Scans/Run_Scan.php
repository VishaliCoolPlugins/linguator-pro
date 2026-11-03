<?php
/**
 * Run Scan ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Inspector\Abilities\Scans;

use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Inspector_Guard;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Report_Service;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs a scan and saves the report: the whole site, one dashboard section, or
 * the live URL test.
 *
 * One tool for every scan button on the dashboard (Scan Website, the section
 * re-scan buttons and Test URLs), chosen with `scope`.
 *
 * @since 1.1.0
 */
final class Run_Scan extends Abstract_Ability {

	/**
	 * Scopes this tool accepts.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	const SCOPES = array( 'all', 'content', 'elementor', 'site', 'urls' );

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'run-scan';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Run Scan', 'duplicate-content-addon-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Translation Inspector: scan the site and save the report. scope "all" (default) is the full scan the dashboard runs: content, menus, strings, the language switcher, URL settings and, when Elementor is active, every Elementor document; it can take a while on large sites. "content", "elementor" or "site" re-scan only that section of an existing report, which is much faster after fixing issues in one area. "urls" tests the live language URLs over HTTP for 404s, redirect loops and pages served in the wrong language; it is rate limited, returns the seconds to wait when run too soon, and cannot test local or password-protected sites. Returns the new summary and what changed. Afterwards call get-fix-plan or get-issues.', 'duplicate-content-addon-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'scope' => array(
					'type'        => 'string',
					'enum'        => self::SCOPES,
					'default'     => 'all',
					'description' => __( 'What to scan: the whole site, one dashboard section, or the live URL test.', 'duplicate-content-addon-for-polylang' ),
				),
				'include_elementor' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'For scope "all": also run the Elementor deep scan when Elementor is active.', 'duplicate-content-addon-for-polylang' ),
				),
				'force' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'For scope "urls": test again even when a recent result is still fresh. The rate limit still applies.', 'duplicate-content-addon-for-polylang' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return $this->write_meta();
	}

	/**
	 * {@inheritDoc}
	 *
	 * The same engine calls as the dashboard buttons: Scan Website mirrors
	 * DUPCAP_Dashboard_Adapter::provide_scan_results(), section scans and Test
	 * URLs call the dashboard's public run_section_scan() / run_url_test().
	 *
	 * @since 1.1.0
	 * @param mixed $input Validated input, or null.
	 * @return array<string, mixed>|WP_Error New summary and what changed.
	 */
	public static function execute( $input = array() ) {
		$input = self::input_array( $input );

		if ( ! Inspector_Guard::engine_ready() ) {
			return Inspector_Guard::engine_error();
		}

		$scope  = isset( $input['scope'] ) ? (string) $input['scope'] : 'all';
		$before = Report_Service::scan_summary();

		switch ( $scope ) {
			case 'urls':
				$outcome = \DUPCAP_Dashboard::instance()->run_url_test( ! empty( $input['force'] ) );
				if ( is_wp_error( $outcome ) ) {
					return $outcome;
				}
				$ran     = ! empty( $outcome['ran'] );
				$message = isset( $outcome['message'] ) ? (string) $outcome['message'] : '';
				break;

			case 'content':
			case 'elementor':
			case 'site':
				$outcome = \DUPCAP_Dashboard::instance()->run_section_scan( $scope );
				if ( is_wp_error( $outcome ) ) {
					if ( 'dupcap_no_saved_scan' === $outcome->get_error_code() ) {
						return new WP_Error(
							'dupcap_no_saved_scan',
							__( 'A section scan updates an existing report, and this site has none yet. Call translation-inspector/run-scan with scope "all" first.', 'duplicate-content-addon-for-polylang' ),
							array( 'status' => 409 )
						);
					}
					return $outcome;
				}
				$ran     = true;
				$message = (string) $outcome['message'];
				break;

			default:
				$error = self::full_scan( ! array_key_exists( 'include_elementor', $input ) || ! empty( $input['include_elementor'] ) );
				if ( null !== $error ) {
					return $error;
				}
				$ran     = true;
				$message = __( 'Full scan finished.', 'duplicate-content-addon-for-polylang' );
		}

		Report_Service::forget_context();
		$after = Report_Service::scan_summary();

		$change = array();
		foreach ( array( 'critical', 'warning', 'passed_checks', 'health_score' ) as $field ) {
			$change[ $field ] = (int) $after['issues'][ $field ] - (int) $before['issues'][ $field ];
		}

		$result = array(
			'scope'     => $scope,
			'ran'       => $ran,
			'message'   => $message,
			'issues'    => $after['issues'],
			'change'    => $before['last_scan_gmt'] ? $change : null,
			'scan'      => array(
				'last_scan_gmt' => $after['last_scan_gmt'],
				'partial_scans' => $after['partial_scans'],
			),
			'next_step' => $ran
				? __( 'Call translation-inspector/get-fix-plan for what to fix first, or get-issues for the full list.', 'duplicate-content-addon-for-polylang' )
				: __( 'The URL test did not run; message says why. The previous results still apply: read them with translation-inspector/get-report type "urls".', 'duplicate-content-addon-for-polylang' ),
		);

		if ( 'urls' === $scope && class_exists( 'DUPCAP_Routing_Scanner' ) ) {
			$result['rate_limit_seconds_left'] = (int) \DUPCAP_Routing_Scanner::rate_limit_remaining();
		}

		return $result;
	}

	/**
	 * Full scan, as Scan Website runs it: Elementor first, then the fast pass.
	 *
	 * @since 1.1.0
	 * @param bool $include_elementor Whether to run the Elementor deep scan.
	 * @return WP_Error|null Error, or null on success.
	 */
	private static function full_scan( $include_elementor ) {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Full site scan may exceed default PHP timeout.
			@set_time_limit( 300 );
		}

		// Elementor first so a main-scan timeout cannot leave a stale Elementor report.
		if ( $include_elementor && class_exists( 'DUPCAP_Elementor_Scanner' ) && \DUPCAP_Elementor_Scanner::is_elementor_available() ) {
			try {
				\DUPCAP_Elementor_Scanner::save_report( ( new \DUPCAP_Elementor_Scanner() )->scan() );
			} catch ( \Throwable $e ) {
				// The dashboard keeps going too; the main scan still runs.
				unset( $e );
			}
		}

		try {
			\DUPCAP_Scanner::save_report( ( new \DUPCAP_Scanner() )->scan() );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'dupcap_scan_failed',
				__( 'The scan could not finish. Try again, or ask the user to run Scan Website on the Translation Inspector dashboard.', 'duplicate-content-addon-for-polylang' ),
				array( 'status' => 500 )
			);
		}

		return null;
	}
}
