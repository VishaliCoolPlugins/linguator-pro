<?php
/**
 * Elementor template preflight ability.
 *
 * @package AutoPoly
 * @since   1.14.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Environment;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Template_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports everything an Elementor header or footer translation depends on, before it starts.
 *
 * @since 1.14.0
 */
final class Preflight_Template extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.14.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'preflight-template';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.14.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Preflight Template', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.14.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: check, in one response and before translating, whether an Elementor header, footer or page can be translated and shown. It reports whether Elementor templates are translatable in Polylang, whether the template has a language, whether Connect Polylang for Elementor is active (without it a translated header is never shown), and which menu each Nav Menu widget shows, with the translated menu for the target language if one exists. Menu Sync is not required to translate a template; it is relevant only when a Nav Menu widget needs a separate target-language menu. next_steps lists what to fix or do, in order. Read only. Settings it reports as missing cannot be changed with these tools; ask the user.', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.14.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'         => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the source Elementor template or page.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Language slug you intend to translate into, for example "hi". Optional; it adds the translated menu lookup.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
			),
			'required'   => array( 'post_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.14.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.14.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Report, or an error.
	 */
	public static function execute( array $input = array() ) {
		return Template_Service::preflight(
			isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0,
			isset( $input['target_language'] ) ? (string) $input['target_language'] : ''
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Administrator-only, like get-translation-settings: it reports site configuration.
	 *
	 * @since 1.14.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
