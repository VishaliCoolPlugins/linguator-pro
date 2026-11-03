<?php
/**
 * Get Report ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Reports;

use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Inspector_Guard;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Issue_Service;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Report_Service;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Report.
 *
 * @since 1.2.0
 */
final class Get_Report extends Abstract_Ability {

	/**
	 * Report types.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const TYPES = array( 'full', 'elementor', 'urls' );

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-report';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Report', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator Site Scan: get a saved report. type "full" (default) is the whole Site Scan report, in Markdown to share with a client or support, or JSON (health score, per-check results with their rows, coverage per language, ignored items). type "elementor" is the Elementor part of the scan: the builder health score, which template types were scanned and the problem buckets, such as untranslated widgets, empty translated layouts, translations not linked to an original and repeater mismatches, each with examples and the template to edit. type "urls" is the last live URL test: per language, whether the home page and sample URLs load, and which fail, and when the test ran. Says when a report does not exist yet and which run-scan scope creates it.', 'linguator-pro' );
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
				'type'          => array(
					'type'        => 'string',
					'enum'        => self::TYPES,
					'default'     => 'full',
					'description' => __( 'Which report to return.', 'linguator-pro' ),
				),
				'format'        => array(
					'type'        => 'string',
					'enum'        => array( 'json', 'markdown' ),
					'default'     => 'json',
					'description' => __( 'For type "full": JSON for structured data, Markdown for a human-readable summary. The other types are always JSON.', 'linguator-pro' ),
				),
				'language'      => array(
					'type'        => 'string',
					'description' => __( 'For type "urls": only this language slug, for example "fr".', 'linguator-pro' ),
				),
				'example_limit' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => 50,
					'default'     => 5,
					'description' => __( 'For type "elementor": maximum examples per problem bucket; 0 for counts only.', 'linguator-pro' ),
				),
			),
		);
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

		$type = isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : 'full';

		if ( 'elementor' === $type ) {
			return self::elementor_report( min( 50, max( 0, isset( $input['example_limit'] ) ? (int) $input['example_limit'] : 5 ) ) );
		}

		if ( 'urls' === $type ) {
			return self::url_report( isset( $input['language'] ) ? sanitize_key( (string) $input['language'] ) : '' );
		}

		$error = Inspector_Guard::require_saved_scan();
		if ( null !== $error ) {
			return $error;
		}

		$format = isset( $input['format'] ) ? sanitize_key( (string) $input['format'] ) : 'json';

		if ( 'markdown' === $format ) {
			return array(
				'type'     => 'full',
				'format'   => 'markdown',
				'filename' => 'linguator-site-scan-' . gmdate( 'Y-m-d' ) . '.md',
				'content'  => self::markdown(),
			);
		}

		$context = Report_Service::context();

		return array(
			'type'   => 'full',
			'format' => 'json',
			'report' => array(
				'time_gmt'      => Report_Service::iso( (int) $context['time'] ),
				'health'        => $context['health'],
				'summary'       => $context['summary'],
				'coverage'      => array_values( (array) $context['coverage'] ),
				'results'       => $context['results'],
				'ignored_items' => $context['ignored_items'],
				'elementor'     => $context['elementor'],
			),
		);
	}

	/**
	 * The report as Markdown, in the layout of the Site Scan export.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	private static function markdown() {
		$context = Report_Service::context();
		$lines   = array(
			'# ' . __( 'Linguator site scan', 'linguator-pro' ),
			'',
			sprintf(
				/* translators: 1: score, 2: scan date */
				__( 'Health score: %1$d/100 — scanned %2$s', 'linguator-pro' ),
				(int) ( $context['health']['score'] ?? 0 ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $context['time'] )
			),
			'',
		);

		foreach ( (array) $context['results'] as $result ) {
			if ( 'pass' === ( $result['status'] ?? 'pass' ) ) {
				continue;
			}

			$lines[] = sprintf( '## [%s] %s', strtoupper( (string) $result['status'] ), wp_strip_all_tags( (string) $result['label'] ) );
			$lines[] = wp_strip_all_tags( (string) $result['message'] );
			foreach ( (array) ( $result['details'] ?? array() ) as $row ) {
				$lines[] = sprintf( '- %s — %s', wp_strip_all_tags( (string) ( $row['label'] ?? '' ) ), wp_strip_all_tags( (string) ( $row['message'] ?? '' ) ) );
			}
			if ( ! empty( $result['details_truncated'] ) ) {
				$lines[] = '- …';
			}
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * The Elementor part of the saved scan.
	 *
	 * @since 1.2.0
	 * @param int $example_limit Examples per bucket.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function elementor_report( $example_limit ) {
		$check = '\LinguatorPro\Modules\Scan\Checks\Elementor';

		if ( ! class_exists( $check ) || ! $check::available() ) {
			return array(
				'type'      => 'elementor',
				'available' => false,
				'message'   => __( 'Elementor is not active on this site, so there is no Elementor report.', 'linguator-pro' ),
			);
		}

		$context = Report_Service::context();
		$results = array_values(
			array_filter(
				(array) $context['results'],
				static function ( $result ) {
					return 'elementor' === ( $result['section'] ?? '' );
				}
			)
		);

		if ( ! $context['has_scan'] || empty( $results ) ) {
			return new WP_Error(
				'lmat_no_elementor_report',
				__( 'The Elementor part of the scan has not run yet. Call linguator-inspector/run-scan with scope "elementor" (or "all"), then call this again.', 'linguator-pro' ),
				array( 'status' => 409 )
			);
		}

		$buckets = array();
		foreach ( $results as $result ) {
			if ( 'pass' === $result['status'] ) {
				continue;
			}

			$rows     = array_values( array_filter( (array) ( $result['details'] ?? array() ), 'is_array' ) );
			$examples = array();
			foreach ( array_slice( $rows, 0, $example_limit ) as $row ) {
				$examples[] = Issue_Service::present_row( $row, Issue_Service::type_for( (string) $result['id'] ) );
			}

			$buckets[] = array(
				'issue_key' => (string) $result['id'],
				'title'     => wp_strip_all_tags( (string) $result['label'] ),
				'severity'  => Report_Service::severity( (string) $result['status'] ),
				'count'     => count( $rows ),
				'message'   => wp_strip_all_tags( (string) $result['message'] ),
				'examples'  => $examples,
			);
		}

		// Elementor templates that Linguator does not translate cannot have translations.
		$templates_blocked = post_type_exists( 'elementor_library' ) && ! linguator_is_translated_post_type( 'elementor_library' );

		$notes = array(
			__( 'health_score is the builder score of the Site Scan; ignored findings are already removed from the buckets.', 'linguator-pro' ),
		);
		if ( $templates_blocked ) {
			$notes[] = __( 'Elementor templates (post type elementor_library) are not enabled for translation in Linguator, so headers, footers and other templates cannot have translations. Enabling it in the Linguator settings fixes that.', 'linguator-pro' );
		}

		if ( ! empty( $buckets ) ) {
			$next = __( 'Call linguator-inspector/get-issues with a bucket issue_key for every affected widget. Elementor layouts are translated in the Elementor editor; confirm each fix with recheck-issue.', 'linguator-pro' );
		} elseif ( $templates_blocked ) {
			$next = __( 'No widget-level issues, but Elementor templates cannot be translated yet. Guide the user to enable elementor_library in the Linguator settings, then call run-scan with scope "elementor".', 'linguator-pro' );
		} else {
			$next = __( 'No Elementor issues to fix.', 'linguator-pro' );
		}

		$summary = (array) $context['elementor'];

		return array(
			'type'                   => 'elementor',
			'available'              => true,
			'last_scan_gmt'          => Report_Service::iso( (int) ( $context['partial_scans']['elementor'] ?? $context['time'] ) ),
			'health_score'           => isset( $context['health']['builder'] ) ? (int) $context['health']['builder'] : null,
			'templates_translatable' => ! $templates_blocked,
			'summary'                => $summary,
			'document_types'         => (array) ( $summary['types'] ?? array() ),
			'buckets'                => $buckets,
			'notes'                  => $notes,
			'next_step'              => $next,
		);
	}

	/**
	 * The last live URL test.
	 *
	 * @since 1.2.0
	 * @param string $language Language filter, or ''.
	 * @return array<string, mixed>
	 */
	private static function url_report( $language ) {
		$context   = Report_Service::context();
		$languages = array_keys( \CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Content_Service::languages() );

		if ( count( $languages ) < 2 ) {
			return array(
				'type'      => 'urls',
				'available' => false,
				'message'   => __( 'URLs cannot be tested because fewer than two languages are configured in Linguator.', 'linguator-pro' ),
			);
		}

		$tested = (int) ( $context['partial_scans']['urls'] ?? 0 );
		$meta   = array(
			'type'      => 'urls',
			'available' => true,
		);

		$routes = array_values(
			array_filter(
				(array) $context['results'],
				static function ( $result ) {
					return 0 === strpos( (string) ( $result['id'] ?? '' ), 'url_route_' );
				}
			)
		);

		if ( ! $context['has_scan'] || empty( $routes ) ) {
			return $meta + array(
				'has_report' => false,
				'next_step'  => __( 'The URLs have not been tested yet. Call linguator-inspector/run-scan with scope "urls".', 'linguator-pro' ),
			);
		}

		$checks     = array();
		$issues     = 0;
		$untestable = 0;

		foreach ( $routes as $result ) {
			$id     = (string) $result['id'];
			$rows   = array();
			$status = (string) $result['status'];

			foreach ( (array) ( $result['details'] ?? array() ) as $detail ) {
				$detail = (array) $detail;
				if ( '' !== $language && ! Issue_Service::mentions_language( $detail, $language ) ) {
					continue;
				}
				$row            = Issue_Service::present_row( $detail, 'routing_health' );
				$row['problem'] = true;
				++$issues;
				$rows[] = $row;
			}

			// An info result means the server could not test the URLs at all.
			if ( 'info' === $status ) {
				++$untestable;
			}

			if ( '' !== $language && empty( $rows ) && $id !== 'url_route_' . $language ) {
				continue;
			}

			$checks[] = array(
				'issue_key' => $id,
				'title'     => wp_strip_all_tags( (string) ( $result['label'] ?? '' ) ),
				'severity'  => Report_Service::severity( $status ),
				'message'   => wp_strip_all_tags( (string) ( $result['message'] ?? '' ) ),
				'urls'      => $rows,
			);
		}

		if ( $untestable > 0 && 0 === $issues ) {
			$next = __( 'The server could not test the URLs (normal on local, firewalled or closed sites). Ask the user to open each language home page in a browser. If they load, nothing is wrong; on live hosting, re-test later with run-scan scope "urls".', 'linguator-pro' );
		} elseif ( 0 === $issues ) {
			$next = __( 'No problem URLs in the last test.', 'linguator-pro' );
		} else {
			$next = __( 'Explain each problem URL to the user, using its message. 404s and wrong-language pages are usually fixed by saving Settings > Permalinks, or in the Linguator URL settings. If a URL loads in a browser, the server could not reach it: ignore the row with linguator-inspector/ignore-issues. Then re-test with run-scan scope "urls".', 'linguator-pro' );
		}

		return $meta + array(
			'has_report'      => true,
			'tested_gmt'      => Report_Service::iso( $tested ),
			'fresh'           => $tested > 0 && ( time() - $tested ) < DAY_IN_SECONDS,
			'problem_urls'    => $issues,
			'untestable_urls' => $untestable,
			'checks'          => $checks,
			'next_step'       => $next,
		);
	}
}
