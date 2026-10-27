<?php
/**
 * @package Linguator
 */

namespace Linguator\Modules\REST\V1;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use Linguator\Includes\Other\Linguator_Model;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Linguator\Includes\Models\Languages;
use Linguator\Includes\Options\Options;
use Linguator\Includes\Options\Business\Api_Keys as Api_Keys_Option;
use Linguator\Modules\REST\Abstract_Controller;
use Linguator\Includes\Migration\Polylang_Migration;
use Linguator\Includes\Migration\WPML_Migration;


/**
 * Settings REST controller.
 *
 *  
 */
class Settings extends Abstract_Controller {
	/**
	 * @var Options
	 */
	private $options;

	/**
	 * @var Languages
	 */
	private $languages;

	/**
	 * @var Linguator_Model
	 */
	private $model;

	/**
	 * The list of post types to show in the form.
	 *
	 * @var string[]
	 */
	private $post_types;

	/**
	 * The list of post types to disable in the form.
	 *
	 * @var string[]
	 */
	private $disabled_post_types;

	/**
	 * The list of taxonomies to show in the form.
	 *
	 * @var string[]
	 */
	private $taxonomies;

	/**
	 * The list of taxonomies to disable in the form.
	 *
	 * @var string[]
	 */
	private $disabled_taxonomies;

	/**
	 * True when update_item saved a new non-empty Gemini key; get_item runs discovery once then clears this.
	 *
	 * @var bool
	 */
	private $ai_gemini_model_refresh_needed = false;

	protected $namespace;
	protected $rest_base;
	/**
	 * Constructor.
	 *
	 *  
	 *
	 * @param Linguator_Model $model Linguator's model.
	 */
	public function __construct( Linguator_Model $model ) {
		$this->namespace = 'lmat/v1';
		$this->rest_base = 'settings';
		$this->model     = $model;
		$this->options   = $model->options;
		$this->languages = $model->languages;
	}

	
	/**
	 * Registers the routes for options.
	 *
	 *  
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				'schema'      => array( $this, 'get_public_item_schema' ),
				'allow_batch' => array( 'v1' => true ),
			)
		);
		
		// Add specific endpoint for video status
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/video-status",
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_video_status' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'status' => array(
							'required'          => true,
							'type'              => 'boolean',
							'sanitize_callback' => array( $this, 'sanitize_boolean_param' ),
							'validate_callback' => array( $this, 'validate_boolean_param' ),
						),
					),
				),
			)
		);
		
		// Add specific endpoint for setup completion
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/setup-complete",
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_setup_complete' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'complete' => array(
							'required'          => true,
							'type'              => 'boolean',
							'sanitize_callback' => array( $this, 'sanitize_boolean_param' ),
							'validate_callback' => array( $this, 'validate_boolean_param' ),
						),
					),
				),
			)
		);

		// Add specific endpoint for migration status
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/migration-status",
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_migration_status' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'completed' => array(
							'required'          => true,
							'type'              => 'boolean',
							'sanitize_callback' => array( $this, 'sanitize_boolean_param' ),
							'validate_callback' => array( $this, 'validate_boolean_param' ),
						),
					),
				),
			)
		);

		// Add migration endpoints - dynamic routes for both Polylang and WPML
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/migration/(?P<plugin>polylang|wpml)/detect",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'detect_migration' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'plugin' => array(
							'required'          => true,
							'type'              => 'string',
							'enum'              => array( 'polylang', 'wpml' ),
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => array( $this, 'validate_migration_plugin_param' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/migration/(?P<plugin>polylang|wpml)/migrate",
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'migrate_plugin' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'plugin' => array(
							'required'          => true,
							'type'              => 'string',
							'enum'              => array( 'polylang', 'wpml' ),
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => array( $this, 'validate_migration_plugin_param' ),
						),
						'migrate_languages'    => array(
							'required'          => false,
							'type'              => 'boolean',
							'default'           => true,
							'sanitize_callback' => array( $this, 'sanitize_boolean_param' ),
							'validate_callback' => array( $this, 'validate_boolean_param' ),
						),
						'migrate_translations' => array(
							'required'          => false,
							'type'              => 'boolean',
							'default'           => true,
							'sanitize_callback' => array( $this, 'sanitize_boolean_param' ),
							'validate_callback' => array( $this, 'validate_boolean_param' ),
						),
						'migrate_settings'     => array(
							'required'          => false,
							'type'              => 'boolean',
							'default'           => true,
							'sanitize_callback' => array( $this, 'sanitize_boolean_param' ),
							'validate_callback' => array( $this, 'validate_boolean_param' ),
						),
						'migrate_strings'     => array(
							'required'          => false,
							'type'              => 'boolean',
							'default'           => true,
							'sanitize_callback' => array( $this, 'sanitize_boolean_param' ),
							'validate_callback' => array( $this, 'validate_boolean_param' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Sanitizes boolean-like request values.
	 *
	 * @param mixed $value Raw request value.
	 * @return bool
	 */
	public function sanitize_boolean_param( $value ) {
		if ( function_exists( 'rest_sanitize_boolean' ) ) {
			return \rest_sanitize_boolean( $value );
		}

		// Fallback for WP installs that don't provide rest_sanitize_boolean().
		if ( function_exists( 'wp_validate_boolean' ) ) {
			// wp_validate_boolean() returns bool on valid values, null on invalid.
			$validated = wp_validate_boolean( $value );
			return null !== $validated ? (bool) $validated : false;
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value === 1;
		}

		if ( ! is_string( $value ) ) {
			return false;
		}

		$v = strtolower( trim( $value ) );
		return in_array( $v, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Validates boolean-like request values.
	 *
	 * @param mixed $value Request value.
	 * @return bool
	 */
	public function validate_boolean_param( $value ) {
		if ( function_exists( 'wp_validate_boolean' ) ) {
			return null !== wp_validate_boolean( $value );
		}

		if ( is_bool( $value ) ) {
			return true;
		}

		if ( is_numeric( $value ) ) {
			$n = (int) $value;
			return (string) $n === (string) (int) $value && ( $n === 0 || $n === 1 );
		}

		if ( ! is_string( $value ) ) {
			return false;
		}

		$v = strtolower( trim( $value ) );
		return in_array( $v, array( '1', '0', 'true', 'false', 'yes', 'no', 'on', 'off' ), true );
	}

	/**
	 * Validates migration plugin identifier.
	 *
	 * @param mixed $value Request value.
	 * @return bool
	 */
	public function validate_migration_plugin_param( $value ) {
		return in_array( sanitize_key( (string) $value ), array( 'polylang', 'wpml' ), true );
	}

	/**
	 * Updates video status option.
	 *
	 *  
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_video_status( $request ) {
		$status = $request->get_param( 'status' );

		// update_option() returns false when the value is unchanged — that is not a failure.
		$current = get_option( 'lmat_video_status', false );
		if ( (bool) $current === (bool) $status ) {
			return rest_ensure_response(
				array(
					'success'           => true,
					'lmat_video_status' => (bool) $status,
					'message'           => esc_html__( 'Video status updated successfully', 'linguator-pro' ),
				)
			);
		}

		$result = update_option( 'lmat_video_status', $status );

		if ( false !== $result ) {
			return rest_ensure_response(
				array(
					'success'           => true,
					'lmat_video_status' => (bool) $status,
					'message'           => esc_html__( 'Video status updated successfully', 'linguator-pro' ),
				)
			);
		}

		return new WP_Error(
			'update_failed',
			esc_html__( 'Failed to update video status', 'linguator-pro' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Updates setup completion status.
	 *
	 *  
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_setup_complete( $request ) {
		$complete = (bool) $request->get_param( 'complete' );
		
		$result = update_option( 'lmat_setup_complete', $complete );
		// Verify the option was set correctly by checking the stored value
		$stored_value = get_option( 'lmat_setup_complete' );
		
		$stored_bool = $this->sanitize_boolean_param( $stored_value );
		if ( $result !== false || $stored_bool === $complete ) {
				$cpfm_opt_in_choice = linguator_get_cpfm_opt_in_choice();
				$lmat_feedback_data = (bool) $this->options->get( 'lmat_feedback_data' );
				if ( $complete && ( 'yes' === $cpfm_opt_in_choice || $lmat_feedback_data ) ) {
					if ( class_exists( '\\CPFM_Usage_Cron' ) ) {
						\CPFM_Usage_Cron::cpfm_schedule_event( 'lmat_extra_data_update' );
						if ( 'no' !== $cpfm_opt_in_choice ) {
							do_action( 'lmat_extra_data_update' );
						}
					}
				}
			return rest_ensure_response( array(
				'success' => true,
				'lmat_setup_complete' => $complete,
				'message' => esc_html__( 'Setup completion status updated successfully', 'linguator-pro' )
			) );
		} else {
			return new WP_Error(
				'update_failed',
				esc_html__( 'Failed to update setup completion status', 'linguator-pro' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Updates migration completion status.
	 *
	 *  
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_migration_status( $request ) {
		$completed = $request->get_param( 'completed' );
		
		$result = update_option( 'lmat_migration_completed', $completed );
		
		if ( $result !== false ) {
			return rest_ensure_response( array(
				'success' => true,
				'lmat_migration_completed' => $completed,
				'message' => esc_html__( 'Migration status updated successfully', 'linguator-pro' )
			) );
		} else {
			return new WP_Error(
				'update_failed',
				esc_html__( 'Failed to update migration status', 'linguator-pro' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Retrieves all options.
	 *
	 *  
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 *
	 * @phpstan-template T of array
	 * @phpstan-param WP_REST_Request<T> $request
	 */
	public function get_item( $request ) {
		$public_post_types = get_post_types( array( 'public' => true, '_builtin' => false ) );
		/** This filter is documented in include/model.php */
		$this->post_types = array_unique( apply_filters( 'lmat_get_post_types', $public_post_types, true ) );

		/** This filter is documented in include/model.php */
		$programmatically_active_post_types = array_unique( apply_filters( 'lmat_get_post_types', array(), false ) );
		$this->disabled_post_types = array_intersect( $programmatically_active_post_types, $this->post_types );

		$public_taxonomies = get_taxonomies( array( 'public' => true, '_builtin' => false ) );
		$public_taxonomies = array_diff( $public_taxonomies, get_taxonomies( array( '_lmat' => true ) ) );
		/** This filter is documented in include/model.php */
		$this->taxonomies = array_unique( apply_filters( 'lmat_get_taxonomies', $public_taxonomies, true ) );

		/** This filter is documented in include/model.php */
		$programmatically_active_taxonomies = array_unique( apply_filters( 'lmat_get_taxonomies', array(), false ) );
		$this->disabled_taxonomies = array_intersect( $programmatically_active_taxonomies, $this->taxonomies );
		$response = $this->options->get_all();

		$available_post_types = array();
		foreach ( $this->post_types as $post_type ) {
			$pt = get_post_type_object( $post_type );
			if ( ! empty( $pt ) ) {
				array_push($available_post_types,["post_type_name"=>$pt->labels->name,"post_type_key"=>$pt->name]);
			}
		}

		$available_taxonomies = array();
		foreach ( $this->taxonomies as $taxonomy ) {
			$tx = get_taxonomy( $taxonomy );
			if ( ! empty( $tx ) ) {
				array_push($available_taxonomies,["taxonomy_name"=>$tx->labels->name,"taxonomy_key"=>$tx->name]);
			}
		}

		$disabled_post_types = array();
		foreach ( $this->disabled_post_types as $disabled_post_type ) {
			$pt = get_post_type_object( $disabled_post_type );
			if ( ! empty( $pt ) ) {
				array_push($disabled_post_types,["post_type_name"=>$pt->labels->name,"post_type_key"=>$pt->name]);
			}
		}
		$response['available_post_types'] = $available_post_types;
		$response['available_taxonomies'] = $available_taxonomies;
		$response['disabled_post_types'] = $disabled_post_types;
		$response['lmat_video_status'] = get_option('lmat_video_status');
		$response['lmat_migration_completed'] = get_option('lmat_migration_completed', false);
		$response['lmat_setup_complete'] = $this->sanitize_boolean_param( get_option( 'lmat_setup_complete', false ) );
		// Check CPFM opt-in choice and feedback data option (default to true if not explicitly set)
		$raw_options = get_option( 'linguator' );
		$is_setup_complete = get_option( 'lmat_setup_complete' );
		if ( is_array( $raw_options ) && array_key_exists( 'lmat_feedback_data', $raw_options ) ) {
			$response['lmat_feedback_data'] = (bool) $raw_options['lmat_feedback_data'];
		} elseif ( ! $is_setup_complete ) {
			$response['lmat_feedback_data'] = true;
		} else {
			$cpfm_opt_in_choice = linguator_get_cpfm_opt_in_choice();
			$response['lmat_feedback_data'] = ( 'no' !== $cpfm_opt_in_choice );
		}

		// Never return raw API keys over REST; return masked values so the UI can show "configured".
		// Keys live in dedicated WP options `connectors_ai_google_api_key`.
		$gemini_raw = trim( (string) get_option( 'connectors_ai_google_api_key', '' ) );
		$gemini_masked = '';
		if ( '' !== $gemini_raw ) {
			$tail          = substr( $gemini_raw, -4 );
			$gemini_masked = '••••••••' . $tail;
		}

		$models = $this->options->get( 'api_keys' );
		if ( ! is_array( $models ) ) {
			$models = array();
		}
		$ai_config  = $this->options->get( 'ai_translation_configuration' );
		$providers  = isset( $ai_config['provider'] ) && is_array( $ai_config['provider'] ) ? $ai_config['provider'] : array();
		$gemini_on  = ! empty( $providers['gemini'] );
		$has_key    = ( '' !== $gemini_raw );
		$available_models = array(
			'gemini' => array()
		);
		if ( $has_key ) {
			$available_models = Api_Keys_Option::get_stored_provider_models();
		}

		$this->ai_gemini_model_refresh_needed = false;

		$response['api_keys_configuration'] = array(
			'keys'             => array(
				'gemini' => $gemini_masked,
			),
			'models'           => $models,
			'available_models' => $available_models,
		);
		
		return $response;
	}

	/**
	 * Sanitize provider exceptions for safe UI display.
	 *
	 * @param string $message Raw exception message.
	 * @param string $api_key Raw API key (to redact if present).
	 * @return string
	 */
	private function sanitize_provider_error_message( string $message, string $api_key ): string {
		$msg = trim( (string) $message );
		if ( '' === $msg ) {
			return __( 'Provider returned an unknown error.', 'linguator-pro' );
		}

		$key_trimmed = trim( (string) $api_key );
		if ( '' !== $key_trimmed ) {
			$msg = str_replace( $key_trimmed, '[redacted]', $msg );
		}

		// Keep responses reasonably small for REST/UI.
		if ( strlen( $msg ) > 500 ) {
			$msg = substr( $msg, 0, 500 ) . '…';
		}

		return $msg;
	}

	/**
	 * Validate Gemini API key via WP AI Client before saving.
	 *
	 * @param string $api_key Raw API key.
	 * @return true|WP_Error
	 */
	private function validate_gemini_api_key( string $api_key ) {
		// Normalize pasted keys: strip accidental whitespace/newlines only.
		$key_trimmed = preg_replace( '/\s+/', '', (string) $api_key );
		if ( '' === $key_trimmed ) {
			return true;
		}

		// Reject control characters and markup-breaking chars; do not enforce provider-specific format.
		if ( preg_match( '/[\x00-\x1F\x7F<>"\']/', $key_trimmed ) ) {
			return new WP_Error(
				'lmat_api_key_invalid',
				__( 'Invalid API key format. Please check your credentials.', 'linguator-pro' ),
				array( 'status' => 400 )
			);
		}

		// Generous upper bound to avoid storing abnormally large pasted strings.
		if ( strlen( $key_trimmed ) > 512 ) {
			return new WP_Error(
				'lmat_api_key_invalid',
				__( 'API key is too long. Please check your credentials.', 'linguator-pro' ),
				array( 'status' => 400 )
			);
		}

		// Validate against the provider; Google defines the real key format.
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return new WP_Error(
				'lmat_ai_client_missing',
				__( 'AI client is not available.', 'linguator-pro' ),
				array( 'status' => 400 )
			);
		}

		$registry = \WordPress\AiClient\AiClient::defaultRegistry();
		if ( ! $registry || ! method_exists( $registry, 'hasProvider' ) || ! $registry->hasProvider( 'google' ) ) {
			return new WP_Error(
				'lmat_ai_provider_invalid',
				__( 'Invalid AI provider.', 'linguator-pro' ),
				array( 'status' => 400 )
			);
		}

		$debounce_key     = 'lmat_ai_test_lock_google_' . md5( $key_trimmed );
		$rate_limit_key   = 'lmat_ai_rate_limit_google_' . md5( $key_trimmed );
		$debounce_seconds = 30;

		if ( get_transient( $rate_limit_key ) ) {
			return new WP_Error(
				'lmat_ai_provider_rate_limited',
				__( 'Gemini free tier rate limit exceeded. Please wait and try again.', 'linguator-pro' ),
				array( 'status' => 429 )
			);
		}

		if ( get_transient( $debounce_key ) ) {
			return new WP_Error(
				'lmat_api_key_test_cooldown',
				__( 'Please wait 30 seconds and try again.', 'linguator-pro' ),
				array( 'status' => 400 )
			);
		}

		set_transient( $debounce_key, 1, $debounce_seconds );

		$auth_class = '\WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication';
		if ( ! class_exists( $auth_class ) ) {
			delete_transient( $debounce_key );
			return new WP_Error(
				'lmat_ai_client_missing',
				__( 'AI client is not available.', 'linguator-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( method_exists( $registry, 'setProviderRequestAuthentication' ) ) {
			$registry->setProviderRequestAuthentication( 'google', new $auth_class( $key_trimmed ) );
		}

		try {
			$provider_classname = $registry->getProviderClassName( 'google' );
			if ( ! $provider_classname || ! class_exists( $provider_classname ) ) {
				delete_transient( $debounce_key );
				return new WP_Error(
					'lmat_ai_provider_invalid',
					__( 'Invalid AI provider.', 'linguator-pro' ),
					array( 'status' => 400 )
				);
			}

			if ( method_exists( $provider_classname, 'availability' ) ) {
				$provider_availability = $provider_classname::availability();
				if ( is_object( $provider_availability ) && method_exists( $provider_availability, 'isConfigured' ) && ! $provider_availability->isConfigured() ) {
					delete_transient( $debounce_key );
					return new WP_Error(
						'lmat_api_key_invalid',
						__( 'Invalid API key. Please check API key and try again.', 'linguator-pro' ),
						array( 'status' => 400 )
					);
				}
			}

			if ( method_exists( $provider_classname, 'modelMetadataDirectory' ) ) {
				$model_metadata_directory = $provider_classname::modelMetadataDirectory();
				if ( is_object( $model_metadata_directory ) && method_exists( $model_metadata_directory, 'listModelMetadata' ) ) {
					$model_metadata_directory->listModelMetadata(); // throws on invalid key.
				}
			}
		} catch ( \Exception $e ) {
			$msg = strtolower( (string) $e->getMessage() );
			$is_rate_limited =
				( false !== strpos( $msg, '429' ) ) ||
				( false !== strpos( $msg, 'too many requests' ) ) ||
				( false !== strpos( $msg, 'rate limit' ) ) ||
				( false !== strpos( $msg, 'ratelimit' ) );

			if ( $is_rate_limited ) {
				delete_transient( $debounce_key );
				set_transient( $rate_limit_key, 1, 60 );
				return new WP_Error(
					'lmat_ai_provider_rate_limited',
					__( 'Gemini free tier rate limit exceeded. Please wait and try again.', 'linguator-pro' ),
					array( 'status' => 429 )
				);
			}

			delete_transient( $debounce_key );

			return new WP_Error(
				'lmat_api_key_invalid',
				$this->sanitize_provider_error_message( (string) $e->getMessage(), $key_trimmed ),
				array( 'status' => 400 )
			);
		}

		delete_transient( $debounce_key );

		return true;
	}

	/**
	 * Clear Gemini API key validation locks (debounce / rate-limit) for a stored key value.
	 *
	 * @param string $api_key Raw or normalized API key.
	 */
	private function clear_gemini_api_key_validation_locks( string $api_key ) {
		$key_trimmed = preg_replace( '/\s+/', '', (string) $api_key );
		if ( '' === $key_trimmed ) {
			return;
		}
		$hash = md5( $key_trimmed );
		delete_transient( 'lmat_ai_test_lock_google_' . $hash );
		delete_transient( 'lmat_ai_rate_limit_google_' . $hash );
	}

	/**
	 * Updates option(s).
	 * This allows to update one or several options.
	 *
	 *  
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 *
	 * @phpstan-template T of array
	 * @phpstan-param WP_REST_Request<T> $request
	 */
	public function update_item( $request ) {
		$this->ai_gemini_model_refresh_needed = false;

		// Support saving AI provider keys/models via the Settings route.
		// Keys are stored in dedicated WP options `connectors_ai_google_api_key`,
		// while models are stored in the `api_keys` option (see Business\\Api_Keys).
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$incoming_keys   = isset( $params['keys'] ) && is_array( $params['keys'] ) ? $params['keys'] : array();
		$incoming_models = isset( $params['models'] ) && is_array( $params['models'] ) ? $params['models'] : array();

		// Handle Gemini key save/reset.
		if ( array_key_exists( 'gemini', $incoming_keys ) ) {
			$v = $incoming_keys['gemini'];
			$v = is_string( $v ) ? preg_replace( '/\s+/', '', $v ) : '';

			$current_raw  = trim( (string) get_option( 'connectors_ai_google_api_key', '' ) );
			$is_unchanged = ( '' !== $v && '' !== $current_raw && $current_raw === $v );

			// Validate only when setting a non-empty key AND it differs from the stored one.
			// Empty string is allowed for reset.
			if ( '' !== $v && ! $is_unchanged ) {
				$validation = $this->validate_gemini_api_key( $v );
				if ( is_wp_error( $validation ) ) {
					return $validation;
				}
			}

			if ( '' === $v && '' !== $current_raw ) {
				$this->clear_gemini_api_key_validation_locks( $current_raw );
			}

			update_option( 'connectors_ai_google_api_key', $v );
			if ( '' === $v && '' !== $current_raw ) {
				Api_Keys_Option::clear_gemini_models_list();
			} elseif ( '' !== $v && ! $is_unchanged ) {
				Api_Keys_Option::discover_provider_models();
				$this->ai_gemini_model_refresh_needed = false;
			}
		}

		$errors  = new WP_Error();
		$schema  = $this->options->get_schema();
		$options = array_intersect_key(
			$request->get_params(),
			rest_get_endpoint_args_for_schema( $schema, WP_REST_Server::EDITABLE ) // Remove fields with `readonly`.
		);

		// Allow saving provider models through Settings using the same payload shape as the Api Keys endpoint.
		if ( ! empty( $incoming_models ) && isset( $incoming_models['gemini_model'] ) ) {
			if ( ! isset( $options['api_keys'] ) || ! is_array( $options['api_keys'] ) ) {
				$options['api_keys'] = array();
			}
			$options['api_keys']['gemini_model'] = sanitize_text_field( (string) $incoming_models['gemini_model'] );
		}

		// Validate domains before saving if force_lang is set to 3 (domains)
		$validation_errors = $this->validate_domains_before_save( $options );
		if ( $validation_errors->has_errors() ) {
			return $this->add_status_to_error( $validation_errors );
		}else{
			// Get current force_lang value
			$current_force_lang = $this->options->get( 'force_lang' );
			$new_force_lang = isset( $options['force_lang'] ) ? $options['force_lang'] : $current_force_lang;

			if($current_force_lang !== $new_force_lang){
				$this->model->clean_languages_cache();
			}

		}

		foreach ( $options as $option_name => $new_value ) {
			$previous_value = $this->options->get( $option_name );

			if ( 'default_lang' === $option_name ) {
				$result = $this->languages->update_default( $new_value );
			} elseif ( 'post_types' === $option_name ) {
				// Handle post types with programmatically active ones
				$processed_value = $this->process_post_types_for_save( $new_value );
				$result = $this->options->set( $option_name, $processed_value );
			} else {
				$result = $this->options->set( $option_name, $new_value );
			}

			if ( $result->has_errors() ) {
				$errors->merge_from( $result );
				continue;
			}

			if ( $this->options->get( $option_name ) === $previous_value ) {
				continue;
			}

			switch ( $option_name ) {
				case 'rewrite':
				case 'force_lang':
				case 'hide_default':
					delete_option( 'rewrite_rules' );
					break;

				case 'redirect_lang':
					// Refresh language cache as the home urls depend on this option.
					$this->model->clean_languages_cache();
					break;
			
				case 'post_types':
				case 'taxonomies':
				case 'media_support':
					$this->trigger_mass_language_assignment( $option_name, $previous_value, $new_value );
					break;

				case 'lmat_feedback_data':
					$opt_in = (bool) $new_value;
					update_option( 'cpfm_opt_in_choice_cool_translations', $opt_in ? 'yes' : 'no' );
					if ( $opt_in ) {
						if ( class_exists( '\\CPFM_Usage_Cron' ) ) {
							\CPFM_Usage_Cron::cpfm_schedule_event( 'lmat_extra_data_update' );
							do_action( 'lmat_extra_data_update' );
						}
					}
					break;
			}
		}
		
		// Handle cron job scheduling/removal based on CPFM opt-in choice and data usage sharing
		$this->handle_cron_scheduling();
		
		if ( $errors->has_errors() ) {
			return $this->add_status_to_error( $errors );
		}

		// If this request also carried AI key/model payload, return the full settings response
		// (including `api_keys_configuration.available_models`) so the UI can update instantly
		// without issuing a second GET request.
		if ( ! empty( $incoming_keys ) || ! empty( $incoming_models ) ) {
			return $this->get_item( $request );
		}

		return $this->prepare_item_for_response( $this->options->get_all(), $request );
	}

	/**
	 * Handles cron job scheduling/removal based on CPFM opt-in choice and data usage sharing.
	 *
	 *  
	 */
	private function handle_cron_scheduling() {
		$cpfm_opt_in_choice = linguator_get_cpfm_opt_in_choice();
		$lmat_feedback_data = (bool) $this->options->get( 'lmat_feedback_data' );
		$is_setup_complete  = get_option( 'lmat_setup_complete' );
		
		// Determine if cron should be scheduled based on the conditions
		$should_schedule_cron = ( $is_setup_complete && ( 'yes' === $cpfm_opt_in_choice || $lmat_feedback_data ) );
		
		// Schedule or remove the cron job
		if ( $should_schedule_cron ) {
			if ( ! wp_next_scheduled( 'lmat_extra_data_update' ) ) {
				wp_schedule_event( time(), 'every_30_days', 'lmat_extra_data_update' );
			}
		} else {
			wp_clear_scheduled_hook( 'lmat_extra_data_update' );
		}
	}

	/**
	 * Validates domains before saving when force_lang is set to 3.
	 *
	 *  
	 *
	 * @param array $options The options being updated.
	 * @return WP_Error WP_Error object with validation errors, or empty if no errors.
	 */
	private function validate_domains_before_save( $options ) {
		$errors = new WP_Error();
		
		// Get current force_lang value
		$current_force_lang = $this->options->get( 'force_lang' );
		$new_force_lang = isset( $options['force_lang'] ) ? $options['force_lang'] : $current_force_lang;
		
		// Only validate if force_lang is being set to 3 (domains)
		if ( 3 !== (int) $new_force_lang ) {
			return $errors;
		}
		
		// Get domains being updated or current domains
		$domains = isset( $options['domains'] ) ? $options['domains'] : $this->options->get( 'domains' );
		
		if ( empty( $domains ) || ! is_array( $domains ) ) {
			$errors->add(
				'missing_domains',
				__( 'Domains are required when language is set from different domains.', 'linguator-pro' ),
				array( 'status' => 400 )
			);
			return $errors;
		}
		
		// Get all available languages
		$languages = $this->languages->get_list();
		$language_slugs = wp_list_pluck( $languages, 'slug' );
		
		// Validate each domain
		foreach ( $domains as $lang_slug => $domain_url ) {
			// Check if language exists
			if ( ! in_array( $lang_slug, $language_slugs, true ) ) {
				$errors->add(
					'invalid_language',
					// translators: %s is the language slug/code that was provided
					sprintf( __( 'Invalid language code: %s', 'linguator-pro' ), $lang_slug ),
					array( 'status' => 400 )
				);
				continue;
			}
			
			// Validate URL format
			if ( empty( $domain_url ) || ! is_string( $domain_url ) ) {
				$errors->add(
					'empty_domain',
					// translators: %s is the language slug/code that needs a domain URL
					sprintf( __( 'Domain URL is required for language: %s', 'linguator-pro' ), $lang_slug ),
					array( 'status' => 400 )
				);
				continue;
			}
			
			// Check if URL is valid
			$parsed_url = wp_parse_url( $domain_url );
			if ( false === $parsed_url || empty( $parsed_url['host'] ) ) {
				$errors->add(
					'invalid_domain_format',
					// translators: %1$s is the language slug/code, %2$s is the invalid domain URL provided
					sprintf( __( 'Invalid domain URL format for language %1$s: %2$s', 'linguator-pro' ), $lang_slug, $domain_url ),
					array( 'status' => 400 )
				);
				continue;
			}
			

			
		}
		
		// Check that all languages have domains
		foreach ( $language_slugs as $lang_slug ) {
			if ( empty( $domains[ $lang_slug ] ) ) {
				$errors->add(
					'missing_language_domain',
					// translators: %s is the language slug/code that is missing a domain URL
					sprintf( __( 'Domain URL is required for language: %s', 'linguator-pro' ), $lang_slug ),
					array( 'status' => 400 )
				);
			}
		}
		
		// Ping all URLs to make sure they are accessible - moved from Domains.php
		$failed_urls = array();
		foreach ( array_filter( $domains ) as $url ) {
			$ping_token = wp_hash( 'lmat_domain_ping|' . gmdate( 'YmdH' ) );
			$test_url   = add_query_arg(
				array(
					'lmat_ping_token' => $ping_token,
				),
				$url
			);
			$response = wp_remote_get( sanitize_url( $test_url ) );

			if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
				$failed_urls[] = $url;
			}
		}

		if ( ! empty( $failed_urls ) ) {
			// Blocking error - prevents save
			if ( 1 === count( $failed_urls ) ) {
				/* translators: %s is a URL. */
				$message = __( 'Linguator was unable to access the %s URL. Please check that the URL is valid.', 'linguator-pro' );
			} else {
				/* translators: %s is a list of URLs. */
				$message = __( 'Linguator was unable to access the %s URLs. Please check that the URLs are valid.', 'linguator-pro' );
			}
			$errors->add(
				'lmat_invalid_domains',
				sprintf( $message, wp_sprintf_l( '%l', $failed_urls ) ),
				array( 'status' => 400 )
			);
		}
		
		return $errors;
	}

	/**
	 * Checks if a given request has access to update the options.
	 *
	 *  
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to update the option, WP_Error object otherwise.
	 *
	 * @phpstan-template T of array
	 * @phpstan-param WP_REST_Request<T> $request
	 */
	public function update_item_permissions_check( $request ) {
		// Check user capabilities first
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden_context',
				__( 'Sorry, you are not allowed to edit options.', 'linguator-pro' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Verify nonce for non-GET requests
		$nonce_check = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce_check ) ) {
			return $nonce_check;
		}

		return true;
	}

	/**
	 * Checks if a given request has access to read the options.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to read options, WP_Error object otherwise.
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden_context',
				__( 'Sorry, you are not allowed to view options.', 'linguator-pro' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Prepares the option value for the REST response.
	 *
	 *  
	 *
	 * @param array           $item    Option values.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 *
	 * @phpstan-template T of array
	 * @phpstan-param array<non-falsy-string, mixed> $item
	 * @phpstan-param WP_REST_Request<T> $request
	 */
	public function prepare_item_for_response( $item, $request ) {
		$fields   = $this->get_fields_for_response( $request );
		$response = array();

		foreach ( $item as $option => $value ) {
			if ( rest_is_field_included( $option, $fields ) ) {
				$response[ $option ] = $value;
			}
		}
		
		/** @var WP_REST_Response */
		return rest_ensure_response( $response );
	}

	/**
	 * Process post types for saving, removing programmatically active ones.
	 *
	 *  
	 *
	 * @param array $post_types Post types from frontend.
	 * @return array Processed post types for saving.
	 */
	private function process_post_types_for_save( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return array();
		}
		
		// Get programmatically active post types
		$programmatically_active = array_unique( apply_filters( 'lmat_get_post_types', array(), false ) );
		
		// Remove programmatically active post types from the list to save
		// They should not be stored in options since they're handled by code
		return array_diff( $post_types, $programmatically_active );
	}

	/**
	 * Triggers mass language assignment for when they are updated.
	 *
	 *  
	 *
	 * @param string $type           The type of option being updated (post_types, taxonomies, media_support).
	 * @param mixed $previous_value Previous option value.
	 * @param mixed $new_value      New option value.
	 * @return void
	 */
	private function trigger_mass_language_assignment( $type, $previous_value, $new_value ) {
		// Get the default language
		$default_lang = $this->languages->get_default();
		if ( ! $default_lang ) {
			return;
		}
	
		switch ( $type ) {
			case 'post_types':
				$previous_value = is_array( $previous_value ) ? $previous_value : array();
				$new_value      = is_array( $new_value ) ? $new_value : array();
				$newly_added = array_diff( $new_value, $previous_value );
				if ( ! empty( $newly_added ) ) {
					// Only assign language to posts that don't already have one
					$posts_without_lang = $this->model->get_posts_with_no_lang( $newly_added, 1000 );
					if ( ! empty( $posts_without_lang ) ) {
						$this->model->translatable_objects
							->get( 'post' )
							->set_language_in_mass( $posts_without_lang, $default_lang );
					}
				}
				break;

			case 'taxonomies':
				$previous_value = is_array( $previous_value ) ? $previous_value : array();
				$new_value      = is_array( $new_value ) ? $new_value : array();
				$newly_added = array_diff( $new_value, $previous_value );
				if ( ! empty( $newly_added ) ) {
					$terms_without_lang = $this->model->get_terms_with_no_lang( $newly_added, 1000 );
					if ( ! empty( $terms_without_lang ) ) {
						$this->model->translatable_objects
							->get( 'term' )
							->set_language_in_mass( $terms_without_lang, $default_lang );
					}
				}
				break;

			case 'media_support':
				if ( ! (bool) $previous_value && (bool) $new_value ) {
					// Only assign language to media that don't already have one
					$media_without_lang = $this->model->get_posts_with_no_lang( array( 'attachment' ), 1000 );
					if ( ! empty( $media_without_lang ) ) {
						$this->model->translatable_objects
							->get( 'post' )
							->set_language_in_mass( $media_without_lang, $default_lang );
					}
				}
				break;
		}
	}

	/**
	 * Detects if a migration plugin is installed and has data to migrate (dynamic).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function detect_migration( $request ) {
		$plugin = $request->get_param( 'plugin' );

		if ( 'polylang' === $plugin ) {
			$migration = new Polylang_Migration( $this->model, $this->options );
			$detection = $migration->detect_polylang();
			$plugin_name = 'Polylang';
			$has_key = 'has_polylang';
		} elseif ( 'wpml' === $plugin ) {
			$migration = new WPML_Migration( $this->model, $this->options );
			$detection = $migration->detect_wpml();
			$plugin_name = 'WPML';
			$has_key = 'has_wpml';
		} else {
			return new WP_Error(
				'invalid_plugin',
				__( 'Invalid plugin specified.', 'linguator-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( false === $detection ) {
			return rest_ensure_response( array(
				$has_key => false,
				'message' => sprintf(
					/* translators: %s: Plugin name */
					__( 'No %s data found.', 'linguator-pro' ),
					$plugin_name
				),
			) );
		}

		return rest_ensure_response( $detection );
	}

	/**
	 * Performs migration from a plugin to Linguator (dynamic).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function migrate_plugin( $request ) {
		$plugin = $request->get_param( 'plugin' );
		$migrate_languages    = $request->get_param( 'migrate_languages' );
		$migrate_translations = $request->get_param( 'migrate_translations' );
		$migrate_settings     = $request->get_param( 'migrate_settings' );
		$migrate_strings      = $request->get_param( 'migrate_strings' );

		if ( 'polylang' === $plugin ) {
			$migration = new Polylang_Migration( $this->model, $this->options );
			$plugin_name = 'Polylang';
		} elseif ( 'wpml' === $plugin ) {
			$migration = new WPML_Migration( $this->model, $this->options );
			$plugin_name = 'WPML';
		} else {
			return new WP_Error(
				'invalid_plugin',
				__( 'Invalid plugin specified.', 'linguator-pro' ),
				array( 'status' => 400 )
			);
		}

		$results = $migration->migrate_all( $migrate_languages, $migrate_translations, $migrate_settings, $migrate_strings );

		if ( ! $results['success'] ) {
			return new WP_Error(
				'migration_failed',
				/* translators: %s: Plugin name */
				sprintf( __( 'Migration from %s completed with errors.', 'linguator-pro' ), $plugin_name ),
				array(
					'status' => 500,
					'data'   => $results,
				)
			);
		}

		return rest_ensure_response( array(
			'success' => true,
			/* translators: %s: Plugin name */
			'message' => sprintf( __( 'Migration from %s completed successfully.', 'linguator-pro' ), $plugin_name ),
			'data'    => $results,
		) );
	}

	/**
	 * Retrieves the options' schema, conforming to JSON Schema.
	 *
	 *  
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema(): array {
		return $this->add_additional_fields_schema( $this->options->get_schema() );
	}
}
