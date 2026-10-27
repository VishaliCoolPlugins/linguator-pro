<?php
namespace Linguator\Modules\Page_Translation;

/**
 * LMAT Page Translation Ajax Handler
 *
 * @package Linguator
 */

/**
 * Do not access the page directly
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Linguator\Includes\Other\Linguator_Translation_Dashboard;
use Linguator\Custom_Fields\Custom_Fields;
use Linguator\Includes\Services\Media\Media_Translation_Service;

/**
 * Handle LMAT Page Translation ajax requests
 */
if ( ! class_exists( 'Linguator_Page_Translation_Helper' ) ) {
	class Linguator_Page_Translation_Helper {
		/**
		 * Member Variable
		 *
		 * @var instance
		 */
		private static $instance;
		/**
		 * Stores custom block data for processing and retrieval.
		 *
		 * This static array holds the data related to custom blocks that are
		 * used within the plugin. It can be utilized to manage and manipulate
		 * the custom block information as needed during AJAX requests.
		 *
		 * @var array
		 */
		private $custom_block_data_array = array();

		/**
		 * Gets an instance of our plugin.
		 *
		 * @param object $settings_obj timeline settings.
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor.
		 *
		 * @param object $settings_obj Plugin settings.
		 */
		public function __construct() {
			if ( is_admin() ) {
				add_action( 'wp_ajax_lmat_update_translate_data', array( $this, 'linguator_update_translate_data' ) );
			}
		}

		/**
		 * Fetches post meta fields via AJAX request.
		 */
		public function fetch_post_meta_fields() {
			if ( ! check_ajax_referer( 'lmat_fetch_post_meta_fields', 'meta_fields_key', false ) ) {
				wp_send_json_error( __( 'Invalid security token sent.', 'linguator-pro' ) );
			}

			$post_id = isset( $_POST['postId']) ? absint(sanitize_text_field(wp_unslash($_POST['postId']))) : false;

			if(!isset($post_id) || false === $post_id){
				wp_send_json_error( __( 'Invalid Post ID.', 'linguator-pro' ) );
			}

			if(!current_user_can('edit_post', $post_id)){
				wp_send_json_error( __( 'Unauthorized', 'linguator-pro' ), 403 );
			}

			$post_meta_sync = true;

            if (!isset(LMAT()->options['sync']) || (isset(LMAT()->options['sync']) && !in_array('post_meta', LMAT()->options['sync']))) {
                $post_meta_sync = false;
            }

			if($post_meta_sync){
				wp_send_json_success( __( 'Post meta sync is enabled. Please disable post meta sync in Linguator settings.', 'linguator-pro' ) );
			}

			$allowed_meta_fields=Custom_Fields::get_allowed_custom_fields();
			$post_meta_fields=get_post_meta($post_id);

			$existed_meta_fields=array_intersect(array_keys($post_meta_fields), array_keys($allowed_meta_fields));
			$filtered_meta_fields=array();

			foreach($existed_meta_fields as $key){
				if(isset($post_meta_fields[$key]) && !empty($post_meta_fields[$key]) && isset($allowed_meta_fields[$key]['status']) && true === $allowed_meta_fields[$key]['status']){
					$value=$allowed_meta_fields[$key]['type'] && is_array($post_meta_fields[$key]) ? linguator_maybe_unserialize($post_meta_fields[$key][0]) : linguator_maybe_unserialize($post_meta_fields[$key]);
					$filtered_meta_fields[$key]=$value;
				}
			}

			wp_send_json_success( array( 'metaFields' => $filtered_meta_fields, 'allowedMetaFields' => $allowed_meta_fields ) );
			exit;
		}

		/**
		 * Fetches post content via AJAX request.
		 */
		public function fetch_post_content() {
			if ( ! check_ajax_referer( 'lmat_page_translation_admin', 'lmat_page_translation_nonce', false ) ) {
				wp_send_json_error( __( 'Invalid security token sent.', 'linguator-pro' ) );
			}

			$post_id = absint( isset( $_POST['postId'] ) ? absint( sanitize_text_field( wp_unslash( $_POST['postId'] ) ) ) : false );

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_send_json_error( __( 'Unauthorized', 'linguator-pro' ), 403 );
			}

			if ( false !== $post_id ) {
				$post_data               = get_post( absint( $post_id ) );
			$locale                  = isset( $_POST['local'] ) ? sanitize_text_field( wp_unslash( $_POST['local'] ) ) : 'en';
			$current_locale          = isset( $_POST['current_local'] ) ? sanitize_text_field( wp_unslash( $_POST['current_local'] ) ) : 'en';

				$slug_translation_option = 'title_translate';

				if(property_exists(LMAT(), 'options') && isset(LMAT()->options['ai_translation_configuration']['slug_translation_option'])){
					$slug_translation_option = LMAT()->options['ai_translation_configuration']['slug_translation_option'];
				}

				$content = $post_data->post_content;
				
				/**
				 * Filter the post content for translation.
				 * 
				 * @since 1.0.4
				 * @param string $content Post content.
				 * @param int    $post_id Post ID.
				 */
				$content = apply_filters( 'lmat_post_content_for_translation', $content, $post_id );

				if ( function_exists( 'linguator_replace_links_with_translations' ) ) {
					$content = linguator_replace_links_with_translations( $content, $locale, $current_locale );
				}

				$meta_fields = get_post_meta( $post_id );

				$data = array(
					'title'      => $post_data->post_title,
					'excerpt'    => $post_data->post_excerpt,
					'content'    => $content,
				);

				if ( $slug_translation_option === 'slug_translate' || $slug_translation_option === 'slug_keep' ) {
					$data['slug_name'] = urldecode( get_post_field( 'post_name', $post_id ) );
				}

				// Append media strings when media translation is enabled.
				if ( function_exists( 'LMAT' ) ) {
					$media_service = new Media_Translation_Service( LMAT() );
					if ( $media_service->is_enabled() ) {
						$featured = $media_service->get_featured_image_strings( $post_id );
						if ( ! empty( $featured ) ) {
							$data['featured_image'] = $featured;
						}

						$content_media = $media_service->get_content_media_strings( $post_id );
						if ( ! empty( $content_media ) ) {
							$data['content_media'] = $content_media;
						}
					}
				}

				return wp_send_json_success( $data );
			} else {
				wp_send_json_error( __( 'Invalid Post ID.', 'linguator-pro' ) );
			}

			exit;
		}

		public function linguator_update_translate_data() {
			if ( ! check_ajax_referer( 'lmat_update_translate_data_nonce', 'update_translation_key', false ) ) {
				wp_send_json_error( __( 'Invalid security token sent.', 'linguator-pro' ) );
			}

		$post_id     = isset( $_POST['post_id'] ) ? absint( sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) ) : 0;
		$editor_type = isset( $_POST['editorType'] ) ? sanitize_text_field( wp_unslash( $_POST['editorType'] ) ) : '';

		$extra_data = array();
		if ( isset( $_POST['extraData'] ) ) {
			$extra_data_raw = wp_unslash( $_POST['extraData'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded then sanitized key/value before use.
			$decoded        = json_decode( is_string( $extra_data_raw ) ? $extra_data_raw : '', true );
			$extra_data     = is_array( $decoded ) ? $decoded : array();
		}

			// Require capability based on context
			if ( $post_id > 0 ) {
				if ( ! current_user_can( 'edit_post', $post_id ) && $editor_type !== 'taxonomy' ) {
					wp_send_json_error( __( 'Unauthorized to edit post', 'linguator-pro' ), 403 );
				}
				
				if ( $editor_type === 'taxonomy' ) {
					if ( ! current_user_can( 'edit_posts' ) ) {
						wp_send_json_error( __( 'Unauthorized to edit terms', 'linguator-pro' ), 403 );
					}
				}
			} elseif ( ! current_user_can( 'edit_posts' ) ) {
					wp_send_json_error( __( 'Unauthorized', 'linguator-pro' ), 403 );
			}

		$provider            = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		$total_string_count  = isset( $_POST['totalStringCount'] ) ? absint( wp_unslash( $_POST['totalStringCount'] ) ) : 0;
		$total_word_count    = isset( $_POST['totalWordCount'] ) ? absint( wp_unslash( $_POST['totalWordCount'] ) ) : 0;
		$total_char_count    = isset( $_POST['totalCharacterCount'] ) ? absint( wp_unslash( $_POST['totalCharacterCount'] ) ) : 0;
		$date                = isset( $_POST['date'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( sanitize_text_field( wp_unslash( $_POST['date'] ) ) ) ) : '';
		$source_string_count = isset( $_POST['sourceStringCount'] ) ? absint( wp_unslash( $_POST['sourceStringCount'] ) ) : 0;
		$source_word_count   = isset( $_POST['sourceWordCount'] ) ? absint( wp_unslash( $_POST['sourceWordCount'] ) ) : 0;
		$source_char_count   = isset( $_POST['sourceCharacterCount'] ) ? absint( wp_unslash( $_POST['sourceCharacterCount'] ) ) : 0;
		$source_lang         = isset( $_POST['sourceLang'] ) ? sanitize_text_field( wp_unslash( $_POST['sourceLang'] ) ) : '';
		$target_lang         = isset( $_POST['targetLang'] ) ? sanitize_text_field( wp_unslash( $_POST['targetLang'] ) ) : '';
			$time_taken          = isset( $_POST['timeTaken'] ) ? absint( wp_unslash( $_POST['timeTaken'] ) ) : 0;

			if ( class_exists( Linguator_Translation_Dashboard::class ) ) {
				$translation_data = array(
					'post_id'                => $post_id,
					'service_provider'       => $provider,
					'source_language'        => $source_lang,
					'target_language'        => $target_lang,
					'time_taken'             => $time_taken,
					'string_count'           => $total_string_count,
					'word_count'             => $total_word_count,
					'character_count'        => $total_char_count,
					'source_string_count'    => $source_string_count,
					'source_word_count'      => $source_word_count,
					'source_character_count' => $source_char_count,
					'editor_type'            => $editor_type,
					'date_time'              => $date,
					'version_type'           => 'free',
				);

				if ( ! empty( $extra_data ) && is_array( $extra_data ) && count( $extra_data ) > 0 ) {
					foreach ( $extra_data as $key => $value ) {
						if ( ! isset( $translation_data[ $key ] ) && ! empty( $value ) && ! empty( $key ) ) {
							$translation_data[ sanitize_text_field( $key ) ] = sanitize_text_field( $value );
						}
					}
				}

				Linguator_Translation_Dashboard::store_options(
					'lmat',
					'post_id',
					'update',
					$translation_data
				);

				wp_send_json_success(
					array(
						'message' => __( 'Translation data updated successfully', 'linguator-pro' ),
					)
				);
			} else {
				wp_send_json_error(
					array(
						'message' => __( 'Lmat_Dashboard class not found', 'linguator-pro' ),
					)
				);
			}
			exit;
		}

		/**
		 * Saves translated attachment metadata submitted by the page-translation UI.
		 *
		 * Expects POST fields:
		 *   lmat_media_nonce     – nonce verified against 'lmat_save_media_translations'.
		 *   post_id              – ID of the translated (target) post.
		 *   featured_image       – JSON-encoded translated strings for the featured image.
		 *   content_media        – JSON-encoded translated strings for content attachments.
		 *   source_post_id       – ID of the original (source) post.
		 */
		public function save_media_translations() {
			if ( ! check_ajax_referer( 'lmat_save_media_translations', 'lmat_media_nonce', false ) ) {
				wp_send_json_error( __( 'Invalid security token sent.', 'linguator-pro' ), 403 );
			}

			$post_id        = isset( $_POST['post_id'] ) ? absint( sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) ) : 0;
			$source_post_id = isset( $_POST['source_post_id'] ) ? absint( sanitize_text_field( wp_unslash( $_POST['source_post_id'] ) ) ) : 0;

			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				wp_send_json_error( __( 'Unauthorized', 'linguator-pro' ), 403 );
			}

			if ( ! $source_post_id || ! current_user_can( 'edit_post', $source_post_id ) ) {
				wp_send_json_error( __( 'Unauthorized', 'linguator-pro' ), 403 );
			}

			if ( ! function_exists( 'LMAT' ) ) {
				wp_send_json_error( __( 'Linguator not available.', 'linguator-pro' ), 500 );
			}

			$media_service = new Media_Translation_Service( LMAT() );

			if ( ! $media_service->is_enabled() ) {
				wp_send_json_success( array( 'message' => __( 'Media translation is disabled.', 'linguator-pro' ) ) );
			}

			$target_language = LMAT()->model->post->get_language( $post_id );
			$linked_post_id  = $target_language
				? (int) LMAT()->model->post->get_translation( $source_post_id, $target_language )
				: 0;

			// Single-page translation applies content before the editor's first
			// real save creates the translation link. The CRUD layer stores the
			// nonce-verified source and language intent on that auto-draft.
			$target_post = get_post( $post_id );
			$pending_translation = $target_language
				&& $target_post instanceof \WP_Post
				&& 'auto-draft' === $target_post->post_status
				&& $source_post_id === absint( get_post_meta( $post_id, '_lmat_from_post', true ) )
				&& $target_language->slug === get_post_meta( $post_id, '_lmat_new_lang', true )
				&& 0 === $linked_post_id;

			if ( $linked_post_id !== $post_id && ! $pending_translation ) {
				wp_send_json_error( __( 'The source and target posts are not linked translations.', 'linguator-pro' ), 400 );
			}

			// Decode and authorize the whole request before writing any attachment.
			$payload = array();
			foreach ( array( 'featured_image', 'content_media' ) as $key ) {
				if ( empty( $_POST[ $key ] ) ) {
					continue;
				}
				$raw = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the media service.
				if ( ! is_string( $raw ) ) {
					wp_send_json_error( __( 'Invalid media translation data.', 'linguator-pro' ), 400 );
				}
				$payload[ $key ] = json_decode( $raw, true );
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload[ $key ] ) ) {
					wp_send_json_error( __( 'Invalid media translation data.', 'linguator-pro' ), 400 );
				}
			}
			$validation = $media_service->validate_media_payload( $source_post_id, $target_language, $payload );
			if ( is_wp_error( $validation ) ) {
				wp_send_json_error( $validation->get_error_message(), 403 );
			}

			$updated   = false;
			$media_map = array();

			if ( ! empty( $payload['featured_image'] ) ) {
				$featured_updated = $media_service->apply_featured_image_translations( $source_post_id, $post_id, $payload['featured_image'] );
				$updated          = $updated || $featured_updated;
				$source_thumb     = (int) get_post_thumbnail_id( $source_post_id );
				$target_thumb     = (int) get_post_thumbnail_id( $post_id );
				if ( $source_thumb > 0 && $target_thumb > 0 && $source_thumb !== $target_thumb ) {
					$media_map[ $source_thumb ] = $target_thumb;
				}
			}

			if ( ! empty( $payload['content_media'] ) ) {
				$content_map = $media_service->apply_content_media_translations( $post_id, $payload['content_media'], $source_post_id );
				if ( ! empty( $content_map ) ) {
					$updated   = true;
					$media_map = $media_map + $content_map;
				}
			}

			if ( ! empty( $media_map ) ) {
				$media_service->remap_post_content( $post_id, $media_map );
				// Keep map until the editor's first save remaps in-memory content IDs.
				update_post_meta( $post_id, '_lmat_pending_media_map', $media_map );
			}

			wp_send_json_success(
				array(
					'updated'   => $updated,
					'media_map' => (object) $media_map,
					'message'   => $updated
						? __( 'Media translations saved.', 'linguator-pro' )
						: __( 'No media translations to save.', 'linguator-pro' ),
				)
			);
		}


		/**
		 * Remaps post content / featured image using a pending media map from AI media save.
		 *
		 * @param int $post_id Post ID being saved.
		 * @return void
		 */
		public function apply_pending_media_map( $post_id ) {
			$post_id = absint( $post_id );
			if ( $post_id <= 0 || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
				return;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) || ! function_exists( 'LMAT' ) ) {
				return;
			}

			$map = get_post_meta( $post_id, '_lmat_pending_media_map', true );
			if ( ! is_array( $map ) || empty( $map ) ) {
				return;
			}

			$media_service = new Media_Translation_Service( LMAT() );
			if ( ! $media_service->is_enabled() ) {
				delete_post_meta( $post_id, '_lmat_pending_media_map' );
				return;
			}

			$normalized = array();
			foreach ( $map as $source_id => $target_id ) {
				$source_id = absint( $source_id );
				$target_id = absint( $target_id );
				if ( $source_id > 0 && $target_id > 0 && $source_id !== $target_id ) {
					$normalized[ $source_id ] = $target_id;
				}
			}

			if ( empty( $normalized ) ) {
				delete_post_meta( $post_id, '_lmat_pending_media_map' );
				return;
			}

			// Clear before writes so nested save_post from wp_update_post cannot re-enter.
			delete_post_meta( $post_id, '_lmat_pending_media_map' );

			$media_service->remap_post_content( $post_id, $normalized );

			$featured = (int) get_post_thumbnail_id( $post_id );
			if ( $featured > 0 && isset( $normalized[ $featured ] ) ) {
				set_post_thumbnail( $post_id, $normalized[ $featured ] );
			}
		}

		/**
		 * Handle AJAX request to update Elementor data.
		 */
		public function update_elementor_data() {
			if ( ! check_ajax_referer( 'lmat_page_translation_admin', 'lmat_page_translation_nonce', false ) ) {
				wp_send_json_error( __( 'Invalid security token sent.', 'linguator-pro' ) );
			}
			$post_id = isset( $_POST['post_id'] ) ? absint( sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) ) : 0;
			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				wp_send_json_error( __( 'Unauthorized', 'linguator-pro' ), 403 );
			}

		// Optional hardening: enforce valid JSON if not using Elementor Document API
		if ( isset( $_POST['elementor_data'] ) && is_string( $_POST['elementor_data'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['elementor_data'] ), true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					wp_send_json_error( __( 'Invalid data.', 'linguator-pro' ), 400 );
				}
			}

			$parent_post_id          = isset( $_POST['parent_post_id'] ) ? intval( sanitize_text_field( wp_unslash( $_POST['parent_post_id'] ) ) ) : 0;

			$current_slug            = get_post_field( 'post_name', $post_id );
			$new_post_name           = false;
			
			$slug_translation_option = 'title_translate';
			if(property_exists(LMAT(), 'options') && isset(LMAT()->options['ai_translation_configuration']['slug_translation_option'])){
				$slug_translation_option = LMAT()->options['ai_translation_configuration']['slug_translation_option'];
			}

			$elementor_data = ! empty( $_POST['elementor_data'] ) ? wp_unslash( $_POST['elementor_data'] ) : '';

			if ( '' === $current_slug ) {
			if ( ! empty( $_POST['post_name'] ) && '' !== $_POST['post_name'] && $slug_translation_option === 'slug_translate' ) {
				$new_post_name = sanitize_title( wp_unslash( $_POST['post_name'] ) );
				} elseif ( $slug_translation_option === 'slug_keep' ) {
					$new_post_name = sanitize_text_field( get_post_field( 'post_name', $parent_post_id ) );
				}
			}

			// Check if the current post has Elementor data
			if ( $elementor_data && '' !== $elementor_data ) {
				if ( class_exists( 'Elementor\Plugin' ) ) {
					$plugin   = \Elementor\Plugin::$instance;
					$document = $plugin->documents->get( $post_id );

					$elementor_data = json_decode( wp_unslash( $_POST['elementor_data'] ), true );

					if ( json_last_error() !== JSON_ERROR_NONE ) {
						wp_send_json_error( __( 'Invalid Elementor data.', 'linguator-pro' ), 400 );
					}

					// Remap attachment IDs and URLs when media translation is enabled.
					if ( function_exists( 'LMAT' ) ) {
						$media_service = new Media_Translation_Service( LMAT() );
						if ( $media_service->is_enabled() ) {
							$target_language = LMAT()->model->post->get_language( $post_id );
							if ( $target_language ) {
								$elementor_data = $media_service->remap_elementor_media( $elementor_data, $target_language );
							}
						}
					}

					$document->save(
						array(
							'elements' => $elementor_data,
						)
					);

					$plugin->files_manager->clear_cache();
					update_post_meta( $post_id, '_lmat_elementor_translated', 'true' );
				}
			}

			if ( $new_post_name && '' !== $new_post_name ) {
				wp_update_post(
					array(
						'ID'        => $post_id,
						'post_name' => $new_post_name,
					)
				);
			}

			wp_send_json_success( 'Elementor data updated.' );
			exit;
		}
	}
}


