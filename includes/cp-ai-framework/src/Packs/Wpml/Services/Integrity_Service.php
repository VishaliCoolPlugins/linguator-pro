<?php
/**
 * Translation-integrity audits for the abilities subsystem.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Services;

use WPML_AT_PRO_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks a translated post for the failure modes WPML's own "complete" status
 * does not catch: content that looks translated but is not, internal links
 * still pointing at the source-language original, and Elementor templates or
 * global widgets still wired to a source-language asset.
 *
 * These are read-only checks. Nothing here writes to the post; findings are
 * reported for a human or another ability to act on.
 *
 * @since 1.5.0
 */
final class Integrity_Service {

	/**
	 * Every check this ability knows how to run.
	 *
	 * @since 1.5.0
	 * @var string[]
	 */
	const ALL_CHECKS = array( 'content', 'links', 'assets' );

	/**
	 * Minimum length, in characters, before an identical source/target string
	 * is worth flagging. Shorter strings are too often legitimately identical
	 * across languages (numbers, brand names, single words) to be useful.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const MIN_FLAGGABLE_LENGTH = 20;

	/**
	 * Audits one translated post.
	 *
	 * @since 1.5.0
	 * @param int      $post_id Translated post to audit.
	 * @param string[] $checks  Subset of self::ALL_CHECKS to run.
	 * @return array<string, mixed>|null Report, or null when the post is gone.
	 */
	public static function audit( $post_id, array $checks ) {
		$post_id = absint( $post_id );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return null;
		}

		$checks = array_values( array_intersect( self::ALL_CHECKS, $checks ) );

		if ( empty( $checks ) ) {
			$checks = self::ALL_CHECKS;
		}

		$details = Translation_Service::get_element_language_details( $post_id );

		$target_language = null === $details ? '' : $details['language_code'];
		$source_language  = null === $details
			? ''
			: ( $details['is_source'] ? $details['language_code'] : $details['source_language_code'] );

		$source_id = self::find_source_id( $post_id, $source_language, $target_language );

		$issues = array();

		if ( in_array( 'content', $checks, true ) ) {
			$issues = array_merge( $issues, self::check_content( $post_id, $source_id, $source_language ) );
		}

		if ( in_array( 'links', $checks, true ) ) {
			$issues = array_merge( $issues, self::check_links( $post_id, $source_language, $target_language ) );
		}

		if ( in_array( 'assets', $checks, true ) ) {
			$issues = array_merge( $issues, self::check_elementor_assets( $post_id, $source_language, $target_language ) );
		}

		return array(
			'post_id'         => $post_id,
			'source_id'       => $source_id,
			'target_language' => $target_language,
			'source_language' => $source_language,
			'checks_run'      => $checks,
			'issue_count'     => count( $issues ),
			'issues'          => $issues,
			'edit_link'       => html_entity_decode( (string) get_edit_post_link( $post_id, 'raw' ) ),
		);
	}

	/**
	 * The source post backing a translation, resolved from its translation group.
	 *
	 * @since 1.5.0
	 * @param int    $post_id         Translated post ID.
	 * @param string $source_language Source language code, when known.
	 * @param string $target_language Target language code, when known.
	 * @return int Source post ID, or 0 when it cannot be resolved.
	 */
	private static function find_source_id( $post_id, $source_language, $target_language ) {
		if ( '' === $source_language || $source_language === $target_language ) {
			return 0;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return 0;
		}

		$source_id = apply_filters( 'wpml_object_id', $post_id, (string) $post->post_type, false, $source_language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return is_numeric( $source_id ) ? (int) $source_id : 0;
	}

	/**
	 * The language Get_Package_Content resolves a post's own strings against.
	 *
	 * Get_Content asks WPML for each post's own detected source language
	 * rather than assuming it matches the translation group's original
	 * language, and Get_Package_Content's output shape depends on getting the
	 * same value: passing the group's source language for every post in the
	 * group (as opposed to each post's own) is what produces the generic
	 * "body"/"t_N" fallback shape for a translation instead of its real
	 * builder-specific fields.
	 *
	 * @since 1.5.0
	 * @param int $post_id Post to resolve.
	 * @return string Language code, or the site default when undetectable.
	 */
	private static function own_source_language( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return '';
		}

		$language = '';

		if ( class_exists( 'WPML_AT_PRO_Helper' ) ) {
			$language = (string) WPML_AT_PRO_Helper::get_post_source_language( $post_id, (string) $post->post_type );
		}

		return '' !== $language ? $language : Language_Service::get_default_code();
	}

	/**
	 * Flags target-language strings that are identical to their source, which
	 * usually means the string was never actually translated even though WPML
	 * marks the post complete.
	 *
	 * @since 1.5.0
	 * @param int    $post_id         Translated post ID.
	 * @param int    $source_id       Source post ID, or 0 when unknown.
	 * @param string $source_language Source language code.
	 * @return array<int, array<string, mixed>> Issues found.
	 */
	private static function check_content( $post_id, $source_id, $source_language ) {
		if ( 0 === $source_id ) {
			return array();
		}

		$target_package = Wpml_Context::get_package( $post_id, self::own_source_language( $post_id ) );
		$source_package = Wpml_Context::get_package( $source_id, self::own_source_language( $source_id ) );

		if ( is_wp_error( $target_package ) || is_wp_error( $source_package ) ) {
			return array();
		}

		/*
		 * Field keys cannot be trusted for this comparison: an existing
		 * Elementor/Divi translation package always collapses into one
		 * generic "body" field regardless of whether it is genuinely broken
		 * or has since been fixed by re-saving in the builder, while the
		 * source keeps its real per-widget keys (verified directly against
		 * this site: source, broken, and manually-fixed translations of the
		 * same page all differ only in this one field's text, never its key).
		 * So each source fragment is instead searched for verbatim inside the
		 * target's full text, which works regardless of how either side is
		 * keyed.
		 */
		$target_text = self::normalize( implode( ' ', array_map( array( __CLASS__, 'plain_text' ), (array) $target_package['contents'] ) ) );

		if ( '' === $target_text ) {
			return array();
		}

		$issues = array();

		foreach ( (array) $source_package['contents'] as $key => $source_entry ) {
			$source_text = self::normalize( self::plain_text( $source_entry ) );

			if ( mb_strlen( $source_text ) < self::MIN_FLAGGABLE_LENGTH ) {
				continue;
			}

			if ( false === mb_stripos( $target_text, $source_text ) ) {
				continue;
			}

			$issues[] = array(
				'type'    => 'untranslated_content',
				'field'   => (string) $key,
				'excerpt' => mb_substr( $source_text, 0, 120 ),
				'message' => __( 'This source-language text still appears verbatim in the translation, so this part was likely never actually translated even though WPML marks the post complete. If this post was recently fixed by hand in the page builder, note that AutoMLP\'s own content package can lag behind a manual save; confirm against the live page before treating this as still broken.', 'wpml-translation-check-pro' ),
			);

			if ( count( $issues ) >= 20 ) {
				break;
			}
		}

		return $issues;
	}

	/**
	 * Flags internal links inside the translated post that still point at a
	 * source-language post, when a target-language version of that same link
	 * exists and could be used instead.
	 *
	 * @since 1.5.0
	 * @param int    $post_id         Translated post ID.
	 * @param string $source_language Source language code.
	 * @param string $target_language Target language code.
	 * @return array<int, array<string, mixed>> Issues found.
	 */
	private static function check_links( $post_id, $source_language, $target_language ) {
		if ( '' === $source_language || '' === $target_language || $source_language === $target_language ) {
			return array();
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		$haystacks   = array( (string) $post->post_content );
		$elementor_json = get_post_meta( $post_id, '_elementor_data', true );

		if ( is_string( $elementor_json ) && '' !== $elementor_json ) {
			$haystacks[] = $elementor_json;
		}

		$urls = array();

		foreach ( $haystacks as $haystack ) {
			if ( preg_match_all( '/(?:href|src)\\\\?"\s*:\s*\\\\?"([^"\\\\]+)\\\\?"|(?:href|src)="([^"]+)"/i', $haystack, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$url = '' !== $match[1] ? $match[1] : $match[2];
					$url = wp_unslash( $url );

					if ( '' !== $url ) {
						$urls[ $url ] = true;
					}
				}
			}
		}

		$home = home_url();
		$issues = array();

		foreach ( array_keys( $urls ) as $url ) {
			if ( 0 !== strpos( $url, $home ) ) {
				continue;
			}

			$linked_id = url_to_postid( $url );

			if ( 0 === $linked_id || $linked_id === $post_id ) {
				continue;
			}

			$linked_details = Translation_Service::get_element_language_details( $linked_id );

			if ( null === $linked_details || $linked_details['language_code'] !== $source_language ) {
				continue;
			}

			$linked_post = get_post( $linked_id );
			$target_of_link = apply_filters( 'wpml_object_id', $linked_id, (string) $linked_post->post_type, false, $target_language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

			if ( ! is_numeric( $target_of_link ) || (int) $target_of_link === $linked_id ) {
				continue;
			}

			$issues[] = array(
				'type'          => 'stale_source_language_link',
				'url'           => $url,
				'linked_post_id' => $linked_id,
				'suggested_url' => (string) get_permalink( (int) $target_of_link ),
				'message'       => __( 'This link points at the source-language version of a page that has its own translation. It should point at the translated version instead.', 'wpml-translation-check-pro' ),
			);
		}

		return $issues;
	}

	/**
	 * Flags Elementor templates or global widgets on a translated page that
	 * still reference a source-language template ID.
	 *
	 * @since 1.5.0
	 * @param int    $post_id         Translated post ID.
	 * @param string $source_language Source language code.
	 * @param string $target_language Target language code.
	 * @return array<int, array<string, mixed>> Issues found.
	 */
	private static function check_elementor_assets( $post_id, $source_language, $target_language ) {
		if ( '' === $source_language || '' === $target_language || $source_language === $target_language ) {
			return array();
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		$template_ids = array();

		self::collect_template_ids( $data, $template_ids );

		$issues = array();

		foreach ( array_unique( $template_ids ) as $template_id ) {
			$template_id = absint( $template_id );

			if ( 0 === $template_id ) {
				continue;
			}

			$template_details = Translation_Service::get_element_language_details( $template_id );

			if ( null === $template_details || $template_details['language_code'] !== $source_language ) {
				continue;
			}

			$template_post   = get_post( $template_id );
			$correct_template = apply_filters( 'wpml_object_id', $template_id, $template_post ? (string) $template_post->post_type : 'elementor_library', false, $target_language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

			if ( ! is_numeric( $correct_template ) || (int) $correct_template === $template_id ) {
				continue;
			}

			$issues[] = array(
				'type'                => 'source_language_template',
				'template_id'         => $template_id,
				'template_title'      => (string) get_the_title( $template_id ),
				'correct_template_id' => (int) $correct_template,
				'message'             => __( 'This Elementor template or global widget still points at the source-language version of the template. It should point at the target-language version instead.', 'wpml-translation-check-pro' ),
			);
		}

		return $issues;
	}

	/**
	 * Recursively collects Elementor template widget IDs from an element tree.
	 *
	 * @since 1.5.0
	 * @param array<int|string, mixed> $nodes        Elementor element tree, or a single element's settings.
	 * @param int[]                    $template_ids Accumulator, passed by reference.
	 * @return void
	 */
	private static function collect_template_ids( array $nodes, array &$template_ids ) {
		foreach ( $nodes as $key => $value ) {
			if ( is_array( $value ) ) {
				self::collect_template_ids( $value, $template_ids );
				continue;
			}

			if ( is_numeric( $value ) && is_string( $key ) && preg_match( '/^template_?id$/i', $key ) ) {
				$template_ids[] = (int) $value;
			}
		}
	}

	/**
	 * Plain text from one entry of a Wpml_Context content package.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed>|string $entry Content entry.
	 * @return string Plain text.
	 */
	private static function plain_text( $entry ) {
		if ( is_array( $entry ) && isset( $entry['text'] ) ) {
			return (string) $entry['text'];
		}

		if ( is_array( $entry ) && isset( $entry['html'] ) ) {
			return wp_strip_all_tags( (string) $entry['html'] );
		}

		return is_string( $entry ) ? wp_strip_all_tags( $entry ) : '';
	}

	/**
	 * Normalizes text for an identical/not-identical comparison.
	 *
	 * @since 1.5.0
	 * @param string $text Text to normalize.
	 * @return string Normalized text.
	 */
	private static function normalize( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}
}
