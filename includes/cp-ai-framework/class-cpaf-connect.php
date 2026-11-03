<?php
/**
 * Cool Plugins AI framework: REST routes behind "connect an AI app".
 *
 * @package CoolPluginsAiFramework
 * @since   1.5.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CPAF_Connect' ) ) {

	/**
	 * One Application Password per user and AI app, created once and reused.
	 *
	 * WordPress keeps only a hash of an Application Password, so to show the
	 * setup prompt again the password is saved encrypted in the user's own meta.
	 * A password revoked from the profile page no longer verifies and is replaced.
	 *
	 * @since 1.5.0
	 */
	final class CPAF_Connect {

		/**
		 * REST namespace.
		 *
		 * @since 1.5.0
		 * @var string
		 */
		const REST_NAMESPACE = 'cpaf/v1';

		/**
		 * User meta key: array of AI app => array( uuid, secret ).
		 *
		 * @since 1.6.0
		 * @var string
		 */
		const META_KEY = 'cpaf_mcp_credentials';

		/**
		 * Supported AI apps: id => label.
		 *
		 * @since 1.5.0
		 * @return array<string, string> Client id to display name.
		 */
		public static function clients() {
			return array(
				'claude-code'    => 'Claude Code',
				'claude-desktop' => 'Claude Desktop',
				'codex'          => 'Codex',
				'cursor'         => 'Cursor',
				'other'          => CPAF_Framework::t( 'Other' ),
			);
		}

		/**
		 * Hooks the routes.
		 *
		 * @since 1.5.0
		 * @return void
		 */
		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 10, 0 );
		}

		/**
		 * Registers the routes: credentials (GET reads, POST creates) and connection.
		 *
		 * @since 1.5.0
		 * @return void
		 */
		public static function register_routes() {
			$args = array(
				'methods'             => 'GET, POST',
				'callback'            => array( __CLASS__, 'credentials' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'client'     => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array_keys( self::clients() ),
					),
					'regenerate' => array( 'type' => 'boolean' ),
				),
			);

			register_rest_route( self::REST_NAMESPACE, '/credentials', $args );

			register_rest_route(
				self::REST_NAMESPACE,
				'/connection',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'connection' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
					'args'                => array(
						'uuid' => array(
							'required' => true,
							'type'     => 'string',
							'pattern'  => '^[0-9a-fA-F-]{36}$',
						),
					),
				)
			);
		}

		/**
		 * Only administrators may read or create credentials.
		 *
		 * @since 1.5.0
		 * @return bool True when allowed.
		 */
		public static function check_permission() {
			return current_user_can( 'manage_options' );
		}

		/**
		 * Encrypts a password for storage (AES-256-CBC, key from the site salts).
		 *
		 * @since 1.6.0
		 * @param string $plain Password.
		 * @return string Encoded blob.
		 */
		public static function encrypt( $plain ) {
			$iv = random_bytes( 16 );

			return base64_encode( $iv . openssl_encrypt( $plain, 'aes-256-cbc', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $iv ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary encoding, not obfuscation.
		}

		/**
		 * Decrypts a blob made by encrypt().
		 *
		 * @since 1.6.0
		 * @param string $blob Encoded blob.
		 * @return string Password, or an empty string when it cannot be read.
		 */
		public static function decrypt( $blob ) {
			$raw = (string) base64_decode( $blob ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary decoding, not obfuscation.

			return (string) openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		}

		/**
		 * A no-store response: the body can carry a password.
		 *
		 * @since 1.6.0
		 * @param array<string, mixed> $body Response body.
		 * @return WP_REST_Response Response.
		 */
		private static function reply( array $body ) {
			$response = new WP_REST_Response( $body, 200 );

			$response->header( 'Cache-Control', 'no-store' );

			return $response;
		}

		/**
		 * GET returns the saved credentials for an AI app. POST returns them too,
		 * creating an Application Password only when none is usable, or when
		 * "regenerate" is set (which revokes the one it replaces).
		 *
		 * @since 1.5.0
		 * @param WP_REST_Request $request Request with "client" and optional "regenerate".
		 * @return WP_REST_Response|WP_Error Credentials, or an error the page can show.
		 */
		public static function credentials( $request ) {
			$user   = wp_get_current_user();
			$client = (string) $request->get_param( 'client' );
			$saved  = get_user_meta( $user->ID, self::META_KEY, true );
			$saved  = is_array( $saved ) ? $saved : array();

			if ( ! class_exists( 'WP_Application_Passwords' ) ) {
				return self::reply( array( 'exists' => false ) );
			}

			$item     = isset( $saved[ $client ] ) ? WP_Application_Passwords::get_user_application_password( $user->ID, $saved[ $client ]['uuid'] ) : null;
			$password = $item ? self::decrypt( $saved[ $client ]['secret'] ) : '';
			$usable   = '' !== $password && ( method_exists( 'WP_Application_Passwords', 'check_password' ) ? WP_Application_Passwords::check_password( $password, $item['password'] ) : wp_check_password( $password, $item['password'] ) );

			if ( ! $usable || $request->get_param( 'regenerate' ) ) {
				if ( 'POST' !== $request->get_method() ) {
					return self::reply( array( 'exists' => false ) );
				}

				if ( ! function_exists( 'wp_is_application_passwords_available_for_user' ) || ! wp_is_application_passwords_available_for_user( $user ) || ! function_exists( 'openssl_encrypt' ) ) {
					return new WP_Error( 'cpaf_unavailable', CPAF_Framework::t( 'Application Passwords are not available on this site. WordPress turns them off on sites without HTTPS, and a plugin or setting can also disable them.' ), array( 'status' => 400, 'profile_url' => admin_url( 'profile.php#application-passwords-section' ) ) );
				}

				$created = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => sprintf( 'AI Translation Tools - %s (%s)', self::clients()[ $client ], gmdate( 'Y-m-d H:i' ) ) ) );

				if ( is_wp_error( $created ) ) {
					return new WP_Error( 'cpaf_failed', CPAF_Framework::t( 'Could not create an Application Password.' ), array( 'status' => 500 ) );
				}

				if ( $item ) {
					WP_Application_Passwords::delete_application_password( $user->ID, $item['uuid'] );
				}

				list( $password, $item ) = $created;

				$saved[ $client ] = array(
					'uuid'   => $item['uuid'],
					'secret' => self::encrypt( $password ),
				);
				update_user_meta( $user->ID, self::META_KEY, $saved );
				$usable = false;
			}

			$site = sanitize_title( (string) get_bloginfo( 'name' ) );

			return self::reply(
				array(
					'exists' => true,
					'data'   => array(
						'username'           => $user->user_login,
						'password'           => WP_Application_Passwords::chunk_password( $password ),
						'serverUrl'          => rest_url( CPAF_Framework::MCP_REST_NAMESPACE . '/' . CPAF_Framework::MCP_REST_ROUTE ),
						'serverName'         => ( '' !== $site ? $site : 'site' ) . '-translation',
						'encodedCredentials' => base64_encode( $user->user_login . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth encoding.
						'uuid'               => $item['uuid'],
						'reused'             => $usable,
					),
				)
			);
		}

		/**
		 * Whether an Application Password has been used yet: WordPress sets
		 * "last_used" on its first successful request, i.e. the AI app connecting.
		 *
		 * @since 1.5.0
		 * @param WP_REST_Request $request Request with "uuid".
		 * @return WP_REST_Response Whether it has been used.
		 */
		public static function connection( $request ) {
			$item = class_exists( 'WP_Application_Passwords' ) ? WP_Application_Passwords::get_user_application_password( get_current_user_id(), (string) $request->get_param( 'uuid' ) ) : null;

			return self::reply( array( 'connected' => is_array( $item ) && ! empty( $item['last_used'] ) ) );
		}
	}
}
