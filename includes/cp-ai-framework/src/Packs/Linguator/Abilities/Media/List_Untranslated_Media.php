<?php
/**
 * List Untranslated Media ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Media;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Media_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List Untranslated Media.
 *
 * @since 1.3.0
 */
final class List_Untranslated_Media extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'list-untranslated-media';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'List Untranslated Media', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: list media library items (default language unless source_language is given) that have no translation in the target language, with ID, title, filename, MIME type, URL, alt text, caption, language and translations. Needs Linguator\'s media support setting; when it is off an error explains how to switch it on. Translate items with translate-media.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Language slug to find missing media translations for, for example "fr".', 'linguator-pro' ),
				),
				'source_language' => array(
					'type'        => 'string',
					'description' => __( 'Language slug of the media to list. Defaults to the default language.', 'linguator-pro' ),
				),
				'mime_type' => array(
					'type'        => 'string',
					'description' => __( 'Optional MIME filter, for example "image" or "image/png".', 'linguator-pro' ),
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum items to return. Defaults to 20, maximum 100.', 'linguator-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'offset' => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to skip for pagination. Defaults to 0.', 'linguator-pro' ),
					'default'     => 0,
					'minimum'     => 0,
				),
			),
			'required'   => array( 'target_language' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		return Media_Service::list_untranslated( $input );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'upload_files', $input );
	}
}
