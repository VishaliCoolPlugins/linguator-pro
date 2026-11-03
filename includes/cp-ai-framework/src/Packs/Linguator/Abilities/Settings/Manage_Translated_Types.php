<?php
/**
 * Manage Translated Types ability.
 *
 * @package CoolPluginsAiFramework
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Settings;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Structure_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists, enables and disables the post types and taxonomies Linguator translates.
 *
 * @since 1.3.0
 */
final class Manage_Translated_Types extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'manage-translated-types';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Manage Translated Types', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: see and change which post types and taxonomies are translated. action "list" shows every public custom post type and taxonomy with how it is translated: "always" (posts, pages, categories, tags), "setting" (switched on by an administrator, can be switched off), "code" (forced on by a theme or plugin, cannot be switched off here), "media_setting" (media, controlled by media_support in update-settings) or "not_translated". action "enable" turns translation on for the named post_types and taxonomies; items of that type without a language receive the default language, like the settings screen does (up to 1000 per type per run). action "disable" is refused unless confirm is true: existing translations are NOT deleted, but the content stops being managed by Linguator (no language filtering, no translation links, no switcher). Tell the user that before confirming. Types forced by code and the built-in types cannot be disabled. This is a dry run by default; call again with dry_run false to save.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'     => array(
					'type'        => 'string',
					'enum'        => array( 'list', 'enable', 'disable' ),
					'default'     => 'list',
					'description' => __( 'list, enable or disable.', 'linguator-pro' ),
				),
				'post_types' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Post type slugs to enable or disable, for example ["product"].', 'linguator-pro' ),
				),
				'taxonomies' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Taxonomy slugs to enable or disable, for example ["product_cat"].', 'linguator-pro' ),
				),
				'confirm'    => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Required (true) to disable. Existing translations stay in the database but stop being managed.', 'linguator-pro' ),
				),
				'dry_run' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'When true, the default, report what would change and change nothing. Set to false to save.', 'linguator-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		$action = isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : 'list';

		if ( 'list' === $action ) {
			return Structure_Service::list_types();
		}

		// Defaults to a dry run; only an explicit false writes.
		$dry_run = isset( $input['dry_run'] ) ? rest_sanitize_boolean( $input['dry_run'] ) : true;

		$post_types = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? $input['post_types'] : array();
		$taxonomies = isset( $input['taxonomies'] ) && is_array( $input['taxonomies'] ) ? $input['taxonomies'] : array();
		$confirm    = isset( $input['confirm'] ) && rest_sanitize_boolean( $input['confirm'] );

		return Structure_Service::change_types( $action, $post_types, $taxonomies, $confirm, $dry_run );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
