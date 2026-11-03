<?php
/**
 * Navigation menu sync ability.
 *
 * @package CoolPluginsAiFramework
 * @since 1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Menus;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Menu_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copies a menu's structure into other languages with Linguator's Menu Synchronization.
 *
 * @since 1.2.0
 */
final class Sync_Menu extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'sync-menu';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Sync Menu', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: copy a navigation menu into other languages, using the same code as the Menu Synchronization button in Appearance > Menus. Each page, post or term item is pointed at its published translation and takes the translated title; items with no published translation are left out. A custom or hand-edited label takes its glossary translation when the glossary has one (see manage-glossary); when machine translation is switched on in Linguator\'s AI translation settings, other labels are sent to Google Translate by Linguator itself, and otherwise they stay in the source language (the dry run reports each label with its label_source). Custom links are copied unchanged: unlike some tools, Linguator does not repoint a custom link at the translation of the page it leads to, so compare custom_links_kept in the dry run and tell the user about any that still lead to the source language. The target language\'s menu is REPLACED: Linguator uses the menu named "Menu name (Language)" or the one already assigned to the same location for that language, deletes its items and rebuilds them, and assigns it to the same theme locations as the source, so labels edited by hand in the target menu are lost. This is a dry run by default: it reports which menu would be overwritten or created, which items would be skipped, which labels would be translated or left unchanged and which existing items would be deleted, and changes nothing. Review that, then call again with dry_run false to apply. After applying, if Elementor uses this menu, check elementor_header and call set-template-menu when requested. Menu IDs and their languages are listed under available_menus in get-translation-plan. Requires Menu Synchronization to be switched on in Linguator settings.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'menu_id'          => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the source menu, normally the one in the default language. See available_menus in get-translation-plan.', 'linguator-pro' ),
				),
				'target_languages' => array(
					// String first; see the note on Get_Glossary::target_langs.
					'type'        => array( 'string', 'array' ),
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Language slugs to sync into, for example ["hi","fr"]. Omit to sync into every language except the menu\'s own. Required when the menu is not assigned to a location for any language, because its own language is then unknown.', 'linguator-pro' ),
				),
				'dry_run'          => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'When true, the default, report what would change and change nothing. Set to false to replace the target menus.', 'linguator-pro' ),
				),
			),
			'required'   => array( 'menu_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
				$result['notice'] = __( 'Dry run: nothing was changed. For each language, "target_menu" says whether an existing menu is overwritten or a new one created, "items_replaced" lists the existing items that would be deleted, "items_skipped" the source items left out for lack of a published translation, "moved_to_top_level" the items whose parent is left out, "labels_translated" the custom labels that take a glossary translation or would be machine translated, "labels_unchanged" the labels copied as they are, in the source language (add a glossary entry with manage-glossary first if they need translating), "custom_links_kept" the custom links that are copied unchanged, and "labels" the label each item will get with its label_source (page_title, term_name, glossary, machine_translation or left_in_source). Languages in "refused" are not synced at all. Call again with dry_run false to apply.', 'linguator-pro' );
			}

			return $result;
		}

		$result = Menu_Service::sync( $menu_id, $languages );

		if ( ! is_wp_error( $result ) ) {
			$result['notice'] = __( 'The menus were synced and assigned to the same theme locations as the source. Custom labels with a glossary entry were translated, custom labels may have been machine translated when that is switched on in Linguator, and custom links were copied unchanged: a dry run lists them under custom_links_kept, so check that none still leads to the source language. Rather than editing a synced menu by hand, add glossary entries with manage-glossary and sync again, since the next sync rebuilds it. "labels" shows where each label came from (label_source). On a site with Elementor, "elementor_header" says whether the header or footer of each language will actually show this menu: Elementor does not switch menus by language, so when its status is needs_set_template_menu, call set-template-menu. Elementor\'s cache was cleared so an old copy of the menu is not served.', 'linguator-pro' );
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * edit_theme_options, matching Linguator's own menu sync AJAX handler and
	 * WordPress's menu screens.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'edit_theme_options', $input );
	}
}
