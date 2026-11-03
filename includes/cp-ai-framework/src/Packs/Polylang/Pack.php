<?php
/**
 * The Polylang pack: AutoPoly's translation abilities.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Content\Get_Content;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Content\Get_Outdated_Translations;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Content\Get_Term;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Content\Get_Untranslated_Content;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Content\List_Content_Types;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Environment\Get_Site_Info;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Environment\Get_Translation_Settings;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Environment\Get_Translation_Plan;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Environment\Get_Translation_Status;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Environment\Preflight_Template;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Environment\Verify_Translations;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Glossary\Get_Glossary;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Glossary\Manage_Glossary;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Languages\Create_Language;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Languages\Delete_Language;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Languages\List_Languages;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Languages\Update_Language;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Menus\Set_Template_Menu;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Menus\Sync_Menu;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Translation\Get_String_Groups;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Translation\Manage_Translation_Links;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Translation\Translate_Post;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Translation\Translate_String;
use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Translation\Translate_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the pack to the framework and says when it may be offered.
 *
 * @since 1.1.0
 */
final class Pack {

	/**
	 * Option that switches the pack off when set to "no".
	 *
	 * Same option the pre-framework AutoPoly abilities used.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const OPTION_ENABLED = 'atfpp_abilities_enabled';

	/**
	 * Whether the site owner has left the pack on. Defaults to on.
	 *
	 * @since 1.1.0
	 * @return bool True when enabled.
	 */
	public static function is_enabled() {
		return 'no' !== get_option( self::OPTION_ENABLED, 'yes' );
	}

	/**
	 * Whether the pack can be offered in this request.
	 *
	 * Needs the pack switched on, AutoPoly Pro active (the tools call into it) and
	 * Polylang loaded. AutoPoly prints its own notice when Polylang is missing, so
	 * this stays silent about it.
	 *
	 * @since 1.1.0
	 * @return bool True when the pack's abilities may be registered.
	 */
	public static function is_available() {
		return self::is_enabled()
			&& defined( 'ATFPP_V' )
			&& function_exists( 'PLL' )
			&& function_exists( 'pll_default_language' );
	}

	/**
	 * The pack definition consumed by CPAF_Framework::packs().
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> Pack definition.
	 */
	public static function definition() {
		return array(
			'id'           => 'polylang',
			'label'        => __( 'AutoPoly (Polylang)', 'autopoly-ai-translation-for-polylang-pro' ),
			'description'  => __( 'Translate posts, terms, strings and menus, and manage languages and the glossary.', 'autopoly-ai-translation-for-polylang-pro' ),
			'namespace'    => Abilities\Abstract_Ability::ABILITY_NAMESPACE,
			'category'     => array(
				'slug'        => Abilities\Abstract_Ability::ABILITY_CATEGORY,
				'label'       => __( 'AutoPoly Translation', 'autopoly-ai-translation-for-polylang-pro' ),
				'description' => __( 'AI translation tools for Polylang, provided by AutoPoly.', 'autopoly-ai-translation-for-polylang-pro' ),
			),
			'is_available' => array( __CLASS__, 'is_available' ),
			'abilities'    => array(
				Get_Site_Info::class,
				Get_Translation_Status::class,
				Get_Translation_Plan::class,
				Verify_Translations::class,
				Get_Translation_Settings::class,
				Preflight_Template::class,
				List_Languages::class,
				Create_Language::class,
				Update_Language::class,
				Delete_Language::class,
				List_Content_Types::class,
				Get_Untranslated_Content::class,
				Get_Outdated_Translations::class,
				Get_Content::class,
				Get_Term::class,
				Translate_Post::class,
				Translate_Term::class,
				Translate_String::class,
				Manage_Translation_Links::class,
				Get_String_Groups::class,
				Get_Glossary::class,
				Manage_Glossary::class,
				Sync_Menu::class,
				Set_Template_Menu::class,
			),
		);
	}
}
