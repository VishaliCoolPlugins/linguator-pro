<?php
/**
 * Translated URL slug checks.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Modules\Scan\Abstract_Check;
use LinguatorPro\Modules\Scan\Http_Probe;
use LinguatorPro\Modules\Scan\Result;

/**
 * Finds translations whose slug is the source slug with a number added, such as "about-us-2".
 *
 * WordPress adds the number when a translation is given the same slug as its source. Visitors then see an
 * address that looks like a duplicate. Each address is requested to see whether it loads.
 *
 * @since 2.3.0
 */
class Url_Permalinks extends Abstract_Check {

	/**
	 * Slugs inspected.
	 */
	const SLUG_LIMIT = 40;

	/**
	 * @var string
	 */
	protected $group = 'URLs & Routing';

	/**
	 * @var string
	 */
	protected $section = 'urls';

	/**
	 * Runs the check.
	 *
	 * @since 2.3.0
	 *
	 * @return Result[]
	 */
	public function run(): array {
		if ( count( $this->active_languages() ) < 2 || ! $this->wants( 'translated_permalink_review' ) ) {
			return array();
		}

		$rows = $this->suffixed_slugs();
		if ( empty( $rows ) ) {
			return array();
		}

		$responses = Http_Probe::fetch_many( array_column( $rows, 'permalink', 'id' ) );
		$problems  = 0;
		$details   = array();

		foreach ( $rows as $row ) {
			$response = $responses[ $row['id'] ] ?? array( 'code' => 0, 'location' => '', 'error' => '' );
			$code     = (int) $response['code'];
			$loads    = $code >= 200 && $code < 300;
			$problem  = $code > 0 && ! $loads;
			$problems += $problem ? 1 : 0;

			$status = 0 === $code
				? __( 'Not tested.', 'linguator-pro' )
				: ( $loads
					? __( 'The address loads (HTTP 200).', 'linguator-pro' )
					: sprintf(
						/* translators: %d: HTTP status code */
						__( 'The address does not load (HTTP %d).', 'linguator-pro' ),
						$code
					) );

			$details[] = array(
				'label'        => $row['title'] . ' · ' . $row['language_name'],
				'message'      => sprintf(
					/* translators: 1: slug, 2: source slug, 3: source language name, 4: load status */
					__( 'The slug "%1$s" looks like a duplicate of "%2$s" from %3$s. %4$s', 'linguator-pro' ),
					$row['slug'],
					$row['base'],
					$row['source_language'],
					$status
				),
				'action_url'   => $row['edit'],
				'action_label' => __( 'Review permalink', 'linguator-pro' ),
				'ignore_key'   => 'url:slug_suffix:' . $row['language'] . ':' . $row['id'],
				'language'     => $row['language'],
				'url'          => $row['permalink'],
				'source_url'   => $row['source_url'],
				'current_slug' => $row['slug'],
				'base_slug'    => $row['base'],
				'http_status'  => $code,
				'status_label' => $loads ? __( 'Page loads correctly', 'linguator-pro' ) : $status,
			);
		}

		return array(
			$this->result(
				'translated_permalink_review',
				$problems > 0 ? Result::WARNING : Result::INFO,
				$problems > 0
					? __( 'Translated slugs with a problem', 'linguator-pro' )
					: __( 'Translated slugs to review', 'linguator-pro' ),
				sprintf(
					/* translators: %d: number of translated pages */
					_n(
						'%d translation has a slug that is the source slug with a number added. Give it its own translated slug so the address reads naturally.',
						'%d translations have a slug that is the source slug with a number added. Give them their own translated slugs so the addresses read naturally.',
						count( $details ),
						'linguator-pro'
					),
					count( $details )
				)
			)->with_details( $details ),
		);
	}

	/**
	 * Published translations whose slug ends in a number and whose slug without it belongs to another language.
	 *
	 * @since 2.3.0
	 *
	 * @return array[]
	 */
	private function suffixed_slugs(): array {
		global $wpdb;

		$types = $this->translated_post_types();
		if ( empty( $types ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// Bounded diagnostic read during a manual scan; not worth caching.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_name, post_title, post_type FROM {$wpdb->posts}
				WHERE post_status = 'publish' AND post_type IN ({$placeholders}) AND post_name REGEXP '-[0-9]+$'
				ORDER BY ID DESC LIMIT %d",
				array_merge( $types, array( self::SLUG_LIMIT ) )
			)
		);
		// phpcs:enable

		$rows = array();
		foreach ( (array) $candidates as $candidate ) {
			$base = (string) preg_replace( '/-\d+$/', '', (string) $candidate->post_name );
			$lang = $this->model->post->get_language( (int) $candidate->ID );
			if ( '' === $base || $base === $candidate->post_name || ! $lang ) {
				continue;
			}

			$source = get_posts(
				array(
					'name'             => $base,
					'post_type'        => $candidate->post_type,
					'post_status'      => 'publish',
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- The scan must see every language, so Linguator's language query filters are bypassed.
					'lang'             => '',
				)
			);
			if ( empty( $source ) ) {
				continue;
			}

			$source_lang = $this->model->post->get_language( (int) $source[0] );
			if ( ! $source_lang || $source_lang->slug === $lang->slug ) {
				continue;
			}

			$rows[] = array(
				'id'              => (int) $candidate->ID,
				'slug'            => (string) $candidate->post_name,
				'base'            => $base,
				'title'           => '' !== $candidate->post_title ? (string) $candidate->post_title : __( '(no title)', 'linguator-pro' ),
				'language'        => $lang->slug,
				'language_name'   => (string) $lang->name,
				'source_language' => (string) $source_lang->name,
				'permalink'       => (string) get_permalink( (int) $candidate->ID ),
				'source_url'      => (string) get_permalink( (int) $source[0] ),
				'edit'            => (string) get_edit_post_link( (int) $candidate->ID, 'url' ),
			);
		}

		return $rows;
	}
}
