<?php
/**
 * Elementor template menu ability.
 *
 * @package AutoPoly
 * @since   1.14.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Menus;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Template_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points the Nav Menu widget of an Elementor template at a menu.
 *
 * @since 1.14.0
 */
final class Set_Template_Menu extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.14.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'set-template-menu';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.14.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Set Template Menu', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.14.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: change which navigation menu the Elementor Nav Menu widget of a template shows. Use it on the translated header or footer after sync-menu has built the translated menu: Elementor does not switch menus by language, so the translated header keeps showing the source menu until its widget is changed. Pass the translated template as template_id and the menu from sync-menu as menu_id. The menu must belong to the template\'s language (or to no language). If the template has more than one Nav Menu widget, pass widget_id; the error lists them. Nothing else in the template changes. Elementor\'s cache is cleared afterwards, because it otherwise keeps serving the old menu. Check the public page afterwards, for example with translate-post and verify_public.', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.14.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'template_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the translated Elementor template (header or footer) to change.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'menu_id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the menu the widget should show, for example the menu_id sync-menu returned for the language.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'widget_id'   => array(
					'type'        => 'string',
					'description' => __( 'The Nav Menu widget to change. Needed only when the template has more than one.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
			),
			'required'   => array( 'template_id', 'menu_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.14.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.14.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.14.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		return Template_Service::set_menu(
			isset( $input['template_id'] ) ? absint( $input['template_id'] ) : 0,
			isset( $input['menu_id'] ) ? absint( $input['menu_id'] ) : 0,
			isset( $input['widget_id'] ) ? (string) $input['widget_id'] : ''
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Needs edit rights on the template and edit_theme_options, as the menu
	 * screens and sync-menu do, because it changes what the site navigation shows.
	 *
	 * @since 1.14.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		$template_id = isset( $input['template_id'] ) ? absint( $input['template_id'] ) : 0;

		if ( 0 === $template_id ) {
			return false;
		}

		return self::user_can( 'edit_post', $input, $template_id ) && self::user_can( 'edit_theme_options', $input );
	}
}
