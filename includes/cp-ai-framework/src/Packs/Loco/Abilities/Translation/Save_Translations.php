<?php
/**
 * Translation saving ability.
 *
 * @package LocoAI
 * @since   2.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Loco\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Loco\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Loco\Services\Loco_Guard;
use CoolPlugins\AiFramework\Packs\Loco\Services\Project_Service;
use CoolPlugins\AiFramework\Packs\Loco\Services\String_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes translations into a language file and recompiles it.
 *
 * @since 2.3.0
 */
final class Save_Translations extends Abstract_Ability {

	/**
	 * Most entries accepted in one call.
	 *
	 * @since 2.3.0
	 * @var int
	 */
	const MAX_ENTRIES = 200;

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'save-translations';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Save Translations', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'LocoAI: save up to 200 translations into a language file, then recompile its MO, PHP and JSON files so the site shows them immediately. Loco Translate keeps a backup of the previous PO file. Match each entry by "source" and "context" exactly as get-strings returned them; send "translation" for normal strings and "translations" (one per plural form) for plural strings. Complete translations are skipped unless "overwrite" is true. Entries whose placeholders or HTML tags differ from the source are rejected and listed with a reason, while the valid entries are still saved; fix and resend only the rejected ones. Set "fuzzy" to mark a translation as needing human review.', 'loco-automatic-translate-addon-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return [
			'type'       => 'object',
			'properties' => $this->project_properties() + [
				'locale'                     => $this->locale_property(),
				'location'                   => $this->location_property(
					__( 'Which file to update when several exist for the language. Default: the custom file, then system, then author.', 'loco-automatic-translate-addon-pro' ),
					[ 'custom', 'system', 'author', 'other' ]
				),
				'translations'               => [
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => self::MAX_ENTRIES,
					'description' => __( 'Translations to save.', 'loco-automatic-translate-addon-pro' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'source'       => [
								'type'        => 'string',
								'description' => __( 'Source text, exactly as returned by get-strings.', 'loco-automatic-translate-addon-pro' ),
							],
							'context'      => [
								'type'        => 'string',
								'description' => __( 'Context, exactly as returned by get-strings. Omit or send "" when it was empty.', 'loco-automatic-translate-addon-pro' ),
							],
							'translation'  => [
								'type'        => 'string',
								'description' => __( 'Translation of a string without plural forms.', 'loco-automatic-translate-addon-pro' ),
							],
							'translations' => [
								'type'        => 'array',
								'items'       => [ 'type' => 'string' ],
								'description' => __( 'Plural strings only: one translation per plural form, in the order of plural_labels from get-strings.', 'loco-automatic-translate-addon-pro' ),
							],
							'fuzzy'        => [
								'type'        => 'boolean',
								'description' => __( 'Mark as needing review. Default false, which also clears an existing fuzzy flag.', 'loco-automatic-translate-addon-pro' ),
							],
						],
						'required'   => [ 'source' ],
					],
				],
				'overwrite'                  => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Replace translations that are already complete. Default false; fuzzy and untranslated strings are always updated.', 'loco-automatic-translate-addon-pro' ),
				],
				'allow_placeholder_mismatch' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Accept translations whose printf placeholders or HTML tag counts differ from the source. Only for intentional differences. HTML tags that are not in the source are always rejected.', 'loco-automatic-translate-addon-pro' ),
				],
			],
			'required'   => [ 'bundle', 'locale', 'translations' ],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return [
			'annotations'  => [
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.3.0
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result, or an error.
	 */
	public static function execute( $input = [] ) {
		if ( ! Loco_Guard::is_available() ) {
			return Loco_Guard::unavailable_error();
		}

		$input   = self::to_array( $input );
		$entries = self::normalize_entries( isset( $input['translations'] ) ? $input['translations'] : null );

		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$bundle = Loco_Guard::resolve_bundle( self::input_string( $input, 'bundle' ) );

		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		$project = Loco_Guard::resolve_project( $bundle, self::input_string( $input, 'domain' ) );

		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$locale = Loco_Guard::resolve_locale( self::input_string( $input, 'locale' ) );

		if ( is_wp_error( $locale ) ) {
			return $locale;
		}

		$location = self::input_string( $input, 'location' );

		if ( ! in_array( $location, Loco_Guard::LOCATION_ORDER, true ) ) {
			$location = '';
		}

		$selection = Project_Service::pick_po_file( $project, $locale, $location );

		if ( is_wp_error( $selection ) ) {
			return $selection;
		}

		return String_Service::save_translations(
			$bundle,
			$project,
			$selection['file'],
			$locale,
			$entries,
			self::input_bool( $input, 'overwrite', false ),
			self::input_bool( $input, 'allow_placeholder_mismatch', false )
		);
	}

	/**
	 * Normalises the translations input into a predictable shape.
	 *
	 * Source and context are kept byte-for-byte, because they identify the
	 * entry. Translations are kept as sent; String_Service validates them.
	 *
	 * @since 2.3.0
	 * @param mixed $raw Raw "translations" input.
	 * @return array<int, array<string, mixed>>|\WP_Error Entries, or an error.
	 */
	private static function normalize_entries( $raw ) {
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : null;
		}

		if ( ! is_array( $raw ) || [] === $raw ) {
			return new \WP_Error(
				'atlt_translations_required',
				__( 'The "translations" input must be a non-empty array of objects with "source" and "translation" (or "translations" for plural strings).', 'loco-automatic-translate-addon-pro' )
			);
		}

		if ( count( $raw ) > self::MAX_ENTRIES ) {
			return new \WP_Error(
				'atlt_translations_too_many',
				sprintf(
					/* translators: %d: maximum number of entries. */
					__( 'At most %d translations can be saved per call. Split them into several calls.', 'loco-automatic-translate-addon-pro' ),
					self::MAX_ENTRIES
				)
			);
		}

		$entries = [];

		foreach ( array_values( $raw ) as $position => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['source'] ) || ! is_string( $item['source'] ) || '' === $item['source'] ) {
				return new \WP_Error(
					'atlt_translation_entry_invalid',
					sprintf(
						/* translators: %d: 1-based position of the entry. */
						__( 'Translation entry %d has no "source" text. Every entry needs "source", copied exactly from get-strings.', 'loco-automatic-translate-addon-pro' ),
						$position + 1
					)
				);
			}

			$translations = null;

			if ( isset( $item['translations'] ) && is_array( $item['translations'] ) ) {
				$translations = array_values( $item['translations'] );
			}

			$entries[] = [
				'source'       => $item['source'],
				'context'      => isset( $item['context'] ) && is_scalar( $item['context'] ) ? (string) $item['context'] : '',
				'translation'  => isset( $item['translation'] ) && is_scalar( $item['translation'] ) ? (string) $item['translation'] : null,
				'translations' => $translations,
				'fuzzy'        => isset( $item['fuzzy'] ) && true === filter_var( $item['fuzzy'], FILTER_VALIDATE_BOOLEAN ),
			];
		}

		return $entries;
	}
}
