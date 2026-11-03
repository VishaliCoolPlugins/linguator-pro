<?php
/**
 * Translation plan ability.
 *
 * @package AutoPoly
 * @since   1.16.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Environment;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Scope_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the checklist for translating the homepage, header, footer and a menu.
 *
 * @since 1.16.0
 */
final class Get_Translation_Plan extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.16.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-translation-plan';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.16.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Translation Plan', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.16.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: call this FIRST for a request such as "translate the homepage, header, footer and the pages in the header menu". It returns the full checklist in a safe order: the header and footer templates, the static homepage, then every page, term and custom link in the header menu (nested items included, with their depth). Each item says whether its translation is missing, unpublished, has no language, or is already published. It also lists blockers (for example Connect Polylang for Elementor not active), the steps to follow, next_item, and verify_batches to pass to verify-translations at the end. Read only: it changes nothing, so call it again at any time to resume after an interruption or to see what is left. "translated" means a published translation exists, not that it is correct; finish with verify-translations. Pass menu_id to plan a different menu (available_menus lists them).', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.16.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Language slug to translate into, for example "fr" or "de". It must not be the default language.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'menu_id'         => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Navigation menu to include. Omit to use the menu in the main or header theme location.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'include'         => array(
					'type'        => array( 'string', 'array' ),
					'description' => __( 'Parts of the site to include: any of "homepage", "header", "footer", "menu". Omit for all four. Accepts an array or a comma-separated string.', 'autopoly-ai-translation-for-polylang-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'target_language' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.16.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'outcome'     => array( 'type' => 'string' ),
				'summary'     => array( 'type' => 'string' ),
				'next_action' => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.16.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Plan, or an error.
	 */
	public static function execute( array $input = array() ) {
		return Scope_Service::plan(
			isset( $input['target_language'] ) ? (string) $input['target_language'] : '',
			isset( $input['menu_id'] ) ? absint( $input['menu_id'] ) : 0,
			array_map( 'strval', self::normalize_list( isset( $input['include'] ) ? $input['include'] : array() ) )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Administrator-only, like get-translation-settings: it reports site configuration.
	 *
	 * @since 1.16.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
