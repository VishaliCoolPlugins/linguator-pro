<?php
/**
 * String group listing ability.
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
 * Lists registered string groups and their translation status.
 *
 * @since 1.10.0
 */
final class Get_String_Groups extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-string-groups';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get String Groups', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: list all registered Polylang string translation groups with their strings and per-language translation status. Optionally filter by group name.', 'autopoly-ai-translation-for-polylang-pro' );
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
				'group' => array(
					'type'        => 'string',
					'description' => __( 'Filter to a specific string group name. Omit for all groups.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<int, array<string, mixed>> String groups.
	 */
	public static function execute( array $input = array() ) {
		$group = isset( $input['group'] ) ? sanitize_text_field( (string) $input['group'] ) : null;

		return String_Service::get_string_groups( $group );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'edit_posts', $input );
	}
}
