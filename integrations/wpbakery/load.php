<?php
/**
 * Loads the integration with WPBakery Page Builder.
 *
 * @package Linguator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

// Load WPBakery Page Builder compatibility
if ( linguator_pro_is_plugin_active( 'js_composer/js_composer.php' ) ) {
	require_once __DIR__ . '/wpbakery.php';
	new LinguatorPro\Integrations\wpbakery\Linguator_WPBakery();
}

