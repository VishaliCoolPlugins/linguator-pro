<?php
/**
 * @package Linguator
 */
namespace Linguator\Admin\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Linguator\Includes\Walkers\Linguator_Walker_Dropdown;
use WP_Post;
use WP_Query;
use WP_Screen;
use WP_Ajax_Response;
use WP_Term;
use WP_Term_Query;


/**
 * Adds the language column in posts and terms list tables
 * Manages quick edit and bulk edit as well
 *
 *  
 */
class Linguator_Admin_Filters_Columns {
	/**
	 * @var Linguator_Model
	 */
	public $model;

	/**
	 * @var Linguator_Admin_Links|null
	 */
	public $links;

	/**
	 * Language selected in the admin language filter.
	 *
	 * @var Linguator_Language|null
	 */
	public $filter_lang;

	/**
	 * Constructor: setups filters and actions
	 *
	 *  
	 *
	 * @param object $linguator The Linguator object.
	 */
	public function __construct( &$linguator ) {
		$this->links = &$linguator->links;
		$this->model = &$linguator->model;
		$this->filter_lang = &$linguator->filter_lang;

		// Hide the column of the filtered language.
		add_filter( 'hidden_columns', array( $this, 'hidden_columns' ) ); // Since WP 4.4.

		// Add the language and translations columns in 'All Posts', 'All Pages' and 'Media library' panels.
		foreach ( $this->model->get_translated_post_types() as $type ) {
			// Use the latest filter late as some plugins purely overwrite what's done by others :(
			// Specific case for media.
			add_filter( 'manage_' . ( 'attachment' == $type ? 'upload' : 'edit-' . $type ) . '_columns', array( $this, 'add_post_column' ), 100 );
			add_action( 'manage_' . ( 'attachment' == $type ? 'media' : $type . '_posts' ) . '_custom_column', array( $this, 'post_column' ), 10, 2 );
		}

		// Quick edit and bulk edit.
		add_filter( 'quick_edit_custom_box', array( $this, 'quick_edit_custom_box' ) );
		add_filter( 'bulk_edit_custom_box', array( $this, 'quick_edit_custom_box' ) );

		// Adds the language column in the 'Categories' and 'Post Tags' tables.
		foreach ( $this->model->get_translated_taxonomies() as $tax ) {
			add_filter( 'manage_edit-' . $tax . '_columns', array( $this, 'add_term_column' ) );
			add_filter( 'manage_' . $tax . '_custom_column', array( $this, 'term_column' ), 20, 3 );
		}

		// Ajax responses to update list table rows.
		add_action( 'wp_ajax_lmat_update_post_rows', array( $this, 'ajax_update_post_rows' ) );
		add_action( 'wp_ajax_lmat_update_term_rows', array( $this, 'ajax_update_term_rows' ) );
	}

	/**
	 * Adds languages and translations columns in posts, pages, media, categories and tags tables.
	 *
	 *  
	 *
	 * @param string[] $columns List of table columns.
	 * @param string   $before  The column before which we want to add our languages.
	 * @return string[] Modified list of columns.
	 */
	protected function add_column( $columns, $before ) {
		if ( $n = array_search( $before, array_keys( $columns ) ) ) {
			$end = array_slice( $columns, $n );
			$columns = array_slice( $columns, 0, $n );
		}

		foreach ( $this->model->get_languages_list() as $language ) {
			/*
			 * WP_Screen strips HTML from column labels in Screen Options, revealing the
			 * language name. List table headers retain the HTML, displaying the flag
			 * while keeping the language name available to screen readers.
			 */
			$columns[ 'language_' . $language->slug ] = $this->get_flag_html( $language, true ) . '<span class="screen-reader-text">' . esc_html( $language->name ) . '</span>';
		}

		return isset( $end ) ? array_merge( $columns, $end ) : $columns;
	}

	/**
	 * Returns the first language column in posts, pages, media, categories and tags tables.
	 *
	 *  
	 *
	 * @return string first language column name.
	 */
	protected function get_first_language_column() {
		foreach ( $this->model->get_languages_list() as $language ) {
			return 'language_' . $language->slug;
		}

		return '';
	}

	/**
	 * Hides the column for the filtered language.
	 *
	 *  
	 *
	 * @param string[] $hidden Array of hidden columns.
	 * @return string[]
	 */
	public function hidden_columns( $hidden ) {
		if ( ! empty( $this->filter_lang ) ) {
			$hidden[] = 'language_' . $this->filter_lang->slug;
		}
		return $hidden;
	}

	/**
	 * Adds the language and translations columns ( before the comments column ) in the posts, pages and media library tables.
	 *
	 *  
	 *
	 * @param string[] $columns List of posts table columns.
	 * @return string[] Modified list of columns.
	 */
	public function add_post_column( $columns ) {
		return $this->add_column( $columns, 'comments' );
	}

	/**
	 * Fills the language and translations columns in the posts, pages and media library tables
	 * take care that when doing ajax inline edit, the post may not be updated in database yet
	 *
	 *  
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function post_column( $column, $post_id ) {
		$inline = wp_doing_ajax() && isset( $_REQUEST['action'], $_POST['inline_lang_choice'] ) && 'inline-save' === sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$lang = $inline ? $this->model->get_language( sanitize_key( wp_unslash( $_POST['inline_lang_choice'] ) ) ) : $this->model->post->get_language( $post_id ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( false === strpos( $column, 'language_' ) || ! $lang ) {
			return;
		}

		$language = $this->model->get_language( substr( $column, 9 ) );

		if ( empty( $language ) ) {
			return;
		}

		// Hidden field containing the post language for quick edit
		if ( $column == $this->get_first_language_column() ) {
			printf( '<div class="hidden" id="lang_%d">%s</div>', intval( $post_id ), esc_html( $lang->slug ) );
		}

		// Link to edit post ( or a translation )
		if ( $id = $this->model->post->get( $post_id, $language ) ) {
			// get_edit_post_link returns nothing if the user cannot edit the post
			if ( $link = get_edit_post_link( $id ) ) {
				$link = add_query_arg( 'lang', $language->slug, $link );
				$flag = '';
				if ( $id === $post_id ) {
					$flag = $this->get_flag_html( $language );
					$class = 'lmat_column_flag';
					/* translators: accessibility text, %s is a native language name */
					$s = sprintf( __( 'Edit this item in %s', 'linguator-pro' ), $language->name );
				} else {
					$class = esc_attr( 'lmat_icon_edit translation_' . $id );
					/* translators: accessibility text, %s is a native language name */
					$s = sprintf( __( 'Edit the translation in %s', 'linguator-pro' ), $language->name );
				}

				$post = get_post( $id );

				if ( ! empty( $post ) ) {
					printf(
						'<a class="%1$s" title="%2$s" href="%3$s"><span class="screen-reader-text">%4$s</span>%5$s</a>',
						esc_attr( $class ),
						esc_attr( $post->post_title ),
						esc_url( $link ),
						esc_html( $s ),
						wp_kses_post( $flag )
					);
				}
			} elseif ( $id === $post_id ) {
				printf(
					'<span class="lmat_column_flag" style=""><span class="screen-reader-text">%1$s</span>%2$s</span>',
					/* translators: accessibility text, %s is a native language name */
					esc_html( sprintf( __( 'This item is in %s', 'linguator-pro' ), $language->name ) ),
					wp_kses_post( $this->get_flag_html( $language ) )
				);
			}
		}
		// Link to add a new translation
		else {
			echo wp_kses_post( $this->links->new_post_translation_link( $post_id, $language ) );
		}
	}

	/**
	 * Quick edit & bulk edit
	 *
	 *  
	 *
	 * @param string $column column name
	 * @return string unmodified $column
	 */
	public function quick_edit_custom_box( $column ) {
		if ( $column == $this->get_first_language_column() ) {

			$elements = $this->model->languages->filter( 'translator' )->get_list();
			if ( current_filter() == 'bulk_edit_custom_box' ) {
				array_unshift( $elements, (object) array( 'slug' => -1, 'name' => __( '&mdash; No Change &mdash;', 'linguator-pro' ) ) );
			}

			$dropdown = new Linguator_Walker_Dropdown();
			// The hidden field 'old_lang' allows to pass the old language to ajax request
			printf(
				'<fieldset class="inline-edit-col-left">
					<div class="inline-edit-col">
						<label class="alignleft">
							<span class="title">%s</span>
							%s
						</label>
					</div>
				</fieldset>',
				esc_html__( 'Language', 'linguator-pro' ),
				wp_kses( $dropdown->walk( $elements, -1, array( 'name' => 'inline_lang_choice', 'id' => '' ) ), array(
					'select' => array( 'name' => true, 'id' => true, 'class' => true ),
					'option' => array( 'value' => true, 'selected' => true )
				) )
			);
		}
		return $column;
	}

	/**
	 * Adds the language column ( before the posts column ) in the 'Categories' or 'Post Tags' table.
	 *
	 *  
	 *
	 * @param string[] $columns List of terms table columns.
	 * @return string[] modified List of columns.
	 */
	public function add_term_column( $columns ) {
		$screen = get_current_screen();

		// Avoid displaying languages in screen options when editing a term.
		if ( $screen instanceof WP_Screen && 'term' === $screen->base ) {
			return $columns;
		}

		return $this->add_column( $columns, 'posts' );
	}

	/**
	 * Fills the language column in the taxonomy terms list table.
	 *
	 *  
	 *
	 * @param string $out     Column output.
	 * @param string $column  Column name.
	 * @param int    $term_id Term ID.
	 * @return string
	 */
	public function term_column( $out, $column, $term_id ) {
		$inline = wp_doing_ajax() && isset( $_REQUEST['action'], $_POST['inline_lang_choice'] ) && 'inline-save-tax' === sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( false === strpos( $column, 'language_' ) || ! ( $lang = $inline ? $this->model->get_language( sanitize_key( wp_unslash( $_POST['inline_lang_choice'] ) ) ) : $this->model->term->get_language( $term_id ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $out;
		}

		if ( isset( $_REQUEST['post_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$post_type = sanitize_key( wp_unslash( $_REQUEST['post_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		if ( isset( $GLOBALS['post_type'] ) ) {
			$post_type = $GLOBALS['post_type'];
		}

		if ( isset( $_REQUEST['taxonomy'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$taxonomy = sanitize_key( wp_unslash( $_REQUEST['taxonomy'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		if ( isset( $GLOBALS['taxonomy'] ) ) {
			$taxonomy = $GLOBALS['taxonomy'];
		}

		if ( ! isset( $taxonomy, $post_type ) || ! post_type_exists( $post_type ) || ! taxonomy_exists( $taxonomy ) ) {
			return $out;
		}

		$term_id = (int) $term_id;
		$language = $this->model->get_language( substr( $column, 9 ) );

		if ( empty( $language ) ) {
			return $out;
		}

		// Link to edit term ( or a translation )
		if ( ( $id = $this->model->term->get( $term_id, $language ) ) && $term = get_term( $id, $taxonomy ) ) {
			if ( $term instanceof WP_Term && $link = get_edit_term_link( $id, $taxonomy, $post_type ) ) {
				// Add lang parameter to the edit link similar to how it's done in add new links
				$link = add_query_arg( 'lang', $language->slug, $link );
				$flag = '';
				if ( $id === $term_id ) {
					$flag = $this->get_flag_html( $language );
					$class = 'lmat_column_flag';
					/* translators: accessibility text, %s is a native language name */
					$s = sprintf( __( 'Edit this item in %s', 'linguator-pro' ), $language->name );
				} else {
					$class = esc_attr( 'lmat_icon_edit translation_' . $id );
					/* translators: accessibility text, %s is a native language name */
					$s = sprintf( __( 'Edit the translation in %s', 'linguator-pro' ), $language->name );
				}
				$out .= sprintf(
					'<a class="%1$s" title="%2$s" href="%3$s"><span class="screen-reader-text">%4$s</span>%5$s</a>',
					$class,
					esc_attr( $term->name ),
					esc_url( $link ),
					esc_html( $s ),
					$flag
				);
			} elseif ( $id === $term_id ) {
				$out .= sprintf(
					'<span class="lmat_column_flag"><span class="screen-reader-text">%1$s</span>%2$s</span>',
					/* translators: accessibility text, %s is a native language name */
					esc_html( sprintf( __( 'This item is in %s', 'linguator-pro' ), $language->name ) ),
					$this->get_flag_html( $language )
				);
			}
		}

		// Link to add a new translation
		else {
			$out .= $this->links->new_term_translation_link( $term_id, $taxonomy, $post_type, $language );
		}

		if ( $this->get_first_language_column() === $column ) {
			$out .= sprintf( '<div class="hidden" id="lang_%d">%s</div>', intval( $term_id ), esc_html( $lang->slug ) );

			/**
			 * Filters the output of the first language column in the terms list table.
			 *
			 *  
			 *
			 * @param string $output  First language column output.
			 * @param int    $term_id Term ID.
			 * @param string $lang    Language code.
			 */
			$out = apply_filters( 'lmat_first_language_term_column', $out, $term_id, $lang->slug );
		}

		return $out;
	}

	/**
	 * Update rows of translated posts when the language is modified in quick edit
	 *
	 *  
	 *
	 * @return void
	 */
	public function ajax_update_post_rows() {
		check_ajax_referer( 'inlineeditnonce', '_lmat_nonce' );

		if ( ! isset( $_POST['post_type'], $_POST['post_id'], $_POST['screen'] ) ) {
			wp_die( 0 );
		}

		if ( ! is_numeric( $_POST['post_id'] ) ) {
			wp_die( 0 );
		}

		if ( ! current_user_can( 'edit_post', (int) $_POST['post_id'] ) ) {
			wp_die( 0 );
		}

		$post_type_object = get_post_type_object( sanitize_key( wp_unslash( $_POST['post_type'] ) ) );

		if ( empty( $post_type_object ) || ! $this->model->is_translated_post_type( $post_type_object->name ) ) {
			wp_die( 0 );
		}

		$wp_list_table = _get_list_table( 'WP_Posts_List_Table', array( 'screen' => sanitize_key( wp_unslash( $_POST['screen'] ) ) ) );

		if ( empty( $wp_list_table ) ) {
			wp_die( 0 );
		}

		$response = new WP_Ajax_Response();

		// Collect old translations.
		if ( ! empty( $_POST['translations'] ) && is_string( $_POST['translations'] ) ) {
			$translations = explode( ',', wp_unslash( $_POST['translations'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$translations = array_filter( $translations, 'is_numeric' );
			$translations = array_map( 'intval', $translations );
			$translations = array_filter( $translations );
		} else {
			$translations = array();
		}

		$translations = array_merge( $translations, $this->model->post->get_translations( (int) $_POST['post_id'] ) );
		$translations = array_unique( $translations );

		if ( empty( $translations ) ) { // May occur if the modified post has no language.
			$response->send();
		}

		/** @var WP_Post[] */
		$posts = ( new WP_Query() )->query(
			array(
				// Do not add `post_status`, as this allows `WP_Query` to handle user capabilities to read private posts.
				'post__in'       => $translations,
				'post_type'      => $post_type_object->name,
				'posts_per_page' => count( $translations ),
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'lmat_lang'      => '',
			)
		);

		foreach ( $posts as $post ) {
			if ( $post_type_object->hierarchical ) {
				$level = count( get_ancestors( $post->ID, $post->post_type, 'post_type' ) );
			} else {
				$level = 0;
			}

			ob_start();
			$wp_list_table->single_row( $post, $level );
			$data = (string) ob_get_clean();

			$response->add(
				array(
					'what'         => 'row',
					'data'         => $data,
					'supplemental' => array( 'post_id' => $post->ID ),
				)
			);
		}

		$response->send();
	}

	/**
	 * Update rows of translated terms when adding / deleting a translation or when the language is modified in quick edit
	 *
	 *  
	 *
	 * @return void
	 */
	public function ajax_update_term_rows() {
		check_ajax_referer( 'lmat_language', '_lmat_nonce' );

		if ( ! isset( $_POST['taxonomy'], $_POST['term_id'], $_POST['screen'] ) ) {
			wp_die( 0 );
		}

		if ( ! is_numeric( $_POST['term_id'] ) ) {
			wp_die( 0 );
		}

		if ( ! current_user_can( 'edit_term', (int) $_POST['term_id'] ) ) {
			wp_die( 0 );
		}

		$taxonomy_object = get_taxonomy( sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) );

		if ( empty( $taxonomy_object ) || ! $this->model->is_translated_taxonomy( $taxonomy_object->name ) ) {
			wp_die( 0 );
		}

		$wp_list_table = _get_list_table( 'WP_Terms_List_Table', array( 'screen' => sanitize_key( wp_unslash( $_POST['screen'] ) ) ) );

		if ( empty( $wp_list_table ) ) {
			wp_die( 0 );
		}

		$response = new WP_Ajax_Response();

		// Collect old translations.
		if ( ! empty( $_POST['translations'] ) && is_string( $_POST['translations'] ) ) {
			$translations = explode( ',', wp_unslash( $_POST['translations'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$translations = array_filter( $translations, 'is_numeric' );
			$translations = array_map( 'intval', $translations );
			$translations = array_filter( $translations );
		} else {
			$translations = array();
		}

		$translations = array_merge( $translations, $this->model->term->get_translations( (int) $_POST['term_id'] ) );
		$translations = array_unique( $translations );

		if ( empty( $translations ) ) { // May occur if the modified term has no language.
			$response->send();
		}

		/** @var WP_Term[] */
		$terms = ( new WP_Term_Query() )->query(
			array(
				'include'    => $translations,
				'taxonomy'   => $taxonomy_object->name,
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'lang'       => '',
			)
		);

		foreach ( $terms as $term ) {
			if ( $taxonomy_object->hierarchical ) {
				$level = count( get_ancestors( $term->term_id, $taxonomy_object->name, 'taxonomy' ) );
			} else {
				$level = 0;
			}

			ob_start();
			$wp_list_table->single_row( $term, $level );
			$data = (string) ob_get_clean();

			$response->add(
				array(
					'what'         => 'row',
					'data'         => $data,
					'supplemental' => array( 'term_id' => $term->term_id ),
				)
			);
		}

		$response->send();
	}

	/**
	 * Returns the language flag or the language slug if there is no flag.
	 *
	 *  
	 *
	 * @param Linguator_Language $language    Linguator_Language object.
	 * @param bool                $aria_hidden Whether to hide the flag from assistive technology.
	 * @return string
	 */
	protected function get_flag_html( $language, $aria_hidden = false ) {
		$flag = $language->flag ?: sprintf( '<abbr>%s</abbr>', esc_html( $language->slug ) );

		if ( $aria_hidden ) {
			$flag = (string) preg_replace( '/^<([a-z][a-z0-9]*)\b/i', '<$1 aria-hidden="true"', $flag, 1 );
		}

		return $flag;
	}
}
