<?php
/**
 * How each issue type gets fixed.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorInspector\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps an issue type (and one of its rows) to the tool or screen that fixes it.
 *
 * Three kinds of answer:
 * - "inspector": a linguator-inspector/fix-issue action fixes it.
 * - "linguator": a Linguator pack ability fixes it. Only offered while those
 *   abilities are registered; otherwise the answer falls back to "guide".
 * - "guide": no tool can; this is a manual fix. The AI walks the user through
 *   the admin screen using the instructions and admin_url.
 *
 * Shared by get-issues, get-fix-plan and inspect-content so they never disagree.
 *
 * Mapping from the Polylang Translation Inspector this pack is modelled on:
 * its "autopoly/*" fixes are the "linguator/*" abilities here, its Elementor
 * bucket fixes have no Linguator counterpart (Elementor layouts are translated
 * in the editor) and so fall back to "guide".
 *
 * @since 1.2.0
 */
final class Fix_Router {

	/**
	 * Issue types fixed by a fix-issue action: type => action.
	 *
	 * @since 1.2.0
	 * @var array<string, string>
	 */
	const INSPECTOR = array(
		'content_nolang' => 'assign_default_language',
		'content_draft'  => 'publish_translation',
		'woo_variations' => 'sync_woocommerce_product',
		'woo_fields'     => 'sync_woocommerce_product',
	);

	/**
	 * Issue types a Linguator pack ability fixes: type => ability.
	 *
	 * Missing, copied and outdated translations are all fixed by translating
	 * the source again.
	 *
	 * @since 1.2.0
	 * @var array<string, string>
	 */
	const LINGUATOR = array(
		'content_missing'        => 'linguator/translate-post',
		'content_identical'      => 'linguator/translate-post',
		'content_source_updated' => 'linguator/translate-post',
		'homepage_translations'  => 'linguator/translate-post',
		'elementor_missing'      => 'linguator/translate-post',
		'term_missing'           => 'linguator/translate-term',
		'strings_missing'        => 'linguator/translate-string',
		'elementor_orphan'       => 'linguator/manage-translation-links',
	);

	/**
	 * Effort by issue type: one_click, guided or manual. Unlisted types are manual.
	 *
	 * @since 1.2.0
	 * @var array<string, string>
	 */
	const EFFORT = array(
		'content_nolang'         => 'one_click',
		'content_draft'          => 'one_click',
		'woo_variations'         => 'one_click',
		'woo_fields'             => 'one_click',
		'content_missing'        => 'guided',
		'content_identical'      => 'guided',
		'content_source_updated' => 'guided',
		'homepage_translations'  => 'guided',
		'term_missing'           => 'guided',
		'strings_missing'        => 'guided',
		'elementor_missing'      => 'guided',
		'elementor_orphan'       => 'guided',
	);

	/**
	 * Effort an issue type takes.
	 *
	 * @since 1.2.0
	 * @param string $type Issue type key.
	 * @return string one_click, guided or manual.
	 */
	public static function effort( $type ) {
		return self::EFFORT[ (string) $type ] ?? 'manual';
	}

	/**
	 * How to fix an issue as a whole.
	 *
	 * @since 1.2.0
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
				'ability'      => 'linguator-inspector/fix-issue',
				'input'        => array(
					'action'    => self::INSPECTOR[ $type ],
					'issue_key' => isset( $issue['issue_key'] ) ? (string) $issue['issue_key'] : '',
				),
				'instructions' => self::inspector_instructions( $type ),
				'admin_url'    => $url,
			);
		}

		if ( isset( self::LINGUATOR[ $type ] ) ) {
			if ( Inspector_Guard::linguator_abilities_available() ) {
				return array(
					'method'       => 'linguator',
					'ability'      => self::LINGUATOR[ $type ],
					'instructions' => self::linguator_instructions( self::LINGUATOR[ $type ], $type ),
					'admin_url'    => $url,
				);
			}

			return array(
				'method'       => 'guide',
				'instructions' => $howto,
				'admin_url'    => $url,
				'note'         => __( 'The Linguator translation abilities (linguator/*) are not available. With them, an assistant could fix this automatically; otherwise it is a manual fix.', 'linguator-pro' ),
			);
		}

		return array(
			'method'       => 'guide',
			'instructions' => $howto,
			'admin_url'    => $url,
			'note'         => __( 'No tool can apply this fix. It is a manual fix: walk the user through the instructions.', 'linguator-pro' ),
		);
	}

	/**
	 * The ready-to-use input for fixing one row, when it can be derived.
	 *
	 * @since 1.2.0
	 * @param string               $type Issue type key.
	 * @param array<string, mixed> $row  Raw detail row.
	 * @return array<string, mixed>|null Ability and input, or null.
	 */
	public static function for_row( $type, array $row ) {
		$type     = (string) $type;
		$language = self::row_language( $row );
		$post_id  = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;

		if ( isset( self::INSPECTOR[ $type ] ) ) {
			$action = self::INSPECTOR[ $type ];
			$input  = array( 'action' => $action );

			switch ( $action ) {
				case 'publish_translation':
					if ( $post_id <= 0 || '' === $language ) {
						return null;
					}
					$input['source_post_id'] = $post_id;
					$input['language']       = $language;
					break;

				case 'sync_woocommerce_product':
					if ( $post_id <= 0 ) {
						return null;
					}
					$input['product_id'] = $post_id;
					break;
			}

			return array(
				'ability' => 'linguator-inspector/fix-issue',
				'input'   => $input,
			);
		}

		if ( ! isset( self::LINGUATOR[ $type ] ) || ! Inspector_Guard::linguator_abilities_available() ) {
			return null;
		}

		$ability = self::LINGUATOR[ $type ];

		switch ( $ability ) {
			case 'linguator/translate-post':
				if ( 'homepage_translations' === $type ) {
					$post_id = (int) get_option( 'page_on_front' );
				}
				if ( $post_id <= 0 || '' === $language ) {
					return null;
				}
				return array(
					'ability' => $ability,
					'input'   => array(
						'source_post_id'  => $post_id,
						'target_language' => $language,
					),
				);

			case 'linguator/translate-term':
				$term_id = isset( $row['term_id'] ) ? (int) $row['term_id'] : 0;
				if ( $term_id <= 0 || '' === $language ) {
					return null;
				}
				return array(
					'ability' => $ability,
					'input'   => array(
						'source_term_id'  => $term_id,
						'target_language' => $language,
					),
				);

			case 'linguator/translate-string':
				if ( '' === $language ) {
					return null;
				}
				return array(
					'ability' => $ability,
					'input'   => array( 'target_language' => $language ),
				);

			case 'linguator/manage-translation-links':
				if ( $post_id <= 0 ) {
					return null;
				}
				return array(
					'ability' => $ability,
					'input'   => array(
						'action' => 'get',
						'id'     => $post_id,
					),
				);
		}

		return null;
	}

	/**
	 * What to do with a fix-issue action, for the AI.
	 *
	 * @since 1.2.0
	 * @param string $type Issue type.
	 * @return string
	 */
	private static function inspector_instructions( $type ) {
		switch ( $type ) {
			case 'content_draft':
				return __( 'Call it with dry_run true and the issue_key first: it lists the draft or pending translations it would publish and skips any whose original is not published. Show the user, then call again with dry_run false.', 'linguator-pro' );
			case 'woo_variations':
			case 'woo_fields':
				return __( 'Call it with dry_run true and a product_id first: it shows which translations would be overwritten with the original product\'s variations and shared settings (SKU, stock, tax, visibility, prices). Show the user, then call again with dry_run false.', 'linguator-pro' );
		}

		return __( 'Call it with dry_run true first, show the user what would change, then call it again with dry_run false.', 'linguator-pro' );
	}

	/**
	 * What to do with a Linguator ability, for the AI.
	 *
	 * @since 1.2.0
	 * @param string $ability Ability name.
	 * @param string $type    Issue type.
	 * @return string
	 */
	private static function linguator_instructions( $ability, $type ) {
		switch ( $ability ) {
			case 'linguator/translate-post':
				if ( 'content_source_updated' === $type ) {
					return __( 'For each row, read the original with linguator/get-content, translate it again, then call linguator/translate-post with source_post_id, target_language and the translated fields. If the user says the translation is still correct, linguator-inspector/fix-issue action "mark_synced" clears the warning without changing the text.', 'linguator-pro' );
				}
				return __( 'For each row, read the source with linguator/get-content, translate it, then call linguator/translate-post with source_post_id, target_language and the translated fields. Each row\'s fix.input has the ids. Pages built with Elementor keep their layout in meta and cannot be translated this way: translate them in the editor.', 'linguator-pro' );
			case 'linguator/translate-term':
				return __( 'For each row, translate the term name, then call linguator/translate-term with source_term_id, target_language and translated_name. Each row\'s fix.input has the ids.', 'linguator-pro' );
			case 'linguator/translate-string':
				return __( 'Find the original text with linguator/get-string-groups, translate it, then call linguator/translate-string with source_string (the exact original), target_language and translation.', 'linguator-pro' );
			case 'linguator/manage-translation-links':
				return __( 'The layout exists in another language but is not linked to an original. Read the links with linguator/manage-translation-links action "get", find the original, then link them with action "link" (or linguator-inspector/fix-issue action "link_translations").', 'linguator-pro' );
		}

		return '';
	}

	/**
	 * Language slug a row is about.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $row Detail row.
	 * @return string
	 */
	private static function row_language( array $row ) {
		if ( ! empty( $row['language'] ) && is_string( $row['language'] ) ) {
			return sanitize_key( $row['language'] );
		}

		return '';
	}
}
