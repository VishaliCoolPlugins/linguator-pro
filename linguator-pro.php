<?php
/**
 * Plugin Name:       Linguator Pro
 * Plugin URI:        https://linguator.com/
 * Description:       Pro add-on for Linguator – adds the Site Scan and the license manager. Requires the free Linguator plugin.
 * Version:           2.2.1
 * Requires at least: 6.8
 * Requires PHP:      7.2
 * Requires Plugins:  translate-words
 * Author:            Cool Plugins
 * Author URI:        https://coolplugins.net/?utm_source=twlmat_plugin&utm_medium=inside&utm_campaign=author_page&utm_content=plugins_list
 * Text Domain:       linguator-pro
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.txt
 *
 * --- CREDITS & COPYRIGHT NOTICE ---
 * This plugin is an add-on for the free Linguator plugin, which is a derivative work (fork) of
 * the free version of Polylang, originally developed by WP SYNTEX (https://wordpress.org/plugins/polylang).
 * Original Copyright 2011-2019 Frédéric Demarle
 * Original Copyright 2019-2026 WP SYNTEX
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 or later.
 * ----------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'LINGUATOR_PRO_VERSION' ) ) {
	define( 'LINGUATOR_PRO_VERSION', '2.2.1' );
}
if ( ! defined( 'LINGUATOR_PRO_FILE' ) ) {
	define( 'LINGUATOR_PRO_FILE', __FILE__ );
}
if ( ! defined( 'LINGUATOR_PRO_ROOT_FILE' ) ) {
	define( 'LINGUATOR_PRO_ROOT_FILE', __FILE__ );
}
if ( ! defined( 'LINGUATOR_PRO_DIR' ) ) {
	define( 'LINGUATOR_PRO_DIR', __DIR__ );
}
if ( ! defined( 'LINGUATOR_PRO_URL' ) ) {
	define( 'LINGUATOR_PRO_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'LINGUATOR_PRO_BASENAME' ) ) {
	define( 'LINGUATOR_PRO_BASENAME', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'LINGUATOR_PRO' ) ) {
	define( 'LINGUATOR_PRO', ucwords( str_replace( '-', ' ', dirname( LINGUATOR_PRO_BASENAME ) ) ) );
}
if ( ! defined( 'LINGUATOR_PRO_FREE_PLUGIN_BASE' ) ) {
	define( 'LINGUATOR_PRO_FREE_PLUGIN_BASE', 'translate-words/translate-wp-words.php' );
}
if ( ! defined( 'LINGUATOR_PRO_FREE_PLUGIN_SLUG' ) ) {
	define( 'LINGUATOR_PRO_FREE_PLUGIN_SLUG', 'translate-words' );
}
// Oldest free Linguator release that offers the hooks Pro relies on.
if ( ! defined( 'LINGUATOR_PRO_MIN_FREE_VERSION' ) ) {
	define( 'LINGUATOR_PRO_MIN_FREE_VERSION', '2.2.1' );
}

require_once __DIR__ . '/includes/class-linguator-pro.php';

\LinguatorPro\Includes\Linguator_Pro::get_instance()->init();
