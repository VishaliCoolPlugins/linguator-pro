<?php
/**
 * Translation settings read ability.
 *
 * @package AutoPoly
 * @since   1.11.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Environment;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Settings_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports the Polylang and AutoPoly settings that govern translation.
 *
 * @since 1.11.0
 */
final class Get_Translation_Settings extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-translation-settings';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Translation Settings', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: read the Polylang and AutoPoly settings that decide how translation behaves. Use it to explain why a translation turned out the way it did: which post types and taxonomies can be translated at all, which fields are synchronised between translations, how language appears in URLs, which post meta keys may be written, and what status new translations get. It also lists the navigation menus with the language each is assigned to, for use with sync-menu. Read only; nothing here can be changed through this tool.', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return $this->empty_object_schema();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed> Settings from both plugins, plus guidance.
	 */
	public static function execute( array $input = array() ) {
		$settings = Settings_Service::get_all();

		$settings['notice'] = __( 'Nothing here can be changed through this tool. "not_synchronized" lists what Polylang will not copy between translations, which is usually why a featured image, page template or custom field is missing from a translation. "slug_translation" is the rule translate-post and translate-term follow for URL slugs: title_translate (slug from the translated title), slug_translate (send a translated_slug) or slug_keep (the source slug is kept); you do not need to choose, the tools apply it. A post type absent from "translatable_post_types" cannot be translated at all. A meta key absent from "translatable_post_meta_keys" is rejected by translate-post and reported in its meta_skipped list. If "custom_translation_prompt" is present, it is the site owner\'s instruction to translators and should be followed. In "menus", "language" is the language whose theme locations show the menu (empty when it is assigned to none), "language_menus" maps other languages to the menus shown in the same locations, and "shared_with_languages" lists languages that show this same menu, which sync-menu will not overwrite. "elementor" lists the settings an Elementor header or footer needs (templates translatable and Connect Polylang for Elementor). Menu Sync is optional for template translation and is needed only when an Elementor Nav Menu widget must show a separate menu for its language. For one template, preflight-template adds its menu widgets.', 'autopoly-ai-translation-for-polylang-pro' );

		return $settings;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Administrator-only, matching get-site-info: this reports site
	 * configuration rather than content. Lower it per site with the
	 * atfpp_ability_required_capability filter if editors need it for
	 * diagnosis.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
