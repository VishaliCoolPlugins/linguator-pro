<?php
/**
 * Get Inspector Status ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Status;

use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Inspector_Guard;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Report_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Inspector Status.
 *
 * @since 1.2.0
 */
final class Get_Inspector_Status extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-inspector-status';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Inspector Status', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator Site Scan: start here. Reports whether this Linguator site has been scanned, when, the health score and how many critical and warning issues the last scan found (scan.issues, the same counts as get-issues; scan.checks is the raw per-check tally); whether Elementor results and the live URL test have saved results; how many issues are ignored; and whether the linguator/* translation abilities are available to fix issues automatically. Also returns the multilingual setup (languages, default language, page builder, theme, active Linguator add-ons) and translation coverage per language, weakest first; leave either out with include. next_step says which ability to call next.', 'linguator-pro' );
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
				'include' => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => Report_Service::STATUS_EXTRAS,
					),
					'uniqueItems' => true,
					'default'     => Report_Service::STATUS_EXTRAS,
					'description' => __( 'Extra parts to return. "environment": the multilingual setup. "coverage": translation coverage per language. Both by default; pass [] for the scan state only.', 'linguator-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param mixed $input Validated input, or null.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( $input = array() ) {
		$input = self::input_array( $input );

		if ( ! Inspector_Guard::engine_ready() ) {
			return Inspector_Guard::engine_error();
		}

		$include = array_key_exists( 'include', $input )
			? array_map( 'strval', self::normalize_list( $input['include'] ) )
			: Report_Service::STATUS_EXTRAS;

		return Report_Service::get_status( array_values( array_intersect( Report_Service::STATUS_EXTRAS, $include ) ) );
	}
}
