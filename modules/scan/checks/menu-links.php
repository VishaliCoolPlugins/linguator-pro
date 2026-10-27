<?php
/**
 * Menu link checks.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Modules\Scan\Abstract_Check;
use LinguatorPro\Modules\Scan\Link_Tools;
use LinguatorPro\Modules\Scan\Result;

/**
 * Finds menu items, in the menus assigned to a language, that link to a page that does not exist or is not published.
 *
 * @since 2.3.0
 */
class Menu_Links extends Abstract_Check {

	use Link_Tools;

	/**
	 * Detail rows kept.
	 */
	const DETAIL_LIMIT = 30;

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
		// Block themes keep navigation in the Site Editor, not in menu locations.
		if ( count( $this->active_languages() ) < 2 || ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) || ! $this->wants( 'menu_dead_links' ) ) {
			return array();
		}

		$nav_menus = (array) $this->option( 'nav_menus', array() );
		$theme_map = isset( $nav_menus[ get_option( 'stylesheet' ) ] ) ? (array) $nav_menus[ get_option( 'stylesheet' ) ] : array();
		if ( empty( $theme_map ) ) {
			return array();
		}

		$details = array();
		$total   = 0;
		$seen    = array();

		foreach ( $theme_map as $location => $by_language ) {
			if ( ! is_array( $by_language ) ) {
				continue;
			}

			foreach ( $by_language as $slug => $menu_id ) {
				$menu_id = (int) $menu_id;
				if ( $menu_id <= 0 || isset( $seen[ 'menu:' . $menu_id ] ) ) {
					continue;
				}
				$seen[ 'menu:' . $menu_id ] = true;

				$items = wp_get_nav_menu_items( $menu_id );
				if ( ! is_array( $items ) ) {
					continue;
				}

				$lang      = $this->model->get_language( (string) $slug );
				$lang_name = $lang && ! empty( $lang->name ) ? $lang->name : strtoupper( (string) $slug );

				foreach ( $items as $item ) {
					if ( ! $item instanceof \WP_Post || $this->item_works( $item ) ) {
						continue;
					}

					$url = trim( (string) $item->url );
					if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
						$url = home_url( $url );
					}
					$dead = '' !== $url && $this->is_internal( $url ) ? $this->dead_target( $url ) : null;
					if ( ! $dead ) {
						continue;
					}

					$key = $menu_id . '|' . $this->normalize_url( $url );
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					$seen[ $key ] = true;

					++$total;
					if ( count( $details ) >= self::DETAIL_LIMIT ) {
						continue;
					}

					$title     = trim( (string) $item->title );
					$details[] = array(
						'label'        => $lang_name . ' → ' . ( '' !== $title ? $title : __( 'Menu item', 'linguator-pro' ) ),
						'message'      => $dead['message'],
                        'url' => $url,
                        'code' => 'dead_link',
                        'kind' => $dead['kind'],
                        'page_fix_url' => $dead['fix_url'],
                        'page_fix_label' => $dead['fix_label'],
						'action_url'   => admin_url( 'nav-menus.php?action=edit&menu=' . $menu_id ),
						'action_label' => __( 'Edit menu', 'linguator-pro' ),
						'ignore_key'   => 'menu_dead:' . $menu_id . ':' . md5( $this->normalize_url( $url ) ),
						'language'     => (string) $slug,
					);
				}
			}
		}

		if ( 0 === $total ) {
			return array();
		}

		return array(
			$this->result(
				'menu_dead_links',
				Result::WARNING,
				1 === $total
					? __( '1 menu link points to a missing page', 'linguator-pro' )
					: sprintf(
						/* translators: %d: number of links */
						__( '%d menu links point to missing pages', 'linguator-pro' ),
						$total
					),
				__( 'Some menu items link to URLs with no published page. Visitors who click them will see a page-not-found error.', 'linguator-pro' )
			)
				->with_fix( admin_url( 'nav-menus.php' ), __( 'Edit menus', 'linguator-pro' ) )
				->with_details( $details, $total > count( $details ) ),
		);
	}

	/**
	 * Whether a menu item that points at a post, term or archive still has its target.
	 *
	 * @since 2.3.0
	 *
	 * @param \WP_Post $item Nav menu item.
	 * @return bool True when the item needs no further check.
	 */
	private function item_works( \WP_Post $item ): bool {
		$type = (string) $item->type;

		if ( 'post_type' === $type && ! empty( $item->object_id ) ) {
			$target = get_post( (int) $item->object_id );

			return $target instanceof \WP_Post && 'publish' === $target->post_status && '' === (string) $target->post_password;
		}
		if ( 'taxonomy' === $type && ! empty( $item->object_id ) ) {
			$term = get_term( (int) $item->object_id );

			return $term instanceof \WP_Term && ! is_wp_error( $term );
		}

		return 'post_type_archive' === $type;
	}
}
