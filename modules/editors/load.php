<?php
/**
 * @package Linguator
 */

use LinguatorPro\Modules\Editors\Screens\Post;
use LinguatorPro\Modules\Editors\Screens\Site;
use LinguatorPro\Modules\Editors\Screens\Widget;
use LinguatorPro\Modules\Editors\Filter_Preload_Paths;
use LinguatorPro\Admin\Controllers\Linguator_Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

add_action(
	'lmat_init',
	function ( $linguator ) {
		if (
			$linguator->model->languages->has()
			&& $linguator instanceof Linguator_Admin
			&& linguator_pro_use_block_editor_plugin()
		) {
			$linguator->site_editor   = ( new Site( $linguator ) )->init();
			$linguator->post_editor   = ( new Post( $linguator ) )->init();
			$linguator->widget_editor = ( new Widget( $linguator ) )->init();
			$linguator->filter_path   = ( new Filter_Preload_Paths( $linguator ) )->init();
		}
	}
);
