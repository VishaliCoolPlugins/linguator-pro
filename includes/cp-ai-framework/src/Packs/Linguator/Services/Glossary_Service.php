<?php
/**
 * Glossary read and write operations.
 *
 * Linguator keeps the terms a site insists on translating a particular way in
 * its glossary module. Every read and write is delegated to that module's public
 * static methods so the abilities and the admin screen share one storage format
 * (the lmat_glossary_data option) and one set of rules. This service only
 * reshapes data and reports outcomes.
 *
 * Two shape differences between the stored format and this layer are deliberate:
 *
 *   - Stored entries key the term as "original_term" and its type as "kind".
 *     Both are renamed here to "term" and "type", matching the argument names
 *     the module itself accepts.
 *   - Stored translations are a list of {target_language_code, translated_term}
 *     pairs. They are exposed here as a language-keyed map, which is far easier
 *     for a client to consult while translating.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Services;

use Linguator\Modules\Glossary\Glossary as Linguator_Glossary;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps Linguator's glossary store for the abilities layer.
 *
 * @since 1.2.0
 */
final class Glossary_Service {

	/**
	 * Glossary entry types offered by the admin UI.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const ENTRY_TYPES = array( 'general', 'name' );

	/**
	 * Default entry type, matching the CSV importer's fallback.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const DEFAULT_TYPE = 'general';

	/**
	 * Most entries scanned when matching a glossary against a piece of text.
	 *
	 * Matching is O(entries x text), and get-content runs it on every call, so
	 * a pathologically large glossary must not be able to slow every read down.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MATCH_SCAN_LIMIT = 500;

	/**
	 * Whether Linguator's glossary module is loaded.
	 *
	 * @since 1.2.0
	 * @return bool True when the glossary class is available.
	 */
	public static function is_available() {
		return class_exists( 'Linguator\Modules\Glossary\Glossary' );
	}

	/**
	 * Error returned when the glossary feature cannot be reached.
	 *
	 * @since 1.2.0
	 * @return WP_Error The error.
	 */
	public static function unavailable_error() {
		return new WP_Error(
			'lmat_glossary_unavailable',
			__( 'The Linguator glossary is not available on this site.', 'linguator-pro' )
		);
	}

	/**
	 * Returns glossary entries, optionally narrowed to a translation direction.
	 *
	 * Passing both a source language and target languages yields exactly the
	 * term pairs needed for one translation job, which is the intended use.
	 *
	 * @since 1.2.0
	 * @param string   $source_lang  Optional source language slug to filter by.
	 * @param string[] $target_langs Optional target language slugs to narrow translations to.
	 * @param string   $type         Optional entry type filter, "general" or "name".
	 * @param string   $search       Optional case-insensitive substring match on the term.
	 * @param int      $limit        Maximum entries to return.
	 * @param int      $offset       Entries to skip, for pagination.
	 * @return array<string, mixed> Entries, total and has_more flag.
	 */
	public static function get_entries( $source_lang = '', array $target_langs = array(), $type = '', $search = '', $limit = 100, $offset = 0 ) {
		if ( ! self::is_available() ) {
			return array(
				'entries'  => array(),
				'total'    => 0,
				'has_more' => false,
			);
		}

		$source_lang = sanitize_key( (string) $source_lang );
		$type        = sanitize_key( (string) $type );
		$search      = trim( (string) $search );
		$limit       = max( 1, min( (int) $limit, 500 ) );
		$offset      = max( 0, (int) $offset );

		$targets = array();

		foreach ( $target_langs as $target ) {
			$target = sanitize_key( (string) $target );

			if ( '' !== $target ) {
				$targets[] = $target;
			}
		}

		$stored  = Linguator_Glossary::get_all_glossaries();
		$matched = array();

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		foreach ( $stored as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$entry_term   = isset( $entry['original_term'] ) ? (string) $entry['original_term'] : '';
			$entry_source = isset( $entry['original_language_code'] ) ? (string) $entry['original_language_code'] : '';
			$entry_type   = isset( $entry['kind'] ) ? (string) $entry['kind'] : self::DEFAULT_TYPE;

			if ( '' === $entry_term ) {
				continue;
			}

			if ( '' !== $source_lang && $entry_source !== $source_lang ) {
				continue;
			}

			if ( '' !== $type && $entry_type !== $type ) {
				continue;
			}

			if ( '' !== $search && false === stripos( $entry_term, $search ) ) {
				continue;
			}

			$translations = self::flatten_translations( $entry, $targets );

			/*
			 * An entry with no translation in any requested language is noise for
			 * a caller that asked about a specific direction. Unfiltered calls
			 * keep such entries so the admin view stays complete.
			 */
			if ( array() !== $targets && array() === $translations ) {
				continue;
			}

			$matched[] = array(
				'term'         => $entry_term,
				'source_lang'  => $entry_source,
				'type'         => $entry_type,
				'description'  => isset( $entry['description'] ) ? (string) $entry['description'] : '',
				'translations' => $translations,
			);
		}

		$total = count( $matched );

		return array(
			'entries'  => array_slice( $matched, $offset, $limit ),
			'total'    => $total,
			'has_more' => ( $offset + $limit ) < $total,
		);
	}

	/**
	 * Converts a stored translations list into a language-keyed map.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $entry   One stored glossary entry.
	 * @param string[]             $targets Language slugs to keep, or empty for all.
	 * @return array<string, string> Translations keyed by language slug.
	 */
	private static function flatten_translations( array $entry, array $targets ) {
		$translations = array();

		if ( ! isset( $entry['translations'] ) || ! is_array( $entry['translations'] ) ) {
			return $translations;
		}

		foreach ( $entry['translations'] as $translation ) {
			if ( ! is_array( $translation ) ) {
				continue;
			}

			$lang = isset( $translation['target_language_code'] ) ? (string) $translation['target_language_code'] : '';
			$term = isset( $translation['translated_term'] ) ? (string) $translation['translated_term'] : '';

			if ( '' === $lang || '' === $term ) {
				continue;
			}

			if ( array() !== $targets && ! in_array( $lang, $targets, true ) ) {
				continue;
			}

			$translations[ $lang ] = $term;
		}

		return $translations;
	}

	/**
	 * Returns the glossary entries whose term actually appears in some text.
	 *
	 * A tool description asking a client to consult the glossary is only a
	 * request, and a client that forgets produces translations disagreeing with
	 * Linguator's own. Handing back just the entries relevant to the text being
	 * translated turns that request into data the caller already holds.
	 *
	 * Matching is a plain case-insensitive substring test against tag-stripped
	 * text. Two deliberate choices:
	 *
	 *   - Tags are stripped first so a term never matches inside a URL, class or
	 *     attribute, where it is not prose and must not be translated.
	 *   - No word-boundary check. \b is meaningless in scripts that do not
	 *     separate words, such as Hindi, Japanese and Thai, so it would silently
	 *     under-match exactly the languages this plugin exists to serve. An
	 *     over-match costs one extra line in the payload; an under-match costs a
	 *     missed mandatory term, which is the failure being prevented.
	 *
	 * @since 1.2.0
	 * @param string[] $texts       Texts to scan, such as title, content and builder strings.
	 * @param string   $source_lang Language the texts are written in.
	 * @param string   $target_lang Optional target language to narrow translations to.
	 * @return array<int, array<string, mixed>> Matching entries, possibly empty.
	 */
	public static function get_matching_entries( array $texts, $source_lang, $target_lang = '' ) {
		if ( ! self::is_available() ) {
			return array();
		}

		$source_lang = sanitize_key( (string) $source_lang );
		$target_lang = sanitize_key( (string) $target_lang );

		if ( '' === $source_lang ) {
			return array();
		}

		$targets = ( '' !== $target_lang ) ? array( $target_lang ) : array();
		$result  = self::get_entries( $source_lang, $targets, '', '', self::MATCH_SCAN_LIMIT, 0 );

		if ( array() === $result['entries'] ) {
			return array();
		}

		$haystack = '';

		foreach ( $texts as $text ) {
			if ( ! is_string( $text ) || '' === $text ) {
				continue;
			}

			// A separator stops a term from spanning the join of two fields.
			$haystack .= ' ' . wp_strip_all_tags( $text ) . ' ';
		}

		$haystack = trim( $haystack );

		if ( '' === $haystack ) {
			return array();
		}

		$matched = array();

		foreach ( $result['entries'] as $entry ) {
			if ( '' === $entry['term'] || false === stripos( $haystack, $entry['term'] ) ) {
				continue;
			}

			$matched[] = $entry;
		}

		return $matched;
	}

	/**
	 * Reports which matched terms are missing from a translation.
	 *
	 * Advisory only. A mandated term can be legitimately absent because word
	 * order, grammar or a compound construction absorbed it, so this never
	 * blocks a write; it only tells the caller what to look at.
	 *
	 * @since 1.2.0
	 * @param array<int, array<string, mixed>> $entries     Entries from get_matching_entries().
	 * @param string[]                         $texts       Translated texts to scan.
	 * @param string                           $target_lang Language the texts are written in.
	 * @return array<int, array<string, mixed>> One entry per unapplied term.
	 */
	public static function get_unapplied_terms( array $entries, array $texts, $target_lang ) {
		$target_lang = sanitize_key( (string) $target_lang );

		if ( '' === $target_lang || array() === $entries ) {
			return array();
		}

		$haystack = '';

		foreach ( $texts as $text ) {
			if ( is_string( $text ) && '' !== $text ) {
				$haystack .= ' ' . wp_strip_all_tags( $text ) . ' ';
			}
		}

		if ( '' === trim( $haystack ) ) {
			return array();
		}

		$missing = array();

		foreach ( $entries as $entry ) {
			if ( ! isset( $entry['translations'][ $target_lang ] ) ) {
				continue;
			}

			$expected = (string) $entry['translations'][ $target_lang ];

			if ( '' === $expected || false !== stripos( $haystack, $expected ) ) {
				continue;
			}

			$missing[] = array(
				'term'     => $entry['term'],
				'expected' => $expected,
				'type'     => $entry['type'],
			);
		}

		return $missing;
	}

	/**
	 * Looks up a single entry by term and source language.
	 *
	 * @since 1.2.0
	 * @param string $term        The original term.
	 * @param string $source_lang Source language slug.
	 * @return array<string, mixed>|null The entry, or null when not found.
	 */
	public static function find_entry( $term, $source_lang ) {
		$result = self::get_entries( $source_lang, array(), '', '', 500, 0 );

		foreach ( $result['entries'] as $entry ) {
			if ( $entry['term'] === (string) $term ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Adds a glossary entry, or adds languages to an existing one.
	 *
	 * Linguator_Glossary::store_glossary_data() never overwrites a translation that
	 * already exists, and signals that by returning false. That is reported here
	 * as an actionable error rather than a bare failure, because the caller's
	 * next step is always to switch to update_entry().
	 *
	 * @since 1.2.0
	 * @param string                $term         The original term.
	 * @param string                $source_lang  Source language slug.
	 * @param array<string, string> $translations Translations keyed by language slug.
	 * @param string                $type         Entry type, "general" or "name".
	 * @param string                $description  Optional context for translators.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function add_entry( $term, $source_lang, array $translations, $type = self::DEFAULT_TYPE, $description = '' ) {
		if ( ! self::is_available() ) {
			return self::unavailable_error();
		}

		$validated = self::validate_entry_input( $term, $source_lang, $translations );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		/*
		 * store_glossary_data() takes the languages and their terms as two
		 * parallel indexed arrays, while update_glossary_data() takes a map.
		 * The map is the only shape exposed to callers; it is split here.
		 */
		$stored = Linguator_Glossary::store_glossary_data(
			array(
				'type'            => self::normalize_type( $type ),
				'term'            => $validated['term'],
				'description'     => (string) $description,
				'source_lang'     => $validated['source_lang'],
				'target_lang'     => array_keys( $validated['translations'] ),
				'translated_term' => array_values( $validated['translations'] ),
			)
		);

		if ( ! $stored ) {
			return new WP_Error(
				'lmat_glossary_add_failed',
				sprintf(
					/* translators: %s: glossary term. */
					__( 'Term "%s" already has a translation in every language supplied. Use action "update" to change an existing translation.', 'linguator-pro' ),
					$validated['term']
				)
			);
		}

		return self::success_payload(
			'added',
			$validated['term'],
			$validated['source_lang'],
			sprintf(
				/* translators: %s: glossary term. */
				__( 'Glossary entry "%s" was saved.', 'linguator-pro' ),
				$validated['term']
			)
		);
	}

	/**
	 * Updates an existing glossary entry.
	 *
	 * Linguator_Glossary::update_glossary_data() replaces the whole translations
	 * list, which is right for the admin form because it always posts every
	 * language. A caller here typically sends one language, so translations are
	 * merged over the stored set by default; pass $replace to get the legacy
	 * replace-everything behaviour.
	 *
	 * @since 1.2.0
	 * @param string                $term            Term identifying the entry to update.
	 * @param string                $source_lang     Source language identifying the entry.
	 * @param array<string, string> $translations    Translations keyed by language slug.
	 * @param string|null           $type            Entry type, or null to keep the stored one.
	 * @param string|null           $description     Description, or null to keep the stored one.
	 * @param string                $new_term        Optional replacement term.
	 * @param string                $new_source_lang Optional replacement source language.
	 * @param bool                  $replace         Replace translations instead of merging.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function update_entry( $term, $source_lang, array $translations, $type = null, $description = null, $new_term = '', $new_source_lang = '', $replace = false ) {
		if ( ! self::is_available() ) {
			return self::unavailable_error();
		}

		$term        = trim( (string) $term );
		$source_lang = sanitize_key( (string) $source_lang );
		$existing    = self::find_entry( $term, $source_lang );

		if ( null === $existing ) {
			return self::not_found_error( $term, $source_lang );
		}

		$target_term   = '' !== trim( (string) $new_term ) ? trim( (string) $new_term ) : $term;
		$target_source = '' !== sanitize_key( (string) $new_source_lang ) ? sanitize_key( (string) $new_source_lang ) : $source_lang;

		$merged = $replace ? array() : $existing['translations'];

		foreach ( $translations as $lang => $translated ) {
			$lang       = sanitize_key( (string) $lang );
			$translated = trim( (string) $translated );

			if ( '' === $lang || $lang === $target_source ) {
				continue;
			}

			/*
			 * An empty value is how a caller removes one language without
			 * resending the rest, so it unsets rather than storing a blank.
			 * store_glossary_data() discards empty terms too.
			 */
			if ( '' === $translated ) {
				unset( $merged[ $lang ] );

				continue;
			}

			$merged[ $lang ] = $translated;
		}

		if ( array() === $merged ) {
			return new WP_Error(
				'lmat_glossary_no_translations',
				__( 'An entry must keep at least one translation. Use action "delete" to remove the entry entirely.', 'linguator-pro' )
			);
		}

		$updated = Linguator_Glossary::update_glossary_data(
			array(
				// Both keys are read without a default by the legacy method.
				'original_term'        => $term,
				'original_source_lang' => $source_lang,
				'type'                 => null === $type ? $existing['type'] : self::normalize_type( $type ),
				'term'                 => $target_term,
				'description'          => null === $description ? $existing['description'] : (string) $description,
				'source_lang'          => $target_source,
				'translations'         => $merged,
			)
		);

		if ( ! $updated ) {
			return new WP_Error(
				'lmat_glossary_update_failed',
				sprintf(
					/* translators: %s: glossary term. */
					__( 'Glossary entry "%s" could not be updated.', 'linguator-pro' ),
					$term
				)
			);
		}

		return self::success_payload(
			'updated',
			$target_term,
			$target_source,
			sprintf(
				/* translators: %s: glossary term. */
				__( 'Glossary entry "%s" was updated.', 'linguator-pro' ),
				$target_term
			)
		);
	}

	/**
	 * Deletes a glossary entry and every translation it holds.
	 *
	 * @since 1.2.0
	 * @param string $term        The original term.
	 * @param string $source_lang Source language slug.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function delete_entry( $term, $source_lang ) {
		if ( ! self::is_available() ) {
			return self::unavailable_error();
		}

		$term        = trim( (string) $term );
		$source_lang = sanitize_key( (string) $source_lang );

		if ( '' === $term || '' === $source_lang ) {
			return new WP_Error(
				'lmat_glossary_invalid_input',
				__( 'Both "term" and "source_lang" are required to delete an entry.', 'linguator-pro' )
			);
		}

		if ( null === self::find_entry( $term, $source_lang ) ) {
			return self::not_found_error( $term, $source_lang );
		}

		if ( ! Linguator_Glossary::delete_glossary_data( $term, $source_lang ) ) {
			return new WP_Error(
				'lmat_glossary_delete_failed',
				sprintf(
					/* translators: %s: glossary term. */
					__( 'Glossary entry "%s" could not be deleted.', 'linguator-pro' ),
					$term
				)
			);
		}

		return array(
			'success'     => true,
			'action'      => 'deleted',
			'term'        => $term,
			'source_lang' => $source_lang,
			'message'     => sprintf(
				/* translators: %s: glossary term. */
				__( 'Glossary entry "%s" was deleted.', 'linguator-pro' ),
				$term
			),
		);
	}

	/**
	 * Validates the term, source language and translations shared by add/update.
	 *
	 * @since 1.2.0
	 * @param string                $term         The original term.
	 * @param string                $source_lang  Source language slug.
	 * @param array<string, string> $translations Translations keyed by language slug.
	 * @return array<string, mixed>|WP_Error Cleaned values, or an error.
	 */
	private static function validate_entry_input( $term, $source_lang, array $translations ) {
		$term        = trim( (string) $term );
		$source_lang = sanitize_key( (string) $source_lang );

		if ( '' === $term ) {
			return new WP_Error(
				'lmat_glossary_invalid_input',
				__( 'The glossary term cannot be empty.', 'linguator-pro' )
			);
		}

		if ( '' === $source_lang ) {
			return new WP_Error(
				'lmat_glossary_invalid_input',
				__( 'A source language slug is required.', 'linguator-pro' )
			);
		}

		$cleaned = array();

		foreach ( $translations as $lang => $translated ) {
			$lang       = sanitize_key( (string) $lang );
			$translated = trim( (string) $translated );

			// The store silently drops these; rejecting early explains why.
			if ( '' === $lang || '' === $translated || $lang === $source_lang ) {
				continue;
			}

			$cleaned[ $lang ] = $translated;
		}

		if ( array() === $cleaned ) {
			return new WP_Error(
				'lmat_glossary_no_translations',
				__( 'At least one translation in a language other than the source language is required.', 'linguator-pro' )
			);
		}

		return array(
			'term'         => $term,
			'source_lang'  => $source_lang,
			'translations' => $cleaned,
		);
	}

	/**
	 * Constrains an entry type to the values the admin UI offers.
	 *
	 * @since 1.2.0
	 * @param string $type Requested type.
	 * @return string A supported type.
	 */
	private static function normalize_type( $type ) {
		$type = sanitize_key( (string) $type );

		return in_array( $type, self::ENTRY_TYPES, true ) ? $type : self::DEFAULT_TYPE;
	}

	/**
	 * Builds the payload returned by a successful write, including the entry.
	 *
	 * @since 1.2.0
	 * @param string $action      Action performed, "added" or "updated".
	 * @param string $term        The stored term.
	 * @param string $source_lang The stored source language.
	 * @param string $message     Human-readable outcome.
	 * @return array<string, mixed> Result payload.
	 */
	private static function success_payload( $action, $term, $source_lang, $message ) {
		return array(
			'success' => true,
			'action'  => $action,
			'message' => $message,
			'entry'   => self::find_entry( $term, $source_lang ),
		);
	}

	/**
	 * Error returned when no entry matches the given term and language.
	 *
	 * @since 1.2.0
	 * @param string $term        The original term.
	 * @param string $source_lang Source language slug.
	 * @return WP_Error The error.
	 */
	private static function not_found_error( $term, $source_lang ) {
		return new WP_Error(
			'lmat_glossary_not_found',
			sprintf(
				/* translators: 1: glossary term, 2: language slug. */
				__( 'No glossary entry for "%1$s" in language "%2$s".', 'linguator-pro' ),
				$term,
				$source_lang
			)
		);
	}
}
