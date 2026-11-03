<?php
/**
 * Recheck Issue ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Inspector\Abilities\Scans;

use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Inspector_Guard;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Issue_Service;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Report_Service;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Re-checks one issue, one row of it, or one post after a fix, and updates the
 * saved report without a full scan.
 *
 * @since 1.1.0
 */
final class Recheck_Issue extends Abstract_Ability {

	/**
	 * Most rows rechecked one by one in a call; beyond this, rescan the section.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const MAX_ROWS = 25;

	/**
	 * Issue sections run-scan can refresh on their own.
	 *
	 * @since 1.1.0
	 * @var array<string, string>
	 */
	const SECTION_SCANS = array(
		'content'   => 'content',
		'elementor' => 'elementor',
		'site'      => 'site',
	);

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'recheck-issue';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Recheck Issue', 'duplicate-content-addon-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Translation Inspector: confirm a fix. Re-checks one issue (issue_key), one row inside it (ignore_key), or everything recorded for one post or Elementor document (post_id), and updates the saved report without a full scan. Returns, per item, whether it is resolved or still present and why. Call it after each fix from get-fix-plan, fix-issue or an autopoly/* tool. Pass issue_key or post_id.', 'duplicate-content-addon-for-polylang' );
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
				'issue_key' => array(
					'type'        => 'string',
					'description' => __( 'The issue_key returned by get-issues.', 'duplicate-content-addon-for-polylang' ),
				),
				'ignore_key' => array(
					'type'        => 'string',
					'description' => __( 'With issue_key: re-check only this row of the issue, by its ignore_key.', 'duplicate-content-addon-for-polylang' ),
				),
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Instead of issue_key: re-check every issue recorded for this post, page or Elementor template.', 'duplicate-content-addon-for-polylang' ),
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
	 * Each row goes to the dashboard's own recheck for its kind (the ignore key
	 * prefix says which). A row with no single-row recheck, or an issue with
	 * more rows than MAX_ROWS, is confirmed by re-scanning its section.
	 *
	 * @since 1.1.0
	 * @param mixed $input Validated input, or null.
	 * @return array<string, mixed>|WP_Error Result per row.
	 */
	public static function execute( $input = array() ) {
		$input = self::input_array( $input );

		$error = Inspector_Guard::require_saved_scan();
		if ( null !== $error ) {
			return $error;
		}

		$ignore_key = isset( $input['ignore_key'] ) ? \DUPCAP_Ignored_Issues::sanitize_key( (string) $input['ignore_key'] ) : '';
		$issue_key  = isset( $input['issue_key'] ) ? (string) $input['issue_key'] : '';
		$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		if ( '' !== $ignore_key ) {
			$found   = Issue_Service::find_row( $ignore_key );
			$targets = array(
				array(
					'key'     => $ignore_key,
					'row'     => $found ? $found['row'] : array(),
					'section' => $found ? $found['issue']['section'] : self::section_for_key( $ignore_key ),
				),
			);
		} elseif ( '' !== $issue_key ) {
			$issue = Issue_Service::find( $issue_key );
			if ( null === $issue ) {
				return array(
					'checked'   => 0,
					'resolved'  => true,
					'results'   => array(),
					'message'   => __( 'This issue is not in the current report, so it is already resolved or ignored.', 'duplicate-content-addon-for-polylang' ),
					'next_step' => __( 'Call translation-inspector/get-issues to see what is left.', 'duplicate-content-addon-for-polylang' ),
				);
			}
			$targets = self::targets_for_rows( $issue['rows'], $issue['section'] );
			if ( empty( $targets ) ) {
				return self::recheck_by_section( $issue['section'], $issue_key );
			}
		} elseif ( $post_id > 0 ) {
			$targets = self::targets_for_post( $post_id );
			if ( empty( $targets ) ) {
				return array(
					'checked'   => 0,
					'resolved'  => true,
					'results'   => array(),
					'message'   => __( 'The current report records no issue for this post or its translations.', 'duplicate-content-addon-for-polylang' ),
					'next_step' => __( 'Nothing to recheck. To look at the post itself, call translation-inspector/inspect-content.', 'duplicate-content-addon-for-polylang' ),
				);
			}
		} else {
			return new WP_Error(
				'dupcap_recheck_target_missing',
				__( 'Say what to recheck: pass issue_key (a whole issue), ignore_key (one row) or post_id (everything for one post). Take them from translation-inspector/get-issues.', 'duplicate-content-addon-for-polylang' ),
				array( 'status' => 400 )
			);
		}

		// Rows can share one key (a string row per language): check each key once.
		$unique = array();
		foreach ( $targets as $target ) {
			$unique[ $target['key'] ] = $target;
		}
		$targets = array_values( $unique );

		// Too many rows to check one by one: re-scan the section instead.
		if ( count( $targets ) > self::MAX_ROWS ) {
			$sections = array_values( array_unique( array_column( $targets, 'section' ) ) );
			if ( '' === $ignore_key && '' !== $issue_key && 1 === count( $sections ) ) {
				return self::recheck_by_section( $sections[0], $issue_key );
			}
			$targets = array_slice( $targets, 0, self::MAX_ROWS );
		}

		$results  = array();
		$sections = array();
		foreach ( $targets as $target ) {
			$outcome = self::recheck_row( $target['key'], $target['row'] );

			if ( null === $outcome ) {
				$sections[ $target['section'] ][] = $target['key'];
				continue;
			}

			$results[] = is_wp_error( $outcome )
				? array(
					'ignore_key' => $target['key'],
					'resolved'   => false,
					'error'      => $outcome->get_error_message(),
				)
				: array(
					'ignore_key' => $target['key'],
					'resolved'   => ! empty( $outcome['resolved'] ),
					'message'    => (string) $outcome['message'],
				);
		}

		// Rows with no single-row recheck: rescan their section, then look again.
		$rescanned = array();
		foreach ( $sections as $section => $keys ) {
			$scan = self::scan_section( (string) $section );
			if ( is_wp_error( $scan ) ) {
				foreach ( $keys as $key ) {
					$results[] = array(
						'ignore_key' => $key,
						'resolved'   => false,
						'error'      => $scan->get_error_message(),
					);
				}
				continue;
			}

			$rescanned[] = (string) $section;
			Report_Service::forget_context();
			$known = Issue_Service::known_keys();
			foreach ( $keys as $key ) {
				$results[] = array(
					'ignore_key' => $key,
					'resolved'   => ! isset( $known[ $key ] ),
					'message'    => __( 'Checked by re-scanning its section.', 'duplicate-content-addon-for-polylang' ),
				);
			}
		}

		Report_Service::forget_context();

		$resolved = count( array_filter( array_column( $results, 'resolved' ) ) );
		$all_done = count( $results ) > 0 && count( $results ) === $resolved;

		return array(
			'checked'            => count( $results ),
			'resolved_count'     => $resolved,
			'still_present'      => count( $results ) - $resolved,
			'resolved'           => $all_done,
			'sections_rescanned' => $rescanned,
			'results'            => $results,
			'next_step'          => $all_done
				? __( 'Fixed. Move on to the next step of translation-inspector/get-fix-plan.', 'duplicate-content-addon-for-polylang' )
				: __( 'Some rows are still present or could not be checked. Get their details with translation-inspector/get-issues (issue_key) and try the fix again, or ask the user.', 'duplicate-content-addon-for-polylang' ),
		);
	}

	/**
	 * Recheck targets for a list of rows: one per ignore key.
	 *
	 * @since 1.1.0
	 * @param array<int, array<string, mixed>> $rows    Raw rows.
	 * @param string                           $section Their issue's section.
	 * @return array<int, array{key: string, row: array<string, mixed>, section: string}>
	 */
	private static function targets_for_rows( array $rows, $section ) {
		$targets = array();

		foreach ( $rows as $row ) {
			foreach ( Issue_Service::row_keys( $row ) as $key ) {
				$targets[] = array(
					'key'     => $key,
					'row'     => $row,
					'section' => (string) $section,
				);
			}
		}

		return $targets;
	}

	/**
	 * Recheck targets for every row about a post or its translations.
	 *
	 * @since 1.1.0
	 * @param int $post_id Post ID in any language.
	 * @return array<int, array{key: string, row: array<string, mixed>, section: string}>
	 */
	private static function targets_for_post( $post_id ) {
		$ids = array( (int) $post_id );
		if ( function_exists( 'pll_get_post_translations' ) ) {
			$ids = array_merge( $ids, array_map( 'intval', (array) pll_get_post_translations( (int) $post_id ) ) );
		}
		$ids = array_unique( $ids );

		$targets = array();
		foreach ( Issue_Service::all() as $issue ) {
			foreach ( $issue['rows'] as $row ) {
				$objects = Issue_Service::row_objects( $row );
				if ( array_intersect( $ids, $objects['posts'] ) ) {
					$targets = array_merge( $targets, self::targets_for_rows( array( $row ), $issue['section'] ) );
				}
			}
		}

		return $targets;
	}

	/**
	 * Recheck one row with the dashboard's recheck for its kind.
	 *
	 * Key formats are defined by DUPCAP_Ignored_Issues.
	 *
	 * @since 1.1.0
	 * @param string               $key Ignore key.
	 * @param array<string, mixed> $row Raw row, or empty when the key is not in the report.
	 * @return array<string, mixed>|WP_Error|null Outcome, or null when the kind
	 *                                           has no single-row recheck.
	 */
	private static function recheck_row( $key, array $row ) {
		$dashboard = \DUPCAP_Dashboard::instance();
		$parts     = explode( ':', $key );

		if ( 'el' === $parts[0] ) {
			$template_id = ! empty( $row['template_id'] ) ? (int) $row['template_id'] : (int) ( $parts[2] ?? 0 );
			$type        = (string) ( $row['template_type'] ?? $row['type'] ?? '' );
			return $dashboard->recheck_elementor_issue( $template_id, $type, $key );
		}

		if ( 'content_missing' === $parts[0] || 'content_draft' === $parts[0] ) {
			return $dashboard->recheck_content_issue(
				array(
					'kind'     => 'content',
					'post_id'  => (int) ( $parts[1] ?? 0 ),
					'language' => (string) ( $parts[2] ?? '' ),
					'gap_type' => 'content_draft' === $parts[0] ? 'draft' : 'missing',
				)
			);
		}

		if ( 'content' === $parts[0] && 'source_updated' === ( $parts[1] ?? '' ) ) {
			return $dashboard->recheck_content_issue(
				array(
					'kind'     => 'content',
					'post_id'  => (int) ( $parts[2] ?? 0 ),
					'language' => (string) ( $parts[3] ?? '' ),
					'gap_type' => 'source_updated',
				)
			);
		}

		if ( 'content' === $parts[0] && in_array( $parts[1] ?? '', array( 'identical', 'link' ), true ) ) {
			return $dashboard->recheck_content_issue(
				array(
					'kind'           => $parts[1],
					'ignore_key'     => $key,
					'translation_id' => (int) ( $row['translation_id'] ?? 0 ),
					'field'          => (string) ( $row['field'] ?? $parts[4] ?? '' ),
				)
			);
		}

		if ( 'menu' === $parts[0] && 'dead' === ( $parts[1] ?? '' ) ) {
			return $dashboard->recheck_content_issue(
				array(
					'kind'       => 'menu_dead',
					'ignore_key' => $key,
					'url'        => (string) ( $row['url'] ?? '' ),
				)
			);
		}

		if ( 'url' === $parts[0] && in_array( $parts[1] ?? '', array( 'front_page_missing', 'front_page_empty_layout' ), true ) ) {
			return $dashboard->recheck_content_issue(
				array(
					'kind'     => 'homepage',
					'language' => (string) ( $parts[2] ?? '' ),
					'gap_type' => 'front_page_empty_layout' === $parts[1] ? 'empty_layout' : 'missing',
				)
			);
		}

		return null;
	}

	/**
	 * Section of an ignore key that is not in the report.
	 *
	 * @since 1.1.0
	 * @param string $key Ignore key.
	 * @return string content, elementor, urls or site.
	 */
	private static function section_for_key( $key ) {
		$section = \DUPCAP_Ignored_Issues::get_section_for_key( $key );

		return '' === $section ? 'site' : Report_Service::section( $section );
	}

	/**
	 * Confirm a whole issue by re-scanning its section.
	 *
	 * @since 1.1.0
	 * @param string $section   Issue section.
	 * @param string $issue_key Issue being rechecked.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function recheck_by_section( $section, $issue_key ) {
		$scan = self::scan_section( $section );
		if ( is_wp_error( $scan ) ) {
			return $scan;
		}

		Report_Service::forget_context();
		$still = Issue_Service::find( $issue_key );

		return array(
			'checked'            => 1,
			'resolved'           => null === $still,
			'sections_rescanned' => array( $section ),
			'results'            => array(),
			'remaining_count'    => null === $still ? 0 : (int) $still['count'],
			'message'            => null === $still
				? __( 'The issue is gone after re-scanning its section.', 'duplicate-content-addon-for-polylang' )
				: __( 'The issue is still present after re-scanning its section.', 'duplicate-content-addon-for-polylang' ),
			'next_step'          => null === $still
				? __( 'Fixed. Move on to the next step of translation-inspector/get-fix-plan.', 'duplicate-content-addon-for-polylang' )
				: __( 'Call translation-inspector/get-issues with the issue_key to see which rows remain.', 'duplicate-content-addon-for-polylang' ),
		);
	}

	/**
	 * Re-scan one report section.
	 *
	 * @since 1.1.0
	 * @param string $section Issue section: content, elementor, urls or site.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function scan_section( $section ) {
		if ( ! isset( self::SECTION_SCANS[ $section ] ) ) {
			return new WP_Error(
				'dupcap_recheck_needs_url_test',
				__( 'URL issues are confirmed by testing the live URLs, which is rate limited. Call translation-inspector/run-scan with scope "urls" when the user is ready.', 'duplicate-content-addon-for-polylang' ),
				array( 'status' => 409 )
			);
		}

		return \DUPCAP_Dashboard::instance()->run_section_scan( self::SECTION_SCANS[ $section ] );
	}
}
