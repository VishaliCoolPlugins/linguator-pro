<?php
/**
 * @package Linguator
 */

namespace LinguatorPro\Includes\Switcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the switcher's frontend CSS and JS dependencies.
 */
class Assets {
	public const FRONTEND_ASSET_HANDLE = 'lmat-language-switcher';

	/**
	 * Enqueues frontend CSS.
	 *
	 * @return void
	 */
	public static function enqueue_frontend_styles(): void {
		self::register_styles();
		wp_enqueue_style( self::FRONTEND_ASSET_HANDLE );
	}

	/**
	 * Enqueues frontend JS. Called on the fly by layouts that need it.
	 *
	 * @return void
	 */
	public static function enqueue_frontend_scripts(): void {
		self::register_scripts();
		wp_enqueue_script( self::FRONTEND_ASSET_HANDLE );
	}

	/**
	 * Registers frontend CSS.
	 *
	 * @return void
	 */
	public static function register_styles(): void {
		if ( wp_style_is( self::FRONTEND_ASSET_HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_style( self::FRONTEND_ASSET_HANDLE, self::get_build_url( 'css', 'language-switcher' ), array(), LINGUATOR_PRO_VERSION );
	}

	/**
	 * Registers frontend JS.
	 *
	 * @return void
	 */
	public static function register_scripts(): void {
		if ( wp_script_is( self::FRONTEND_ASSET_HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_script( self::FRONTEND_ASSET_HANDLE, self::get_build_url( 'js', 'language-switcher' ), array(), LINGUATOR_PRO_VERSION, array( 'in_footer' => true ) );
	}

	/**
	 * Returns the URL of a built asset, minified unless SCRIPT_DEBUG is on.
	 *
	 * @param string $type Asset type, 'css' or 'js'.
	 * @param string $name Asset base name.
	 * @return string
	 */
	private static function get_build_url( string $type, string $name ): string {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		return plugins_url( "admin/assets/{$type}/build/{$name}{$suffix}.{$type}", LINGUATOR_PRO_ROOT_FILE );
	}
}
