<?php
/**
 * Glossary write ability.
 *
 * @package AutoPoly
 * @since   1.11.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Glossary;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Glossary_Service;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds, updates and deletes glossary entries.
 *
 * @since 1.11.0
 */
final class Manage_Glossary extends Abstract_Ability {

	/**
	 * Supported actions.
	 *
	 * @since 1.11.0
	 * @var string[]
	 */
	const ACTIONS = array( 'add', 'update', 'delete' );

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'manage-glossary';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Manage Glossary', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.11.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: add, update or delete an entry in the site glossary of mandated term translations. Entries written here also govern AutoPoly\'s own translation, so they affect the whole site, not just this session. Use "add" for a new term or to add languages to an existing one, "update" to change a translation that already exists, and "delete" to remove a term and all its translations. Call get-glossary first to see what is already recorded.', 'autopoly-ai-translation-for-polylang-pro' );
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
				'action'          => array(
					'type'        => 'string',
					'enum'        => self::ACTIONS,
					'description' => __( 'The operation to perform: "add", "update" or "delete".', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'term'            => array(
					'type'        => 'string',
					'description' => __( 'The original term, in the source language. Together with source_lang this identifies the entry for every action.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'source_lang'     => array(
					'type'        => 'string',
					'description' => __( 'Language slug the original term is written in, for example "en".', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'translations'    => array(
					/*
					 * See the note on Get_Glossary::target_langs. rest_is_object()
					 * rejects a JSON string outright rather than mangling it, so
					 * this union only widens what is accepted; execute() decodes.
					 */
					'type'                 => array( 'string', 'object' ),
					'additionalProperties' => array( 'type' => 'string' ),
					'description'          => __( 'Translations keyed by language slug, for example {"hi":"कार्ट","fr":"Panier"}. Required for "add" and "update", ignored for "delete". On "update", supplied languages are merged over the stored ones; pass an empty string to remove a single language.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'type'            => array(
					'type'        => 'string',
					'enum'        => Glossary_Service::ENTRY_TYPES,
					'description' => __( 'Entry type. Use "name" for proper nouns such as brand or product names, "general" for ordinary terminology. Defaults to "general" on add, and is left unchanged on update when omitted.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'description'     => array(
					'type'        => 'string',
					'description' => __( 'Optional context explaining how the term should be used. Left unchanged on update when omitted.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'new_term'        => array(
					'type'        => 'string',
					'description' => __( 'Rename the entry\'s original term. Only used by "update".', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'new_source_lang' => array(
					'type'        => 'string',
					'description' => __( 'Change the entry\'s source language slug. Only used by "update".', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'replace_translations' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, "update" discards every stored translation and keeps only those supplied. Defaults to false, which merges instead.', 'autopoly-ai-translation-for-polylang-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'action', 'term', 'source_lang' ),
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
	 * Deleting an entry destroys its translations, so this cannot be marked
	 * non-destructive even though add and update are recoverable.
	 *
	 * @since 1.11.0
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
	 * @since 1.11.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		$action      = isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : '';
		$term        = isset( $input['term'] ) ? (string) $input['term'] : '';
		$source_lang = isset( $input['source_lang'] ) ? (string) $input['source_lang'] : '';

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new WP_Error(
				'atfpp_glossary_invalid_action',
				sprintf(
					/* translators: %s: comma-separated list of supported actions. */
					__( 'Unknown action. Supported actions are: %s.', 'autopoly-ai-translation-for-polylang-pro' ),
					implode( ', ', self::ACTIONS )
				)
			);
		}

		if ( 'delete' === $action ) {
			return Glossary_Service::delete_entry( $term, $source_lang );
		}

		$translations = isset( $input['translations'] ) ? $input['translations'] : array();

		/*
		 * REST hands associative data back as an array or an stdClass depending
		 * on how the client encoded it, and a client that stringified the whole
		 * parameter sends JSON text.
		 */
		if ( is_object( $translations ) ) {
			$translations = get_object_vars( $translations );
		} elseif ( is_string( $translations ) ) {
			$decoded = json_decode( $translations, true );

			$translations = is_array( $decoded ) ? $decoded : $translations;
		}

		if ( ! is_array( $translations ) ) {
			return new WP_Error(
				'atfpp_glossary_invalid_input',
				__( 'The "translations" parameter must be an object keyed by language slug, for example {"fr":"Panier"}.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		if ( 'add' === $action ) {
			return Glossary_Service::add_entry(
				$term,
				$source_lang,
				$translations,
				isset( $input['type'] ) ? (string) $input['type'] : Glossary_Service::DEFAULT_TYPE,
				isset( $input['description'] ) ? (string) $input['description'] : ''
			);
		}

		// Null preserves the stored value; an empty string would blank it.
		return Glossary_Service::update_entry(
			$term,
			$source_lang,
			$translations,
			array_key_exists( 'type', $input ) ? (string) $input['type'] : null,
			array_key_exists( 'description', $input ) ? (string) $input['description'] : null,
			isset( $input['new_term'] ) ? (string) $input['new_term'] : '',
			isset( $input['new_source_lang'] ) ? (string) $input['new_source_lang'] : '',
			! empty( $input['replace_translations'] )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Administrator-only, matching AutoPoly's own glossary write endpoints. The
	 * glossary governs translation across the whole site, so it is a settings
	 * change rather than a content edit.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
