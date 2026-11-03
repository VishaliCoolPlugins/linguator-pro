<?php
/**
 * Adapter for legacy code that ends the request on failure.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Services;

use Exception;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets an ability call code that assumes it owns the HTTP response.
 *
 * Create_Translated_Post reports every failure with wp_send_json_error()
 * followed by exit, because it was written to run as the last thing a REST
 * route does. Thirteen call sites do this. Eight are in its constructor and can
 * be pre-validated, but three fire mid-creation (failing to load an existing
 * translation, wp_update_post returning an error, wp_insert_post failing) and
 * cannot be predicted from the outside.
 *
 * Inside an ability that is not merely untidy, it is wrong: the process would
 * emit an admin-ajax shaped JSON body and stop, so the Abilities REST
 * controller or an MCP client receives a payload that does not match the
 * protocol it expects, with no way to tell a failure from a crash.
 *
 * So for the duration of one call this class captures output and replaces
 * wp_die() with a throw. The buffered body is parsed back into a WP_Error
 * carrying the original message. wp_send_json() writes its JSON before calling
 * wp_die(), which is exactly why the output buffer is needed as well as the
 * handler filter.
 *
 * Use this only to wrap existing code that cannot be changed safely. New code
 * should return WP_Error.
 *
 * @since 1.5.0
 */
final class Json_Exit_Guard {

	/**
	 * Whether a guarded call is currently running.
	 *
	 * @since 1.5.0
	 * @var bool
	 */
	private static $active = false;

	/**
	 * Runs a callable, converting a JSON exit into a WP_Error.
	 *
	 * @since 1.5.0
	 * @param callable $callback     Code to run.
	 * @param string   $error_code   Error code used when the call exits.
	 * @param string   $fallback_msg Message used when no message can be recovered.
	 * @return mixed|WP_Error The callable's return value, or an error.
	 */
	public static function call( callable $callback, $error_code = 'automlp_write_failed', $fallback_msg = '' ) {
		// Nested guards would fight over the same output buffer.
		if ( self::$active ) {
			return $callback();
		}

		if ( '' === $fallback_msg ) {
			$fallback_msg = __( 'WPML rejected the write and gave no reason.', 'wpml-translation-check-pro' );
		}

		$thrower = static function () {
			return static function ( $message = '' ) {
				throw new Json_Exit_Exception( is_string( $message ) ? $message : '' );
			};
		};

		self::$active = true;

		// wp_die() picks its handler by request type, so all three are replaced.
		add_filter( 'wp_die_handler', $thrower, PHP_INT_MAX );
		add_filter( 'wp_die_ajax_handler', $thrower, PHP_INT_MAX );
		add_filter( 'wp_die_json_handler', $thrower, PHP_INT_MAX );

		ob_start();

		try {
			$result = $callback();
			ob_end_clean();

			return $result;
		} catch ( Json_Exit_Exception $e ) {
			$body = (string) ob_get_clean();

			return new WP_Error(
				$error_code,
				self::message_from_body( $body, $e->getMessage(), $fallback_msg )
			);
		} catch ( Exception $e ) {
			ob_end_clean();

			return new WP_Error( $error_code, $e->getMessage() );
		} finally {
			remove_filter( 'wp_die_handler', $thrower, PHP_INT_MAX );
			remove_filter( 'wp_die_ajax_handler', $thrower, PHP_INT_MAX );
			remove_filter( 'wp_die_json_handler', $thrower, PHP_INT_MAX );

			self::$active = false;
		}
	}

	/**
	 * Recovers the human message from a captured wp_send_json_error() body.
	 *
	 * @since 1.5.0
	 * @param string $body     Buffered response body.
	 * @param string $thrown   Message carried by the exception, if any.
	 * @param string $fallback Message of last resort.
	 * @return string The best available message.
	 */
	private static function message_from_body( $body, $thrown, $fallback ) {
		$decoded = json_decode( trim( $body ), true );

		if ( is_array( $decoded ) && array_key_exists( 'data', $decoded ) ) {
			$data = $decoded['data'];

			if ( is_string( $data ) && '' !== $data ) {
				return $data;
			}

			// Some call sites send array( 'msg' => ... ) instead of a string.
			if ( is_array( $data ) && isset( $data['msg'] ) && is_string( $data['msg'] ) && '' !== $data['msg'] ) {
				return $data['msg'];
			}
		}

		if ( is_string( $thrown ) && '' !== trim( wp_strip_all_tags( $thrown ) ) ) {
			return trim( wp_strip_all_tags( $thrown ) );
		}

		return $fallback;
	}
}
