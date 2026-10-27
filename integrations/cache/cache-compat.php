<?php
/**
 * @package Linguator
 */
namespace LinguatorPro\Integrations\cache;


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A class to manage specific compatibility issue with cache plugins
 * Tested with WP Rocket 2.10.7
 *
 *  
 */
class Linguator_Cache_Compat {
	/**
	 * Setups actions
	 *
	 *  
	 */
	public function init() {
		if ( LMATP_COOKIE ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'linguator_add_cookie_script' ) );
		}

		// Since version 3.0.5, WP Rocket does not serve the cached page if our cookie is not set
		if ( ! defined( 'WP_ROCKET_VERSION' ) || version_compare( WP_ROCKET_VERSION, '3.0.5', '<' ) ) {
			add_action( 'wp', array( $this, 'linguator_do_not_cache_site_home' ) );
		}

		add_action( 'clean_post_cache', array( $this, 'clean_post_cache' ), 1 );
	}

	/**
	 * Currently all tested cache plugins don't send cookies with cached pages.
	 * This makes us impossible to know the language of the last browsed page.
	 * This functions allows to create the cookie in javascript as a workaround.
	 *
	 *  
	 *
	 * @return void
	 */
	public function linguator_add_cookie_script() {
		// Embeds should not set the cookie.
		if ( is_embed() ) {
			return;
		}

		$domain   = ( 2 === LMATP()->options['force_lang'] ) ? wp_parse_url( LMATP()->links_model->home, PHP_URL_HOST ) : COOKIE_DOMAIN;
		$samesite = ( 3 === LMATP()->options['force_lang'] ) ? 'None' : 'Lax';

		/** This filter is documented in include/cookie.php */
		$expiration = (int) apply_filters( 'lmat_cookie_expiration', YEAR_IN_SECONDS );

		if ( 0 !== $expiration ) {
			$format = 'var expirationDate = new Date();
				expirationDate.setTime( expirationDate.getTime() + %7$d * 1000 );
				document.cookie = "%1$s=%2$s; expires=" + expirationDate.toUTCString() + "; path=%3$s%4$s%5$s%6$s";';
		} else {
			$format = 'document.cookie = "%1$s=%2$s; path=%3$s%4$s%5$s%6$s";';
		}

		$js = sprintf(
			"(function() {
				{$format}
			}());\n",
			esc_js( LMATP_COOKIE ),
			esc_js( linguator_pro_current_language() ),
			esc_js( COOKIEPATH ),
			$domain ? '; domain=' . esc_js( $domain ) : '',
			is_ssl() ? '; secure' : '',
			'; SameSite=' . $samesite,
			esc_js( $expiration )
		);

		// Need to register prior to enqueue empty script and add extra code to it.
		wp_register_script( 'lmat_cookie_script', '', array(), LINGUATOR_PRO_VERSION, true );
		wp_enqueue_script( 'lmat_cookie_script' );
		wp_add_inline_script( 'lmat_cookie_script', $js );
	}

	/**
	 * Informs cache plugins not to cache the home in the default language
	 * When the detection of the browser preferred language is active
	 *
	 *  
	 */
	public function linguator_do_not_cache_site_home() {
		if ( ! defined( 'DONOTCACHEPAGE' ) && LMATP()->options['browser'] && LMATP()->options['hide_default'] && is_front_page() && linguator_pro_current_language() === linguator_pro_default_language() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * Allows cache plugins to clean the right post type archive cache when cleaning a post cache.
	 *
	 *  
	 *
	 * @param int $post_id Post id.
	 */
	public function clean_post_cache( $post_id ) {
		$lang = LMATP()->model->post->get_language( $post_id );

		if ( $lang ) {
			$filter_callback = function ( $link, $post_type ) use ( $lang ) {
				return linguator_pro_is_translated_post_type( $post_type ) && 'post' !== $post_type ? LMATP()->links_model->switch_language_in_link( $link, $lang ) : $link;
			};
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			add_filter( 'post_type_archive_link', $filter_callback, 99, 2 );
		}
	}
}

