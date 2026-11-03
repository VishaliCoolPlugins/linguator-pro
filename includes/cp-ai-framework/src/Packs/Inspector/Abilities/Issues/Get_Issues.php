<?php
/**
 * Get Issues ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Inspector\Abilities\Issues;

use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Inspector_Guard;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Issue_Service;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Report_Service;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the problems in the last saved scan, or every row of one of them.
 *
 * Each issue carries its own explanation (what it means, how it is fixed), so
 * there is no separate issue-type catalogue; include_ignored covers the list
 * of ignored issues.
 *
 * @since 1.1.0
 */
final class Get_Issues extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-issues';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Issues', 'duplicate-content-addon-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Translation Inspector: list the problems found by the last saved scan, grouped the way the dashboard shows them, most urgent first. Each issue has an issue_key, its section and severity, what it means, how it is fixed, the affected languages and a count of affected items. Pass issue_key to get every affected row instead (post, term, menu, string, URL or Elementor widget, each with its ignore_key and edit link), plus step-by-step fix instructions. Filter by section, severity, issue_type or language. Ignored issues and passed checks are left out unless include_ignored or include_passed is set. Returns an error telling you to call run-scan when the site has never been scanned.', 'duplicate-content-addon-for-polylang' );
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
			'properties' => array_merge(
				array(
					'issue_key' => array(
						'type'        => 'string',
						'description' => __( 'Return every row of this one issue, from a previous get-issues call, instead of the list.', 'duplicate-content-addon-for-polylang' ),
					),
					'section' => array(
						'type'        => 'string',
						'enum'        => self::SECTIONS,
						'description' => __( 'Only issues in this dashboard section.', 'duplicate-content-addon-for-polylang' ),
					),
					'severity' => array(
						'type'        => 'string',
						'enum'        => self::SEVERITIES,
						'description' => __( 'Only issues at this severity.', 'duplicate-content-addon-for-polylang' ),
					),
					'issue_type' => array(
						'type'        => 'string',
						'description' => __( 'Only this issue type, as returned in a previous get-issues call.', 'duplicate-content-addon-for-polylang' ),
					),
					'language' => array(
						'type'        => 'string',
						'description' => __( 'Language slug, for example "fr". Omit for all languages.', 'duplicate-content-addon-for-polylang' ),
					),
					'include_ignored' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Also return issues the user chose to ignore, marked as ignored.', 'duplicate-content-addon-for-polylang' ),
					),
					'include_passed' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Also return checks that passed.', 'duplicate-content-addon-for-polylang' ),
					),
				),
				$this->pagination_schema( 20 )
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Reads the dashboard's own issue groups (Issue_Service), so the list, its
	 * order and its counts match what the dashboard shows. Fix links are
	 * already signed for the current user by the dashboard adapter.
	 *
	 * @since 1.1.0
	 * @param mixed $input Validated input, or null.
	 * @return array<string, mixed>|WP_Error Issue list, or one issue's rows.
	 */
	public static function execute( $input = array() ) {
		$input = self::input_array( $input );

		$error = Inspector_Guard::require_saved_scan();
		if ( null !== $error ) {
			return $error;
		}

		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );
		$language = isset( $input['language'] ) ? sanitize_key( (string) $input['language'] ) : '';

		if ( ! empty( $input['issue_key'] ) ) {
			return self::one_issue( (string) $input['issue_key'], $language, $page, $per_page );
		}

		$section    = isset( $input['section'] ) ? (string) $input['section'] : '';
		$severity   = isset( $input['severity'] ) ? (string) $input['severity'] : '';
		$issue_type = isset( $input['issue_type'] ) ? (string) $input['issue_type'] : '';

		$matches = array();
		foreach ( Issue_Service::all() as $issue ) {
			if ( ( '' !== $section && $section !== $issue['section'] )
				|| ( '' !== $severity && $severity !== $issue['severity'] )
				|| ( '' !== $issue_type && $issue_type !== $issue['type'] ) ) {
				continue;
			}

			if ( '' !== $language ) {
				$rows = array_values(
					array_filter(
						$issue['rows'],
						static function ( $row ) use ( $language ) {
							return Issue_Service::mentions_language( $row, $language );
						}
					)
				);

				if ( empty( $rows ) && ! Issue_Service::mentions_language( $issue, $language ) ) {
					continue;
				}

				if ( ! empty( $rows ) ) {
					$issue['rows'] = $rows;
				}
			}

			$matches[] = Issue_Service::summarize( $issue );
		}

		if ( ! empty( $input['include_passed'] ) && ( '' === $severity || 'pass' === $severity ) ) {
			foreach ( Issue_Service::passed() as $check ) {
				if ( '' === $section || $section === $check['section'] ) {
					$matches[] = $check;
				}
			}
		}

		$total  = count( $matches );
		$result = array(
			'scan'     => self::scan_meta(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
			'issues'   => array_slice( $matches, ( $page - 1 ) * $per_page, $per_page ),
		);

		if ( ! empty( $input['include_ignored'] ) ) {
			$ignored = Issue_Service::ignored();
			if ( '' !== $section ) {
				$ignored = array_values(
					array_filter(
						$ignored,
						static function ( $item ) use ( $section ) {
							return $section === $item['section'];
						}
					)
				);
			}
			$result['ignored'] = $ignored;
		}

		$result['next_step'] = 0 === $total
			? __( 'Nothing matches. Remove filters, or call translation-inspector/run-scan if the report is old.', 'duplicate-content-addon-for-polylang' )
			: __( 'Pass an issue_key to get every affected row. Follow each issue\'s fix; after fixing, call translation-inspector/recheck-issue.', 'duplicate-content-addon-for-polylang' );

		return $result;
	}

	/**
	 * Every row of one issue.
	 *
	 * @since 1.1.0
	 * @param string $issue_key Issue key.
	 * @param string $language  Language filter, or ''.
	 * @param int    $page      Page number.
	 * @param int    $per_page  Rows per page.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function one_issue( $issue_key, $language, $page, $per_page ) {
		$issue = Issue_Service::find( $issue_key );

		if ( null === $issue ) {
			return new WP_Error(
				'dupcap_issue_not_found',
				sprintf(
					/* translators: %s: issue key. */
					__( 'No open issue has the key "%s". It may be fixed, ignored, or the key may be wrong. Call translation-inspector/get-issues without issue_key to see the current keys.', 'duplicate-content-addon-for-polylang' ),
					$issue_key
				),
				array( 'status' => 404 )
			);
		}

		$rows = $issue['rows'];
		if ( '' !== $language ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $language ) {
						return Issue_Service::mentions_language( $row, $language );
					}
				)
			);
		}

		$total     = count( $rows );
		$presented = array();
		foreach ( array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ) as $row ) {
			$presented[] = Issue_Service::present_row( $row, $issue['type'] );
		}

		return array(
			'scan'           => self::scan_meta(),
			'issue'          => Issue_Service::summarize( $issue, 0 ),
			'total'          => $total,
			'page'           => $page,
			'per_page'       => $per_page,
			'pages'          => (int) ceil( $total / $per_page ),
			'rows'           => $presented,
			'rows_truncated' => $issue['rows_truncated'],
			'next_step'      => __( 'Fix rows with their fix.input where given, otherwise follow issue.fix. Then call translation-inspector/recheck-issue with the ignore_key (one row) or issue_key (all rows).', 'duplicate-content-addon-for-polylang' ),
		);
	}

	/**
	 * When the report was made, and its health score.
	 *
	 * @since 1.1.0
	 * @return array<string, mixed>
	 */
	private static function scan_meta() {
		$scan    = Report_Service::scan_summary();
		$context = Report_Service::context();

		return array(
			'last_scan_gmt' => $scan['last_scan_gmt'],
			'partial_scans' => $scan['partial_scans'],
			'health_score'  => (int) ( $context['health_score'] ?? 0 ),
		);
	}
}
