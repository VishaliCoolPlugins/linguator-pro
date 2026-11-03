<?php
/**
 * Cool Plugins AI framework: versioned loader.
 *
 * Every translation plugin that uses the framework ships an identical copy of
 * this folder and calls cpaf_register() as early as it can. On plugins_loaded
 * (priority 1) the copy with the highest version is required and booted; the
 * others are ignored. Registration order never decides the winner.
 *
 * @package CoolPluginsAiFramework
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'cpaf_register' ) ) {

	/**
	 * Registers one plugin's copy of the framework.
	 *
	 * @since 1.0.0
	 * @param array $args {
	 *     Registration data.
	 *
	 *     @type string $dir         Absolute path to this framework folder.
	 *     @type string $text_domain Text domain of the registering plugin.
	 *     @type string $domain_path Absolute path to that plugin's languages folder.
	 * }
	 * @return void
	 */
	function cpaf_register( array $args ) {
		global $cpaf_registry;

		if ( ! is_array( $cpaf_registry ) ) {
			$cpaf_registry = array();
		}

		$dir = isset( $args['dir'] ) ? untrailingslashit( (string) $args['dir'] ) : '';

		if ( '' === $dir || ! is_readable( $dir . '/version.php' ) ) {
			return;
		}

		$cpaf_registry[] = array(
			'version'     => (string) require $dir . '/version.php',
			'dir'         => $dir,
			'text_domain' => isset( $args['text_domain'] ) ? (string) $args['text_domain'] : '',
			'domain_path' => isset( $args['domain_path'] ) ? untrailingslashit( (string) $args['domain_path'] ) : '',
		);

		if ( ! has_action( 'plugins_loaded', 'cpaf_boot' ) ) {
			add_action( 'plugins_loaded', 'cpaf_boot', 1, 0 );
		}

		// Registered after plugins_loaded already ran: boot now, best effort.
		if ( did_action( 'plugins_loaded' ) ) {
			cpaf_boot();
		}
	}
}

if ( ! function_exists( 'cpaf_boot' ) ) {

	/**
	 * Loads the highest-version copy once and starts it.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function cpaf_boot() {
		global $cpaf_registry;

		if ( class_exists( 'CPAF_Framework', false ) || empty( $cpaf_registry ) || ! is_array( $cpaf_registry ) ) {
			return;
		}

		// Stable sort: on equal versions the earlier registration keeps its place.
		$indexed = array_values( $cpaf_registry );

		foreach ( $indexed as $position => $entry ) {
			$indexed[ $position ]['position'] = $position;
		}

		usort(
			$indexed,
			function ( $a, $b ) {
				$by_version = version_compare( $b['version'], $a['version'] );

				return 0 !== $by_version ? $by_version : $a['position'] - $b['position'];
			}
		);

		$winner = $indexed[0];
		$file   = $winner['dir'] . '/class-cpaf-framework.php';

		if ( ! is_readable( $file ) ) {
			return;
		}

		require_once $file;

		if ( class_exists( 'CPAF_Framework', false ) ) {
			CPAF_Framework::boot( $winner, $indexed );
		}
	}
}
