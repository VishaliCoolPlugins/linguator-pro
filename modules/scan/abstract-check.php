<?php
/**
 * Base class for all site scan checks.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Includes\Other\Linguator_Model;

/**
 * Provides safe access to Linguator internals so individual checks stay short.
 *
 * @since 2.3.0
 */
abstract class Abstract_Check {

	/**
	 * Category label shown in the UI.
	 *
	 * @var string
	 */
	protected $group = '';

	/**
	 * Report section the check feeds: content, urls or site.
	 *
	 * @var string
	 */
	protected $section = 'site';

	/**
	 * When set, the check only produces the result with this id.
	 *
	 * @var string|null
	 */
	protected $only = null;

	/**
	 * Post query batch size. Full checks continue through every batch.
	 */
	const POST_LIMIT = 500;

	/**
	 * Detail rows kept per result.
	 */
	const DETAIL_LIMIT = 50;
	protected $row_keys = array();

	public function scope_rows( array $keys ): void {
		$this->row_keys = $keys;
	}

	/**
	 * All published default-language posts of one type, newest first.
	 *
	 * @since 2.3.0
	 *
	 * @param string $type         Post type.
	 * @param object $default_lang Default language.
	 * @return int[]
	 */
	protected function default_language_post_ids( string $type, $default_lang ): array {
		return array_map(
			'intval',
			$this->posts_in_batches(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'fields'           => 'ids',
					'posts_per_page'   => self::POST_LIMIT,
					'orderby'          => 'ID',
					'order'            => 'DESC',
					'no_found_rows'    => true,
					'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- The scan must see every language, so Linguator's language query filters are bypassed.
					'lang'             => '',
					'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => 'lmat_language',
							'field'    => 'term_id',
							'terms'    => (int) $default_lang->term_id,
						),
					),
				)
			)
		);
	}

	/** Read all matching posts in bounded queries rather than skipping older documents. */
	protected function posts_in_batches( array $args ): array {
		$size = max( 1, (int) ( $args['posts_per_page'] ?? self::POST_LIMIT ) );
		$args['posts_per_page'] = $size;
		$args['paged'] = 1;
		$all = array();
		do {
			$batch = get_posts( $args );
			$all = array_merge( $all, $batch );
			++$args['paged'];
		} while ( count( $batch ) === $size );
		return $all;
	}

	/**
	 * Name of a language, or its slug in capitals when it is unknown.
	 *
	 * @since 2.3.0
	 *
	 * @param string $slug Language slug.
	 * @return string
	 */
	protected function language_name( string $slug ): string {
		$lang = $this->model->get_language( $slug );

		return $lang && ! empty( $lang->name ) ? (string) $lang->name : strtoupper( $slug );
	}

	/**
	 * Linguator model.
	 *
	 * @var Linguator_Model
	 */
	protected $model;

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
	 * Runs the check.
	 *
	 * @since 2.3.0
	 *
	 * @return Result[]
	 */
	abstract public function run(): array;

	/**
	 * Report section the check feeds.
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 */
	public function section(): string {
		return $this->section;
	}

	/**
	 * Limits the next run to one result id so a single issue can be rechecked cheaply.
	 *
	 * @since 2.3.0
	 *
	 * @param string|null $id Result id, or null for everything.
	 * @return void
	 */
	public function only( ?string $id ): void {
		$this->only = $id;
	}

	/**
	 * Whether the check should produce a result id under the current `only` filter.
	 *
	 * @since 2.3.0
	 *
	 * @param string $id Result id.
	 * @return bool
	 */
	protected function wants( string $id ): bool {
		return null === $this->only || $this->only === $id;
	}

	/**
	 * Extra report data the check collected during run(), such as language coverage.
	 *
	 * @since 2.3.0
	 *
	 * @return array
	 */
	public function extra(): array {
		return array();
	}

	/**
	 * Active languages, excluding languages the site owner deactivated.
	 *
	 * Reporting a missing translation for a deliberately deactivated language is a false positive.
	 *
	 * @since 2.3.0
	 *
	 * @return \LinguatorPro\Includes\Other\Linguator_Language[]
	 */
	protected function active_languages(): array {
		$list = $this->model->get_languages_list();
		if ( ! is_array( $list ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$list,
				static function ( $lang ) {
					return is_object( $lang ) && ( ! isset( $lang->active ) || $lang->active );
				}
			)
		);
	}

	/**
	 * Default language slug.
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 */
	protected function default_lang(): string {
		$default = $this->model->get_default_language();
		return $default ? (string) $default->slug : '';
	}

	/**
	 * Reads a Linguator option.
	 *
	 * @since 2.3.0
	 *
	 * @param string $key           Option name.
	 * @param mixed  $default_value Value returned when the option is missing.
	 * @return mixed
	 */
	protected function option( string $key, $default_value = null ) {
		try {
			$value = $this->model->options->get( $key );
		} catch ( \Throwable $e ) {
			return $default_value;
		}

		return null === $value ? $default_value : $value;
	}

	/**
	 * Language label such as "French (fr)".
	 *
	 * @since 2.3.0
	 *
	 * @param object $lang Language object.
	 * @return string
	 */
	protected function lang_label( $lang ): string {
		return sprintf(
			/* translators: 1: language name, 2: language code */
			__( '%1$s (%2$s)', 'linguator-pro' ),
			$lang->name,
			$lang->slug
		);
	}

	/**
	 * Post types translated by Linguator that a site owner can manage.
	 *
	 * Non-public types (patterns, templates, font faces…) are not content anyone translates by hand.
	 *
	 * @since 2.3.0
	 *
	 * @return string[]
	 */
	protected function translated_post_types(): array {
		$types = $this->model->get_translated_post_types();
		if ( ! is_array( $types ) ) {
			return array();
		}

		// Elementor templates are layouts, not content a visitor reads in a language.
		$types = array_diff( $types, array( 'elementor_library' ) );

		$out = array();
		foreach ( $types as $type ) {
			$object = get_post_type_object( (string) $type );
			if ( $object && ! empty( $object->public ) && ! empty( $object->show_ui ) ) {
				$out[] = (string) $type;
			}
		}

		return $out;
	}

	/**
	 * URL that starts a new translation of a post, as the post list "+" icon does.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $post_id   Source post ID.
	 * @param string $lang_slug Target language slug.
	 * @return string Empty when the post does not exist.
	 */
	protected function new_translation_url( int $post_id, string $lang_slug ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		// Not wp_nonce_url(): it HTML-escapes the URL, and the "&amp;" would reach the server as a literal parameter name.
		return add_query_arg(
			array(
				'post_type' => $post->post_type,
				'from_post' => $post_id,
				'new_lang'  => sanitize_key( $lang_slug ),
				'_wpnonce'  => wp_create_nonce( 'new-post-translation' ),
			),
			admin_url( 'post-new.php' )
		);
	}

	/**
	 * Builds a result.
	 *
	 * @since 2.3.0
	 *
	 * @param string $id      Unique id.
	 * @param string $status  One of the Result status constants.
	 * @param string $label   Title.
	 * @param string $message Explanation.
	 * @return Result
	 */
	protected function result( string $id, string $status, string $label, string $message = '' ): Result {
		$result          = new Result( $id, $status, $this->group, $label, $message );
		$result->section = $this->section;
		return $result;
	}
}
