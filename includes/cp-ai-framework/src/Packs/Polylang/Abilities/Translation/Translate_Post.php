<?php
/**
 * Post translation ability.
 *
 * @package AutoPoly
 * @since   1.10.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Translation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates or updates a translated post.
 *
 * @since 1.10.0
 */
final class Translate_Post extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'translate-post';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Translate Post', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: create or update a translated version of a post. An existing translation in the target language is updated in place, and a title, content, excerpt, slug or set of builder strings you omit is left as it is, so sending only status publishes an existing translation. Creating a translation requires translated_title, and translated_content unless get-content reported builder_storage "meta". For a page whose builder_storage is "meta" (Elementor), translated_builder_strings is required to create the translation; without it the call is refused and nothing is written. The source post must have a language: if it has none, give it one with manage-translation-links action "set_language" first. The translation is linked to the source post automatically, and categories and tags are mapped to their translated equivalents where those exist. Only meta keys allowlisted in AutoPoly settings are written. An Elementor Theme Builder template (post type elementor_library, such as a header or footer) is translated the same way. Its display conditions stay on the source and are not language aware, so showing the translation on pages in the target language needs the free plugin Connect Polylang for Elementor (https://wordpress.org/plugins/connect-polylang-elementor/); translating it alone does not change what visitors see. The template_notice in the result says whether that plugin is active and what to tell the user. Before translating a header or footer, call preflight-template once to see every setting it needs; the menu of a header is a separate step (sync-menu, then set-template-menu). Internal links: in a page builder layout they are pointed at their translations as it is saved, but only when the address belongs to a post, page or term that has a translation; links in translated_content are saved exactly as you send them. Sites route addresses differently (SEO redirects, post type archives, custom routes), so a link can stay in the source language even though the call succeeds. When that happens the result lists those links in links_unresolved with a links_notice explaining the likely causes and how to check them; report them to the user instead of treating the page as fully translated. Apply any glossary terms get-content returned; the result reports any that are missing from the translation. Read "outcome" and "summary" in the result, not "success", and follow next_action: success only means the post was saved. outcome is "complete", "partial" (some builder strings were skipped or not sent, so parts of the page are still in the source language) or "needs_attention". Every call rebuilds the builder layout from the source post, so send ALL builder strings in one call; a string you leave out reverts to the source language. get-content marks builder entries that are settings and not text with translate false: do not translate those. After saving, the public page is requested and reported under public_page (turn off with verify_public false). Do not declare the translation complete merely because it saved: inspect outcome, summary, public_page, links_unresolved and template_notice; investigate and repair any partial, skipped, unresolved, unpublished or 404 result. The slug follows the rule saved in the AutoPoly settings (slug_translation in get-translation-settings): title_translate builds it from the translated title, slug_translate uses your translated_slug (send one; it is made from the title if you do not), and slug_keep keeps the source slug. The result reports slug, slug_mode and, when your translated_slug was not used, slug_notice. translated_slug does not control every address: a static front page is served from the language home address, so do not diagnose or fix a static-homepage URL by changing its slug; use canonical_url and the front-page mapping to investigate instead.', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'source_post_id'     => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the source post to translate from.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'target_language'    => array(
					'type'        => 'string',
					'description' => __( 'Target language slug, for example "fr" or "de".', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'translated_title'   => array(
					'type'        => 'string',
					'description' => __( 'The translated post title. Required when creating a translation; omit it on update to keep the current title.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'translated_content' => array(
					'type'        => 'string',
					'description' => __( 'The translated post content as HTML. Block comment delimiters are preserved. Required when creating a translation, except when get-content reported builder_storage "meta". Omit it on update to keep the current content.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'translated_excerpt' => array(
					'type'        => 'string',
					'description' => __( 'The translated post excerpt. Omit to leave an existing excerpt untouched.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'translated_slug'    => array(
					'type'        => 'string',
					'description' => __( 'The translated URL slug. The saved slug rule of the site decides how it is used: "slug_translate" uses it, "title_translate" and "slug_keep" ignore it and say so in slug_notice (see slug_translation in get-translation-settings). Omit it when updating to leave the existing address untouched.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'translated_meta'    => array(
					'type'        => 'object',
					'description' => __( 'Key-value pairs of translated meta fields. Keys not on the AutoPoly allowlist are rejected and reported in meta_skipped.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'translated_builder_strings' => array(
					/*
					 * A JSON-encoded string is accepted alongside a real array
					 * because MCP clients differ in whether they can send a
					 * structured value for a nested parameter. Declaring 'array'
					 * alone makes WP's rest_is_array() comma-split any string it
					 * receives, which fails validation in a way that tells the
					 * caller nothing useful.
					 *
					 * 'string' must come first: rest_get_best_type_for_value()
					 * returns the first type that matches, and rest_is_array()
					 * accepts any scalar, so listing 'array' first would resolve
					 * a JSON string back to an array and shred it again.
					 */
					'type'        => array( 'string', 'array' ),
					'description' => __( 'Translations of the strings get-content returned in builder_strings, which carry the page builder\'s copy. Required when builder_storage is "meta" to create a translation, or to update one that has no layout yet: such a builder stores its layout in post meta rather than in post_content, so without them the call is refused and nothing is written. When updating a translation that already has a layout, omit them to leave the layout as it is. Not needed when builder_storage is "content", where translating the content field is enough. Accepts either an array of objects or the same array JSON-encoded as a string.', 'autopoly-ai-translation-for-polylang-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'          => array(
								'type'        => 'string',
								'description' => __( 'The id of the string, copied verbatim from builder_strings.', 'autopoly-ai-translation-for-polylang-pro' ),
							),
							'translation' => array(
								'type'        => 'string',
								'description' => __( 'The translated string. Preserve any HTML markup found in the source.', 'autopoly-ai-translation-for-polylang-pro' ),
							),
							'source'      => array(
								'type'        => 'string',
								'description' => __( 'Optional stale-revision guard. Only supply it by copying "value" from get-content byte for byte; never retype or reconstruct it, because builder copy routinely contains non-breaking spaces and HTML entities that look identical to ordinary characters but are not. The write is skipped when it does not match exactly, so omit this field if you cannot copy it verbatim.', 'autopoly-ai-translation-for-polylang-pro' ),
							),
						),
						'required'   => array( 'id', 'translation' ),
					),
				),
				'verify_public'      => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'When true, the default, the site requests the public page after saving and reports its HTTP status, title, headings, menu labels and how many of your strings it shows (public_page in the result). For an Elementor template it checks the target home page of the target language, where the header and footer appear. Set false to skip the request.', 'autopoly-ai-translation-for-polylang-pro' ),
				),
				'status'             => array(
					'type'        => 'string',
					'description' => __( 'Post status for the translation. Downgraded to draft when the current user cannot publish.', 'autopoly-ai-translation-for-polylang-pro' ),
					'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
					'default'     => 'publish',
				),
			),
			// Title and content are required only when creating; Translation_Service enforces that.
			'required'   => array( 'source_post_id', 'target_language' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		return Translation_Service::translate_post( $input );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Requires edit rights on the source post and, when updating, on the existing
	 * translation too.
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		$source_id = isset( $input['source_post_id'] ) ? absint( $input['source_post_id'] ) : 0;

		if ( 0 === $source_id ) {
			return false;
		}

		if ( ! self::user_can( 'edit_post', $input, $source_id ) ) {
			return false;
		}

		$target = isset( $input['target_language'] ) ? sanitize_key( (string) $input['target_language'] ) : '';

		if ( '' === $target ) {
			return false;
		}

		if ( ! function_exists( 'pll_get_post' ) ) {
			return true;
		}

		$existing = (int) pll_get_post( $source_id, $target );

		// Polylang can keep a link to a translation that was deleted outside Polylang. That is "no translation", not "not allowed".
		if ( $existing > 0 && null !== get_post( $existing ) && ! self::user_can( 'edit_post', $input, $existing ) ) {
			return false;
		}

		return true;
	}
}
