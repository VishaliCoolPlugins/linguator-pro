<?php
/**
 * Issues from the saved report, shaped for the abilities.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorInspector\Services;

use LinguatorPro\Modules\Scan\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the Site Scan's prepared report and turns it into one list of issues
 * with stable keys, a fixed order and the same copy the admin screen shows.
 *
 * Nothing here re-checks the site: every row comes from the saved report, so
 * the tools always agree with the Site Scan screen. The only live additions are
 * two things the screen shows outside the report: content with no language and
 * post types that are not translatable.
 *
 * An issue_key is the scan result id (for example "content_page" or
 * "string_fr"). The issue type groups result ids into families (for example
 * "content_missing") and decides how an issue is fixed.
 *
 * @since 1.2.0
 */
final class Issue_Service {

	/**
	 * Row fields passed to the AI. Everything else in a row is display markup.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const ROW_FIELDS = array(
		'ignore_key',
		'label',
		'subtitle',
		'message',
		'post_id',
		'term_id',
		'translation_id',
		'post_type',
		'language',
		'location',
		'location_label',
		'template_type',
		'layout',
		'edit_url',
		'action_url',
		'action_label',
	);

	/**
	 * Longest text passed for one row field.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MAX_TEXT = 300;

	/**
	 * Section order after the priority issues, as the Site Scan screen uses.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const SECTION_ORDER = array( 'content', 'elementor', 'urls', 'site' );

	/**
	 * Issues for this request.
	 *
	 * @since 1.2.0
	 * @var array<int, array<string, mixed>>|null
	 */
	private static $cache = null;

	/**
	 * Drop the cached issues after the report changed.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function forget() {
		self::$cache = null;
	}

	/**
	 * Every open issue, most urgent first.
	 *
	 * Critical issues come first; then the rest by section (content,
	 * elementor, urls, site) and severity.
	 *
	 * @since 1.2.0
	 * @return array<int, array<string, mixed>> Issues with their raw rows.
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$context = Report_Service::context();
		$issues  = array();

		$nolang = self::nolang_issue();
		if ( null !== $nolang ) {
			$issues[] = $nolang;
		}

		foreach ( (array) $context['results'] as $result ) {
			if ( 'pass' === ( $result['status'] ?? 'pass' ) ) {
				continue;
			}
			$issues[] = self::from_result( (array) $result );
		}

		if ( is_array( $context['types_notice'] ) ) {
			$issues[] = self::from_result( $context['types_notice'] );
		}

		$severity_rank = array_flip( array( 'critical', 'warning', 'info', 'pass' ) );
		$section_rank  = array_flip( self::SECTION_ORDER );
		$position      = 0;

		foreach ( $issues as $index => $issue ) {
			$issues[ $index ]['_order'] = array(
				$issue['priority'] ? 0 : 1,
				$section_rank[ $issue['section'] ] ?? 9,
				$severity_rank[ $issue['severity'] ] ?? 9,
				$position++,
			);
		}

		usort(
			$issues,
			static function ( $a, $b ) {
				return $a['_order'] <=> $b['_order'];
			}
		);

		foreach ( $issues as $index => $issue ) {
			unset( $issues[ $index ]['_order'] );
		}

		self::$cache = $issues;

		return $issues;
	}

	/**
	 * One issue by its issue_key.
	 *
	 * @since 1.2.0
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
	 * @since 1.2.0
	 * @param string $ignore_key Row ignore key.
	 * @return array{issue: array<string, mixed>, row: array<string, mixed>}|null
	 */
	public static function find_row( $ignore_key ) {
		$ignore_key = (string) $ignore_key;

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
	 * Every key that can be ignored in the current report: row keys and the
	 * ids of whole issues (the Site Scan can dismiss either).
	 *
	 * @since 1.2.0
	 * @return array<string, true> Keys as array keys.
	 */
	public static function known_keys() {
		$keys = array();

		foreach ( self::all() as $issue ) {
			if ( ! $issue['synthetic'] ) {
				$keys[ $issue['issue_key'] ] = true;
			}

			foreach ( $issue['rows'] as $row ) {
				foreach ( self::row_keys( $row ) as $key ) {
					$keys[ $key ] = true;
				}
			}
		}

		return $keys;
	}

	/**
	 * Ignore keys on one row.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $row Raw row.
	 * @return string[]
	 */
	public static function row_keys( array $row ) {
		return ! empty( $row['ignore_key'] ) && is_string( $row['ignore_key'] ) ? array( $row['ignore_key'] ) : array();
	}

	/**
	 * Issue type (the family a result id belongs to).
	 *
	 * Follows the Site Scan's own grouping (Issue_Copy) and extends it to every
	 * post type, so "content_product" is a missing-translation issue like
	 * "content_page".
	 *
	 * @since 1.2.0
	 * @param string $id Scan result id.
	 * @return string
	 */
	public static function type_for( $id ) {
		$id = (string) $id;

		$exact = array(
			'lang_homepage'              => 'homepage_translations',
			'translated_permalink_review' => 'translated_permalinks',
			'content_source_updated'     => 'content_source_updated',
			'content_nolang'             => 'content_nolang',
			'menu_dead_links'            => 'menu_dead_links',
			'menu_none'                  => 'menu_none',
		);

		if ( isset( $exact[ $id ] ) ) {
			return $exact[ $id ];
		}

		$families = array(
			'content_unpublished_' => 'content_draft',
			'content_draft_'       => 'content_draft',
			'content_identical_'   => 'content_identical',
			'content_link_'        => 'content_links',
			'content_placeholder_' => 'content_placeholder',
			'content_acf_'         => 'content_acf',
			'term_missing_'        => 'term_missing',
			'string_'              => 'strings_missing',
			'url_config_'          => 'url_configuration',
			'url_route_'           => 'routing_health',
			'nav_'                 => 'navigation',
			'menu_'                => 'menu_assignments',
			'woo_variations_'      => 'woo_variations',
			'woo_attributes_'      => 'woo_attributes',
			'woo_fields_'          => 'woo_fields',
		);

		foreach ( $families as $prefix => $family ) {
			if ( 0 === strpos( $id, $prefix ) ) {
				return $family;
			}
		}

		if ( 0 === strpos( $id, 'content_' ) ) {
			return 'content_missing';
		}

		return $id;
	}

	/**
	 * Public summary of an issue, without its rows.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $issue   Issue from all().
	 * @param int                  $samples Number of example rows to include.
	 * @return array<string, mixed>
	 */
	public static function summarize( array $issue, $samples = 3 ) {
		$summary = array(
			'issue_key'          => $issue['issue_key'],
			'issue_type'         => $issue['type'],
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
	 * @since 1.2.0
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

			if ( is_string( $value ) ) {
				$value = wp_strip_all_tags( $value );
				if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > self::MAX_TEXT ) {
					$value = mb_substr( $value, 0, self::MAX_TEXT ) . '…';
				}
			} elseif ( ! is_scalar( $value ) ) {
				continue;
			}

			if ( in_array( $field, array( 'post_id', 'term_id', 'translation_id' ), true ) ) {
				$value = (int) $value;
			}

			$out[ $field ] = $value;
		}

		// Possible-duplicate rows list the posts involved.
		if ( ! empty( $row['posts'] ) && is_array( $row['posts'] ) ) {
			$out['related'] = array();
			foreach ( array_slice( $row['posts'], 0, 10 ) as $related ) {
				if ( is_array( $related ) ) {
					$out['related'][] = array(
						'title'    => wp_strip_all_tags( (string) ( $related['title'] ?? '' ) ),
						'language' => wp_strip_all_tags( (string) ( $related['language'] ?? '' ) ),
						'edit_url' => (string) ( $related['action_url'] ?? '' ),
					);
				}
			}
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
	 * @since 1.2.0
	 * @param array<string, mixed> $row      Issue or raw row.
	 * @param string               $language Language slug.
	 * @return bool
	 */
	public static function mentions_language( array $row, $language ) {
		$language = sanitize_key( (string) $language );

		if ( isset( $row['language'] ) && $language === (string) $row['language'] ) {
			return true;
		}

		if ( isset( $row['affected_languages'] ) && is_array( $row['affected_languages'] ) && in_array( $language, array_map( 'strval', $row['affected_languages'] ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Posts and terms a row is about.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $row Raw row.
	 * @return array{posts: int[], terms: int[]}
	 */
	public static function row_objects( array $row ) {
		$posts = array();
		$terms = array();

		foreach ( array( 'post_id', 'translation_id' ) as $field ) {
			if ( ! empty( $row[ $field ] ) ) {
				$posts[] = (int) $row[ $field ];
			}
		}

		if ( ! empty( $row['term_id'] ) ) {
			$terms[] = (int) $row['term_id'];
		}

		foreach ( self::row_keys( $row ) as $key ) {
			if ( preg_match( '/^url:numbered_copy:(\d+):(\d+)$/', $key, $m ) ) {
				$posts[] = (int) $m[1];
				$posts[] = (int) $m[2];
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
	 * @since 1.2.0
	 * @return array<int, array<string, string>>
	 */
	public static function passed() {
		$passed = array();

		foreach ( (array) Report_Service::context()['results'] as $result ) {
			if ( 'pass' !== ( $result['status'] ?? '' ) ) {
				continue;
			}

			$passed[] = array(
				'issue_key' => (string) $result['id'],
				'title'     => (string) ( $result['label'] ?? '' ),
				'section'   => Report_Service::section( (string) ( $result['section'] ?? '' ) ),
				'severity'  => 'pass',
				'message'   => (string) ( $result['message'] ?? '' ),
			);
		}

		return $passed;
	}

	/**
	 * Issues the user chose to ignore.
	 *
	 * The report drops ignored rows when it is read, so what is known is the
	 * key, its section, the issue and the row label.
	 *
	 * @since 1.2.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function ignored() {
		$details = array();

		foreach ( (array) Report_Service::context()['ignored_items'] as $item ) {
			$details[ (string) ( $item['key'] ?? '' ) ] = (array) $item;
		}

		$ignored = array();

		foreach ( Scanner::get_ignored() as $key ) {
			$item      = $details[ $key ] ?? array();
			$ignored[] = array(
				'ignore_key' => (string) $key,
				'section'    => isset( $item['section'] ) ? Report_Service::section( (string) $item['section'] ) : null,
				'issue'      => isset( $item['issue'] ) ? (string) $item['issue'] : '',
				'label'      => isset( $item['label'] ) ? wp_strip_all_tags( (string) $item['label'] ) : '',
				'language'   => isset( $item['language'] ) ? (string) $item['language'] : '',
			);
		}

		return $ignored;
	}

	/**
	 * Issue from a scan result.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $result Prepared result (with Issue_Copy fields).
	 * @return array<string, mixed>
	 */
	private static function from_result( array $result ) {
		$id       = (string) $result['id'];
		$type     = self::type_for( $id );
		$rows     = array_values( array_filter( (array) ( $result['details'] ?? array() ), 'is_array' ) );
		$language = array();
		$severity = Report_Service::severity( (string) ( $result['status'] ?? 'info' ) );

		foreach ( $rows as $row ) {
			if ( ! empty( $row['language'] ) ) {
				$language[] = (string) $row['language'];
			}
		}

		$count     = count( $rows );
		$truncated = ! empty( $result['details_truncated'] );
		$copy      = self::product_copy( $type );

		if ( null !== $copy ) {
			$result['why'] = $copy[0];
			$result['how'] = $copy[1];
		}

		if ( $truncated && preg_match( '/\d+/', (string) ( $result['message'] ?? '' ), $m ) ) {
			$count = max( $count, (int) $m[0] );
		}

		return array(
			'issue_key'          => $id,
			'type'               => $type,
			'synthetic'          => false,
			'section'            => Report_Service::section( (string) ( $result['section'] ?? '' ) ),
			'severity'           => $severity,
			'priority'           => 'critical' === $severity,
			'title'              => (string) ( $result['label'] ?? '' ),
			'message'            => (string) ( $result['message'] ?? '' ),
			'why'                => (string) ( $result['why'] ?? '' ),
			'howto'              => (string) ( $result['how'] ?? '' ),
			'effort'             => Fix_Router::effort( $type ),
			'count'              => $count,
			'affected_languages' => array_values( array_unique( $language ) ),
			'action_url'         => (string) ( $result['fix_url'] ?? '' ),
			'action_label'       => (string) ( $result['fix_label'] ?? '' ),
			'rows'               => $rows,
			'rows_truncated'     => $truncated,
		);
	}

	/**
	 * Why and how for the WooCommerce product issues, which the Site Scan has no shared copy for.
	 *
	 * @since 1.2.0
	 * @param string $type Issue type.
	 * @return string[]|null Why and how, or null when the scan's own copy applies.
	 */
	private static function product_copy( $type ) {
		switch ( $type ) {
			case 'woo_variations':
				return array(
					__( 'A translated variable product that lacks variations (or keeps copied descriptions) cannot be bought in that language.', 'linguator-pro' ),
					__( 'Sync the product from its original (linguator-inspector/fix-issue action sync_woocommerce_product), or open the translation and add the missing variations.', 'linguator-pro' ),
				);
			case 'woo_fields':
				return array(
					__( 'SKU, stock, tax, visibility and prices should match across the translations of a product, or customers see different offers per language.', 'linguator-pro' ),
					__( 'Sync the product from its original (linguator-inspector/fix-issue action sync_woocommerce_product). This overwrites those fields in the translations with the original\'s values.', 'linguator-pro' ),
				);
			case 'woo_attributes':
				return array(
					__( 'Custom product attributes that still match the original show untranslated names and values on the translated product page.', 'linguator-pro' ),
					__( 'Open the translated product and translate its custom attributes. This is a manual fix.', 'linguator-pro' ),
				);
		}

		return null;
	}

	/**
	 * The "content without a language" issue, checked live.
	 *
	 * Linguator refuses to scan while content has no language, so the saved
	 * report never lists it. The Site Scan screen offers an "assign default
	 * language" button instead; this issue is its counterpart.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed>|null Issue, or null when every item has a language.
	 */
	private static function nolang_issue() {
		$model = Inspector_Guard::model();

		if ( null === $model || ! Scanner::has_unassigned_content( $model ) ) {
			return null;
		}

		$types = array();
		foreach ( (array) $model->get_translated_post_types() as $type ) {
			$object = get_post_type_object( (string) $type );
			if ( $object && ! empty( $object->public ) && ! empty( $object->show_ui ) && 'elementor_library' !== $type ) {
				$types[] = (string) $type;
			}
		}

		$taxonomies = array_values( array_diff( (array) $model->get_translated_taxonomies(), array( 'nav_menu', 'link_category', 'post_format' ) ) );
		$post_ids   = $types ? (array) $model->get_posts_with_no_lang( $types, 50 ) : array();
		$term_ids   = $taxonomies ? (array) $model->get_terms_with_no_lang( $taxonomies, 50 ) : array();
		$rows       = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( $post && ! in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) && in_array( $post->post_type, $types, true ) ) {
				$rows[] = array(
					'label'     => '' !== get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'linguator-pro' ),
					'message'   => __( 'This item has no language.', 'linguator-pro' ),
					'post_id'   => (int) $post->ID,
					'post_type' => $post->post_type,
					'edit_url'  => (string) get_edit_post_link( (int) $post->ID, 'url' ),
				);
			}
		}

		foreach ( $term_ids as $term_id ) {
			$term = get_term( (int) $term_id );
			if ( $term instanceof \WP_Term && in_array( $term->taxonomy, $taxonomies, true ) ) {
				$rows[] = array(
					'label'     => $term->name,
					'message'   => __( 'This term has no language.', 'linguator-pro' ),
					'term_id'   => (int) $term->term_id,
					'post_type' => $term->taxonomy,
					'edit_url'  => (string) get_edit_term_link( (int) $term->term_id, $term->taxonomy ),
				);
			}
		}

		return array(
			'issue_key'          => 'content_nolang',
			'type'               => 'content_nolang',
			'synthetic'          => true,
			'section'            => 'content',
			'severity'           => 'critical',
			'priority'           => true,
			'title'              => __( 'Content without a language', 'linguator-pro' ),
			'message'            => __( 'Some posts, pages or terms have no language. Linguator will not scan until they do, and visitors cannot reach them in the right language.', 'linguator-pro' ),
			'why'                => __( 'Content with no language cannot be linked to translations and may show in every language or none.', 'linguator-pro' ),
			'howto'              => __( 'Assign the default language to this content (linguator-inspector/fix-issue assign_default_language), or open each item and pick its language.', 'linguator-pro' ),
			'effort'             => Fix_Router::effort( 'content_nolang' ),
			'count'              => count( $rows ),
			'affected_languages' => array(),
			'action_url'         => '',
			'action_label'       => '',
			'rows'               => $rows,
			'rows_truncated'     => count( $post_ids ) >= 50 || count( $term_ids ) >= 50,
		);
	}
}
