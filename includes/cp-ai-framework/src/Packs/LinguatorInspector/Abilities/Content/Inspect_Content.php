<?php
/**
 * Inspect Content ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Content;

use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Content_Service;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Inspector_Guard;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Issue_Service;
use LinguatorPro\Modules\Scan\Scanner;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inspect Content.
 *
 * @since 1.2.0
 */
final class Inspect_Content extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'inspect-content';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Inspect Content', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator Site Scan: everything about one post, page, Elementor template, product or term and its translations: its language, which languages have a translation (with status and edit link) and which do not, and every issue the saved report records for any of them, such as missing, unpublished or outdated translations, identical copied text, links to the wrong language, WooCommerce product differences and Elementor widget problems. Look it up by post_id, term_id or a front-end url. Use it when the user asks about a specific page ("why does /fr/contact show English?").', 'linguator-pro' );
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
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Post, page, product or Elementor template ID, in any language.', 'linguator-pro' ),
				),
				'term_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Category, tag or other term ID, in any language.', 'linguator-pro' ),
				),
				'url'     => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'A front-end URL on this site, in any language.', 'linguator-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param mixed $input Validated input, or null.
	 * @return bool True when permitted.
	 */
	public static function check_permission( $input = array() ) {
		$input = self::input_array( $input );

		if ( ! parent::check_permission( $input ) ) {
			return false;
		}

		if ( ! empty( $input['post_id'] ) && get_post( (int) $input['post_id'] ) ) {
			return current_user_can( 'edit_post', (int) $input['post_id'] );
		}

		if ( ! empty( $input['term_id'] ) && get_term( (int) $input['term_id'] ) instanceof \WP_Term ) {
			return current_user_can( 'edit_term', (int) $input['term_id'] );
		}

		return true;
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

		if ( ! Inspector_Guard::engine_ready() ) {
			return Inspector_Guard::engine_error();
		}

		$target = self::resolve( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$is_term = 'term' === $target['kind'];
		$id      = $target['id'];

		$group = $is_term ? (array) linguator_get_term_translations( $id ) : (array) linguator_get_post_translations( $id );
		if ( empty( $group ) ) {
			$group = array( '' => $id );
		}

		$translations = array();
		foreach ( $group as $slug => $object_id ) {
			$translations[] = $is_term ? Content_Service::describe_term( (int) $object_id, (string) $slug ) : Content_Service::describe_post( (int) $object_id, (string) $slug );
		}

		$languages = array_keys( Content_Service::languages() );
		$missing   = array_values( array_diff( $languages, array_map( 'strval', array_keys( $group ) ) ) );

		$result = array(
			'object'            => $is_term ? Content_Service::describe_term( $id, '' ) : Content_Service::describe_post( $id, '' ),
			'translations'      => $translations,
			'missing_languages' => $missing,
		);

		if ( null === Scanner::get_saved_report() ) {
			$result['issues']    = array();
			$result['next_step'] = __( 'The site has not been scanned yet, so no issues are recorded for this content. Call linguator-inspector/run-scan to check it.', 'linguator-pro' );
			return $result;
		}

		$ids    = array_map( 'intval', array_values( $group ) );
		$issues = array();
		foreach ( Issue_Service::all() as $issue ) {
			$rows = array();
			foreach ( $issue['rows'] as $row ) {
				$objects = Issue_Service::row_objects( $row );
				if ( array_intersect( $ids, $is_term ? $objects['terms'] : $objects['posts'] ) ) {
					$rows[] = Issue_Service::present_row( $row, $issue['type'] );
				}
			}

			if ( empty( $rows ) ) {
				continue;
			}

			$summary         = Issue_Service::summarize( $issue, 0 );
			$summary['rows'] = $rows;
			unset( $summary['count'], $summary['rows_total'] );
			$issues[] = $summary;
		}

		$result['issues']    = $issues;
		$result['next_step'] = empty( $issues )
			? ( empty( $missing )
				? __( 'The saved report records no issue for this content, and every language has a translation.', 'linguator-pro' )
				: __( 'The saved report records no issue for this content, but missing_languages have no translation. If they should, create them (linguator/translate-post when the Linguator translation abilities are active).', 'linguator-pro' ) )
			: __( 'Fix each row with its fix.input, or follow the issue fix. Then confirm with linguator-inspector/recheck-issue and this post_id.', 'linguator-pro' );

		return $result;
	}

	/**
	 * The post or term the input points at.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Ability input.
	 * @return array{kind: string, id: int}|WP_Error
	 */
	private static function resolve( array $input ) {
		if ( ! empty( $input['post_id'] ) ) {
			$post = get_post( (int) $input['post_id'] );
			return $post
				? array(
					'kind' => 'post',
					'id'   => (int) $post->ID,
				)
				: self::not_found();
		}

		if ( ! empty( $input['term_id'] ) ) {
			$term = get_term( (int) $input['term_id'] );
			return $term instanceof \WP_Term
				? array(
					'kind' => 'term',
					'id'   => (int) $term->term_id,
				)
				: self::not_found();
		}

		if ( ! empty( $input['url'] ) ) {
			return Content_Service::resolve_url( (string) $input['url'] );
		}

		return new WP_Error(
			'lmat_content_target_missing',
			__( 'Pass post_id, term_id or url.', 'linguator-pro' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Error for an ID that points at nothing.
	 *
	 * @since 1.2.0
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error(
			'lmat_content_not_found',
			__( 'No post or term has this ID.', 'linguator-pro' ),
			array( 'status' => 404 )
		);
	}
}
