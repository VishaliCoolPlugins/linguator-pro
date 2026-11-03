<?php
/**
 * Set Language Front Page ability.
 *
 * @package CoolPluginsAiFramework
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Pages;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Static_Pages_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Links a translated page as the front page or posts page of a language.
 *
 * @since 1.3.0
 */
final class Set_Language_Front_Page extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'set-language-front-page';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Set Language Front Page', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: make an existing page the front page (or posts page) of one language by linking it into the translation group of the site\'s static page. The page must be a published page written in that language; a page that already belongs to another translation group is refused (unlink it first with manage-translation-links). The language whose page is chosen in Settings > Reading cannot be changed here, and the site must use a static front page. The page currently linked in that language is unlinked, not deleted. This is a dry run by default and returns the before and after; call again with dry_run false to save, and the response includes the page read back.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'language' => array(
					'type'        => 'string',
					'description' => __( 'Language slug the page is the front page for, for example "fr".', 'linguator-pro' ),
				),
				'page_id'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the published page, written in that language.', 'linguator-pro' ),
				),
				'type'     => array(
					'type'        => 'string',
					'enum'        => array( 'front_page', 'posts_page' ),
					'default'     => 'front_page',
					'description' => __( 'front_page (default) or posts_page.', 'linguator-pro' ),
				),
				'dry_run' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'When true, the default, report what would change and change nothing. Set to false to save.', 'linguator-pro' ),
				),
			),
			'required'   => array( 'language', 'page_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		// Defaults to a dry run; only an explicit false writes.
		$dry_run = isset( $input['dry_run'] ) ? rest_sanitize_boolean( $input['dry_run'] ) : true;

		return Static_Pages_Service::set(
			isset( $input['language'] ) ? sanitize_key( (string) $input['language'] ) : '',
			isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0,
			isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : 'front_page',
			$dry_run
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
