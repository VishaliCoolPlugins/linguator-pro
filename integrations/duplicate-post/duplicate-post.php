<?php
/**
 * @package Linguator
 */
namespace Linguator\Integrations\duplicate_post;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages the compatibility with Duplicate Post.
 *
 *  
 */
class Linguator_Duplicate_Post {
	/**
	 * Setups actions.
	 *
	 *  
	 */
	public function init() {
		add_filter( 'option_duplicate_post_taxonomies_blacklist', array( $this, 'linguator_taxonomies_blacklist' ) );
		add_filter( 'lmat_sync_post_fields', array( $this, 'exclude_rewrite_copy_fields' ), 10, 2 );
		add_filter( 'lmat_skip_save_post_translations', array( $this, 'skip_rewrite_copy_translations' ), 10, 2 );
	}

	/**
	 * Prevents the Classic Editor from replacing the canonical translation group
	 * with Yoast's temporary Rewrite & Republish copy.
	 *
	 * @param bool $skip    Whether translation saving should already be skipped.
	 * @param int  $post_id Post being saved.
	 * @return bool
	 */
	public function skip_rewrite_copy_translations( $skip, $post_id ) {
		return $skip || 1 === (int) get_post_meta( $post_id, '_dp_is_rewrite_republish_copy', true );
	}

	/**
	 * Keeps Duplicate Post's temporary rewrite status off translated posts.
	 *
	 * @param string[] $fields  Post fields to synchronize.
	 * @param int      $post_id Synchronization source post ID.
	 * @return string[]
	 */
	public function exclude_rewrite_copy_fields( $fields, $post_id ) {
		if ( 1 === (int) get_post_meta( $post_id, '_dp_is_rewrite_republish_copy', true ) ) {
			unset( $fields['post_status'] );
		}

		return $fields;
	}

	/**
	 * Avoid duplicating the 'lmat_post_translations' taxonomy.
	 *
	 *  
	 *
	 * @param array|string $taxonomies The list of taxonomies not to duplicate.
	 * @return array
	 */
	public function linguator_taxonomies_blacklist( $taxonomies ) {
		if ( empty( $taxonomies ) ) {
			$taxonomies = array(); // As we get an empty string when there is no taxonomy.
		}

		$taxonomies[] = 'lmat_post_translations';
		return $taxonomies;
	}
}

