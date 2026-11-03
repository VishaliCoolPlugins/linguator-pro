<?php
/**
 * Loco Translate availability, resolution and write guards.
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
 * Resolves ability input to Loco Translate objects and guards file writes.
 *
 * Every ability goes through this class so that the Loco version check, the
 * bundle type allow-list and the REST-safe write wrapper live in one place.
 *
 * @since 2.3.0
 */
final class Loco_Guard {

	/**
	 * Oldest Loco Translate release whose internal API the abilities use.
	 *
	 * @since 2.3.0
	 * @var string
	 */
	const MIN_LOCO_VERSION = '2.7.0';

	/**
	 * Bundle types Loco Translate can create from an ID.
	 *
	 * @since 2.3.0
	 * @var string[]
	 */
	const BUNDLE_TYPES = [ 'plugin', 'theme', 'core' ];

	/**
	 * Location keys in the order they are preferred when several files exist.
	 *
	 * Custom files are loaded on top of system and author files at runtime, so
	 * they are what the site actually shows.
	 *
	 * @since 2.3.0
	 * @var string[]
	 */
	const LOCATION_ORDER = [ 'custom', 'system', 'author', 'other' ];

	/**
	 * Whether a supported Loco Translate is loaded.
	 *
	 * @since 2.3.0
	 * @return bool True when the abilities can run.
	 */
	public static function is_available() {
		if ( ! function_exists( 'loco_plugin_version' ) ) {
			return false;
		}

		if ( version_compare( (string) loco_plugin_version(), self::MIN_LOCO_VERSION, '<' ) ) {
			return false;
		}

		// Triggers Loco's own autoloader, which is registered on every request.
		return class_exists( 'Loco_package_Bundle' );
	}

	/**
	 * Error returned when Loco Translate is missing or too old.
	 *
	 * @since 2.3.0
	 * @return WP_Error Error object.
	 */
	public static function unavailable_error() {
		return new WP_Error(
			'atlt_loco_unavailable',
			sprintf(
				/* translators: %s: minimum Loco Translate version. */
				__( 'Loco Translate %s or later is not active in this request. Tell the user to install or update Loco Translate under Plugins; no ability can do that. Retry once it is active.', 'loco-automatic-translate-addon-pro' ),
				self::MIN_LOCO_VERSION
			)
		);
	}

	/**
	 * Loads the wp-admin plugin functions Loco relies on to list plugins.
	 *
	 * REST and MCP requests do not include wp-admin/includes/plugin.php, but
	 * Loco_package_Plugin::get_plugins() calls get_plugins() and get_mu_plugins().
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public static function load_plugin_functions() {
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Resolves a bundle ID such as "plugin.akismet/akismet.php".
	 *
	 * @since 2.3.0
	 * @param string $bundle_id Bundle ID from list-projects.
	 * @return \Loco_package_Bundle|WP_Error Bundle, or an error.
	 */
	public static function resolve_bundle( $bundle_id ) {
		$bundle_id = trim( (string) $bundle_id );

		if ( '' === $bundle_id ) {
			return new WP_Error(
				'atlt_bundle_required',
				__( 'The "bundle" input is required. Call list-projects to find the bundle ID of the plugin or theme.', 'loco-automatic-translate-addon-pro' )
			);
		}

		$parts  = explode( '.', $bundle_id, 2 );
		$type   = strtolower( $parts[0] );
		$handle = isset( $parts[1] ) ? $parts[1] : '';

		if ( ! in_array( $type, self::BUNDLE_TYPES, true ) || ( 'core' !== $type && '' === $handle ) ) {
			return new WP_Error(
				'atlt_bundle_invalid',
				sprintf(
					/* translators: %s: bundle ID supplied by the caller. */
					__( '"%s" is not a valid bundle ID. Bundle IDs look like "plugin.folder/file.php", "theme.folder" or "core"; copy one exactly from list-projects.', 'loco-automatic-translate-addon-pro' ),
					$bundle_id
				)
			);
		}

		// Plugin handles are relative paths; refuse traversal before touching the filesystem.
		if ( 'core' !== $type && 0 !== validate_file( $handle ) ) {
			return new WP_Error(
				'atlt_bundle_invalid',
				__( 'The bundle handle contains an invalid path. Copy the bundle ID exactly from list-projects.', 'loco-automatic-translate-addon-pro' )
			);
		}

		self::load_plugin_functions();

		try {
			return \Loco_package_Bundle::createType( $type, $handle );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'atlt_bundle_not_found',
				sprintf(
					/* translators: 1: bundle ID, 2: reason reported by Loco Translate. */
					__( 'Loco Translate could not load bundle "%1$s": %2$s. Call list-projects for the current bundle IDs; the plugin or theme may have been removed or renamed.', 'loco-automatic-translate-addon-pro' ),
					$bundle_id,
					self::safe_message( $e )
				)
			);
		}
	}

	/**
	 * Resolves the translation project inside a bundle.
	 *
	 * @since 2.3.0
	 * @param \Loco_package_Bundle $bundle Bundle.
	 * @param string               $domain Project ID from list-projects, or "" for the default project.
	 * @return \Loco_package_Project|WP_Error Project, or an error.
	 */
	public static function resolve_project( \Loco_package_Bundle $bundle, $domain ) {
		$domain = trim( (string) $domain );

		$project = '' === $domain ? $bundle->getDefaultProject() : $bundle->getProjectById( $domain );

		if ( $project instanceof \Loco_package_Project ) {
			return $project;
		}

		$available = [];
		foreach ( $bundle as $candidate ) {
			$available[] = $candidate->getId();
		}

		if ( [] === $available ) {
			return new WP_Error(
				'atlt_project_not_configured',
				sprintf(
					/* translators: %s: plugin or theme name. */
					__( 'Loco Translate has no translation project for "%s" because it declares no text domain Loco can detect. Tell the user to open Loco Translate, select it and complete "Setup"; no ability can configure it. Then call list-projects again.', 'loco-automatic-translate-addon-pro' ),
					$bundle->getName()
				)
			);
		}

		if ( '' === $domain ) {
			return new WP_Error(
				'atlt_project_ambiguous',
				sprintf(
					/* translators: 1: plugin or theme name, 2: comma-separated project IDs. */
					__( '"%1$s" has several translation projects and none is the default. Pass "domain" with one of: %2$s.', 'loco-automatic-translate-addon-pro' ),
					$bundle->getName(),
					implode( ', ', $available )
				)
			);
		}

		return new WP_Error(
			'atlt_project_not_found',
			sprintf(
				/* translators: 1: project ID supplied by the caller, 2: plugin or theme name, 3: comma-separated project IDs. */
				__( 'No project "%1$s" exists in "%2$s". Pass "domain" with one of: %3$s, or omit it to use the default project.', 'loco-automatic-translate-addon-pro' ),
				$domain,
				$bundle->getName(),
				implode( ', ', $available )
			)
		);
	}

	/**
	 * Resolves and validates a locale code.
	 *
	 * The display name is built from Loco's bundled data, never fetched from
	 * the WordPress.org API, so resolving a locale makes no HTTP request.
	 *
	 * @since 2.3.0
	 * @param string $tag Locale code, e.g. "fr_FR".
	 * @return \Loco_Locale|WP_Error Locale, or an error.
	 */
	public static function resolve_locale( $tag ) {
		$tag = trim( (string) $tag );

		if ( '' === $tag ) {
			return new WP_Error(
				'atlt_locale_required',
				__( 'The "locale" input is required, for example "fr_FR" or "hi_IN".', 'loco-automatic-translate-addon-pro' )
			);
		}

		$locale = \Loco_Locale::parse( $tag );

		if ( ! $locale->isValid() ) {
			return new WP_Error(
				'atlt_locale_invalid',
				sprintf(
					/* translators: %s: locale code supplied by the caller. */
					__( '"%s" is not a valid locale code. Use a WordPress locale such as "fr_FR", "de_DE", "hi_IN" or "pt_BR"; get-site-info lists the languages installed on this site.', 'loco-automatic-translate-addon-pro' ),
					$tag
				)
			);
		}

		if ( ! $locale->getName() ) {
			$locale->buildName();
		}

		return $locale;
	}

	/**
	 * Location key of a translation file: custom, system, author or other.
	 *
	 * Mirrors Loco_fs_LocaleDirectory::getTypeId(), but compares normalised
	 * paths. Loco compares its file paths (always forward slashes) against the
	 * raw LOCO_LANG_DIR and WP_LANG_DIR constants, which contain backslashes on
	 * Windows, so there it reports custom and system files as "other".
	 *
	 * @since 2.3.0
	 * @param \Loco_fs_File $file Translation file.
	 * @return string Location key.
	 */
	public static function location_key( \Loco_fs_File $file ) {
		$directory  = self::normalize_directory( $file->dirname() );
		$custom_dir = (string) loco_constant( 'LOCO_LANG_DIR' );

		if ( '' !== $custom_dir && 0 === strpos( $directory, self::normalize_directory( $custom_dir ) ) ) {
			return 'custom';
		}

		$languages_dir = self::normalize_directory( (string) loco_constant( 'WP_LANG_DIR' ) );

		if ( 0 === strpos( $directory, $languages_dir ) ) {
			$system = [ $languages_dir, $languages_dir . 'plugins/', $languages_dir . 'themes/' ];

			return in_array( $directory, $system, true ) ? 'system' : 'other';
		}

		$type = ( new \Loco_fs_LocaleDirectory( $file->dirname() ) )->getTypeId();

		return 'plugin' === $type || 'theme' === $type ? 'author' : 'other';
	}

	/**
	 * Normalises a directory path for prefix comparison.
	 *
	 * @since 2.3.0
	 * @param string $path Directory path.
	 * @return string Forward-slashed path with a trailing slash.
	 */
	private static function normalize_directory( $path ) {
		return trailingslashit( wp_normalize_path( (string) $path ) );
	}

	/**
	 * Human-readable explanation of a location key.
	 *
	 * @since 2.3.0
	 * @param string $key Location key.
	 * @return string Description.
	 */
	public static function location_label( $key ) {
		switch ( $key ) {
			case 'custom':
				return __( 'Custom (wp-content/languages/loco): safe from updates and loaded on top of other files.', 'loco-automatic-translate-addon-pro' );
			case 'system':
				return __( 'System (wp-content/languages): replaced when WordPress installs language pack updates.', 'loco-automatic-translate-addon-pro' );
			case 'author':
				return __( 'Author (inside the plugin or theme folder): deleted when the plugin or theme updates.', 'loco-automatic-translate-addon-pro' );
			default:
				return __( 'Other location.', 'loco-automatic-translate-addon-pro' );
		}
	}

	/**
	 * Path relative to wp-content, safe to show to callers.
	 *
	 * @since 2.3.0
	 * @param \Loco_fs_File $file File.
	 * @return string Relative path.
	 */
	public static function relative_path( \Loco_fs_File $file ) {
		return $file->getRelativePath( (string) loco_constant( 'WP_CONTENT_DIR' ) );
	}

	/**
	 * Checks a file can be written without asking for FTP credentials.
	 *
	 * @since 2.3.0
	 * @param \Loco_fs_File $file File about to be created or updated.
	 * @return true|WP_Error True when writable, or an error telling the caller what to do.
	 */
	public static function check_writable( \Loco_fs_File $file ) {
		$path = self::relative_path( $file );

		try {
			$file->getWriteContext()->authorize();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'atlt_write_denied',
				sprintf(
					/* translators: 1: file path, 2: reason reported by Loco Translate. */
					__( 'Loco Translate will not write %1$s: %2$s. This is decided by the site configuration (DISALLOW_FILE_MODS in wp-config.php) or by Loco Translate > Settings > file system access, which no ability can change. Tell the user, or try the "custom" location.', 'loco-automatic-translate-addon-pro' ),
					$path,
					self::safe_message( $e )
				)
			);
		}

		$writable = $file->exists() ? $file->writable() : $file->creatable();

		if ( ! $writable ) {
			return new WP_Error(
				'atlt_not_writable',
				sprintf(
					/* translators: %s: file path. */
					__( 'The web server cannot write %s directly. Loco Translate would ask for FTP credentials at this point, which AI tools cannot provide. Try the "custom" location, or tell the user to make the folder writable by the web server.', 'loco-automatic-translate-addon-pro' ),
					$path
				)
			);
		}

		return true;
	}

	/**
	 * Runs a Loco Translate write operation safely outside wp-admin.
	 *
	 * When a file is not directly writable, Loco negotiates a remote connection
	 * through request_filesystem_credentials(), which is not loaded in REST
	 * requests and would fatal. Loco's loco_allow_remote filter makes it throw a
	 * write exception instead, which is converted to a WP_Error here.
	 *
	 * @since 2.3.0
	 * @param callable $operation Callback performing the write; its return value is passed through.
	 * @return mixed Callback result, or WP_Error on failure.
	 */
	public static function run_write( callable $operation ) {
		add_filter( 'loco_allow_remote', [ __CLASS__, 'deny_remote_connection' ], 99, 1 );

		try {
			return $operation();
		} catch ( \Loco_error_Exception $e ) {
			return new WP_Error(
				'atlt_write_failed',
				sprintf(
					/* translators: %s: reason reported by Loco Translate. */
					__( 'Loco Translate could not save the file: %s. Tell the user; check get-project-status to see whether the file is writable.', 'loco-automatic-translate-addon-pro' ),
					self::safe_message( $e )
				)
			);
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Server-side diagnostics only; the caller gets a generic message.
			error_log( 'LocoAI ability write failed: ' . $e->getMessage() );

			return new WP_Error(
				'atlt_write_failed',
				__( 'An unexpected error stopped the file from being saved. Tell the user to check the PHP error log.', 'loco-automatic-translate-addon-pro' )
			);
		} finally {
			remove_filter( 'loco_allow_remote', [ __CLASS__, 'deny_remote_connection' ], 99 );
		}
	}

	/**
	 * Filter callback for loco_allow_remote during ability writes.
	 *
	 * @since 2.3.0
	 * @param bool $allowed Whether Loco may open a remote filesystem connection.
	 * @return bool Always false.
	 */
	public static function deny_remote_connection( $allowed ) {
		return false;
	}

	/**
	 * Exception message that is safe to return to a caller.
	 *
	 * Loco's own exceptions carry translated, user-facing text. Anything else
	 * is logged and replaced with a generic message.
	 *
	 * @since 2.3.0
	 * @param \Throwable $e Exception.
	 * @return string Message.
	 */
	public static function safe_message( \Throwable $e ) {
		if ( $e instanceof \Loco_error_Exception ) {
			return wp_strip_all_tags( $e->getMessage() );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Server-side diagnostics only.
		error_log( 'LocoAI ability error: ' . $e->getMessage() );

		return __( 'unexpected error (details written to the PHP error log)', 'loco-automatic-translate-addon-pro' );
	}
}
