<?php
/**
 * @package Linguator
 */

namespace LinguatorPro\Modules\sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use LinguatorPro\Settings\Controllers\Linguator_Settings_Module;



/**
 * Settings class for synchronization settings management
 *
 *  
 */
class Linguator_Settings_Sync extends Linguator_Settings_Module {
	/**
	 * Stores the display order priority.
	 *
	 * @var int
	 */
	public $priority = 50;

	/**
	 * Constructor
	 *
	 *  
	 *
	 * @param object $linguator The linguator object.
	 */
	public function __construct( &$linguator ) {
		parent::__construct(
			$linguator,
			array(
				'module'      => 'sync',
				'title'       => __( 'Synchronization', 'linguator-pro' ),
				'description' => __( 'The synchronization options allow to maintain exact same values (or translations in the case of taxonomies and page parent) of meta content between the translations of a post or page.', 'linguator-pro' ),
			)
		);
	}

	/**
	 * Deactivates the module
	 *
	 *  
	 */
	public function deactivate() {
		$this->options['sync'] = array();
	}


	/**
	 * Prepare the received data before saving.
	 *
	 *  
	 *
	 * @param array $options Raw values to save.
	 * @return array
	 */
	protected function prepare_raw_data( array $options ): array {
		// Take care to return only validated options.
		return array( 'sync' => empty( $options['sync'] ) ? array() : array_keys( $options['sync'], 1 ) );
	}

	/**
	 * Get the row actions.
	 *
	 *  
	 *
	 * @return string[] Row actions.
	 */
	protected function get_actions() {
		return empty( $this->options['sync'] ) ? array( 'configure' ) : array( 'configure', 'deactivate' );
	}

	/**
	 * Get the list of synchronization settings.
	 *
	 *  
	 *
	 * @return string[] Array synchronization options.
	 *
	 * @phpstan-return non-empty-array<non-falsy-string, string>
	 */
	public static function list_metas_to_sync() {
		return array(
			'taxonomies'        => __( 'Taxonomies', 'linguator-pro' ),
			'post_meta'         => __( 'Custom fields', 'linguator-pro' ),
			'comment_status'    => __( 'Comment status', 'linguator-pro' ),
			'ping_status'       => __( 'Ping status', 'linguator-pro' ),
			'sticky_posts'      => __( 'Sticky posts', 'linguator-pro' ),
			'post_date'         => __( 'Published date', 'linguator-pro' ),
			'post_format'       => __( 'Post format', 'linguator-pro' ),
			'post_parent'       => __( 'Page parent', 'linguator-pro' ),
			'_wp_page_template' => __( 'Page template', 'linguator-pro' ),
			'menu_order'        => __( 'Page order', 'linguator-pro' ),
			'_thumbnail_id'     => __( 'Featured image', 'linguator-pro' ),
		);
	}
}

