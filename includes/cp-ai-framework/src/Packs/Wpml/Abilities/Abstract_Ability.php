<?php
/**
 * Base class for the WPML pack's abilities.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities;

use CoolPlugins\AiFramework\Core\Abstract_Ability as Core_Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets the AutoMLP identity every WPML ability shares. The values match what
 * the abilities had before they moved into the framework, so names, category
 * and the capability filter stay the same.
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
	const ABILITY_NAMESPACE = 'automlp';

	/**
	 * Ability category slug.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const ABILITY_CATEGORY = 'automlp-wpml';

	/**
	 * Capability filter name.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const CAPABILITY_FILTER = 'automlp_ability_required_capability';
}
