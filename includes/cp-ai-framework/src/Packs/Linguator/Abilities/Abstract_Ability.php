<?php
/**
 * Base class for the Linguator pack's abilities.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities;

use CoolPlugins\AiFramework\Core\Abstract_Ability as Core_Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets the Linguator identity every Linguator ability shares.
 *
 * @since 1.2.0
 */
abstract class Abstract_Ability extends Core_Ability {

	/**
	 * Ability name prefix.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const ABILITY_NAMESPACE = 'linguator';

	/**
	 * Ability category slug.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const ABILITY_CATEGORY = 'linguator-translation';

	/**
	 * Capability filter name.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const CAPABILITY_FILTER = 'lmat_ability_required_capability';

	/**
	 * Builds the argument array consumed by wp_register_ability().
	 *
	 * An MCP call or REST GET with no arguments passes null, which fails
	 * "type: object". The schema default makes WP_Ability use an empty array, so
	 * every tool can be called with no arguments.
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Registration arguments.
	 */
	public function get_args() {
		$args   = parent::get_args();
		$schema = $args['input_schema'];

		if ( is_array( $schema ) && isset( $schema['type'] ) && 'object' === $schema['type'] && ! array_key_exists( 'default', $schema ) ) {
			$schema['default']    = array();
			$args['input_schema'] = $schema;
		}

		return $args;
	}
}
