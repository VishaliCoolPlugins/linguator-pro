<?php
/**
 * The Linguator Inspector pack: Linguator Pro's Site Scan diagnose, scan and fix abilities.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorInspector;

use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Content\Inspect_Content;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Content\List_Urls;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Fixes\Fix_Issue;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Issues\Get_Fix_Plan;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Issues\Get_Issues;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Issues\Ignore_Issues;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Reports\Get_Report;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Scans\Recheck_Issue;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Scans\Run_Scan;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Status\Get_Inspector_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the pack to the framework and says when it may be offered.
 *
 * The scan engine ships inside Linguator Pro (modules/scan), so the pack is
 * offered only while Linguator Pro is active and its scanner is loaded.
 *
 * @since 1.2.0
 */
final class Pack {

	/**
	 * Option that switches the pack off when set to "no".
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const OPTION_ENABLED = 'lmat_inspector_abilities_enabled';

	/**
	 * Whether the site owner has left the pack on. Defaults to on.
	 *
	 * @since 1.2.0
	 * @return bool True when enabled.
	 */
	public static function is_enabled() {
		return 'no' !== get_option( self::OPTION_ENABLED, 'yes' );
	}

	/**
	 * Whether the pack can be offered in this request.
	 *
	 * Needs the pack switched on, Linguator Pro active and its Site Scan
	 * engine loaded. The engine loads only once the free Linguator plugin is
	 * ready, so this stays silent when Linguator itself is missing.
	 *
	 * @since 1.2.0
	 * @return bool True when the pack's abilities may be registered.
	 */
	public static function is_available() {
		return self::is_enabled()
			&& defined( 'LINGUATOR_PRO_VERSION' )
			&& class_exists( '\LinguatorPro\Modules\Scan\Scanner' );
	}

	/**
	 * The pack definition consumed by CPAF_Framework::packs().
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Pack definition.
	 */
	public static function definition() {
		return array(
			'id'           => 'linguator-inspector',
			'label'        => __( 'Linguator Site Scan', 'linguator-pro' ),
			'description'  => __( 'Scan a Linguator site for translation problems, read the issues and fix plan, and apply or confirm fixes.', 'linguator-pro' ),
			'namespace'    => Abilities\Abstract_Ability::ABILITY_NAMESPACE,
			'category'     => array(
				'slug'        => Abilities\Abstract_Ability::ABILITY_CATEGORY,
				'label'       => __( 'Linguator Site Scan', 'linguator-pro' ),
				'description' => __( 'Tools that find and fix translation problems on a Linguator site, provided by Linguator Pro.', 'linguator-pro' ),
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
