<?php
/**
 * Glossary read ability.
 *
 * @package CoolPluginsAiFramework
 * @since 1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Glossary;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Glossary_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the site's mandated term translations.
 *
 * @since 1.2.0
 */
final class Get_Glossary extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-glossary';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Glossary', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: read the site glossary, which lists terms the site owner requires to be translated a specific way (brand names, product names, industry terminology). Call this before translating any content and apply the listed translations exactly; a term marked "name" should usually be left unchanged. Linguator\'s own translation tools and menu synchronization use this glossary, so skipping it produces content that disagrees with the rest of the site. Pass source_lang and target_langs to get just the pairs relevant to one translation job.', 'linguator-pro' );
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
				'source_lang'  => array(
					'type'        => 'string',
					'description' => __( 'Only return entries whose original term is in this language, for example "en". Omit for all languages.', 'linguator-pro' ),
				),
				'target_langs' => array(
					/*
					 * "string" is listed first deliberately. Some MCP clients
					 * forward an array as its JSON text; rest_is_array() accepts
					 * that scalar and wp_parse_list() then shreds it on both
					 * commas and whitespace, so the array branch would receive
					 * fragments of JSON. Matching "string" first hands the raw
					 * text to execute(), which decodes it properly.
					 */
					'type'        => array( 'string', 'array' ),
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Only return translations into these language slugs, for example ["hi","fr"]. Entries with no translation in any of them are omitted. Omit for all languages.', 'linguator-pro' ),
				),
				'type'         => array(
					'type'        => 'string',
					'enum'        => Glossary_Service::ENTRY_TYPES,
					'description' => __( 'Filter by entry type. "name" marks proper nouns that normally stay untranslated; "general" marks ordinary terminology.', 'linguator-pro' ),
				),
				'search'       => array(
					'type'        => 'string',
					'description' => __( 'Only return entries whose term contains this text, matched case-insensitively. Useful on large glossaries.', 'linguator-pro' ),
				),
				'limit'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum entries to return. Defaults to 100, maximum 500.', 'linguator-pro' ),
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 500,
				),
				'offset'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of entries to skip for pagination. Defaults to 0.', 'linguator-pro' ),
					'default'     => 0,
					'minimum'     => 0,
				),
			),
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
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed> Entries, total, has_more and usage guidance.
	 */
	public static function execute( array $input = array() ) {
		$targets = self::normalize_list(
			isset( $input['target_langs'] ) ? $input['target_langs'] : array()
		);

		$result = Glossary_Service::get_entries(
			isset( $input['source_lang'] ) ? (string) $input['source_lang'] : '',
			$targets,
			isset( $input['type'] ) ? (string) $input['type'] : '',
			isset( $input['search'] ) ? (string) $input['search'] : '',
			isset( $input['limit'] ) ? (int) $input['limit'] : 100,
			isset( $input['offset'] ) ? (int) $input['offset'] : 0
		);

		if ( array() === $result['entries'] ) {
			$result['notice'] = __( 'No glossary entries matched. Translate normally.', 'linguator-pro' );

			return $result;
		}

		$result['notice'] = __( 'These translations are mandatory. Whenever one of these terms appears in the source text, use the listed translation for that language exactly, including capitalisation. Entries of type "name" are proper nouns and should normally be carried over unchanged unless a translation is given.', 'linguator-pro' );

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Matches the edit_posts check on Linguator's own glossary screen;
	 * anyone who may translate content may read the terms governing it.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'edit_posts', $input );
	}
}
