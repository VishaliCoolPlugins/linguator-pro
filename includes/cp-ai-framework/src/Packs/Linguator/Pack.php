<?php
/**
 * The Linguator pack: Linguator Pro's translation abilities.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Content\Get_Content;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Content\Get_Outdated_Translations;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Content\Get_Post_Translations;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Content\Get_Term;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Content\Get_Untranslated_Content;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Content\List_Content_Types;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Content\List_Posts;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Content\List_Terms;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Environment\Get_Ai_Settings;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Environment\Get_License_Status;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Environment\Get_Site_Health;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Environment\Get_Site_Info;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Environment\Get_Translation_Plan;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Environment\Get_Translation_Settings;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Environment\Get_Translation_Status;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Environment\Preflight_Template;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Environment\Verify_Translations;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Glossary\Get_Glossary;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Glossary\Manage_Glossary;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Languages\Create_Language;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Languages\Delete_Language;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Languages\List_Languages;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Languages\Update_Language;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Media\List_Untranslated_Media;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Media\Translate_Media;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Menus\List_Menus;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Menus\Manage_Menu_Locations;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Menus\Set_Template_Menu;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Menus\Sync_Menu;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Pages\Get_Static_Pages;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Pages\Set_Language_Front_Page;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Settings\Get_Switcher_Settings;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Settings\Manage_Custom_Fields;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Settings\Manage_Translated_Types;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Settings\Update_Settings;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Settings\Update_Switcher_Settings;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Translation\Copy_Post_To_Language;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Translation\Get_String_Groups;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Translation\Manage_Translation_Links;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Translation\Translate_Post;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Translation\Translate_String;
use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Translation\Translate_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the pack to the framework and says when it may be offered.
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
	const OPTION_ENABLED = 'lmat_abilities_enabled';

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
	 * Needs the pack switched on, Linguator Pro active (the tools call into it)
	 * and the free Linguator plugin loaded with its model.
	 *
	 * @since 1.2.0
	 * @return bool True when the pack's abilities may be registered.
	 */
	public static function is_available() {
		return self::is_enabled()
			&& defined( 'LINGUATOR_PRO_VERSION' )
			&& function_exists( 'LMAT' )
			&& function_exists( 'linguator_default_language' )
			&& is_object( LMAT() )
			&& isset( LMAT()->model );
	}

	/**
	 * The pack definition consumed by CPAF_Framework::packs().
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Pack definition.
	 */
	public static function definition() {
		return array(
			'id'           => 'linguator',
			'label'        => __( 'Linguator Pro', 'linguator-pro' ),
			'description'  => __( 'Translate posts, terms and strings, and inspect languages and translation coverage.', 'linguator-pro' ),
			'namespace'    => Abilities\Abstract_Ability::ABILITY_NAMESPACE,
			'category'     => array(
				'slug'        => Abilities\Abstract_Ability::ABILITY_CATEGORY,
				'label'       => __( 'Linguator Translation', 'linguator-pro' ),
				'description' => __( 'AI translation tools for Linguator, provided by Linguator Pro.', 'linguator-pro' ),
			),
			'is_available' => array( __CLASS__, 'is_available' ),
			'groups'       => array(
				array(
					'label' => __( 'Translate', 'linguator-pro' ),
					'tools' => array( 'translate-post', 'translate-term', 'translate-string', 'translate-media', 'copy-post-to-language' ),
				),
				array(
					'label' => __( 'Language Structure', 'linguator-pro' ),
					'tools' => array( 'list-languages', 'create-language', 'update-language', 'delete-language', 'manage-translation-links' ),
				),
				array(
					'label' => __( 'Menus', 'linguator-pro' ),
					'tools' => array( 'list-menus', 'sync-menu', 'set-template-menu', 'manage-menu-locations' ),
				),
				array(
					'label' => __( 'Strings & Glossary', 'linguator-pro' ),
					'tools' => array( 'get-string-groups', 'get-glossary', 'manage-glossary' ),
				),
				array(
					'label' => __( 'Content', 'linguator-pro' ),
					'tools' => array( 'list-content-types', 'get-untranslated-content', 'get-outdated-translations', 'get-content', 'get-term', 'list-posts', 'list-terms', 'get-post-translations', 'list-untranslated-media' ),
				),
				array(
					'label' => __( 'Pages & Settings', 'linguator-pro' ),
					'tools' => array( 'get-static-pages', 'set-language-front-page', 'update-settings', 'manage-translated-types', 'manage-custom-fields', 'get-switcher-settings', 'update-switcher-settings' ),
				),
				array(
					'label' => __( 'Site & Status', 'linguator-pro' ),
					'tools' => array( 'get-site-info', 'get-translation-status', 'get-translation-plan', 'verify-translations', 'get-translation-settings', 'preflight-template', 'get-site-health', 'get-ai-settings', 'get-license-status' ),
				),
			),
			'abilities'    => array(
				Get_Site_Info::class,
				Get_Translation_Status::class,
				Get_Translation_Settings::class,
				Get_Translation_Plan::class,
				Preflight_Template::class,
				Verify_Translations::class,

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
				List_Posts::class,
				List_Terms::class,
				Get_Post_Translations::class,
				List_Untranslated_Media::class,
				Translate_Media::class,
				Copy_Post_To_Language::class,
				Get_Site_Health::class,
				Get_Ai_Settings::class,
				Get_License_Status::class,
				Update_Settings::class,
				Manage_Translated_Types::class,
				Manage_Custom_Fields::class,
				Get_Switcher_Settings::class,
				Update_Switcher_Settings::class,
				List_Menus::class,
				Manage_Menu_Locations::class,
				Get_Static_Pages::class,
				Set_Language_Front_Page::class,
			),
		);
	}
}
