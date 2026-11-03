<?php
/**
 * POT template creation.
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
 * Builds a project's POT template from its source code, following Loco
 * Translate's own "Create template" flow (Loco_ajax_XgettextController).
 *
 * Every step delegates to Loco Translate: the target path comes from
 * Loco_package_Project::getPot(), the strings from Loco_gettext_Extraction and
 * the write from Loco_fs_File, so the result is byte-for-byte what clicking
 * "Create template" in the admin produces.
 *
 * @since 2.5.0
 */
final class Template_Service {

	/**
	 * Creates the POT file for a project that has none.
	 *
	 * @since 2.5.0
	 * @param \Loco_package_Bundle  $bundle  Bundle.
	 * @param \Loco_package_Project $project Project.
	 * @return array<string, mixed>|WP_Error Result, or an error.
	 */
	public static function create( \Loco_package_Bundle $bundle, \Loco_package_Project $project ) {
		$pot = self::target_file( $project );

		if ( is_wp_error( $pot ) ) {
			return $pot;
		}

		if ( $pot->exists() ) {
			return new WP_Error(
				'atlt_template_exists',
				sprintf(
					/* translators: %s: file path. */
					__( 'This project already has a POT template at %s. Nothing was created, and no ability overwrites a template. Call create-language-file to start a new language from it.', 'loco-automatic-translate-addon-pro' ),
					Loco_Guard::relative_path( $pot )
				)
			);
		}

		$writable = Loco_Guard::check_writable( $pot );

		if ( is_wp_error( $writable ) ) {
			return $writable;
		}

		$data = self::extract( $bundle, $project );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Name the template after the project, exactly as Loco_ajax_XgettextController does.
		$headers                       = $data->getHeaders();
		$headers['Project-Id-Version'] = $project->getName();

		$written = Loco_Guard::run_write(
			static function () use ( $pot, $data ) {
				return $pot->putContents( $data->msgcat( true ) );
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

		return [
			'created'         => true,
			'path'            => Loco_Guard::relative_path( $pot ),
			'strings'         => (int) \Loco_gettext_Metadata::load( $pot )->getTotal(),
			'location_notice' => self::location_notice( $bundle, $pot ),
			'next_step'       => __( 'Call create-language-file with the same bundle and domain for each locale the user wants; every new file now starts from this template instead of re-reading the source code.', 'loco-automatic-translate-addon-pro' ),
		];
	}

	/**
	 * Extracts a project's strings from its source code.
	 *
	 * Shared with Language_File_Service, which starts a new PO from these
	 * strings when a project has no POT on disk.
	 *
	 * @since 2.5.0
	 * @param \Loco_package_Bundle  $bundle  Bundle.
	 * @param \Loco_package_Project $project Project.
	 * @return \Loco_gettext_Data|WP_Error Extracted template, or an error.
	 */
	public static function extract( \Loco_package_Bundle $bundle, \Loco_package_Project $project ) {
		if ( ! extension_loaded( 'tokenizer' ) ) {
			return new WP_Error(
				'atlt_tokenizer_missing',
				__( 'Extracting strings from source code needs the PHP "tokenizer" extension, which this server lacks. Tell the user to ask their host to enable it, or to add a POT template to the plugin or theme.', 'loco-automatic-translate-addon-pro' )
			);
		}

		try {
			$extraction = new \Loco_gettext_Extraction( $bundle );

			return $extraction->addProject( $project )->includeMeta()->getTemplate( $project->getDomain()->getName() );
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
	}

	/**
	 * Resolves where Loco Translate would put this project's template.
	 *
	 * @since 2.5.0
	 * @param \Loco_package_Project $project Project.
	 * @return \Loco_fs_File|WP_Error Target file, or an error.
	 */
	private static function target_file( \Loco_package_Project $project ) {
		try {
			$pot = $project->getPot();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'atlt_template_path_unknown',
				sprintf(
					/* translators: %s: reason reported by Loco Translate. */
					__( 'Loco Translate could not work out where this project\'s template belongs: %s. Tell the user to create it in Loco Translate instead.', 'loco-automatic-translate-addon-pro' ),
					Loco_Guard::safe_message( $e )
				)
			);
		}

		if ( ! $pot instanceof \Loco_fs_File ) {
			return new WP_Error(
				'atlt_template_path_unknown',
				__( 'Loco Translate did not offer a template path for this project. Tell the user to create the template in Loco Translate instead.', 'loco-automatic-translate-addon-pro' )
			);
		}

		return $pot;
	}

	/**
	 * Warns when the template lands inside the plugin or theme being translated.
	 *
	 * Loco Translate puts a template next to the code it describes, which is
	 * correct for an author working on their own plugin but means the file is
	 * deleted the next time a third-party plugin updates. A human clicking
	 * "Create template" sees the path; an AI client has to be told.
	 *
	 * @since 2.5.0
	 * @param \Loco_package_Bundle $bundle Bundle.
	 * @param \Loco_fs_File        $pot    Template file.
	 * @return string Notice, empty when the template is outside the bundle.
	 */
	private static function location_notice( \Loco_package_Bundle $bundle, \Loco_fs_File $pot ) {
		if ( ! $bundle->hasDirectoryPath() ) {
			return '';
		}

		$root = trailingslashit( wp_normalize_path( $bundle->getDirectoryPath() ) );
		$path = wp_normalize_path( $pot->getPath() );

		if ( '/' === $root || 0 !== strpos( $path, $root ) ) {
			return '';
		}

		return sprintf(
			/* translators: %s: bundle name. */
			__( 'The template was written inside %s itself, which is where Loco Translate keeps templates. Tell the user it will be deleted the next time that plugin or theme updates, and can simply be created again; their translations are not affected, because those live elsewhere.', 'loco-automatic-translate-addon-pro' ),
			$bundle->getName()
		);
	}
}
