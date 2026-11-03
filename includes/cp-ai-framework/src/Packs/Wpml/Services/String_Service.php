<?php
/**
 * WPML String Translation reads and writes for the abilities subsystem.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Services;

use WP_Error;
use WPML_AT_PRO_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme, plugin and admin-text strings held by WPML String Translation.
 *
 * Strings belonging to a string package (Elementor, Gutenberg and other
 * builder content) are left out everywhere. They are part of a post, and
 * translating them here would bypass the post's own translation job; they are
 * read with get-content and written with translate-post.
 *
 * Reads query WPML's tables directly because WPML has no public API for
 * listing strings. Writes never do: each one goes through
 * icl_add_string_translation(), which also updates the string's own status in
 * icl_strings, fires wpml_st_add_string_translation and flushes WPML's cache.
 *
 * @since 1.5.0
 */
final class String_Service {

	/**
	 * Maximum strings a single write may carry.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const MAX_WRITE = 100;

	/**
	 * WPML's ICL_STRING_TRANSLATION_COMPLETE, repeated so the value is
	 * available before WPML String Translation defines its constants.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const STATUS_COMPLETE = 10;

	/**
	 * WPML's ICL_STRING_TRANSLATION_NEEDS_UPDATE: the source text changed
	 * after it was translated.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const STATUS_NEEDS_UPDATE = 3;

	/**
	 * Context suffix WPML Menus Sync gives the strings of a menu, for example
	 * "Main Menu menu". Repeated from WPML_Menu_Sync_Functionality, which is not
	 * always loaded when an ability runs.
	 *
	 * @since 1.5.1
	 * @var string
	 */
	const MENU_CONTEXT_SUFFIX = ' menu';

	/**
	 * Name prefixes WPML Menus Sync gives menu item label and URL strings.
	 *
	 * @since 1.5.1
	 * @var string[]
	 */
	const MENU_NAME_PREFIXES = array( 'Menu Item Label ', 'Menu Item URL ' );

	/**
	 * Status filters get-strings accepts.
	 *
	 * @since 1.5.0
	 * @var string[]
	 */
	const STATUS_FILTERS = array( 'untranslated', 'needs_update', 'translated', 'all' );

	/**
	 * Whether a string is a menu item label or URL registered by WPML Menus Sync.
	 *
	 * @since 1.5.1
	 * @param string $context String domain.
	 * @param string $name    String name.
	 * @return bool True for a menu label or URL string.
	 */
	private static function is_menu_string( $context, $name ) {
		$suffix = self::MENU_CONTEXT_SUFFIX;

		if ( strlen( $context ) <= strlen( $suffix ) || substr( $context, -strlen( $suffix ) ) !== $suffix ) {
			return false;
		}

		foreach ( self::MENU_NAME_PREFIXES as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What to tell the user after menu strings are saved.
	 *
	 * Saving a translation does not change the translated menu. Its items keep
	 * the text they were created with until an administrator applies the
	 * changes in WPML Menus Sync, which no tool can do.
	 *
	 * @since 1.5.1
	 * @return string Notice text.
	 */
	private static function menu_notice() {
		return __( 'These are WPML menu labels or links. Saving them does not change the menu people see: the translated menu keeps its old text until a site administrator applies the changes under WPML > Translations > Menus (older WPML: WPML > WP Menus Sync) (tick the changed labels and URLs, click "Apply changes"), then clears the site cache. No tool can do that step, so tell the user to do it. A menu that is still in the source language after this is expected until then.', 'wpml-translation-check-pro' );
	}

	/**
	 * Per-domain translation counts.
	 *
	 * @since 1.5.0
	 * @param string $target_language Language to report on, or '' for every
	 *                                active language.
	 * @return array<string, mixed> Report payload.
	 */
	public static function get_groups( $target_language = '' ) {
		global $wpdb;

		$languages = '' === $target_language ? Language_Service::get_codes() : array( $target_language );
		$groups    = array();

		foreach ( $languages as $language ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML has no API for this and the figures must be current.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.context AS domain,
						COUNT(*) AS total,
						SUM( CASE WHEN t.status = %d THEN 1 ELSE 0 END ) AS translated,
						SUM( CASE WHEN t.status = %d THEN 1 ELSE 0 END ) AS needs_update
					FROM {$wpdb->prefix}icl_strings s
					LEFT JOIN {$wpdb->prefix}icl_string_translations t
						ON t.string_id = s.id AND t.language = %s
					WHERE s.string_package_id IS NULL AND s.language <> %s
					GROUP BY s.context
					ORDER BY s.context ASC",
					self::STATUS_COMPLETE,
					self::STATUS_NEEDS_UPDATE,
					$language,
					$language
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$domain       = (string) $row['domain'];
				$total        = (int) $row['total'];
				$translated   = (int) $row['translated'];
				$needs_update = (int) $row['needs_update'];

				if ( ! isset( $groups[ $domain ] ) ) {
					$groups[ $domain ] = array(
						'domain'    => $domain,
						'total'     => $total,
						'languages' => array(),
					);
				}

				$groups[ $domain ]['total'] = max( $groups[ $domain ]['total'], $total );

				$groups[ $domain ]['languages'][ $language ] = array(
					'translated'   => $translated,
					'needs_update' => $needs_update,
					'untranslated' => max( 0, $total - $translated - $needs_update ),
					'percentage'   => $total > 0 ? (int) floor( ( $translated / $total ) * 100 ) : 100,
				);
			}
		}

		$notice = __( 'Only strings WPML has already registered are listed. If a theme or plugin is missing, a site administrator has to scan it under WPML > Theme and plugins localization first. Page builder content is not here: translate it with get-content and translate-post.', 'wpml-translation-check-pro' );

		foreach ( array_keys( $groups ) as $domain ) {
			$suffix = self::MENU_CONTEXT_SUFFIX;

			if ( strlen( $domain ) > strlen( $suffix ) && substr( $domain, -strlen( $suffix ) ) === $suffix ) {
				$notice .= ' ' . __( 'Groups named "<menu name> menu" hold menu labels and links. Translations saved there only reach the site after a site administrator applies them under WPML > Translations > Menus (older WPML: WPML > WP Menus Sync); tell the user.', 'wpml-translation-check-pro' );
				break;
			}
		}

		return array(
			'source_language' => Language_Service::get_default_code(),
			'groups'          => array_values( $groups ),
			'total_groups'    => count( $groups ),
			'notice'          => $notice,
		);
	}

	/**
	 * Registered strings with their translation in one language.
	 *
	 * @since 1.5.0
	 * @param string $target_language Language to read translations in.
	 * @param string $domain          Domain filter, or ''.
	 * @param string $status          One of STATUS_FILTERS.
	 * @param string $search          Search in the source text, or ''.
	 * @param int    $limit           Page size.
	 * @param int    $offset          Rows to skip.
	 * @return array<string, mixed> List payload.
	 */
	public static function get_strings( $target_language, $domain = '', $status = 'untranslated', $search = '', $limit = Content_Service::DEFAULT_LIMIT, $offset = 0 ) {
		global $wpdb;

		$where  = array( 's.string_package_id IS NULL', 's.language <> %s' );
		$params = array( $target_language, $target_language );

		if ( '' !== $domain ) {
			$where[]  = 's.context = %s';
			$params[] = $domain;
		}

		if ( '' !== $search ) {
			$where[]  = 's.value LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		if ( 'translated' === $status ) {
			$where[]  = 't.status = %d';
			$params[] = self::STATUS_COMPLETE;
		} elseif ( 'needs_update' === $status ) {
			$where[]  = 't.status = %d';
			$params[] = self::STATUS_NEEDS_UPDATE;
		} elseif ( 'untranslated' === $status ) {
			$where[]  = '( t.id IS NULL OR t.status NOT IN ( %d, %d ) )';
			$params[] = self::STATUS_COMPLETE;
			$params[] = self::STATUS_NEEDS_UPDATE;
		}

		$from = "FROM {$wpdb->prefix}icl_strings s
			LEFT JOIN {$wpdb->prefix}icl_string_translations t
				ON t.string_id = s.id AND t.language = %s
			WHERE " . implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $from is built only from fixed fragments; every value is a placeholder.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", $params ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.context, s.name, s.gettext_context, s.language, s.value,
					t.value AS translation, t.mo_string, t.status
				{$from}
				ORDER BY s.context ASC, s.id ASC
				LIMIT %d OFFSET %d",
				array_merge( $params, array( $limit, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$items = array();

		foreach ( (array) $rows as $row ) {
			$translation = null !== $row['translation'] ? (string) $row['translation'] : (string) $row['mo_string'];

			$items[] = array(
				'string_id'       => (int) $row['id'],
				'domain'          => (string) $row['context'],
				'name'            => (string) $row['name'],
				'context'         => (string) $row['gettext_context'],
				'source_language' => (string) $row['language'],
				'value'           => (string) $row['value'],
				'translation'     => $translation,
				'status'          => self::status_name( null === $row['status'] ? null : (int) $row['status'] ),
			);
		}

		$payload = array(
			'target_language' => $target_language,
			'domain'          => $domain,
			'status'          => $status,
			'items'           => $items,
			'total'           => $total,
			'has_more'        => ( $offset + count( $items ) ) < $total,
		);

		foreach ( $items as $item ) {
			if ( self::is_menu_string( $item['domain'], $item['name'] ) ) {
				$payload['notice'] = self::menu_notice();
				break;
			}
		}

		return $payload;
	}

	/**
	 * Saves a batch of string translations.
	 *
	 * Each string is checked and saved on its own, so one bad entry is
	 * reported without failing the rest of the batch.
	 *
	 * @since 1.5.0
	 * @param string                      $target_language Language to write.
	 * @param array<int, array<string, mixed>> $translations Entries of { string_id, value }.
	 * @return array<string, mixed> Result payload.
	 */
	public static function save( $target_language, array $translations ) {
		$saved   = array();
		$skipped = array();
		$errors  = array();
		$sources = self::get_sources( self::collect_ids( $translations ) );
		$menu    = false;

		foreach ( $translations as $entry ) {
			$string_id = is_array( $entry ) && isset( $entry['string_id'] ) ? absint( $entry['string_id'] ) : 0;
			$raw       = is_array( $entry ) && isset( $entry['value'] ) ? (string) $entry['value'] : '';

			if ( 0 === $string_id || ! isset( $sources[ $string_id ] ) ) {
				$errors[] = self::entry_error( $string_id, 'automlp_string_not_found', __( 'No string with that ID is registered in WPML String Translation.', 'wpml-translation-check-pro' ) );
				continue;
			}

			$source = $sources[ $string_id ];

			if ( null !== $source['string_package_id'] ) {
				$errors[] = self::entry_error( $string_id, 'automlp_string_in_package', __( 'That string belongs to page builder content. Translate its post with get-content and translate-post instead.', 'wpml-translation-check-pro' ) );
				continue;
			}

			if ( $source['language'] === $target_language ) {
				$errors[] = self::entry_error( $string_id, 'automlp_same_language', __( 'That string is already written in the target language.', 'wpml-translation-check-pro' ) );
				continue;
			}

			$value = WPML_AT_PRO_Helper::sanitize_string_translation( $raw );

			if ( '' === trim( $value ) ) {
				$skipped[] = self::entry_error( $string_id, 'automlp_empty_value', __( 'The translation was empty, or empty once disallowed HTML was removed, so nothing was saved.', 'wpml-translation-check-pro' ) );
				continue;
			}

			$result = icl_add_string_translation(
				$string_id,
				$target_language,
				$value,
				self::STATUS_COMPLETE,
				get_current_user_id()
			);

			if ( ! $result ) {
				$errors[] = self::entry_error( $string_id, 'automlp_write_failed', __( 'WPML did not save the translation and gave no reason.', 'wpml-translation-check-pro' ) );
				continue;
			}

			$saved[] = array(
				'string_id' => $string_id,
				'domain'    => $source['context'],
				'value'     => $source['value'],
				'saved'     => $value,
			);

			if ( self::is_menu_string( $source['context'], $source['name'] ) ) {
				$menu = true;
			}
		}

		$result = array(
			'target_language' => $target_language,
			'saved_count'     => count( $saved ),
			'saved'           => $saved,
			'skipped'         => $skipped,
			'errors'          => $errors,
		);

		if ( $menu ) {
			$result['notice'] = self::menu_notice();
		}

		return $result;
	}

	/**
	 * Validates a write before anything is saved.
	 *
	 * @since 1.5.0
	 * @param string $target_language Language to write.
	 * @param mixed  $translations    Raw entries.
	 * @return WP_Error|null An error, or null when the batch may proceed.
	 */
	public static function validate_write( $target_language, $translations ) {
		if ( ! function_exists( 'icl_add_string_translation' ) ) {
			return new WP_Error(
				'automlp_wpml_st_missing',
				__( 'WPML String Translation is not loaded, so strings cannot be saved. Check with get-site-info.', 'wpml-translation-check-pro' )
			);
		}

		if ( ! Language_Service::is_active( $target_language ) ) {
			return Language_Service::inactive_error( $target_language );
		}

		if ( ! is_array( $translations ) || empty( $translations ) ) {
			return new WP_Error(
				'automlp_nothing_to_write',
				__( 'Pass "translations" as a list of { "string_id", "value" } entries. Get the IDs from get-strings.', 'wpml-translation-check-pro' )
			);
		}

		if ( count( $translations ) > self::MAX_WRITE ) {
			return new WP_Error(
				'automlp_batch_too_large',
				sprintf(
					/* translators: 1: maximum batch size, 2: batch size sent. */
					__( 'At most %1$d strings can be saved per call; %2$d were sent. Split them into smaller batches.', 'wpml-translation-check-pro' ),
					self::MAX_WRITE,
					count( $translations )
				)
			);
		}

		return null;
	}

	/**
	 * Maps a WPML status number to the name the abilities use.
	 *
	 * @since 1.5.0
	 * @param int|null $status WPML status, or null for no translation row.
	 * @return string Status name.
	 */
	private static function status_name( $status ) {
		if ( self::STATUS_COMPLETE === $status ) {
			return 'translated';
		}

		if ( self::STATUS_NEEDS_UPDATE === $status ) {
			return 'needs_update';
		}

		return 'untranslated';
	}

	/**
	 * Unique positive string IDs in a batch.
	 *
	 * @since 1.5.0
	 * @param array<int, mixed> $translations Raw entries.
	 * @return int[] IDs.
	 */
	private static function collect_ids( array $translations ) {
		$ids = array();

		foreach ( $translations as $entry ) {
			if ( is_array( $entry ) && isset( $entry['string_id'] ) && absint( $entry['string_id'] ) > 0 ) {
				$ids[] = absint( $entry['string_id'] );
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Source rows for a set of string IDs, in one query.
	 *
	 * @since 1.5.0
	 * @param int[] $ids String IDs.
	 * @return array<int, array<string, mixed>> Rows keyed by ID.
	 */
	private static function get_sources( array $ids ) {
		global $wpdb;

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated from the ID count.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, context, name, language, value, string_package_id FROM {$wpdb->prefix}icl_strings WHERE id IN ( {$placeholders} )",
				$ids
			),
			ARRAY_A
		);

		$sources = array();

		foreach ( (array) $rows as $row ) {
			$sources[ (int) $row['id'] ] = array(
				'context'           => (string) $row['context'],
				'name'              => (string) $row['name'],
				'language'          => (string) $row['language'],
				'value'             => (string) $row['value'],
				'string_package_id' => null === $row['string_package_id'] ? null : (int) $row['string_package_id'],
			);
		}

		return $sources;
	}

	/**
	 * One per-entry problem, in the shape the write reports.
	 *
	 * @since 1.5.0
	 * @param int    $string_id String ID.
	 * @param string $code      Error code.
	 * @param string $message   Explanation.
	 * @return array<string, mixed> Entry.
	 */
	private static function entry_error( $string_id, $code, $message ) {
		return array(
			'string_id' => $string_id,
			'code'      => $code,
			'message'   => $message,
		);
	}
}
