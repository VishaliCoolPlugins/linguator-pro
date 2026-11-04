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
}
