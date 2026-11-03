<?php
/**
 * How each issue type gets fixed.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Inspector\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps an issue type (and one of its rows) to the tool or screen that fixes it.
 *
 * Three kinds of answer:
 * - "inspector": a translation-inspector/* tool fixes it (fix-issue).
 * - "autopoly": an AutoPoly ability fixes it. Only offered while AutoPoly's
 *   abilities are registered; otherwise the answer falls back to "guide".
 * - "guide": no tool can; the AI walks the user through the admin screen.
 *
 * Shared by get-issues, get-fix-plan and inspect-content so they never disagree.
 *
 * @since 1.1.0
 */
final class Fix_Router {

	/**
	 * Issue types fixed by an Inspector tool: type => [ability, action].
	 *
	 * @since 1.1.0
	 * @var array<string, array{0: string, 1: string}>
	 */
	const INSPECTOR = array(
		'content_nolang' => array( 'translation-inspector/fix-issue', 'assign_default_language' ),
	);

	/**
	 * Issue types an AutoPoly ability fixes: type => ability.
	 *
	 * Missing, copied and outdated translations are all fixed by translating
	 * the source again. Elementor issues are keyed "elementor:{bucket}".
	 *
	 * @since 1.1.0
	 * @var array<string, string>
	 */
	const AUTOPOLY = array(
		'content_missing'                => 'autopoly/translate-post',
		'content_identical'              => 'autopoly/translate-post',
		'content_source_updated'         => 'autopoly/translate-post',
		'homepage_translations'          => 'autopoly/translate-post',
		'term_missing'                   => 'autopoly/translate-term',
		'strings_missing'                => 'autopoly/translate-string',
		'menu_assignments'               => 'autopoly/sync-menu',
		'elementor:missing_translation'  => 'autopoly/translate-post',
		'elementor:untranslated_field'   => 'autopoly/translate-post',
		'elementor:empty_translation'    => 'autopoly/translate-post',
		'elementor:missing_loop_item_translation' => 'autopoly/translate-post',
	);

	/**
	 * How to fix an issue as a whole.
	 *
	 * @since 1.1.0
	 * @param string               $type  Issue type key.
	 * @param array<string, mixed> $issue Issue from Issue_Service (howto, action_url).
	 * @return array<string, mixed> Fix suggestion.
	 */
	public static function for_issue( $type, array $issue ) {
		$type  = (string) $type;
		$howto = isset( $issue['howto'] ) ? (string) $issue['howto'] : '';
		$url   = isset( $issue['action_url'] ) ? (string) $issue['action_url'] : '';

		if ( isset( self::INSPECTOR[ $type ] ) ) {
			return array(
				'method'       => 'inspector',
				'ability'      => self::INSPECTOR[ $type ][0],
				'input'        => array( 'action' => self::INSPECTOR[ $type ][1] ),
				'instructions' => __( 'Call it with dry_run true first, show the user what would change, then call it again with dry_run false.', 'duplicate-content-addon-for-polylang' ),
				'admin_url'    => $url,
			);
		}

		if ( isset( self::AUTOPOLY[ $type ] ) ) {
			if ( Inspector_Guard::autopoly_abilities_available() ) {
				return array(
					'method'       => 'autopoly',
					'ability'      => self::AUTOPOLY[ $type ],
					'instructions' => self::autopoly_instructions( self::AUTOPOLY[ $type ] ),
					'admin_url'    => $url,
				);
			}

			return array(
				'method'       => 'guide',
				'instructions' => $howto,
				'admin_url'    => $url,
				'note'         => __( 'AutoPoly AI Translation for Polylang is not active. With it, an assistant could fix this automatically.', 'duplicate-content-addon-for-polylang' ),
			);
		}

		return array(
			'method'       => 'guide',
			'instructions' => $howto,
			'admin_url'    => $url,
		);
	}

	/**
	 * The ready-to-use input for fixing one row, when it can be derived.
	 *
	 * @since 1.1.0
	 * @param string               $type Issue type key.
	 * @param array<string, mixed> $row  Raw detail row or Elementor example.
	 * @return array<string, mixed>|null Ability and input, or null.
	 */
	public static function for_row( $type, array $row ) {
		$type = (string) $type;

		if ( isset( self::INSPECTOR[ $type ] ) ) {
			return array(
				'ability' => self::INSPECTOR[ $type ][0],
				'input'   => array( 'action' => self::INSPECTOR[ $type ][1] ),
			);
		}

		if ( ! isset( self::AUTOPOLY[ $type ] ) || ! Inspector_Guard::autopoly_abilities_available() ) {
			return null;
		}

		$ability  = self::AUTOPOLY[ $type ];
		$language = self::row_language( $row );

		switch ( $ability ) {
			case 'autopoly/translate-post':
				$source = self::row_int( $row, array( 'post_id', 'template_id' ) );
				if ( 'homepage_translations' === $type ) {
					$source = (int) get_option( 'page_on_front' );
				}
				if ( $source <= 0 || '' === $language ) {
					return null;
				}
				return array(
					'ability' => $ability,
					'input'   => array(
						'source_post_id'  => $source,
						'target_language' => $language,
					),
				);

			case 'autopoly/translate-term':
				$source = self::row_int( $row, array( 'post_id', 'term_id' ) );
				if ( $source <= 0 || '' === $language ) {
					return null;
				}
				return array(
					'ability' => $ability,
					'input'   => array(
						'source_term_id'  => $source,
						'target_language' => $language,
					),
				);

			case 'autopoly/translate-string':
				if ( '' === $language ) {
					return null;
				}
				return array(
					'ability' => $ability,
					'input'   => array( 'language' => $language ),
				);

			case 'autopoly/sync-menu':
				$menu_id = self::default_language_menu( isset( $row['location'] ) ? (string) $row['location'] : '' );
				if ( $menu_id <= 0 ) {
					return null;
				}
				return array(
					'ability' => $ability,
					'input'   => array(
						'menu_id'          => $menu_id,
						'target_languages' => self::non_default_languages( isset( $row['missing_languages'] ) ? (array) $row['missing_languages'] : array() ),
					),
				);
		}

		return null;
	}

	/**
	 * What to do with an AutoPoly ability, for the AI.
	 *
	 * @since 1.1.0
	 * @param string $ability Ability name.
	 * @return string
	 */
	private static function autopoly_instructions( $ability ) {
		switch ( $ability ) {
			case 'autopoly/translate-post':
				return __( 'For each row, read the source with autopoly/get-content, translate it, then call autopoly/translate-post with source_post_id, target_language and the translated fields. Each row\'s fix.input has the ids.', 'duplicate-content-addon-for-polylang' );
			case 'autopoly/translate-term':
				return __( 'For each row, translate the term name, then call autopoly/translate-term with source_term_id, target_language and translated_name. Each row\'s fix.input has the ids.', 'duplicate-content-addon-for-polylang' );
			case 'autopoly/translate-string':
				return __( 'Find the original text with autopoly/get-string-groups, translate it, then call autopoly/translate-string with string (the exact original), language and translation.', 'duplicate-content-addon-for-polylang' );
			case 'autopoly/sync-menu':
				return __( 'Call autopoly/sync-menu with the default-language menu (fix.input on each row) and the missing languages, dry_run first. When a row has no fix.input, the location has no default-language menu yet: guide the user to assign one first.', 'duplicate-content-addon-for-polylang' );
		}

		return '';
	}

	/**
	 * Language slug a row is about.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $row Detail row.
	 * @return string
	 */
	private static function row_language( array $row ) {
		foreach ( array( 'language', 'lang_slug' ) as $field ) {
			if ( ! empty( $row[ $field ] ) && is_string( $row[ $field ] ) ) {
				return sanitize_key( $row[ $field ] );
			}
		}

		if ( ! empty( $row['issue_languages'] ) && is_array( $row['issue_languages'] ) && 1 === count( $row['issue_languages'] ) ) {
			return sanitize_key( (string) reset( $row['issue_languages'] ) );
		}

		return '';
	}

	/**
	 * First positive integer among the given row fields.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $row    Detail row.
	 * @param string[]             $fields Field names, in order.
	 * @return int
	 */
	private static function row_int( array $row, array $fields ) {
		foreach ( $fields as $field ) {
			if ( isset( $row[ $field ] ) && (int) $row[ $field ] > 0 ) {
				return (int) $row[ $field ];
			}
		}

		return 0;
	}

	/**
	 * Menu assigned to a theme location in the default language.
	 *
	 * @since 1.1.0
	 * @param string $location Theme location slug.
	 * @return int Menu term ID, or 0.
	 */
	private static function default_language_menu( $location ) {
		if ( '' === $location ) {
			return 0;
		}

		$locations = get_nav_menu_locations();

		return isset( $locations[ $location ] ) ? (int) $locations[ $location ] : 0;
	}

	/**
	 * Language slugs without the default language.
	 *
	 * @since 1.1.0
	 * @param string[] $languages Language slugs.
	 * @return string[]
	 */
	private static function non_default_languages( array $languages ) {
		$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '';

		return array_values(
			array_filter(
				array_map( 'sanitize_key', array_map( 'strval', $languages ) ),
				static function ( $slug ) use ( $default ) {
					return '' !== $slug && $slug !== $default;
				}
			)
		);
	}
}
