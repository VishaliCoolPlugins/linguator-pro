<?php
/**
 * The Inspector pack: Translation Inspector's diagnose, scan and fix abilities.
 *
 * @package CoolPluginsAiFramework
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Inspector;

use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Content\Inspect_Content;
use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Content\List_Urls;
use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Fixes\Fix_Issue;
use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Issues\Get_Fix_Plan;
use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Issues\Get_Issues;
use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Issues\Ignore_Issues;
use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Reports\Get_Report;
use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Scans\Recheck_Issue;
use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Scans\Run_Scan;
use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Status\Get_Inspector_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the pack to the framework and says when it may be offered.
 *
 * The Inspector engine ships in the free Toolkit for Polylang plugin, so the
 * pack is offered only while that plugin is active and its Inspector tool is
 * switched on.
 *
 * @since 1.5.0
 */
final class Pack {

	/**
	 * Option that switches the pack off when set to "no".
	 *
	 * Same option the standalone Translation Inspector abilities used.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const OPTION_ENABLED = 'dupcap_abilities_enabled';

	/**
	 * Whether the site owner has left the pack on. Defaults to on.
	 *
	 * @since 1.5.0
	 * @return bool True when enabled.
	 */
	public static function is_enabled() {
		return 'no' !== get_option( self::OPTION_ENABLED, 'yes' );
	}

	/**
	 * Whether the pack can be offered in this request.
	 *
	 * Needs the pack switched on, the Toolkit for Polylang plugin active with
	 * its Inspector tool enabled (the setup wizard can turn it off), and the
	 * Inspector engine loaded. The engine only loads when Polylang is active, so
	 * this stays silent about a missing Polylang.
	 *
	 * @since 1.5.0
	 * @return bool True when the pack's abilities may be registered.
	 */
	public static function is_available() {
		return self::is_enabled()
			&& defined( 'DUPCAP_VERSION' )
			&& function_exists( 'dupcap_is_tool_enabled' )
			&& dupcap_is_tool_enabled( 'inspector' )
			&& class_exists( 'DUPCAP_Scanner' );
	}

	/**
	 * The pack definition consumed by CPAF_Framework::packs().
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> Pack definition.
	 */
	public static function definition() {
		return array(
			'id'           => 'inspector',
			'label'        => __( 'Translation Inspector', 'duplicate-content-addon-for-polylang' ),
			'description'  => __( 'Scan a Polylang site for translation problems, read the issues and fix plan, and apply or confirm fixes.', 'duplicate-content-addon-for-polylang' ),
			'namespace'    => Abilities\Abstract_Ability::ABILITY_NAMESPACE,
			'category'     => array(
				'slug'        => Abilities\Abstract_Ability::ABILITY_CATEGORY,
				'label'       => __( 'Translation Inspector', 'duplicate-content-addon-for-polylang' ),
				'description' => __( 'Tools that find and fix translation problems on a Polylang site, provided by Translation Inspector.', 'duplicate-content-addon-for-polylang' ),
			),
			'is_available' => array( __CLASS__, 'is_available' ),
			'abilities'    => array(
				Get_Inspector_Status::class,
				Get_Fix_Plan::class,
				Get_Issues::class,
				Inspect_Content::class,
				List_Urls::class,
				Get_Report::class,
				Run_Scan::class,
				Recheck_Issue::class,
				Fix_Issue::class,
				Ignore_Issues::class,
			),
		);
	}
}
