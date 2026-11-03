<?php
/**
 * Base class for the Polylang pack's abilities.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities;

use CoolPlugins\AiFramework\Core\Abstract_Ability as Core_Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets the AutoPoly identity every Polylang ability shares. The values match
 * what the abilities had before they moved into the framework, so names,
 * category and the capability filter stay the same for AI clients and for sites
 * that hook the filter.
 *
 * @since 1.1.0
 */
abstract class Abstract_Ability extends Core_Ability {

	/**
	 * Ability name prefix.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const ABILITY_NAMESPACE = 'autopoly';

	/**
	 * Ability category slug.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const ABILITY_CATEGORY = 'autopoly-polylang';

	/**
	 * Capability filter name.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const CAPABILITY_FILTER = 'atfpp_ability_required_capability';
}
