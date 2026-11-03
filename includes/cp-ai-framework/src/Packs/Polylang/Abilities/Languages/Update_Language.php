<?php
/**
 * Language update ability.
 *
 * @package AutoPoly
 * @since   1.11.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Languages;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Language_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Edits an existing language's name, slug, locale, flag, direction or order.
 *
 * @since 1.11.0
 */
final class Update_Language extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'update-language';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Update Language', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: change an existing Polylang language. Identify it by its current "slug", then supply only the fields to change; anything omitted is left as it is. Use this rather than deleting and recreating a language: renaming through here migrates the slug across post and term translations, widgets and menu locations, whereas deleting a language unlinks its content. Changing "new_slug" alters the URLs of every page in that language. This does not change which language is the site default.', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'slug'     => array(
					'type'        => 'string',
					'description' => __( 'Current slug of the language to update, for example "fr". This identifies the language and is never itself changed; use new_slug to rename.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'name'     => array(
					'type'        => 'string',
					'description' => __( 'Display name shown in language switchers, for example "Francais". Cannot be empty.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'new_slug' => array(
					'type'        => 'string',
					'description' => __( 'New slug for the language. This is part of every translated URL, so changing it changes those permalinks. Polylang migrates existing translations, widgets and menu locations to the new slug.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'locale'   => array(
					'type'        => 'string',
					'description' => __( 'WordPress locale, for example "fr_FR". Must be a locale WordPress recognises, or its translation files will not load.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'rtl'      => array(
					'type'        => 'boolean',
					'description' => __( 'True for a right-to-left language such as Arabic or Hebrew.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'flag'     => array(
					'type'        => 'string',
					'description' => __( 'Country code for the flag icon, for example "fr" or "in". Call list-languages to see the current value in the "flag" field; "flag_url" is a rendered image and cannot be sent back.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'order'    => array(
					'type'        => 'integer',
					'description' => __( 'Position in a list of languages. Lower sorts first.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
			),
			'required'   => array( 'slug' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Not destructive: nothing is removed, and Polylang migrates references
	 * rather than dropping them. Not idempotent either, because a repeated
	 * rename no longer finds the language under its original slug.
	 *
	 * @since 1.11.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Language descriptor, or an error.
	 */
	public static function execute( array $input = array() ) {
		$result = Language_Service::update( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload = array(
			'success'  => true,
			'language' => $result,
			'message'  => sprintf(
				/* translators: %s: language slug. */
				__( 'Language "%s" was updated.', 'autopoly-ai-translation-for-polylang-pro' ),
				$result['slug']
			),
		);

		$old_slug = isset( $input['slug'] ) ? sanitize_key( (string) $input['slug'] ) : '';

		if ( '' !== $old_slug && $old_slug !== $result['slug'] ) {
			$payload['slug_changed'] = true;
			$payload['notice']       = sprintf(
				/* translators: 1: previous language slug, 2: new language slug. */
				__( 'The slug changed from "%1$s" to "%2$s", so the URLs of every page in this language have changed. Existing translations, widgets and menu locations were migrated by Polylang, but any hardcoded links or redirects pointing at the old slug need updating yourself.', 'autopoly-ai-translation-for-polylang-pro' ),
				$old_slug,
				$result['slug']
			);
		}

		return $payload;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Administrator-only, matching create-language and delete-language. This
	 * changes site structure rather than content.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
