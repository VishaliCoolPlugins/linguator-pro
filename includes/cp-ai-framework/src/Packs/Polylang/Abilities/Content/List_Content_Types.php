<?php
/**
 * List content types ability.
 *
 * @package AutoPoly
 * @since   1.10.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Content;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Content_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists translatable post types and taxonomies.
 *
 * @since 1.10.0
 */
final class List_Content_Types extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'list-content-types';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'List Content Types', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: list all translatable post types and taxonomies configured in Polylang, with their labels and total content counts. Also lists, under not_translated_post_types and not_translated_taxonomies, types Polylang can translate but that are switched off, with a notice explaining how the user can switch them on. Call this before planning a site translation, so content such as Elementor headers and footers is not silently left out.', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return $this->empty_object_schema();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed> Post types and taxonomies.
	 */
	public static function execute( array $input = array() ) {
		return Content_Service::get_content_types();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'edit_posts', $input );
	}
}
