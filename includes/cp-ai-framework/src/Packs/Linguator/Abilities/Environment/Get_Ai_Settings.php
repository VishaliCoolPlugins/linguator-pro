<?php
/**
 * Get AI Settings ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Environment;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Diagnostics_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get AI Settings.
 *
 * @since 1.3.0
 */
final class Get_Ai_Settings extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-ai-settings';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get AI Settings', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: list the AI translation providers (Google Translate, Chrome and Edge built-in AI, Gemini) with whether each is allowed, enabled and configured, the default provider, whether menu labels are machine translated, and the usage tracking choice. API keys are never returned.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return $this->empty_object_schema();
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
		return Diagnostics_Service::ai_settings();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
