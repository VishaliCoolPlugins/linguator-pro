<?php
/**
 * Posts, terms and languages as the abilities describe them.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Inspector\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site facts several abilities need: the languages, one post or term in a
 * fixed shape, and which post or term a front-end URL opens.
 *
 * Read straight from WordPress and Polylang, so it works without a scan.
 *
 * @since 1.1.0
 */
final class Content_Service {

	/**
	 * Polylang's languages, keyed by slug.
	 *
	 * expected_html_lang is the <html lang> value Polylang prints for pages in
	 * that language.
	 *
	 * @since 1.1.0
	 * @return array<string, array<string, string>>
	 */
	public static function languages() {
		$languages = array();

		foreach ( (array) PLL()->model->get_languages_list() as $lang ) {
			$locale = (string) $lang->locale;
			$w3c    = method_exists( $lang, 'get_prop' ) ? (string) $lang->get_prop( 'w3c' ) : '';

			$languages[ (string) $lang->slug ] = array(
				'slug'               => (string) $lang->slug,
				'name'               => (string) $lang->name,
				'locale'             => $locale,
				'expected_html_lang' => '' !== $w3c ? $w3c : str_replace( '_', '-', $locale ),
				'home_url'           => method_exists( $lang, 'get_home_url' ) ? (string) $lang->get_home_url() : (string) pll_home_url( (string) $lang->slug ),
			);
		}

		return $languages;
	}

	/**
	 * A post as the AI sees it.
	 *
	 * @since 1.1.0
	 * @param int    $post_id Post ID.
	 * @param string $slug    Its language, when already known.
	 * @return array<string, mixed>
	 */
	public static function describe_post( $post_id, $slug = '' ) {
		$post = get_post( $post_id );

		return array(
			'id'        => (int) $post_id,
			'type'      => $post ? $post->post_type : '',
			'title'     => $post ? get_the_title( $post ) : '',
			'status'    => $post ? $post->post_status : '',
			'language'  => '' !== $slug ? $slug : ( function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id ) : '' ),
			'url'       => $post ? (string) get_permalink( $post ) : '',
			'edit_url'  => (string) get_edit_post_link( $post_id, 'raw' ),
			'elementor' => self::is_elementor( (int) $post_id ),
		);
	}

	/**
	 * A term as the AI sees it.
	 *
	 * @since 1.1.0
	 * @param int    $term_id Term ID.
	 * @param string $slug    Its language, when already known.
	 * @return array<string, mixed>
	 */
	public static function describe_term( $term_id, $slug = '' ) {
		$term = get_term( $term_id );
		$ok   = $term instanceof \WP_Term;
		$link = $ok ? get_term_link( $term ) : '';

		return array(
			'id'       => (int) $term_id,
			'taxonomy' => $ok ? $term->taxonomy : '',
			'title'    => $ok ? $term->name : '',
			'language' => '' !== $slug ? $slug : ( function_exists( 'pll_get_term_language' ) ? (string) pll_get_term_language( $term_id ) : '' ),
			'url'      => is_string( $link ) ? $link : '',
			'edit_url' => $ok ? (string) get_edit_term_link( $term_id, $term->taxonomy ) : '',
		);
	}

	/**
	 * Whether a post is built with Elementor.
	 *
	 * @since 1.1.0
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_elementor( $post_id ) {
		return '' !== (string) get_post_meta( $post_id, '_elementor_edit_mode', true );
	}

	/**
	 * The post or term a front-end URL opens.
	 *
	 * @since 1.1.0
	 * @param string $url Front-end URL, in any language.
	 * @return array{kind: string, id: int}|WP_Error
	 */
	public static function resolve_url( $url ) {
		$url = esc_url_raw( (string) $url );

		$post_id = (int) url_to_postid( $url );
		if ( $post_id <= 0 && trailingslashit( $url ) === trailingslashit( home_url() ) ) {
			$post_id = (int) get_option( 'page_on_front' );
		}
		if ( $post_id > 0 ) {
			return array(
				'kind' => 'post',
				'id'   => $post_id,
			);
		}

		$term = self::term_from_url( $url );
		if ( $term instanceof \WP_Term ) {
			return array(
				'kind' => 'term',
				'id'   => (int) $term->term_id,
			);
		}

		if ( self::is_language_home( $url ) ) {
			return new WP_Error(
				'dupcap_url_is_posts_home',
				__( 'This is a home page that lists the latest posts, not a single post or page, so there is nothing to inspect here. Homepage problems are reported by translation-inspector/get-issues (issue homepage_translations); live URL problems by get-report type "urls".', 'duplicate-content-addon-for-polylang' ),
				array( 'status' => 404 )
			);
		}

		return new WP_Error(
			'dupcap_url_not_resolved',
			__( 'This URL does not open a post, page or term on this site. It may be an archive, a 404 or another site. To test URLs, call translation-inspector/run-scan with scope "urls".', 'duplicate-content-addon-for-polylang' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Whether a URL is the home page of the site or of one of its languages.
	 *
	 * @since 1.1.0
	 * @param string $url Front-end URL.
	 * @return bool
	 */
	private static function is_language_home( $url ) {
		$homes = array( home_url( '/' ) );

		if ( function_exists( 'pll_home_url' ) && function_exists( 'pll_languages_list' ) ) {
			foreach ( (array) pll_languages_list( array( 'fields' => 'slug' ) ) as $slug ) {
				$homes[] = pll_home_url( (string) $slug );
			}
		}

		foreach ( $homes as $home ) {
			if ( untrailingslashit( (string) $home ) === untrailingslashit( $url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Term whose archive a URL opens, matched by its last path segment.
	 *
	 * @since 1.1.0
	 * @param string $url Front-end URL.
	 * @return \WP_Term|null
	 */
	private static function term_from_url( $url ) {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return null;
		}

		$slug       = sanitize_title( basename( $path ) );
		$taxonomies = function_exists( 'PLL' ) && isset( PLL()->model ) ? (array) PLL()->model->get_translated_taxonomies() : array( 'category', 'post_tag' );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'slug'       => $slug,
					'hide_empty' => false,
					'lang'       => '',
				)
			);
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( is_string( $link ) && untrailingslashit( $link ) === untrailingslashit( $url ) ) {
						return $term;
					}
				}
			}
		}

		return null;
	}
}
