<?php
/**
 * The WPML pack: AutoMLP's translation abilities.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content\Audit_Translation_Integrity;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content\Get_Content;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content\Get_Editor_Type;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content\Get_Untranslated_Content;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content\List_Content_Types;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content\List_Posts;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content\List_Terms;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content\Rebuild_Elementor_Data;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content\Repair_Elementor_Media;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Environment\Get_Site_Info;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Environment\Get_Translation_Settings;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Environment\Get_Translation_Plan;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Environment\Get_Translation_Status;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Environment\Verify_Translations;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Languages\List_Languages;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Strings\Get_String_Groups;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Strings\Get_Strings;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Strings\Translate_Strings;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Translation\Ensure_Translation;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Translation\Fix_Translated_Post_Url;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Translation\Get_Element_Language_Details;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Translation\Get_Post_Translations;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Translation\Get_Term_Translations;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Translation\Manage_Translation_Links;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Translation\Translate_Post;
use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Translation\Translate_Term;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;

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
	 * Same option the pre-framework AutoMLP abilities used.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const OPTION_ENABLED = 'automlp_abilities_enabled';

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
	 * Needs the pack switched on, AutoMLP Pro active (the tools call into it),
	 * and WPML core plus String Translation with a finished setup. AutoMLP prints
	 * its own notices for missing WPML pieces, so this stays silent about them.
	 *
	 * @since 1.2.0
	 * @return bool True when the pack's abilities may be registered.
	 */
	public static function is_available() {
		return self::is_enabled()
			&& defined( 'AUTOMLP_AI_PRO_VERSION' )
			&& Wpml_Guard::ready();
	}

	/**
	 * The pack definition consumed by CPAF_Framework::packs().
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Pack definition.
	 */
	public static function definition() {
		return array(
			'id'           => 'wpml',
			'label'        => __( 'AutoMLP (WPML)', 'wpml-translation-check-pro' ),
			'description'  => __( 'Translate and manage WPML posts, terms, strings, URLs, and Elementor content.', 'wpml-translation-check-pro' ),
			'namespace'    => Abilities\Abstract_Ability::ABILITY_NAMESPACE,
			'category'     => array(
				'slug'        => Abilities\Abstract_Ability::ABILITY_CATEGORY,
				'label'       => __( 'AutoMLP Translation', 'wpml-translation-check-pro' ),
				'description' => __( 'AI translation tools for WPML, provided by AutoMLP.', 'wpml-translation-check-pro' ),
			),
			'is_available' => array( __CLASS__, 'is_available' ),
			'abilities'    => array(
				Get_Site_Info::class,
				Get_Translation_Settings::class,
				Get_Translation_Status::class,
				Get_Translation_Plan::class,
				Verify_Translations::class,
				List_Languages::class,
				List_Content_Types::class,
				List_Posts::class,
				List_Terms::class,
				Get_Untranslated_Content::class,
				Get_Content::class,
				Get_Editor_Type::class,
				Audit_Translation_Integrity::class,
				Get_Post_Translations::class,
				Get_Element_Language_Details::class,
				Get_Term_Translations::class,
				Get_String_Groups::class,
				Get_Strings::class,
				Translate_Post::class,
				Translate_Term::class,
				Ensure_Translation::class,
				Manage_Translation_Links::class,
				Translate_Strings::class,
				Fix_Translated_Post_Url::class,
				Repair_Elementor_Media::class,
				Rebuild_Elementor_Data::class,
			),
		);
	}
}
