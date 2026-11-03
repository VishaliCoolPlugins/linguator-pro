<?php
/**
 * Issues from the saved report, shaped for the abilities.
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
 * Reads the dashboard's issue groups and Elementor buckets and turns them into
 * one list of issues with the same keys, order and copy the dashboard shows.
 *
 * Nothing here re-checks the site: every row comes from
 * DUPCAP_Dashboard::get_report_context(), so the tools always agree with the
 * dashboard.
 *
 * @since 1.1.0
 */
final class Issue_Service {

	/**
	 * Row fields passed to the AI. Everything else in a row is display markup.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	const ROW_FIELDS = array(
		'ignore_key',
		'ignore_keys',
		'title',
		'label',
		'message',
		'post_id',
		'template_id',
		'translation_id',
		'post_type',
		'post_type_label',
		'type_display',
		'post_status',
		'language',
		'language_name',
		'lang_slug',
		'issue_languages',
		'missing_languages',
		'location',
		'location_label',
		'gap_type',
		'url',
		'http_status',
		'verdict',
		'string_name',
		'string_group',
		'widget',
		'field',
		'what',
		'why',
		'original_value',
		'translated_value',
		'suggested_url',
		'edit_url',
		'translate_url',
		'assign_url',
		'fix_url',
		'action_url',
		'action_label',
	);

	/**
	 * Longest text passed for one row field.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const MAX_TEXT = 300;

	/**
	 * Section order after the priority issues, as the dashboard and its export use.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	const SECTION_ORDER = array( 'content', 'elementor', 'urls', 'site' );

	/**
	 * Every open issue, most urgent first.
	 *
	 * "Fix These First" issues come first, in the dashboard's order; then the
	 * rest by section (content, elementor, urls, site) and severity.
	 *
	 * @since 1.1.0
	 * @return array<int, array<string, mixed>> Issues with their raw rows.
	 */
	public static function all() {
		$context = Report_Service::context();
		$issues  = array();

		foreach ( (array) ( $context['priority_issues'] ?? array() ) as $group ) {
			$issues[] = self::from_group( (array) $group, true );
		}

		$rest = array();
		foreach ( (array) ( $context['grouped_issues'] ?? array() ) as $group ) {
			$rest[] = self::from_group( (array) $group, false );
		}

		$elementor = isset( $context['elementor'] ) && is_array( $context['elementor'] ) ? $context['elementor'] : array();
		foreach ( (array) ( $elementor['buckets'] ?? array() ) as $bucket ) {
			$rest[] = self::from_bucket( (array) $bucket );
		}

		$severity_rank = array_flip( array( 'critical', 'warning', 'info', 'pass' ) );
		$section_rank  = array_flip( self::SECTION_ORDER );
		$position      = 0;
		foreach ( $rest as $index => $issue ) {
			$rest[ $index ]['_order'] = array(
				$section_rank[ $issue['section'] ] ?? 9,
				$severity_rank[ $issue['severity'] ] ?? 9,
				$position++,
			);
		}

		usort(
			$rest,
			static function ( $a, $b ) {
				return $a['_order'] <=> $b['_order'];
			}
		);

		foreach ( $rest as $issue ) {
			unset( $issue['_order'] );
			$issues[] = $issue;
		}

		return $issues;
	}

	/**
	 * One issue by its issue_key.
	 *
	 * @since 1.1.0
	 * @param string $issue_key Issue key from get-issues.
	 * @return array<string, mixed>|null
	 */
	public static function find( $issue_key ) {
		foreach ( self::all() as $issue ) {
			if ( $issue['issue_key'] === (string) $issue_key ) {
				return $issue;
			}
		}

		return null;
	}

	/**
	 * The issue and row an ignore key belongs to.
	 *
	 * @since 1.1.0
	 * @param string $ignore_key Row ignore key.
	 * @return array{issue: array<string, mixed>, row: array<string, mixed>}|null
	 */
	public static function find_row( $ignore_key ) {
		$ignore_key = \DUPCAP_Ignored_Issues::sanitize_key( (string) $ignore_key );

		if ( '' === $ignore_key ) {
			return null;
		}

		foreach ( self::all() as $issue ) {
			foreach ( $issue['rows'] as $row ) {
				if ( in_array( $ignore_key, self::row_keys( $row ), true ) ) {
					return array(
						'issue' => $issue,
						'row'   => $row,
					);
				}
			}
		}

		return null;
	}

	/**
	 * Every ignore key in the current report.
	 *
	 * @since 1.1.0
	 * @return array<string, true> Keys as array keys.
	 */
	public static function known_keys() {
		$keys = array();

		foreach ( self::all() as $issue ) {
			foreach ( $issue['rows'] as $row ) {
				foreach ( self::row_keys( $row ) as $key ) {
					$keys[ $key ] = true;
				}
			}
		}

		return $keys;
	}

	/**
	 * Ignore keys on one row: its own key, or one per language on menu rows.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $row Raw row.
	 * @return string[]
	 */
	public static function row_keys( array $row ) {
		$keys = array();

		if ( ! empty( $row['ignore_key'] ) && is_string( $row['ignore_key'] ) ) {
			$keys[] = \DUPCAP_Ignored_Issues::sanitize_key( $row['ignore_key'] );
		}

		if ( ! empty( $row['ignore_keys'] ) && is_array( $row['ignore_keys'] ) ) {
			foreach ( $row['ignore_keys'] as $key ) {
				$keys[] = \DUPCAP_Ignored_Issues::sanitize_key( (string) $key );
			}
		}

		return array_values( array_filter( array_unique( $keys ) ) );
	}

	/**
	 * Public summary of an issue, without its rows.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $issue   Issue from all().
	 * @param int                  $samples Number of example rows to include.
	 * @return array<string, mixed>
	 */
	public static function summarize( array $issue, $samples = 3 ) {
		$summary = array(
			'issue_key'          => $issue['issue_key'],
			'title'              => $issue['title'],
			'section'            => $issue['section'],
			'severity'           => $issue['severity'],
			'fix_first'          => $issue['priority'],
			'count'              => $issue['count'],
			'rows_total'         => count( $issue['rows'] ),
			'affected_languages' => $issue['affected_languages'],
			'message'            => $issue['message'],
			'why'                => $issue['why'],
			'how_to_fix'         => $issue['howto'],
			'effort'             => $issue['effort'],
			'fix'                => Fix_Router::for_issue( $issue['type'], $issue ),
		);

		if ( $samples > 0 && ! empty( $issue['rows'] ) ) {
			$summary['examples'] = array();
			foreach ( array_slice( $issue['rows'], 0, (int) $samples ) as $row ) {
				$summary['examples'][] = self::present_row( $row, $issue['type'] );
			}
		}

		return $summary;
	}

	/**
	 * A row as the AI sees it: known fields only, long text shortened, plus
	 * the ready-to-use fix input when one can be derived.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $row  Raw row.
	 * @param string               $type Issue type key.
	 * @return array<string, mixed>
	 */
	public static function present_row( array $row, $type ) {
		$out = array();

		foreach ( self::ROW_FIELDS as $field ) {
			if ( ! isset( $row[ $field ] ) || '' === $row[ $field ] || array() === $row[ $field ] ) {
				continue;
			}

			$value = $row[ $field ];

			if ( is_array( $value ) ) {
				$value = array_values( array_filter( $value, 'is_scalar' ) );
				if ( empty( $value ) ) {
					continue;
				}
			} elseif ( is_string( $value ) ) {
				$value = wp_strip_all_tags( $value );
				if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > self::MAX_TEXT ) {
					$value = mb_substr( $value, 0, self::MAX_TEXT ) . '…';
				}
			} elseif ( ! is_scalar( $value ) ) {
				continue;
			}

			if ( in_array( $field, array( 'post_id', 'template_id', 'translation_id', 'http_status' ), true ) ) {
				$value = (int) $value;
			}

			$out[ $field ] = $value;
		}

		$fix = Fix_Router::for_row( (string) $type, $row );
		if ( null !== $fix ) {
			$out['fix'] = $fix;
		}

		return $out;
	}

	/**
	 * Whether an issue or row concerns a language.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $row      Issue or raw row.
	 * @param string               $language Language slug.
	 * @return bool
	 */
	public static function mentions_language( array $row, $language ) {
		$language = sanitize_key( (string) $language );

		foreach ( array( 'language', 'lang_slug' ) as $field ) {
			if ( isset( $row[ $field ] ) && $language === (string) $row[ $field ] ) {
				return true;
			}
		}

		foreach ( array( 'affected_languages', 'issue_languages', 'missing_languages' ) as $field ) {
			if ( isset( $row[ $field ] ) && is_array( $row[ $field ] ) && in_array( $language, array_map( 'strval', $row[ $field ] ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Posts and terms a row is about.
	 *
	 * Uses the row's own ID fields, then the IDs inside its ignore key, whose
	 * formats DUPCAP_Ignored_Issues defines.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $row Raw row.
	 * @return array{posts: int[], terms: int[]}
	 */
	public static function row_objects( array $row ) {
		$posts = array();
		$terms = array();

		$is_term = ! empty( $row['post_type'] ) && taxonomy_exists( (string) $row['post_type'] );

		if ( ! empty( $row['post_id'] ) ) {
			if ( $is_term ) {
				$terms[] = (int) $row['post_id'];
			} else {
				$posts[] = (int) $row['post_id'];
			}
		}

		foreach ( array( 'template_id', 'translation_id' ) as $field ) {
			if ( ! empty( $row[ $field ] ) ) {
				$posts[] = (int) $row[ $field ];
			}
		}

		foreach ( self::row_keys( $row ) as $key ) {
			if ( preg_match( '/^(?:content_missing|content_draft):(\d+):/', $key, $m )
				|| preg_match( '/^content:(?:identical|link|source_updated):(\d+):/', $key, $m )
				|| preg_match( '/^el:[a-z0-9_]+:(\d+):/', $key, $m ) ) {
				$posts[] = (int) $m[1];
			} elseif ( preg_match( '/^term_missing:[a-z0-9_-]+:(\d+):/', $key, $m ) ) {
				$terms[] = (int) $m[1];
			}
		}

		return array(
			'posts' => array_values( array_unique( array_filter( $posts ) ) ),
			'terms' => array_values( array_unique( array_filter( $terms ) ) ),
		);
	}

	/**
	 * Checks that passed in the last scan.
	 *
	 * @since 1.1.0
	 * @return array<int, array<string, string>>
	 */
	public static function passed() {
		$context = Report_Service::context();
		$passed  = array();

		foreach ( (array) ( $context['passed_checks'] ?? array() ) as $check ) {
			$type     = (string) ( $check['dedupe_key'] ?? $check['code'] ?? '' );
			$passed[] = array(
				'issue_key' => $type,
				'title'     => (string) ( $check['title'] ?? '' ),
				'section'   => Report_Service::section( \DUPCAP_Issue_Registry::section( $type ) ),
				'severity'  => 'pass',
				'message'   => (string) ( $check['message'] ?? '' ),
			);
		}

		return $passed;
	}

	/**
	 * Issues the user chose to ignore.
	 *
	 * The report drops ignored rows when it is read, so only the key, its
	 * section and when it was ignored are known.
	 *
	 * @since 1.1.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function ignored() {
		$ignored = array();

		foreach ( \DUPCAP_Ignored_Issues::get_all() as $key => $meta ) {
			$meta      = is_array( $meta ) ? $meta : array();
			$section   = \DUPCAP_Ignored_Issues::get_section_for_key( (string) $key );
			$ignored[] = array(
				'ignore_key' => (string) $key,
				'section'    => '' === $section ? null : Report_Service::section( $section ),
				'reason'     => isset( $meta['reason'] ) ? (string) $meta['reason'] : '',
				'ignored_at' => ! empty( $meta['ignored_at'] ) ? gmdate( 'c', (int) $meta['ignored_at'] ) : null,
			);
		}

		return $ignored;
	}

	/**
	 * Issue from a dashboard issue group.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $group    Group from build_issue_groups().
	 * @param bool                 $priority Whether it is in Fix These First.
	 * @return array<string, mixed>
	 */
	private static function from_group( array $group, $priority ) {
		$type       = (string) ( $group['dedupe_key'] ?? '' );
		$definition = '' !== $type ? \DUPCAP_Issue_Registry::get( $type ) : null;
		$definition = is_array( $definition ) ? $definition : array();

		return array(
			'issue_key'          => $type,
			'type'               => $type,
			'section'            => Report_Service::section( (string) ( $definition['section'] ?? 'other' ) ),
			'severity'           => Report_Service::severity( (string) ( $group['severity'] ?? 'info' ) ),
			'priority'           => (bool) $priority,
			'title'              => (string) ( $group['title'] ?? '' ),
			'message'            => (string) ( $group['message'] ?? '' ),
			'why'                => (string) ( $definition['why'] ?? '' ),
			'howto'              => (string) ( $definition['howto'] ?? '' ),
			'effort'             => (string) ( $definition['effort'] ?? '' ),
			'owner'              => (string) ( $definition['owner'] ?? '' ),
			'count'              => (int) ( $group['count'] ?? 0 ),
			'affected_languages' => array_values( array_map( 'strval', (array) ( $group['affected_languages'] ?? array() ) ) ),
			'action_url'         => (string) ( $group['action_url'] ?? '' ),
			'action_label'       => (string) ( $group['action_label'] ?? '' ),
			'rows'               => array_values( array_filter( (array) ( $group['details'] ?? array() ), 'is_array' ) ),
			'rows_truncated'     => ! empty( $group['details_truncated'] ),
		);
	}

	/**
	 * Issue from an Elementor insight bucket.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $bucket Bucket from DUPCAP_Elementor_Insights.
	 * @return array<string, mixed>
	 */
	private static function from_bucket( array $bucket ) {
		$key      = (string) ( $bucket['key'] ?? 'other' );
		$rows     = array_values( array_filter( (array) ( $bucket['examples'] ?? array() ), 'is_array' ) );
		$langs    = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['language'] ) ) {
				$langs[] = (string) $row['language'];
			}
		}

		$howto = \DUPCAP_Issue_Registry::elementor_field( $key, 'howto' );

		return array(
			'issue_key'          => 'elementor:' . $key,
			'type'               => 'elementor:' . $key,
			'section'            => 'elementor',
			'severity'           => Report_Service::severity( (string) ( $bucket['severity'] ?? 'warning' ) ),
			'priority'           => false,
			'title'              => (string) ( $bucket['title'] ?? '' ),
			'message'            => (string) ( $bucket['message'] ?? '' ),
			'why'                => \DUPCAP_Issue_Registry::elementor_field( $key, 'message' ),
			'howto'              => $howto,
			'effort'             => '',
			'owner'              => '',
			'count'              => (int) ( $bucket['count'] ?? count( $rows ) ),
			'affected_languages' => array_values( array_unique( $langs ) ),
			'action_url'         => (string) ( $bucket['action_url'] ?? '' ),
			'action_label'       => (string) ( $bucket['action_label'] ?? '' ),
			'rows'               => $rows,
			'rows_truncated'     => (int) ( $bucket['count'] ?? 0 ) > count( $rows ),
		);
	}
}
