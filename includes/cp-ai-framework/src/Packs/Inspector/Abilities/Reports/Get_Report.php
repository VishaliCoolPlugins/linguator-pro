<?php
/**
 * Get Report ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Inspector\Abilities\Reports;

use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Inspector_Guard;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Issue_Service;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Report_Service;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns one of the Inspector's reports: the Elementor health report, the
 * URL test results, or the full export the dashboard downloads.
 *
 * @since 1.1.0
 */
final class Get_Report extends Abstract_Ability {

	/**
	 * Report types this tool returns.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	const TYPES = array( 'full', 'elementor', 'urls' );

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-report';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Report', 'duplicate-content-addon-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Translation Inspector: get a saved report. type "full" (default) is the report the dashboard exports, in Markdown to share with a client or support, or JSON. type "elementor" is the Elementor deep-scan report: health score, which document types were scanned (pages, headers, footers, loop items, popups) and the problem buckets, such as untranslated widgets, links to the wrong language, missing template translations and display conditions, each with examples and the template to edit. type "urls" is the last live URL test: per language, whether the home page and sample URLs answer in the right language or return 404s, redirect loops or the wrong language, and whether the result is stale. Says when a report does not exist yet and which run-scan scope creates it.', 'duplicate-content-addon-for-polylang' );
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
				'type' => array(
					'type'        => 'string',
					'enum'        => self::TYPES,
					'default'     => 'full',
					'description' => __( 'Which report to return.', 'duplicate-content-addon-for-polylang' ),
				),
				'format' => array(
					'type'        => 'string',
					'enum'        => array( 'json', 'markdown' ),
					'default'     => 'json',
					'description' => __( 'For type "full": JSON for structured data, Markdown for a human-readable summary. The other types are always JSON.', 'duplicate-content-addon-for-polylang' ),
				),
				'language' => array(
					'type'        => 'string',
					'description' => __( 'For type "urls": only this language slug, for example "fr".', 'duplicate-content-addon-for-polylang' ),
				),
				'example_limit' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => 50,
					'default'     => 5,
					'description' => __( 'For type "elementor": maximum examples per problem bucket; 0 for counts only.', 'duplicate-content-addon-for-polylang' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * "full" is the dashboard's own export (DUPCAP_Dashboard::export_report()).
	 * "elementor" and "urls" read the saved Elementor and URL-test reports.
	 *
	 * @since 1.1.0
	 * @param mixed $input Validated input, or null.
	 * @return array<string, mixed>|WP_Error Report.
	 */
	public static function execute( $input = array() ) {
		$input = self::input_array( $input );

		if ( ! Inspector_Guard::engine_ready() ) {
			return Inspector_Guard::engine_error();
		}

		$type = isset( $input['type'] ) ? (string) $input['type'] : 'full';

		if ( 'elementor' === $type ) {
			return self::elementor_report( isset( $input['example_limit'] ) ? (int) $input['example_limit'] : 5 );
		}

		if ( 'urls' === $type ) {
			return self::url_report( isset( $input['language'] ) ? sanitize_key( (string) $input['language'] ) : '' );
		}

		$error = Inspector_Guard::require_saved_scan();
		if ( null !== $error ) {
			return $error;
		}

		$format = isset( $input['format'] ) ? (string) $input['format'] : 'json';

		if ( 'markdown' === $format ) {
			$export = \DUPCAP_Dashboard::instance()->export_report( 'markdown' );

			return array(
				'type'     => 'full',
				'format'   => 'markdown',
				'filename' => $export['filename'],
				'content'  => $export['content'],
			);
		}

		return array(
			'type'   => 'full',
			'format' => 'json',
			'report' => Report_Service::context(),
		);
	}

	/**
	 * The Elementor deep-scan report, as the dashboard's Elementor tab shows it.
	 *
	 * @since 1.1.0
	 * @param int $example_limit Examples kept per bucket.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function elementor_report( $example_limit ) {
		if ( ! class_exists( 'DUPCAP_Elementor_Scanner' ) || ! \DUPCAP_Elementor_Scanner::is_elementor_available() ) {
			return array(
				'type'      => 'elementor',
				'available' => false,
				'message'   => __( 'Elementor is not active on this site, so there is no Elementor report.', 'duplicate-content-addon-for-polylang' ),
			);
		}

		$saved = \DUPCAP_Elementor_Scanner::get_saved_report();
		if ( ! is_array( $saved ) ) {
			return new WP_Error(
				'dupcap_no_elementor_report',
				__( 'The Elementor deep scan has not run yet. Call translation-inspector/run-scan with scope "elementor" (or "all"), then call this again.', 'duplicate-content-addon-for-polylang' ),
				array( 'status' => 409 )
			);
		}

		$insights = \DUPCAP_Elementor_Insights::get_dashboard_insights( $saved );
		$buckets  = array();
		foreach ( (array) ( $insights['buckets'] ?? array() ) as $bucket ) {
			$examples = array();
			foreach ( array_slice( (array) ( $bucket['examples'] ?? array() ), 0, max( 0, $example_limit ) ) as $example ) {
				$examples[] = Issue_Service::present_row( (array) $example, 'elementor:' . ( $bucket['key'] ?? '' ) );
			}

			$buckets[] = array(
				'issue_key' => 'elementor:' . (string) ( $bucket['key'] ?? '' ),
				'title'     => (string) ( $bucket['title'] ?? '' ),
				'severity'  => Report_Service::severity( (string) ( $bucket['severity'] ?? '' ) ),
				'count'     => (int) ( $bucket['count'] ?? 0 ),
				'message'   => (string) ( $bucket['message'] ?? '' ),
				'examples'  => $examples,
			);
		}

		// The scanner scores 0 while Elementor templates cannot be translated at all.
		$templates_blocked = post_type_exists( 'elementor_library' )
			&& function_exists( 'pll_is_translated_post_type' )
			&& ! pll_is_translated_post_type( 'elementor_library' );

		$notes = array(
			__( 'health_score is the score the dashboard shows. summary.health_score and summary.problems_found come from the raw scan, before ignored findings and findings already listed as content issues (such as pages missing a translation) are removed.', 'duplicate-content-addon-for-polylang' ),
		);
		if ( $templates_blocked ) {
			$notes[] = __( 'health_score is 0 because Elementor templates (post type elementor_library) are not enabled for translation in Polylang, so headers, footers and other templates cannot have translations. This is the "untranslated_types" issue in get-issues; enabling it in Languages > Settings > Custom post types fixes it.', 'duplicate-content-addon-for-polylang' );
		}

		if ( ! empty( $buckets ) ) {
			$next = __( 'Call translation-inspector/get-issues with a bucket issue_key for every affected widget, then fix and confirm with recheck-issue.', 'duplicate-content-addon-for-polylang' );
		} elseif ( $templates_blocked ) {
			$next = __( 'No widget-level issues, but Elementor templates cannot be translated yet. Guide the user to enable elementor_library in Languages > Settings > Custom post types, then call run-scan with scope "elementor".', 'duplicate-content-addon-for-polylang' );
		} else {
			$next = __( 'No Elementor issues to fix.', 'duplicate-content-addon-for-polylang' );
		}

		return array(
			'type'                     => 'elementor',
			'available'                => true,
			'last_scan_gmt'            => ! empty( $saved['time'] ) ? gmdate( 'c', (int) $saved['time'] ) : null,
			'health_score'             => (int) ( $insights['health_score'] ?? 0 ),
			'templates_translatable'   => ! $templates_blocked,
			'summary'                  => (array) ( $insights['summary'] ?? array() ),
			'document_types'           => (array) ( $insights['document_types'] ?? array() ),
			'buckets'                  => $buckets,
			'more_types'               => (int) ( $insights['more_types'] ?? 0 ),
			'message'                  => isset( $insights['message'] ) ? (string) $insights['message'] : '',
			'notes'                    => $notes,
			'next_step'                => $next,
		);
	}

	/**
	 * The last live URL test.
	 *
	 * @since 1.1.0
	 * @param string $language Only this language, or ''.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function url_report( $language ) {
		if ( ! class_exists( 'DUPCAP_Routing_Scanner' ) || ! \DUPCAP_Routing_Scanner::is_available() ) {
			return array(
				'type'      => 'urls',
				'available' => false,
				'message'   => __( 'URLs cannot be tested because Polylang is not available.', 'duplicate-content-addon-for-polylang' ),
			);
		}

		$saved = \DUPCAP_Routing_Scanner::get_saved_report();
		$meta  = array(
			'type'                    => 'urls',
			'available'               => true,
			'likely_local_site'       => (bool) \DUPCAP_Routing_Scanner::is_likely_local_site(),
			'rate_limit_seconds_left' => (int) \DUPCAP_Routing_Scanner::rate_limit_remaining(),
		);

		if ( ! is_array( $saved ) ) {
			return $meta + array(
				'has_report' => false,
				'next_step'  => __( 'The URLs have not been tested yet. Call translation-inspector/run-scan with scope "urls".', 'duplicate-content-addon-for-polylang' ),
			);
		}

		$results = \DUPCAP_Routing_Scanner::apply_ignores_to_results( (array) ( $saved['results'] ?? array() ) );
		$checks     = array();
		$issues     = 0;
		$untestable = 0;
		foreach ( $results as $result ) {
			$result = (array) $result;
			$rows   = array();
			foreach ( (array) ( $result['details'] ?? array() ) as $detail ) {
				$detail = (array) $detail;
				if ( '' !== $language && ! Issue_Service::mentions_language( $detail, $language ) ) {
					continue;
				}
				$row            = Issue_Service::present_row( $detail, 'routing_health' );
				$row['problem'] = (bool) \DUPCAP_Routing_Scanner::detail_is_problem( $detail );
				$issues        += $row['problem'] ? 1 : 0;
				$untestable    += ( $row['problem'] && 'untestable' === ( $detail['verdict'] ?? '' ) ) ? 1 : 0;
				$rows[]         = $row;
			}

			if ( '' !== $language && empty( $rows ) ) {
				continue;
			}

			$checks[] = array(
				'title'    => (string) ( $result['label'] ?? $result['title'] ?? '' ),
				'severity' => Report_Service::severity( (string) ( $result['status'] ?? $result['severity'] ?? 'info' ) ),
				'message'  => (string) ( $result['message'] ?? '' ),
				'urls'     => $rows,
			);
		}

		if ( 0 === $issues ) {
			$next = __( 'No problem URLs in the last test.', 'duplicate-content-addon-for-polylang' );
		} elseif ( $untestable === $issues ) {
			$next = __( 'None of these URLs is known to be broken: the server could not test them (verdict "untestable"), which is normal on local or firewalled sites. Ask the user to open each url in a browser. If they load, ignore the rows with translation-inspector/ignore-issues; on live hosting, re-test later with run-scan scope "urls".', 'duplicate-content-addon-for-polylang' );
		} else {
			$next = __( 'Explain each problem URL to the user, using its message. 404s and wrong-language pages are usually fixed in Languages > Settings (URL modifications) or by saving Settings > Permalinks. Rows with verdict "untestable" were not reached from the server and may load fine in a browser. Then re-test with run-scan scope "urls".', 'duplicate-content-addon-for-polylang' );
		}

		return $meta + array(
			'has_report'      => true,
			'tested_gmt'      => ! empty( $saved['time'] ) ? gmdate( 'c', (int) $saved['time'] ) : null,
			'fresh'           => (bool) \DUPCAP_Routing_Scanner::has_fresh_report(),
			'problem_urls'    => $issues,
			'untestable_urls' => $untestable,
			'checks'          => $checks,
			'next_step'       => $next,
		);
	}
}
