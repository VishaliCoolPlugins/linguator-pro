<?php
/**
 * Loads the site scan.
 *
 * @package Linguator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

require_once __DIR__ . '/result.php';
require_once __DIR__ . '/abstract-check.php';
require_once __DIR__ . '/link-tools.php';
require_once __DIR__ . '/elementor-fields.php';
require_once __DIR__ . '/http-probe.php';
require_once __DIR__ . '/toolkit-copy.php';
require_once __DIR__ . '/issue-copy.php';
require_once __DIR__ . '/freshness.php';
require_once __DIR__ . '/editor-panel.php';
require_once __DIR__ . '/untranslated-collector.php';
require_once __DIR__ . '/frontend-inspector.php';
require_once __DIR__ . '/scanner.php';
require_once __DIR__ . '/checks/languages.php';
require_once __DIR__ . '/checks/url-config.php';
require_once __DIR__ . '/checks/url-settings.php';
require_once __DIR__ . '/checks/url-slugs.php';
require_once __DIR__ . '/checks/url-numbered-copies.php';
require_once __DIR__ . '/checks/url-routing.php';
require_once __DIR__ . '/checks/url-permalinks.php';
require_once __DIR__ . '/checks/content.php';
require_once __DIR__ . '/checks/content-identical.php';
require_once __DIR__ . '/checks/content-links.php';
require_once __DIR__ . '/checks/content-placeholders.php';
require_once __DIR__ . '/checks/content-fields.php';
require_once __DIR__ . '/checks/content-terms.php';
require_once __DIR__ . '/checks/untranslated-types.php';
require_once __DIR__ . '/checks/menus.php';
require_once __DIR__ . '/checks/menu-links.php';
require_once __DIR__ . '/checks/navigation.php';
require_once __DIR__ . '/checks/strings.php';
require_once __DIR__ . '/checks/switcher.php';
require_once __DIR__ . '/checks/elementor.php';
require_once __DIR__ . '/checks/elementor-headers.php';
require_once __DIR__ . '/checks/elementor-loops.php';

\Linguator\Modules\Scan\Freshness::init();
\Linguator\Modules\Scan\Editor_Panel::init();
\Linguator\Modules\Scan\Frontend_Inspector::init();
