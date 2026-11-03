<?php
/**
 * Manage Custom Fields ability.
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
 * Lists custom fields and switches them on or off for translation.
 *
 * @since 1.3.0
 */
final class Manage_Custom_Fields extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'manage-custom-fields';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Manage Custom Fields', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: manage the Custom Fields allowlist, the post meta keys Linguator may translate (the Custom Fields tab in Linguator settings). action "list" returns every field Linguator knows, from the site\'s data, the default rules and integrations, with its type and whether it is enabled. "enable" and "disable" switch the named meta_keys. Only keys that appear in the list can be switched; an unknown key is refused (call list first). Enabled fields are offered to translate-post in translated_meta and to the translation screens. WooCommerce product attributes (_product_attributes) appear when WooCommerce is active and are never enabled by default. Disabling never deletes a stored translation. This is a dry run by default; call again with dry_run false to save, and the response includes the state read back.', 'linguator-pro' );
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
				'action'    => array(
					'type'        => 'string',
					'enum'        => array( 'list', 'enable', 'disable' ),
					'default'     => 'list',
					'description' => __( 'list, enable or disable.', 'linguator-pro' ),
				),
				'meta_keys' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Meta keys to enable or disable. Each must appear in the list.', 'linguator-pro' ),
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
		$action = isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : 'list';
		// Defaults to a dry run; only an explicit false writes.
		$dry_run = isset( $input['dry_run'] ) ? rest_sanitize_boolean( $input['dry_run'] ) : true;

		$keys = isset( $input['meta_keys'] ) && is_array( $input['meta_keys'] ) ? $input['meta_keys'] : array();

		return Structure_Service::custom_fields( $action, $keys, $dry_run );
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
