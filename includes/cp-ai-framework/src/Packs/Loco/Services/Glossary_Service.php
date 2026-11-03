<?php
/**
 * Glossary reads and writes for the abilities.
 *
 * @package LocoAI
 * @since   2.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Loco\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes glossary entries through Atlt_Glossary.
 *
 * Every read and write is delegated to Atlt_Glossary's public static methods so
 * that the abilities, the admin Glossary tab and the translation prompt share
 * one storage format and one set of rules. This service never touches the
 * atlt_glossary_data option directly, and changes nothing in that class.
 *
 * It exists because the stored shape is not the shape to expose:
 *
 *   original_term          -> term
 *   original_language_code -> source_lang
 *   kind                   -> type
 *   [{target_language_code, translated_term}] -> {"fr_FR": "..."}
 *
 * @since 2.5.0
 */
final class Glossary_Service {

	/**
	 * Entry kinds the glossary understands.
	 *
	 * @since 2.5.0
	 * @var string[]
	 */
	const ENTRY_TYPES = [ 'general', 'name' ];

	/**
	 * Kind used when the caller does not choose one.
	 *
	 * @since 2.5.0
	 * @var string
	 */
	const DEFAULT_TYPE = 'general';

	/**
	 * Largest page a caller may request.
	 *
	 * @since 2.5.0
	 * @var int
	 */
	const MAX_LIMIT = 500;

	/**
	 * Whether the glossary storage class is loaded.
	 *
	 * The glossary is option storage plus the installed language list, so it
	 * does not need Loco Translate the way every other ability does.
	 *
	 * @since 2.5.0
	 * @return bool True when the glossary is usable.
	 */
	public static function is_available() {
		return class_exists( 'Atlt_Glossary' );
	}

	/**
	 * Error returned when the glossary class is missing.
	 *
	 * @since 2.5.0
	 * @return WP_Error Error describing the situation.
	 */
	public static function unavailable_error() {
		return new WP_Error(
			'atlt_glossary_unavailable',
			__( 'The LocoAI glossary is not available on this site. Tell the user to check that the LocoAI plugin is fully installed; no ability can fix this.', 'loco-automatic-translate-addon-pro' )
		);
	}

	/**
	 * Lists glossary entries, filtered and paginated.
	 *
	 * @since 2.5.0
	 * @param string   $source_lang  Optional source language filter.
	 * @param string[] $target_langs Optional target languages to keep.
	 * @param string   $type         Optional kind filter.
	 * @param string   $search       Optional case-insensitive filter on the term.
	 * @param int      $limit        Entries per page.
	 * @param int      $offset       Entries to skip.
	 * @return array<string, mixed> Listing with entries, total and has_more.
	 */
	public static function get_entries( $source_lang = '', array $target_langs = [], $type = '', $search = '', $limit = 100, $offset = 0 ) {
		$empty = [
			'entries'  => [],
			'total'    => 0,
			'has_more' => false,
		];

		if ( ! self::is_available() ) {
			return $empty;
		}

		$entries = \Atlt_Glossary::get_all_glossaries();

		if ( ! is_array( $entries ) ) {
			return $empty;
		}

		// Reads stay forgiving: Loco hands this plugin bare language codes such
		// as "fr", so a caller is likely to pass one back. Writes are strict.
		$source_lang = self::normalize_locale( $source_lang );
		$type        = strtolower( trim( (string) $type ) );
		$search      = trim( (string) $search );
		$limit       = max( 1, min( (int) $limit, self::MAX_LIMIT ) );
		$offset      = max( 0, (int) $offset );

		$wanted = [];
		foreach ( $target_langs as $target ) {
			$normalized = self::normalize_locale( $target );

			if ( '' !== $normalized ) {
				$wanted[] = $normalized;
			}
		}

		$matched = [];

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['original_term'] ) ) {
				continue;
			}

			if ( '' !== $source_lang && ! self::locales_match( isset( $entry['original_language_code'] ) ? $entry['original_language_code'] : '', $source_lang ) ) {
				continue;
			}

			if ( '' !== $type && self::normalize_type( isset( $entry['kind'] ) ? $entry['kind'] : '' ) !== self::normalize_type( $type ) ) {
				continue;
			}

			if ( '' !== $search && stripos( (string) $entry['original_term'], $search ) === false ) {
				continue;
			}

			$shaped = self::reshape( $entry, $wanted );

			// An entry with nothing in the requested languages is noise for a
			// caller asking about one direction. Unfiltered calls keep everything.
			if ( [] !== $wanted && [] === $shaped['translations'] ) {
				continue;
			}

			$matched[] = $shaped;
		}

		$total = count( $matched );

		return [
			'entries'  => array_values( array_slice( $matched, $offset, $limit ) ),
			'total'    => $total,
			'has_more' => ( $offset + $limit ) < $total,
		];
	}

	/**
	 * One entry by its identity, in the exposed shape.
	 *
	 * The term is matched exactly first, then case-insensitively, because
	 * Atlt_Glossary::delete_glossary_data() compares case-insensitively while
	 * the other legacy methods do not. Callers pass the returned "term" back to
	 * those methods so both agree on the spelling.
	 *
	 * @since 2.5.0
	 * @param string $term        Glossary term.
	 * @param string $source_lang Source language of the entry.
	 * @return array<string, mixed>|null Entry, or null when absent.
	 */
	public static function find_entry( $term, $source_lang ) {
		if ( ! self::is_available() ) {
			return null;
		}

		$entries = \Atlt_Glossary::get_all_glossaries();

		if ( ! is_array( $entries ) ) {
			return null;
		}

		$term     = (string) $term;
		$fallback = null;

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['original_term'] ) ) {
				continue;
			}

			if ( ! self::locales_match( isset( $entry['original_language_code'] ) ? $entry['original_language_code'] : '', $source_lang ) ) {
				continue;
			}

			if ( (string) $entry['original_term'] === $term ) {
				return self::reshape( $entry, [] );
			}

			if ( null === $fallback && 0 === strcasecmp( trim( (string) $entry['original_term'] ), trim( $term ) ) ) {
				$fallback = $entry;
			}
		}

		return null === $fallback ? null : self::reshape( $fallback, [] );
	}

	/**
	 * Creates a glossary entry.
	 *
	 * @since 2.5.0
	 * @param string                $term         Glossary term.
	 * @param string                $source_lang  Source language.
	 * @param array<string, string> $translations Translations keyed by language.
	 * @param string                $type         Entry kind.
	 * @param string                $description  Optional note for the model.
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	public static function add_entry( $term, $source_lang, array $translations, $type = self::DEFAULT_TYPE, $description = '' ) {
		if ( ! self::is_available() ) {
			return self::unavailable_error();
		}

		$term = self::validate_term( $term );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$source_lang = self::validate_locale( $source_lang, 'source_lang' );

		if ( is_wp_error( $source_lang ) ) {
			return $source_lang;
		}

		$translations = self::validate_translations( $translations, $source_lang );

		if ( is_wp_error( $translations ) ) {
			return $translations;
		}

		$missing = self::missing_target_languages( $translations, $source_lang );

		if ( [] !== $missing ) {
			return new WP_Error(
				'atlt_glossary_missing_languages',
				sprintf(
					/* translators: 1: comma-separated locale codes, 2: glossary term. */
					__( 'A new glossary entry needs a translation in every language of this site, as in the admin Glossary tab. Missing: %1$s. Send them in "translations". If the term stays the same in a language, such as a brand name or a word both languages share, send the term itself ("%2$s") for that language.', 'loco-automatic-translate-addon-pro' ),
					implode( ', ', $missing ),
					$term
				),
				[ 'missing_languages' => $missing ]
			);
		}

		if ( null !== self::find_entry( $term, $source_lang ) ) {
			return new WP_Error(
				'atlt_glossary_exists',
				sprintf(
					/* translators: 1: glossary term, 2: language code. */
					__( 'A glossary entry for "%1$s" in language "%2$s" already exists. Use action "update" to change it.', 'loco-automatic-translate-addon-pro' ),
					$term,
					$source_lang
				)
			);
		}

		$stored = \Atlt_Glossary::store_glossary_data(
			[
				'type'            => self::normalize_type( $type ),
				'term'            => $term,
				'description'     => (string) $description,
				'source_lang'     => $source_lang,
				// Parallel arrays, indexed 0..n-1: the legacy store pairs them by index.
				'target_lang'     => array_keys( $translations ),
				'translated_term' => array_values( $translations ),
			]
		);

		if ( ! $stored ) {
			return new WP_Error(
				'atlt_glossary_add_failed',
				sprintf(
					/* translators: %s: glossary term. */
					__( 'The glossary entry for "%s" could not be created. Tell the user to check the Glossary tab in LocoAI.', 'loco-automatic-translate-addon-pro' ),
					$term
				)
			);
		}

		return self::success_payload(
			'added',
			$term,
			$source_lang,
			sprintf(
				/* translators: %s: glossary term. */
				__( 'Glossary entry "%s" added.', 'loco-automatic-translate-addon-pro' ),
				$term
			)
		);
	}

	/**
	 * Updates an existing glossary entry.
	 *
	 * Translations are merged onto the stored ones unless $replace is true,
	 * because the admin form always posts every language while an AI client
	 * typically sends one. Sending an empty string for a language removes that
	 * translation.
	 *
	 * @since 2.5.0
	 * @param string                $term            Current term.
	 * @param string                $source_lang     Current source language.
	 * @param array<string, string> $translations    Translations to apply.
	 * @param string|null           $type            New kind, or null to keep.
	 * @param string|null           $description     New description, or null to keep.
	 * @param string                $new_term        Optional rename.
	 * @param string                $new_source_lang Optional new source language.
	 * @param bool                  $replace         Replace instead of merging.
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	public static function update_entry( $term, $source_lang, array $translations, $type = null, $description = null, $new_term = '', $new_source_lang = '', $replace = false ) {
		if ( ! self::is_available() ) {
			return self::unavailable_error();
		}

		$source_lang = self::validate_locale( $source_lang, 'source_lang' );

		if ( is_wp_error( $source_lang ) ) {
			return $source_lang;
		}

		$existing = self::find_entry( $term, $source_lang );

		if ( null === $existing ) {
			return self::not_found_error( $term, $source_lang );
		}

		// The stored spelling and language, so the legacy update matches the row.
		$stored_term   = $existing['term'];
		$stored_source = $existing['source_lang'];

		$target_term = $stored_term;

		if ( '' !== trim( (string) $new_term ) ) {
			$target_term = self::validate_term( $new_term );

			if ( is_wp_error( $target_term ) ) {
				return $target_term;
			}
		}

		$target_source = $stored_source;

		if ( '' !== trim( (string) $new_source_lang ) ) {
			$target_source = self::validate_locale( $new_source_lang, 'new_source_lang' );

			if ( is_wp_error( $target_source ) ) {
				return $target_source;
			}
		}

		// The legacy rename does no collision check and would create a second
		// entry with the same identity, which nothing can then reach.
		$renamed = $target_term !== $stored_term || ! self::locales_match( $target_source, $stored_source );

		if ( $renamed && null !== self::find_entry( $target_term, $target_source ) ) {
			return new WP_Error(
				'atlt_glossary_exists',
				sprintf(
					/* translators: 1: glossary term, 2: language code. */
					__( 'A glossary entry for "%1$s" in language "%2$s" already exists, so this entry cannot be renamed onto it. Delete one of them first.', 'loco-automatic-translate-addon-pro' ),
					$target_term,
					$target_source
				)
			);
		}

		$merged = $replace ? [] : $existing['translations'];

		foreach ( $translations as $code => $translated ) {
			$code = self::validate_locale( $code, 'translations' );

			if ( is_wp_error( $code ) ) {
				return $code;
			}

			if ( self::locales_match( $code, $target_source ) ) {
				continue;
			}

			$translated = trim( (string) $translated );

			if ( '' === $translated ) {
				unset( $merged[ $code ] );
				continue;
			}

			$merged[ $code ] = $translated;
		}

		if ( [] === $merged ) {
			return new WP_Error(
				'atlt_glossary_no_translations',
				__( 'An entry must keep at least one translation. Use action "delete" to remove the entry entirely.', 'loco-automatic-translate-addon-pro' )
			);
		}

		$updated = \Atlt_Glossary::update_glossary_data(
			[
				'type'        => null === $type ? $existing['type'] : self::normalize_type( $type ),
				'term'        => $target_term,
				'description' => null === $description ? $existing['description'] : (string) $description,
				'source_lang' => $target_source,
				// The old identity. The legacy method reads both without a default.
				'original_term'        => $stored_term,
				'original_source_lang' => $stored_source,
				'translations'         => $merged,
			]
		);

		if ( ! $updated ) {
			return new WP_Error(
				'atlt_glossary_update_failed',
				sprintf(
					/* translators: %s: glossary term. */
					__( 'The glossary entry for "%s" could not be updated. Tell the user to check the Glossary tab in LocoAI.', 'loco-automatic-translate-addon-pro' ),
					$stored_term
				)
			);
		}

		return self::success_payload(
			'updated',
			$target_term,
			$target_source,
			sprintf(
				/* translators: %s: glossary term. */
				__( 'Glossary entry "%s" updated.', 'loco-automatic-translate-addon-pro' ),
				$target_term
			)
		);
	}

	/**
	 * Deletes a glossary entry.
	 *
	 * @since 2.5.0
	 * @param string $term        Glossary term.
	 * @param string $source_lang Source language.
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	public static function delete_entry( $term, $source_lang ) {
		if ( ! self::is_available() ) {
			return self::unavailable_error();
		}

		$source_lang = self::validate_locale( $source_lang, 'source_lang' );

		if ( is_wp_error( $source_lang ) ) {
			return $source_lang;
		}

		$existing = self::find_entry( $term, $source_lang );

		if ( null === $existing ) {
			return self::not_found_error( $term, $source_lang );
		}

		$deleted = \Atlt_Glossary::delete_glossary_data( $existing['term'], $existing['source_lang'] );

		if ( ! $deleted ) {
			return new WP_Error(
				'atlt_glossary_delete_failed',
				sprintf(
					/* translators: %s: glossary term. */
					__( 'The glossary entry for "%s" could not be deleted. Tell the user to check the Glossary tab in LocoAI.', 'loco-automatic-translate-addon-pro' ),
					$existing['term']
				)
			);
		}

		return [
			'success'     => true,
			'action'      => 'deleted',
			'term'        => $existing['term'],
			'source_lang' => $existing['source_lang'],
			'message'     => sprintf(
				/* translators: %s: glossary term. */
				__( 'Glossary entry "%s" deleted.', 'loco-automatic-translate-addon-pro' ),
				$existing['term']
			),
		];
	}

	/**
	 * Language codes an entry may use: installed languages, plus any already in
	 * the glossary so existing rows stay editable.
	 *
	 * @since 2.5.0
	 * @return string[] Canonical locale codes.
	 */
	public static function valid_locales() {
		$codes = [];

		if ( self::is_available() ) {
			foreach ( \Atlt_Glossary::get_languages() as $language ) {
				if ( isset( $language['code'] ) && '' !== $language['code'] ) {
					$codes[] = (string) $language['code'];
				}
			}

			// get_available_languages() lists installed core translations only.
			// A site may legitimately be translating a plugin into a language it
			// has no core pack for, so codes already in use stay acceptable.
			$entries = \Atlt_Glossary::get_all_glossaries();

			if ( is_array( $entries ) ) {
				foreach ( $entries as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}

					if ( ! empty( $entry['original_language_code'] ) ) {
						$codes[] = (string) $entry['original_language_code'];
					}

					if ( empty( $entry['translations'] ) || ! is_array( $entry['translations'] ) ) {
						continue;
					}

					foreach ( $entry['translations'] as $translation ) {
						if ( is_array( $translation ) && ! empty( $translation['target_language_code'] ) ) {
							$codes[] = (string) $translation['target_language_code'];
						}
					}
				}
			}
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * Canonical form of a locale a write may use.
	 *
	 * Never uses sanitize_key(): it lower-cases, which would turn "fr_FR" into
	 * "fr_fr". That matches nothing in the installed language list, and the
	 * admin Glossary tab hides entries whose language it does not recognise.
	 *
	 * @since 2.5.0
	 * @param string $code  Locale code from the caller.
	 * @param string $field Input field being validated, named in the error.
	 * @return string|WP_Error Canonical code, or an error listing valid ones.
	 */
	private static function validate_locale( $code, $field ) {
		$code  = trim( (string) $code );
		$valid = self::valid_locales();

		if ( '' === $code ) {
			return new WP_Error(
				'atlt_glossary_invalid_locale',
				sprintf(
					/* translators: 1: input field name, 2: comma-separated locale codes. */
					__( 'The "%1$s" value is required. Use one of these language codes: %2$s.', 'loco-automatic-translate-addon-pro' ),
					$field,
					implode( ', ', $valid )
				)
			);
		}

		foreach ( $valid as $candidate ) {
			if ( self::normalize_locale( $candidate ) === self::normalize_locale( $code ) ) {
				return $candidate;
			}
		}

		return new WP_Error(
			'atlt_glossary_invalid_locale',
			sprintf(
				/* translators: 1: input field name, 2: locale code supplied, 3: comma-separated locale codes. */
				__( 'The "%1$s" value "%2$s" is not a language this site has. Use one of: %3$s. To use another language, tell the user to install it under Settings > General first; an entry in a language the site does not have would be hidden in the Glossary tab.', 'loco-automatic-translate-addon-pro' ),
				$field,
				$code,
				implode( ', ', $valid )
			)
		);
	}

	/**
	 * Checks a term and returns it as the storage will hold it.
	 *
	 * Atlt_Glossary runs sanitize_text_field() on the term, which strips tags
	 * and collapses whitespace. Applying it here too means the identity used to
	 * look the entry up afterwards matches what was stored.
	 *
	 * @since 2.5.0
	 * @param string $term Term from the caller.
	 * @return string|WP_Error Sanitised term, or an error.
	 */
	private static function validate_term( $term ) {
		$term = sanitize_text_field( (string) $term );

		if ( '' === trim( $term ) ) {
			return new WP_Error(
				'atlt_glossary_invalid_input',
				__( 'The "term" value is required and cannot be empty.', 'loco-automatic-translate-addon-pro' )
			);
		}

		// The Glossary tab keys its rows on term . "||" . language, so a term
		// containing "||" produces a row that cannot be edited or deleted there.
		if ( false !== strpos( $term, '||' ) ) {
			return new WP_Error(
				'atlt_glossary_invalid_input',
				__( 'A glossary term cannot contain "||". Choose a different term.', 'loco-automatic-translate-addon-pro' )
			);
		}

		return $term;
	}

	/**
	 * Site languages, other than the source language, a new entry has no translation for.
	 *
	 * The admin Glossary form requires every one of these, so a new entry made
	 * through the tools has to as well.
	 *
	 * @since 2.5.1
	 * @param array<string, string> $translations Canonical translations keyed by language.
	 * @param string                $source_lang  Canonical source language.
	 * @return string[] Locale codes still needing a translation.
	 */
	private static function missing_target_languages( array $translations, $source_lang ) {
		$missing = [];

		foreach ( \Atlt_Glossary::get_languages() as $language ) {
			if ( empty( $language['code'] ) ) {
				continue;
			}

			$code = (string) $language['code'];

			if ( self::locales_match( $code, $source_lang ) ) {
				continue;
			}

			$found = false;

			foreach ( array_keys( $translations ) as $given ) {
				if ( self::locales_match( (string) $given, $code ) ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				$missing[] = $code;
			}
		}

		return $missing;
	}

	/**
	 * Checks the translations supplied for a new entry.
	 *
	 * @since 2.5.0
	 * @param array<string, string> $translations Translations keyed by language.
	 * @param string                $source_lang  Canonical source language.
	 * @return array<string, string>|WP_Error Canonical map, or an error.
	 */
	private static function validate_translations( array $translations, $source_lang ) {
		$cleaned = [];

		foreach ( $translations as $code => $translated ) {
			$code = self::validate_locale( $code, 'translations' );

			if ( is_wp_error( $code ) ) {
				return $code;
			}

			// The store silently drops a translation into the source language.
			if ( self::locales_match( $code, $source_lang ) ) {
				continue;
			}

			$translated = trim( (string) $translated );

			if ( '' === $translated ) {
				continue;
			}

			$cleaned[ $code ] = $translated;
		}

		if ( [] === $cleaned ) {
			return new WP_Error(
				'atlt_glossary_no_translations',
				__( 'At least one translation in a language other than the source language is required. Without one the entry would be stored but never used.', 'loco-automatic-translate-addon-pro' )
			);
		}

		return $cleaned;
	}

	/**
	 * A stored entry in the shape the abilities expose.
	 *
	 * @since 2.5.0
	 * @param array<string, mixed> $entry        Stored entry.
	 * @param string[]             $target_langs Languages to keep, or [] for all.
	 * @return array<string, mixed> Exposed entry.
	 */
	private static function reshape( array $entry, array $target_langs ) {
		$translations = [];

		if ( ! empty( $entry['translations'] ) && is_array( $entry['translations'] ) ) {
			foreach ( $entry['translations'] as $translation ) {
				if (
					! is_array( $translation ) ||
					empty( $translation['target_language_code'] ) ||
					empty( $translation['translated_term'] )
				) {
					continue;
				}

				$code = (string) $translation['target_language_code'];

				if ( [] !== $target_langs && ! self::locale_in( $code, $target_langs ) ) {
					continue;
				}

				$translations[ $code ] = (string) $translation['translated_term'];
			}
		}

		return [
			'term'         => (string) $entry['original_term'],
			'source_lang'  => isset( $entry['original_language_code'] ) ? (string) $entry['original_language_code'] : '',
			'type'         => self::normalize_type( isset( $entry['kind'] ) ? $entry['kind'] : '' ),
			'description'  => isset( $entry['description'] ) ? (string) $entry['description'] : '',
			'translations' => $translations,
		];
	}

	/**
	 * Clamps a kind to one the glossary and its admin table understand.
	 *
	 * @since 2.5.0
	 * @param string $type Kind from the caller or from storage.
	 * @return string One of ENTRY_TYPES.
	 */
	private static function normalize_type( $type ) {
		$type = strtolower( trim( (string) $type ) );

		return in_array( $type, self::ENTRY_TYPES, true ) ? $type : self::DEFAULT_TYPE;
	}

	/**
	 * Lower-cased locale with underscores, for comparison only.
	 *
	 * @since 2.5.0
	 * @param string $code Locale code.
	 * @return string Normalised code.
	 */
	private static function normalize_locale( $code ) {
		return strtolower( str_replace( '-', '_', trim( (string) $code ) ) );
	}

	/**
	 * Whether two codes name the same language.
	 *
	 * Exact first, then the language subtag, because Loco Translate hands this
	 * plugin a bare "fr" while the glossary stores "fr_FR".
	 *
	 * @since 2.5.0
	 * @param string $a First code.
	 * @param string $b Second code.
	 * @return bool True when both name the same language.
	 */
	private static function locales_match( $a, $b ) {
		$a = self::normalize_locale( $a );
		$b = self::normalize_locale( $b );

		if ( '' === $a || '' === $b ) {
			return false;
		}

		if ( $a === $b ) {
			return true;
		}

		return strtok( $a, '_' ) === strtok( $b, '_' );
	}

	/**
	 * Whether a code matches any in a list.
	 *
	 * @since 2.5.0
	 * @param string   $code  Code to test.
	 * @param string[] $codes Codes to test against.
	 * @return bool True on the first match.
	 */
	private static function locale_in( $code, array $codes ) {
		foreach ( $codes as $candidate ) {
			if ( self::locales_match( $code, $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Success payload for add and update, with the stored entry re-read.
	 *
	 * @since 2.5.0
	 * @param string $action      "added" or "updated".
	 * @param string $term        Term as stored.
	 * @param string $source_lang Source language as stored.
	 * @param string $message     Message for the caller.
	 * @return array<string, mixed> Payload.
	 */
	private static function success_payload( $action, $term, $source_lang, $message ) {
		return [
			'success' => true,
			'action'  => $action,
			'message' => $message,
			'entry'   => self::find_entry( $term, $source_lang ),
		];
	}

	/**
	 * Error for an entry that does not exist.
	 *
	 * @since 2.5.0
	 * @param string $term        Term looked for.
	 * @param string $source_lang Source language looked for.
	 * @return WP_Error Error describing the miss.
	 */
	private static function not_found_error( $term, $source_lang ) {
		return new WP_Error(
			'atlt_glossary_not_found',
			sprintf(
				/* translators: 1: glossary term, 2: language code. */
				__( 'No glossary entry for "%1$s" in language "%2$s". Call get-glossary to see what exists, or use action "add" to create it.', 'loco-automatic-translate-addon-pro' ),
				(string) $term,
				(string) $source_lang
			)
		);
	}
}
