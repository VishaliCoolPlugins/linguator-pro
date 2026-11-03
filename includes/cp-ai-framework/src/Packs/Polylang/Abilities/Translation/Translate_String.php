<?php
/**
 * String translation ability.
 *
 * @package AutoPoly
 * @since   1.10.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\String_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets the translation of a registered Polylang string.
 *
 * @since 1.10.0
 */
final class Translate_String extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'translate-string';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Translate String', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: set the translation of a registered Polylang string such as the site title, tagline or a widget title. The original string must match exactly; call get-string-groups first to see the available strings. Also translates navigation menu labels: pass a label from a sync-menu dry run\'s labels_unchanged exactly, and sync-menu uses the translation for that label.', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'string'      => array(
					'type'        => 'string',
					'description' => __( 'The original string. Must match exactly.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'language'    => array(
					'type'        => 'string',
					'description' => __( 'Target language slug, for example "fr" or "de".', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'translation' => array(
					'type'        => 'string',
					'description' => __( 'The translated string.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
			),
			'required'   => array( 'string', 'language', 'translation' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return array<string, mixed> Meta array.
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
	 * @since 1.10.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		return String_Service::translate_string(
			isset( $input['string'] ) ? (string) $input['string'] : '',
			isset( $input['language'] ) ? (string) $input['language'] : '',
			isset( $input['translation'] ) ? (string) $input['translation'] : ''
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Administrator-only. Despite being a translation operation, this writes
	 * site-wide interface strings rather than a single piece of content.
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
