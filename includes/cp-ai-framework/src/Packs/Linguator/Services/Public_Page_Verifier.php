<?php
/**
 * Checks what a visitor really gets after a translation was written.
 *
 * @package CoolPluginsAiFramework
 * @since 1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requests the site's own public page and reports its title, headings and menu.
 *
 * Writing a translation proves only that the database changed. A page can still
 * 404, redirect, or show an old header from a cache. This asks the site itself.
 * It only ever requests this site's own address, never one supplied by a caller,
 * and it never fails a translation: when it cannot check, it says so.
 *
 * @since 1.2.0
 */
final class Public_Page_Verifier {

	/**
	 * Transient set when a request to the site's own address fails to connect.
	 *
	 * Stops every later call in the next few minutes from waiting on a host
	 * that cannot call itself.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const FAILED_TRANSIENT = 'lmat_verify_public_failed';

	/**
	 * Seconds to wait for the page.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const TIMEOUT = 10;

	/**
	 * Most headings and menu labels returned.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MAX_LISTED = 20;

	/**
	 * Most translated strings compared against the page.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MAX_COMPARED = 60;

	/**
	 * Fetches the public page for a post and compares it with what was written.
	 *
	 * An Elementor template has no public page of its own, so the target
	 * language's home page is used: that is where its header and footer show.
	 *
	 * @since 1.2.0
	 * @param int      $post_id     Translated post ID.
	 * @param string   $target_lang Target language slug.
	 * @param string[] $expected    Translated strings that were sent.
	 * @param string   $slug        Slug the caller asked for, or '' when none.
	 * @param int      $timeout     Seconds to wait for the page, or 0 for the default.
	 * @return array<string, mixed> Verification report; never an error.
	 */
	public static function verify( $post_id, $target_lang, array $expected, $slug = '', $timeout = 0 ) {
		$post = get_post( (int) $post_id );

		if ( null === $post ) {
			return self::skipped( __( 'The translated post could not be read back.', 'linguator-pro' ) );
		}

		if ( 'publish' !== $post->post_status ) {
			return self::skipped(
				sprintf(
					/* translators: %s: post status. */
					__( 'The translation is "%s", so visitors cannot see it and there is no public page to check.', 'linguator-pro' ),
					$post->post_status
				)
			);
		}

		clean_post_cache( $post->ID );

		$is_template = Template_Service::TEMPLATE_POST_TYPE === $post->post_type;
		$url         = $is_template && function_exists( 'linguator_home_url' ) ? (string) linguator_home_url( $target_lang ) : (string) get_permalink( $post->ID );

		if ( '' === $url || ! self::is_own_address( $url ) ) {
			return self::skipped( __( 'This post has no public address on this site.', 'linguator-pro' ) );
		}

		if ( false !== get_transient( self::FAILED_TRANSIENT ) ) {
			return self::skipped( __( 'This site could not reach its own address a few minutes ago, so the check was skipped. Check the page in a browser.', 'linguator-pro' ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $timeout > 0 ? (int) $timeout : self::TIMEOUT,
				'redirection' => 5,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'user-agent'  => 'Linguator-Verify/1.0; ' . home_url( '/' ),
				'headers'     => array(
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			set_transient( self::FAILED_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );

			return self::skipped(
				sprintf(
					/* translators: %s: error message. */
					__( 'The page could not be requested (%s). The translation itself was saved. Check the page in a browser.', 'linguator-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$final  = self::final_url( $response, $url );

		$report = array(
			'verified'     => true,
			'checked_url'  => $url,
			'final_url'    => $final,
			'http_status'  => $status,
			// Compared decoded: the same address can differ only in the case of its %xx escapes.
			'redirected'   => rawurldecode( $final ) !== rawurldecode( $url ),
			'checked_page' => $is_template ? 'home_page_of_language' : 'translation',
		);

		$issues = array();

		if ( $status >= 400 ) {
			$issues[] = sprintf(
				/* translators: 1: HTTP status, 2: address. */
				__( 'The page answered HTTP %1$d at %2$s. Visitors will not see this translation.', 'linguator-pro' ),
				$status,
				$final
			);
		}

		if ( $status < 400 && '' !== $body ) {
			$report = array_merge( $report, self::read_page( $body ) );
			$report = array_merge( $report, self::compare( $expected, $body ) );

			$checked = isset( $report['strings_checked'] ) ? (int) $report['strings_checked'] : 0;
			$found   = isset( $report['strings_found'] ) ? (int) $report['strings_found'] : 0;

			// A few strings can be hidden on a page (editor-only titles, other tabs), so only a clear majority missing is reported.
			if ( $checked > 0 && ( 0 === $found || ( $checked >= 3 && $found * 2 < $checked ) ) ) {
				// Advisory only: a layout can hold hidden or conditional elements that are never printed, so absence alone does not prove an old copy.
				$report['warnings'][] = sprintf(
					/* translators: 1: strings found, 2: strings checked. */
					__( 'Only %1$d of %2$d translated strings were found on the public page (see not_found). That is normal when the layout has hidden or conditional elements. If the page looks untranslated, clear Elementor\'s cache and any page cache and check again; for a header or footer also check that the template is translated and published.', 'linguator-pro' ),
					$found,
					$checked
				);
			}
		}

		// Plain permalinks (?p=ID) carry no slug, so there is nothing to compare.
		if ( '' !== (string) $slug && '' !== (string) get_option( 'permalink_structure', '' ) ) {
			$decoded = rawurldecode( (string) wp_parse_url( $final, PHP_URL_PATH ) );

			if ( false === strpos( $decoded, rawurldecode( $slug ) ) && ! $is_template ) {
				$report['canonical_url'] = $final;
				$issues[]                = sprintf(
					/* translators: 1: requested slug, 2: address the page is really served from. */
					__( 'The slug "%1$s" is not in the address this page is served from (%2$s), so it is not used there. Static front pages behave this way. Link to canonical_url.', 'linguator-pro' ),
					$slug,
					$final
				);
			}
		}

		$report['issues'] = $issues;

		return $report;
	}

	/**
	 * A report for a check that did not run.
	 *
	 * @since 1.2.0
	 * @param string $reason Why.
	 * @return array{verified: bool, reason: string} Report.
	 */
	private static function skipped( $reason ) {
		return array(
			'verified' => false,
			'reason'   => $reason,
		);
	}

	/**
	 * Whether an address is on this site's own host.
	 *
	 * @since 1.2.0
	 * @param string $url Address.
	 * @return bool True when it is.
	 */
	private static function is_own_address( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$own  = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return is_string( $host ) && is_string( $own ) && 0 === strcasecmp( $host, $own );
	}

	/**
	 * The address the request ended on after any redirects.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed>|\WP_HTTP_Requests_Response $response Response.
	 * @param string                                           $url      Address requested.
	 * @return string Final address.
	 */
	private static function final_url( $response, $url ) {
		if ( isset( $response['http_response'] ) && is_object( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ) {
			$object = $response['http_response']->get_response_object();

			if ( is_object( $object ) && isset( $object->url ) && is_string( $object->url ) && '' !== $object->url ) {
				return $object->url;
			}
		}

		return $url;
	}

	/**
	 * Reads the title, headings, menu labels and language out of a page.
	 *
	 * @since 1.2.0
	 * @param string $html Page HTML.
	 * @return array<string, mixed> Parsed parts, empty when the HTML cannot be parsed.
	 */
	private static function read_page( $html ) {
		$document = self::load( $html );

		if ( null === $document ) {
			return array();
		}

		$xpath = new \DOMXPath( $document );

		$title = $xpath->query( '//title' );
		$lang  = $xpath->query( '//html/@lang' );

		$parts = array(
			'title'      => $title && $title->length > 0 ? self::clean( (string) $title->item( 0 )->textContent ) : '',
			'html_lang'  => $lang && $lang->length > 0 ? (string) $lang->item( 0 )->nodeValue : '',
			'headings'   => self::texts( $xpath, '//h1|//h2|//h3' ),
			'nav_labels' => self::texts( $xpath, '//nav//a|//*[contains(concat(" ", normalize-space(@class), " "), " elementor-nav-menu ")]//a' ),
		);

		return $parts;
	}

	/**
	 * Counts how many of the translated strings appear on the page.
	 *
	 * @since 1.2.0
	 * @param string[] $expected Translated strings that were sent.
	 * @param string   $html     Page HTML.
	 * @return array<string, mixed> Counts and a sample of those not found.
	 */
	private static function compare( array $expected, $html ) {
		$document = self::load( $html );

		if ( null === $document ) {
			return array();
		}

		$xpath = new \DOMXPath( $document );

		foreach ( $xpath->query( '//script|//style|//noscript' ) as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}

		$page    = self::fold( (string) $document->textContent );
		$checked = 0;
		$found   = 0;
		$missing = array();

		foreach ( $expected as $string ) {
			$text = self::fold( wp_strip_all_tags( (string) $string ) );

			// Very short strings match by accident, very long ones are often re-wrapped by the builder.
			if ( mb_strlen( $text ) < 4 || mb_strlen( $text ) > 300 ) {
				continue;
			}

			if ( $checked >= self::MAX_COMPARED ) {
				break;
			}

			++$checked;

			if ( self::contains( $page, $text ) ) {
				++$found;
			} elseif ( count( $missing ) < 10 ) {
				$missing[] = mb_substr( $text, 0, 80 );
			}
		}

		return array(
			'strings_checked' => $checked,
			'strings_found'   => $found,
			'not_found'       => $missing,
		);
	}

	/**
	 * Case-insensitive containment that works without the mbstring extension.
	 *
	 * @since 1.2.0
	 * @param string $haystack Text to search.
	 * @param string $needle   Text to find.
	 * @return bool True when found.
	 */
	private static function contains( $haystack, $needle ) {
		return function_exists( 'mb_stripos' )
			? false !== mb_stripos( $haystack, $needle )
			: false !== stripos( $haystack, $needle );
	}

	/**
	 * Parses HTML without raising warnings.
	 *
	 * @since 1.2.0
	 * @param string $html Page HTML.
	 * @return \DOMDocument|null The document, or null when it cannot be parsed.
	 */
	private static function load( $html ) {
		if ( ! class_exists( '\DOMDocument' ) || '' === trim( $html ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$loaded   = $document->loadHTML( '<?xml encoding="utf-8"?>' . $html );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $document : null;
	}

	/**
	 * The distinct, cleaned text of the nodes an XPath finds.
	 *
	 * @since 1.2.0
	 * @param \DOMXPath $xpath Query object.
	 * @param string    $query XPath expression.
	 * @return string[] Texts, at most MAX_LISTED.
	 */
	private static function texts( \DOMXPath $xpath, $query ) {
		$nodes = $xpath->query( $query );
		$out   = array();

		if ( ! $nodes ) {
			return $out;
		}

		foreach ( $nodes as $node ) {
			$text = self::clean( (string) $node->textContent );

			if ( '' !== $text && ! in_array( $text, $out, true ) ) {
				$out[] = mb_substr( $text, 0, 120 );
			}

			if ( count( $out ) >= self::MAX_LISTED ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Collapses whitespace.
	 *
	 * @since 1.2.0
	 * @param string $text Text.
	 * @return string Cleaned text.
	 */
	private static function clean( $text ) {
		$out = preg_replace( '/\s+/u', ' ', str_replace( "\u{00A0}", ' ', $text ) );

		return trim( null === $out ? $text : $out );
	}

	/**
	 * Text prepared for a containment check: decoded, whitespace-collapsed.
	 *
	 * @since 1.2.0
	 * @param string $text Text.
	 * @return string Folded text.
	 */
	private static function fold( $text ) {
		return self::clean( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
