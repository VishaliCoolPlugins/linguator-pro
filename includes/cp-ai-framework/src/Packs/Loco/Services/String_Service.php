<?php
/**
 * Reading and writing translation strings in PO files.
 *
 * @package LocoAI
 * @since   2.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Loco\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads PO entries and merges translations back through Loco's compiler.
 *
 * Entries are identified the way gettext identifies them: context plus source
 * text. Plural entries carry one translation per plural form of the language.
 *
 * @since 2.3.0
 */
final class String_Service {

	/**
	 * Accepted values of the status filter.
	 *
	 * @since 2.3.0
	 * @var string[]
	 */
	const STATUSES = [ 'untranslated', 'fuzzy', 'translated', 'all' ];

	/**
	 * Loco's internal flag value for fuzzy entries.
	 *
	 * @since 2.3.0
	 * @var int
	 */
	const FUZZY_FLAG = 4;

	/**
	 * Longest translation accepted for a single form, in bytes.
	 *
	 * @since 2.3.0
	 * @var int
	 */
	const MAX_TRANSLATION_BYTES = 20000;

	/**
	 * Longest references string returned per entry, in characters.
	 *
	 * @since 2.3.0
	 * @var int
	 */
	const MAX_REFERENCES_LENGTH = 300;

	/**
	 * Printf-style placeholder, e.g. %s, %d, %1$s, %.2f.
	 *
	 * @since 2.3.0
	 * @var string
	 */
	const PLACEHOLDER_PATTERN = '/%(?:\d+\$)?[-+ 0\'#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/';

	/**
	 * Lists entries of a PO file.
	 *
	 * @since 2.3.0
	 * @param \Loco_fs_LocaleFile $file     PO file.
	 * @param \Loco_Locale        $locale   Locale of the file.
	 * @param string              $status   One of self::STATUSES.
	 * @param string              $search   Case-insensitive text filter.
	 * @param int                 $page     1-based page number.
	 * @param int                 $per_page Entries per page.
	 * @return array<string, mixed>|WP_Error Listing, or an error.
	 */
	public static function get_strings( \Loco_fs_LocaleFile $file, \Loco_Locale $locale, $status, $search, $page, $per_page ) {
		$data = self::load( $file );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$nplurals = self::plural_count( $data, $locale );
		$offset   = ( $page - 1 ) * $per_page;
		$matched  = 0;
		$items    = [];
		$counts   = [
			'untranslated' => 0,
			'fuzzy'        => 0,
			'translated'   => 0,
		];

		foreach ( $data as $message ) {
			$state = self::message_status( $message, $nplurals );
			++$counts[ $state ];

			if ( 'all' !== $status && $state !== $status ) {
				continue;
			}

			if ( '' !== $search && ! self::message_matches( $message, $search ) ) {
				continue;
			}

			if ( $matched >= $offset && count( $items ) < $per_page ) {
				$items[] = self::message_to_array( $message, $nplurals, $state );
			}

			++$matched;
		}

		$plural_labels = $locale->getPluralForms();

		return [
			'file'       => [
				'path'     => Loco_Guard::relative_path( $file ),
				'location' => Loco_Guard::location_key( $file ),
				'writable' => $file->writable(),
			],
			'locale'     => [
				'code'          => (string) $locale,
				'language'      => (string) $locale->getName(),
				'plural_forms'  => $nplurals,
				'plural_labels' => count( $plural_labels ) === $nplurals ? array_values( $plural_labels ) : [],
			],
			'status'     => $status,
			'counts'     => $counts + [ 'total' => array_sum( $counts ) ],
			'pagination' => [
				'page'        => $page,
				'per_page'    => $per_page,
				'total_items' => $matched,
				'total_pages' => (int) ceil( $matched / max( 1, $per_page ) ),
				'has_more'    => ( $offset + count( $items ) ) < $matched,
			],
			'strings'    => $items,
		];
	}

	/**
	 * Tells the caller whether to fetch another batch.
	 *
	 * A large file needs several get-strings and save-translations rounds. The
	 * caller is an AI that otherwise stops after one saved batch and waits for the
	 * user, so each save says what to do next.
	 *
	 * @since 2.3.0
	 * @param int $untranslated Strings still untranslated in the file.
	 * @return string Instruction for the caller.
	 */
	private static function next_step( $untranslated ) {
		if ( $untranslated < 1 ) {
			return __( 'No untranslated strings remain in this file. Report the result to the user.', 'loco-automatic-translate-addon-pro' );
		}

		return sprintf(
			/* translators: %d: number of strings still untranslated. */
			__( '%d strings are still untranslated. Call get-strings again with the same bundle, domain and locale and keep translating without asking the user. Repeat get-strings and save-translations until untranslated is 0, then report the result.', 'loco-automatic-translate-addon-pro' ),
			$untranslated
		);
	}

	/**
	 * Merges translations into a PO file and recompiles it.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Bundle  $bundle         Bundle, for dashboard statistics.
	 * @param \Loco_package_Project $project        Project, needed to compile JSON files.
	 * @param \Loco_fs_LocaleFile   $file           PO file to update.
	 * @param \Loco_Locale          $locale         Locale of the file.
	 * @param array<int, array>     $entries        Normalised entries: source, context, translation, translations, fuzzy.
	 * @param bool                  $overwrite      Whether to replace translations that are already complete.
	 * @param bool                  $allow_mismatch Whether to accept placeholder and HTML count differences.
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	public static function save_translations( \Loco_package_Bundle $bundle, \Loco_package_Project $project, \Loco_fs_LocaleFile $file, \Loco_Locale $locale, array $entries, $overwrite, $allow_mismatch ) {
		$writable = Loco_Guard::check_writable( $file );

		if ( is_wp_error( $writable ) ) {
			return $writable;
		}

		$data = self::load( $file );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$nplurals = self::plural_count( $data, $locale );
		$requests = [];
		$results  = [
			'updated'   => [],
			'skipped'   => [],
			'rejected'  => [],
			'not_found' => [],
		];

		foreach ( $entries as $index => $entry ) {
			$key = self::entry_key( $entry['source'], $entry['context'] );

			if ( isset( $requests[ $key ] ) ) {
				$results['rejected'][] = self::entry_ref( $entry ) + [
					'reason'  => 'duplicate',
					'message' => __( 'The same source and context appear more than once in this call; only the first was used.', 'loco-automatic-translate-addon-pro' ),
				];
				continue;
			}

			$requests[ $key ] = $index;
		}

		$updated_po = clone $data;
		$updated_po->clear();

		$changed    = 0;
		$characters = 0;

		foreach ( $data as $message ) {
			$key = $message->getKey();

			if ( isset( $requests[ $key ] ) ) {
				$entry = $entries[ $requests[ $key ] ];
				unset( $requests[ $key ] );

				$outcome = self::apply_entry( $message, $entry, $nplurals, (bool) $overwrite, (bool) $allow_mismatch );

				if ( 'updated' === $outcome['result'] ) {
					++$changed;
					$characters            += self::text_length( (string) $message['source'] );
					$results['updated'][]   = self::entry_ref( $entry );
				} else {
					$results[ $outcome['result'] ][] = self::entry_ref( $entry ) + [
						'reason'  => $outcome['reason'],
						'message' => $outcome['message'],
					];
				}
			}

			$updated_po->push( $message );
		}

		foreach ( $requests as $index ) {
			$results['not_found'][] = self::entry_ref( $entries[ $index ] );
		}

		$response = [
			'saved'          => false,
			'updated_count'  => $changed,
			'skipped_count'  => count( $results['skipped'] ),
			'rejected_count' => count( $results['rejected'] ),
		] + $results;

		if ( [] !== $results['not_found'] ) {
			$response['not_found_notice'] = __( 'These sources are not in the file. Source text and context must match get-strings exactly, including punctuation and whitespace. If the plugin was updated since the file was last synced, tell the user to open the file in Loco Translate and click "Sync".', 'loco-automatic-translate-addon-pro' );
		}

		if ( 0 === $changed ) {
			$response['file']      = Project_Service::file_summary( $file );
			$response['next_step'] = self::next_step( (int) $response['file']['untranslated'] );

			return $response;
		}

		$updated_po->localize( $locale );

		$written = Loco_Guard::run_write(
			static function () use ( $file, $updated_po, $project ) {
				$compiler = new \Loco_gettext_Compiler( $file );

				return $compiler->writeAll( $updated_po, $project );
			}
		);

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$paths = [];
		foreach ( $written as $written_file ) {
			$paths[] = Loco_Guard::relative_path( $written_file );
		}

		self::remember_bundle( $bundle );
		self::record_statistics( $bundle, $project, $locale, $changed, $characters );

		$response['saved']         = true;
		$response['files_written'] = $paths;
		$response['file']          = Project_Service::file_summary( $file, true );
		$response['next_step']     = self::next_step( (int) $response['file']['untranslated'] );
		$response['editor_notice'] = __( 'Saved. If the user has this file open in the Loco Translate editor, saving there will overwrite these changes; they should reload the editor first.', 'loco-automatic-translate-addon-pro' );

		return $response;
	}

	/**
	 * Loads a PO file.
	 *
	 * @since 2.3.0
	 * @param \Loco_fs_File $file PO file.
	 * @return \Loco_gettext_Data|WP_Error Parsed data, or an error.
	 */
	private static function load( \Loco_fs_File $file ) {
		try {
			return \Loco_gettext_Data::load( $file );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'atlt_po_unreadable',
				sprintf(
					/* translators: 1: file path, 2: reason reported by Loco Translate. */
					__( 'Loco Translate could not read %1$s: %2$s. Tell the user to open the file in Loco Translate, which reports the problem.', 'loco-automatic-translate-addon-pro' ),
					Loco_Guard::relative_path( $file ),
					Loco_Guard::safe_message( $e )
				)
			);
		}
	}

	/**
	 * Number of plural forms the file uses.
	 *
	 * The PO header wins, because it is what WordPress evaluates at runtime.
	 *
	 * @since 2.3.0
	 * @param \Loco_gettext_Data $data   Parsed PO data.
	 * @param \Loco_Locale       $locale Locale of the file.
	 * @return int Plural form count, at least 1.
	 */
	private static function plural_count( \Loco_gettext_Data $data, \Loco_Locale $locale ) {
		$header = $data->getHeaders()->trimmed( 'Plural-Forms' );

		if ( preg_match( '/nplurals\s*=\s*(\d+)/', $header, $match ) ) {
			return max( 1, (int) $match[1] );
		}

		return max( 1, count( $locale->getPluralForms() ) );
	}

	/**
	 * Whether an entry has plural forms.
	 *
	 * @since 2.3.0
	 * @param \LocoPoMessage $message Entry.
	 * @return bool True for plural entries.
	 */
	private static function is_plural( \LocoPoMessage $message ) {
		$plurals = $message->plurals;

		return is_array( $plurals ) && [] !== $plurals;
	}

	/**
	 * Current translation forms of an entry.
	 *
	 * @since 2.3.0
	 * @param \LocoPoMessage $message  Entry.
	 * @param int            $nplurals Plural form count.
	 * @return string[] One form for singular entries, $nplurals forms for plural entries.
	 */
	private static function forms( \LocoPoMessage $message, $nplurals ) {
		$forms = [ (string) $message['target'] ];

		if ( ! self::is_plural( $message ) ) {
			return $forms;
		}

		$plurals = $message->plurals;

		for ( $form = 1; $form < $nplurals; $form++ ) {
			$forms[] = isset( $plurals[ $form - 1 ] ) ? (string) $plurals[ $form - 1 ]['target'] : '';
		}

		return $forms;
	}

	/**
	 * Translation status of an entry.
	 *
	 * An entry with any empty plural form counts as untranslated, because
	 * WordPress falls back to the source text for that form.
	 *
	 * @since 2.3.0
	 * @param \LocoPoMessage $message  Entry.
	 * @param int            $nplurals Plural form count.
	 * @return string "untranslated", "fuzzy" or "translated".
	 */
	private static function message_status( \LocoPoMessage $message, $nplurals ) {
		if ( in_array( '', self::forms( $message, $nplurals ), true ) ) {
			return 'untranslated';
		}

		return $message->isFuzzy() ? 'fuzzy' : 'translated';
	}

	/**
	 * Whether an entry contains a search term.
	 *
	 * @since 2.3.0
	 * @param \LocoPoMessage $message Entry.
	 * @param string         $search  Search term.
	 * @return bool True on a match.
	 */
	private static function message_matches( \LocoPoMessage $message, $search ) {
		$haystack = [ (string) $message['source'], (string) $message->context, (string) $message['target'] ];

		if ( self::is_plural( $message ) ) {
			foreach ( $message->plurals as $plural ) {
				$haystack[] = (string) $plural['source'];
				$haystack[] = (string) $plural['target'];
			}
		}

		return false !== stripos( implode( "\n", $haystack ), $search );
	}

	/**
	 * Caller-facing representation of an entry.
	 *
	 * @since 2.3.0
	 * @param \LocoPoMessage $message  Entry.
	 * @param int            $nplurals Plural form count.
	 * @param string         $status   Entry status.
	 * @return array<string, mixed> Entry data.
	 */
	private static function message_to_array( \LocoPoMessage $message, $nplurals, $status ) {
		$source  = (string) $message['source'];
		$sources = [ $source ];
		$item    = [
			'source'  => $source,
			'context' => (string) $message->context,
		];

		if ( self::is_plural( $message ) ) {
			$plurals               = $message->plurals;
			$item['plural_source'] = (string) $plurals[0]['source'];
			$item['translations']  = self::forms( $message, $nplurals );
			$sources[]             = $item['plural_source'];
		} else {
			$item['translation'] = (string) $message['target'];
		}

		$item['status'] = $status;

		$notes = trim( (string) $message->notes );
		if ( '' !== $notes ) {
			$item['developer_notes'] = $notes;
		}

		$comment = trim( (string) $message->comment );
		if ( '' !== $comment ) {
			$item['translator_comment'] = $comment;
		}

		$references = trim( (string) $message->refs );
		if ( '' !== $references ) {
			$item['references'] = self::truncate( $references, self::MAX_REFERENCES_LENGTH );
		}

		$text = implode( "\n", $sources );

		if ( self::uses_placeholders( $message ) ) {
			preg_match_all( self::PLACEHOLDER_PATTERN, str_replace( '%%', '', $text ), $found );
			$item['placeholders'] = array_values( array_unique( $found[0] ) );
		}

		$tags = array_values( array_unique( self::html_tags( $text ) ) );
		if ( [] !== $tags ) {
			$item['html_tags'] = $tags;
		}

		return $item;
	}

	/**
	 * Applies one requested translation to an entry.
	 *
	 * @since 2.3.0
	 * @param \LocoPoMessage       $message        Entry, modified in place.
	 * @param array<string, mixed> $entry          Normalised request.
	 * @param int                  $nplurals       Plural form count.
	 * @param bool                 $overwrite      Whether complete translations may be replaced.
	 * @param bool                 $allow_mismatch Whether placeholder and HTML count differences are accepted.
	 * @return array{result: string, reason?: string, message?: string} Outcome.
	 */
	private static function apply_entry( \LocoPoMessage $message, array $entry, $nplurals, $overwrite, $allow_mismatch ) {
		$is_plural = self::is_plural( $message );
		$current   = self::forms( $message, $nplurals );

		if ( ! $overwrite && 'translated' === self::message_status( $message, $nplurals ) ) {
			return self::outcome(
				'skipped',
				'already_translated',
				__( 'Already translated. Pass "overwrite": true to replace it.', 'loco-automatic-translate-addon-pro' )
			);
		}

		if ( $is_plural ) {
			$forms = $entry['translations'];

			if ( null === $forms && null !== $entry['translation'] && 1 === $nplurals ) {
				$forms = [ $entry['translation'] ];
			}

			if ( ! is_array( $forms ) || count( $forms ) !== $nplurals ) {
				return self::outcome(
					'rejected',
					'plural_forms_required',
					sprintf(
						/* translators: %d: number of plural forms. */
						_n(
							'This string has plural forms. Send "translations" as an array with exactly %d item, in the order of plural_labels from get-strings.',
							'This string has plural forms. Send "translations" as an array with exactly %d items, in the order of plural_labels from get-strings.',
							$nplurals,
							'loco-automatic-translate-addon-pro'
						),
						$nplurals
					)
				);
			}
		} else {
			$forms = null !== $entry['translation'] ? [ $entry['translation'] ] : null;

			if ( null === $forms && is_array( $entry['translations'] ) && 1 === count( $entry['translations'] ) ) {
				$forms = array_values( $entry['translations'] );
			}

			if ( null === $forms ) {
				return self::outcome(
					'rejected',
					'translation_required',
					__( 'This string has no plural forms. Send the text in "translation".', 'loco-automatic-translate-addon-pro' )
				);
			}
		}

		$forms   = array_values( $forms );
		$invalid = self::validate_forms( $message, $forms, $allow_mismatch );

		if ( null !== $invalid ) {
			return self::outcome( 'rejected', $invalid['reason'], $invalid['message'] );
		}

		$make_fuzzy = (bool) $entry['fuzzy'];

		if ( $current === $forms && $make_fuzzy === $message->isFuzzy() ) {
			return self::outcome(
				'skipped',
				'unchanged',
				__( 'The file already contains exactly this translation.', 'loco-automatic-translate-addon-pro' )
			);
		}

		$message['target'] = $forms[0];

		if ( $is_plural ) {
			$plurals = $message->plurals;

			for ( $form = 1; $form < $nplurals; $form++ ) {
				if ( isset( $plurals[ $form - 1 ] ) ) {
					$plurals[ $form - 1 ]['target'] = $forms[ $form ];
				} else {
					$plurals[] = new \LocoPoMessage(
						[
							'source' => '',
							'target' => $forms[ $form ],
						]
					);
				}
			}

			$message['plurals'] = $plurals;
		}

		self::set_fuzzy( $message, $make_fuzzy );

		return [ 'result' => 'updated' ];
	}

	/**
	 * Sets or clears the fuzzy flag on an entry and its plural forms.
	 *
	 * @since 2.3.0
	 * @param \LocoPoMessage $message Entry, modified in place.
	 * @param bool           $fuzzy   Whether the entry needs review.
	 * @return void
	 */
	private static function set_fuzzy( \LocoPoMessage $message, $fuzzy ) {
		$forms = [ $message ];

		if ( self::is_plural( $message ) ) {
			foreach ( $message->plurals as $plural ) {
				$forms[] = $plural;
			}
		}

		foreach ( $forms as $form ) {
			if ( $fuzzy ) {
				$form['flag'] = self::FUZZY_FLAG;
			} elseif ( $form->offsetExists( 'flag' ) ) {
				$form->offsetUnset( 'flag' );
			}
		}

		// "#|" previous-source comments only describe why an entry is fuzzy.
		if ( ! $fuzzy && $message->offsetExists( 'prev' ) ) {
			$message->offsetUnset( 'prev' );
		}
	}

	/**
	 * Validates requested translation forms against the source.
	 *
	 * HTML tags absent from the source are always refused, because translated
	 * strings are often printed without escaping. Placeholder and tag counts must
	 * match unless $allow_mismatch is set.
	 *
	 * @since 2.3.0
	 * @param \LocoPoMessage $message        Entry.
	 * @param array<int, mixed> $forms       Requested forms.
	 * @param bool           $allow_mismatch Whether count differences are accepted.
	 * @return array{reason: string, message: string}|null Failure, or null when valid.
	 */
	private static function validate_forms( \LocoPoMessage $message, array $forms, $allow_mismatch ) {
		$is_plural = self::is_plural( $message );
		$singular  = (string) $message['source'];
		$sources   = [ $singular ];

		if ( $is_plural ) {
			$plurals   = $message->plurals;
			$sources[] = (string) $plurals[0]['source'];
		}

		foreach ( $forms as $form ) {
			if ( ! is_string( $form ) || '' === trim( $form ) ) {
				return [
					'reason'  => 'empty_translation',
					'message' => __( 'Translations must be non-empty text. To clear a translation, ask the user to do it in the Loco Translate editor.', 'loco-automatic-translate-addon-pro' ),
				];
			}

			if ( strlen( $form ) > self::MAX_TRANSLATION_BYTES || false !== strpos( $form, "\0" ) || '' === wp_check_invalid_utf8( $form ) ) {
				return [
					'reason'  => 'invalid_translation',
					'message' => __( 'The translation is too long or is not valid UTF-8 text.', 'loco-automatic-translate-addon-pro' ),
				];
			}
		}

		$source_tags  = self::html_tags( implode( "\n", $sources ) );
		$allowed_tags = array_unique( array_map( [ __CLASS__, 'tag_name' ], $source_tags ) );

		foreach ( $forms as $form ) {
			$unexpected = array_diff( array_unique( array_map( [ __CLASS__, 'tag_name' ], self::html_tags( $form ) ) ), $allowed_tags );

			if ( [] !== $unexpected ) {
				return [
					'reason'  => 'unexpected_html',
					'message' => sprintf(
						/* translators: %s: comma-separated HTML tag names. */
						__( 'The translation adds HTML the source does not contain (%s). Use only the tags in the source text.', 'loco-automatic-translate-addon-pro' ),
						implode( ', ', $unexpected )
					),
				];
			}
		}

		if ( $allow_mismatch ) {
			return null;
		}

		if ( ! $is_plural ) {
			$expected = self::sorted( self::html_tags( $singular ) );

			if ( self::sorted( self::html_tags( $forms[0] ) ) !== $expected ) {
				return [
					'reason'  => 'html_mismatch',
					'message' => __( 'The translation must keep every HTML tag of the source, the same number of times. Pass "allow_placeholder_mismatch": true only if the difference is intentional.', 'loco-automatic-translate-addon-pro' ),
				];
			}
		}

		if ( ! self::uses_placeholders( $message ) ) {
			return null;
		}

		$singular_types = self::placeholder_types( $singular );
		$allowed_types  = array_unique( self::placeholder_types( implode( "\n", $sources ) ) );

		foreach ( $forms as $form ) {
			$types = self::placeholder_types( $form );

			$valid = $is_plural
				? [] === array_diff( $types, $allowed_types )
				: self::sorted( $types ) === self::sorted( $singular_types );

			if ( ! $valid ) {
				return [
					'reason'  => 'placeholder_mismatch',
					'message' => __( 'The translation must keep the source\'s printf placeholders such as %s, %d or %1$s, written exactly (no spaces inside them). Pass "allow_placeholder_mismatch": true only if the difference is intentional.', 'loco-automatic-translate-addon-pro' ),
				];
			}
		}

		return null;
	}

	/**
	 * Whether an entry's source should be treated as a printf format string.
	 *
	 * Loco records "#, php-format" for such strings. Without that flag, only
	 * the common %s and %d forms count, so text like "50% off" is not mistaken
	 * for a placeholder.
	 *
	 * @since 2.3.0
	 * @param \LocoPoMessage $message Entry.
	 * @return bool True when placeholders must be preserved.
	 */
	private static function uses_placeholders( \LocoPoMessage $message ) {
		$format = $message->getFormat();

		if ( 'php' === $format ) {
			return true;
		}

		if ( '' !== $format ) {
			return false;
		}

		return 1 === preg_match( '/%(?:\d+\$)?[sd]/', (string) $message['source'] );
	}

	/**
	 * Conversion types of the printf placeholders in a text.
	 *
	 * @since 2.3.0
	 * @param string $text Text.
	 * @return string[] Conversion characters, e.g. [ "s", "d" ].
	 */
	private static function placeholder_types( $text ) {
		preg_match_all( self::PLACEHOLDER_PATTERN, str_replace( '%%', '', (string) $text ), $found );

		return array_map(
			static function ( $token ) {
				return substr( $token, -1 );
			},
			$found[0]
		);
	}

	/**
	 * HTML tags in a text, as lower-case names with "/" for closing tags.
	 *
	 * @since 2.3.0
	 * @param string $text Text.
	 * @return string[] Tags, e.g. [ "a", "/a" ].
	 */
	private static function html_tags( $text ) {
		preg_match_all( '/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9-]*)[^>]*>/', (string) $text, $found, PREG_SET_ORDER );

		$tags = [];
		foreach ( $found as $match ) {
			$tags[] = $match[1] . strtolower( $match[2] );
		}

		return $tags;
	}

	/**
	 * Tag name without the closing slash.
	 *
	 * @since 2.3.0
	 * @param string $tag Tag from html_tags().
	 * @return string Tag name.
	 */
	private static function tag_name( $tag ) {
		return ltrim( (string) $tag, '/' );
	}

	/**
	 * Sorted copy of a list.
	 *
	 * @since 2.3.0
	 * @param string[] $values Values.
	 * @return string[] Sorted values.
	 */
	private static function sorted( array $values ) {
		sort( $values, SORT_STRING );

		return $values;
	}

	/**
	 * Lookup key matching LocoPoMessage::getKey().
	 *
	 * @since 2.3.0
	 * @param string $source  Source text.
	 * @param string $context Context.
	 * @return string Key.
	 */
	private static function entry_key( $source, $context ) {
		$source  = (string) $source;
		$context = (string) $context;

		if ( '' === $context ) {
			return $source;
		}

		if ( '' === $source ) {
			$source = '(' . $context . ')';
		}

		return $context . "\4" . $source;
	}

	/**
	 * Source and context of a request, for result lists.
	 *
	 * @since 2.3.0
	 * @param array<string, mixed> $entry Normalised request.
	 * @return array<string, string> Reference.
	 */
	private static function entry_ref( array $entry ) {
		return [
			'source'  => (string) $entry['source'],
			'context' => (string) $entry['context'],
		];
	}

	/**
	 * Outcome of applying one request.
	 *
	 * @since 2.3.0
	 * @param string $result  "skipped" or "rejected".
	 * @param string $reason  Machine-readable reason.
	 * @param string $message Explanation for the caller.
	 * @return array{result: string, reason: string, message: string} Outcome.
	 */
	private static function outcome( $result, $reason, $message ) {
		return [
			'result'  => $result,
			'reason'  => $reason,
			'message' => $message,
		];
	}

	/**
	 * Character length of a text.
	 *
	 * @since 2.3.0
	 * @param string $text Text.
	 * @return int Length.
	 */
	private static function text_length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}

	/**
	 * Shortens a text to a maximum length.
	 *
	 * @since 2.3.0
	 * @param string $text   Text.
	 * @param int    $length Maximum characters.
	 * @return string Possibly shortened text.
	 */
	private static function truncate( $text, $length ) {
		if ( self::text_length( $text ) <= $length ) {
			return $text;
		}

		$short = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );

		return rtrim( $short ) . '…';
	}

	/**
	 * Adds the bundle to Loco Translate's recent items, as its editor does on save.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Bundle $bundle Bundle.
	 * @return void
	 */
	private static function remember_bundle( \Loco_package_Bundle $bundle ) {
		try {
			\Loco_data_RecentItems::get()->pushBundle( $bundle )->persist();
		} catch ( \Throwable $e ) {
			// Recent items are a convenience; never fail a completed save over them.
			unset( $e );
		}
	}

	/**
	 * Adds the saved strings to the LocoAI dashboard statistics.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Bundle  $bundle     Bundle.
	 * @param \Loco_package_Project $project    Project.
	 * @param \Loco_Locale          $locale     Locale.
	 * @param int                   $strings    Number of strings saved.
	 * @param int                   $characters Source characters saved.
	 * @return void
	 */
	private static function record_statistics( \Loco_package_Bundle $bundle, \Loco_package_Project $project, \Loco_Locale $locale, $strings, $characters ) {
		if ( ! class_exists( 'Atlt_Dashboard' ) ) {
			return;
		}

		\Atlt_Dashboard::store_options(
			'atlt',
			'plugins_themes',
			'update',
			[
				'plugins_themes'   => Project_Service::stats_name( $bundle, $project ),
				'service_provider' => 'ai-assistant',
				'source_language'  => 'en',
				'target_language'  => (string) $locale,
				'time_taken'       => 0,
				'string_count'     => (int) $strings,
				'character_count'  => (int) $characters,
				'date_time'        => gmdate( 'Y-m-d H:i:s' ),
				'version_type'     => 'pro',
			]
		);
	}
}
