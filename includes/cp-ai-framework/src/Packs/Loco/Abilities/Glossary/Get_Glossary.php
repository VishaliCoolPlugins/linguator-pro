<?php
/**
 * Glossary read ability.
 *
 * @package LocoAI
 * @since   2.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Loco\Abilities\Glossary;

use CoolPlugins\AiFramework\Packs\Loco\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Loco\Services\Glossary_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the terms the site owner wants translated a particular way.
 *
 * @since 2.5.0
 */
final class Get_Glossary extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-glossary';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Glossary', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'LocoAI: read the site glossary, the terms the owner wants translated a particular way. Call this before save-translations and follow every entry that applies: the listed translation is mandatory wherever the term appears in the source text, including its capitalisation. Entries of type "name" are proper nouns and are normally carried over unchanged unless a translation is given. Filter with "source_lang" (the language the terms are written in, English for almost every plugin), "target_langs" (the locales you are translating into), "type" and "search". Returns 100 entries per page; use "offset" when "has_more" is true.', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The default lets a call with no input pass validation, and the (object)
	 * cast serialises as {} rather than [] for strict MCP clients.
	 *
	 * @since 2.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return [
			'type'       => 'object',
			'default'    => (object) [],
			'properties' => [
				'source_lang'  => [
					'type'        => 'string',
					'description' => __( 'Only entries whose terms are written in this language, for example "en_US". Omit for all.', 'loco-automatic-translate-addon-pro' ),
				],
				'target_langs' => [
					/*
					 * "string" is listed first deliberately. Some MCP clients forward an
					 * array as its JSON text; rest_is_array() accepts that scalar and
					 * wp_parse_list() then splits it on commas and whitespace, shredding
					 * the JSON. Matching "string" first hands the raw text to execute(),
					 * which decodes it properly.
					 */
					'type'        => [ 'string', 'array' ],
					'items'       => [ 'type' => 'string' ],
					'description' => __( 'Locales you are translating into, for example ["fr_FR","de_DE"]. Entries with no translation in any of them are left out. Omit for all.', 'loco-automatic-translate-addon-pro' ),
				],
				'type'         => [
					'type'        => 'string',
					'enum'        => Glossary_Service::ENTRY_TYPES,
					'description' => __( '"name" for proper nouns, "general" for ordinary terms. Omit for both.', 'loco-automatic-translate-addon-pro' ),
				],
				'search'       => [
					'type'        => 'string',
					'description' => __( 'Case-insensitive filter on the term.', 'loco-automatic-translate-addon-pro' ),
				],
				'limit'        => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => Glossary_Service::MAX_LIMIT,
					'default'     => 100,
					'description' => __( 'Entries per page, at most 500.', 'loco-automatic-translate-addon-pro' ),
				],
				'offset'       => [
					'type'        => 'integer',
					'minimum'     => 0,
					'default'     => 0,
					'description' => __( 'Entries to skip, for paging through a large glossary.', 'loco-automatic-translate-addon-pro' ),
				],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.5.0
	 * @param mixed $input Validated input.
	 * @return array<string, mixed> Listing.
	 */
	public static function execute( $input = [] ) {
		$input = self::to_array( $input );

		$result = Glossary_Service::get_entries(
			self::input_string( $input, 'source_lang' ),
			self::normalize_list( isset( $input['target_langs'] ) ? $input['target_langs'] : null ),
			self::input_string( $input, 'type' ),
			self::input_string( $input, 'search' ),
			// Schema defaults are not applied to object properties, so the
			// defaults live here; see Abstract_Ability::input_int().
			self::input_int( $input, 'limit', 100, 1, Glossary_Service::MAX_LIMIT ),
			max( 0, self::input_int( $input, 'offset', 0, 0, PHP_INT_MAX ) )
		);

		$result['notice'] = [] === $result['entries']
			? __( 'No glossary entries matched. Translate normally.', 'loco-automatic-translate-addon-pro' )
			: __( 'These translations are mandatory. Whenever one of these terms appears in the source text, use the listed translation for that language exactly, including its capitalisation. Entries of type "name" are proper nouns and should normally be carried over unchanged unless a translation is given.', 'loco-automatic-translate-addon-pro' );

		return $result;
	}

	/**
	 * Coerces a list input that may arrive as an array, JSON text or a list.
	 *
	 * Kept private here because this is the only ability that takes one, the
	 * same way Save_Translations keeps normalize_entries() to itself.
	 *
	 * @since 2.5.0
	 * @param mixed $value Raw input value.
	 * @return string[] List of trimmed, non-empty strings.
	 */
	private static function normalize_list( $value ) {
		if ( is_array( $value ) ) {
			$items = $value;
		} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
			$value = trim( $value );

			if ( 0 === strpos( $value, '[' ) ) {
				$decoded = json_decode( $value, true );
				$items   = is_array( $decoded ) ? $decoded : explode( ',', $value );
			} else {
				$items = explode( ',', $value );
			}
		} else {
			return [];
		}

		$list = [];

		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = trim( (string) $item );

			if ( '' !== $item ) {
				$list[] = $item;
			}
		}

		return $list;
	}
}
