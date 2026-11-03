<?php
/**
 * Update Settings ability.
 *
 * @package CoolPluginsAiFramework
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Settings;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Settings_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Changes a short list of safe Linguator settings, validated by Linguator's own option classes.
 *
 * @since 1.3.0
 */
final class Update_Settings extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'update-settings';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Update Settings', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: change general Linguator settings. Supported keys: hide_default (hide the language code in default-language URLs), redirect_lang (front page URL uses the language code instead of the page name), browser (detect the visitor\'s browser language on the front page), media_support (translate media), menu_sync_visibility (show Menu Synchronization), sync (list of data kept in sync between translations: taxonomies, post_meta, comment_status, ping_status, sticky_posts, post_date, post_format, post_parent, _wp_page_template, menu_order, _thumbnail_id). Send only the keys to change. Every value is validated by Linguator\'s own option class; a value Linguator would adjust (for example hide_default while languages use domains) is rejected rather than silently altered. hide_default and redirect_lang change public URLs: say so to the user. REFUSED, with a message naming the right place: force_lang, domains and rewrite (URL structure), default_lang, post_types and taxonomies (use manage-translated-types), nav_menus (use manage-menu-locations), the language switcher list (use update-switcher-settings), api_keys, ai_translation_configuration and lmat_feedback_data (secrets and consent). This is a dry run by default and returns the before and after of every key; call again with dry_run false to save, and the response then includes the values read back from the database.', 'linguator-pro' );
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
				'hide_default'         => array(
					'type'        => 'boolean',
					'description' => __( 'Hide the language code in URLs of the default language. Changes public URLs.', 'linguator-pro' ),
				),
				'redirect_lang'        => array(
					'type'        => 'boolean',
					'description' => __( 'Remove the page name from the front page URL (it then carries the language code). Changes public URLs.', 'linguator-pro' ),
				),
				'browser'              => array(
					'type'        => 'boolean',
					'description' => __( 'Detect the visitor\'s preferred browser language on the front page.', 'linguator-pro' ),
				),
				'media_support'        => array(
					'type'        => 'boolean',
					'description' => __( 'Translate the media library. Switching on gives media with no language the default language.', 'linguator-pro' ),
				),
				'menu_sync_visibility' => array(
					'type'        => 'boolean',
					'description' => __( 'Show the Menu Synchronization feature (needed by sync-menu).', 'linguator-pro' ),
				),
				'sync'                 => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Data kept in sync between translations. Allowed: taxonomies, post_meta, comment_status, ping_status, sticky_posts, post_date, post_format, post_parent, _wp_page_template, menu_order, _thumbnail_id. An empty list turns synchronization off.', 'linguator-pro' ),
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
				'destructive' => false,
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
		// Defaults to a dry run; only an explicit false writes.
		$dry_run = isset( $input['dry_run'] ) ? rest_sanitize_boolean( $input['dry_run'] ) : true;

		unset( $input['dry_run'] );

		return Settings_Service::update_options( $input, $dry_run );
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
