<?php
/**
 * Read access to the Site Scan's saved report.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorInspector\Services;

use LinguatorPro\Modules\Scan\Issue_Copy;
use LinguatorPro\Modules\Scan\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns the Site Scan's stored report into ability payloads.
 *
 * Reads only. Abilities never re-implement a check: they read what the scanner
 * saved (option lmat_scan_last_report) and trigger scans through Scanner. The
 * "prepared" report built here mirrors what the Site Scan REST route returns to
 * the admin screen (ignores applied, copy added), so the tools always agree with
 * the screen.
 *
 * @since 1.2.0
 */
final class Report_Service {

	/**
	 * Optional parts of the status payload.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const STATUS_EXTRAS = array( 'environment', 'coverage' );

	/**
	 * Result ids the admin screen shows elsewhere (as a notice or a dedicated
	 * action) instead of as report rows. The abilities rebuild the useful ones.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const HIDDEN_RESULTS = array( 'content_no_language', 'term_no_language', 'lang_untranslated_types' );

	/**
	 * Prepared report for this request.
	 *
	 * @since 1.2.0
	 * @var array<string, mixed>|null
	 */
	private static $context = null;

	/**
	 * A scanner bound to Linguator's model.
	 *
	 * @since 1.2.0
	 * @return Scanner
	 */
	public static function scanner() {
		return new Scanner( Inspector_Guard::model() );
	}

	/**
	 * The prepared report, read once per request.
	 *
	 * Keys: has_scan, time, results, ignored_items, summary, coverage,
	 * elementor, health, partial_scans (section => timestamp), types_notice.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed>
	 */
	public static function context() {
		if ( null === self::$context ) {
			self::$context = self::prepare();
		}

		return self::$context;
	}

	/**
	 * Drop the cached report after a scan, recheck or ignore changed it.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function forget_context() {
		self::$context = null;
		Issue_Service::forget();
	}

	/**
	 * Severity as the tools report it: critical, warning, info or pass.
	 *
	 * @since 1.2.0
	 * @param string $severity Stored result status.
	 * @return string
	 */
	public static function severity( $severity ) {
		return in_array( (string) $severity, array( 'critical', 'warning', 'info', 'pass' ), true ) ? (string) $severity : 'info';
	}

	/**
	 * Section as the tools report it: content, elementor, urls or site.
	 *
	 * @since 1.2.0
	 * @param string $section Stored section.
	 * @return string
	 */
	public static function section( $section ) {
		return in_array( (string) $section, array( 'content', 'elementor', 'urls' ), true ) ? (string) $section : 'site';
	}

	/**
	 * Severity counts and scan time of the saved report.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> The "scan" part of the status payload.
	 */
	public static function scan_summary() {
		$status = self::get_scan_status();

		return $status['scan'];
	}

	/**
	 * Overview of the Site Scan's state on this site.
	 *
	 * @since 1.2.0
	 * @param string[] $include Parts of STATUS_EXTRAS to add.
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
	 * Multilingual setup the Site Scan checks against.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Add-ons plus the admin screen's environment rows.
	 */
	public static function get_environment() {
		$model   = Inspector_Guard::model();
		$default = $model ? $model->get_default_language() : false;
		$builder = 'Gutenberg';

		if ( defined( 'ELEMENTOR_VERSION' ) || class_exists( '\Elementor\Plugin' ) ) {
			$builder = 'Elementor';
		} elseif ( defined( 'ET_BUILDER_VERSION' ) ) {
			$builder = 'Divi';
		}

		$languages = array();
		foreach ( $model ? (array) $model->get_languages_list() : array() as $language ) {
			$languages[] = (string) $language->slug;
		}

		return array(
			'linguator_addons' => self::addons(),
			'details'          => array(
				'page_builder'     => $builder,
				'theme'            => (string) wp_get_theme()->get( 'Name' ),
				'languages'        => $languages,
				'default_language' => $default ? (string) $default->slug : '',
				'woocommerce'      => class_exists( 'WooCommerce' ),
				'php_version'      => PHP_VERSION,
				'wp_version'       => get_bloginfo( 'version' ),
			),
		);
	}

	/**
	 * Translation coverage per language, weakest first.
	 *
	 * The figures of the Site Scan coverage card, taken from the saved scan.
	 * Empty until the site has been scanned.
	 *
	 * @since 1.2.0
	 * @return array<int, array<string, mixed>> Rows: language, slug, coverage (percent).
	 */
	public static function get_language_coverage() {
		$rows = array();

		foreach ( (array) self::context()['coverage'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$rows[] = array(
				'language' => (string) ( $row['name'] ?? '' ),
				'slug'     => (string) ( $row['slug'] ?? '' ),
				'coverage' => (int) ( $row['percent'] ?? 0 ),
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
	 * A timestamp as an ISO 8601 UTC string, or null for zero.
	 *
	 * @since 1.2.0
	 * @param int $time Unix timestamp.
	 * @return string|null
	 */
	public static function iso( $time ) {
		return (int) $time > 0 ? gmdate( 'c', (int) $time ) : null;
	}

	/**
	 * Scan state: the part of the status payload that is always returned.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Status payload.
	 */
	private static function get_scan_status() {
		$context  = self::context();
		$has_scan = ! empty( $context['has_scan'] );
		$time     = (int) $context['time'];
		$partial  = array();
		$issues   = array(
			'critical' => 0,
			'warning'  => 0,
		);

		foreach ( (array) $context['partial_scans'] as $section => $stamp ) {
			$partial[ (string) $section ] = self::iso( (int) $stamp );
		}

		if ( $has_scan ) {
			foreach ( Issue_Service::all() as $issue ) {
				if ( isset( $issues[ $issue['severity'] ] ) ) {
					++$issues[ $issue['severity'] ];
				}
			}
		}

		$summary = (array) $context['summary'];
		$health  = (array) $context['health'];
		$urls_at = (int) ( $context['partial_scans']['urls'] ?? 0 );

		$elementor_results = 0;
		foreach ( (array) $context['results'] as $result ) {
			if ( 'elementor' === ( $result['section'] ?? '' ) ) {
				++$elementor_results;
			}
		}

		$languages = array();
		$default   = '';
		$model     = Inspector_Guard::model();
		if ( $model ) {
			foreach ( (array) $model->get_languages_list() as $language ) {
				$languages[] = (string) $language->slug;
			}
			$default_language = $model->get_default_language();
			$default          = $default_language ? (string) $default_language->slug : '';
		}

		return array(
			'plugin_version' => defined( 'LINGUATOR_PRO_VERSION' ) ? (string) LINGUATOR_PRO_VERSION : '',
			'linguator'      => array(
				'version'          => defined( 'LINGUATOR_VERSION' ) ? (string) LINGUATOR_VERSION : '',
				'default_language' => $default,
				'languages'        => $languages,
			),
			'scan'           => array(
				'has_saved_scan'  => $has_scan,
				'last_scan_gmt'   => self::iso( $has_scan ? $time : 0 ),
				'last_scan_label' => $has_scan ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : null,
				'issues'          => array(
					'critical'      => $issues['critical'],
					'warning'       => $issues['warning'],
					'passed_checks' => (int) ( $summary['pass'] ?? 0 ),
					'health_score'  => $has_scan ? (int) ( $health['score'] ?? 0 ) : 0,
				),
				'checks'          => array(
					'critical' => (int) ( $summary['critical'] ?? 0 ),
					'warning'  => (int) ( $summary['warning'] ?? 0 ),
					'info'     => (int) ( $summary['info'] ?? 0 ),
					'pass'     => (int) ( $summary['pass'] ?? 0 ),
					'total'    => (int) ( $summary['total'] ?? 0 ),
				),
				'partial_scans'   => empty( $partial ) ? (object) array() : $partial,
			),
			'elementor'      => array(
				'available'        => class_exists( '\LinguatorPro\Modules\Scan\Checks\Elementor' ) && \LinguatorPro\Modules\Scan\Checks\Elementor::available(),
				'has_saved_report' => $elementor_results > 0,
				'last_scan_gmt'    => $elementor_results > 0 ? self::iso( (int) ( $context['partial_scans']['elementor'] ?? $time ) ) : null,
			),
			'routing'        => array(
				'available'        => count( $languages ) > 1,
				'has_fresh_report' => $urls_at > 0 && ( time() - $urls_at ) < DAY_IN_SECONDS,
				'last_test_gmt'    => self::iso( $urls_at ),
			),
			'ignored_count'  => count( Scanner::get_ignored() ),
			'fix_tools'      => array(
				'linguator_abilities' => Inspector_Guard::linguator_abilities_available(),
			),
			'next_step'      => $has_scan
				? __( 'Call linguator-inspector/get-issues to list what needs fixing, or linguator-inspector/get-fix-plan for the recommended order.', 'linguator-pro' )
				: __( 'No scan has been saved yet. Call linguator-inspector/run-scan first.', 'linguator-pro' ),
		);
	}

	/**
	 * Names of the active Linguator add-on plugins.
	 *
	 * @since 1.2.0
	 * @return string[]
	 */
	private static function addons() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$addons = array();

		foreach ( get_plugins() as $basename => $plugin ) {
			$folder = dirname( (string) $basename );
			if ( ! is_plugin_active( (string) $basename ) ) {
				continue;
			}
			if ( false !== strpos( $folder, 'linguator' ) || false !== strpos( $folder, 'lmat-' ) ) {
				$addons[] = wp_strip_all_tags( (string) $plugin['Name'] );
			}
		}

		return array_values( array_unique( $addons ) );
	}

	/**
	 * Builds the prepared report from the saved scan.
	 *
	 * Mirrors the Site Scan REST route: ignores applied, results the screen
	 * shows elsewhere dropped, copy added and rows given the fields the screen
	 * derives (language, edit link, menu location).
	 *
	 * @since 1.2.0
	 * @return array<string, mixed>
	 */
	private static function prepare() {
		$base = array(
			'has_scan'      => false,
			'time'          => 0,
			'results'       => array(),
			'ignored_items' => array(),
			'summary'       => Scanner::summarize( array() ),
			'coverage'      => array(),
			'elementor'     => array(),
			'health'        => array(
				'score'    => 100,
				'coverage' => 100,
				'setup'    => 100,
				'builder'  => null,
			),
			'partial_scans' => array(),
			'types_notice'  => self::types_notice(),
		);

		$saved = Scanner::get_saved_report();
		if ( null === $saved ) {
			return $base;
		}

		$report = Scanner::apply_ignored( $saved );

		$results = array();
		foreach ( (array) $report['results'] as $result ) {
			if ( in_array( $result['id'] ?? '', self::HIDDEN_RESULTS, true ) ) {
				continue;
			}

			if ( 'pass' !== ( $result['status'] ?? 'pass' ) ) {
				$result += Issue_Copy::for_id( (string) $result['id'] );
			}

			foreach ( (array) ( $result['details'] ?? array() ) as $index => $row ) {
				$result['details'][ $index ] = self::enrich_row( (array) $row );
			}

			$results[] = $result;
		}

		$ignored = array();
		foreach ( (array) $report['ignored_items'] as $item ) {
			if ( 'lang_untranslated_types' !== ( $item['key'] ?? '' ) ) {
				$ignored[] = $item;
			}
		}

		$coverage  = isset( $saved['coverage'] ) && is_array( $saved['coverage'] ) ? $saved['coverage'] : array();
		$elementor = isset( $saved['elementor'] ) && is_array( $saved['elementor'] ) ? $saved['elementor'] : array();

		return array(
			'has_scan'      => true,
			'time'          => (int) $saved['time'],
			'results'       => $results,
			'ignored_items' => $ignored,
			'summary'       => Scanner::summarize( $results ),
			'coverage'      => $coverage,
			'elementor'     => $elementor,
			'health'        => Scanner::health( $results, $coverage, $elementor ),
			'partial_scans' => isset( $saved['partial_scans'] ) && is_array( $saved['partial_scans'] ) ? $saved['partial_scans'] : array(),
			'types_notice'  => $base['types_notice'],
		);
	}

	/**
	 * The "post types or taxonomies not translatable" warning, checked live.
	 *
	 * The admin screen shows it as a notice rather than a report row, and it is
	 * not stored in the saved report, so it is computed here with the same
	 * check and the same ignore handling.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed>|null The result array, or null when nothing is wrong.
	 */
	private static function types_notice() {
		$model = Inspector_Guard::model();

		if ( null === $model || ! class_exists( '\LinguatorPro\Modules\Scan\Checks\Untranslated_Types' ) ) {
			return null;
		}

		$check   = new \LinguatorPro\Modules\Scan\Checks\Untranslated_Types( $model );
		$results = array();

		foreach ( $check->run() as $result ) {
			$results[] = $result->to_array();
		}

		$report = Scanner::apply_ignored( array( 'results' => $results ) );

		foreach ( $report['results'] as $result ) {
			if ( 'warning' === $result['status'] ) {
				return $result + Issue_Copy::for_id( (string) $result['id'] );
			}
		}

		return null;
	}

	/**
	 * Adds the fields the admin screen derives from a row's ignore key.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function enrich_row( array $row ) {
		$key = isset( $row['ignore_key'] ) ? (string) $row['ignore_key'] : '';

		if ( '' === $key ) {
			return $row;
		}

		if ( preg_match( '/^(?:content|content_updated|content_identical|content_acf|content_placeholder|content_link|elementor_loop):(\d+):/', $key, $m )
			|| preg_match( '/^elementor:[^:]+:(\d+):/', $key, $m )
			|| preg_match( '/^woo_[a-z]+:(\d+):/', $key, $m ) ) {
			$row['post_id'] = (int) $m[1];
		} elseif ( preg_match( '/^term:(\d+):/', $key, $m ) ) {
			$row['term_id'] = (int) $m[1];
		}

		if ( empty( $row['language'] ) ) {
			if ( preg_match( '/^menu:([^:]+):([^:]+)$/', $key, $m ) ) {
				$locations           = get_registered_nav_menus();
				$row['location']     = $m[1];
				$row['location_label'] = (string) ( $locations[ $m[1] ] ?? $m[1] );
				$row['language']     = $m[2];
			} elseif ( preg_match( '/^(?:string|nav_link):([^:]+):/', $key, $m )
				|| preg_match( '/^(?:lang_homepage|lang_homepage_empty|url_domains|url_domains_shared|url_locale):([^:]+)$/', $key, $m )
				|| preg_match( '/^url:slug_suffix:([^:]+):/', $key, $m ) ) {
				$row['language'] = $m[1];
			}
		}

		if ( empty( $row['edit_url'] ) && ! empty( $row['post_id'] ) ) {
			$row['edit_url'] = (string) get_edit_post_link( (int) $row['post_id'], 'url' );
		}

		return $row;
	}
}
