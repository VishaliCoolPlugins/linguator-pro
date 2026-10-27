<?php
/**
 * HTTP requests for the routing checks.
 *
 * @package Linguator
 */

namespace Linguator\Modules\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requests URLs of this site without following redirects.
 *
 * A redirect is never followed: the Location header is the diagnosis, and following it would hide exactly the
 * problem the routing checks exist to find.
 *
 * @since 2.3.0
 */
class Http_Probe {

	/**
	 * Seconds each request may take.
	 */
	const TIMEOUT = 5;

	/**
	 * Requests one URL.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url URL.
	 * @return array{code:int,location:string,headers:array,error:string}
	 */
	public static function fetch( string $url ): array {
		$response = wp_remote_get( $url, self::args( $url ) );

		if ( is_wp_error( $response ) ) {
			return self::normalise( 0, array(), $response->get_error_message() );
		}

		$headers = wp_remote_retrieve_headers( $response );
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		return self::normalise( (int) wp_remote_retrieve_response_code( $response ), is_array( $headers ) ? $headers : array(), '' );
	}

	/**
	 * Requests many URLs, in parallel when the transport supports it.
	 *
	 * @since 2.3.0
	 *
	 * @param array<string,string> $urls Key => URL.
	 * @return array<string,array> Key => response.
	 */
	public static function fetch_many( array $urls ): array {
		if ( empty( $urls ) ) {
			return array();
		}

		if ( class_exists( '\WpOrg\Requests\Requests' ) && method_exists( '\WpOrg\Requests\Requests', 'request_multiple' ) ) {
			$requests = array();
			foreach ( $urls as $key => $url ) {
				$requests[ $key ] = array(
					'url'     => $url,
					'type'    => 'GET',
					'headers' => array( 'Cache-Control' => 'no-cache' ),
				);
			}

			try {
				$responses = \WpOrg\Requests\Requests::request_multiple(
					$requests,
					array(
						'timeout'          => self::TIMEOUT,
						'follow_redirects' => false,
						'useragent'        => self::user_agent(),
						'verify'           => false,
					)
				);
			} catch ( \Throwable $e ) {
				$responses = null;
			}

			if ( is_array( $responses ) ) {
				$out = array();
				foreach ( $urls as $key => $url ) {
					$response = $responses[ $key ] ?? null;
					if ( ! is_object( $response ) || $response instanceof \Throwable || ! isset( $response->status_code ) ) {
						$out[ $key ] = self::normalise( 0, array(), $response instanceof \Throwable ? $response->getMessage() : __( 'No response.', 'linguator-pro' ) );
						continue;
					}

					$headers = array();
					foreach ( (array) ( $response->headers ?? array() ) as $name => $value ) {
						$headers[ strtolower( (string) $name ) ] = $value;
					}
					$out[ $key ] = self::normalise( (int) $response->status_code, $headers, '' );
				}

				return $out;
			}
		}

		$out = array();
		foreach ( $urls as $key => $url ) {
			$out[ $key ] = self::fetch( $url );
		}

		return $out;
	}

	/**
	 * Whether the host and path of two URLs are the same apart from scheme, case and a trailing slash.
	 *
	 * @since 2.3.0
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 * @return bool
	 */
	public static function same_address( string $a, string $b ): bool {
		return self::normalise_url( $a ) === self::normalise_url( $b );
	}

	/**
	 * Path of a URL for messages, or "/" for the home page.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function path( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '' === $path ? '/' : $path;
	}

	/**
	 * Comparable form of a URL.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalise_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return strtolower( trim( $url, '/' ) );
		}

		return strtolower( ( $parts['host'] ?? '' ) . '/' . trim( rawurldecode( (string) ( $parts['path'] ?? '' ) ), '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) );
	}

	/**
	 * Request arguments.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url URL being requested.
	 * @return array
	 */
	private static function args( string $url ): array {
		return array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'sslverify'   => false,
			'cookies'     => array(),
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
			'user-agent'  => self::user_agent(),
		);
	}

	/**
	 * User agent used for every routing request, so a site owner can allow-list it in a security plugin.
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 */
	public static function user_agent(): string {
		return 'LinguatorRoutingCheck/1.0 (+' . home_url() . ')';
	}

	/**
	 * Normalised response.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $code    HTTP status, 0 on failure.
	 * @param array  $headers Response headers.
	 * @param string $error   Error message.
	 * @return array{code:int,location:string,headers:array,error:string}
	 */
	private static function normalise( int $code, array $headers, string $error ): array {
		$lower = array();
		foreach ( $headers as $name => $value ) {
			$lower[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return array(
			'code'     => $code,
			'location' => $lower['location'] ?? '',
			'headers'  => $lower,
			'error'    => $error,
		);
	}
}
