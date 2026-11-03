<?php
/**
 * Elementor gallery media ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Elementor_Media_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audits and repairs the media Elementor's gallery and image widgets
 * reference on a post, separately from the post's own translated text.
 *
 * AutoMLP's post translation never touches attachments: a translated page
 * keeps the exact same gallery images the source page had, thumbnail files
 * and all, so a size WordPress never generated for that attachment stays
 * missing and a caption never gets localized unless done by hand.
 *
 * @since 1.5.0
 */
final class Repair_Elementor_Media extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'repair-elementor-media';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Repair Elementor Media', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: work with the gallery and image media Elementor widgets reference on one post, which post translation never touches. "audit" (default) lists those attachments with their title/caption/description, language, whether the requested image size exists as a real file, and flags a caption that is still identical to the source-language attachment\'s caption. "repair" regenerates a missing image size for those attachments. "update-captions" bulk-updates attachment title/caption/description/alt text by attachment ID, for fixing captions the audit flagged; it ignores post_id and uses "updates" instead. post_id is the post whose galleries to scan, not an attachment ID.', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'  => array(
					'type'        => 'string',
					'description' => __( 'What to do: "audit" (default), "repair", or "update-captions".', 'wpml-translation-check-pro' ),
					'enum'        => array( 'audit', 'repair', 'update-captions' ),
					'default'     => 'audit',
				),
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'The post containing the Elementor galleries to scan. Required for "audit" and "repair", ignored for "update-captions".', 'wpml-translation-check-pro' ),
				),
				'size'    => array(
					'type'        => 'string',
					'description' => __( 'Registered image size to check for, e.g. "medium" or "large", or "full" for the original file. Defaults to "medium".', 'wpml-translation-check-pro' ),
					'default'     => 'medium',
				),
				'force'   => array(
					'type'        => 'boolean',
					'description' => __( '"repair" only: regenerate metadata even when the requested size already exists.', 'wpml-translation-check-pro' ),
					'default'     => false,
				),
				'updates' => array(
					'type'        => 'array',
					'description' => __( '"update-captions" only: one entry per attachment to update.', 'wpml-translation-check-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'          => array(
								'type'        => 'integer',
								'description' => __( 'Attachment ID to update.', 'wpml-translation-check-pro' ),
							),
							'title'       => array( 'type' => 'string' ),
							'caption'     => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'alt_text'    => array( 'type' => 'string' ),
						),
						'required'   => array( 'id' ),
					),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> Ability meta, overridden because "repair" and "update-captions" write data.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	public static function execute( array $input = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$action = isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : 'audit';

		if ( ! in_array( $action, array( 'audit', 'repair', 'update-captions' ), true ) ) {
			return new WP_Error(
				'automlp_invalid_action',
				__( 'action must be one of "audit", "repair", "update-captions".', 'wpml-translation-check-pro' )
			);
		}

		if ( 'update-captions' === $action ) {
			$updates = isset( $input['updates'] ) && is_array( $input['updates'] ) ? $input['updates'] : array();

			if ( empty( $updates ) ) {
				return new WP_Error(
					'automlp_no_updates',
					__( 'Provide at least one entry in updates.', 'wpml-translation-check-pro' )
				);
			}

			return Elementor_Media_Service::update_captions( $updates );
		}

		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( 0 === $post_id ) {
			return new WP_Error(
				'automlp_invalid_post',
				__( 'A post_id is required for this action.', 'wpml-translation-check-pro' )
			);
		}

		$size = isset( $input['size'] ) && '' !== (string) $input['size'] ? sanitize_key( (string) $input['size'] ) : 'medium';

		if ( 'repair' === $action ) {
			return Elementor_Media_Service::repair( $post_id, $size, ! empty( $input['force'] ) );
		}

		return Elementor_Media_Service::audit( $post_id, $size );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return false;
		}

		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( 0 === $post_id ) {
			return self::user_can( 'edit_posts', $input );
		}

		return self::user_can( 'edit_post', $input, $post_id );
	}
}
