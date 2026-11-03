<?php
/**
 * Loads the WooCommerce integration.
 *
 * @package LinguatorPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

// Nothing to do on a site without WooCommerce.
if ( ! class_exists( 'WooCommerce' ) ) {
	return;
}

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/product-sync.php';
require_once __DIR__ . '/attribute-labels.php';
require_once __DIR__ . '/attribute-texts.php';
require_once __DIR__ . '/attribute-form.php';

\LinguatorPro\Modules\WooCommerce\Attribute_Labels::init();
\LinguatorPro\Modules\WooCommerce\Registry::init();
\LinguatorPro\Modules\WooCommerce\Product_Sync::init();
\LinguatorPro\Modules\WooCommerce\Attribute_Texts::init();
\LinguatorPro\Modules\WooCommerce\Attribute_Form::init();
