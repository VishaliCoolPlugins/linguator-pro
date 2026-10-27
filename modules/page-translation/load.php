<?php
/**
 * Loads the setup wizard.
 *
 * @package Linguator
 */
namespace LinguatorPro\Modules\Page_Translation;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly
}

if ( $linguator->model->has_languages() ) {
    class_exists(Linguator_Page_Translation::class) && new Linguator_Page_Translation($linguator);
}
