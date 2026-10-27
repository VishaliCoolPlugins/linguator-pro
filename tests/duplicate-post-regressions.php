<?php
/**
 * Standalone regression checks for Duplicate Post rewrite synchronization.
 * Run: php tests/duplicate-post-regressions.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['filters'] = array();
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['filters'][ $hook ] = array( $callback, $priority, $accepted_args );
}

function get_post_meta( $post_id, $key, $single ) {
	return 21 === $post_id && '_dp_is_rewrite_republish_copy' === $key ? '1' : '';
}

require_once dirname( __DIR__ ) . '/integrations/duplicate-post/duplicate-post.php';

$integration = new \Linguator\Integrations\duplicate_post\Linguator_Duplicate_Post();
$integration->init();

if ( ! isset( $GLOBALS['filters']['lmat_sync_post_fields'] ) || 2 !== $GLOBALS['filters']['lmat_sync_post_fields'][2] ) {
	throw new RuntimeException( 'Duplicate Post field filter was not registered for the source post ID.' );
}
if ( ! isset( $GLOBALS['filters']['lmat_skip_save_post_translations'] ) || 2 !== $GLOBALS['filters']['lmat_skip_save_post_translations'][2] ) {
	throw new RuntimeException( 'Duplicate Post Classic Editor translation guard was not registered.' );
}

$fields = array( 'post_title' => 'post_title', 'post_status' => 'post_status' );
if ( $integration->exclude_rewrite_copy_fields( $fields, 20 ) !== $fields ) {
	throw new RuntimeException( 'Ordinary post synchronization lost its status.' );
}

if ( $integration->exclude_rewrite_copy_fields( $fields, 21 ) !== array( 'post_title' => 'post_title' ) ) {
	throw new RuntimeException( 'Rewrite copy status was synchronized to a translation.' );
}

if ( $integration->skip_rewrite_copy_translations( false, 20 ) ) {
	throw new RuntimeException( 'Ordinary post translation saving was skipped.' );
}

if ( ! $integration->skip_rewrite_copy_translations( false, 21 ) ) {
	throw new RuntimeException( 'Rewrite copy was allowed to overwrite the canonical translation group.' );
}

if ( ! $integration->skip_rewrite_copy_translations( true, 20 ) ) {
	throw new RuntimeException( 'An existing translation-save guard was overridden.' );
}

echo "Duplicate Post regressions passed.\n";
