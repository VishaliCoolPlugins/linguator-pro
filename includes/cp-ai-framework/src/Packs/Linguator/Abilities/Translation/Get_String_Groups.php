<?php
/**
 * Get String Groups ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\String_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get String Groups.
 *
 * @since 1.2.0
 */
final class Get_String_Groups extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-string-groups';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get String Groups', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: list the groups on the Linguator strings screen with how many strings each has and how many are translated per language. Pass context and language to list the strings of one group with their current translation.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'context' => array(
					'type'        => 'string',
					'description' => __( 'Group name to list the strings of. Optional.', 'linguator-pro' ),
				),
				'language' => array(
					'type'        => 'string',
					'description' => __( 'Language slug the strings are listed for. Required with context.', 'linguator-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		return String_Service::get_groups(
			isset( $input['context'] ) ? (string) $input['context'] : '',
			isset( $input['language'] ) ? (string) $input['language'] : ''
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
