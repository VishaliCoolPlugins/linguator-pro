<?php
/**
 * Site info ability.
 *
 * @package LocoAI
 * @since   2.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Loco\Abilities\Environment;

use CoolPlugins\AiFramework\Packs\Loco\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Loco\Services\Loco_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports the WordPress, Loco Translate and LocoAI configuration.
 *
 * @since 2.3.0
 */
final class Get_Site_Info extends Abstract_Ability {

	/**
	 * Longest owner context returned, in characters.
	 *
	 * @since 2.3.0
	 * @var int
	 */
	const MAX_CONTEXT_LENGTH = 2000;

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-site-info';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Site Info', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'LocoAI: call this first. Reports the site language and installed languages, the Loco Translate version and the settings that decide whether translation files can be written, and the site owner\'s own translation instructions ("owner_context", from LocoAI settings), which outrank anything you infer. These tools translate the fixed interface text of plugins, themes and WordPress core through Loco Translate language files (PO/MO), not posts or pages. Typical flow: list-projects, get-project-status, create-language-file if the language has no file yet, get-strings, then save-translations.', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return $this->empty_object_schema();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|\WP_Error Site information.
	 */
	public static function execute( $input = [] ) {
		if ( ! Loco_Guard::is_available() ) {
			return Loco_Guard::unavailable_error();
		}

		$settings   = \Loco_data_Settings::get();
		$custom_dir = (string) loco_constant( 'LOCO_LANG_DIR' );
		$installed  = get_available_languages();
		$licensed   = class_exists( 'ProHelpers' ) && \ProHelpers::hasValidLicense();

		array_unshift( $installed, 'en_US' );

		return [
			'wordpress'      => [
				'version'             => get_bloginfo( 'version' ),
				'site_name'           => get_bloginfo( 'name' ),
				'site_url'            => home_url(),
				'site_locale'         => get_locale(),
				'installed_languages' => array_values( array_unique( $installed ) ),
			],
			'loco_translate' => [
				'version'                    => (string) loco_plugin_version(),
				'custom_location_enabled'    => '' !== $custom_dir,
				'custom_location_writable'   => '' !== $custom_dir && self::directory_writable( $custom_dir ),
				'file_modifications_allowed' => ! function_exists( 'wp_is_file_mod_allowed' ) || wp_is_file_mod_allowed( 'download_language_pack' ),
				'system_files_protected'     => 1 < (int) $settings->fs_protect,
				'templates_protected'        => 1 < (int) $settings->pot_protect,
				'template_required'          => 2 <= (int) $settings->pot_expected,
				'backups_kept'               => (int) $settings->num_backups,
			],
			'locoai'         => [
				'version'        => defined( 'ATLT_PRO_VERSION' ) ? ATLT_PRO_VERSION : '',
				'license_active' => $licensed,
				'owner_context'  => $licensed ? self::owner_context() : '',
			],
		];
	}

	/**
	 * Whether a directory, or its nearest existing parent, is writable.
	 *
	 * @since 2.3.0
	 * @param string $directory Absolute directory path.
	 * @return bool True when files can be created there.
	 */
	private static function directory_writable( $directory ) {
		while ( '' !== $directory && ! is_dir( $directory ) ) {
			$parent = dirname( $directory );

			if ( $parent === $directory ) {
				return false;
			}

			$directory = $parent;
		}

		return wp_is_writable( $directory );
	}

	/**
	 * The owner's translation instructions from LocoAI settings.
	 *
	 * @since 2.3.0
	 * @return string Plain text, length-capped.
	 */
	private static function owner_context() {
		$context = trim( sanitize_textarea_field( (string) get_option( 'atlt_context_aware', '' ) ) );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $context, 0, self::MAX_CONTEXT_LENGTH );
		}

		return substr( $context, 0, self::MAX_CONTEXT_LENGTH );
	}
}
