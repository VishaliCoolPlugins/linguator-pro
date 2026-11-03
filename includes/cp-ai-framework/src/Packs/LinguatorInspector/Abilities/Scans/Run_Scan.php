<?php
/**
 * Run Scan ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Scans;

use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Inspector_Guard;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Report_Service;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run Scan.
 *
 * @since 1.2.0
 */
final class Run_Scan extends Abstract_Ability {

	/**
	 * Scopes the ability accepts.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const SCOPES = array( 'all', 'content', 'elementor', 'site', 'urls' );

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'run-scan';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Run Scan', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator Site Scan: scan the site and save the report. scope "all" (default) is the full scan the Site Scan screen runs: content, menus, strings, the language switcher, URL settings and, when Elementor is active, every Elementor document; it can take a while on large sites, and it keeps the last live URL test. "content", "elementor" or "site" re-scan only that section of an existing report, which is much faster after fixing issues in one area. "urls" re-runs the URL checks, including the live test of the language home pages and sample pages over HTTP for 404s and wrong-language pages; it cannot test local or password-protected sites and then reports that it could not. Scans need an active Linguator Pro license and fail while some content has no language (assign it first with fix-issue assign_default_language). Returns the new summary and what changed. Afterwards call get-fix-plan or get-issues.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
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
					'description' => __( 'What to scan: the whole site, one Site Scan section, or the URL checks with the live URL test.', 'linguator-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return $this->write_meta();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param mixed $input Validated input, or null.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function execute( $input = array() ) {
		$input = self::input_array( $input );

		if ( ! Inspector_Guard::engine_ready() ) {
			return Inspector_Guard::engine_error();
		}

		$scope = isset( $input['scope'] ) ? sanitize_key( (string) $input['scope'] ) : 'all';
		$scope = '' === $scope ? 'all' : $scope;

		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			return new WP_Error(
				'lmat_invalid_scope',
				sprintf(
					/* translators: %s: comma-separated scopes. */
					__( 'Unknown scope. Use one of: %s.', 'linguator-pro' ),
					implode( ', ', self::SCOPES )
				),
				array( 'status' => 400 )
			);
		}

		$blocked = Inspector_Guard::scan_blocked();
		if ( null !== $blocked ) {
			return $blocked;
		}

		$elementor = '\LinguatorPro\Modules\Scan\Checks\Elementor';
		if ( 'elementor' === $scope && ( ! class_exists( $elementor ) || ! $elementor::available() ) ) {
			return array(
				'scope'     => $scope,
				'ran'       => false,
				'message'   => __( 'Elementor is not active on this site, so there is nothing to scan.', 'linguator-pro' ),
				'next_step' => __( 'Use scope "all" or "content" instead.', 'linguator-pro' ),
			);
		}

		$before = Report_Service::scan_summary();

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A full site scan may exceed the default PHP timeout.
			@set_time_limit( 300 );
		}

		$scanner = Report_Service::scanner();

		try {
			if ( 'all' === $scope ) {
				$report  = $scanner->scan();
				$message = __( 'Full scan finished.', 'linguator-pro' );
			} else {
				$report = $scanner->scan_section( $scope );
				if ( null === $report ) {
					return new WP_Error(
						'lmat_no_saved_scan',
						__( 'A section scan updates an existing report, and this site has none yet. Call linguator-inspector/run-scan with scope "all" first.', 'linguator-pro' ),
						array( 'status' => 409 )
					);
				}
				/* translators: %s: scan section. */
				$message = sprintf( __( 'Section "%s" scanned.', 'linguator-pro' ), $scope );
			}
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'lmat_scan_failed',
				__( 'The scan could not finish. Try again, or ask the user to run Scan site on the Linguator Site Scan screen.', 'linguator-pro' ),
				array( 'status' => 500 )
			);
		}

		Report_Service::forget_context();
		$after = Report_Service::scan_summary();

		$change = array();
		foreach ( array( 'critical', 'warning', 'passed_checks', 'health_score' ) as $field ) {
			$change[ $field ] = (int) $after['issues'][ $field ] - (int) $before['issues'][ $field ];
		}

		$ran = true;
		if ( 'urls' === $scope ) {
			foreach ( (array) $report['results'] as $result ) {
				if ( in_array( $result['id'] ?? '', array( 'url_route_untestable', 'url_route_site_gated' ), true ) ) {
					$ran     = false;
					$message = wp_strip_all_tags( (string) $result['message'] );
				}
			}
		}

		return array(
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
				? __( 'Call linguator-inspector/get-fix-plan for what to fix first, or get-issues for the full list.', 'linguator-pro' )
				: __( 'The live URL test could not run; message says why. The other URL checks were updated. Read them with linguator-inspector/get-report type "urls".', 'linguator-pro' ),
		);
	}
}
