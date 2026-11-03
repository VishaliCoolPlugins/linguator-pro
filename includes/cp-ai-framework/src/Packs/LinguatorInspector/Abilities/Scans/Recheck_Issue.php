<?php
/**
 * Recheck Issue ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Scans;

use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Inspector_Guard;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Issue_Service;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Report_Service;
use LinguatorPro\Modules\Scan\Scanner;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recheck Issue.
 *
 * @since 1.2.0
 */
final class Recheck_Issue extends Abstract_Ability {

	/**
	 * Most rows re-checked one by one; a larger issue re-scans its result instead.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MAX_ROWS = 25;

	/**
	 * Issues Linguator computes live rather than reading from the saved report.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const LIVE_ISSUES = array( 'content_nolang', 'lang_untranslated_types' );

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'recheck-issue';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Recheck Issue', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator Site Scan: confirm a fix. Re-checks one issue (issue_key), one row inside it (ignore_key), or everything recorded for one post or Elementor document (post_id), and updates the saved report without a full scan. Returns, per item, whether it is resolved or still present. Call it after each fix from get-fix-plan, fix-issue or a linguator/* tool. Pass issue_key, ignore_key or post_id. Needs an active Linguator Pro license, like a scan.', 'linguator-pro' );
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
				'issue_key'  => array(
					'type'        => 'string',
					'description' => __( 'The issue_key returned by get-issues.', 'linguator-pro' ),
				),
				'ignore_key' => array(
					'type'        => 'string',
					'description' => __( 'Re-check only this row, by its ignore_key (also give issue_key to name its issue).', 'linguator-pro' ),
				),
				'post_id'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Instead of issue_key: re-check every issue recorded for this post, page, product or Elementor template.', 'linguator-pro' ),
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

		$error = Inspector_Guard::require_saved_scan();
		if ( null !== $error ) {
			return $error;
		}

		$ignore_key = isset( $input['ignore_key'] ) ? sanitize_text_field( (string) $input['ignore_key'] ) : '';
		$issue_key  = isset( $input['issue_key'] ) ? sanitize_text_field( (string) $input['issue_key'] ) : '';
		$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		if ( '' === $ignore_key && '' === $issue_key && $post_id <= 0 ) {
			return new WP_Error(
				'lmat_recheck_target_missing',
				__( 'Say what to recheck: pass issue_key (a whole issue), ignore_key (one row) or post_id (everything for one post). Take them from linguator-inspector/get-issues.', 'linguator-pro' ),
				array( 'status' => 400 )
			);
		}

		// Issues computed live need no scan: look again and report.
		if ( '' === $ignore_key && in_array( $issue_key, self::LIVE_ISSUES, true ) ) {
			Report_Service::forget_context();
			$still = Issue_Service::find( $issue_key );

			return array(
				'checked'   => 1,
				'resolved'  => null === $still,
				'results'   => array(),
				'message'   => null === $still
					? __( 'The issue is gone.', 'linguator-pro' )
					: __( 'The issue is still present.', 'linguator-pro' ),
				'next_step' => null === $still
					? __( 'Fixed. Run linguator-inspector/run-scan if the report is old, or move on to the next step of get-fix-plan.', 'linguator-pro' )
					: __( 'Fix it (see get-fix-plan) and recheck.', 'linguator-pro' ),
			);
		}

		$blocked = Inspector_Guard::scan_blocked();
		if ( null !== $blocked ) {
			return $blocked;
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		// Group the keys to check by section.
		$by_section = array();
		$issue      = null;

		if ( '' !== $ignore_key ) {
			$found = Issue_Service::find_row( $ignore_key );
			if ( null === $found ) {
				return self::already_resolved( __( 'This row is not in the current report, so it is already resolved or ignored.', 'linguator-pro' ) );
			}
			$by_section[ $found['issue']['section'] ][ $found['issue']['issue_key'] ] = array( $ignore_key );
		} elseif ( '' !== $issue_key ) {
			$issue = Issue_Service::find( $issue_key );
			if ( null === $issue ) {
				return self::already_resolved( __( 'This issue is not in the current report, so it is already resolved or ignored.', 'linguator-pro' ) );
			}

			$keys = array();
			foreach ( $issue['rows'] as $row ) {
				$keys = array_merge( $keys, Issue_Service::row_keys( $row ) );
			}
			$keys = array_values( array_unique( $keys ) );

			if ( empty( $keys ) || count( $keys ) > self::MAX_ROWS || $issue['rows_truncated'] ) {
				return self::recheck_whole_issue( $issue );
			}

			$by_section[ $issue['section'] ][ $issue['issue_key'] ] = $keys;
		} else {
			$ids = array( $post_id );
			$ids = array_merge( $ids, array_map( 'intval', array_values( (array) linguator_get_post_translations( $post_id ) ) ) );
			$ids = array_unique( $ids );

			foreach ( Issue_Service::all() as $candidate ) {
				foreach ( $candidate['rows'] as $row ) {
					$objects = Issue_Service::row_objects( $row );
					if ( array_intersect( $ids, $objects['posts'] ) ) {
						foreach ( Issue_Service::row_keys( $row ) as $key ) {
							$by_section[ $candidate['section'] ][ $candidate['issue_key'] ][] = $key;
						}
					}
				}
			}

			if ( empty( $by_section ) ) {
				return array(
					'checked'   => 0,
					'resolved'  => true,
					'results'   => array(),
					'message'   => __( 'The current report records no issue for this post or its translations.', 'linguator-pro' ),
					'next_step' => __( 'Nothing to recheck. To look at the post itself, call linguator-inspector/inspect-content.', 'linguator-pro' ),
				);
			}
		}

		$scanner = Report_Service::scanner();
		$results = array();
		$checked = array();

		try {
			foreach ( $by_section as $section => $issues ) {
				foreach ( $issues as $key_issue => $keys ) {
					$keys = array_values( array_unique( array_slice( $keys, 0, self::MAX_ROWS ) ) );
					$done = $scanner->recheck_rows( (string) $section, $keys );

					// A row the scanner cannot check alone: re-scan the issue's result instead.
					if ( null === $done ) {
						$done = $scanner->scan_section( (string) $section, (string) $key_issue );
					}

					foreach ( $keys as $key ) {
						$checked[ $key ] = null === $done ? false : true;
					}
				}
			}
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'lmat_recheck_failed',
				__( 'The recheck could not finish. Try run-scan with the issue\'s section instead.', 'linguator-pro' ),
				array( 'status' => 500 )
			);
		}

		Report_Service::forget_context();
		$known = Issue_Service::known_keys();

		foreach ( $checked as $key => $ok ) {
			if ( ! $ok ) {
				$results[] = array(
					'ignore_key' => (string) $key,
					'resolved'   => false,
					'error'      => __( 'This row could not be re-checked. Run linguator-inspector/run-scan for its section.', 'linguator-pro' ),
				);
				continue;
			}

			$resolved  = ! isset( $known[ $key ] );
			$results[] = array(
				'ignore_key' => (string) $key,
				'resolved'   => $resolved,
				'message'    => $resolved
					? __( 'No longer reported.', 'linguator-pro' )
					: __( 'Still reported by the scan.', 'linguator-pro' ),
			);
		}

		$resolved_count = count( array_filter( array_column( $results, 'resolved' ) ) );
		$all_done       = count( $results ) > 0 && count( $results ) === $resolved_count;

		return array(
			'checked'            => count( $results ),
			'resolved_count'     => $resolved_count,
			'still_present'      => count( $results ) - $resolved_count,
			'resolved'           => $all_done,
			'sections_rescanned' => array_map( 'strval', array_keys( $by_section ) ),
			'results'            => $results,
			'next_step'          => $all_done
				? __( 'Fixed. Move on to the next step of linguator-inspector/get-fix-plan.', 'linguator-pro' )
				: __( 'Some rows are still present or could not be checked. Get their details with linguator-inspector/get-issues (issue_key) and try the fix again, or ask the user.', 'linguator-pro' ),
		);
	}

	/**
	 * Re-scans the result behind an issue and reports whether it is gone.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $issue Issue from Issue_Service.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function recheck_whole_issue( array $issue ) {
		try {
			$report = Report_Service::scanner()->scan_section( (string) $issue['section'], (string) $issue['issue_key'] );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'lmat_recheck_failed',
				__( 'The recheck could not finish. Try run-scan with the issue\'s section instead.', 'linguator-pro' ),
				array( 'status' => 500 )
			);
		}

		if ( null === $report ) {
			return new WP_Error(
				'lmat_no_saved_scan',
				__( 'This issue cannot be re-checked yet. Call linguator-inspector/run-scan with scope "all" first.', 'linguator-pro' ),
				array( 'status' => 409 )
			);
		}

		Report_Service::forget_context();
		$still = Issue_Service::find( (string) $issue['issue_key'] );

		return array(
			'checked'            => 1,
			'resolved'           => null === $still,
			'sections_rescanned' => array( (string) $issue['section'] ),
			'results'            => array(),
			'remaining_count'    => null === $still ? 0 : (int) $still['count'],
			'message'            => null === $still
				? __( 'The issue is gone after re-checking it.', 'linguator-pro' )
				: __( 'The issue is still present after re-checking it.', 'linguator-pro' ),
			'next_step'          => null === $still
				? __( 'Fixed. Move on to the next step of linguator-inspector/get-fix-plan.', 'linguator-pro' )
				: __( 'Call linguator-inspector/get-issues with the issue_key to see which rows remain.', 'linguator-pro' ),
		);
	}

	/**
	 * Result for a target that is no longer in the report.
	 *
	 * @since 1.2.0
	 * @param string $message Why nothing was checked.
	 * @return array<string, mixed>
	 */
	private static function already_resolved( $message ) {
		return array(
			'checked'   => 0,
			'resolved'  => true,
			'results'   => array(),
			'message'   => $message,
			'next_step' => __( 'Call linguator-inspector/get-issues to see what is left.', 'linguator-pro' ),
		);
	}
}
