<?php
/**
 * String listing ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Strings;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Content_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Language_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\String_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists registered strings with their translation in one language.
 *
 * @since 1.5.0
 */
final class Get_Strings extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-strings';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Strings', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: list theme, plugin and admin-text strings from WPML String Translation with their current translation in one language. By default only strings still needing a translation are returned; status "needs_update" finds strings whose original changed after they were translated. Each item has a "string_id": translate "value" and pass the text back to translate-strings under that ID. Keep placeholders such as %s, %1$s and {name}, and HTML tags, exactly as they appear in "value". Call get-string-groups first to pick a domain.', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Language code to read translations in, for example "hi". Must be one of the codes from list-languages.', 'wpml-translation-check-pro' ),
				),
				'domain'          => array(
					'type'        => 'string',
					'description' => __( 'Limit to one domain from get-string-groups, for example "astra". Omit for every domain.', 'wpml-translation-check-pro' ),
				),
				'status'          => array(
					'type'        => 'string',
					'enum'        => String_Service::STATUS_FILTERS,
					'default'     => 'untranslated',
					'description' => __( '"untranslated" (the default) for strings with no finished translation, "needs_update" for translations whose original changed, "translated" for finished ones, or "all".', 'wpml-translation-check-pro' ),
				),
				'search'          => array(
					'type'        => 'string',
					'description' => __( 'Optional text to find in the original string.', 'wpml-translation-check-pro' ),
				),
				'limit'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum items to return. Defaults to 20, maximum 100.', 'wpml-translation-check-pro' ),
					'default'     => Content_Service::DEFAULT_LIMIT,
					'minimum'     => 1,
					'maximum'     => Content_Service::MAX_LIMIT,
				),
				'offset'          => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to skip for pagination. Defaults to 0.', 'wpml-translation-check-pro' ),
					'default'     => 0,
					'minimum'     => 0,
				),
			),
			'required'   => array( 'target_language' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'target_language' => array( 'type' => 'string' ),
				'domain'          => array( 'type' => 'string' ),
				'status'          => array( 'type' => 'string' ),
				'items'           => array( 'type' => 'array' ),
				'total'           => array( 'type' => 'integer' ),
				'has_more'        => array( 'type' => 'boolean' ),
				'notice'          => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error String list, or an error.
	 */
	public static function execute( array $input = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$language = isset( $input['target_language'] ) ? sanitize_text_field( (string) $input['target_language'] ) : '';

		if ( ! Language_Service::is_active( $language ) ) {
			return Language_Service::inactive_error( $language );
		}

		$status = isset( $input['status'] ) ? (string) $input['status'] : 'untranslated';

		if ( ! in_array( $status, String_Service::STATUS_FILTERS, true ) ) {
			$status = 'untranslated';
		}

		$limit  = isset( $input['limit'] ) ? absint( $input['limit'] ) : Content_Service::DEFAULT_LIMIT;
		$limit  = max( 1, min( Content_Service::MAX_LIMIT, $limit ) );
		$offset = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		return String_Service::get_strings(
			$language,
			isset( $input['domain'] ) ? sanitize_text_field( (string) $input['domain'] ) : '',
			$status,
			isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '',
			$limit,
			$offset
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Matches the capability of AutoMLP's own String Translation bulk screen.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
