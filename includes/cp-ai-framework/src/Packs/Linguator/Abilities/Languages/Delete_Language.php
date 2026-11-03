<?php
/**
 * Delete language ability.
 *
 * @package CoolPluginsAiFramework
 * @since 1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Languages;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Language_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes a language from Linguator.
 *
 * @since 1.2.0
 */
final class Delete_Language extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'delete-language';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Delete Language', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: remove a language from Linguator. The default language cannot be deleted. A language that still has posts or terms is refused unless force is true: with force the content is not deleted, but it is left with no language and loses its translation links (a language can be assigned again with manage-translation-links, action "assign_language"). Rewrite rules are refreshed, so the language\'s URLs stop working. Check the counts in the refusal before forcing, and tell the user first.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'slug' => array(
					'type'        => 'string',
					'description' => __( 'The language slug to delete, for example "fr" or "de".', 'linguator-pro' ),
				),
				'force' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Set to true to delete a language that still has posts or terms. They are not deleted, but are left with no language and lose their translation links. Without it the call is refused and reports the counts.', 'linguator-pro' ),
				),
			),
			'required'   => array( 'slug' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		$slug   = isset( $input['slug'] ) ? sanitize_key( (string) $input['slug'] ) : '';
		$result = Language_Service::delete( $slug, ! empty( $input['force'] ) && rest_sanitize_boolean( $input['force'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'             => true,
			'posts_left_unlinked' => $result['posts_left_unlinked'],
			'terms_left_unlinked' => $result['terms_left_unlinked'],
			'message'             => sprintf(
				/* translators: %s: language slug. */
				__( 'Language "%s" was deleted.', 'linguator-pro' ),
				$slug
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
