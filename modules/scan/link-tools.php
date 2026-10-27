<?php
/**
 * Helpers for judging internal links.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared by the checks that read links from content and menus. Needs `$this->model`.
 *
 * @since 2.3.0
 */
trait Link_Tools {

	/**
	 * Whether a URL points at this site (relative, or on the home host).
	 *
	 * @since 2.3.0
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_internal( string $url ): bool {
		if ( preg_match( '#^(mailto:|tel:|javascript:|sms:)#i', $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		if ( empty( $parts['host'] ) ) {
			return isset( $parts['path'] ) && '/' === substr( $parts['path'], 0, 1 );
		}

		$home = wp_parse_url( home_url() );

		return is_array( $home ) && ! empty( $home['host'] ) && strtolower( (string) $parts['host'] ) === strtolower( (string) $home['host'] );
	}

	/**
	 * Whether a URL is an uploaded file or other asset rather than a page.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_asset( string $url ): bool {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return (bool) preg_match( '#/wp-(content|includes|admin|json)/|\.(jpe?g|png|gif|webp|svg|ico|pdf|zip|docx?|xlsx?|pptx?|mp[34]|mov|avi|css|js|xml|txt)$#i', $path );
	}

	/**
	 * Comparable form of a URL: path only, no scheme, host, query-less trailing slash.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function normalize_url( string $url ): string {
		$parts = wp_parse_url( $url );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '/';
		$query = is_array( $parts ) && isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		return strtolower( '/' . trim( rawurldecode( $path ), '/' ) . $query );
	}

	/**
	 * Resolves an internal URL to a post ID, also when it carries a language prefix.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url URL.
	 * @return int
	 */
	private function resolve_post_id( string $url ): int {
		$absolute = 0 === strpos( $url, '/' ) ? home_url( $url ) : $url;
		$id       = (int) url_to_postid( $absolute );
		if ( $id > 0 ) {
			return $id;
		}

		$path = trim( (string) wp_parse_url( $absolute, PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}

		$segments = '' === $path ? array() : explode( '/', $path );
		if ( $segments && $this->model->get_language( $segments[0] ) ) {
			array_shift( $segments );
			$id = (int) url_to_postid( home_url( '/' . implode( '/', $segments ) . '/' ) );
		}

		return $id;
	}

	/**
	 * Whether the URL matches a page, archive or system route that is not a single post.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_non_post_route( string $url ): bool {
		$parts = wp_parse_url( 0 === strpos( $url, '/' ) ? home_url( $url ) : $url );
		$path  = is_array( $parts ) ? trim( (string) ( $parts['path'] ?? '' ), '/' ) : '';
		$home  = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}

		// Home, language homes, query-string routes, feeds, search, dates and author pages are not posts.
		if ( '' === $path || ! empty( $parts['query'] ) ) {
			return true;
		}
		$segments = explode( '/', $path );
		if ( $this->model->get_language( $segments[0] ) ) {
			array_shift( $segments );
		}
		if ( ! $segments ) {
			return true;
		}
		if ( preg_match( '#^(feed|search|author|page|comments|wp-login\.php|\d{4})$#i', $segments[0] ) ) {
			return true;
		}

		// A taxonomy archive is a real address only while its term exists.
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$base = ! empty( $taxonomy->rewrite['slug'] ) ? trim( (string) $taxonomy->rewrite['slug'], '/' ) : '';
			if ( '' !== $base && $segments[0] === $base ) {
				return count( $segments ) < 2 || (bool) get_term_by( 'slug', rawurldecode( (string) end( $segments ) ), $taxonomy->name );
			}
		}

		// Post type archive bases.
		foreach ( get_post_types( array( 'public' => true, 'has_archive' => true ), 'objects' ) as $post_type ) {
			$base = is_string( $post_type->has_archive ) ? trim( $post_type->has_archive, '/' ) : $post_type->name;
			if ( $segments[0] === $base ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Path form of a URL for messages.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function readable( string $url ): string {
		return Http_Probe::path( $url );
	}

	/**
	 * Why an internal URL goes nowhere, or null when it works.
	 *
	 * Names what exists at the address (nothing, a draft, a private or password-protected page, and so on) and
	 * how to fix it.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url Internal URL.
	 * @return array{kind:string,message:string,post_id:int,fix_url:string,fix_label:string}|null Kind is "missing" or the post status.
	 */
	private function dead_target( string $url ): ?array {
		$post_id = $this->resolve_post_id( $url );

		if ( $post_id <= 0 ) {
			if ( $this->is_non_post_route( $url ) ) {
				return null;
			}

			return $this->dead_result( 'missing', 0, $url );
		}

		return $this->dead_for_post( $post_id, $url );
	}

	/**
	 * Why a link to a known post goes nowhere, or null when the post is public.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Link URL.
	 * @return array{kind:string,message:string,post_id:int,fix_url:string,fix_label:string}|null
	 */
	private function dead_for_post( int $post_id, string $url ): ?array {
		$target = get_post( $post_id );
		if ( ! $target instanceof \WP_Post ) {
			return $this->dead_result( 'missing', 0, $url );
		}
		if ( 'publish' === $target->post_status ) {
			return '' === $target->post_password ? null : $this->dead_result( 'protected', $post_id, $url );
		}

		$kind = in_array( $target->post_status, array( 'draft', 'private', 'pending', 'future' ), true ) ? $target->post_status : 'other';

		return $this->dead_result( $kind, $post_id, $url );
	}

	/**
	 * A dead-link finding with its plain-language problem and fix.
	 *
	 * @since 2.3.0
	 *
	 * @param string $kind    missing, draft, private, protected, pending, future or other.
	 * @param int    $post_id Post at the address, or 0.
	 * @param string $url     Link URL.
	 * @return array{kind:string,message:string,post_id:int,fix_url:string,fix_label:string}
	 */
	private function dead_result( string $kind, int $post_id, string $url ): array {
		$problems = array(
			'draft'     => __( 'A Draft page exists at this URL, but Draft pages are not public, so visitors still get a "page not found" error.', 'linguator-pro' ),
			'private'   => __( 'A Private page exists at this URL, but Private pages are not public, so visitors still get a "page not found" error.', 'linguator-pro' ),
			'protected' => __( 'A password-protected page exists at this URL. Visitors without the password cannot open it like a normal public page.', 'linguator-pro' ),
			'pending'   => __( 'A Pending Review page exists at this URL, but it is not published, so visitors still get a "page not found" error.', 'linguator-pro' ),
			'future'    => __( 'A Scheduled page exists at this URL, but it is not published yet, so visitors still get a "page not found" error.', 'linguator-pro' ),
			'other'     => __( 'A page exists at this URL but is not published, so visitors still get a "page not found" error.', 'linguator-pro' ),
			'missing'   => __( 'No page was found at this URL (including Draft, Private, or password-protected). Visitors who click it will see a "page not found" error.', 'linguator-pro' ),
		);
		$fixes    = array(
			'protected' => __( 'Open Edit page and remove the password if the page should be public, or change the link to a public page.', 'linguator-pro' ),
			'missing'   => __( 'Create and publish that page (then translate it for every language), or change the link to an existing published page.', 'linguator-pro' ),
		);

		$edit = $post_id > 0 ? (string) get_edit_post_link( $post_id, 'url' ) : '';

		return array(
			'kind'      => $kind,
			'message'   => sprintf(
				/* translators: 1: link path, 2: what is wrong, 3: how to fix it */
				__( 'This link goes to %1$s. %2$s %3$s', 'linguator-pro' ),
				$this->readable( $url ),
				$problems[ $kind ] ?? $problems['other'],
				$fixes[ $kind ] ?? __( 'Open Edit page, publish it if that is intended (then translate it for every language), or change the link to an existing published page.', 'linguator-pro' )
			),
			'post_id'   => $post_id,
			'fix_url'   => '' !== $edit ? $edit : admin_url( 'post-new.php?post_type=page' ),
			'fix_label' => '' !== $edit ? __( 'Edit page', 'linguator-pro' ) : __( 'Create page', 'linguator-pro' ),
		);
	}
}
