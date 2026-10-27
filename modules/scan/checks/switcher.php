<?php
/**
 * Language switcher detection.
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
 * Looks for a language switcher in menus, widgets and block templates.
 *
 * @since 2.3.0
 */
class Switcher extends Abstract_Check {

	/**
	 * Block names that render a language switcher.
	 */
	const BLOCK_MARKERS = array(
		'wp:linguator/language-switcher',
		'wp:linguator/navigation-language-switcher',
	);

	/**
	 * @var string
	 */
	protected $group = 'Language Setup';

	/**
	 * Runs the check.
	 *
	 * @since 2.3.0
	 *
	 * @return Result[]
	 */
	public function run(): array {
		if ( count( $this->active_languages() ) < 2 ) {
			return array();
		}

		$found   = array();
		$enabled = (array) $this->option( 'lmat_language_switcher_options', array( 'default' ) );

		if ( $this->has_menu_switcher() ) {
			$found[] = __( 'Menus', 'linguator-pro' );
		}
		// Widgets and blocks only render when their switcher type is enabled in settings.
		if ( in_array( 'default', $enabled, true ) && $this->has_widget_switcher() ) {
			$found[] = __( 'Widgets', 'linguator-pro' );
		}
		if ( in_array( 'block', $enabled, true ) && $this->has_block_switcher() ) {
			$found[] = __( 'Blocks / templates', 'linguator-pro' );
		}
		if ( $this->has_elementor_switcher() ) {
			$found[] = __( 'Elementor', 'linguator-pro' );
		}

		/**
		 * Filters the detected language switcher locations.
		 *
		 * Return a non-empty array to treat a switcher as present.
		 *
		 * @since 2.3.0
		 *
		 * @param string[] $found Location labels.
		 */
		$found = apply_filters( 'lmat_scan_language_switcher_locations', $found );
		$found = is_array( $found ) ? array_values( array_unique( array_filter( array_map( 'strval', $found ) ) ) ) : array();

		if ( ! empty( $found ) ) {
			return array(
				$this->result(
					'lang_switcher',
					Result::PASS,
					__( 'Language switcher detected', 'linguator-pro' ),
					/* translators: %s: comma-separated list of locations */
					sprintf( __( 'Found in: %s.', 'linguator-pro' ), implode( ', ', $found ) )
				),
			);
		}

		// The settings tab only enables switcher types; placing one happens in the menu or site editor.
		if ( wp_is_block_theme() ) {
			$fix_url   = admin_url( 'site-editor.php?path=%2Fpatterns' );
			$fix_label = __( 'Add to header', 'linguator-pro' );
		} else {
			$fix_url   = admin_url( 'nav-menus.php' );
			$fix_label = __( 'Add to menu', 'linguator-pro' );
		}

		return array(
			$this->result(
				'lang_switcher',
				Result::WARNING,
				__( 'No language switcher detected', 'linguator-pro' ),
				__( 'Enabling a switcher type in settings does not display it. Add the language switcher to your header menu, a widget area or a block template so visitors can change language.', 'linguator-pro' )
			)->with_fix( $fix_url, $fix_label ),
		);
	}

	/**
	 * Whether a nav menu item is the Linguator switcher.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	private function has_menu_switcher(): bool {
		global $wpdb;

		// Bounded diagnostic read during a manual scan; not worth caching.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = %s
				WHERE p.post_type = 'nav_menu_item'
					AND p.post_status = 'publish'
				LIMIT 1",
				'_menu_item_url',
				'#lmat_switcher'
			)
		);
		// phpcs:enable

		return $found > 0;
	}

	/**
	 * Whether the languages widget sits in an active sidebar.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	private function has_widget_switcher(): bool {
		$sidebars = get_option( 'sidebars_widgets', array() );
		if ( ! is_array( $sidebars ) ) {
			return false;
		}

		foreach ( $sidebars as $sidebar => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar || ! is_array( $widgets ) ) {
				continue;
			}
			foreach ( $widgets as $widget_id ) {
				if ( 0 === strpos( (string) $widget_id, 'linguator_widget' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a switcher block is used in a template, template part, navigation or post.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	private function has_block_switcher(): bool {
		global $wpdb;

		$conditions = array();
		foreach ( self::BLOCK_MARKERS as $marker ) {
			$conditions[] = $wpdb->prepare( 'post_content LIKE %s', '%' . $wpdb->esc_like( $marker ) . '%' );
		}

		// Marker strings come from the class constant above and are prepared.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = (int) $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_status = 'publish'
				AND post_type IN ('wp_template', 'wp_template_part', 'wp_navigation', 'wp_block', 'post', 'page')
				AND ( " . implode( ' OR ', $conditions ) . ' )
			LIMIT 1'
		);
		// phpcs:enable

		return $found > 0;
	}

	/** Detect Linguator's own widget in published Elementor documents. */
	private function has_elementor_switcher(): bool {
		$options = (array) $this->option( 'lmat_language_switcher_options', array() );
		if ( ! Elementor::available() || ! in_array( 'elementor', $options, true ) ) {
			return false;
		}
		$ids = get_posts( array(
			'post_type' => 'any', 'post_status' => 'publish', 'fields' => 'ids',
			'posts_per_page' => Elementor::DOCUMENT_LIMIT, 'suppress_filters' => true,
			'meta_query' => array( array( 'key' => '_elementor_data', 'value' => 'lmat_widget', 'compare' => 'LIKE' ) ),
		) );
		foreach ( $ids as $id ) {
			$raw = get_post_meta( $id, '_elementor_data', true );
			$data = is_array( $raw ) ? $raw : ( is_string( $raw ) ? json_decode( $raw, true, 128 ) : null );
			if ( is_array( $data ) && Elementor::contains_switcher( $data ) ) {
				return true;
			}
		}
		return false;
	}
}
