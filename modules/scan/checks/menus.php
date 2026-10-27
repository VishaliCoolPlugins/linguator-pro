<?php
/**
 * Navigation menu checks.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Modules\Scan\Abstract_Check;
use LinguatorPro\Modules\Scan\Result;

/**
 * Every theme menu location needs a menu assigned in every language.
 *
 * @since 2.3.0
 */
class Menus extends Abstract_Check {

	/**
	 * @var string
	 */
	protected $group = 'Menus';

	/**
	 * Runs the check.
	 *
	 * @since 2.3.0
	 *
	 * @return Result[]
	 */
	public function run(): array {
		$languages = $this->active_languages();

		// Block themes manage navigation in the Site Editor, not in menu locations.
		if ( count( $languages ) < 2 || ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ) {
			return array();
		}

		$locations = get_registered_nav_menus();
		if ( empty( $locations ) ) {
			return array(
				$this->result(
					'menu_none',
					Result::PASS,
					__( 'No menu locations to check', 'linguator-pro' ),
					__( 'Your theme registers no navigation menu locations.', 'linguator-pro' )
				),
			);
		}

		$nav_menus = (array) $this->option( 'nav_menus', array() );
		$theme_map = isset( $nav_menus[ get_option( 'stylesheet' ) ] ) ? (array) $nav_menus[ get_option( 'stylesheet' ) ] : array();
		$assign    = admin_url( 'nav-menus.php?action=locations' );
		$results   = array();

		foreach ( $locations as $location => $label ) {
			// Skip the temporary per-language locations Linguator registers (e.g. header___hi).
			if ( false !== strpos( (string) $location, '___' ) ) {
				continue;
			}

			if ( ! $this->wants( 'menu_' . $location ) ) {
				continue;
			}

			$details = array();
			$missing = array();

			foreach ( $languages as $lang ) {
				if ( ! empty( $theme_map[ $location ][ $lang->slug ] ) ) {
					continue;
				}

				$missing[] = $lang->slug;
				$details[] = array(
					'label'        => $this->lang_label( $lang ),
					'message'      => sprintf(
						/* translators: %s: menu location label */
						__( 'No menu assigned to "%s".', 'linguator-pro' ),
						$label
					),
					'action_url'   => $assign,
					'action_label' => __( 'Assign menu', 'linguator-pro' ),
					'ignore_key'   => 'menu:' . $location . ':' . $lang->slug,
				);
			}

			if ( empty( $missing ) ) {
				$results[] = $this->result(
					'menu_' . $location,
					Result::PASS,
					/* translators: %s: menu location label */
					sprintf( __( '"%s" menu set for all languages', 'linguator-pro' ), $label ),
					__( 'A menu is assigned in every language.', 'linguator-pro' )
				);
				continue;
			}

			$results[] = $this->result(
				'menu_' . $location,
				Result::WARNING,
				/* translators: %s: menu location label */
				sprintf( __( '"%s" menu not set for all languages', 'linguator-pro' ), $label ),
				sprintf(
					/* translators: 1: menu location label, 2: language slugs */
					__( 'No menu is assigned to "%1$s" for: %2$s.', 'linguator-pro' ),
					$label,
					implode( ', ', $missing )
				)
			)
				->with_fix( $assign, __( 'Manage menu locations', 'linguator-pro' ) )
				->with_details( $details );
		}

		return $results;
	}
}
