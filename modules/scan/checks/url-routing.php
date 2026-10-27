<?php
/**
 * Language URL routing health.
 *
 * @package Linguator
 */

namespace Linguator\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Linguator\Modules\Scan\Abstract_Check;
use Linguator\Modules\Scan\Http_Probe;
use Linguator\Modules\Scan\Result;

/**
 * Requests each language's home page and a few of its pages, and reports the ones that do not load.
 *
 * Catches the failures a settings check cannot see: a language home that returns "Page not found", a redirect to
 * another language, or a server error. Redirects are not followed, so the Location header is the diagnosis.
 *
 * @since 2.3.0
 */
class Url_Routing extends Abstract_Check {

	/**
	 * Pages requested per language besides the home page.
	 */
	const PAGES_PER_LANGUAGE = 2;

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
		$languages = $this->active_languages();
		if ( count( $languages ) < 2 ) {
			return array();
		}

		$targets = array();
		$urls    = array();
		foreach ( $languages as $lang ) {
			foreach ( $this->urls_for( $lang ) as $index => $url ) {
				$key            = $lang->slug . '|' . $index;
				$urls[ $key ]   = $url;
				$targets[ $key ] = array( 'lang' => $lang, 'url' => $url, 'is_home' => 0 === $index );
			}
		}
		if ( empty( $urls ) ) {
			return array();
		}

		$responses = Http_Probe::fetch_many( $urls );

		// Loopback requests that all fail say nothing about routing.
		$reachable = array_filter(
			$responses,
			static function ( $response ) {
				return $response['code'] > 0;
			}
		);
		if ( empty( $reachable ) ) {
			return $this->wants( 'url_route_untestable' ) ? array(
				$this->result(
					'url_route_untestable',
					Result::INFO,
					__( 'Language URLs could not be tested', 'linguator-pro' ),
					__( 'This server could not request its own pages (common on local sites or behind a firewall), so routing could not be checked. Open each language home page in your browser to confirm it loads.', 'linguator-pro' )
				),
			) : array();
		}

		// A site closed to every visitor (maintenance mode, password) fails each request the same way.
		$closed = array_filter(
			$reachable,
			static function ( $response ) {
				return in_array( $response['code'], array( 401, 403, 503 ), true );
			}
		);
		if ( count( $closed ) === count( $reachable ) ) {
			return $this->wants( 'url_route_site_gated' ) ? array(
				$this->result(
					'url_route_site_gated',
					Result::INFO,
					__( 'Your whole site is closed to visitors', 'linguator-pro' ),
					__( 'Every request was refused (maintenance mode, a password or a firewall), so routing could not be checked. Run the scan again once the site is open.', 'linguator-pro' )
				),
			) : array();
		}

		$homes   = array_map(
			static function ( $lang ) {
				return method_exists( $lang, 'get_home_url' ) ? (string) $lang->get_home_url() : '';
			},
			$languages
		);
		$rows    = array();
		$checked = array();
		foreach ( $targets as $key => $target ) {
			$slug               = $target['lang']->slug;
			$checked[ $slug ]   = ( $checked[ $slug ] ?? 0 ) + 1;
			$verdict            = $this->classify( $target, $responses[ $key ], $languages, $homes );
			if ( null === $verdict ) {
				continue;
			}
			$rows[ $slug ][] = array(
				'label'        => $target['lang']->name . ' · ' . Http_Probe::path( $target['url'] ),
				'message'      => $verdict,
				'action_url'   => $target['url'],
				'action_label' => __( 'Open page', 'linguator-pro' ),
				'ignore_key'   => 'url_route:' . $slug . ':' . md5( $target['url'] ),
				'language'     => $slug,
			);
		}

		$results = array();
		foreach ( $languages as $lang ) {
			$id = 'url_route_' . $lang->slug;
			if ( ! $this->wants( $id ) || empty( $checked[ $lang->slug ] ) ) {
				continue;
			}

			if ( empty( $rows[ $lang->slug ] ) ) {
				$results[] = $this->result(
					$id,
					Result::PASS,
					/* translators: %s: language name with code */
					sprintf( __( 'Language URLs load in "%s"', 'linguator-pro' ), $this->lang_label( $lang ) ),
					__( 'The home page and sampled pages loaded normally.', 'linguator-pro' )
				);
				continue;
			}

			$results[] = $this->result(
				$id,
				Result::WARNING,
				/* translators: %s: language name with code */
				sprintf( __( 'Language URLs fail in "%s"', 'linguator-pro' ), $this->lang_label( $lang ) ),
				sprintf(
					/* translators: 1: number of URLs, 2: language name with code */
					_n( '%1$d URL in "%2$s" did not load as expected.', '%1$d URLs in "%2$s" did not load as expected.', count( $rows[ $lang->slug ] ), 'linguator-pro' ),
					count( $rows[ $lang->slug ] ),
					$this->lang_label( $lang )
				)
			)->with_details( $rows[ $lang->slug ] );
		}

		return $results;
	}

	/**
	 * Home page and a few recent pages of a language.
	 *
	 * @since 2.3.0
	 *
	 * @param object $lang Language.
	 * @return string[] The home URL first.
	 */
	private function urls_for( $lang ): array {
		$urls = array();
		if ( method_exists( $lang, 'get_home_url' ) && '' !== (string) $lang->get_home_url() ) {
			$urls[] = (string) $lang->get_home_url();
		}

		$ids = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'posts_per_page'   => self::PAGES_PER_LANGUAGE,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'lang'             => '',
				'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'lmat_language',
						'field'    => 'term_id',
						'terms'    => (int) $lang->term_id,
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$link = (string) get_permalink( (int) $id );
			if ( '' !== $link ) {
				$urls[] = $link;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Judges one response. Null when the URL loads as expected.
	 *
	 * @since 2.3.0
	 *
	 * @param array    $target    Target: lang, url, is_home.
	 * @param array    $response  Response.
	 * @param object[] $languages Active languages.
	 * @param string[] $homes     Language home URLs.
	 * @return string|null Plain-language problem.
	 */
	private function classify( array $target, array $response, array $languages, array $homes ): ?string {
		$code = $response['code'];

		if ( 0 === $code || '' !== $response['error'] ) {
			return null; // Not testable; the summary result covers a server that cannot reach itself.
		}
		if ( $code >= 200 && $code < 300 ) {
			return null;
		}
		if ( 404 === $code ) {
			return __( 'Returned "Page not found". Visitors and search engines cannot reach this page.', 'linguator-pro' );
		}
		if ( $code >= 500 ) {
			return sprintf(
				/* translators: %d: HTTP status code */
				__( 'The server returned an error (HTTP %d). This is a server problem rather than a translation problem.', 'linguator-pro' ),
				$code
			);
		}
		if ( $code < 300 || $code >= 400 ) {
			return sprintf(
				/* translators: %d: HTTP status code */
				__( 'Returned HTTP %d instead of loading the page.', 'linguator-pro' ),
				$code
			);
		}

		$location = $response['location'];
		if ( '' === $location ) {
			return __( 'Redirected, but the destination was not given.', 'linguator-pro' );
		}
		if ( 0 !== strpos( $location, 'http' ) ) {
			$location = home_url( '/' === substr( $location, 0, 1 ) ? $location : '/' . $location );
		}

		// A trailing-slash or scheme-only difference is not a routing problem.
		if ( Http_Probe::same_address( $location, $target['url'] ) ) {
			return null;
		}

		$destination = $this->language_of_url( $location, $languages );
		if ( '' !== $destination && $destination !== $target['lang']->slug ) {
			return sprintf(
				/* translators: 1: destination URL, 2: language code */
				__( 'Redirected to %1$s, which is the "%2$s" version. Visitors asking for this language are being sent to another one.', 'linguator-pro' ),
				$location,
				$destination
			);
		}

		foreach ( $homes as $home ) {
			if ( '' !== $home && Http_Probe::same_address( $home, $location ) && ! $target['is_home'] ) {
				return sprintf(
					/* translators: %s: destination URL */
					__( 'Redirected to the home page at %s instead of loading the requested page.', 'linguator-pro' ),
					$location
				);
			}
		}

		return null;
	}

	/**
	 * Language a URL belongs to, by the home URL it starts with.
	 *
	 * @since 2.3.0
	 *
	 * @param string   $url       URL.
	 * @param object[] $languages Active languages.
	 * @return string Language slug, or an empty string.
	 */
	private function language_of_url( string $url, array $languages ): string {
		$best   = '';
		$length = 0;
		$needle = Http_Probe::normalise_url( $url );

		foreach ( $languages as $lang ) {
			$home = method_exists( $lang, 'get_home_url' ) ? Http_Probe::normalise_url( (string) $lang->get_home_url() ) : '';
			if ( '' !== $home && 0 === strpos( $needle, $home ) && strlen( $home ) > $length ) {
				$best   = $lang->slug;
				$length = strlen( $home );
			}
		}

		return $best;
	}

}
