<?php
/**
 * Creation of new PO language files.
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
 * Creates a PO file for a new language, following Loco Translate's own
 * "New language" flow (Loco_ajax_MsginitController) without copying an
 * existing translation.
 *
 * @since 2.3.0
 */
final class Language_File_Service {

	/**
	 * Creates the PO (and an empty MO) for a locale.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Bundle  $bundle            Bundle.
	 * @param \Loco_package_Project $project           Project.
	 * @param \Loco_Locale          $locale            Locale.
	 * @param string                $location          Location key: custom, system or author.
	 * @param bool                  $location_explicit Whether the caller chose the location.
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	public static function create( \Loco_package_Bundle $bundle, \Loco_package_Project $project, \Loco_Locale $locale, $location, $location_explicit ) {
		$target = self::target_file( $project, $locale, $location );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$existing = Project_Service::find_po_files( $project, $locale );
		$others   = [];

		foreach ( $existing as $file ) {
			if ( $file->getPath() === $target->getPath() ) {
				return new WP_Error(
					'atlt_language_file_exists',
					sprintf(
						/* translators: 1: locale code, 2: file path. */
						__( 'A %1$s file already exists at %2$s. Nothing was created. Use get-strings and save-translations on it.', 'loco-automatic-translate-addon-pro' ),
						(string) $locale,
						Loco_Guard::relative_path( $file )
					)
				);
			}

			$others[] = $file;
		}

		if ( [] !== $others && ! $location_explicit ) {
			return new WP_Error(
				'atlt_language_exists_elsewhere',
				sprintf(
					/* translators: 1: locale code, 2: file path, 3: location key. */
					__( 'A %1$s file already exists at %2$s ("%3$s" location), so nothing was created. Use get-strings and save-translations on it. Only if the user explicitly wants a second copy, call this again with "location" set.', 'loco-automatic-translate-addon-pro' ),
					(string) $locale,
					Loco_Guard::relative_path( $others[0] ),
					Loco_Guard::location_key( $others[0] )
				)
			);
		}

		if ( $target->cloneExtension( 'mo' )->exists() ) {
			return new WP_Error(
				'atlt_mo_exists',
				sprintf(
					/* translators: %s: file path. */
					__( 'A compiled MO file already exists at %s without a PO file, and Loco Translate will not overwrite it. Tell the user to delete it in Loco Translate or choose another location; no ability deletes files.', 'loco-automatic-translate-addon-pro' ),
					Loco_Guard::relative_path( $target->cloneExtension( 'mo' ) )
				)
			);
		}

		$writable = Loco_Guard::check_writable( $target );

		if ( is_wp_error( $writable ) ) {
			return $writable;
		}

		$template = self::load_template( $bundle, $project );

		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$data    = $template['data'];
		$headers = [];
		$version = $data->getHeaders()->trimmed( 'Project-Id-Version' );

		if ( '' === $version || 'PACKAGE VERSION' === $version ) {
			$headers['Project-Id-Version'] = $project->getName();
		}

		$data->localize( $locale, $headers );

		$written = Loco_Guard::run_write(
			static function () use ( $target, $data ) {
				$compiler = new \Loco_gettext_Compiler( $target );
				$compiler->writePo( $data );

				// An untranslated file needs no JSON, but an MO keeps the set complete, as Loco does.
				$data->clear();
				$compiler->writeMo( $data );

				return true;
			}
		);

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		try {
			\Loco_data_RecentItems::get()->pushBundle( $bundle )->persist();
		} catch ( \Throwable $e ) {
			// Recent items are a convenience; never fail a completed creation over them.
			unset( $e );
		}

		$result = [
			'created'         => true,
			'file'            => Project_Service::file_summary( $target, true ),
			'template_source' => $template['source'],
			'next_step'       => __( 'Call get-strings with the same bundle, domain and locale (status "untranslated"), translate the strings, then send them to save-translations.', 'loco-automatic-translate-addon-pro' ),
		];

		if ( [] !== $others ) {
			$result['other_files'] = array_map( [ Project_Service::class, 'file_summary' ], $others );
		}

		return $result;
	}

	/**
	 * Resolves the new PO file path for a location.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Project $project  Project.
	 * @param \Loco_Locale          $locale   Locale.
	 * @param string                $location Location key.
	 * @return \Loco_fs_LocaleFile|WP_Error File, or an error.
	 */
	private static function target_file( \Loco_package_Project $project, \Loco_Locale $locale, $location ) {
		$available = [];

		foreach ( $project->initLocaleFiles( $locale ) as $candidate ) {
			$key = Loco_Guard::location_key( $candidate );

			if ( $key === $location ) {
				return $candidate instanceof \Loco_fs_LocaleFile ? $candidate : new \Loco_fs_LocaleFile( $candidate->getPath() );
			}

			$available[] = $key;
		}

		return new WP_Error(
			'atlt_location_unavailable',
			sprintf(
				/* translators: 1: requested location, 2: comma-separated available locations. */
				__( 'This project has no "%1$s" location. Available locations: %2$s.', 'loco-automatic-translate-addon-pro' ),
				(string) $location,
				implode( ', ', array_unique( $available ) )
			)
		);
	}

	/**
	 * Loads the strings a new file starts from: the POT file, or the source code.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Bundle  $bundle  Bundle.
	 * @param \Loco_package_Project $project Project.
	 * @return array{data: \Loco_gettext_Data, source: string}|WP_Error Template, or an error.
	 */
	private static function load_template( \Loco_package_Bundle $bundle, \Loco_package_Project $project ) {
		$pot = Project_Service::template_file( $project );

		if ( null !== $pot ) {
			try {
				return [
					'data'   => \Loco_gettext_Data::load( $pot ),
					'source' => 'template',
				];
			} catch ( \Throwable $e ) {
				return new WP_Error(
					'atlt_template_unreadable',
					sprintf(
						/* translators: 1: file path, 2: reason reported by Loco Translate. */
						__( 'Loco Translate could not read the template %1$s: %2$s. Tell the user to open the project in Loco Translate and recreate the template.', 'loco-automatic-translate-addon-pro' ),
						Loco_Guard::relative_path( $pot ),
						Loco_Guard::safe_message( $e )
					)
				);
			}
		}

		if ( Project_Service::template_required() ) {
			return new WP_Error(
				'atlt_template_required',
				__( 'This project has no POT template and Loco Translate > Settings requires one. Call create-template for this bundle first, then call this again.', 'loco-automatic-translate-addon-pro' )
			);
		}

		$data = Template_Service::extract( $bundle, $project );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return [
			'data'   => $data,
			'source' => 'source_code',
		];
	}
}
