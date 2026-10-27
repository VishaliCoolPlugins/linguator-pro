<?php

namespace LinguatorPro\Modules\REST\V1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Includes\Capabilities\Capabilities;
use LinguatorPro\Includes\Services\Translation\Translation_Term_Model;
use LinguatorPro\Includes\Services\Media\Media_Translation_Service;
use LinguatorPro\Supported_Blocks\Supported_Blocks;
use LinguatorPro\Custom_Fields\Custom_Fields;
use Translation_Entry;
use Translations;
use WP_Error;
use WP_REST_Request;

if ( ! class_exists( 'Bulk_Translation' ) ) :
	/**
	 * Bulk_Translation
	 *
	 * @package LinguatorPro\Modules\Bulk_Translation
	 */
	class Bulk_Translation {


		/**
		 * The base name of the route.
		 *
		 * @var string
		 */
		private $namespace;

		/**
		 * The base name of the route.
		 *
		 * @var string
		 */
		private $rest_base;

		/**
		 * Constructor
		 *
		 * @param string $base_name The base name of the route.
		 */
		public function __construct( $model ) {
			$this->namespace = 'lmat/v1';
			$this->rest_base = 'bulk-translate';
		}

		/**
		 * Register the routes
		 */
		public function register_routes(): void {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<slug>[\w-]+):bulk-translate-entries',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'bulk_translate_entries' ),
					'permission_callback' => array( $this, 'linguator_permission_only_admins' ),
					'args'                => array(
						'ids'        => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this, 'sanitize_lmat_json_ids' ),
							'validate_callback' => array( $this, 'validate_lmat_json_ids' ),
						),
						'lang'       => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this, 'sanitize_lmat_json_langs' ),
							'validate_callback' => array( $this, 'validate_lmat_json_langs' ),
						),
						'privateKey' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_lmat_bulk_nonce' ),
						),
					),
				)
			);

			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<slug>[\w-]+):bulk-translate-taxonomy-entries',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'bulk_translate_taxonomy_entries' ),
					'permission_callback' => array( $this, 'linguator_permission_only_admins' ),
					'args'                => array(
						'taxonomy'   => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => array( $this, 'validate_taxonomy_param' ),
						),
						'lang'       => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this, 'sanitize_lmat_json_langs' ),
							'validate_callback' => array( $this, 'validate_lmat_json_langs' ),
						),
						'privateKey' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_lmat_bulk_nonce' ),
						),
						'ids'        => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this, 'sanitize_lmat_json_ids' ),
							'validate_callback' => array( $this, 'validate_lmat_json_ids' ),
						),
					),
				)
			);

			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<post_id>[\w-]+):create-translate-post',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'linguator_create_translate_post' ),
					'permission_callback' => array( $this, 'linguator_permission_only_admins' ),
					'args'                => array(
						'privateKey'      => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_lmat_create_post_nonce' ),
						),
						'post_id'         => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => array( $this, 'validate_positive_int_param' ),
						),
						'target_language' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => array( $this, 'validate_required_slug_param' ),
						),
						'editor_type'     => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_editor_type_param' ),
						),
						'source_language' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => array( $this, 'validate_required_slug_param' ),
						),
						'post_title'      => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_optional_string_param' ),
						),
						'post_content'    => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_post_content_for_builders' ),
							'validate_callback' => array( $this, 'validate_optional_string_param' ),
						),
						'post_meta_fields' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_post_meta_fields_param' ),
							'validate_callback' => array( $this, 'validate_post_meta_fields_param' ),
						),
						'featured_image'   => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_media_translations_param' ),
							'validate_callback' => array( $this, 'validate_media_translations_param' ),
						),
						'content_media'     => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_media_translations_param' ),
							'validate_callback' => array( $this, 'validate_media_translations_param' ),
						),
						'elementor_media'   => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_media_translations_param' ),
							'validate_callback' => array( $this, 'validate_media_translations_param' ),
						),
					),
				)
			);

			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<term_id>[\w-]+):create-translate-taxonomy',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_translate_taxonomy' ),
					'permission_callback' => array( $this, 'linguator_permission_only_admins' ),
					'args'                => array(
						'term_id'              => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => array( $this, 'validate_positive_int_param' ),
						),
						'privateKey'           => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_lmat_create_term_nonce' ),
						),
						'target_language'      => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => array( $this, 'validate_required_slug_param' ),
						),
						'source_language'      => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => array( $this, 'validate_required_slug_param' ),
						),
						'taxonomy'             => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => array( $this, 'validate_taxonomy_param' ),
						),
						'taxonomy_name'        => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_required_text_param' ),
						),
						'taxonomy_slug'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_optional_string_param' ),
						),
						'taxonomy_description' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => array( $this, 'sanitize_taxonomy_description_param' ),
							'validate_callback' => array( $this, 'validate_optional_string_param' ),
						),
					),
				)
			);

			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/ai-translate-batch',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'ai_translate_batch' ),
					'permission_callback' => array( $this, 'ai_translate_batch_permissions_check' ),
				)
			);
		}

		/**
		 * REST permission for AI string batch translation (post or taxonomy term as object).
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return true|\WP_Error
		 */
		public function ai_translate_batch_permissions_check( $request ) {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error( 'rest_forbidden', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 401 ) );
			}

			$nonce = sanitize_text_field( wp_unslash( (string) $request->get_header( 'X-WP-Nonce' ) ) );
			if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new WP_Error( 'rest_forbidden', __( 'Invalid nonce.', 'linguator-pro' ), array( 'status' => 403 ) );
			}

			if ( ! current_user_can( Capabilities::TRANSLATIONS ) ) {
				return new \WP_Error( 'rest_forbidden', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
			}

			return true;
		}

		/**
		 * Batch-translate string map via configured LLM (Gemini).
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function ai_translate_batch( $request ) {
			if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
				return new WP_Error(
					'lmat_ai_unavailable',
					__( 'WordPress AI Client is not available. Install or enable the AI Client and provider packages.', 'linguator-pro' ),
					array( 'status' => 501 )
				);
			}

			$params = $request->get_json_params();
			if ( ! is_array( $params ) ) {
				$params = array();
			}

			$provider    = isset( $params['provider'] ) ? sanitize_key( (string) $params['provider'] ) : '';
			$post_id     = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
			$source_lang = isset( $params['source_lang'] ) ? sanitize_key( (string) $params['source_lang'] ) : '';
			$target_lang = isset( $params['target_lang'] ) ? sanitize_key( (string) $params['target_lang'] ) : '';
			$strings     = isset( $params['strings'] ) && is_array( $params['strings'] ) ? $params['strings'] : array();
			$object_type = isset( $params['object_type'] ) ? sanitize_key( (string) $params['object_type'] ) : 'post';
			$model       = isset( $params['model'] ) ? sanitize_text_field( (string) $params['model'] ) : '';

			if ( 'gemini' !== $provider ) {
				return new WP_Error( 'lmat_ai_invalid_provider', __( 'Invalid translation provider.', 'linguator-pro' ), array( 'status' => 400 ) );
			}

			if ( $post_id <= 0 || '' === $source_lang || '' === $target_lang || empty( $strings ) ) {
				return new WP_Error( 'lmat_ai_invalid_params', __( 'Missing required translation parameters.', 'linguator-pro' ), array( 'status' => 400 ) );
			}

			$access = $this->ai_translate_batch_verify_object_access( $post_id, $object_type );
			if ( is_wp_error( $access ) ) {
				return $access;
			}

			$ai_config = array();
			if ( property_exists( LMATP(), 'options' ) && isset( LMATP()->options['ai_translation_configuration'] ) && is_array( LMATP()->options['ai_translation_configuration'] ) ) {
				$ai_config = LMATP()->options['ai_translation_configuration'];
			}

			$enabled = isset( $ai_config['provider'][ $provider ] ) && $ai_config['provider'][ $provider ];
			if ( ! $enabled ) {
				return new WP_Error( 'lmat_ai_provider_disabled', __( 'This AI provider is not enabled in translation settings.', 'linguator-pro' ), array( 'status' => 400 ) );
			}

			$key_option = 'connectors_ai_google_api_key';
			$api_key    = (string) get_option( $key_option, '' );
			if ( '' === trim( $api_key ) ) {
				return new WP_Error( 'lmat_ai_no_key', __( 'Please provide a valid API key for the selected provider.', 'linguator-pro' ), array( 'status' => 400 ) );
			}

			$sanitized_strings = array();
			foreach ( $strings as $k => $v ) {
				$key = sanitize_text_field( (string) $k );
				if ( '' === $key ) {
					continue;
				}
				if ( ! is_string( $v ) ) {
					$v = wp_json_encode( $v );
				}
				$sanitized_strings[ $key ] = $v;
			}

			if ( empty( $sanitized_strings ) ) {
				return new WP_Error( 'lmat_ai_invalid_params', __( 'No translatable strings in request.', 'linguator-pro' ), array( 'status' => 400 ) );
			}

			$result = $this->ai_translate_strings_with_llm(
				$provider,
				$source_lang,
				$target_lang,
				$sanitized_strings,
				$api_key,
				$model
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response( array( 'translations' => $result ) );
		}

		/**
		 * @param int    $object_id   Post or term ID.
		 * @param string $object_type post|term.
		 * @return true|\WP_Error
		 */
		private function ai_translate_batch_verify_object_access( int $object_id, string $object_type ) {
			if ( 'term' === $object_type ) {
				$term = get_term( $object_id );
				if ( ! $term || is_wp_error( $term ) ) {
					return new WP_Error( 'rest_forbidden', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
				}
				if ( ! current_user_can( 'edit_term', $object_id ) ) {
					return new WP_Error( 'rest_forbidden', __( 'You are not authorized to edit this term.', 'linguator-pro' ), array( 'status' => 403 ) );
				}
				return true;
			}

			$post = get_post( $object_id );
			if ( ! $post ) {
				return new WP_Error( 'rest_forbidden', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
			}
			if ( ! current_user_can( 'edit_post', $object_id ) ) {
				return new WP_Error( 'rest_forbidden', __( 'You are not allowed to edit this content.', 'linguator-pro' ), array( 'status' => 403 ) );
			}
			$post_type_object = get_post_type_object( $post->post_type );
			if ( ! $post_type_object || empty( $post_type_object->cap->create_posts ) ) {
				return new WP_Error( 'rest_forbidden', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
			}
			if ( ! current_user_can( $post_type_object->cap->create_posts ) ) {
				return new WP_Error( 'rest_forbidden', __( 'You are not allowed to create translations for this post type.', 'linguator-pro' ), array( 'status' => 403 ) );
			}
			return true;
		}

		/**
		 * @param string               $provider    gemini.
		 * @param string               $source_lang Slug
		 * @param string               $target_lang Slug
		 * @param array<string,string> $strings     Key => source text.
		 * @return array<string,string>|\WP_Error
		 */
		private function ai_translate_strings_with_llm( string $provider, string $source_lang, string $target_lang, array $strings, string $api_key, string $model_override = '', int $split_depth = 0 ) {
			$model_id = $this->ai_translate_resolve_llm_model_id( $model_override );

			$provider_setup = $this->ai_translate_prepare_llm_provider( $provider, $api_key );
			if ( is_wp_error( $provider_setup ) ) {
				return $provider_setup;
			}

			$registry    = $provider_setup['registry'];
			$provider_id = $provider_setup['provider_id'];

			$instruction = $this->ai_translate_build_llm_prompt( $source_lang, $target_lang, $strings );
			if ( is_wp_error( $instruction ) ) {
				return $instruction;
			}

			$text = $this->ai_translate_call_llm_provider( $registry, $provider_id, $model_id, $instruction );
			if ( is_wp_error( $text ) ) {
				return $this->ai_translate_handle_llm_call_error(
					$text,
					$provider,
					$source_lang,
					$target_lang,
					$strings,
					$api_key,
					$model_override,
					$split_depth
				);
			}

			return $this->ai_translate_parse_llm_response( (string) $text, $strings );
		}

		/**
		 * Resolve Gemini model id from override, options, or default.
		 *
		 * @param string $model_override Optional model override.
		 * @return string
		 */
		private function ai_translate_resolve_llm_model_id( string $model_override = '' ): string {
			$models = array();
			if ( property_exists( LMATP(), 'options' ) ) {
				$m = LMATP()->model->options->get( 'api_keys' );
				if ( is_array( $m ) ) {
					$models = $m;
				}
			}

			$model_key      = 'gemini_model';
			$model_defaults = array(
				'gemini_model' => 'gemini-2.5-flash',
			);
			$model_id = trim( $model_override );
			if ( '' === $model_id ) {
				$model_id = isset( $models[ $model_key ] ) ? trim( (string) $models[ $model_key ] ) : '';
			}
			if ( '' === $model_id && isset( $model_defaults[ $model_key ] ) ) {
				$model_id = $model_defaults[ $model_key ];
			}

			return $model_id;
		}

		/**
		 * Validate AI client/provider and inject API key authentication.
		 *
		 * @param string $provider Provider slug (gemini maps to google).
		 * @param string $api_key  API key.
		 * @return array{registry: object, provider_id: string}|\WP_Error
		 */
		private function ai_translate_prepare_llm_provider( string $provider, string $api_key ) {
			$provider_id = ( 'gemini' === $provider ) ? 'google' : $provider;
			if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
				return new WP_Error(
					'lmat_ai_client_missing',
					__( 'AI client is not available.', 'linguator-pro' ),
					array( 'status' => 400 )
				);
			}

			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			if ( ! $registry || ! method_exists( $registry, 'hasProvider' ) || ! $registry->hasProvider( $provider_id ) ) {
				return new WP_Error(
					'lmat_ai_provider_invalid',
					__( 'Invalid AI provider.', 'linguator-pro' ),
					array( 'status' => 400 )
				);
			}

			$auth_class = '\WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication';
			if ( ! class_exists( $auth_class ) ) {
				return new WP_Error(
					'lmat_ai_client_missing',
					__( 'AI client is not available.', 'linguator-pro' ),
					array( 'status' => 400 )
				);
			}

			// Inject key for this request so prompt builder can resolve models.
			$registry->setProviderRequestAuthentication( $provider_id, new $auth_class( trim( $api_key ) ) );

			return array(
				'registry'    => $registry,
				'provider_id' => $provider_id,
			);
		}

		/**
		 * Build glossary-aware translation prompt for the LLM.
		 *
		 * @param string               $source_lang Source language slug.
		 * @param string               $target_lang Target language slug.
		 * @param array<string,string> $strings     Key => source text.
		 * @return string|\WP_Error
		 */
		private function ai_translate_build_llm_prompt( string $source_lang, string $target_lang, array $strings ) {
			$payload = wp_json_encode( $strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( false === $payload ) {
				return new WP_Error( 'lmat_ai_encode_error', __( 'Could not prepare translation payload.', 'linguator-pro' ), array( 'status' => 500 ) );
			}

			$glossary_instructions = $this->ai_translate_build_glossary_instructions( $source_lang, $target_lang, $strings );

			$instruction = sprintf(
				'You are a professional translator.
				Source Language: %s
				Target Language: %s
				Instruction 1: Translate visible text content semantically from %s into %s language. Provide a proper meaning-based translation.
				Instruction 2: Do not translate or modify any content inside square brackets [] and Do not translate any URL. These are shortcodes or dynamic placeholders and must remain exactly as they are.
				Instruction 3: Preserve all HTML tags and their attributes such as class, id, data-*, etc. Do not alter any part of the HTML structure.
				Instruction 4: Return the translation in the format of a JSON object with the keys being numeric values (matching the source keys), and the values being the translated strings.
				Instruction 5: Do not escape double quotes with backslashes. Output must be valid JSON without extra slashes.
				Instruction 6: Translate the provided JSON array from %s into %s language, regardless of whether the values are the same, and ensure the JSON is well-formed and complete.
				Instruction 7: Decode any &lt; and &gt; HTML entities back to < and > symbols in the output and preserve and maintain whitespace.
				Instruction 8: Return the output as a valid JSON object. Do not wrap the output in a string or markdown code block. Ensure the JSON is clean, parseable, and properly formatted.

				Please ensure that the output follows the format: {"key(numeric value)": "(translations of the strings in %s language)"}

				Strings are :- %s',
				sanitize_text_field( $source_lang ),
				sanitize_text_field( $target_lang ),
				sanitize_text_field( $source_lang ),
				sanitize_text_field( $target_lang ),
				sanitize_text_field( $source_lang ),
				sanitize_text_field( $target_lang ),
				sanitize_text_field( $target_lang ),
				$payload
			);

			if ( '' !== $glossary_instructions ) {
				$instruction .= 'Instruction 9: ' . $glossary_instructions;
			}

			return $instruction;
		}

		/**
		 * Build glossary instruction block when matched terms exist for the payload.
		 *
		 * @param string               $source_lang Source language slug.
		 * @param string               $target_lang Target language slug.
		 * @param array<string,string> $strings     Key => source text.
		 * @return string
		 */
		private function ai_translate_build_glossary_instructions( string $source_lang, string $target_lang, array $strings ): string {
			$glossary_data = get_option( 'lmat_glossary_data', array() );
			if ( empty( $glossary_data ) || ! is_array( $glossary_data ) ) {
				return '';
			}

			$has_glossary_terms = false;
			foreach ( $strings as $string ) {
				foreach ( $glossary_data as $entry ) {
					if (
						is_array( $entry ) &&
						! empty( $entry['original_term'] ) &&
						isset( $entry['original_language_code'] ) &&
						$entry['original_language_code'] === $source_lang &&
						stripos( (string) $string, (string) $entry['original_term'] ) !== false
					) {
						$has_glossary_terms = true;
						break 2;
					}
				}
			}

			if ( ! $has_glossary_terms ) {
				return '';
			}

			$matched_terms = array();
			foreach ( $glossary_data as $entry ) {
				if (
					! is_array( $entry ) ||
					empty( $entry['original_language_code'] ) ||
					empty( $entry['original_term'] ) ||
					empty( $entry['translations'] ) ||
					$entry['original_language_code'] !== $source_lang
				) {
					continue;
				}

				$translations_by_code = array();
				foreach ( $entry['translations'] as $translation ) {
					if (
						is_array( $translation ) &&
						! empty( $translation['target_language_code'] ) &&
						! empty( $translation['translated_term'] )
					) {
						$translations_by_code[ $translation['target_language_code'] ] = $translation['translated_term'];
					}
				}

				$term_found = false;
				foreach ( $strings as $string ) {
					if ( stripos( (string) $string, (string) $entry['original_term'] ) !== false ) {
						$term_found = true;
						break;
					}
				}

				if ( $term_found && isset( $translations_by_code[ $target_lang ] ) ) {
					$matched_terms[] = array(
						'term'        => $entry['original_term'],
						'translation' => $translations_by_code[ $target_lang ],
						'description' => $entry['description'] ?? '',
					);
				}
			}

			if ( empty( $matched_terms ) ) {
				return '';
			}

			$glossary_instructions = "Please use the following glossary terms in your translation:\n";
			foreach ( $matched_terms as $term ) {
				$src_term    = isset( $term['term'] ) ? (string) $term['term'] : '';
				$translation = isset( $term['translation'] ) ? (string) $term['translation'] : '';
				$description = isset( $term['description'] ) ? (string) $term['description'] : '';

				$glossary_instructions .= '- "' . $src_term . '" -> "' . $translation . '"';
				if ( '' !== $description ) {
					$glossary_instructions .= ' - Note: ' . $description;
				}
				$glossary_instructions .= "\n";
			}

			return $glossary_instructions;
		}

		/**
		 * Call the WP AI Client provider to generate translation text.
		 *
		 * @param object $registry    AI client registry.
		 * @param string $provider_id Provider id in the registry.
		 * @param string $model_id    Optional model id.
		 * @param string $instruction Prompt text.
		 * @return string|\WP_Error
		 */
		private function ai_translate_call_llm_provider( $registry, string $provider_id, string $model_id, string $instruction ) {
			$ai_request_timeout = absint( get_option( 'lmat_ai_request_timeout', 120 ) );
			if ( $ai_request_timeout < 1 ) {
				$ai_request_timeout = 120;
			}

			$timeout_filter = static function ( $time ) use ( $ai_request_timeout ) {
				return $ai_request_timeout;
			};
			add_filter( 'wp_ai_client_default_request_timeout', $timeout_filter, 10, 1 );

			$text = null;

			try {
				if ( method_exists( $registry, 'isProviderConfigured' ) && ! $registry->isProviderConfigured( $provider_id ) ) {
					return new WP_Error(
						'lmat_ai_no_key',
						__( 'Please provide a valid API key for the selected provider.', 'linguator-pro' ),
						array( 'status' => 400 )
					);
				}

				$provider_class = $registry->getProviderClassName( $provider_id );
				if ( ! is_string( $provider_class ) || ! class_exists( $provider_class ) ) {
					return new WP_Error(
						'lmat_ai_provider_invalid',
						__( 'Invalid AI provider.', 'linguator-pro' ),
						array( 'status' => 400 )
					);
				}

				$builder = wp_ai_client_prompt();

				$canUseProviderChain = method_exists( $builder, 'using_provider' )
					&& method_exists( $builder, 'with_text' )
					&& method_exists( $builder, 'generate_text' )
					&& ( '' === $model_id || method_exists( $builder, 'using_model' ) );

				if ( $canUseProviderChain ) {
					if ( '' !== $model_id ) {
						try {
							$model   = $provider_class::model( $model_id );
							$builder = $builder->using_model( $model );
						} catch ( \Throwable $e ) {
							return new WP_Error(
								'lmat_ai_invalid_model',
								__( 'Invalid model selected during text generation.', 'linguator-pro' ),
								array( 'status' => 400 )
							);
						}
					}

					try {
						$text = $builder
							->using_provider( $provider_id )
							->with_text( $instruction )
							->generate_text();
					} catch ( \Throwable $e ) {
						return $this->ai_translate_map_generate_text_exception( $e );
					}
				} else {
					// Older WP AI Client: fall back to model preference + single-message prompt.
					if ( method_exists( $builder, 'using_system_instruction' ) ) {
						$builder = $builder->using_system_instruction( __( 'You are a professional translator. Output only valid JSON objects.', 'linguator-pro' ) );
					}
					if ( method_exists( $builder, 'with_text' ) ) {
						$builder = $builder->with_text( $instruction );
					} else {
						$builder = wp_ai_client_prompt( $instruction );
					}

					if ( '' !== $model_id && method_exists( $builder, 'using_model_preference' ) ) {
						$builder = $builder->using_model_preference( $model_id );
					}

					try {
						$text = $builder->generate_text();
					} catch ( \Throwable $e ) {
						return $this->ai_translate_map_generate_text_exception( $e );
					}
				}
			} finally {
				remove_filter( 'wp_ai_client_default_request_timeout', $timeout_filter, 10, 1 );
			}

			return $text;
		}

		/**
		 * Handle LLM call failures, including recursive split retries on timeout.
		 *
		 * @param \WP_Error            $error          Provider/call error.
		 * @param string               $provider       Provider slug.
		 * @param string               $source_lang    Source language slug.
		 * @param string               $target_lang    Target language slug.
		 * @param array<string,string> $strings        Key => source text.
		 * @param string               $api_key        API key.
		 * @param string               $model_override Model override.
		 * @param int                  $split_depth    Current recursion depth.
		 * @return array<string,string>|\WP_Error
		 */
		private function ai_translate_handle_llm_call_error( WP_Error $error, string $provider, string $source_lang, string $target_lang, array $strings, string $api_key, string $model_override, int $split_depth ) {
			if ( $this->ai_translate_is_timeout_error( $error ) ) {
				$string_count = count( $strings );
				if ( $string_count > 1 && $split_depth < 3 ) {
					$chunks = $this->ai_translate_split_string_map( $strings );
					if ( 2 === count( $chunks ) ) {
						$left = $this->ai_translate_strings_with_llm(
							$provider,
							$source_lang,
							$target_lang,
							$chunks[0],
							$api_key,
							$model_override,
							$split_depth + 1
						);
						if ( is_wp_error( $left ) ) {
							return $left;
						}

						$right = $this->ai_translate_strings_with_llm(
							$provider,
							$source_lang,
							$target_lang,
							$chunks[1],
							$api_key,
							$model_override,
							$split_depth + 1
						);
						if ( is_wp_error( $right ) ) {
							return $right;
						}

						return $left + $right;
					}
				}

				return new WP_Error(
					'lmat_ai_request_timeout',
					__( 'The AI provider request timed out. Please retry in a moment or translate fewer strings at once.', 'linguator-pro' ),
					array( 'status' => 503 )
				);
			}

			return $error;
		}

		/**
		 * Parse LLM text response into a key => translated string map.
		 *
		 * @param string               $text    Raw provider response text.
		 * @param array<string,string> $strings Original key => source text map.
		 * @return array<string,string>|\WP_Error
		 */
		private function ai_translate_parse_llm_response( string $text, array $strings ) {
			$clean_text = preg_replace( '/(^```json\n|```$)/', '', $text );
			$final_text = preg_replace( '/\\\\{2,}([\'"n])/', '\\\$1', (string) $clean_text );

			if ( is_string( $final_text ) ) {
				$maybe_decoded = json_decode( $final_text, true );
				if ( is_array( $maybe_decoded ) && 1 === count( $maybe_decoded ) ) {
					$key = array_keys( $maybe_decoded )[0];
					if ( isset( $maybe_decoded[ $key ] ) && is_string( $maybe_decoded[ $key ] ) ) {
						$inner = json_decode( $maybe_decoded[ $key ], true );
						if ( is_array( $inner ) && isset( $inner[ $key ] ) && ! is_array( $inner[ $key ] ) ) {
							$maybe_decoded[ $key ] = (string) $inner[ $key ];
							$final_text            = wp_json_encode( $maybe_decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
						}
					}
				}
			}

			if ( is_string( $final_text ) && ( str_starts_with( $final_text, '"' ) || str_ends_with( $final_text, '"' ) ) ) {
				$final_text = trim( $final_text, '"' );
			}

			$decoded = $this->ai_translate_parse_json_object( (string) $final_text );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}

			$out = array();
			foreach ( array_keys( $strings ) as $key ) {
				if ( isset( $decoded[ $key ] ) && is_scalar( $decoded[ $key ] ) ) {
					$out[ $key ] = $this->ai_normalize_translation_string( (string) $decoded[ $key ] );
				} else {
					$out[ $key ] = $strings[ $key ];
				}
			}

			return $out;
		}

		/**
		 * Turn literal \n, \r, \t (backslash + letter) into real control characters after JSON decode.
		 * Matches client `normalizeBulkTranslationEscapes`; repeats until stable (max 5 passes).
		 * Strips spurious surrounding ASCII double quotes (up to three layers) some models add per value.
		 *
		 * @param string $value Raw decoded string from the model.
		 * @return string
		 */
		private function ai_normalize_translation_string( string $value ): string {
			$out = $value;
			for ( $i = 0; $i < 5; $i++ ) {
				$next = str_replace( array( '\\n', '\\r', '\\t' ), array( "\n", "\r", "\t" ), $out );
				if ( $next === $out ) {
					break;
				}
				$out = $next;
			}
			// Strip spurious surrounding ASCII double quotes the model sometimes leaves on decoded values.
			for ( $j = 0; $j < 3; $j++ ) {
				$t = trim( $out );
				$len = strlen( $t );
				if ( $len >= 2 && '"' === $t[0] && '"' === $t[ $len - 1 ] ) {
					$out = substr( $t, 1, -1 );
				} else {
					break;
				}
			}
			return $out;
		}

		/**
		 * Recursively normalize literal \n, \r, \t in all string leaves (blocks JSON, meta, Elementor trees).
		 *
		 * @param mixed $data Decoded array or scalar.
		 * @return mixed
		 */
		private function ai_normalize_translation_strings_recursive( $data ) {
			if ( is_string( $data ) ) {
				return $this->ai_normalize_translation_string( $data );
			}
			if ( is_array( $data ) ) {
				foreach ( $data as $k => $v ) {
					$data[ $k ] = $this->ai_normalize_translation_strings_recursive( $v );
				}
				return $data;
			}
			return $data;
		}

		/**
		 * @param string $text Raw model output.
		 * @return array<string,mixed>|\WP_Error
		 */
		private function ai_translate_parse_json_object( string $text ) {
			$text = trim( $text );
			$text = html_entity_decode( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
			if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m ) ) {
				$text = $m[1];
			} elseif ( preg_match( '/\{[\s\S]*\}/', $text, $m ) ) {
				$text = $m[0];
			}

			$decoded = json_decode( $text, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				return new WP_Error(
					'lmat_ai_bad_response',
					__( 'The AI returned an invalid translation response. Please try again.', 'linguator-pro' ),
					array( 'status' => 502 )
				);
			}

			return $decoded;
		}

		/**
		 * Map generate_text() failures to WP_Error
		 *
		 * @param \Throwable $e Thrown error.
		 * @return \WP_Error
		 */
		private function ai_translate_map_generate_text_exception( \Throwable $e ): WP_Error {
			$msg = (string) $e->getMessage();
			if ( false !== stripos( $msg, 'No models found' ) ) {
				return new WP_Error(
					'lmat_ai_no_models',
					__( 'No compatible text-generation model is available for the selected provider. Please ensure the provider is installed, an API key is saved, and a text model is selected.', 'linguator-pro' ),
					array( 'status' => 400 )
				);
			}
			if (
				false !== stripos( $msg, '429' ) ||
				false !== stripos( $msg, 'quota exceeded' ) ||
				false !== stripos( $msg, 'rate limit' ) ||
				false !== stripos( $msg, 'too many requests' )
			) {
				return new WP_Error(
					'lmat_ai_rate_limited',
					__( 'Gemini API quota/rate limit exceeded. Please check billing/quotas, then retry with smaller batches.', 'linguator-pro' ),
					array( 'status' => 429 )
				);
			}
			return new WP_Error(
				'lmat_ai_request_failed',
				__( 'AI translation request failed. Please try again shortly.', 'linguator-pro' ),
				array( 'status' => 502 )
			);
		}

		/**
		 * Determines whether an AI client error indicates a request timeout.
		 *
		 * @param \WP_Error $error Error returned by the AI client.
		 * @return bool
		 */
		private function ai_translate_is_timeout_error( WP_Error $error ): bool {
			$codes = $error->get_error_codes();
			foreach ( $codes as $code ) {
				$code_str = strtolower( (string) $code );
				if ( false !== stripos( $code_str, 'timeout' ) || false !== stripos( $code_str, 'network_error' ) ) {
					return true;
				}

				$data = $error->get_error_data( $code );
				if ( is_array( $data ) && isset( $data['status'] ) && 503 === absint( $data['status'] ) ) {
					$exception_class = isset( $data['exception_class'] ) ? strtolower( (string) $data['exception_class'] ) : '';
					if ( false !== stripos( $exception_class, 'networkexception' ) ) {
						return true;
					}
				}
			}

			$message = strtolower( $error->get_error_message() );
			return false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'cURL error 28' );
		}

		/**
		 * Split an associative string map into two balanced chunks.
		 *
		 * @param array<string,string> $strings Source strings.
		 * @return array<int,array<string,string>>
		 */
		private function ai_translate_split_string_map( array $strings ): array {
			$keys  = array_keys( $strings );
			$count = count( $keys );
			if ( $count < 2 ) {
				return array( $strings );
			}

			$left_count = (int) ceil( $count / 2 );
			$left       = array();
			$right      = array();

			foreach ( $keys as $index => $key ) {
				if ( $index < $left_count ) {
					$left[ $key ] = $strings[ $key ];
					continue;
				}
				$right[ $key ] = $strings[ $key ];
			}

			if ( empty( $left ) || empty( $right ) ) {
				return array( $strings );
			}

			return array( $left, $right );
		}

		public function linguator_permission_only_admins( $request ) {

			if ( ! is_user_logged_in() ) {
				return new \WP_Error( 'rest_forbidden', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 401 ) );
			}

			$nonce = $request->get_header( 'X-WP-Nonce' );

			$nonce = sanitize_text_field( wp_unslash( (string) $nonce ) );

			if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new WP_Error( 'rest_forbidden', __( 'Invalid nonce.', 'linguator-pro' ), array( 'status' => 403 ) );
			}

			$taxonomy = $request->get_param( 'taxonomy' );
			if ( ! empty( $taxonomy ) ) {
				$taxonomy = sanitize_key( $taxonomy );
				$tax_obj  = get_taxonomy( $taxonomy );
				if ( ! $tax_obj || empty( $tax_obj->cap ) || empty( $tax_obj->cap->manage_terms ) ) {
					return new \WP_Error( 'rest_invalid_param', __( 'Invalid taxonomy.', 'linguator-pro' ), array( 'status' => 400 ) );
				}
				if ( ! current_user_can( $tax_obj->cap->manage_terms ) ) {
					return new \WP_Error( 'rest_forbidden', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
				}

				// Object-level checks: if a term (or list of terms) is provided, require edit capability for it.
				$term_id_param = $request->get_param( 'term_id' );
				if ( null !== $term_id_param && '' !== $term_id_param ) {
					// Creating a translated term (create-translate-taxonomy) will create a new term; require create capability too.
					if ( ! empty( $tax_obj->cap->create_terms ) && ! current_user_can( $tax_obj->cap->create_terms ) ) {
						return new \WP_Error( 'rest_forbidden', __( 'You are not authorized to create terms for this taxonomy.', 'linguator-pro' ), array( 'status' => 403 ) );
					}

					$term_id = absint( $term_id_param );
					if ( $term_id <= 0 ) {
						return new \WP_Error( 'rest_invalid_param', __( 'Invalid term id.', 'linguator-pro' ), array( 'status' => 400 ) );
					}
					if ( ! current_user_can( 'edit_term', $term_id ) ) {
						return new \WP_Error( 'rest_forbidden', __( 'You are not authorized to edit this term.', 'linguator-pro' ), array( 'status' => 403 ) );
					}
				}

				$ids_param = $request->get_param( 'ids' );
				if ( null !== $ids_param && '' !== $ids_param ) {
					// Bulk taxonomy translation can create new terms; require create capability too.
					if ( ! empty( $tax_obj->cap->create_terms ) && ! current_user_can( $tax_obj->cap->create_terms ) ) {
						return new \WP_Error( 'rest_forbidden', __( 'You are not authorized to create terms for this taxonomy.', 'linguator-pro' ), array( 'status' => 403 ) );
					}

					$decoded_ids = json_decode( (string) $ids_param, true );
					if ( is_array( $decoded_ids ) ) {
						foreach ( $decoded_ids as $maybe_id ) {
							$term_id = absint( $maybe_id );
							if ( $term_id > 0 && ! current_user_can( 'edit_term', $term_id ) ) {
								return new \WP_Error( 'rest_forbidden', __( 'You are not authorized to edit one or more requested terms.', 'linguator-pro' ), array( 'status' => 403 ) );
							}
						}
					}
				}
				return true;
			}

			// Creating a translated post: must edit source and be allowed to create new content of that post type.
			$post_id_param = $request->get_param( 'post_id' );
			if ( null !== $post_id_param && '' !== $post_id_param ) {
				$post_id = absint( $post_id_param );
				if ( $post_id <= 0 ) {
					return new \WP_Error( 'rest_invalid_param', __( 'Invalid post ID.', 'linguator-pro' ), array( 'status' => 400 ) );
				}
				$post = get_post( $post_id );
				if ( ! $post ) {
					return new \WP_Error( 'rest_forbidden', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return new \WP_Error( 'rest_forbidden', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
				}
				$post_type_object = get_post_type_object( $post->post_type );
				if ( ! $post_type_object || empty( $post_type_object->cap->create_posts ) ) {
					return new \WP_Error( 'rest_forbidden', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
				}
				if ( ! current_user_can( $post_type_object->cap->create_posts ) ) {
					return new \WP_Error( 'rest_forbidden', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
				}
				return true;
			}

			// Bulk post translation (`bulk-translate-entries`): body `ids` are post IDs — require edit + create (and publish when source is public).
			$ids_param = $request->get_param( 'ids' );
			if ( null !== $ids_param && '' !== $ids_param ) {
				$decoded_ids = json_decode( (string) $ids_param, true );
				if ( ! is_array( $decoded_ids ) || empty( $decoded_ids ) ) {
					return new \WP_Error( 'rest_invalid_param', __( 'Invalid post ids.', 'linguator-pro' ), array( 'status' => 400 ) );
				}

				if ( ! current_user_can( Capabilities::TRANSLATIONS ) ) {
					return new \WP_Error( 'rest_forbidden', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
				}

				foreach ( $decoded_ids as $maybe_id ) {
					$post_id = absint( $maybe_id );
					if ( $post_id <= 0 ) {
						continue;
					}

					$post = get_post( $post_id );
					if ( ! $post ) {
						continue;
					}

					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return new \WP_Error( 'rest_forbidden', __( 'You are not allowed to translate one or more of the selected posts.', 'linguator-pro' ), array( 'status' => 403 ) );
					}

					$post_type_object = get_post_type_object( $post->post_type );
					if ( ! $post_type_object || empty( $post_type_object->cap ) ) {
						return new \WP_Error( 'rest_forbidden', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
					}

					$create_cap = ! empty( $post_type_object->cap->create_posts )
						? $post_type_object->cap->create_posts
						: ( ! empty( $post_type_object->cap->edit_posts ) ? $post_type_object->cap->edit_posts : '' );

					if ( '' === $create_cap || ! current_user_can( $create_cap ) ) {
						return new \WP_Error( 'rest_forbidden', __( 'You are not allowed to create translations for one or more of the selected post types.', 'linguator-pro' ), array( 'status' => 403 ) );
					}

					// Translations may inherit publish status from source.
					if ( 'publish' === $post->post_status || 'private' === $post->post_status ) {
						$publish_cap = ! empty( $post_type_object->cap->publish_posts ) ? $post_type_object->cap->publish_posts : '';
						if ( '' !== $publish_cap && ! current_user_can( $publish_cap ) ) {
							return new \WP_Error( 'rest_forbidden', __( 'You are not allowed to publish translations for one or more of the selected posts.', 'linguator-pro' ), array( 'status' => 403 ) );
						}
					}
				}

				return true;
			}

			if ( ! current_user_can( Capabilities::TRANSLATIONS ) ) {
				return new \WP_Error( 'rest_forbidden', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
			}
			return true;
		}

		public function validate_lmat_bulk_nonce( $value, $request, $param ) {
			$nonce = sanitize_text_field( wp_unslash( (string) $value ) );
			return wp_verify_nonce( $nonce, 'lmat_bulk_translate_entries_nonce' ) ? true : new \WP_Error( 'rest_invalid_param', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
		}

		public function validate_lmat_create_post_nonce( $value, $request, $param ) {
			$nonce = sanitize_text_field( wp_unslash( (string) $value ) );
			return wp_verify_nonce( $nonce, 'lmat_create_translate_post_nonce' ) ? true : new \WP_Error( 'rest_invalid_param', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
		}

		public function validate_lmat_create_term_nonce( $value, $request, $param ) {
			$nonce = sanitize_text_field( wp_unslash( (string) $value ) );
			return wp_verify_nonce( $nonce, 'lmat_create_translate_taxonomy_nonce' ) ? true : new \WP_Error( 'rest_invalid_param', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
		}

		/**
		 * Validate positive integer request values.
		 *
		 * @param mixed $value Request value.
		 * @return bool
		 */
		public function validate_positive_int_param( $value ) {
			return is_numeric( $value ) && absint( $value ) > 0;
		}

		/**
		 * Validate required slug-like values.
		 *
		 * @param mixed $value Request value.
		 * @return bool
		 */
		public function validate_required_slug_param( $value ) {
			return is_scalar( $value ) && '' !== sanitize_key( (string) $value );
		}

		/**
		 * Validate taxonomy parameter.
		 *
		 * @param mixed $value Request value.
		 * @return bool
		 */
		public function validate_taxonomy_param( $value ) {
			$taxonomy = sanitize_key( (string) $value );
			return '' !== $taxonomy && taxonomy_exists( $taxonomy );
		}

		/**
		 * Validates editor type payload.
		 *
		 * @param mixed $value Request value.
		 * @return bool
		 */
		public function validate_editor_type_param( $value ) {
			// Allow empty/missing editor type (handler handles defaults).
			if ( null === $value ) {
				return true;
			}

			$editor_type = sanitize_text_field( (string) $value );
			if ( '' === $editor_type ) {
				return true;
			}

			return in_array( $editor_type, array( 'elementor', 'block', 'classic' ), true );
		}

		/**
		 * Validates an optional string-like parameter.
		 *
		 * @param mixed $value Request value.
		 * @return bool
		 */
		public function validate_optional_string_param( $value ) {
			// Optional args can be empty. Ensure we only accept scalar inputs.
			if ( null === $value ) {
				return true;
			}

			return is_scalar( $value );
		}

		/**
		 * Validates optional JSON payload for post meta fields.
		 *
		 * @param mixed $value Request value.
		 * @return bool
		 */
		public function validate_post_meta_fields_param( $value ) {
			if ( null === $value || '' === $value ) {
				return true;
			}

			if ( ! is_string( $value ) ) {
				return false;
			}

			$decoded = json_decode( $value, true );
			return JSON_ERROR_NONE === json_last_error() && is_array( $decoded );
		}

		/**
		 * Sanitizes optional JSON payload for post meta fields.
		 *
		 * @param mixed $value Raw request value.
		 * @return string
		 */
		public function sanitize_post_meta_fields_param( $value ) {
			if ( null === $value || '' === $value || ! is_string( $value ) ) {
				return '';
			}

			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				return '';
			}

			return wp_json_encode( $decoded );
		}

		/**
		 * Validates an optional JSON media-translation payload.
		 *
		 * @param mixed $value Request value.
		 * @return bool
		 */
		public function validate_media_translations_param( $value ) {
			if ( null === $value || '' === $value ) {
				return true;
			}

			if ( ! is_string( $value ) ) {
				return false;
			}

			$decoded = json_decode( $value, true );
			return JSON_ERROR_NONE === json_last_error() && is_array( $decoded );
		}

		/**
		 * Sanitizes a JSON media-translation payload using its field semantics.
		 *
		 * @param mixed $value Request value.
		 * @return string Sanitized JSON.
		 */
		public function sanitize_media_translations_param( $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return '';
			}

			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				return '';
			}

			return wp_json_encode( $this->sanitize_media_translations_recursive( $decoded ) );
		}

		/**
		 * Recursively sanitizes media translation fields while preserving IDs.
		 *
		 * @param array $value Decoded media payload.
		 * @return array Sanitized payload.
		 */
		private function sanitize_media_translations_recursive( array $value ) {
			$sanitized = array();

			foreach ( $value as $key => $item ) {
				if ( is_array( $item ) ) {
					$sanitized[ $key ] = $this->sanitize_media_translations_recursive( $item );
					continue;
				}

				switch ( $key ) {
					case 'id':
						$sanitized[ $key ] = absint( $item );
						break;
					case 'title':
					case 'alt':
						$sanitized[ $key ] = sanitize_text_field( (string) $item );
						break;
					case 'caption':
					case 'description':
						$sanitized[ $key ] = wp_kses_post( (string) $item );
						break;
				}
			}

			return $sanitized;
		}

		/**
		 * Sanitize taxonomy term description for REST (allowed post HTML).
		 *
		 * @param mixed $value Raw request value.
		 * @return string
		 */
		public function sanitize_taxonomy_description_param( $value ) {
			if ( null === $value || false === $value ) {
				return '';
			}
			return wp_kses_post( is_string( $value ) ? $value : (string) $value );
		}
		
		/**
		 * Validates a required non-empty text parameter.
		 *
		 * @param mixed $value Request value.
		 * @return bool
		 */
		public function validate_required_text_param( $value ) {
			if ( ! is_scalar( $value ) ) {
				return false;
			}

			return '' !== sanitize_text_field( (string) $value );
		}

		/**
		 * Validate JSON-encoded IDs payload.
		 *
		 * @param mixed $value Request value.
		 * @return bool
		 */
		public function validate_lmat_json_ids( $value ) {
			$decoded = json_decode( (string) $value, true );
			if ( ! is_array( $decoded ) || empty( $decoded ) ) {
				return false;
			}

			foreach ( $decoded as $id ) {
				if ( ! is_numeric( $id ) || absint( $id ) <= 0 ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Validate JSON-encoded language slugs payload.
		 *
		 * @param mixed $value Request value.
		 * @return bool
		 */
		public function validate_lmat_json_langs( $value ) {
			$decoded = json_decode( (string) $value, true );
			if ( ! is_array( $decoded ) || empty( $decoded ) ) {
				return false;
			}

			foreach ( $decoded as $lang ) {
				if ( '' === sanitize_key( (string) $lang ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Sanitizes JSON-encoded post IDs payload.
		 *
		 * @param mixed $value Raw request value.
		 * @return string
		 */
		public function sanitize_lmat_json_ids( $value ) {
			$decoded = json_decode( (string) $value, true );
			if ( ! is_array( $decoded ) ) {
				return wp_json_encode( array() );
			}

			$ids = array_values( array_filter( array_map( 'absint', $decoded ) ) );
			return wp_json_encode( $ids );
		}

		/**
		 * Sanitizes JSON-encoded language slugs payload.
		 *
		 * @param mixed $value Raw request value.
		 * @return string
		 */
		public function sanitize_lmat_json_langs( $value ) {
			$decoded = json_decode( (string) $value, true );
			if ( ! is_array( $decoded ) ) {
				return wp_json_encode( array() );
			}

			$langs = array();
			foreach ( $decoded as $lang ) {
				$sanitized_lang = sanitize_key( (string) $lang );
				if ( '' !== $sanitized_lang ) {
					$langs[] = $sanitized_lang;
				}
			}

			return wp_json_encode( array_values( $langs ) );
		}

		/**
		 * Sanitizes post_content for create-translate-post depending on the page builder/editor.
		 *
		 * - classic/wpbakery: decode the JSON-encoded string sent by bulk-translate.js, then allow safe HTML via wp_kses_post().
		 * - block/elementor: expect JSON payload; validate JSON but don't run HTML sanitization on it.
		 *
		 * @param mixed           $value   Raw incoming value.
		 * @param WP_REST_Request $request Request object.
		 * @param string          $param   Parameter name.
		 * @return string Sanitized content (empty string if invalid).
		 */
		public function sanitize_post_content_for_builders( $value, $request, $param ) {
			$value = is_string( $value ) ? $value : '';

			$editor_type = '';
			if ( $request instanceof WP_REST_Request ) {
				$editor_type = sanitize_key( (string) $request->get_param( 'editor_type' ) );
			}

			// JSON builders: validate JSON and return it as-is (so downstream json_decode() works).
			if ( in_array( $editor_type, array( 'block', 'elementor' ), true ) ) {
				if ( '' === $value ) {
					return '';
				}
				json_decode( $value, true );
				return ( JSON_ERROR_NONE === json_last_error() ) ? $value : '';
			}

			// Decode before kses: kses rewrites tag attributes but keeps \" in text, which broke shortcode attributes (e.g. [caption id]).
			$decoded = json_decode( $value, true );
			if ( is_string( $decoded ) ) {
				$value = $decoded;
			}

			// Default: sanitize as post content HTML.
			return wp_kses_post( $value );
		}

		public function bulk_translate_entries( $params ) {
			// Check if the user is logged in and has the necessary capabilities
			if ( ! is_user_logged_in() ) {
				wp_send_json_error( 'You are not authorized to perform this action.' );
			}
			if ( ! current_user_can( Capabilities::TRANSLATIONS ) ) {
				wp_send_json_error( 'You are not authorized to perform this action.' );
			}

			// Verify the nonce
			$private_key = isset( $params['privateKey'] ) ? sanitize_text_field( wp_unslash( (string) $params['privateKey'] ) ) : '';
			if ( '' === $private_key || ! wp_verify_nonce( $private_key, 'lmat_bulk_translate_entries_nonce' ) ) {
				wp_send_json_error( 'You are not authorized to perform this action.' );
			}

			global $linguator;

			// check language exists or not
			$translate_lang = json_decode( $params['lang'], true );
			$translate_lang = is_array( $translate_lang )
				? array_values(
					array_filter(
						array_map(
							static function ( $lang ) {
								return sanitize_key( (string) $lang );
							},
							$translate_lang
						)
					)
				)
				: array();

			$post_ids = json_decode( $params['ids'], true );
			$post_ids = is_array( $post_ids )
				? array_values( array_filter( array_map( 'absint', $post_ids ) ) )
				: array();
			$posts_translate = array();
			$gutenberg_block = false;

			$slug_translation_option = 'title_translate';
			if(property_exists(LMATP(), 'options') && isset(LMATP()->options['ai_translation_configuration']['slug_translation_option'])){
				$slug_translation_option = LMATP()->options['ai_translation_configuration']['slug_translation_option'];
			}

			$post_meta_sync = true;
			if ( ! isset( LMATP()->options['sync'] ) || ( isset( LMATP()->options['sync'] ) && ! in_array( 'post_meta', LMATP()->options['sync'] ) ) ) {
				$post_meta_sync = false;
			}

			if ( count( $translate_lang ) > 0 && ! ( count( $post_ids ) < 1 ) ) {
				$lmat_langs           = $linguator->model->get_languages_list();
				$lmat_langs_slugs     = array_column( $lmat_langs, 'slug' );
				$allowed_meta_fields = Custom_Fields::get_allowed_custom_fields();
				
				foreach ( $post_ids as $postId ) {

					if ( ! current_user_can( 'edit_post', $postId ) ) {
						continue;
					}

					$posts_translate[ $postId ]['sourceLanguage'] = $linguator->model->post->get_language( $postId )->slug;
					$post_data                                    = get_post( $postId );

					if ( ! $posts_translate[ $postId ]['sourceLanguage'] ) {
						$posts_translate[ $postId ]['sourceLanguage'] = false;
						$posts_translate[ $postId ]['title']          = $post_data->post_title;
						$posts_translate[ $postId ]['editor_type']    = has_blocks( $post_data->post_content ) ? 'block' : 'classic';
						$posts_translate[ $postId ]['post_link']      = html_entity_decode( get_edit_post_link( $postId ) );
						continue;
					}

			$elementor_enabled = get_post_meta( $postId, '_elementor_edit_mode', true );
			$wpbakery_enabled = get_post_meta( $postId, '_wpb_vc_js_status', true );
			if ( ! $post_data ) {
				continue;
			}

			if ( $slug_translation_option === 'slug_translate' ) {
				$posts_translate[ $postId ]['post_name'] = urldecode( get_post_field( 'post_name', $postId ) );
			}

			$posts_translate[ $postId ]['title']       = $post_data->post_title;
			
			// Check for WPBakery first - if enabled, treat as classic even if has_blocks() returns true
			// This prevents vc_gutenberg element from incorrectly setting editor type to 'block'
			$is_wpbakery_page = ( 'true' === $wpbakery_enabled || true === $wpbakery_enabled );
			
			if ( $is_wpbakery_page ) {
				// For WPBakery pages, always use raw content and set editor to 'classic'
				$posts_translate[ $postId ]['content']     = $post_data->post_content;
				$posts_translate[ $postId ]['editor_type'] = 'classic';
			} else {
				// For non-WPBakery pages, check for Gutenberg blocks
				$posts_translate[ $postId ]['content']     = has_blocks( $post_data->post_content ) ? parse_blocks( $post_data->post_content ) : $post_data->post_content;
				$posts_translate[ $postId ]['editor_type'] = has_blocks( $post_data->post_content ) ? 'block' : 'classic';
			}

					if ( isset( $post_data->post_excerpt ) && ! empty( $post_data->post_excerpt ) ) {
						$posts_translate[ $postId ]['excerpt'] = $post_data->post_excerpt;
					}

					$posts_translate[ $postId ]['sourceLanguage'] = ! isset( $posts_translate[ $postId ]['sourceLanguage'] ) ? linguator_pro_default_language() : $posts_translate[ $postId ]['sourceLanguage'];

					if ( ! $post_meta_sync ) {
						$post_meta_fields    = get_post_meta( $postId );
						$existed_meta_fields = array_intersect( array_keys( $post_meta_fields ), array_keys( $allowed_meta_fields ) );

						foreach ( $existed_meta_fields as $key ) {
							if ( isset( $post_meta_fields[ $key ] ) && ! empty( $post_meta_fields[ $key ] ) && isset( $allowed_meta_fields[ $key ]['status'] ) && true === $allowed_meta_fields[ $key ]['status'] ) {
								$value = $allowed_meta_fields[ $key ]['type'] && is_array( $post_meta_fields[ $key ] ) ? linguator_pro_maybe_unserialize( $post_meta_fields[ $key ][0] ) : linguator_pro_maybe_unserialize( $post_meta_fields[ $key ] );
								$posts_translate[ $postId ]['metaFields'][ $key ] = $value;
							}
						}
					}

					$posts_translate[ $postId ]['post_link'] = get_the_permalink( $postId );

				if ( $elementor_enabled && 'builder' === $elementor_enabled && defined( 'ELEMENTOR_VERSION' ) ) {
					$elementor_data = get_post_meta( $postId, '_elementor_data', true );

					if ( $elementor_data && '' !== $elementor_data ) {
						$posts_translate[ $postId ]['editor_type'] = 'elementor';
						$elementor_data                            = array();

						if ( class_exists( '\Elementor\Plugin' ) && property_exists( '\Elementor\Plugin', 'instance' ) ) {
							$elementor_data = \Elementor\Plugin::$instance->documents->get( $postId )->get_elements_data();
						}

						$posts_translate[ $postId ]['content'] = $elementor_data;
						unset( $posts_translate[ $postId ]['metaFields']['_elementor_data'] );
					}
				}

			// Handle WPBakery content - apply transformations for translation
			if ( $is_wpbakery_page ) {
				// Apply WPBakery content filters to prepare for translation
				// This decodes base64-encoded attributes and exposes translatable content
				$wpbakery_content = $posts_translate[ $postId ]['content'];
				
				// Apply the lmat_post_content_for_translation filter that WPBakery hooks into
				$wpbakery_content = apply_filters( 'lmat_post_content_for_translation', $wpbakery_content, $postId );
				
				$posts_translate[ $postId ]['content'] = $wpbakery_content;
			}

				if ( $posts_translate[ $postId ]['editor_type'] === 'block' && ! $gutenberg_block ) {
					$gutenberg_block = true;
				}

					foreach ( $translate_lang as $lang ) {
						if ( in_array( $lang, $lmat_langs_slugs ) ) {
							$post_translate_status = $linguator->model->post->get_translation( $postId, $lang );
							if ( ! $post_translate_status ) {
								$posts_translate[ $postId ]['languages'][] = $lang;
							} else {
								$posts_translate[ $postId ]['postExists'][ $lang ] = array(
									'post_title' => get_the_title( $post_translate_status ),
									'post_url'   => get_the_permalink( $post_translate_status ),
								);
							}
						}
					}
				}
			}

			$data = array(
				'posts'                    => $posts_translate,
				'CreateTranslatePostNonce' => wp_create_nonce( 'lmat_create_translate_post_nonce' ),
			);
			if ( ! $post_meta_sync ) {
				$data['allowedMetaFields'] = wp_json_encode( $allowed_meta_fields );
			}

			// Append media strings per post when media translation is enabled.
			if ( function_exists( 'LMATP' ) ) {
				$media_service = new Media_Translation_Service( LMATP() );
				if ( $media_service->is_enabled() ) {
					foreach ( array_keys( $posts_translate ) as $pid ) {
						$pid = absint( $pid );
						if ( 0 === $pid ) {
							continue;
						}

						$featured = $media_service->get_featured_image_strings( $pid );
						if ( ! empty( $featured ) ) {
							$data['posts'][ $pid ]['featured_image'] = $featured;
						}

						$content_media = $media_service->get_content_media_strings( $pid );
						if ( ! empty( $content_media ) ) {
							$data['posts'][ $pid ]['content_media'] = $content_media;
						}

						$elementor_media = $media_service->get_elementor_media_strings( $pid );
						if ( ! empty( $elementor_media ) ) {
							$data['posts'][ $pid ]['elementor_media'] = $elementor_media;
						}
					}
				}
			}

			if ( $gutenberg_block ) {
				$block_parse_rules       = Supported_Blocks::get_instance()->block_parsing_rules();
				$data['blockParseRules'] = json_encode( $block_parse_rules );
			}

			if ( count( $posts_translate ) > 0 ) {
				wp_send_json_success( $data );
			} else {
				wp_send_json_error( 'No posts to translate' );
			}
		}

		/**
		 * Choose the slug when a provider omits it or repeats the source slug.
		 *
		 * @param string|false $slug        Provider's translated slug.
		 * @param string       $title       Translated title.
		 * @param string       $source_slug Source post slug.
		 * @return string
		 */
		private function select_translated_post_slug( $slug, $title, $source_slug ) {
			$translated_slug = $slug ? sanitize_title( $slug ) : '';
			$source_slug     = sanitize_title( urldecode( $source_slug ) );
			$title_slug      = sanitize_title( $title );

			if ( ( '' === $translated_slug || $translated_slug === $source_slug ) && '' !== $title_slug ) {
				return $title_slug;
			}

			return '' !== $translated_slug ? $translated_slug : $source_slug;
		}

		/**
		 * Create a translated copy of a post (capabilities verified in permission_callback).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return \WP_REST_Response|WP_Error
		 */
		public function linguator_create_translate_post( WP_REST_Request $request ) {
			$params = $request->get_params();

			if ( empty( $params['source_language'] ) ) {
				return new WP_Error( 'invalid_source_language', __( 'Invalid source language', 'linguator-pro' ), array( 'status' => 400 ) );
			}

			if ( ! isset( $params['post_id'] ) || ! isset( $params['target_language'] ) || ( ! isset( $params['post_title'] ) && ! isset( $params['post_content'] ) ) ) {
				return new WP_Error( 'invalid_request', __( 'Invalid request', 'linguator-pro' ), array( 'status' => 400 ) );
			}

			if ( empty( $params['target_language'] ) ) {
				return new WP_Error( 'invalid_target_language', __( 'Invalid target language', 'linguator-pro' ), array( 'status' => 400 ) );
			}

			$private_key = isset( $params['privateKey'] ) ? sanitize_text_field( wp_unslash( (string) $params['privateKey'] ) ) : '';
			if ( '' === $private_key || ! wp_verify_nonce( $private_key, 'lmat_create_translate_post_nonce' ) ) {
				return new WP_Error( 'rest_forbidden', __( 'You are not authorized to perform this action.', 'linguator-pro' ), array( 'status' => 403 ) );
			}

			if ( empty( $params['post_title'] ) && empty( $params['post_content'] ) ) {
				return new WP_Error( 'empty_content', __( 'Invalid request content & title empty', 'linguator-pro' ), array( 'status' => 400 ) );
			}

			$source_post_id   = absint( $params['post_id'] );
			$target_language  = sanitize_text_field( $params['target_language'] );
			$editor_type      = isset( $params['editor_type'] ) ? sanitize_text_field( $params['editor_type'] ) : '';
			$source_language  = sanitize_text_field( $params['source_language'] );
			$title            = isset( $params['post_title'] ) ? sanitize_text_field( $params['post_title'] ) : '';
			$slug             = isset( $params['post_name'] ) && ! empty( $params['post_name'] ) ? sanitize_text_field( $params['post_name'] ) : false;
			$excerpt          = isset( $params['post_excerpt'] ) ? sanitize_text_field( $params['post_excerpt'] ) : '';
			$content          = isset( $params['post_content'] ) ? $params['post_content'] : '';
			$slug_translation_option = 'title_translate';

			if ( property_exists( LMATP(), 'options' ) && isset( LMATP()->options['ai_translation_configuration']['slug_translation_option'] ) ) {
				$slug_translation_option = LMATP()->options['ai_translation_configuration']['slug_translation_option'];
			}

			$meta_fields = isset( $params['post_meta_fields'] ) ? $params['post_meta_fields'] : '';

			$post_data = array(
				'post_title'   => sanitize_text_field( $title ),
				'post_content' => $content,
			);

			if ( $excerpt && ! empty( $excerpt ) ) {
				$post_data['post_excerpt'] = sanitize_text_field( $excerpt );
			}

			if ( $meta_fields && ! empty( $meta_fields ) ) {
				$decoded_meta_fields = json_decode( $meta_fields, true );
				if ( null === $decoded_meta_fields && json_last_error() !== JSON_ERROR_NONE ) {
					return new WP_Error(
						'invalid_post_meta_fields',
						__( 'Invalid post_meta_fields JSON payload.', 'linguator-pro' ),
						array( 'status' => 400 )
					);
				}
				$post_data['post_meta_fields'] = is_array( $decoded_meta_fields )
					? $this->ai_normalize_translation_strings_recursive( $decoded_meta_fields )
					: $decoded_meta_fields;
			}

			if ( 'elementor' === $editor_type ) {
				$post_data['meta_fields'] = array();
				$el_raw = is_string( $content ) ? $content : wp_json_encode( $content );
				$el_dec = json_decode( $el_raw, true );
				if ( JSON_ERROR_NONE === json_last_error() && is_array( $el_dec ) ) {
					$post_data['meta_fields']['_elementor_data'] = wp_json_encode(
						$this->ai_normalize_translation_strings_recursive( $el_dec ),
						JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
					);
				} else {
					$post_data['meta_fields']['_elementor_data'] = $this->ai_normalize_translation_string( (string) $el_raw );
				}
				unset( $post_data['post_content'] );
			} elseif ( 'block' === $editor_type ) {
				$decoded_blocks = json_decode( $post_data['post_content'], true );
				if ( null === $decoded_blocks && json_last_error() !== JSON_ERROR_NONE ) {
					return new WP_Error(
						'invalid_block_content',
						__( 'Invalid block post_content JSON payload.', 'linguator-pro' ),
						array( 'status' => 400 )
					);
				}
				if ( ! is_array( $decoded_blocks ) ) {
					return new WP_Error(
						'invalid_block_content_type',
						__( 'Block post_content must decode to an array.', 'linguator-pro' ),
						array( 'status' => 400 )
					);
				}
				$decoded_blocks            = $this->ai_normalize_translation_strings_recursive( $decoded_blocks );
				$post_data['post_content'] = serialize_blocks( $decoded_blocks );
			} else {
				// Classic and other HTML content is already decoded and sanitized by sanitize_post_content_for_builders().
				if ( isset( $post_data['post_content'] ) && is_string( $post_data['post_content'] ) ) {
					$post_data['post_content'] = $this->ai_normalize_translation_string( $post_data['post_content'] );
				}
			}

			$post_data['post_title'] = sanitize_text_field( $this->ai_normalize_translation_string( (string) ( $post_data['post_title'] ?? '' ) ) );
			if ( isset( $post_data['post_excerpt'] ) ) {
				$post_data['post_excerpt'] = sanitize_text_field( $this->ai_normalize_translation_string( (string) $post_data['post_excerpt'] ) );
			}

			if ( $slug_translation_option === 'slug_translate' ) {
				$post_data['post_name'] = $this->select_translated_post_slug(
					$slug,
					$post_data['post_title'],
					(string) get_post_field( 'post_name', $source_post_id )
				);
			} elseif ( $slug_translation_option === 'slug_keep' ) {
				$post_data['post_name'] = sanitize_text_field( get_post_field( 'post_name', $source_post_id ) );
			} else {
				$post_data['post_name'] = sanitize_title( $post_data['post_title'] );
			}

			foreach ( array( 'featured_image', 'content_media', 'elementor_media' ) as $media_key ) {
				if ( empty( $params[ $media_key ] ) || ! is_string( $params[ $media_key ] ) ) {
					continue;
				}

				$media_payload = json_decode( $params[ $media_key ], true );
				if ( JSON_ERROR_NONE === json_last_error() && is_array( $media_payload ) ) {
					$post_data[ $media_key ] = $media_payload;
				}
			}

			$media_service = new Media_Translation_Service( LMATP() );
			if ( $media_service->is_enabled() ) {
				$media_validation = $media_service->validate_media_payload( $source_post_id, $target_language, $post_data );
				if ( is_wp_error( $media_validation ) ) {
					return $media_validation;
				}
			}

			global $linguator;
			$post_clone   = new \Linguator_Pro_Sync_Post_Model( $linguator );
			try {
				// AI-supplied content must keep inline media text while IDs/URLs remap.
				if ( isset( $post_data['post_content'] ) ) {
					$post_data['preserve_media_text'] = true;
				}
				$new_post_id = $post_clone->copy_post( $source_post_id, $source_language, $target_language, false, $post_data, $editor_type );
			} catch ( \Throwable $e ) {
				return new WP_Error(
					'create_failed_exception',
					__( 'Failed to create the translated post.', 'linguator-pro' ),
					array( 'status' => 500 )
				);
			}

			if ( ! $new_post_id ) {
				return new WP_Error(
					'create_failed',
					sprintf(
						/* translators: 1: source post ID, 2: language slug */
						__( 'Unable to create the translated post for parent post ID %1$s in %2$s.', 'linguator-pro' ),
						(string) $source_post_id,
						$target_language
					),
					array( 'status' => 500 )
				);
			}


			$post_link      = html_entity_decode( get_the_permalink( $new_post_id ) );
			$post_title_out = html_entity_decode( get_the_title( $new_post_id ) );
			$post_edit_link = html_entity_decode( get_edit_post_link( $new_post_id ) );

			// Build Elementor editor link if the translated post uses Elementor.
			$elementor_edit_link = false;
			if ( defined( 'ELEMENTOR_VERSION' ) && 'builder' === get_post_meta( $new_post_id, '_elementor_edit_mode', true ) ) {
				$elementor_edit_link = add_query_arg(
					array(
						'post'   => $new_post_id,
						'action' => 'elementor',
					),
					admin_url( 'post.php' )
				);
			}

			return rest_ensure_response(
				array(
					'post_id'                     => $new_post_id,
					'target_language'             => $target_language,
					'post_link'                   => $post_link,
					'post_title'                  => $post_title_out,
					'post_edit_link'              => $post_edit_link,
					'elementor_edit_link'         => $elementor_edit_link,
					'update_translate_data_nonce' => wp_create_nonce( 'lmat_update_translate_data_nonce' ),
				)
			);
		}

		public function bulk_translate_taxonomy_entries( $params ) {
			if ( ! isset( $params['taxonomy'] ) || empty( $params['taxonomy'] ) ) {
				wp_send_json_error( 'Invalid taxonomy' );
			}
			if ( ! isset( $params['lang'] ) || empty( $params['lang'] ) ) {
				wp_send_json_error( 'Invalid language' );
			}
			if ( ! isset( $params['privateKey'] ) || empty( $params['privateKey'] ) ) {
				wp_send_json_error( 'Invalid private key' );
			}
			if ( ! isset( $params['ids'] ) || empty( $params['ids'] ) ) {
				wp_send_json_error( 'Invalid ids' );
			}

			// Check if the user is logged in and has the necessary capabilities
			if ( ! is_user_logged_in() ) {
				wp_send_json_error( 'You are not authorized to perform this action.' );
			}
			if ( ! current_user_can( Capabilities::TRANSLATIONS ) ) {
				wp_send_json_error( 'You are not authorized to perform this action.' );
			}

			$params                  = $params->get_params();

			// Verify the nonce
			$private_key = isset( $params['privateKey'] ) ? sanitize_text_field( wp_unslash( (string) $params['privateKey'] ) ) : '';
			if ( '' === $private_key || ! wp_verify_nonce( $private_key, 'lmat_bulk_translate_entries_nonce' ) ) {
				wp_send_json_error( 'You are not authorized to perform this action.' );
			}

			$translate_lang = json_decode( $params['lang'] );

			$taxonomy_translate = array();

			$slug_translation_option = 'title_translate';
			if(property_exists(LMATP(), 'options') && isset(LMATP()->options['ai_translation_configuration']['slug_translation_option'])){
				$slug_translation_option = LMATP()->options['ai_translation_configuration']['slug_translation_option'];
			}

			if ( $translate_lang && count( $translate_lang ) > 0 ) {
				global $linguator;
				$lmat_langs       = $linguator->model->get_languages_list();
				$lmat_langs_slugs = array_column( $lmat_langs, 'slug' );

				$taxonomy     = sanitize_text_field( $params['taxonomy'] );
				$taxonomy_ids = json_decode( $params['ids'] );

				foreach ( $taxonomy_ids as $taxonomy_id ) {
					$taxonomy_translate[ $taxonomy_id ]['sourceLanguage'] = linguator_pro_get_term_language( $taxonomy_id );
					$taxonomy_data                                        = get_term( $taxonomy_id, $taxonomy );

					if ( ! $taxonomy_translate[ $taxonomy_id ]['sourceLanguage'] ) {
						$taxonomy_translate[ $taxonomy_id ]['sourceLanguage'] = false;
						$taxonomy_translate[ $taxonomy_id ]['title']          = $taxonomy_data->name;
						$taxonomy_translate[ $taxonomy_id ]['editor_type']    = 'taxonomy';
						$taxonomy_translate[ $taxonomy_id ]['post_link']      = html_entity_decode( get_edit_term_link( $taxonomy_data->term_id, $taxonomy_data->taxonomy ) );
						continue;
					}

					$taxonomy_translate[ $taxonomy_id ]['title'] = $taxonomy_data->name;

					if ( $slug_translation_option === 'slug_translate' ) {
						$taxonomy_translate[ $taxonomy_id ]['post_name'] = urldecode( $taxonomy_data->slug );
					}

					$taxonomy_translate[ $taxonomy_id ]['editor_type'] = 'taxonomy';

					if ( $taxonomy_data->description && ! empty( $taxonomy_data->description ) ) {
						$taxonomy_translate[ $taxonomy_id ]['content'] = $taxonomy_data->description;
					}

					foreach ( $translate_lang as $lang ) {
						if ( in_array( $lang, $lmat_langs_slugs ) ) {
							$post_translate_status = linguator_pro_get_term( $taxonomy_id, $lang );

							if ( ! $post_translate_status ) {
								$taxonomy_translate[ $taxonomy_id ]['languages'][] = $lang;
							} else {
								$term = get_term( $post_translate_status, $taxonomy );

								$title = isset( $term->name ) ? $term->name : '';
								$slug  = get_term_link( $post_translate_status, $taxonomy );

								if ( is_wp_error( $slug ) || empty( $slug ) ) {
									$slug = '';
								}

								$taxonomy_translate[ $taxonomy_id ]['postExists'][ $lang ] = array(
									'post_title' => $title,
									'post_url'   => $slug,
								);
							}
						}
					}
				}
			}

			$data = array(
				'posts'                    => $taxonomy_translate,
				'CreateTranslatePostNonce' => wp_create_nonce( 'lmat_create_translate_taxonomy_nonce' ),
			);

			if ( count( $taxonomy_translate ) > 0 ) {
				wp_send_json_success( $data );
			} else {
				wp_send_json_error( 'No taxonomy posts to translate' );
			}
		}

		public function create_translate_taxonomy( $params ) {
			if ( ! isset( $params['term_id'] ) || empty( $params['term_id'] ) ) {
				wp_send_json_error( 'Invalid term id' );
			}
			if ( ! isset( $params['target_language'] ) || empty( $params['target_language'] ) ) {
				wp_send_json_error( 'Invalid target language' );
			}
			if ( ! isset( $params['taxonomy'] ) || empty( $params['taxonomy'] ) ) {
				wp_send_json_error( 'Invalid taxonomy' );
			}
			if ( ! isset( $params['source_language'] ) || empty( $params['source_language'] ) ) {
				wp_send_json_error( 'Invalid source language' );
			}
			$private_key = isset( $params['privateKey'] ) ? sanitize_text_field( wp_unslash( (string) $params['privateKey'] ) ) : '';
			if ( '' === $private_key || ! wp_verify_nonce( $private_key, 'lmat_create_translate_taxonomy_nonce' ) ) {
				wp_send_json_error( 'You are not authorized to perform this action.' );
			}

			$params = $params->get_params();

			$term_id                 = intval( sanitize_text_field( $params['term_id'] ) );
			$target_language         = isset( $params['target_language'] ) ? sanitize_text_field( $params['target_language'] ) : '';
			$taxonomy                = isset( $params['taxonomy'] ) ? sanitize_text_field( $params['taxonomy'] ) : '';
			$taxonomy_name           = isset( $params['taxonomy_name'] ) ? sanitize_text_field( $this->ai_normalize_translation_string( (string) $params['taxonomy_name'] ) ) : '';
			$taxonomy_slug           = isset( $params['taxonomy_slug'] ) ? sanitize_title( $params['taxonomy_slug'] ) : '';
			$taxonomy_description    = isset( $params['taxonomy_description'] ) ? wp_kses_post( $this->ai_normalize_translation_string( (string) $params['taxonomy_description'] ) ) : '';
					$slug_translation_option = 'title_translate';
			if(property_exists(LMATP(), 'options') && isset(LMATP()->options['ai_translation_configuration']['slug_translation_option'])){
				$slug_translation_option = LMATP()->options['ai_translation_configuration']['slug_translation_option'];
			}
			if ( ! $target_language ) {
				wp_send_json_error( 'Invalid target language' );
			}
			if ( ! $taxonomy ) {
				wp_send_json_error( 'Invalid taxonomy' );
			}

			$get_term = get_term( $term_id, $taxonomy );

			$translations = new Translations();

			if ( $taxonomy_name && ! empty( $taxonomy_name ) ) {
				$entry = $this->create_translation_entry( $get_term->name, $taxonomy_name, 'name' );
				$translations->add_entry( $entry );
			}

			if ( $taxonomy_description && ! empty( $taxonomy_description ) ) {
				$entry = $this->create_translation_entry( $get_term->description, $taxonomy_description, 'description' );
				$translations->add_entry( $entry );
			}

			if ( $slug_translation_option === 'slug_translate' && $taxonomy_slug && ! empty( $taxonomy_slug ) ) {
				$taxonomy_slug = sanitize_title( $taxonomy_slug );
			} elseif ( $slug_translation_option === 'slug_keep' ) {
				$taxonomy_slug = sanitize_text_field( $get_term->slug );
			} else {
				$taxonomy_slug = sanitize_title( $taxonomy_name );
			}

			if ( $taxonomy_slug && ! empty( $taxonomy_slug ) ) {
				$entry = $this->create_translation_entry( $get_term->slug, $taxonomy_slug, 'slug' );
				$translations->add_entry( $entry );
			}

			global $linguator;

			$target_language_object = $linguator->model->get_language( $target_language );
			$term_clone             = new Translation_Term_Model( $linguator );

			$term_id = $term_clone->translate(
				array(
					'id'   => $term_id,
					'data' => $translations,
				),
				$target_language_object
			);

			if ( ! $term_id ) {
				wp_send_json_error( 'Unable to create the translated post for parent post ID ' . $term_id . ' in ' . $target_language_object . '.' );
				exit;
			}

			$term_url       = get_term_link( $term_id, $taxonomy );
			$term           = get_term( $term_id, $taxonomy );
			$term_title     = html_entity_decode( $term->name );
			$term_link      = $term_url && is_string( $term_url ) ? html_entity_decode( $term_url ) : '';
			$term_edit_link = html_entity_decode( get_edit_term_link( $term_id, $taxonomy ) );

			wp_send_json_success(
				array(
					'post_id'                     => $term_id,
					'target_language'             => $target_language,
					'post_link'                   => $term_link,
					'post_title'                  => $term_title,
					'post_edit_link'              => $term_edit_link,
					'update_translate_data_nonce' => wp_create_nonce( 'lmat_update_translate_data_nonce' ),
				)
			);
		}

		public function create_translation_entry( $singular, $translation, $context ) {
			$entry = new Translation_Entry(
				array(
					'singular'    => $singular,
					'translation' => array( $translation ),
					'context'     => $context,
				)
			);
			return $entry;
		}
	}
endif;
