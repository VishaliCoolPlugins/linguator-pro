<?php
/**
 * Exception used to recover from a legacy JSON exit.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Services;

use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown in place of wp_die() so a guarded call can be recovered from.
 *
 * Lives in its own file because the PSR-4 autoloader resolves one class per
 * file: declaring it alongside Json_Exit_Guard would make it unloadable on its
 * own, and it would only ever exist by the accident of that file having been
 * loaded first.
 *
 * @since 1.5.0
 */
final class Json_Exit_Exception extends Exception {}
