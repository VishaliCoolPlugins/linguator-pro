<?php
/**
 * @package Linguator
 */
namespace LinguatorPro\Admin\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use LinguatorPro\Includes\Filters\Linguator_Filters;
use LinguatorPro\Includes\Other\Linguator_Language;



/**
 * Setup miscellaneous admin filters as well as filters common to admin and frontend
 *
 *  
 */
class Linguator_Admin_Filters extends Linguator_Filters {

	/**
	 * Constructor: setups filters and actions.
	 *
	 *  
	 *
	 * @param object $linguator The Linguator object.
	 */
	public function __construct( &$linguator ) {
		parent::__construct( $linguator );

		// Language management for users
		add_action( 'personal_options_update', array( $this, 'linguator_personal_options_update' ) );
		add_action( 'edit_user_profile_update', array( $this, 'linguator_personal_options_update' ) );
		add_action( 'personal_options', array( $this, 'linguator_personal_options' ) );

		// Upgrades plugins and themes translations files
		add_filter( 'themes_update_check_locales', array( $this, 'linguator_update_check_locales' ) );
		add_filter( 'plugins_update_check_locales', array( $this, 'linguator_update_check_locales' ) );

		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );

		// Add post state for translations of the privacy policy page
		add_filter( 'display_post_states', array( $this, 'display_post_states' ), 10, 2 );
	}

	/**
	 * Updates the user biographies.
	 *
	 *  
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function linguator_personal_options_update( $user_id ) {
		// Biography translations
		foreach ( $this->model->get_languages_list() as $lang ) {
			$meta        = $lang->is_default ? 'description' : 'description_' . $lang->slug;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WordPress core handles nonce verification for linguator_personal_options_update, sanitized below with sanitize_textarea_field
			$description = ! empty( $_POST[ 'description_' . $lang->slug ] ) ? sanitize_textarea_field( trim( wp_unslash( $_POST[ 'description_' . $lang->slug ] ) ) ) : '';

			/** This filter is documented in wp-includes/user.php */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$description = apply_filters( 'pre_user_description', $description ); // Applies WP default filter wp_filter_kses
			update_user_meta( $user_id, $meta, $description );
		}
	}

	/**
	 * Enqueues scripts for the multilingual biography on the user's profile admin page.
	 *
	 * @param WP_User $profileuser The current WP_User object.
	 * @return void
	 */
	public function linguator_personal_options( $profileuser ) {
		$screen = get_current_screen();

		if ( empty( $screen ) || ! in_array( $screen->base, array( 'profile', 'user-edit' ), true ) ) {
			return;
		}

		if ( ! $this->model->has_languages() ) {
			return;
		}

		$data = array();

		wp_enqueue_script( 'lmat_user', plugins_url( 'admin/assets/js/build/user.min.js', LINGUATOR_PRO_ROOT_FILE ), array(), LINGUATOR_PRO_VERSION, array( 'in_footer' => true ) );

		foreach ( $this->model->get_languages_list() as $lang ) {
			$meta        = $lang->is_default ? 'description' : "description_{$lang->slug}";
			$description = get_user_meta( $profileuser->ID, $meta, true );
			$description = is_string( $description ) ? $description : '';

			$data[] = array(
				'slug'        => esc_attr( $lang->slug ),
				'name'        => esc_html( $lang->name ),
				'lang'        => esc_attr( $lang->get_locale( 'display' ) ),
				'direction'   => $lang->is_rtl ? 'rtl' : 'ltr',
				'flag'        => Linguator_Language::get_flag_information( $lang->flag_code ),
				'description' => sanitize_user_field( 'description', $description, $profileuser->ID, 'edit' ),
			);
		}

		$script = sprintf( 'const lmatDescriptionData = %s;', wp_json_encode( $data ) );
		wp_add_inline_script( 'lmat_user', $script, 'before' );
	}

	/**
	 * Allows to update translations files for plugins and themes.
	 *
	 *  
	 *
	 * @param string[] $locales List of locales to update for plugins and themes.
	 * @return string[]
	 */
	public function linguator_update_check_locales( $locales ) {
		return array_merge( $locales, $this->model->get_languages_list( array( 'fields' => 'locale' ) ) );
	}

	/**
	 * Adds custom classes to the body
	 *
	 *   Adds a text direction dependent class to the body.
	 *   Adds a language dependent class to the body.
	 *
	 * @param string $classes Space-separated list of CSS classes.
	 * @return string
	 */
	public function admin_body_class( $classes ) {
		if ( ! empty( $this->curlang ) ) {
			$classes .= ' lmat-dir-' . ( $this->curlang->is_rtl ? 'rtl' : 'ltr' );
			$classes .= ' lmat-lang-' . $this->curlang->slug;
		}
		return $classes;
	}

	/**
	 * Adds post state for translations of the privacy policy page.
	 *
	 *  
	 *
	 * @param string[] $post_states An array of post display states.
	 * @param WP_Post  $post        The current post object.
	 * @return string[]
	 */
	public function display_post_states( $post_states, $post ) {
		$page_for_privacy_policy = get_option( 'wp_page_for_privacy_policy' );

		if ( $page_for_privacy_policy && in_array( $post->ID, $this->model->post->get_translations( $page_for_privacy_policy ) ) ) {
			$post_states['page_for_privacy_policy'] = __( 'Privacy Policy Page', 'linguator-pro' );
		}

		return $post_states;
	}
}
