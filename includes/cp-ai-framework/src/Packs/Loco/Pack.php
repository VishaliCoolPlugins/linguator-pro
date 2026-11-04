<?php
/**
 * The Loco pack: LocoAI's translation abilities.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Loco;

use CoolPlugins\AiFramework\Packs\Loco\Abilities\Environment\Get_Site_Info;
use CoolPlugins\AiFramework\Packs\Loco\Abilities\Glossary\Get_Glossary;
use CoolPlugins\AiFramework\Packs\Loco\Abilities\Glossary\Manage_Glossary;
use CoolPlugins\AiFramework\Packs\Loco\Abilities\Projects\Get_Project_Status;
use CoolPlugins\AiFramework\Packs\Loco\Abilities\Projects\List_Projects;
use CoolPlugins\AiFramework\Packs\Loco\Abilities\Translation\Create_Language_File;
use CoolPlugins\AiFramework\Packs\Loco\Abilities\Translation\Create_Template;
use CoolPlugins\AiFramework\Packs\Loco\Abilities\Translation\Get_Strings;
use CoolPlugins\AiFramework\Packs\Loco\Abilities\Translation\Save_Translations;
use CoolPlugins\AiFramework\Packs\Loco\Abilities\Translation\Sync_Language_File;
use CoolPlugins\AiFramework\Packs\Loco\Services\Loco_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the pack to the framework and says when it may be offered.
 *
 * Unlike the Polylang and WPML packs, its base class does not extend
 * Core\Abstract_Ability: LocoAI's abilities use an untyped execute() signature
 * and their own input helpers, which Core does not share.
 *
 * @since 1.3.0
 */
final class Pack {

	/**
	 * Option that switches the pack off when set to "no".
	 *
	 * Same option the pre-framework LocoAI abilities used.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const OPTION_ENABLED = 'atlt_abilities_enabled';

	/**
	 * Whether the site owner has left the pack on. Defaults to on.
	 *
	 * @since 1.3.0
	 * @return bool True when enabled.
	 */
	public static function is_enabled() {
		return 'no' !== get_option( self::OPTION_ENABLED, 'yes' );
	}

	/**
	 * Whether the pack can be offered in this request.
	 *
	 * Needs the pack switched on, LocoAI Pro active (the tools call into it) and
	 * a recent enough Loco Translate. LocoAI prints its own notice when Loco
	 * Translate is missing, so this stays silent about it.
	 *
	 * @since 1.3.0
	 * @return bool True when the pack's abilities may be registered.
	 */
	public static function is_available() {
		return self::is_enabled()
			&& defined( 'ATLT_PRO_VERSION' )
			&& Loco_Guard::is_available();
	}

	/**
	 * The pack definition consumed by CPAF_Framework::packs().
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Pack definition.
	 */
	public static function definition() {
		return array(
			'id'           => 'loco',
			'label'        => __( 'LocoAI (Loco Translate)', 'loco-automatic-translate-addon-pro' ),
			'description'  => __( 'Manage plugin and theme translation files, strings, and language files.', 'loco-automatic-translate-addon-pro' ),
			'namespace'    => Abilities\Abstract_Ability::ABILITY_NAMESPACE,
			'category'     => array(
				'slug'        => Abilities\Abstract_Ability::ABILITY_CATEGORY,
				'label'       => __( 'LocoAI Translation', 'loco-automatic-translate-addon-pro' ),
				'description' => __( 'Translation tools for plugin and theme language files managed by Loco Translate, provided by LocoAI.', 'loco-automatic-translate-addon-pro' ),
			),
			'is_available' => array( __CLASS__, 'is_available' ),
			'groups'       => array(
				array(
					'label' => __( 'Translate', 'loco-automatic-translate-addon-pro' ),
					'tools' => array( 'get-strings', 'save-translations' ),
				),
				array(
					'label' => __( 'Language Files', 'loco-automatic-translate-addon-pro' ),
					'tools' => array( 'create-template', 'create-language-file', 'sync-language-file' ),
				),
				array(
					'label' => __( 'Glossary', 'loco-automatic-translate-addon-pro' ),
					'tools' => array( 'get-glossary', 'manage-glossary' ),
				),
				array(
					'label' => __( 'Projects & Status', 'loco-automatic-translate-addon-pro' ),
					'tools' => array( 'get-site-info', 'list-projects', 'get-project-status' ),
				),
			),
			'abilities'    => array(
				Get_Site_Info::class,
				List_Projects::class,
				Get_Project_Status::class,
				Create_Template::class,
				Create_Language_File::class,
				Get_Strings::class,
				Save_Translations::class,
				Sync_Language_File::class,
				Get_Glossary::class,
				Manage_Glossary::class,
			),
		);
	}
}
