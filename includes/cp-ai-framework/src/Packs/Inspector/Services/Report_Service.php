<?php
/**
 * Read access to the Inspector's saved reports.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Inspector\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns the Inspector's stored reports into ability payloads.
 *
 * Reads only. Abilities never re-implement a check: they read what the scanners
 * saved (dupcap_last_report, dupcap_elementor_last_report,
 * dupcap_routing_last_report) and trigger scans through the engine classes.
 *
 * @since 1.1.0
 */
final class Report_Service {

	/**
	 * Optional parts of the status payload.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	const STATUS_EXTRAS = array( 'environment', 'coverage' );

	/**
	 * Dashboard and Elementor severity values, mapped to the ones the tools use.
	 *
	 * @since 1.1.0
	 * @var array<string, string>
	 */
	const SEVERITY_MAP = array(
		'crit'     => 'critical',
		'critical' => 'critical',
		'warn'     => 'warning',
		'major'    => 'warning',
		'warning'  => 'warning',
		'info'     => 'info',
		'pass'     => 'pass',
	);

	/**
	 * Dashboard context for this request.
	 *
	 * @since 1.1.0
	 * @var array<string, mixed>|null
	 */
	private static $context = null;

	/**
	 * Everything the dashboard renders, read once per request.
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> See DUPCAP_Dashboard::get_report_context().
	 */
	public static function context() {
		if ( null === self::$context ) {
			self::$context = (array) \DUPCAP_Dashboard::instance()->get_report_context();
		}

		return self::$context;
	}

	/**
	 * Drop the cached context after a scan, recheck or ignore changed the report.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public static function forget_context() {
		self::$context = null;
	}

	/**
	 * Severity as the tools report it: critical, warning, info or pass.
	 *
	 * @since 1.1.0
	 * @param string $severity Dashboard or Elementor severity.
	 * @return string
	 */
	public static function severity( $severity ) {
		$severity = (string) $severity;

		return self::SEVERITY_MAP[ $severity ] ?? 'info';
	}

	/**
	 * Section as the tools report it: content, elementor, urls or site.
	 *
	 * @since 1.1.0
	 * @param string $section DUPCAP_Issue_Types::SECTION_* value.
	 * @return string
	 */
	public static function section( $section ) {
		return 'other' === $section || '' === (string) $section ? 'site' : (string) $section;
	}

	/**
	 * Severity counts and scan time of the saved report.
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> The "scan" part of the status payload.
	 */
	public static function scan_summary() {
		$status = self::get_scan_status();

		return $status['scan'];
	}

	/**
	 * Overview of the Inspector's state on this site.
	 *
	 * @since 1.1.0
	 * @param string[] $include Parts of STATUS_EXTRAS to add. The AI Tools page
	 *                          passes none: it shows only the scan state.
	 * @return array<string, mixed> Status payload.
	 */
	public static function get_status( array $include = array() ) {
		$status = self::get_scan_status();

		if ( in_array( 'environment', $include, true ) ) {
			$status['environment'] = self::get_environment();
		}

		if ( in_array( 'coverage', $include, true ) ) {
			$status['coverage'] = self::get_language_coverage();
		}

		return $status;
	}

	/**
	 * Multilingual setup the Inspector checks against.
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> Add-ons plus the dashboard's environment rows.
	 */
	public static function get_environment() {
		return array(
			'polylang_addons' => array_values( array_map( 'strval', (array) \DUPCAP_Dashboard_Adapter::get_active_polylang_addons() ) ),
			'details'         => (array) \DUPCAP_Dashboard_Adapter::provide_environment( array() ),
		);
	}

	/**
	 * Translation coverage per language, weakest first.
	 *
	 * The same figures as the dashboard's coverage card, measured live from
	 * Polylang's translation links rather than from the saved scan.
	 *
	 * @since 1.1.0
	 * @return array<int, array<string, mixed>> Rows: language, slug, coverage (percent).
	 */
	public static function get_language_coverage() {
		$rows = array();

		foreach ( (array) \DUPCAP_Dashboard_Adapter::provide_language_coverage( array(), array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$rows[] = array(
				'language' => (string) ( $row['language'] ?? '' ),
				'slug'     => (string) ( $row['slug'] ?? '' ),
				'coverage' => (int) ( $row['coverage'] ?? 0 ),
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $a['coverage'] <=> $b['coverage'];
			}
		);

		return $rows;
	}

	/**
	 * Scan state: the part of the status payload that is always returned.
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> Status payload.
	 */
	private static function get_scan_status() {
		$report    = \DUPCAP_Scanner::get_saved_report();
		$has_scan  = \DUPCAP_Scanner::has_saved_scan();
		$scan_time = ( $has_scan && isset( $report['time'] ) ) ? (int) $report['time'] : 0;
		$summary   = ( is_array( $report ) && isset( $report['summary'] ) && is_array( $report['summary'] ) )
			? $report['summary']
			: \DUPCAP_Scanner::summarize( is_array( $report ) && isset( $report['results'] ) ? (array) $report['results'] : array() );

		$elementor_report = class_exists( 'DUPCAP_Elementor_Scanner' ) ? \DUPCAP_Elementor_Scanner::get_saved_report() : null;
		$routing_on       = class_exists( 'DUPCAP_Routing_Scanner' ) && \DUPCAP_Routing_Scanner::is_available();

		return array(
			'plugin_version' => defined( 'DUPCAP_VERSION' ) ? DUPCAP_VERSION : '',
			'polylang'       => self::polylang_info(),
			'scan'           => array(
				'has_saved_scan'  => $has_scan,
				'last_scan_gmt'   => $scan_time ? gmdate( 'c', $scan_time ) : null,
				'last_scan_label' => $scan_time ? \DUPCAP_Scanner::format_scan_time( $scan_time ) : null,
				'issues'          => self::issue_counts( $has_scan ),
				'checks'          => array(
					'critical' => (int) ( $summary['critical'] ?? 0 ),
					'warning'  => (int) ( $summary['warning'] ?? 0 ),
					'info'     => (int) ( $summary['info'] ?? 0 ),
					'pass'     => (int) ( $summary['pass'] ?? 0 ),
					'total'    => (int) ( $summary['total'] ?? 0 ),
				),
				'partial_scans'   => ( is_array( $report ) && isset( $report['partial_scans'] ) ) ? (array) $report['partial_scans'] : (object) array(),
			),
			'elementor'      => array(
				'available'        => class_exists( 'DUPCAP_Elementor_Scanner' ) && \DUPCAP_Elementor_Scanner::is_elementor_available(),
				'has_saved_report' => is_array( $elementor_report ),
				'last_scan_gmt'    => ( is_array( $elementor_report ) && ! empty( $elementor_report['time'] ) ) ? gmdate( 'c', (int) $elementor_report['time'] ) : null,
			),
			'routing'        => array(
				'available'              => $routing_on,
				'has_fresh_report'       => $routing_on && \DUPCAP_Routing_Scanner::has_fresh_report(),
				'rate_limit_seconds_left' => $routing_on ? (int) \DUPCAP_Routing_Scanner::rate_limit_remaining() : 0,
			),
			'ignored_count'  => class_exists( 'DUPCAP_Ignored_Issues' ) ? count( (array) \DUPCAP_Ignored_Issues::get_all() ) : 0,
			'fix_tools'      => array(
				'autopoly_abilities' => Inspector_Guard::autopoly_abilities_available(),
			),
			'next_step'      => $has_scan
				? __( 'Call translation-inspector/get-issues to list what needs fixing, or translation-inspector/get-fix-plan for the recommended order.', 'duplicate-content-addon-for-polylang' )
				: __( 'No scan has been saved yet. Call translation-inspector/run-scan first.', 'duplicate-content-addon-for-polylang' ),
		);
	}

	/**
	 * Issue counts as the dashboard badges show them.
	 *
	 * These count issues after grouping, ignores and Elementor findings, so
	 * they match get-issues. The raw per-check tally is reported as "checks".
	 *
	 * @since 1.1.0
	 * @param bool $has_scan Whether a saved scan exists.
	 * @return array<string, int>
	 */
	private static function issue_counts( $has_scan ) {
		$counts = $has_scan ? (array) ( self::context()['counts'] ?? array() ) : array();

		return array(
			'critical'      => (int) ( $counts['crit'] ?? 0 ),
			'warning'       => (int) ( $counts['warn'] ?? 0 ),
			'passed_checks' => (int) ( $counts['pass'] ?? 0 ),
			'health_score'  => $has_scan ? (int) ( self::context()['health_score'] ?? 0 ) : 0,
		);
	}

	/**
	 * Polylang languages and version, for the status payload.
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> Polylang details.
	 */
	private static function polylang_info() {
		$languages = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list( array( 'fields' => 'slug' ) ) : array();

		return array(
			'version'          => defined( 'POLYLANG_VERSION' ) ? POLYLANG_VERSION : '',
			'is_pro'           => defined( 'POLYLANG_PRO' ) && POLYLANG_PRO,
			'default_language' => function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '',
			'languages'        => array_values( array_map( 'strval', $languages ) ),
		);
	}
}
