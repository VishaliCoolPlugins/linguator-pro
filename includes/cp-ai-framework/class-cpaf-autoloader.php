<?php
/**
 * Cool Plugins AI framework: PSR-4 autoloader.
 *
 * Only this file and the framework classes named in class-cpaf-framework.php are
 * required by hand; everything under src/ is autoloaded. PSR-4 needs the file
 * name to equal the class short name, which is why src/ does not use the
 * class-*.php convention.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CPAF_Autoloader' ) ) {

	/**
	 * Namespace-scoped autoloader for CoolPlugins\AiFramework\.
	 *
	 * @since 1.1.0
	 */
	final class CPAF_Autoloader {

		/**
		 * Namespace prefix owned by this autoloader.
		 *
		 * @since 1.1.0
		 * @var string
		 */
		const PREFIX = 'CoolPlugins\\AiFramework\\';

		/**
		 * Whether the autoloader is registered.
		 *
		 * @since 1.1.0
		 * @var bool
		 */
		private static $registered = false;

		/**
		 * Resolved path of the src folder, or false.
		 *
		 * @since 1.1.0
		 * @var string|false
		 */
		private static $base_dir = '';

		/**
		 * Registers the autoloader once, for the src folder of the running copy.
		 *
		 * @since 1.1.0
		 * @param string $dir Absolute path to the framework folder.
		 * @return void
		 */
		public static function register( $dir ) {
			if ( true === self::$registered ) {
				return;
			}

			self::$base_dir   = realpath( rtrim( (string) $dir, '/\\' ) . '/src' );
			self::$registered = true;

			spl_autoload_register( array( __CLASS__, 'load' ), true, false );
		}

		/**
		 * Loads the file for a framework class.
		 *
		 * @since 1.1.0
		 * @param string $class_name Fully qualified class name.
		 * @return void
		 */
		public static function load( $class_name ) {
			// Bail before touching the filesystem for foreign classes.
			if ( 0 !== strncmp( self::PREFIX, (string) $class_name, strlen( self::PREFIX ) ) ) {
				return;
			}

			if ( '' === self::$base_dir || false === self::$base_dir ) {
				return;
			}

			$relative = substr( (string) $class_name, strlen( self::PREFIX ) );
			$path     = realpath( self::$base_dir . '/' . str_replace( '\\', '/', $relative ) . '.php' );

			// A crafted class name must not escape src/.
			if ( false === $path || 0 !== strpos( $path, self::$base_dir ) ) {
				return;
			}

			require_once $path;
		}
	}
}
