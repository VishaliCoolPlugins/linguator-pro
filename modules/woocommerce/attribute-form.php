<?php
/**
 * Shows translated product attributes on the product edit screen as soon as the translation editor saves them.
 *
 * @package LinguatorPro
 */

namespace LinguatorPro\Modules\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The translation editor saves the translated attribute texts straight away, but the product form was loaded with
 * the attributes copied from the original product. Publishing posts that form, so until then it would still show
 * the original wording. This class gives the form a way to load the saved attributes again.
 *
 * @since 2.3.0
 */
class Attribute_Form {

	/**
	 * Nonce action of the endpoint.
	 */
	const NONCE = 'lmat_wc_attribute_form';

	/**
	 * Hooks the form refresh.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 10, 1 );
		add_action( 'wp_ajax_lmat_wc_attribute_form', array( __CLASS__, 'respond' ), 10, 0 );
	}

	/**
	 * Loads the script on the product edit screen when the product attributes field is enabled.
	 *
	 * @since 2.3.0
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! Attribute_Texts::is_enabled() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		$post_id = isset( $GLOBALS['post']->ID ) ? (int) $GLOBALS['post']->ID : 0;

		wp_enqueue_script(
			'lmat-wc-attribute-form',
			LINGUATOR_PRO_URL . 'modules/woocommerce/assets/attribute-form.js',
			array(),
			LINGUATOR_PRO_VERSION,
			true
		);
		wp_localize_script(
			'lmat-wc-attribute-form',
			'lmatWcAttributeForm',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'postId'  => $post_id,
			)
		);
	}

	/**
	 * Returns the saved custom attributes of a product, in the order of the form rows.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function respond() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( 'invalid_nonce', 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			wp_send_json_error( 'not_found', 404 );
		}

		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && ! $attribute->is_taxonomy() ) {
				$attributes[] = array(
					'name'  => (string) $attribute->get_name(),
					'value' => wc_implode_text_attributes( $attribute->get_options() ),
				);
			}
		}

		wp_send_json_success( array( 'attributes' => $attributes ) );
	}
}
