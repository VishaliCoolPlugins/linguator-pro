<?php
/**
 * Reconciling a PO file against its current source strings.
 *
 * @package LocoAI
 * @since   2.4.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Loco\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs Loco Translate's own sync/merge over a language file.
 *
 * Follows Loco_ajax_SyncController: resolve the source (a POT, an alternative
 * template named in the PO headers, or strings extracted from the plugin's
 * code), merge through Loco_gettext_Matcher, then write the result.
 *
 * The editor merges in the browser and waits for a separate save, which gives a
 * human the chance to refuse. An ability has no such pause, so removals are
 * counted first and a sync that would drop a meaningful number of finished
 * translations stops and reports instead of writing.
 *
 * @since 2.4.0
 */
final class Sync_Service {

	/**
	 * Most example strings returned per change bucket.
	 *
	 * @since 2.4.0
	 * @var int
	 */
	const MAX_SAMPLES = 20;

	/**
	 * Translated entries that may always be dropped without confirmation.
	 *
	 * Below this, a removal is routine churn rather than a sign that the wrong
	 * source was read.
	 *
	 * @since 2.4.0
	 * @var int
	 */
	const REMOVAL_FLOOR = 5;

	/**
	 * Share of existing translations whose removal triggers confirmation.
	 *
	 * @since 2.4.0
	 * @var float
	 */
	const REMOVAL_RATIO = 0.1;

	/**
	 * Syncs one language file against its source strings.
	 *
	 * @since 2.4.0
	 * @param \Loco_package_Bundle  $bundle  Bundle.
	 * @param \Loco_package_Project $project Project.
	 * @param \Loco_fs_LocaleFile   $file    PO file to sync.
	 * @param \Loco_Locale          $locale  Locale of the file.
	 * @param bool                  $dry_run Whether to report changes without writing.
	 * @param bool                  $confirm Whether large removals are approved.
	 * @return array<string, mixed>|WP_Error Report, or an error.
	 */
	public static function sync( \Loco_package_Bundle $bundle, \Loco_package_Project $project, \Loco_fs_LocaleFile $file, \Loco_Locale $locale, $dry_run, $confirm ) {
		$dry_run = (bool) $dry_run;
		$confirm = (bool) $confirm;

		if ( ! $dry_run ) {
			$writable = Loco_Guard::check_writable( $file );

			if ( is_wp_error( $writable ) ) {
				return $writable;
			}
		}

		try {
			$target = \Loco_gettext_Data::load( $file );
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

		$snapshot = self::snapshot( $target, $locale );
		$source   = self::load_source( $bundle, $project, $file, $target );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$matcher = new \Loco_gettext_Matcher( $project );
		$matcher->loadRefs( $source['data'], $source['translate'] );

		// JSON sources may add strings, so they must be loaded before fuzzy matching.
		if ( [] !== $source['jsons'] ) {
			$matcher->loadJsons( $source['jsons'] );
		}

		$matcher->setFuzziness( (string) \Loco_data_Settings::get()->fuzziness );

		$merged = clone $target;
		$merged->clear();

		$result = $matcher->merge( $target, $merged );
		$merged->sort();

		$report = self::build_report( $result, $snapshot, $merged, $source );

		if ( self::removal_blocked( $report, $snapshot, $confirm ) ) {
			$report['synced']  = false;
			$report['blocked'] = 'removals_need_confirmation';
			$report['message'] = sprintf(
				/* translators: 1: number of translated strings that would be deleted, 2: number currently translated. */
				__( 'This sync would delete %1$d translated strings out of %2$d, so nothing was written. Check that the plugin really removed them; a wrong template or a failed extraction looks the same. If the removals are correct, call this again with "confirm_removals": true.', 'loco-automatic-translate-addon-pro' ),
				$report['changes']['removed']['translated_count'],
				$snapshot['translated']
			);
			$report['file'] = Project_Service::file_summary( $file );

			return $report;
		}

		if ( $dry_run ) {
			$report['synced']  = false;
			$report['dry_run'] = true;
			$report['file']    = Project_Service::file_summary( $file );

			return $report;
		}

		if ( ! $report['changed'] ) {
			$report['synced']  = false;
			$report['message'] = __( 'The file already matches its source strings. Nothing was written.', 'loco-automatic-translate-addon-pro' );
			$report['file']    = Project_Service::file_summary( $file );

			return $report;
		}

		$merged->localize( $locale );

		$written = Loco_Guard::run_write(
			static function () use ( $file, $merged, $project ) {
				$compiler = new \Loco_gettext_Compiler( $file );

				return $compiler->writeAll( $merged, $project );
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

		$report['synced']        = true;
		$report['files_written'] = $paths;
		$report['file']          = Project_Service::file_summary( $file, true );
		$report['next_step']     = __( 'Call get-strings with status "untranslated" for the added strings, then with status "fuzzy" for the ones whose source text changed.', 'loco-automatic-translate-addon-pro' );
		$report['editor_notice'] = __( 'Saved. If the user has this file open in the Loco Translate editor, saving there will overwrite these changes; they should reload the editor first.', 'loco-automatic-translate-addon-pro' );

		return $report;
	}

	/**
	 * Records what the file contained before the merge.
	 *
	 * Removal counts must distinguish finished translations from empty entries,
	 * and the merge result reports keys only, so the originals are indexed here.
	 *
	 * @since 2.4.0
	 * @param \Loco_gettext_Data $data   Parsed PO data.
	 * @param \Loco_Locale       $locale Locale of the file.
	 * @return array{entries: array<string, array>, translated: int, total: int} Snapshot.
	 */
	private static function snapshot( \Loco_gettext_Data $data, \Loco_Locale $locale ) {
		$nplurals   = self::plural_count( $data, $locale );
		$entries    = [];
		$translated = 0;
		$total      = 0;

		foreach ( $data as $message ) {
			$done = self::is_translated( $message, $nplurals );

			$entries[ $message->getKey() ] = [
				'source'     => (string) $message['source'],
				'context'    => (string) $message->context,
				'translated' => $done,
			];

			++$total;

			if ( $done ) {
				++$translated;
			}
		}

		return [
			'entries'    => $entries,
			'translated' => $translated,
			'total'      => $total,
		];
	}

	/**
	 * Number of plural forms the file uses.
	 *
	 * @since 2.4.0
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
	 * Whether every form of an entry is filled in.
	 *
	 * @since 2.4.0
	 * @param \LocoPoMessage $message  Entry.
	 * @param int            $nplurals Plural form count.
	 * @return bool True when the entry counts as translated.
	 */
	private static function is_translated( \LocoPoMessage $message, $nplurals ) {
		if ( '' === (string) $message['target'] ) {
			return false;
		}

		$plurals = $message->plurals;

		if ( ! is_array( $plurals ) || [] === $plurals ) {
			return true;
		}

		for ( $form = 1; $form < $nplurals; $form++ ) {
			if ( ! isset( $plurals[ $form - 1 ] ) || '' === (string) $plurals[ $form - 1 ]['target'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolves the strings to sync against.
	 *
	 * Mirrors Loco_ajax_SyncController: an alternative template named in the PO
	 * headers wins, then the project's configured POT, then extraction from the
	 * plugin or theme source code.
	 *
	 * @since 2.4.0
	 * @param \Loco_package_Bundle  $bundle  Bundle.
	 * @param \Loco_package_Project $project Project.
	 * @param \Loco_fs_LocaleFile   $file    PO file being synced.
	 * @param \Loco_gettext_Data    $target  Parsed PO data, for its headers.
	 * @return array<string, mixed>|WP_Error Source descriptor, or an error.
	 */
	private static function load_source( \Loco_package_Bundle $bundle, \Loco_package_Project $project, \Loco_fs_LocaleFile $file, \Loco_gettext_Data $target ) {
		$options  = new \Loco_gettext_SyncOptions( $target->getHeaders() );
		$warnings = [];

		if ( $options->hasTemplate() ) {
			$potfile = $options->getTemplate();
			$potfile->normalize( $bundle->getDirectoryPath() );
		} else {
			$potfile = $project->getPot();

			// A project may name a localised file as its own template.
			if ( $potfile instanceof \Loco_fs_File && $potfile->equal( $file ) ) {
				$potfile = null;
			}
		}

		if ( $potfile instanceof \Loco_fs_File && ! $potfile->exists() ) {
			if ( 2 === (int) \Loco_data_Settings::get()->pot_expected ) {
				return new WP_Error(
					'atlt_template_required',
					sprintf(
						/* translators: %s: file name of the missing template. */
						__( 'Loco Translate settings disallow a missing template, and %s does not exist. Tell the user to create the template in Loco Translate, or to change that setting; no ability can do either.', 'loco-automatic-translate-addon-pro' ),
						$potfile->basename()
					)
				);
			}

			$warnings[] = sprintf(
				/* translators: %s: file name of the missing template. */
				__( 'The template %s is missing, so strings were extracted from source code instead.', 'loco-automatic-translate-addon-pro' ),
				$potfile->basename()
			);

			$potfile = null;
		}

		if ( $potfile instanceof \Loco_fs_File ) {
			try {
				$data = \Loco_gettext_Data::load( $potfile );
			} catch ( \Throwable $e ) {
				return new WP_Error(
					'atlt_template_unreadable',
					sprintf(
						/* translators: 1: file path, 2: reason reported by Loco Translate. */
						__( 'Loco Translate could not read the template %1$s: %2$s. Tell the user to open the project in Loco Translate and recreate the template.', 'loco-automatic-translate-addon-pro' ),
						Loco_Guard::relative_path( $potfile ),
						Loco_Guard::safe_message( $e )
					)
				);
			}

			// Only a PO used as a template can contribute translations.
			$translate = 'pot' !== $potfile->extension() && $options->mergeMsgstr();
			$jsons     = [];

			if ( $options->mergeJson() ) {
				$siblings = new \Loco_fs_Siblings( $potfile );
				$jsons    = $siblings->getJsons( $project->getDomain()->getName() );
			}

			return [
				'data'      => $data,
				'translate' => $translate,
				'jsons'     => $jsons,
				'origin'    => 'template',
				'template'  => $potfile->basename(),
				'warnings'  => $warnings,
			];
		}

		if ( ! extension_loaded( 'tokenizer' ) ) {
			return new WP_Error(
				'atlt_tokenizer_missing',
				__( 'This project has no POT template, and extracting strings from source code needs the PHP "tokenizer" extension, which this server lacks. Tell the user to ask their host to enable it, or to add a POT template to the plugin or theme.', 'loco-automatic-translate-addon-pro' )
			);
		}

		try {
			$extraction = new \Loco_gettext_Extraction( $bundle );
			$extraction->addProject( $project );

			// Skipped files mean missing strings, which would look like removals.
			$skipped = $extraction->getSkipped();

			if ( $skipped ) {
				$warnings[] = sprintf(
					/* translators: %d: number of source files skipped. */
					_n(
						'%d source file was too large to scan, so strings in it are missing from this sync.',
						'%d source files were too large to scan, so strings in them are missing from this sync.',
						count( $skipped ),
						'loco-automatic-translate-addon-pro'
					),
					count( $skipped )
				);
			}

			$data = $extraction->includeMeta()->getTemplate( $project->getDomain()->getName() );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'atlt_extraction_failed',
				sprintf(
					/* translators: %s: reason reported by Loco Translate. */
					__( 'Loco Translate could not extract strings from the source code: %s. Tell the user to open the project in Loco Translate and click "Create template" to see the full error.', 'loco-automatic-translate-addon-pro' ),
					Loco_Guard::safe_message( $e )
				)
			);
		}

		return [
			'data'      => $data,
			'translate' => false,
			'jsons'     => [],
			'origin'    => 'source_code',
			'template'  => null,
			'warnings'  => $warnings,
		];
	}

	/**
	 * Turns the merge result into a caller-facing report.
	 *
	 * Loco reports entry keys; sources and contexts are recovered here so the
	 * caller never has to parse gettext's internal key format.
	 *
	 * @since 2.4.0
	 * @param array<string, mixed> $result   Return value of Loco_gettext_Matcher::merge().
	 * @param array<string, mixed> $snapshot Pre-merge snapshot.
	 * @param \Loco_gettext_Data   $merged   Merged data.
	 * @param array<string, mixed> $source   Resolved source descriptor.
	 * @return array<string, mixed> Report, without the file summary.
	 */
	private static function build_report( array $result, array $snapshot, \Loco_gettext_Data $merged, array $source ) {
		$added   = isset( $result['add'] ) ? (array) $result['add'] : [];
		$fuzzy   = isset( $result['fuz'] ) ? (array) $result['fuz'] : [];
		$dropped = isset( $result['del'] ) ? (array) $result['del'] : [];

		$total = 0;
		foreach ( $merged as $ignored ) {
			unset( $ignored );
			++$total;
		}

		$removed_translated = 0;
		foreach ( $dropped as $key ) {
			if ( ! empty( $snapshot['entries'][ $key ]['translated'] ) ) {
				++$removed_translated;
			}
		}

		$report = [
			'source'   => $source['origin'],
			'template' => $source['template'],
			'changed'  => [] !== $added || [] !== $fuzzy || [] !== $dropped,
			'changes'  => [
				'added'   => [
					'count'   => count( $added ),
					'samples' => self::samples( $added, $snapshot, $merged ),
				],
				'fuzzy'   => [
					'count'   => count( $fuzzy ),
					'samples' => self::samples( $fuzzy, $snapshot, $merged ),
				],
				'removed' => [
					'count'            => count( $dropped ),
					'translated_count' => $removed_translated,
					'samples'          => self::samples( $dropped, $snapshot, $merged ),
				],
				'kept'    => max( 0, $total - count( $added ) - count( $fuzzy ) ),
			],
		];

		if ( [] !== $source['warnings'] ) {
			$report['warnings'] = $source['warnings'];
		}

		return $report;
	}

	/**
	 * Readable examples for one change bucket.
	 *
	 * @since 2.4.0
	 * @param string[]             $keys     Entry keys from the merge result.
	 * @param array<string, mixed> $snapshot Pre-merge snapshot.
	 * @param \Loco_gettext_Data   $merged   Merged data, for entries that are new.
	 * @return array<int, array<string, string>> Source and context pairs.
	 */
	private static function samples( array $keys, array $snapshot, \Loco_gettext_Data $merged ) {
		$keys = array_slice( $keys, 0, self::MAX_SAMPLES );

		if ( [] === $keys ) {
			return [];
		}

		$found = [];

		foreach ( $keys as $key ) {
			if ( isset( $snapshot['entries'][ $key ] ) ) {
				$found[ $key ] = [
					'source'  => $snapshot['entries'][ $key ]['source'],
					'context' => $snapshot['entries'][ $key ]['context'],
				];
			}
		}

		// Added strings are not in the snapshot, so read those from the merged set.
		if ( count( $found ) < count( $keys ) ) {
			$wanted = array_flip( $keys );

			foreach ( $merged as $message ) {
				$key = $message->getKey();

				if ( isset( $wanted[ $key ] ) && ! isset( $found[ $key ] ) ) {
					$found[ $key ] = [
						'source'  => (string) $message['source'],
						'context' => (string) $message->context,
					];
				}
			}
		}

		$samples = [];
		foreach ( $keys as $key ) {
			if ( isset( $found[ $key ] ) ) {
				$samples[] = $found[ $key ];
			}
		}

		return $samples;
	}

	/**
	 * Whether the sync deletes enough finished work to need confirmation.
	 *
	 * @since 2.4.0
	 * @param array<string, mixed> $report   Report built so far.
	 * @param array<string, mixed> $snapshot Pre-merge snapshot.
	 * @param bool                 $confirm  Whether the caller approved removals.
	 * @return bool True when the sync must not be written yet.
	 */
	private static function removal_blocked( array $report, array $snapshot, $confirm ) {
		if ( $confirm ) {
			return false;
		}

		$removed = (int) $report['changes']['removed']['translated_count'];

		if ( $removed <= self::REMOVAL_FLOOR ) {
			return false;
		}

		return $removed > (int) floor( $snapshot['translated'] * self::REMOVAL_RATIO );
	}

	/**
	 * Adds the bundle to Loco Translate's recent items, as its editor does on save.
	 *
	 * @since 2.4.0
	 * @param \Loco_package_Bundle $bundle Bundle.
	 * @return void
	 */
	private static function remember_bundle( \Loco_package_Bundle $bundle ) {
		try {
			\Loco_data_RecentItems::get()->pushBundle( $bundle )->persist();
		} catch ( \Throwable $e ) {
			// Recent items are a convenience; never fail a completed sync over them.
			unset( $e );
		}
	}
}
