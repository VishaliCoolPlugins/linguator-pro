<?php
/**
 * Duplicate slug checks.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Modules\Scan\Abstract_Check;
use LinguatorPro\Modules\Scan\Result;

/**
 * Finds published post slugs shared by posts in more than one language.
 *
 * @since 2.3.0
 */
class Url_Slugs extends Abstract_Check {

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
		if ( count( $this->active_languages() ) < 2 || ! $this->wants( 'url_dupe_slugs' ) ) {
			return array();
		}

		$types = $this->translated_post_types();
		if ( empty( $types ) ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// Bounded diagnostic read during a manual scan; not worth caching.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$slugs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_name FROM {$wpdb->posts}
				WHERE post_status = 'publish' AND post_type IN ({$placeholders}) AND post_name <> ''
				GROUP BY post_name
				HAVING COUNT(*) > 1
				LIMIT %d",
				array_merge( $types, array( self::DETAIL_LIMIT + 1 ) )
			)
		);
		// phpcs:enable

		$details = array();
		foreach ( (array) $slugs as $slug ) {
			$ids = get_posts(
				array(
					'name'             => $slug,
					'post_type'        => $types,
					'post_status'      => 'publish',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'lang'             => '',
					'suppress_filters' => true,
				)
			);

			// The same slug in one language is WordPress's own concern; here only a cross-language clash matters.
			$languages = array();
			$posts     = array();
			foreach ( $ids as $id ) {
				$lang        = $this->model->post->get_language( (int) $id );
				$languages[] = $lang ? $lang->slug : '';

				$title   = get_the_title( $id );
				$posts[] = array(
					'title'        => '' !== $title ? $title : sprintf(
						/* translators: %d: post ID */
						__( 'Post #%d', 'linguator-pro' ),
						$id
					),
					'language'     => $lang ? $lang->name : __( 'No language', 'linguator-pro' ),
					'action_url'   => (string) get_edit_post_link( $id, 'url' ),
					'action_label' => __( 'Edit', 'linguator-pro' ),
				);
			}
			if ( count( array_unique( $languages ) ) < 2 ) {
				continue;
			}

			$details[] = array(
				'label'      => sprintf(
					/* translators: %s: slug */
					__( 'Conflicting slug: "%s"', 'linguator-pro' ),
					$slug
				),
				'message'    => sprintf(
					/* translators: %d: number of posts */
					__( 'Shared by %d items across languages.', 'linguator-pro' ),
					count( $ids )
				),
				'posts'      => $posts,
				'ignore_key' => 'url:dupe_slug:' . $slug,
			);
		}

		if ( empty( $details ) ) {
			return array(
				$this->result(
					'url_dupe_slugs',
					Result::PASS,
					__( 'No conflicting slugs', 'linguator-pro' ),
					__( 'No duplicate slug conflicts detected.', 'linguator-pro' )
				),
			);
		}

		return array(
			$this->result(
				'url_dupe_slugs',
				Result::WARNING,
				__( 'Duplicate slugs found', 'linguator-pro' ),
				sprintf(
					/* translators: %d: number of slugs */
					_n( '%d slug is shared by posts in different languages, which can break URLs.', '%d slugs are shared by posts in different languages, which can break URLs.', count( $details ), 'linguator-pro' ),
					count( $details )
				)
			)->with_details( array_slice( $details, 0, self::DETAIL_LIMIT ), count( $details ) > self::DETAIL_LIMIT ),
		);
	}
}
