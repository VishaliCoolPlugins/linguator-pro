<?php
/**
 * Glossary write ability.
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
 * Adds, updates and deletes glossary entries.
 *
 * @since 2.5.0
 */
final class Manage_Glossary extends Abstract_Ability {

	/**
	 * Operations this ability performs.
	 *
	 * @since 2.5.0
	 * @var string[]
	 */
	const ACTIONS = [ 'add', 'update', 'delete' ];

	/**
	 * Capability required to change the glossary.
	 *
	 * The glossary governs translation across the whole site, so it is a
	 * settings change rather than a translation edit. This matches the
	 * manage_options check on LocoAI's own glossary admin endpoints; the
	 * default loco_admin would be a wider surface than the Glossary tab.
	 *
	 * @since 2.5.0
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'manage-glossary';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Manage Glossary', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'LocoAI: add, update or delete one glossary entry. An entry is identified by "term" plus "source_lang" for every action. "source_lang" is the language the term itself is written in, and should be English ("en_US") for almost every plugin or theme, because that is the language of their source strings; entries in another source language are stored but never applied when translating. Languages must be ones this site has. "add" creates an entry and needs a translation in every language of the site other than the source language; when the term stays the same in a language (a brand name, a shared word), send the term itself for that language. Use "update" to change a translation that already exists. "update" merges the translations you send onto the stored ones, leaves "type" and "description" alone when you omit them, removes a language when you send an empty string for it, and renames through "new_term". Only call "delete" when the user asked for that entry to be removed.', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'action'               => [
					'type'        => 'string',
					'enum'        => self::ACTIONS,
					'description' => __( '"add", "update" or "delete".', 'loco-automatic-translate-addon-pro' ),
				],
				'term'                 => [
					'type'        => 'string',
					'description' => __( 'The source text to look for, exactly as it appears in the strings being translated, for example "Cart".', 'loco-automatic-translate-addon-pro' ),
				],
				'source_lang'          => [
					'type'        => 'string',
					'description' => __( 'The language the term is written in, normally "en_US".', 'loco-automatic-translate-addon-pro' ),
				],
				'translations'         => [
					/*
					 * "string" first for the same reason as get-glossary: a client that
					 * forwards an object as JSON text must not have it mangled before
					 * execute() can decode it.
					 */
					'type'                 => [ 'string', 'object' ],
					'additionalProperties' => [ 'type' => 'string' ],
					'description'          => __( 'Translations keyed by locale, for example {"fr_FR":"Panier"}. Required for "add" and "update", ignored for "delete".', 'loco-automatic-translate-addon-pro' ),
				],
				'type'                 => [
					'type'        => 'string',
					'enum'        => Glossary_Service::ENTRY_TYPES,
					'description' => __( '"name" for a proper noun, "general" otherwise. Defaults to "general" on add; left unchanged on update when omitted.', 'loco-automatic-translate-addon-pro' ),
				],
				'description'          => [
					'type'        => 'string',
					'description' => __( 'A note explaining the term, passed to the translation model. Left unchanged on update when omitted; send "" to clear it.', 'loco-automatic-translate-addon-pro' ),
				],
				'new_term'             => [
					'type'        => 'string',
					'description' => __( 'Update only: rename the entry to this term.', 'loco-automatic-translate-addon-pro' ),
				],
				'new_source_lang'      => [
					'type'        => 'string',
					'description' => __( 'Update only: change the language the term is written in.', 'loco-automatic-translate-addon-pro' ),
				],
				'replace_translations' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Update only. Default false, which merges what you send onto the stored translations. True discards every stored translation first, so send the complete set.', 'loco-automatic-translate-addon-pro' ),
				],
			],
			'required'   => [ 'action', 'term', 'source_lang' ],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * Destructive because the same tool deletes entries, even though add and
	 * update are recoverable. Not idempotent: a repeated add is refused rather
	 * than repeated.
	 *
	 * @since 2.5.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return [
			'annotations'  => [
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			],
			'show_in_rest' => true,
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.5.0
	 * @param mixed $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( $input = [] ) {
		return static::user_can( self::CAPABILITY, self::to_array( $input ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.5.0
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result, or an error.
	 */
	public static function execute( $input = [] ) {
		if ( ! Glossary_Service::is_available() ) {
			return Glossary_Service::unavailable_error();
		}

		$input       = self::to_array( $input );
		$action      = strtolower( self::input_string( $input, 'action' ) );
		$term        = self::input_string( $input, 'term' );
		$source_lang = self::input_string( $input, 'source_lang' );

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new \WP_Error(
				'atlt_glossary_invalid_action',
				sprintf(
					/* translators: %s: comma-separated list of actions. */
					__( 'The "action" value must be one of: %s.', 'loco-automatic-translate-addon-pro' ),
					implode( ', ', self::ACTIONS )
				)
			);
		}

		if ( 'delete' === $action ) {
			return Glossary_Service::delete_entry( $term, $source_lang );
		}

		$translations = self::normalize_translations( isset( $input['translations'] ) ? $input['translations'] : null );

		if ( is_wp_error( $translations ) ) {
			return $translations;
		}

		if ( 'add' === $action ) {
			return Glossary_Service::add_entry(
				$term,
				$source_lang,
				$translations,
				self::input_string( $input, 'type', Glossary_Service::DEFAULT_TYPE ),
				self::input_string( $input, 'description' )
			);
		}

		// Absent means "keep what is stored", so these use array_key_exists()
		// rather than isset(): an empty string is a deliberate blanking.
		$type        = array_key_exists( 'type', $input ) ? self::input_string( $input, 'type' ) : null;
		$description = array_key_exists( 'description', $input ) ? self::input_string( $input, 'description' ) : null;

		return Glossary_Service::update_entry(
			$term,
			$source_lang,
			$translations,
			$type,
			$description,
			self::input_string( $input, 'new_term' ),
			self::input_string( $input, 'new_source_lang' ),
			self::input_bool( $input, 'replace_translations', false )
		);
	}

	/**
	 * Coerces the translations input into a language-keyed array.
	 *
	 * Accepts a real map, an object from a JSON body, or the JSON text a client
	 * may forward instead of an object.
	 *
	 * @since 2.5.0
	 * @param mixed $value Raw input value.
	 * @return array<string, string>|\WP_Error Map, or an error.
	 */
	private static function normalize_translations( $value ) {
		if ( null === $value || '' === $value ) {
			return [];
		}

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : null;
		}

		if ( ! is_array( $value ) ) {
			return new \WP_Error(
				'atlt_glossary_invalid_input',
				__( 'The "translations" value must be an object keyed by locale, for example {"fr_FR":"Panier"}.', 'loco-automatic-translate-addon-pro' )
			);
		}

		$map = [];

		foreach ( $value as $code => $translated ) {
			if ( ! is_scalar( $translated ) && null !== $translated ) {
				continue;
			}

			$map[ (string) $code ] = null === $translated ? '' : sanitize_text_field( (string) $translated );
		}

		return $map;
	}
}
