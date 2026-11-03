<?php
/**
 * Plugin, theme and core translation project queries.
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
 * Reads Loco Translate bundles, projects and their translation files.
 *
 * @since 2.3.0
 */
final class Project_Service {

	/**
	 * Lists translation projects, paginated by bundle.
	 *
	 * Locale files are only scanned for bundles on the requested page, because
	 * each scan walks the plugin or theme's language directories.
	 *
	 * @since 2.3.0
	 * @param string            $type        "plugin", "theme", "core" or "all".
	 * @param string            $search      Case-insensitive match on name, handle or text domain.
	 * @param bool              $active_only Whether to skip inactive plugins and themes.
	 * @param \Loco_Locale|null $locale      Optional locale to report per project.
	 * @param int               $page        1-based page number.
	 * @param int               $per_page    Bundles per page.
	 * @return array<string, mixed> Listing.
	 */
	public static function list_projects( $type, $search, $active_only, $locale, $page, $per_page ) {
		Loco_Guard::load_plugin_functions();

		$bundles = self::collect_bundles( (string) $type );
		$needle  = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $search ) : strtolower( (string) $search );
		$matched = [];

		foreach ( $bundles as $bundle ) {
			$active = self::is_active( $bundle );

			if ( $active_only && ! $active ) {
				continue;
			}

			if ( '' !== $needle && ! self::bundle_matches( $bundle, $needle ) ) {
				continue;
			}

			$matched[] = [
				'bundle' => $bundle,
				'active' => $active,
			];
		}

		$total  = count( $matched );
		$offset = ( $page - 1 ) * $per_page;
		$items  = [];

		foreach ( array_slice( $matched, $offset, $per_page ) as $row ) {
			$items[] = self::bundle_summary( $row['bundle'], $row['active'], $locale );
		}

		return [
			'bundles'    => $items,
			'pagination' => [
				'page'        => $page,
				'per_page'    => $per_page,
				'total_items' => $total,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			],
		];
	}

	/**
	 * Full status of one project: template and every translation file.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Bundle  $bundle  Bundle.
	 * @param \Loco_package_Project $project Project.
	 * @param \Loco_Locale|null     $locale  Optional locale filter.
	 * @return array<string, mixed> Status payload.
	 */
	public static function project_status( \Loco_package_Bundle $bundle, \Loco_package_Project $project, $locale ) {
		$template_file = self::template_file( $project );
		$template      = [ 'exists' => null !== $template_file ];

		if ( null !== $template_file ) {
			$meta      = \Loco_gettext_Metadata::load( $template_file );
			$template += [
				'path'    => Loco_Guard::relative_path( $template_file ),
				'strings' => (int) $meta->getTotal(),
				'locked'  => (bool) $project->isPotLocked(),
			];
		}

		$files = [];
		foreach ( self::find_po_files( $project, $locale ) as $file ) {
			$files[] = self::file_summary( $file );
		}

		$template_required = self::template_required();

		$status = [
			'bundle'              => $bundle->getId(),
			'type'                => strtolower( $bundle->getType() ),
			'name'                => $bundle->getName(),
			'project'             => self::project_identity( $project ),
			'template'            => $template,
			'files'               => $files,
			'can_create_language' => null !== $template_file || ! $template_required,
		];

		if ( null === $template_file ) {
			$status['template_notice'] = $template_required
				? __( 'This project has no POT template and Loco Translate > Settings requires one, so create-language-file will refuse. Call create-template for this bundle first.', 'loco-automatic-translate-addon-pro' )
				: __( 'This project has no POT template. create-language-file still works: it extracts the strings from the plugin or theme source code, but repeats that scan for every language. Call create-template once first when translating into more than one language.', 'loco-automatic-translate-addon-pro' );
		}

		if ( null !== $locale && [] === $files ) {
			$status['locale_notice'] = sprintf(
				/* translators: %s: locale code. */
				__( 'No %s translation file exists yet. Call create-language-file with the same bundle, domain and locale to create one.', 'loco-automatic-translate-addon-pro' ),
				(string) $locale
			);
		}

		return $status;
	}

	/**
	 * Finds the project's PO files, sorted by location preference.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Project $project Project.
	 * @param \Loco_Locale|null     $locale  Optional locale filter.
	 * @return \Loco_fs_LocaleFile[] Files.
	 */
	public static function find_po_files( \Loco_package_Project $project, $locale ) {
		$tag   = null !== $locale ? (string) $locale : '';
		$files = [];

		foreach ( $project->findLocaleFiles( 'po' ) as $file ) {
			if ( '' !== $tag && (string) $file->getLocale() !== $tag ) {
				continue;
			}

			$files[] = $file;
		}

		usort(
			$files,
			static function ( $a, $b ) {
				$order = array_flip( Loco_Guard::LOCATION_ORDER );
				$diff  = $order[ Loco_Guard::location_key( $a ) ] - $order[ Loco_Guard::location_key( $b ) ];

				return 0 !== $diff ? $diff : strcmp( (string) $a->getLocale(), (string) $b->getLocale() );
			}
		);

		return $files;
	}

	/**
	 * Picks the PO file an ability should read or write.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Project $project  Project.
	 * @param \Loco_Locale          $locale   Locale.
	 * @param string                $location Location key, or "" for the preferred existing file.
	 * @return array{file: \Loco_fs_LocaleFile, others: \Loco_fs_LocaleFile[]}|WP_Error Selection, or an error.
	 */
	public static function pick_po_file( \Loco_package_Project $project, \Loco_Locale $locale, $location ) {
		$files = self::find_po_files( $project, $locale );

		if ( [] === $files ) {
			return new WP_Error(
				'atlt_po_not_found',
				sprintf(
					/* translators: 1: locale code, 2: project name. */
					__( 'No %1$s translation file exists for "%2$s". Call create-language-file with the same bundle, domain and locale first.', 'loco-automatic-translate-addon-pro' ),
					(string) $locale,
					$project->getName()
				)
			);
		}

		$chosen = null;

		foreach ( $files as $file ) {
			if ( '' === $location || Loco_Guard::location_key( $file ) === $location ) {
				$chosen = $file;
				break;
			}
		}

		if ( null === $chosen ) {
			$existing = [];
			foreach ( $files as $file ) {
				$existing[] = Loco_Guard::location_key( $file );
			}

			return new WP_Error(
				'atlt_po_location_not_found',
				sprintf(
					/* translators: 1: locale code, 2: requested location, 3: comma-separated existing locations. */
					__( 'No %1$s file exists in the "%2$s" location. Files exist in: %3$s. Omit "location" to use the preferred file, or pass one of those.', 'loco-automatic-translate-addon-pro' ),
					(string) $locale,
					$location,
					implode( ', ', array_unique( $existing ) )
				)
			);
		}

		$others = [];
		foreach ( $files as $file ) {
			if ( $file !== $chosen ) {
				$others[] = $file;
			}
		}

		return [
			'file'   => $chosen,
			'others' => $others,
		];
	}

	/**
	 * Summary of one PO file with translation progress.
	 *
	 * @since 2.3.0
	 * @param \Loco_fs_LocaleFile $file     PO file.
	 * @param bool                $no_cache Whether to bypass Loco's metadata cache, e.g. right after a write.
	 * @return array<string, mixed> Summary.
	 */
	public static function file_summary( \Loco_fs_LocaleFile $file, $no_cache = false ) {
		if ( $no_cache ) {
			// PHP caches filemtime() and filesize() per request; a write in this request made them stale.
			clearstatcache( true, $file->getPath() );
			clearstatcache( true, $file->cloneExtension( 'mo' )->getPath() );
		}

		$meta     = \Loco_gettext_Metadata::load( $file, (bool) $no_cache );
		$stats    = $meta->getStats();
		$total    = (int) $stats['t'];
		$progress = (int) $stats['p'];
		$fuzzy    = (int) $stats['f'];
		$done     = max( 0, $progress - $fuzzy );
		$locale   = $file->getLocale();
		$location = Loco_Guard::location_key( $file );

		$summary = [
			'locale'         => (string) $locale,
			'language'       => (string) ( $locale->getName() ? $locale->getName() : $locale->buildName() ),
			'location'       => $location,
			'location_label' => Loco_Guard::location_label( $location ),
			'path'           => Loco_Guard::relative_path( $file ),
			'valid'          => ! empty( $meta['valid'] ),
			'total'          => $total,
			'translated'     => $done,
			'fuzzy'          => $fuzzy,
			'untranslated'   => max( 0, $total - $progress ),
			'percent'        => $total > 0 ? round( $done * 100 / $total, 1 ) : 0.0,
			'writable'       => $file->writable(),
			'has_mo'         => $file->cloneExtension( 'mo' )->exists(),
			'modified'       => gmdate( 'c', (int) $file->modified() ),
		];

		if ( empty( $meta['valid'] ) && isset( $meta['error'] ) ) {
			$summary['error'] = (string) $meta['error'];
		}

		return $summary;
	}

	/**
	 * The project's POT file, when one exists on disk.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Project $project Project.
	 * @return \Loco_fs_File|null Template file, or null.
	 */
	public static function template_file( \Loco_package_Project $project ) {
		try {
			$pot = $project->getPot();
		} catch ( \Throwable $e ) {
			return null;
		}

		return $pot instanceof \Loco_fs_File && $pot->exists() ? $pot : null;
	}

	/**
	 * Whether Loco Translate's settings forbid working without a POT file.
	 *
	 * @since 2.3.0
	 * @return bool True when a template is mandatory.
	 */
	public static function template_required() {
		return 2 <= (int) \Loco_data_Settings::get()->pot_expected;
	}

	/**
	 * Identity fields of a project, as callers pass them back.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Project $project Project.
	 * @return array<string, string> Identity.
	 */
	public static function project_identity( \Loco_package_Project $project ) {
		return [
			'domain'      => $project->getId(),
			'text_domain' => $project->getDomain()->getName(),
			'name'        => $project->getName(),
		];
	}

	/**
	 * Name used for the bundle in LocoAI's dashboard statistics.
	 *
	 * Mirrors getProjectName() in assets/js/pro-shared.js: the plugin folder for
	 * plugins and the text domain for themes, so both flows count as one entry.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Bundle  $bundle  Bundle.
	 * @param \Loco_package_Project $project Project.
	 * @return string Statistics label.
	 */
	public static function stats_name( \Loco_package_Bundle $bundle, \Loco_package_Project $project ) {
		if ( $bundle->isTheme() ) {
			return $project->getDomain()->getName();
		}

		if ( $bundle->isPlugin() ) {
			$handle = $bundle->getHandle();
			$slash  = strpos( $handle, '/' );

			return false !== $slash ? substr( $handle, 0, $slash ) : preg_replace( '/\.php$/', '', $handle );
		}

		return 'wordpress';
	}

	/**
	 * Collects bundles of the requested type.
	 *
	 * @since 2.3.0
	 * @param string $type "plugin", "theme", "core" or "all".
	 * @return \Loco_package_Bundle[] Bundles.
	 */
	private static function collect_bundles( $type ) {
		$bundles = [];

		if ( 'all' === $type || 'plugin' === $type ) {
			foreach ( \Loco_package_Plugin::getAll() as $bundle ) {
				$bundles[] = $bundle;
			}
		}

		if ( 'all' === $type || 'theme' === $type ) {
			foreach ( \Loco_package_Theme::getAll() as $bundle ) {
				$bundles[] = $bundle;
			}
		}

		if ( 'all' === $type || 'core' === $type ) {
			try {
				$bundles[] = \Loco_package_Core::create();
			} catch ( \Throwable $e ) {
				// Core is optional in the listing; a broken core config must not hide plugins.
				unset( $e );
			}
		}

		return $bundles;
	}

	/**
	 * Whether a bundle is in use on the site.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Bundle $bundle Bundle.
	 * @return bool True for active plugins, must-use plugins, the active theme and core.
	 */
	private static function is_active( \Loco_package_Bundle $bundle ) {
		$handle = $bundle->getHandle();

		if ( $bundle->isPlugin() ) {
			if ( is_plugin_active( $handle ) ) {
				return true;
			}

			$mu_plugins = function_exists( 'get_mu_plugins' ) ? get_mu_plugins() : [];

			return array_key_exists( $handle, $mu_plugins );
		}

		if ( $bundle->isTheme() ) {
			return in_array( $handle, [ get_stylesheet(), get_template() ], true );
		}

		return true;
	}

	/**
	 * Whether a bundle matches a lower-cased search term.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Bundle $bundle Bundle.
	 * @param string               $needle Lower-cased search term.
	 * @return bool True on a match.
	 */
	private static function bundle_matches( \Loco_package_Bundle $bundle, $needle ) {
		$haystack = [ $bundle->getName(), $bundle->getHandle() ];

		foreach ( $bundle as $project ) {
			$haystack[] = $project->getDomain()->getName();
		}

		$haystack = implode( "\n", $haystack );
		$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );

		return false !== strpos( $haystack, $needle );
	}

	/**
	 * Listing entry for one bundle.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Bundle $bundle Bundle.
	 * @param bool                 $active Whether the bundle is in use.
	 * @param \Loco_Locale|null    $locale Optional locale to report per project.
	 * @return array<string, mixed> Entry.
	 */
	private static function bundle_summary( \Loco_package_Bundle $bundle, $active, $locale ) {
		$projects = [];

		foreach ( $bundle as $project ) {
			$languages = [];

			foreach ( self::find_po_files( $project, null ) as $file ) {
				$languages[] = [
					'locale'   => (string) $file->getLocale(),
					'location' => Loco_Guard::location_key( $file ),
				];
			}

			$entry = self::project_identity( $project ) + [
				'has_template' => null !== self::template_file( $project ),
				'languages'    => $languages,
			];

			if ( null !== $locale ) {
				$entry['has_locale'] = in_array( (string) $locale, array_column( $languages, 'locale' ), true );
			}

			$projects[] = $entry;
		}

		$summary = [
			'bundle'   => $bundle->getId(),
			'type'     => strtolower( $bundle->getType() ),
			'name'     => $bundle->getName(),
			'active'   => (bool) $active,
			'projects' => $projects,
		];

		if ( [] === $projects ) {
			$summary['notice'] = __( 'No text domain detected, so Loco Translate cannot translate this yet. Tell the user to open it in Loco Translate and complete "Setup" if it needs translating.', 'loco-automatic-translate-addon-pro' );
		}

		return $summary;
	}
}
