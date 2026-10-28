<?php
/**
 * Runs every site scan check and stores the report.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Includes\Other\Linguator_Model;

/**
 * Loads checks, runs them, applies the ignore list and persists the report.
 *
 * @since 2.3.0
 */
class Scanner {

	const REPORT_OPTION  = 'lmat_scan_last_report';
	const IGNORED_OPTION = 'lmat_scan_ignored';
	const RESET_KEY      = '__all__';

	/**
	 * Sections a scan can be limited to.
	 */
	const SECTIONS = array( 'content', 'urls', 'site', 'elementor' );

	/**
	 * Row keys that name a post, so one row can be rechecked without rerunning its issue.
	 */
	const ROW_KEY_PATTERN = '/^(?:elementor:[^:]+:|content_updated:|content:)(\d+)(?::|$)/';

	/**
	 * Linguator model.
	 *
	 * @var Linguator_Model
	 */
	private $model;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Linguator_Model $model Linguator model.
	 */
	public function __construct( Linguator_Model $model ) {
		$this->model = $model;
	}

	/**
	 * Runs all checks and saves the report.
	 *
	 * @since 2.3.0
	 *
	 * @return array Report with `time`, `summary`, `results` and `coverage`.
	 */
	public function scan(): array {
        $saved = self::get_saved_report();
        $checks = array_filter( $this->load_checks(), static function ( $check ) {
            return 'urls' !== $check->section();
        } );
        $run = $this->run_checks( $checks );
        // URL checks are explicit. A full scan must neither rerun nor erase them.
        $url_results = array_values( array_filter( $saved['results'] ?? array(), static function ( $result ) {
            return 'urls' === ( $result['section'] ?? '' );
        } ) );
        $results = array_merge( $run['results'], $url_results );
        $partial = array();
        if ( isset( $saved['partial_scans']['urls'] ) ) {
            $partial['urls'] = $saved['partial_scans']['urls'];
        } elseif ( $url_results && isset( $saved['time'] ) ) {
            $partial['urls'] = $saved['time'];
        }
        return self::save_report( array(
            'summary' => self::summarize( $results ),
            'results' => $results,
            'coverage' => $run['coverage'],
            'elementor' => $run['elementor'],
            'partial_scans' => $partial,
        ) );
    }

	/**
	 * Rescans one section, or one issue of it, and swaps those rows in the saved report.
	 *
	 * The main scan time stays; the section's own time is recorded in `partial_scans`.
	 *
	 * @since 2.3.0
	 *
	 * @param string      $section Section key.
	 * @param string|null $only    Result id to recheck, or null for the whole section.
	 * @return array|null The updated report, or null when there is no saved scan to update.
	 */
	public function scan_section( string $section, ?string $only = null ): ?array {
		$saved = self::get_saved_report();
		if ( null === $saved || ! in_array( $section, self::SECTIONS, true ) ) {
			return null;
		}

		$checks = array_filter(
			$this->load_checks(),
			static function ( $check ) use ( $section ) {
				return $check->section() === $section;
			}
		);

		foreach ( $checks as $check ) {
			$check->only( $only );
		}

		$run   = $this->run_checks( $checks );
		$fresh = array();
		foreach ( $run['results'] as $row ) {
			$fresh[ $row['id'] ] = $row;
		}

		$merged = array();
		foreach ( (array) $saved['results'] as $row ) {
			$in_scope = $row['section'] === $section && ( null === $only || $row['id'] === $only );
			if ( ! $in_scope ) {
				$merged[] = $row;
				continue;
			}
			// Keep the row's position when it is still reported; drop it when the check no longer produces it.
			if ( isset( $fresh[ $row['id'] ] ) ) {
				$merged[] = $fresh[ $row['id'] ];
				unset( $fresh[ $row['id'] ] );
			}
		}
		$merged = array_merge( $merged, array_values( $fresh ) );

		$saved['results'] = $merged;
		$saved['summary'] = self::summarize( $merged );

		if ( ! empty( $run['coverage'] ) ) {
			$saved['coverage'] = $run['coverage'];
		}
		if ( ! empty( $run['elementor'] ) ) {
			$saved['elementor'] = $run['elementor'];
		}

		if ( null === $only ) {
			$saved['partial_scans']             = isset( $saved['partial_scans'] ) && is_array( $saved['partial_scans'] ) ? $saved['partial_scans'] : array();
			$saved['partial_scans'][ $section ] = time();
		}

		update_option( self::REPORT_OPTION, $saved, false );

		return $saved;
	}

	/**
	 * Whether any post or term of a translated type still has no language.
	 *
	 * Such content is invisible to the scan's language comparisons, so the scan waits until it is assigned.
	 *
	 * @since 2.3.0
	 *
	 * @param Linguator_Model $model Linguator model.
	 * @return bool
	 */
	public static function has_unassigned_content( Linguator_Model $model ): bool {
		if ( ! $model->get_default_language() ) {
			return false;
		}

		// The model also returns objects of internal types (revisions, global styles); only real content counts.
		$types = array();
		foreach ( (array) $model->get_translated_post_types() as $type ) {
			$object = get_post_type_object( (string) $type );
			if ( $object && ! empty( $object->public ) && ! empty( $object->show_ui ) && 'elementor_library' !== $type ) {
				$types[] = (string) $type;
			}
		}
		foreach ( $types ? (array) $model->get_posts_with_no_lang( $types, 50 ) : array() as $id ) {
			if ( in_array( get_post_type( (int) $id ), $types, true ) && ! in_array( get_post_status( (int) $id ), array( 'trash', 'auto-draft' ), true ) ) {
				return true;
			}
		}

		$taxonomies = array_values( array_diff( (array) $model->get_translated_taxonomies(), array( 'nav_menu', 'link_category', 'post_format' ) ) );
		foreach ( $taxonomies ? (array) $model->get_terms_with_no_lang( $taxonomies, 50 ) : array() as $id ) {
			$term = get_term( (int) $id );
			if ( $term instanceof \WP_Term && in_array( $term->taxonomy, $taxonomies, true ) ) {
				return true;
			}
		}

		return false;
	}

	/** Recheck explicit stored row keys without changing other rows or scan times. */
	public function recheck_rows( string $section, array $keys ): ?array {
		$saved = self::get_saved_report();
		if ( ! $saved || ! $keys || ! in_array( $section, self::SECTIONS, true ) ) {
			return null;
		}
		if ( 'elementor' === $section && ! Checks\Elementor::available() ) return null;
		$keys = array_values( array_unique( $keys ) );
		$known = array();
		foreach ( $saved['results'] as $result ) {
			if ( $result['section'] !== $section ) continue;
			foreach ( $result['details'] ?? array() as $row ) {
				if ( isset( $row['ignore_key'] ) && in_array( $row['ignore_key'], $keys, true ) ) $known[] = $row['ignore_key'];
			}
		}
		if ( array_diff( $keys, $known ) ) return null;

		// A row can be rebuilt on its own only when its key names a post; any other row reruns its whole issue.
		$scoped_ok   = 'content' === $section || 'elementor' === $section;
		foreach ( $keys as $key ) {
			if ( ! $scoped_ok || ! preg_match( self::ROW_KEY_PATTERN, $key ) ) {
				$scoped_ok = false;
				break;
			}
		}
		if ( ! $scoped_ok ) {
			foreach ( $saved['results'] as $result ) {
				if ( $result['section'] === $section && array_intersect( $keys, array_column( $result['details'] ?? array(), 'ignore_key' ) ) ) {
					$this->scan_section( $section, (string) $result['id'] );
				}
			}
			return self::get_saved_report();
		}

		foreach ( $saved['results'] as &$result ) {
			if ( $result['section'] !== $section ) continue;
			$scoped = array_values( array_intersect( $keys, array_column( $result['details'] ?? array(), 'ignore_key' ) ) );
			if ( ! $scoped ) continue;
			foreach ( $scoped as $key ) {
				if ( ! preg_match( self::ROW_KEY_PATTERN, $key, $parts ) ) return null;
				clean_post_cache( (int) $parts[1] );
				foreach ( $this->model->post->get_translations( (int) $parts[1] ) as $linked ) clean_post_cache( (int) $linked );
			}
			$check = 'elementor' === $section ? new Checks\Elementor( $this->model ) : new Checks\Content( $this->model );
			$check->only( $result['id'] );
			$check->scope_rows( $scoped );
			$fresh = null;
			foreach ( $check->run() as $found ) {
				if ( $found->id === $result['id'] ) $fresh = $found->to_array();
			}
			$kept = array_values( array_filter( $result['details'], static function ( $row ) use ( $scoped ) { return ! in_array( $row['ignore_key'] ?? '', $scoped, true ); } ) );
			foreach ( $fresh['details'] ?? array() as $row ) {
				if ( in_array( $row['ignore_key'] ?? '', $scoped, true ) ) $kept[] = $row;
			}
			$result['details'] = $kept;
			if ( ! $kept && empty( $result['details_truncated'] ) ) {
				$result['status'] = Result::PASS;
				$result['recently_fixed'] = true;
				$result['message'] = __( 'The previously reported issues have been resolved.', 'linguator-pro' );
			} else {
				$result['message'] = __( 'Review the remaining affected items below. Other rows retain their previous scan results.', 'linguator-pro' );
			}
		}
		unset( $result );
		$saved['summary'] = self::summarize( $saved['results'] );
		update_option( self::REPORT_OPTION, $saved, false );
		return $saved;
	}

	/**
	 * Saved report.
	 *
	 * @since 2.3.0
	 *
	 * @return array|null
	 */
	public static function get_saved_report(): ?array {
		$report = get_option( self::REPORT_OPTION, null );
		if ( ! is_array( $report ) || empty( $report['time'] ) ) {
			return null;
		}

		// A report saved before sections existed cannot be updated in place; ask for a fresh scan.
		if ( ! empty( $report['results'] ) && ! isset( $report['results'][0]['section'] ) ) {
			return null;
		}

		return $report;
	}

	/**
	 * Persists a report.
	 *
	 * @since 2.3.0
	 *
	 * @param array $data Report data.
	 * @return array Saved report with `time` set.
	 */
	public static function save_report( array $data ): array {
		$data['time'] = time();
		update_option( self::REPORT_OPTION, $data, false );
		return $data;
	}

	/**
	 * Keys the user dismissed.
	 *
	 * @since 2.3.0
	 *
	 * @return string[]
	 */
	public static function get_ignored(): array {
		$ignored = get_option( self::IGNORED_OPTION, array() );
		return is_array( $ignored ) ? array_values( array_filter( array_map( 'strval', $ignored ) ) ) : array();
	}

	/**
	 * Adds or removes a key from the ignore list.
	 *
	 * @since 2.3.0
	 *
	 * @param string $key    Issue id or detail row ignore key.
	 * @param bool   $ignore True to ignore, false to restore.
	 * @return void
	 */
	public static function set_ignored( string $key, bool $ignore ): void {
        self::set_ignored_many( array( $key ), $ignore );
    }

    /** Update an accordion's dismissed items in a single option write. */
    public static function set_ignored_many( array $keys, bool $ignore ): void {
        $keys = array_values( array_unique( array_filter( array_map( 'strval', $keys ), static function ( $key ) { return '' !== $key; } ) ) );
        if ( ! $keys ) return;
        if ( ! $ignore && in_array( self::RESET_KEY, $keys, true ) ) {
            delete_option( self::IGNORED_OPTION );
            return;
        }
        $ignored = self::get_ignored();
        $ignored = $ignore ? array_merge( $ignored, $keys ) : array_diff( $ignored, $keys );
        update_option( self::IGNORED_OPTION, array_values( array_unique( $ignored ) ), false );
    }

	/**
	 * Applies the ignore list so dismissed rows disappear without a rescan.
	 *
	 * An issue whose rows are all dismissed is shown as passing. Dismissed rows are
	 * returned in `ignored_items` so they can be restored one by one.
	 *
	 * @since 2.3.0
	 *
	 * @param array $report Saved report.
	 * @return array
	 */
	public static function apply_ignored( array $report ): array {
		$report['ignored_items'] = array();
		$ignored                 = self::get_ignored();

		foreach ( $report['results'] as $i => $result ) {
            // Missing menu assignments and broken navigation prevent visitors from reaching a language.
            if ( 'warning' === ($result['status'] ?? '') && ( 0 === strpos($result['id'], 'menu_') || 0 === strpos($result['id'], 'nav_') ) ) {
                $result['status'] = Result::CRITICAL;
            }
			if ( 'pass' === $result['status'] ) {
				continue;
			}

			$whole_ignored = in_array( $result['id'], $ignored, true );
			$had_details   = ! empty( $result['details'] );
			$kept          = array();

			foreach ( (array) $result['details'] as $row ) {
				if ( ! empty( $row['ignore_key'] ) && in_array( $row['ignore_key'], $ignored, true ) ) {
					$report['ignored_items'][] = array(
						'key'     => $row['ignore_key'],
						'label'   => $row['label'],
						'message' => $row['message'],
						'issue'   => $result['label'],
                        'issue_id' => $result['id'],
						'section' => $result['section'],
						'post_type' => $row['post_type'] ?? '',
						'language' => $row['language'] ?? '',
					);
					continue;
				}
				$kept[] = $row;
			}

			if ( $whole_ignored ) {
				$report['ignored_items'][] = array(
					'key'     => $result['id'],
					'label'   => $result['label'],
					'message' => $result['message'],
					'issue'   => $result['label'],
                        'issue_id' => $result['id'],
					'section' => $result['section'],
				);
			}

			$result['details'] = $kept;

			if ( $whole_ignored || ( $had_details && empty( $kept ) ) ) {
				$result['status']       = Result::PASS;
				$result['message']      = __( 'All items were dismissed.', 'linguator-pro' );
				$result['fix_url']      = '';
			}

			$report['results'][ $i ] = $result;
		}

		$report['summary'] = self::summarize( $report['results'] );

		return $report;
	}

	/**
	 * Counts results by status.
	 *
	 * @since 2.3.0
	 *
	 * @param array[] $results Result arrays.
	 * @return array<string,int>
	 */
	public static function summarize( array $results ): array {
		$summary = array(
			'pass'     => 0,
			'info'     => 0,
			'warning'  => 0,
			'critical' => 0,
			'total'    => count( $results ),
		);

		foreach ( $results as $result ) {
			$status = isset( $result['status'] ) ? $result['status'] : Result::PASS;
			if ( isset( $summary[ $status ] ) ) {
				++$summary[ $status ];
			}
		}

		return $summary;
	}

	/**
	 * Health pillars from 0 to 100 and the weighted score.
	 *
	 * Coverage is the average share of default-language content translated into each
	 * language. Setup starts at 100 and loses points per class of problem; repeated
	 * rows of one class (one menu row per location, one strings row per language) are
	 * charged once so a single misconfiguration cannot floor the score.
	 *
	 * @since 2.3.0
	 *
	 * @param array[] $results  Result arrays with the ignore list applied.
	 * @param array[] $coverage Coverage rows.
	 * @return array{score:int,coverage:int,setup:int}
	 */
	public static function health( array $results, array $coverage, array $elementor = array() ): array {
		$coverage_score = 100;
		if ( ! empty( $coverage ) ) {
			$coverage_score = (int) round( array_sum( wp_list_pluck( $coverage, 'percent' ) ) / count( $coverage ) );
		}

        $quality_keys = array();
        foreach ( $results as $result ) {
            if ( ! in_array($result['status'], array('warning','critical'), true) || ! preg_match('/^(content_identical|content_link|content_acf|content_placeholder|elementor_untranslated)/', $result['id']) ) continue;
            foreach ($result['details'] ?? array() as $row) $quality_keys[$row['ignore_key'] ?? $row['label']] = true;
        }
        $coverage_score = max(0, $coverage_score - min(25, 2 * count($quality_keys)));
		$penalty = 0;
		$once    = array();
		$other   = 0;

		foreach ( $results as $result ) {
			if ( ! in_array( $result['status'], array( 'warning', 'critical' ), true ) ) {
				continue;
			}
			if ( 'elementor' === ( $result['section'] ?? '' ) ) {
				continue; // Builder health is measured separately from site setup.
			}

			$id = $result['id'];

			if ( 'lang_default' === $id || 'lang_secondary' === $id ) {
				$penalty += 40;
			} elseif ( 'lang_homepage' === $id ) {
				$penalty += 25;
			} elseif ( 0 === strpos( $id, 'menu_' ) && ! isset( $once['menus'] ) ) {
				$once['menus'] = true;
				$penalty      += 25;
			} elseif ( 0 === strpos( $id, 'string_' ) && ! isset( $once['strings'] ) ) {
				$once['strings'] = true;
				$penalty        += 10;
			} elseif ( 'url_permalinks' === $id || 'lang_permalinks' === $id ) {
				$penalty += 10;
			} elseif ( 0 === strpos( $id, 'url_' ) && ! isset( $once['urls'] ) ) {
				$once['urls'] = true;
				$penalty     += 30;
			} elseif ( 0 !== strpos( $id, 'content_' ) && 0 !== strpos( $id, 'menu_' ) && 0 !== strpos( $id, 'string_' ) && 0 !== strpos( $id, 'url_' ) ) {
				$other += 'critical' === $result['status'] ? 10 : 5;
			}
		}

		$setup = (int) max( 0, 100 - $penalty - min( 40, $other ) );
		$score = (int) max( 0, min( 100, round( 0.625 * $coverage_score + 0.375 * $setup ) ) );
		$builder_checks = array_filter( $results, static function ( $result ) {
			return 'elementor' === ( $result['section'] ?? '' ) && in_array( $result['status'], array( 'pass', 'warning', 'critical' ), true );
		} );
		$builder = null;
		if ( $builder_checks ) {
            $critical = 0; $warning = 0; $missing = 0; $fields = 0;
            foreach ($builder_checks as $result) {
                if ('pass' === $result['status']) continue;
                $count = max(1, count($result['details'] ?? array()));
                if ('critical' === $result['status']) $critical += $count; else $warning += $count;
                if ('elementor_missing' === $result['id']) $missing += $count;
                if (in_array($result['id'], array('elementor_empty','elementor_fields'), true)) $fields += $count;
            }
            $documents = max(1, (int)($elementor['checked'] ?? count($builder_checks)));
            $widgets = max(0, (int)($elementor['widgets'] ?? 0));
            $expected = max(1, $documents * max(1,count($coverage)));
            $builder_coverage = max(0, round(100 * ($expected - $missing) / $expected));
            $integrity = max(0, round(100 * (1 - min(1, ($critical * 3 + $warning) / max(1, $expected + $widgets)))));
            $fidelity = max(0, round(100 * (1 - min(1, $fields / max(1,$widgets)))));
            $builder = (int)round($widgets > 0 ? .5 * $builder_coverage + .3 * $integrity + .2 * $fidelity : .6 * $builder_coverage + .4 * $integrity);
            if ($critical) $builder = min(95,$builder); elseif ($warning) $builder = min(98,$builder);

			$score = (int) round( 0.50 * $coverage_score + 0.30 * $setup + 0.20 * $builder );
		}

		return array(
			'score'    => $score,
			'coverage' => $coverage_score,
			'setup'    => $setup,
			'builder'  => $builder,
		);
	}

	/**
	 * Runs checks and collects their results and extra data.
	 *
	 * @since 2.3.0
	 *
	 * @param Abstract_Check[] $checks Checks to run.
	 * @return array{results:array[],coverage:array[]}
	 */
	private function run_checks( array $checks ): array {
		$results   = array();
		$coverage  = array();
		$elementor = array();

		foreach ( $checks as $check ) {
			try {
				$found = $check->run();
			} catch ( \Throwable $e ) {
				// One broken check must not stop the rest of the scan.
				error_log( 'Linguator site scan: ' . get_class( $check ) . ' failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				continue;
			}

			foreach ( $found as $result ) {
				if ( $result instanceof Result ) {
					$results[] = $result->to_array();
				}
			}

			$extra = $check->extra();
			if ( ! empty( $extra['coverage'] ) ) {
				$coverage = $extra['coverage'];
			}
			if ( ! empty( $extra['elementor'] ) ) {
				$elementor = $extra['elementor'];
			}
		}

		/**
		 * Filters the raw scan results, letting other add-ons inject or alter rows.
		 *
		 * @since 2.3.0
		 *
		 * @param array[] $results Result arrays.
		 */
		$results = apply_filters( 'lmat_scan_results', $results );

		return array(
			'results'   => $this->reconcile_fixed( is_array( $results ) ? $results : array() ),
			'coverage'  => $coverage,
			'elementor' => $elementor,
		);
	}

	/**
	 * Marks results that were problems in the previous report but pass now.
	 *
	 * @since 2.3.0
	 *
	 * @param array[] $results Fresh result arrays.
	 * @return array[]
	 */
	private function reconcile_fixed( array $results ): array {
		$saved = self::get_saved_report();
		if ( empty( $saved['results'] ) || ! is_array( $saved['results'] ) ) {
			return $results;
		}

		$was_problem = array();
		foreach ( $saved['results'] as $result ) {
			if ( ! empty( $result['id'] ) && isset( $result['status'] ) && in_array( $result['status'], array( 'warning', 'critical' ), true ) ) {
				$was_problem[ $result['id'] ] = true;
			}
		}

		foreach ( $results as $i => $result ) {
			if ( Result::PASS === $result['status'] && ! empty( $was_problem[ $result['id'] ] ) ) {
				$results[ $i ]['recently_fixed'] = true;
			}
		}

		return $results;
	}

	/**
	 * Instantiates the checks.
	 *
	 * @since 2.3.0
	 *
	 * @return Abstract_Check[]
	 */
	private function load_checks(): array {
		$checks = array(
			new Checks\Languages( $this->model ),
			new Checks\Url_Config( $this->model ),
			new Checks\Url_Settings( $this->model ),
			new Checks\Url_Slugs( $this->model ),
			new Checks\Url_Numbered_Copies( $this->model ),
			new Checks\Url_Routing( $this->model ),
			new Checks\Url_Permalinks( $this->model ),
			new Checks\Content( $this->model ),
			new Checks\Content_Identical( $this->model ),
			new Checks\Content_Links( $this->model ),
			new Checks\Content_Placeholders( $this->model ),
			new Checks\Content_Fields( $this->model ),
			new Checks\Content_Terms( $this->model ),
			new Checks\Untranslated_Types( $this->model ),
			new Checks\Menus( $this->model ),
			new Checks\Menu_Links( $this->model ),
			new Checks\Navigation( $this->model ),
			new Checks\Strings( $this->model ),
			new Checks\Switcher( $this->model ),
			new Checks\Elementor( $this->model ),
			new Checks\Elementor_Headers( $this->model ),
			new Checks\Elementor_Loops( $this->model ),
		);

		/**
		 * Filters the list of site scan checks.
		 *
		 * @since 2.3.0
		 *
		 * @param Abstract_Check[] $checks Check instances.
		 */
		$checks = apply_filters( 'lmat_scan_checks', $checks );

		return array_values(
			array_filter(
				is_array( $checks ) ? $checks : array(),
				static function ( $check ) {
					return $check instanceof Abstract_Check;
				}
			)
		);
	}
}
