<?php
/**
 * Navigation menu sync ability.
 *
 * @package AutoPoly
 * @since   1.11.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Menus;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Menu_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copies a menu's structure into other languages with AutoPoly's Menu Sync.
 *
 * @since 1.11.0
 */
final class Sync_Menu extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'sync-menu';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Sync Menu', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: copy a navigation menu into other languages, using the same code as the Menu Synchronization button in Appearance > Menus. Each page, post or term item is pointed at its published translation and takes the translated title; items with no published translation are left out. Translate every requested destination page first, then use the dry run to confirm that no required item is skipped and that every custom link is remapped or intentionally remains in the source language. A custom or hand-edited label takes its Polylang string translation when one has been set with translate-string, and otherwise stays in the source language; a custom link to a page on this site is pointed at that page\'s translation, and other custom links are copied unchanged. That includes a custom link whose address is not itself a page, such as one an SEO or redirection plugin redirects to a page, a post type archive or a custom route: it keeps leading to the source language, so compare the custom links in the dry run with links_remapped and tell the user about any that were not remapped. The target language\'s menu is REPLACED: its existing items are deleted and rebuilt, and it is assigned to the same theme locations as the source, so labels edited by hand in the target menu are lost; translated labels are rebuilt from their string translations every time. This is a dry run by default: it reports which menu would be overwritten or created, which items would be skipped, which labels would be translated or left unchanged, which links would be remapped and which existing items would be deleted, and changes nothing. Review that, then call again with dry_run false to apply. After applying, if Elementor uses this menu, check elementor_header and call set-template-menu when requested. Menu IDs and their languages are listed under polylang.menus in get-translation-settings. Requires AutoPoly\'s Menu Sync setting to be on.', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'menu_id'          => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the source menu, normally the one in the default language. See polylang.menus in get-translation-settings.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'target_languages' => array(
					// String first; see the note on Get_Glossary::target_langs.
					'type'        => array( 'string', 'array' ),
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Language slugs to sync into, for example ["hi","fr"]. Omit to sync into every language except the menu\'s own. Required when the menu is not assigned to a location for any language, because its own language is then unknown.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'dry_run'          => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'When true, the default, report what would change and change nothing. Set to false to replace the target menus.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
			),
			'required'   => array( 'menu_id' ),
		);
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
	 * Destructive: a real run deletes every item in each target menu before
	 * rebuilding it. Not idempotent either, because a repeat run recreates the
	 * items under new IDs.
	 *
	 * @since 1.11.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Preview or result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		$menu_id   = isset( $input['menu_id'] ) ? (int) $input['menu_id'] : 0;
		$languages = self::normalize_list( isset( $input['target_languages'] ) ? $input['target_languages'] : array() );

		// Defaults to a dry run; only an explicit false writes.
		$dry_run = isset( $input['dry_run'] ) ? rest_sanitize_boolean( $input['dry_run'] ) : true;

		if ( $dry_run ) {
			$result = Menu_Service::preview( $menu_id, $languages );

			if ( ! is_wp_error( $result ) ) {
				$result['notice'] = __( 'Dry run: nothing was changed. For each language, "target_menu" says whether an existing menu is overwritten or a new one created, "items_replaced" lists the existing items that would be deleted, "items_skipped" the source items left out for lack of a published translation, "moved_to_top_level" the items whose parent is left out, "labels_translated" the custom labels that take their string translation, "labels_unchanged" the labels copied as they are, in the source language (translate them with translate-string first if they need it), and "links_remapped" the custom links pointed at translated pages, and "labels" the label each item will get with its label_source (page_title, term_name, string_translation or left_in_source). Languages in "refused" are not synced at all. Call again with dry_run false to apply.', 'autopoly-ai-translation-for-polylang-pro' );
			}

			return $result;
		}

		$result = Menu_Service::sync( $menu_id, $languages );

		if ( ! is_wp_error( $result ) ) {
			$result['notice'] = __( 'The menus were synced and assigned to the same theme locations as the source. Custom labels with a string translation were translated, and custom links to translated pages were remapped. Labels with no string translation were copied in the source language: a dry run lists them under labels_unchanged. Translate them with translate-string and sync again rather than editing the synced menu by hand, since the next sync rebuilds it. "labels" shows where each label came from (label_source). On a site with Elementor, "elementor_header" says whether the header or footer of each language will actually show this menu: Elementor does not switch menus by language, so when its status is needs_set_template_menu, call set-template-menu. Elementor\'s cache was cleared so an old copy of the menu is not served.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * edit_theme_options, matching AutoPoly's own menu sync AJAX handler and
	 * WordPress's menu screens.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'edit_theme_options', $input );
	}
}
