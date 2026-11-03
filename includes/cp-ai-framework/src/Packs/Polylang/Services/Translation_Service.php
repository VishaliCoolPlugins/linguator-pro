<?php
/**
 * Post and term translation writes.
 *
 * @package AutoPoly
 * @since   1.10.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Services;

use WP_Error;
use WP_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and updates translations through Polylang's public API.
 *
 * @since 1.10.0
 */
final class Translation_Service {

	/**
	 * Post statuses a translation may be created with.
	 *
	 * @since 1.10.0
	 * @var string[]
	 */
	const ALLOWED_STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Plugin directory of Connect Polylang for Elementor, which makes Elementor
	 * Theme Builder show a template's translation on pages in that language.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const ELEMENTOR_CONNECTOR_PLUGIN = 'connect-polylang-elementor';

	/**
	 * Creates or updates a post translation.
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $args Translation payload.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function translate_post( array $args ) {
		if ( ! function_exists( 'pll_insert_post' ) || ! function_exists( 'pll_get_post_translations' ) ) {
			return Polylang_Guard::unavailable_error();
		}

		$source_id   = isset( $args['source_post_id'] ) ? absint( $args['source_post_id'] ) : 0;
		$target_lang = isset( $args['target_language'] ) ? sanitize_key( (string) $args['target_language'] ) : '';
		$source      = get_post( $source_id );

		if ( null === $source ) {
			return new WP_Error(
				'atfpp_source_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Source post %d was not found.', 'autopoly-ai-translation-for-polylang-pro' ),
					$source_id
				)
			);
		}

		// Checked first: such a post has no language either, and "no language" would send the caller the wrong way.
		if ( function_exists( 'pll_is_translated_post_type' ) && ! pll_is_translated_post_type( $source->post_type ) ) {
			return new WP_Error(
				'atfpp_post_type_not_translated',
				Polylang_Guard::not_translated_message( 'post', $source->post_type )
			);
		}

		$source_lang = (string) pll_get_post_language( $source_id, 'slug' );

		if ( '' === $source_lang ) {
			return new WP_Error(
				'atfpp_no_language',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Source post %d has no language assigned. Give it one with manage-translation-links (action "set_language"), then translate it.', 'autopoly-ai-translation-for-polylang-pro' ),
					$source_id
				)
			);
		}

		if ( null === Language_Service::get_by_slug( $target_lang ) ) {
			return new WP_Error(
				'atfpp_invalid_language',
				sprintf(
					/* translators: %s: language slug. */
					__( 'Target language "%s" was not found.', 'autopoly-ai-translation-for-polylang-pro' ),
					$target_lang
				)
			);
		}

		$translations            = pll_get_post_translations( $source_id );
		$existing_translation_id = isset( $translations[ $target_lang ] ) ? (int) $translations[ $target_lang ] : 0;

		// A link to a translation that no longer exists (deleted outside Polylang) must not be updated; create a fresh one instead.
		if ( $existing_translation_id > 0 && null === get_post( $existing_translation_id ) ) {
			unset( $translations[ $target_lang ] );
			$existing_translation_id = 0;
		}

		$has_title               = isset( $args['translated_title'] ) && '' !== trim( (string) $args['translated_title'] );
		$has_content             = array_key_exists( 'translated_content', $args );

		/*
		 * Checked before the first write. A translation created and then left
		 * without its title or layout is worse than none: it reports success, and
		 * the language switcher leads visitors to a blank page.
		 */
		$refusal = self::check_required_input( $source_id, $existing_translation_id, $args, $has_title, $has_content );

		if ( is_wp_error( $refusal ) ) {
			return $refusal;
		}

		$status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : 'publish';

		if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			$status = 'publish';
		}

		/*
		 * These abilities are reachable at edit_posts, so a caller without publish
		 * rights must not be able to publish through them. Downgrade rather than
		 * fail, so the translation is still saved.
		 */
		$type_object = get_post_type_object( $source->post_type );

		if ( 'publish' === $status && $type_object instanceof WP_Post_Type ) {
			if ( ! current_user_can( $type_object->cap->publish_posts ) ) {
				$status = 'draft';
			}
		}

		$post_data = array(
			'post_status' => $status,
			'post_type'   => $source->post_type,
		);

		/*
		 * Title and content are sent only when supplied, so an update carrying
		 * just a status publishes a translation without re-sending its copy:
		 * wp_update_post() keeps every field it is not given. Creating one
		 * without them was refused above, except content for a builder that
		 * stores its page in meta, which rebuilds post_content when it saves.
		 */
		if ( $has_title ) {
			$post_data['post_title'] = (string) $args['translated_title'];
		}

		if ( $has_content ) {
			$content = (string) $args['translated_content'];

			/*
			 * Mirrors core's wp_insert_post() behaviour. A blanket wp_kses_post() is
			 * deliberately avoided: it would destroy block delimiters and page-builder
			 * payloads that AutoPoly translations legitimately contain.
			 */
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				$content = wp_filter_post_kses( $content );
			}

			/*
			 * A builder that renders from post_content may need the content shaped a
			 * particular way before it will display at all.
			 */
			$post_data['post_content'] = Builder_Service::prepare_content( $content, $source_id );
		}

		/*
		 * Writing a translation is not an edit that makes the translation stale,
		 * so AutoPoly's re-translation tracker must not treat it as one. The REST
		 * flow sets this before its first write in
		 * ATFP_Register_Route::create_translate_post(); without the same guard an
		 * MCP translation would trip ATFP_Re_Translation::atfp_re_translation()
		 * on post_updated and flag content it had just translated.
		 *
		 * Only ATFP_Re_Translation and ATFP_WooCommerce_Attributes_Clone read it,
		 * and the latter is never reached from here, so this changes nothing else.
		 */
		if ( ! defined( 'DOING_ATFPP_BULK_POST_TRANSLATION' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- ATFPP is our plugin prefix.
			define( 'DOING_ATFPP_BULK_POST_TRANSLATION', true );
		}

		/*
		 * Only send excerpt and slug when the caller actually supplied them. The
		 * reference implementation defaulted both to '', so re-running a
		 * translation without a slug blanked it and silently changed the permalink.
		 */
		if ( array_key_exists( 'translated_excerpt', $args ) ) {
			$post_data['post_excerpt'] = (string) $args['translated_excerpt'];
		}

		// The slug follows the rule saved in AutoPoly's settings, as the editor does; translated_slug only feeds "slug_translate".
		$slug = self::resolve_slug( (string) $source->post_name, $args, $has_title ? (string) $args['translated_title'] : '', $existing_translation_id > 0 );

		if ( '' !== $slug['slug'] ) {
			$post_data['post_name'] = $slug['slug'];
		}

		if ( $existing_translation_id > 0 ) {
			$post_data['ID']           = $existing_translation_id;
			$post_data['lang']         = $target_lang;
			$post_data['translations'] = $translations;

			$result = pll_update_post( $post_data );
		} else {
			$translations[ $target_lang ] = 0; // Filled in by pll_insert_post().
			$post_data['translations']    = $translations;

			$result = pll_insert_post( $post_data, $target_lang );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$translated_post_id = (int) $result;

		if ( $translated_post_id <= 0 ) {
			return new WP_Error(
				'atfpp_translation_failed',
				__( 'The translation could not be saved.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		$meta_result = self::write_meta( $translated_post_id, $args );

		self::sync_acf_structure( $source_id, $translated_post_id );

		self::sync_taxonomy_translations( $source_id, $translated_post_id, $target_lang );

		$result = array(
			'success'            => true,
			'source_post_id'     => $source_id,
			'target_language'    => $target_lang,
			'translated_post_id' => $translated_post_id,
			'url'                => get_permalink( $translated_post_id ),
			'status'             => $status,
			'meta_written'       => $meta_result['written'],
			'meta_skipped'       => $meta_result['skipped'],
			'slug'               => rawurldecode( (string) get_post_field( 'post_name', $translated_post_id ) ),
			'slug_mode'          => Settings_Service::slug_mode(),
		);

		$slug['notice'] = self::taken_notice( $slug, $result['slug'] );

		if ( '' !== $slug['notice'] ) {
			$result['slug_notice'] = $slug['notice'];
		}

		$builder = self::write_builder_content( $translated_post_id, $source_id, $args, $target_lang, $source_lang );

		/*
		 * "success" only says the post was saved. "outcome" says whether the copy
		 * the caller sent actually reached the page, so a caller that reads one
		 * field is not told a half-written layout is done.
		 */
		$result['outcome'] = 'complete';
		$result['summary'] = __( 'The translation was saved.', 'autopoly-ai-translation-for-polylang-pro' );

		if ( null !== $builder ) {
			$result['builder'] = $builder;

			$report            = self::builder_report( $builder );
			$result['outcome'] = $report['status'];
			$result['summary'] = $report['summary'];

			if ( 'complete' !== $report['status'] ) {
				$result['warning'] = $report['summary'];
			}
		}

		/*
		 * A builder layout was checked as it was saved. Content sent as HTML is
		 * saved exactly as given, with no link remapping, so check that instead.
		 */
		$unresolved_links = array();

		if ( isset( $result['builder']['links_unresolved'] ) ) {
			$unresolved_links = (array) $result['builder']['links_unresolved'];
			unset( $result['builder']['links_unresolved'] );
		} elseif ( $has_content ) {
			$unresolved_links = Builder_Service::find_unresolved_links( array( (string) $args['translated_content'] ), $target_lang );
		}

		if ( array() !== $unresolved_links ) {
			$result['links_unresolved'] = $unresolved_links;
			$result['links_notice']     = self::links_notice();
		}

		if ( 'elementor_library' === $source->post_type ) {
			$result['template_notice'] = self::template_notice();
		}

		$texts = array_values(
			array_filter(
				self::translated_texts( $args, $meta_result['written'] ),
				function ( $text ) {
					return is_scalar( $text ) && '' !== trim( (string) $text );
				}
			)
		);

		// A call that translated nothing, such as a status change, has no copy to check terms in.
		$glossary_warnings = array() !== $texts ? self::check_glossary( $source, $args, $target_lang, $source_lang ) : array();

		if ( array() !== $glossary_warnings ) {
			$result['glossary_warnings'] = $glossary_warnings;
			$result['glossary_notice']   = __( 'Glossary terms that appear in the source were not found in the translation. This is advisory, not an error: a term can be absent because grammar or word order absorbed it. If it was an oversight, translate again using the expected values. The translation has already been saved either way.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		/*
		 * AutoPoly's editor flow logs every translation, and only logged
		 * translations are tracked for re-translation, so log this one too.
		 * Not a call that only changed the status or slug: it translated
		 * nothing, and logging it would overwrite the post's entry with zeros.
		 */
		if ( array() !== $texts ) {
			Retranslation_Service::record_translation(
				$source,
				$translated_post_id,
				$source_lang,
				$target_lang,
				$texts
			);
		}

		/*
		 * Re-translating the body brings a translation up to date, as AutoPoly's
		 * own re-translation does. Not when a builder layout the source needs was
		 * left unwritten, nor when only the status or title changed: the page
		 * would still be stale.
		 */
		$body_translated = ( null === $builder ) ? $has_content : ! empty( $builder['applied'] );

		if ( $existing_translation_id > 0 && $body_translated ) {
			Retranslation_Service::clear_outdated( $translated_post_id );
		}

		// A translated header or footer still on the source menu is the usual reason a translated site shows an English menu.
		if ( 'elementor_library' === $source->post_type ) {
			$menu_notices = Template_Service::unbound_menu_notices( $translated_post_id, $target_lang );

			if ( array() !== $menu_notices ) {
				$result['menu_notice'] = implode( ' ', $menu_notices );
				$result['outcome']     = 'needs_attention';
				$result['summary']     = trim( $result['summary'] . ' ' . $result['menu_notice'] );
			}
		}

		/*
		 * The database now holds the translation; this asks the site what a
		 * visitor gets. It reports and never fails the call: the post is saved
		 * either way.
		 */
		if ( ! isset( $args['verify_public'] ) || rest_sanitize_boolean( $args['verify_public'] ) ) {
			$requested_slug = ( array_key_exists( 'translated_slug', $args ) && '' !== trim( (string) $args['translated_slug'] ) )
				? (string) get_post_field( 'post_name', $translated_post_id )
				: '';

			$expected = array_map( 'strval', $texts );

			// A template's title is not shown on the page it appears on, so it is not looked for.
			if ( 'elementor_library' === $source->post_type && isset( $args['translated_title'] ) ) {
				$expected = array_values( array_diff( $expected, array( (string) $args['translated_title'] ) ) );
			}

			$verification = Public_Page_Verifier::verify( $translated_post_id, $target_lang, $expected, $requested_slug );

			$result['public_page'] = $verification;

			if ( ! empty( $verification['canonical_url'] ) ) {
				$result['canonical_url'] = $verification['canonical_url'];
			}

			if ( ! empty( $verification['issues'] ) ) {
				$result['outcome'] = 'needs_attention';
				$result['summary'] = trim( $result['summary'] . ' ' . __( 'Public page check:', 'autopoly-ai-translation-for-polylang-pro' ) . ' ' . implode( ' ', (array) $verification['issues'] ) );
			}
		}

		$result['next_action'] = self::next_action( $result );

		return $result;
	}

	/**
	 * Says what the caller should do after this write.
	 *
	 * A model that has to remember the workflow forgets it; a result that states
	 * the next step does not depend on that.
	 *
	 * @since 1.16.0
	 * @param array<string, mixed> $result Result built by translate_post().
	 * @return string One plain instruction.
	 */
	private static function next_action( array $result ) {
		if ( 'complete' !== $result['outcome'] ) {
			return __( 'Not finished. Read summary, then repair what it names (omitted builder strings, a failed public page, unresolved links, a menu still on the source language) and call translate-post again for this post. Do not mark this item done.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		if ( 'publish' !== $result['status'] ) {
			return __( 'Saved but not published, so visitors cannot see it. Publish it with translate-post and status "publish" when the user wants it live.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		if ( isset( $result['public_page'] ) && empty( $result['public_page']['verified'] ) ) {
			return sprintf(
				/* translators: %s: why the check did not run. */
				__( 'Saved, but the public page was NOT checked (%s). Do not assume it works: keep this item on your list as unverified and confirm it with verify-translations at the end. If that cannot reach it either, tell the user to check it in a browser.', 'autopoly-ai-translation-for-polylang-pro' ),
				isset( $result['public_page']['reason'] ) ? (string) $result['public_page']['reason'] : ''
			);
		}

		return __( 'This item is saved. Mark it done in your checklist and continue with the next item from get-translation-plan. When all items are done, call verify-translations.', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * Turns a builder write summary into a status and one plain sentence.
	 *
	 * @since 1.14.0
	 * @param array<string, mixed> $builder Summary from write_builder_content().
	 * @return array{status: string, summary: string} "complete", "partial" or "needs_attention", with the reason.
	 */
	private static function builder_report( array $builder ) {
		if ( ! empty( $builder['layout_unchanged'] ) ) {
			return array(
				'status'  => 'complete',
				'summary' => __( 'The translation was saved. Its existing layout was left as it is.', 'autopoly-ai-translation-for-polylang-pro' ),
			);
		}

		// Content-stored builders, and a layout that could not be written at all, carry no string counts.
		if ( ! isset( $builder['strings_applied'] ) ) {
			if ( ! empty( $builder['applied'] ) ) {
				return array(
					'status'  => 'complete',
					'summary' => __( 'The translation was saved.', 'autopoly-ai-translation-for-polylang-pro' ),
				);
			}

			return array(
				'status'  => 'needs_attention',
				'summary' => __( 'The post was saved, but its page-builder layout was not written, so it may render blank. See "builder" for the reason.', 'autopoly-ai-translation-for-polylang-pro' ),
			);
		}

		$written   = (int) $builder['strings_applied'];
		$unchanged = isset( $builder['strings_unchanged'] ) ? (int) $builder['strings_unchanged'] : 0;
		$skipped   = isset( $builder['strings_skipped'] ) ? count( (array) $builder['strings_skipped'] ) : 0;
		$not_sent  = isset( $builder['strings_not_sent'] ) ? (int) $builder['strings_not_sent'] : 0;
		$total     = isset( $builder['strings_total'] ) ? (int) $builder['strings_total'] : 0;

		if ( 0 === $written && 0 === $unchanged ) {
			return array(
				'status'  => 'needs_attention',
				'summary' => sprintf(
					/* translators: 1: skipped count, 2: not-sent count. */
					__( 'No builder string was written: %1$d skipped (see skipped_details for why) and %2$d not sent. The page layout is still in the source language.', 'autopoly-ai-translation-for-polylang-pro' ),
					$skipped,
					$not_sent
				),
			);
		}

		if ( 0 === $skipped && 0 === $not_sent ) {
			return array(
				'status'  => 'complete',
				'summary' => sprintf(
					/* translators: %d: number of strings. */
					__( 'All %d builder strings were handled.', 'autopoly-ai-translation-for-polylang-pro' ),
					$total
				),
			);
		}

		return array(
			'status'  => 'partial',
			'summary' => sprintf(
				/* translators: 1: written count, 2: total count, 3: skipped count, 4: not-sent count. */
				__( 'Wrote %1$d of %2$d builder strings. Skipped: %3$d (see skipped_details). Not sent: %4$d (ids in not_sent_ids). Skipped and not-sent strings are still in the source language. Each call rebuilds the layout from the source, so send every string in one call: use the same text for a string that should stay as it is.', 'autopoly-ai-translation-for-polylang-pro' ),
				$written,
				$total,
				$skipped,
				$not_sent
			),
		);
	}

	/**
	 * Explains links_unresolved: the likely causes and how to check them.
	 *
	 * Deliberately general. Sites route addresses in many ways, so the notice
	 * gives the caller ways to find out rather than a single assumed cause.
	 *
	 * @since 1.13.0
	 * @return string Notice.
	 */
	private static function links_notice() {
		return __( 'The translation was saved, but the links in links_unresolved still lead to content in another language, so visitors who follow them leave the target language. AutoPoly points a link in a page builder layout at its translation only when the address belongs to a post, page or term with a translation; links in translated_content are saved exactly as sent. Reason "other_language_post": the address is a post in another language. If translation_id is missing, that post has no translation yet: translate it, then translate this post again. If translation_id is present but translation_status is not "publish", publish that translation and translate this post again. Reason "unrecognised_address": the address is not a post or page, so there was nothing to translate it to. Common causes differ between sites: a redirect set up in an SEO or redirection plugin (an address that answers with a 301 or 302 to another page), a post type archive or taxonomy page, a custom route added by a theme, plugin or code snippet, or a page that no longer exists. Check where the address really leads, for example by requesting it and reading any redirect, before choosing a fix. The lasting fix is usually to change the link in the source post to the final address of a translated page and translate again, because a link changed only in the translation reverts the next time the translation is rebuilt from the source. Tell the user which links are affected and why, rather than reporting the translation as complete.', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * What the caller should tell the user after translating a Theme Builder template.
	 *
	 * Elementor's display conditions ignore language, so the translation only
	 * reaches visitors through Connect Polylang for Elementor.
	 *
	 * @since 1.11.0
	 * @return string Notice.
	 */
	private static function template_notice() {
		if ( self::is_elementor_connector_active() ) {
			return __( 'This is an Elementor Theme Builder template. Connect Polylang for Elementor is active on this site, so it shows this translation on pages in the target language in place of the source template; the translation needs no display conditions of its own. If visitors still see the source, tell the user to clear Elementor\'s cache (Elementor > Tools > Regenerate CSS & Data) and any page cache. Its URL is only a preview for logged-in editors.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		return __( 'This is an Elementor Theme Builder template. Its display conditions stay on the source template and are not language aware, so pages in the target language keep showing the source template, and translating it changes nothing visitors see yet. Tell the user to install and activate the free plugin "Connect Polylang for Elementor" (https://wordpress.org/plugins/connect-polylang-elementor/), which shows each template\'s translation on pages in that language; no AutoPoly tool can install it. Its URL is only a preview for logged-in editors.', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * Whether Connect Polylang for Elementor is active, network-wide included.
	 *
	 * @since 1.11.0
	 * @return bool True when active.
	 */
	public static function is_elementor_connector_active() {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( $active as $plugin ) {
			if ( self::ELEMENTOR_CONNECTOR_PLUGIN === strtok( (string) $plugin, '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The texts a translation was given, for the translation log's counts.
	 *
	 * Builder strings replace the content when supplied, since the content of
	 * such a page is only a render of them.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $args         Ability input.
	 * @param string[]             $meta_written Meta keys that were written.
	 * @return array<int, mixed> Texts.
	 */
	private static function translated_texts( array $args, array $meta_written ) {
		$texts = array( isset( $args['translated_title'] ) ? $args['translated_title'] : '' );

		if ( array_key_exists( 'translated_excerpt', $args ) ) {
			$texts[] = $args['translated_excerpt'];
		}

		$builder_texts = array();

		foreach ( self::decode_builder_strings( $args ) as $entry ) {
			if ( is_array( $entry ) && isset( $entry['translation'] ) ) {
				$builder_texts[] = $entry['translation'];
			} elseif ( is_string( $entry ) ) {
				$builder_texts[] = $entry;
			}
		}

		if ( array() === $builder_texts ) {
			$texts[] = isset( $args['translated_content'] ) ? $args['translated_content'] : '';
		}

		foreach ( $meta_written as $key ) {
			$texts[] = $args['translated_meta'][ $key ];
		}

		return array_merge( $texts, $builder_texts );
	}

	/**
	 * Reports glossary terms present in the source but missing from the result.
	 *
	 * Deliberately advisory and run after the write. A mandated term can be
	 * legitimately absent, so refusing the translation would reject good work
	 * over a heuristic; and the post already exists by this point, so reversing
	 * it would lose the caller's translation.
	 *
	 * @since 1.11.0
	 * @param \WP_Post             $source      Source post.
	 * @param array<string, mixed> $args        Ability input.
	 * @param string               $target_lang Target language slug.
	 * @param string               $source_lang Source language slug.
	 * @return array<int, array<string, mixed>> One entry per unapplied term.
	 */
	private static function check_glossary( $source, array $args, $target_lang, $source_lang ) {
		if ( ! Glossary_Service::is_available() ) {
			return array();
		}

		$matched = Glossary_Service::get_matching_entries(
			array( $source->post_title, $source->post_content, $source->post_excerpt ),
			$source_lang,
			$target_lang
		);

		if ( array() === $matched ) {
			return array();
		}

		$translated = array(
			isset( $args['translated_title'] ) ? (string) $args['translated_title'] : '',
			isset( $args['translated_content'] ) ? (string) $args['translated_content'] : '',
			isset( $args['translated_excerpt'] ) ? (string) $args['translated_excerpt'] : '',
		);

		/*
		 * For a builder storing its page in meta, post_content is a derived
		 * render that the caller may not have filled in, so the builder strings
		 * are where the real copy lives and must be scanned too.
		 */
		foreach ( self::decode_builder_strings( $args ) as $entry ) {
			if ( is_array( $entry ) && isset( $entry['translation'] ) && is_string( $entry['translation'] ) ) {
				$translated[] = $entry['translation'];
			} elseif ( is_string( $entry ) ) {
				$translated[] = $entry;
			}
		}

		return Glossary_Service::get_unapplied_terms( $matched, $translated, $target_lang );
	}

	/**
	 * Refuses a translate-post call that would leave the translation incomplete.
	 *
	 * Runs before anything is written. The older behaviour created the post
	 * first and only then reported, inside a success response, that its builder
	 * layout was missing; a caller that missed that flag left a blank page live
	 * behind the language switcher.
	 *
	 * @since 1.12.0
	 * @param int                  $source_id               Source post ID.
	 * @param int                  $existing_translation_id Existing translation ID, or 0 when creating.
	 * @param array<string, mixed> $args                    Ability input.
	 * @param bool                 $has_title               Whether a non-empty title was supplied.
	 * @param bool                 $has_content             Whether translated_content was supplied.
	 * @return true|WP_Error True when the call may proceed, or the reason it may not.
	 */
	private static function check_required_input( $source_id, $existing_translation_id, array $args, $has_title, $has_content ) {
		$in_meta = Builder_Service::STORAGE_META === Builder_Service::get_storage_mode( Builder_Service::detect_editor_type( $source_id ) );

		if ( 0 === $existing_translation_id ) {
			if ( ! $has_title ) {
				return new WP_Error(
					'atfpp_missing_title',
					__( 'translated_title is required when creating a translation. It may be omitted only when updating an existing one.', 'autopoly-ai-translation-for-polylang-pro' )
				);
			}

			// A builder storing its page in meta rebuilds post_content from the layout.
			if ( ! $has_content && ! $in_meta ) {
				return new WP_Error(
					'atfpp_missing_content',
					__( 'translated_content is required when creating a translation. It may be omitted only when updating an existing one, or when get-content reports builder_storage "meta".', 'autopoly-ai-translation-for-polylang-pro' )
				);
			}
		}

		if ( ! $in_meta ) {
			return true;
		}

		if ( array() !== self::decode_builder_strings( $args ) ) {
			// The translation's layout is copied from the source, so a source without one would still render blank.
			if ( array() === Builder_Service::get_elements( $source_id ) ) {
				return new WP_Error(
					'atfpp_no_builder_content',
					sprintf(
						/* translators: %d: post ID. */
						__( 'Source post %d is marked as built with Elementor but has no Elementor layout to translate. Nothing was written.', 'autopoly-ai-translation-for-polylang-pro' ),
						$source_id
					)
				);
			}

			return true;
		}

		if ( 0 === $existing_translation_id ) {
			return new WP_Error(
				'atfpp_missing_builder_strings',
				__( 'The source post is built with a page builder that stores its layout outside post_content (get-content reports builder_storage "meta"), and no usable translated_builder_strings were supplied. The translation would have no layout and render blank, so nothing was created. Call get-content, translate every entry in builder_strings, and pass them as translated_builder_strings.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		if ( array() === Builder_Service::get_elements( $existing_translation_id ) ) {
			return new WP_Error(
				'atfpp_missing_builder_strings',
				sprintf(
					/* translators: %d: post ID. */
					__( 'The existing translation (post %d) has no page-builder layout, so it renders blank, and no usable translated_builder_strings were supplied to give it one. Nothing was written. Call get-content on the source, translate every entry in builder_strings, and pass them as translated_builder_strings.', 'autopoly-ai-translation-for-polylang-pro' ),
					$existing_translation_id
				)
			);
		}

		return true;
	}

	/**
	 * Writes translated page-builder content onto a translation.
	 *
	 * A builder keeps its layout in post meta, so a translation that only
	 * carries post_content renders without one. This runs after the post exists
	 * so the builder has something to save onto.
	 *
	 * A failure here is reported rather than returned as an error: the post has
	 * already been created and reversing it would lose the caller's work.
	 * check_required_input() has already refused the calls that would
	 * predictably fail here.
	 *
	 * @since 1.11.0
	 * @param int                  $translated_post_id Translation post ID.
	 * @param int                  $source_id          Source post ID.
	 * @param array<string, mixed> $args               Ability input.
	 * @param string               $target_lang        Target language slug.
	 * @param string               $source_lang        Source language slug.
	 * @return array<string, mixed>|null Builder summary, or null when not applicable.
	 */
	private static function write_builder_content( $translated_post_id, $source_id, array $args, $target_lang, $source_lang ) {
		$editor_type = Builder_Service::detect_editor_type( $source_id );

		if ( '' === $editor_type ) {
			return null;
		}

		/*
		 * A builder that renders straight from post_content needs nothing more:
		 * the translated content has already been written, and shaped by
		 * Builder_Service::prepare_content() if that builder requires it.
		 */
		if ( Builder_Service::STORAGE_CONTENT === Builder_Service::get_storage_mode( $editor_type ) ) {
			if ( ! array_key_exists( 'translated_content', $args ) ) {
				return array(
					'editor_type'      => $editor_type,
					'storage'          => Builder_Service::STORAGE_CONTENT,
					'applied'          => false,
					'layout_unchanged' => true,
					'message'          => __( 'No translated_content was supplied, so the translation\'s existing content was left as it is.', 'autopoly-ai-translation-for-polylang-pro' ),
				);
			}

			return array(
				'editor_type' => $editor_type,
				'storage'     => Builder_Service::STORAGE_CONTENT,
				'applied'     => true,
				'message'     => __( 'This builder renders from post_content, so the translated content was written directly and no builder strings were required.', 'autopoly-ai-translation-for-polylang-pro' ),
			);
		}

		$strings = self::decode_builder_strings( $args );

		// check_required_input() refused every other case, so this updates a translation that already has a layout.
		if ( empty( $strings ) ) {
			return array(
				'editor_type'      => $editor_type,
				'applied'          => false,
				'layout_unchanged' => true,
				'message'          => __( 'No translated_builder_strings were supplied, so the translation\'s existing layout was left as it is.', 'autopoly-ai-translation-for-polylang-pro' ),
			);
		}

		$applied = Builder_Service::apply_strings(
			$translated_post_id,
			$source_id,
			$strings,
			$target_lang,
			$source_lang
		);

		if ( is_wp_error( $applied ) ) {
			return array(
				'editor_type' => $editor_type,
				'applied'     => false,
				'message'     => $applied->get_error_message(),
			);
		}

		// Nothing written means the layout is a copy of the source, which is not a translation.
		$applied['applied'] = ( (int) $applied['strings_applied'] + (int) $applied['strings_unchanged'] ) > 0;

		return $applied;
	}

	/**
	 * Reads translated_builder_strings in whichever shape the caller sent.
	 *
	 * MCP clients differ in whether a nested array parameter arrives structured
	 * or JSON-encoded, so both are accepted. This is why the parameter's schema
	 * declares both types: left as 'array' alone, WP's rest_is_array() accepts a
	 * JSON string and then wp_parse_list() shreds it on whitespace and commas,
	 * which cannot be undone here.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $args Ability input.
	 * @return array<int|string, mixed> Decoded entries, empty when unusable.
	 */
	private static function decode_builder_strings( array $args ) {
		if ( ! isset( $args['translated_builder_strings'] ) ) {
			return array();
		}

		$raw = $args['translated_builder_strings'];

		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return ( is_array( $decoded ) && JSON_ERROR_NONE === json_last_error() ) ? $decoded : array();
	}

	/**
	 * Writes allowlisted translated meta onto a translation.
	 *
	 * @since 1.10.0
	 * @param int                  $post_id Translation post ID.
	 * @param array<string, mixed> $args    Translation payload.
	 * @return array{written: string[], skipped: string[]} Keys written and rejected.
	 */
	private static function write_meta( $post_id, array $args ) {
		$written = array();
		$skipped = array();

		if ( empty( $args['translated_meta'] ) || ! is_array( $args['translated_meta'] ) ) {
			return array(
				'written' => $written,
				'skipped' => $skipped,
			);
		}

		$allowed = Content_Service::get_allowed_meta_keys( 'post' );

		foreach ( $args['translated_meta'] as $key => $value ) {
			$key = (string) $key;

			if ( ! isset( $allowed[ $key ] ) ) {
				$skipped[] = $key;
				continue;
			}

			// Arrays and objects would allow a serialised payload to be injected.
			if ( ! is_scalar( $value ) ) {
				$skipped[] = $key;
				continue;
			}

			/*
			 * Ability input arrives unslashed and update_post_meta() unslashes
			 * again, so without wp_slash() every quote and backslash in the
			 * translated copy would be silently corrupted.
			 */
			update_post_meta( $post_id, $key, wp_slash( (string) $value ) );

			$written[] = $key;
		}

		return array(
			'written' => $written,
			'skipped' => $skipped,
		);
	}

	/**
	 * Gives a translation the ACF structure its translated field values need.
	 *
	 * write_meta() only stores the translated text, so ACF cannot find it: the
	 * repeater row count, the flexible content layout list and every "_field_xxx"
	 * reference row are missing and get_field() returns nothing. The editor flow
	 * copies these through ATFPP_Helper::sync_acf_structural_fields(); this does
	 * the same for the ability path. Reference rows are only added when absent,
	 * so a value that was already written is never overwritten.
	 *
	 * @since 1.10.0
	 * @param int $source_id     Source post ID.
	 * @param int $translated_id Translation post ID.
	 * @return void
	 */
	private static function sync_acf_structure( $source_id, $translated_id ) {
		if ( ! function_exists( 'acf_get_field' ) || ! class_exists( 'ATFPP_Helper' ) || ! method_exists( 'ATFPP_Helper', 'sync_acf_structural_fields' ) ) {
			return;
		}

		\ATFPP_Helper::sync_acf_structural_fields( (int) $source_id, (int) $translated_id );

		$source_meta = get_post_meta( $source_id );

		foreach ( $source_meta as $key => $values ) {
			if ( '_' !== substr( (string) $key, 0, 1 ) || ! isset( $values[0] ) ) {
				continue;
			}

			$field_key = maybe_unserialize( $values[0] );

			if ( ! is_string( $field_key ) || 0 !== strpos( $field_key, 'field_' ) ) {
				continue;
			}

			if ( ! metadata_exists( 'post', $translated_id, $key ) ) {
				update_post_meta( $translated_id, $key, $field_key );
			}
		}
	}

	/**
	 * Points a translation at the translated equivalents of its source terms.
	 *
	 * @since 1.10.0
	 * @param int    $source_id     Source post ID.
	 * @param int    $translated_id Translation post ID.
	 * @param string $target_lang   Target language slug.
	 * @return void
	 */
	private static function sync_taxonomy_translations( $source_id, $translated_id, $target_lang ) {
		if ( ! function_exists( 'pll_is_translated_taxonomy' ) || ! function_exists( 'pll_get_term' ) ) {
			return;
		}

		$source = get_post( $source_id );

		if ( null === $source ) {
			return;
		}

		foreach ( get_object_taxonomies( $source->post_type ) as $taxonomy ) {
			if ( ! pll_is_translated_taxonomy( $taxonomy ) ) {
				continue;
			}

			$source_terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_wp_error( $source_terms ) || empty( $source_terms ) ) {
				continue;
			}

			$translated_term_ids = array();

			foreach ( $source_terms as $term_id ) {
				$translated_term = (int) pll_get_term( (int) $term_id, $target_lang );

				if ( $translated_term > 0 ) {
					$translated_term_ids[] = $translated_term;
				}
			}

			if ( ! empty( $translated_term_ids ) ) {
				wp_set_object_terms( $translated_id, $translated_term_ids, $taxonomy, false );
			}
		}
	}

	/**
	 * Creates or updates a term translation.
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $args Translation payload.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function translate_term( array $args ) {
		if ( ! function_exists( 'pll_insert_term' ) || ! function_exists( 'pll_get_term_translations' ) ) {
			return Polylang_Guard::unavailable_error();
		}

		$source_id   = isset( $args['source_term_id'] ) ? absint( $args['source_term_id'] ) : 0;
		$target_lang = isset( $args['target_language'] ) ? sanitize_key( (string) $args['target_language'] ) : '';
		$name        = isset( $args['translated_name'] ) ? trim( (string) $args['translated_name'] ) : '';

		if ( '' === $name ) {
			return new WP_Error(
				'atfpp_missing_name',
				__( 'A translated term name is required.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		$source = get_term( $source_id );

		if ( null === $source || is_wp_error( $source ) ) {
			return new WP_Error(
				'atfpp_source_not_found',
				sprintf(
					/* translators: %d: term ID. */
					__( 'Source term %d was not found.', 'autopoly-ai-translation-for-polylang-pro' ),
					$source_id
				)
			);
		}

		if ( function_exists( 'pll_is_translated_taxonomy' ) && ! pll_is_translated_taxonomy( $source->taxonomy ) ) {
			return new WP_Error(
				'atfpp_taxonomy_not_translated',
				Polylang_Guard::not_translated_message( 'term', $source->taxonomy )
			);
		}

		$source_lang = (string) pll_get_term_language( $source_id, 'slug' );

		if ( '' === $source_lang ) {
			return new WP_Error(
				'atfpp_no_language',
				sprintf(
					/* translators: %d: term ID. */
					__( 'Source term %d has no language assigned. Give it one with manage-translation-links (object_type "term", action "set_language"), then translate it.', 'autopoly-ai-translation-for-polylang-pro' ),
					$source_id
				)
			);
		}

		if ( null === Language_Service::get_by_slug( $target_lang ) ) {
			return new WP_Error(
				'atfpp_invalid_language',
				sprintf(
					/* translators: %s: language slug. */
					__( 'Target language "%s" was not found.', 'autopoly-ai-translation-for-polylang-pro' ),
					$target_lang
				)
			);
		}

		$translations            = pll_get_term_translations( $source_id );
		$existing_translation_id = isset( $translations[ $target_lang ] ) ? (int) $translations[ $target_lang ] : 0;

		$term_args = array();

		// As with posts, never blank a value the caller did not supply.
		if ( array_key_exists( 'translated_description', $args ) ) {
			$term_args['description'] = wp_kses_post( (string) $args['translated_description'] );
		}

		$slug = self::resolve_slug( (string) $source->slug, $args, $name, $existing_translation_id > 0 );

		if ( '' !== $slug['slug'] ) {
			$term_args['slug'] = $slug['slug'];
		}

		if ( $existing_translation_id > 0 ) {
			$term_args['name']         = $name;
			$term_args['lang']         = $target_lang;
			$term_args['translations'] = $translations;

			$result = pll_update_term( $existing_translation_id, $term_args );
		} else {
			$translations[ $target_lang ] = 0; // Filled in by pll_insert_term().
			$term_args['translations']    = $translations;

			if ( $source->parent > 0 && function_exists( 'pll_get_term' ) ) {
				$translated_parent = (int) pll_get_term( (int) $source->parent, $target_lang );

				if ( $translated_parent > 0 ) {
					$term_args['parent'] = $translated_parent;
				}
			}

			$result = pll_insert_term( $name, $source->taxonomy, $target_lang, $term_args );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$translated_term_id = is_array( $result ) ? (int) $result['term_id'] : (int) $result;

		$translated_term = get_term( $translated_term_id, $source->taxonomy );
		$result          = array(
			'success'            => true,
			'translated_term_id' => $translated_term_id,
			'slug'               => ( $translated_term instanceof \WP_Term ) ? rawurldecode( (string) $translated_term->slug ) : '',
			'slug_mode'          => Settings_Service::slug_mode(),
		);

		$slug['notice'] = self::taken_notice( $slug, $result['slug'] );

		if ( '' !== $slug['notice'] ) {
			$result['slug_notice'] = $slug['notice'];
		}

		return $result;
	}

	/**
	 * Adds a note when WordPress changed the slug that was asked for.
	 *
	 * Polylang does not let two translations share a slug unless Polylang Pro's
	 * slug sharing is on, so "about" in French becomes "about-2". The editor
	 * behaves the same; saying so stops the caller from trying to "fix" it.
	 *
	 * @since 1.17.0
	 * @param array{slug: string, notice: string} $slug   Result of resolve_slug().
	 * @param string                              $actual Slug the post or term was saved with.
	 * @return string The notice, extended when the slug was changed.
	 */
	private static function taken_notice( array $slug, $actual ) {
		if ( '' === $slug['slug'] || rawurldecode( $slug['slug'] ) === $actual ) {
			return $slug['notice'];
		}

		$changed = sprintf(
			/* translators: 1: slug that was wanted, 2: slug that was saved. */
			__( 'The slug "%1$s" was already used in another language, so it was saved as "%2$s". Polylang only shares one slug between languages when the slug-sharing option of Polylang Pro is on. This is normal and not an error.', 'autopoly-ai-translation-for-polylang-pro' ),
			rawurldecode( $slug['slug'] ),
			$actual
		);

		return trim( $slug['notice'] . ' ' . $changed );
	}

	/**
	 * Decides the slug of a translation from the rule saved in AutoPoly's settings.
	 *
	 * Mirrors the editor so a translation made through an AI assistant gets the
	 * same address as one made in the editor:
	 * - "title_translate": the slug is made from the translated title.
	 * - "slug_translate": the slug the caller sends; if it is missing or equal to the source slug, the translated title is used, as the editor does, so no numbered duplicate of the source slug is written.
	 * - "slug_keep": the source slug is kept.
	 *
	 * Updating an existing translation without a slug leaves its address alone.
	 *
	 * @since 1.17.0
	 * @param string               $source_slug Slug of the source post or term.
	 * @param array<string, mixed> $args        Ability input.
	 * @param string               $title       Translated title or name, or '' when not sent.
	 * @param bool                 $is_update   Whether a translation already exists.
	 * @return array{slug: string, notice: string} The slug to write ('' to leave it), and a note when the caller's slug was not used.
	 */
	private static function resolve_slug( $source_slug, array $args, $title, $is_update ) {
		$mode      = Settings_Service::slug_mode();
		$requested = array_key_exists( 'translated_slug', $args ) ? sanitize_title( (string) $args['translated_slug'] ) : '';
		$source    = sanitize_title( rawurldecode( $source_slug ) );
		$from_text = '' !== trim( $title ) ? sanitize_title( $title ) : '';
		$slug      = '';
		$notice    = '';

		// An update that sends no slug keeps its address, whatever the rule.
		if ( $is_update && '' === $requested ) {
			return array(
				'slug'   => '',
				'notice' => '',
			);
		}

		if ( 'slug_keep' === $mode ) {
			$slug = $source;

			if ( '' !== $requested && $requested !== $source ) {
				$notice = __( 'The site is set to keep the original slug (AutoPoly > Settings > Slug translation), so translated_slug was not used.', 'autopoly-ai-translation-for-polylang-pro' );
			}
		} elseif ( 'slug_translate' === $mode ) {
			if ( '' !== $requested && $requested !== $source ) {
				$slug = $requested;
			} else {
				$slug   = '' !== $from_text ? $from_text : $source;
				$notice = __( 'The site is set to translate the original slug (AutoPoly > Settings > Slug translation), but translated_slug was missing or the same as the source slug, so the slug was made from the translated title. Send a translated_slug to control it.', 'autopoly-ai-translation-for-polylang-pro' );
			}
		} else {
			$slug = $from_text;

			if ( '' !== $requested && $requested !== $from_text ) {
				$notice = __( 'The site is set to use the translated title for the slug (AutoPoly > Settings > Slug translation), so translated_slug was not used.', 'autopoly-ai-translation-for-polylang-pro' );
			}
		}

		return array(
			'slug'   => $slug,
			'notice' => $notice,
		);
	}
}
