<?php
/**
 * Loads the integration with cache plugins.
 *
 * @package Linguator
 */
namespace LinguatorPro\Integrations\cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}
use LinguatorPro\Integrations\cache\Linguator_Cache_Compat;
use LinguatorPro\Integrations\Linguator_Integrations;


add_action(
	'plugins_loaded',
	function () {
		if ( linguator_pro_is_cache_active() ) {
			add_action( 'lmat_init', array( Linguator_Integrations::instance()->cache_compat = new Linguator_Cache_Compat(), 'init' ) );
		}
	},
	0
);
