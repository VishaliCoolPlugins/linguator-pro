<?php
/**
 * Content discovery and read operations.
 *
 * @package AutoPoly
 * @since   1.10.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Services;

use ATFPP_Helper;
use PLL_Language;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports translation coverage and returns content for translation.
 *
 * @since 1.10.0
 */
final class Content_Service {

	/**
	 * Post statuses considered "content" for counting and listing.
	 *
	 * Used by every counting path so that translated and untranslated totals can
	 * never be derived from different status sets.
	 *
	 * @since 1.10.0
	 * @var string[]
	 */
	const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Well-known product and brand names that stay as written in every language.
	 *
	 * Site-specific names belong in the AutoPoly glossary as "name" entries.
	 *
	 * @since 1.14.0
	 * @var string[]
	 */
	const KEEP_UNCHANGED_TERMS = array(
		'Elementor Pro',
		'Elementor',
		'WordPress',
		'WooCommerce',
		'Polylang',
		'WhatsApp',
		'Mailchimp',
		'GetResponse',
		'PayPal',
		'Stripe',
		'Select2',
		'Cloudflare Turnstile',
		'Cloudflare',
		'Google',
		'reCAPTCHA',
		'Zapier',
		'Slack',
	);

	/**
	 * What get-untranslated-content can list.
	 *
	 * "missing_translation" is the original behaviour: content in another
	 * language that has no translation yet. "no_language" lists content Polylang
	 * translates but that has no language at all, which every other listing
	 * passes over because it cannot be translated until it is given one.
	 *
	 * @since 1.13.0
	 * @var string[]
	 */
	const LANGUAGE_STATES = array( 'missing_translation', 'no_language' );

	/**
	 * Post meta in which Elementor records a template's type, such as "header".
	 *
	 * @since 1.13.0
	 * @var string
	 */
	const ELEMENTOR_TEMPLATE_TYPE_META = '_elementor_template_type';

	/**
	 * Returns translatable post types with content counts.
	 *
	 * @since 1.10.0
	 * @return array<int, array<string, mixed>> Post type descriptors.
	 */
	public static function get_translatable_post_types() {
		if ( ! Polylang_Guard::model_ready() ) {
			return array();
		}

		return self::describe_post_types( PLL()->model->get_translated_post_types() );
	}

	/**
	 * Returns translatable taxonomies with term counts.
	 *
	 * @since 1.10.0
	 * @return array<int, array<string, mixed>> Taxonomy descriptors.
	 */
	public static function get_translatable_taxonomies() {
		if ( ! Polylang_Guard::model_ready() ) {
			return array();
		}

		return self::describe_taxonomies( PLL()->model->get_translated_taxonomies() );
	}

	/**
	 * Everything list-content-types reports.
	 *
	 * Also lists the types Polylang offers but has switched off. A caller
	 * cannot discover those any other way: their items have no language, so
	 * every other ability passes over them in silence, and Elementor headers
	 * and footers looked translated when they had never been reachable.
	 *
	 * @since 1.12.0
	 * @return array<string, mixed> Content types, plus the switched-off ones and a notice when there are any.
	 */
	public static function get_content_types() {
		$result = array(
			'post_types' => self::get_translatable_post_types(),
			'taxonomies' => self::get_translatable_taxonomies(),
		);

		if ( ! Polylang_Guard::model_ready() ) {
			return $result;
		}

		$post_types = self::describe_post_types( array_diff( Polylang_Guard::optional_post_types(), PLL()->model->get_translated_post_types() ) );
		$taxonomies = self::describe_taxonomies( array_diff( Polylang_Guard::optional_taxonomies(), PLL()->model->get_translated_taxonomies() ) );

		if ( array() === $post_types && array() === $taxonomies ) {
			return $result;
		}

		$result['not_translated_post_types'] = $post_types;
		$result['not_translated_taxonomies'] = $taxonomies;
		$result['not_translated_notice']     = sprintf(
			/* translators: %s: settings location. */
			__( 'not_translated_post_types and not_translated_taxonomies are content types Polylang can translate but that are switched off. Their items have no language, cannot be translated, and never appear in get-untranslated-content. If the user wants any of them translated, such as Elementor templates for headers and footers, tell them a site administrator must tick them under %s and save; no AutoPoly tool can change this setting. Translated Elementor templates also need the free plugin Connect Polylang for Elementor to show on pages in their language, so mention it when the user asks about headers and footers.', 'autopoly-ai-translation-for-polylang-pro' ),
			Polylang_Guard::content_types_setting_location()
		);

		return $result;
	}

	/**
	 * Builds WP_Query arguments selecting posts with no language.
	 *
	 * Enabling a post type in Polylang's settings does not give its existing
	 * items a language, so without this they are in no count and no listing.
	 *
	 * @since 1.13.0
	 * @param string $post_type Post type to scope to.
	 * @param int    $limit     Maximum posts to return.
	 * @param int    $offset    Number of posts to skip.
	 * @return array<string, mixed> Query arguments.
	 */
	private static function build_no_language_args( $post_type, $limit, $offset ) {
		return array(
			'post_type'              => $post_type,
			'post_status'            => self::STATUSES,
			'posts_per_page'         => $limit,
			'offset'                 => $offset,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'no_found_rows'          => false,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'lang'                   => '',
			'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- NOT EXISTS on the indexed language relationship.
				array(
					'taxonomy' => 'language',
					'operator' => 'NOT EXISTS',
				),
			),
		);
	}

	/**
	 * Describes post types with their content counts.
	 *
	 * @since 1.12.0
	 * @param string[] $post_types Post type names.
	 * @return array<int, array<string, mixed>> Post type descriptors.
	 */
	private static function describe_post_types( array $post_types ) {
		$result = array();

		foreach ( $post_types as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( null === $object ) {
				continue;
			}

			$counts = wp_count_posts( $post_type );
			$total  = 0;

			foreach ( self::STATUSES as $status ) {
				// Not every status exists on every post type.
				if ( isset( $counts->$status ) ) {
					$total += (int) $counts->$status;
				}
			}

			$result[] = array(
				'name'  => $post_type,
				'label' => $object->labels->name,
				'count' => $total,
			);
		}

		return $result;
	}

	/**
	 * Describes taxonomies with their term counts.
	 *
	 * @since 1.12.0
	 * @param string[] $taxonomies Taxonomy names.
	 * @return array<int, array<string, mixed>> Taxonomy descriptors.
	 */
	private static function describe_taxonomies( array $taxonomies ) {
		$result = array();

		foreach ( $taxonomies as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );

			if ( false === $object ) {
				continue;
			}

			$count = wp_count_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);

			$result[] = array(
				'name'  => $taxonomy,
				'label' => $object->labels->name,
				'count' => is_wp_error( $count ) ? 0 : (int) $count,
			);
		}

		return $result;
	}

	/**
	 * Returns the language taxonomy term_taxonomy_id for a language.
	 *
	 * @since 1.10.0
	 * @param PLL_Language $language Language object.
	 * @return int Term taxonomy ID, or 0 when unavailable.
	 */
	private static function get_language_tt_id( PLL_Language $language ) {
		if ( ! method_exists( $language, 'get_tax_prop' ) ) {
			return 0;
		}

		return (int) $language->get_tax_prop( 'language', 'term_taxonomy_id' );
	}

	/**
	 * Returns the translation-group term taxonomy IDs already covering a language.
	 *
	 * Two bounded queries replace the reference implementation's per-post
	 * pll_get_post() loop.
	 *
	 * @since 1.10.0
	 * @param PLL_Language $target    Target language.
	 * @param string       $post_type Post type to scope to.
	 * @return int[] Term taxonomy IDs of covered translation groups.
	 */
	private static function get_translated_group_tt_ids( PLL_Language $target, $post_type ) {
		$target_tt_id = self::get_language_tt_id( $target );

		if ( 0 === $target_tt_id ) {
			return array();
		}

		$translated_ids = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => self::STATUSES,
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'lang'                   => '',
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Indexed term_taxonomy_id lookup.
					array(
						'taxonomy' => 'language',
						'field'    => 'term_taxonomy_id',
						'terms'    => array( $target_tt_id ),
					),
				),
			)
		);

		if ( empty( $translated_ids ) ) {
			return array();
		}

		$tt_ids = wp_get_object_terms( $translated_ids, 'post_translations', array( 'fields' => 'tt_ids' ) );

		if ( is_wp_error( $tt_ids ) ) {
			return array();
		}

		return array_map( 'intval', $tt_ids );
	}

	/**
	 * Builds WP_Query arguments selecting content missing a target translation.
	 *
	 * @since 1.10.0
	 * @param PLL_Language $target    Target language.
	 * @param string       $post_type Post type to scope to.
	 * @param int          $limit     Maximum posts to return.
	 * @param int          $offset    Number of posts to skip.
	 * @return array<string, mixed>|null Query arguments, or null when not resolvable.
	 */
	private static function build_untranslated_args( PLL_Language $target, $post_type, $limit, $offset ) {
		$source_tt_ids = array();

		foreach ( PLL()->model->get_languages_list() as $language ) {
			if ( ! $language instanceof PLL_Language || $language->slug === $target->slug ) {
				continue;
			}

			$tt_id = self::get_language_tt_id( $language );

			if ( 0 !== $tt_id ) {
				$source_tt_ids[] = $tt_id;
			}
		}

		if ( empty( $source_tt_ids ) ) {
			return null;
		}

		$tax_query = array(
			array(
				'taxonomy' => 'language',
				'field'    => 'term_taxonomy_id',
				'terms'    => $source_tt_ids,
			),
		);

		$exclude_tt_ids = self::get_translated_group_tt_ids( $target, $post_type );

		if ( ! empty( $exclude_tt_ids ) ) {
			/*
			 * NOT IN compiles to a NOT EXISTS subquery, so posts carrying no
			 * post_translations term at all correctly remain in the result.
			 */
			$tax_query[] = array(
				'taxonomy' => 'post_translations',
				'field'    => 'term_taxonomy_id',
				'terms'    => $exclude_tt_ids,
				'operator' => 'NOT IN',
			);
		}

		return array(
			'post_type'              => $post_type,
			'post_status'            => self::STATUSES,
			'posts_per_page'         => $limit,
			'offset'                 => $offset,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'no_found_rows'          => false,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'lang'                   => '',
			'tax_query'              => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Indexed term_taxonomy_id lookup.
		);
	}

	/**
	 * Counts posts assigned to a language.
	 *
	 * Replaces pll_count_posts(), which can only count a single status and would
	 * therefore disagree with the untranslated scan.
	 *
	 * @since 1.10.0
	 * @param PLL_Language $language  Language to count.
	 * @param string       $post_type Post type to scope to.
	 * @return int Number of posts.
	 */
	private static function count_posts_in_language( PLL_Language $language, $post_type ) {
		$tt_id = self::get_language_tt_id( $language );

		if ( 0 === $tt_id ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => self::STATUSES,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'lang'                   => '',
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Indexed term_taxonomy_id lookup.
					array(
						'taxonomy' => 'language',
						'field'    => 'term_taxonomy_id',
						'terms'    => array( $tt_id ),
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Explains why a requested post type can have no translatable content.
	 *
	 * @since 1.12.0
	 * @param string[] $post_types Requested post types.
	 * @return string The explanation, or '' when Polylang translates every one.
	 */
	private static function type_notice( array $post_types ) {
		$translated = PLL()->model->get_translated_post_types();

		foreach ( $post_types as $post_type ) {
			if ( in_array( $post_type, $translated, true ) ) {
				continue;
			}

			if ( ! post_type_exists( $post_type ) ) {
				return sprintf(
					/* translators: %s: post type name. */
					__( 'Post type "%s" does not exist on this site. Call list-content-types for the valid names.', 'autopoly-ai-translation-for-polylang-pro' ),
					$post_type
				);
			}

			return Polylang_Guard::not_translated_message( 'post', $post_type );
		}

		return '';
	}

	/**
	 * Reports translation coverage per content type and language.
	 *
	 * @since 1.10.0
	 * @param string|null $content_type Optional post type filter.
	 * @param string|null $language     Optional language slug filter.
	 * @return array<string, mixed> Coverage report keyed by post type, or a lone "notice" for a type Polylang does not translate.
	 */
	public static function get_translation_status( $content_type = null, $language = null ) {
		if ( ! Polylang_Guard::model_ready() ) {
			return array();
		}

		$languages  = PLL()->model->get_languages_list();
		$post_types = ( null !== $content_type && '' !== $content_type )
			? array( sanitize_key( (string) $content_type ) )
			: PLL()->model->get_translated_post_types();

		// Such a type counted 0 of 0 items, which reported as 100% translated.
		$notice = self::type_notice( $post_types );

		if ( '' !== $notice ) {
			return array( 'notice' => $notice );
		}

		$filter_slug = ( null !== $language && '' !== $language ) ? sanitize_key( (string) $language ) : '';
		$status      = array();

		foreach ( $post_types as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( null === $object ) {
				continue;
			}

			$type_status = array(
				'label'     => $object->labels->name,
				'languages' => array(),
			);

			foreach ( $languages as $target ) {
				if ( ! $target instanceof PLL_Language ) {
					continue;
				}

				if ( '' !== $filter_slug && $target->slug !== $filter_slug ) {
					continue;
				}

				$translated = self::count_posts_in_language( $target, $post_type );

				$untranslated = 0;
				$args         = self::build_untranslated_args( $target, $post_type, 1, 0 );

				if ( null !== $args ) {
					$query        = new WP_Query( $args );
					$untranslated = (int) $query->found_posts;
				}

				$total      = $translated + $untranslated;
				$percentage = ( $total > 0 ) ? round( ( $translated / $total ) * 100, 1 ) : 100;

				$type_status['languages'][ $target->slug ] = array(
					'translated'   => $translated,
					'untranslated' => $untranslated,
					'total'        => $total,
					'percentage'   => $percentage,
				);
			}

			/*
			 * Reported once per type: these items are in none of the counts above,
			 * which read 0 of 0 = 100% for a type whose items all lack a language.
			 */
			$query = new WP_Query( self::build_no_language_args( $post_type, 1, 0 ) );

			$type_status['without_language'] = (int) $query->found_posts;

			if ( $type_status['without_language'] > 0 ) {
				$type_status['without_language_notice'] = __( 'without_language counts items with no language assigned; they are in none of the other counts and cannot be translated yet. List them with get-untranslated-content and language_state "no_language", give them a language with manage-translation-links action "assign_language", then translate them.', 'autopoly-ai-translation-for-polylang-pro' );
			}

			$status[ $post_type ] = $type_status;
		}

		return $status;
	}

	/**
	 * Lists content missing a translation in the target language.
	 *
	 * @since 1.10.0
	 * @since 1.13.0 Added $language_state, and template_type on Elementor templates.
	 * @param string      $target_language Target language slug.
	 * @param string|null $content_type    Optional post type filter.
	 * @param int         $limit           Maximum items to return.
	 * @param int         $offset          Number of items to skip.
	 * @param string      $language_state  "missing_translation" (default) or "no_language".
	 * @return array<string, mixed> Items, total and has_more flag.
	 */
	public static function get_untranslated( $target_language, $content_type = null, $limit = 20, $offset = 0, $language_state = 'missing_translation' ) {
		$empty = array(
			'items'    => array(),
			'total'    => 0,
			'has_more' => false,
		);

		if ( ! Polylang_Guard::model_ready() ) {
			return $empty;
		}

		$target = Language_Service::get_by_slug( (string) $target_language );

		if ( null === $target ) {
			return $empty;
		}

		$limit  = max( 1, min( (int) $limit, 100 ) );
		$offset = max( 0, (int) $offset );

		$post_types = ( null !== $content_type && '' !== $content_type )
			? array( sanitize_key( (string) $content_type ) )
			: PLL()->model->get_translated_post_types();

		/*
		 * A type Polylang does not translate has no language on any item, so the
		 * query below finds nothing, and an empty list reads as "all translated".
		 */
		$notice = self::type_notice( $post_types );

		if ( '' !== $notice ) {
			return $empty + array( 'notice' => $notice );
		}

		$no_language = 'no_language' === $language_state;
		$items       = array();
		$total       = 0;

		foreach ( $post_types as $post_type ) {
			$args = $no_language
				? self::build_no_language_args( $post_type, $limit, $offset )
				: self::build_untranslated_args( $target, $post_type, $limit, $offset );

			if ( null === $args ) {
				continue;
			}

			$query  = new WP_Query( $args );
			$total += (int) $query->found_posts;

			foreach ( $query->posts as $post ) {
				$language = function_exists( 'pll_get_post_language' )
					? (string) pll_get_post_language( $post->ID, 'slug' )
					: '';

				$item = array(
					'id'            => (int) $post->ID,
					'title'         => $post->post_title,
					'type'          => $post->post_type,
					'content_type'  => $post->post_type,
					'status'        => $post->post_status,
					'language'      => $language,
					'modified_date' => $post->post_modified,
				);

				// Lets a caller find the header and footer without opening every template.
				if ( 'elementor_library' === $post->post_type ) {
					$item['template_type'] = (string) get_post_meta( $post->ID, self::ELEMENTOR_TEMPLATE_TYPE_META, true );
				}

				$items[] = $item;
			}
		}

		// Each post type is queried with its own LIMIT, so trim the merged set.
		$items = array_slice( $items, 0, $limit );

		$result = array(
			'items'    => $items,
			'total'    => $total,
			'has_more' => ( $offset + $limit ) < $total,
		);

		if ( $no_language && $total > 0 ) {
			$result['notice'] = __( 'These items have no language, so they cannot be translated yet and target_language was not used to select them. Give them their language with manage-translation-links action "assign_language" (usually the default language), then list untranslated content again and translate them.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		return $result;
	}

	/**
	 * Returns AutoPoly's translatable meta allowlist.
	 *
	 * @since 1.10.0
	 * @param string $type Either "post" or "term".
	 * @return array<string, mixed> Allowlisted meta keys, keyed by key name.
	 */
	public static function get_allowed_meta_keys( $type = 'post' ) {
		if ( ! class_exists( 'ATFPP_Helper' ) || ! method_exists( 'ATFPP_Helper', 'get_allowed_custom_fields' ) ) {
			return array();
		}

		$allowed = ATFPP_Helper::get_allowed_custom_fields( $type );

		return is_array( $allowed ) ? $allowed : array();
	}

	/**
	 * Filters raw meta down to what may be exposed for translation.
	 *
	 * The reference implementation skipped every underscore-prefixed key, which
	 * hid exactly the SEO fields AutoPoly is built to translate. Private keys are
	 * now exposed when, and only when, they are on the allowlist.
	 *
	 * @since 1.10.0
	 * @param array<string, array<int, mixed>> $meta    Raw get_post_meta()/get_term_meta() output.
	 * @param array<string, mixed>             $allowed Allowlisted meta keys.
	 * @param string                           $type    Meta type, "post" or "term".
	 * @return array<string, mixed> Exposed meta.
	 */
	private static function filter_meta( array $meta, array $allowed, $type ) {
		$public_meta = array();

		foreach ( $meta as $key => $values ) {
			$key        = (string) $key;
			$is_allowed = isset( $allowed[ $key ] );

			if ( 0 === strpos( $key, '_' ) && ! $is_allowed ) {
				continue;
			}

			if ( is_protected_meta( $key, $type ) && ! $is_allowed ) {
				continue;
			}

			$public_meta[ $key ] = ( is_array( $values ) && 1 === count( $values ) ) ? $values[0] : $values;
		}

		return $public_meta;
	}

	/**
	 * Returns a post with everything needed to translate it.
	 *
	 * @since 1.10.0
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null Post payload, or null when not found.
	 */
	public static function get_post_content( $post_id, $target_language = '' ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( null === $post ) {
			return null;
		}

		$language     = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id, 'slug' ) : '';
		$translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $post_id ) : array();
		$trans_detail = array();

		foreach ( (array) $translations as $slug => $translation_id ) {
			$translation_id = (int) $translation_id;

			if ( $translation_id === $post_id ) {
				continue;
			}

			$translation = get_post( $translation_id );

			if ( null !== $translation ) {
				$trans_detail[ $slug ] = array(
					'id'    => $translation_id,
					'title' => $translation->post_title,
				);
			}
		}

		$categories = self::format_post_terms( $post_id, $post->post_type, 'category' );
		$tags       = self::format_post_terms( $post_id, $post->post_type, 'post_tag' );

		$allowed = self::get_allowed_meta_keys( 'post' );
		$meta    = get_post_meta( $post_id );

		$payload = array(
			'id'                     => $post_id,
			'title'                  => $post->post_title,
			'content'                => $post->post_content,
			'excerpt'                => $post->post_excerpt,
			'type'                   => $post->post_type,
			'status'                 => $post->post_status,
			'language'               => $language,
			'translations'           => $trans_detail,
			'categories'             => $categories,
			'tags'                   => $tags,
			'meta'                   => self::filter_meta( is_array( $meta ) ? $meta : array(), $allowed, 'post' ),
			'translatable_meta_keys' => array_keys( $allowed ),
			'editor_type'            => Builder_Service::detect_editor_type( $post_id ),
		);

		/*
		 * Builders differ in where they keep the page, and that decides what the
		 * caller must translate. Elementor keeps a tree in post meta and leaves
		 * only a flattened render in post_content, so translating post_content
		 * yields a page stripped of its layout. Divi 5 keeps its blocks in
		 * post_content itself, so there the ordinary content field is correct.
		 */
		$storage = Builder_Service::get_storage_mode( $payload['editor_type'] );

		if ( Builder_Service::STORAGE_META === $storage ) {
			$payload['builder_storage'] = $storage;
			$payload['builder_strings'] = Builder_Service::get_strings( $post_id );
			$payload['builder_notice']  = __( 'This post is built with a page builder that stores the page outside post_content, so the "content" field is omitted. Translate every entry in "builder_strings" and pass the results back as translated_builder_strings; translate-post refuses to create the translation without them. translated_content is not needed: the builder rebuilds the page content from the translated layout.', 'autopoly-ai-translation-for-polylang-pro' );
		} elseif ( Builder_Service::STORAGE_CONTENT === $storage ) {
			$payload['builder_storage'] = $storage;
			$payload['builder_notice']  = __( 'This post is built with a page builder that stores the page in post_content as block markup. Translate the "content" field as usual, preserving every block delimiter and attribute exactly. No translated_builder_strings are required.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		$payload = self::attach_glossary( $payload, $language, $target_language );
		$payload = self::attach_keep_unchanged( $payload );

		/*
		 * For a builder storing its page in meta, post_content is only a
		 * flattened render of the same copy builder_strings carries. On a large
		 * page it doubled the payload and pushed the strings the caller needs
		 * past what an MCP client can read in one go. Dropped only after the
		 * glossary scan above, which still reads it.
		 */
		if ( Builder_Service::STORAGE_META === $storage ) {
			unset( $payload['content'] );
		}

		return $payload;
	}

	/**
	 * Adds the glossary terms that appear in this post, when there are any.
	 *
	 * The glossary governs AutoPoly's own translation, so a caller that ignores
	 * it produces content disagreeing with the rest of the site. Carrying the
	 * relevant terms in the payload the caller is already reading is what makes
	 * that hard to skip, rather than relying on it calling get-glossary first.
	 *
	 * Only matching terms travel, so the payload grows by a line or two rather
	 * than by the whole glossary. Both keys are omitted when nothing matches, so
	 * an unrelated post is unchanged.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $payload         Assembled post payload.
	 * @param string               $source_language Language of the post.
	 * @param string               $target_language Optional target language filter.
	 * @return array<string, mixed> The payload, with glossary keys when relevant.
	 */
	private static function attach_glossary( array $payload, $source_language, $target_language ) {
		if ( '' === (string) $source_language ) {
			return $payload;
		}

		$texts = array(
			isset( $payload['title'] ) ? (string) $payload['title'] : '',
			isset( $payload['content'] ) ? (string) $payload['content'] : '',
			isset( $payload['excerpt'] ) ? (string) $payload['excerpt'] : '',
		);

		/*
		 * For a builder that stores its page in meta, post_content is only a
		 * flattened render and may lag behind the tree, so the strings the
		 * caller will actually translate are scanned too.
		 */
		if ( ! empty( $payload['builder_strings'] ) && is_array( $payload['builder_strings'] ) ) {
			foreach ( $payload['builder_strings'] as $string ) {
				if ( isset( $string['value'] ) ) {
					$texts[] = (string) $string['value'];
				}
			}
		}

		$matched = Glossary_Service::get_matching_entries( $texts, $source_language, $target_language );

		if ( array() === $matched ) {
			return $payload;
		}

		$payload['glossary']        = $matched;
		$payload['glossary_notice'] = __( 'These glossary terms appear in this post and have mandated translations. Use the listed translation for the target language exactly, including capitalisation. A term of type "name" is a proper noun and should be carried over unchanged unless a translation is given. AutoPoly\'s own translation applies these, so ignoring them makes this page disagree with the rest of the site.', 'autopoly-ai-translation-for-polylang-pro' );

		return $payload;
	}

	/**
	 * Adds well-known product and brand names found in the post, which must not be translated.
	 *
	 * Read only: nothing is written to the glossary. It covers the case where
	 * the site has no glossary entry for a name, so an agent would otherwise
	 * translate or transliterate it. A name the glossary already lists is left
	 * to the glossary.
	 *
	 * @since 1.14.0
	 * @param array<string, mixed> $payload Assembled post payload.
	 * @return array<string, mixed> The payload, with keep_unchanged keys when a name is present.
	 */
	private static function attach_keep_unchanged( array $payload ) {
		$texts = array(
			isset( $payload['title'] ) ? (string) $payload['title'] : '',
			isset( $payload['content'] ) ? (string) $payload['content'] : '',
			isset( $payload['excerpt'] ) ? (string) $payload['excerpt'] : '',
		);

		if ( ! empty( $payload['builder_strings'] ) && is_array( $payload['builder_strings'] ) ) {
			foreach ( $payload['builder_strings'] as $string ) {
				if ( isset( $string['value'] ) ) {
					$texts[] = (string) $string['value'];
				}
			}
		}

		$haystack = implode( "
", $texts );
		$in_gloss = array();

		if ( ! empty( $payload['glossary'] ) && is_array( $payload['glossary'] ) ) {
			foreach ( $payload['glossary'] as $entry ) {
				if ( isset( $entry['term'] ) ) {
					$in_gloss[ strtolower( (string) $entry['term'] ) ] = true;
				}
			}
		}

		$found = array();

		foreach ( self::KEEP_UNCHANGED_TERMS as $term ) {
			if ( isset( $in_gloss[ strtolower( $term ) ] ) ) {
				continue;
			}

			if ( 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])/u', $haystack ) ) {
				$found[] = $term;
			}
		}

		if ( array() === $found ) {
			return $payload;
		}

		$payload['keep_unchanged']        = $found;
		$payload['keep_unchanged_notice'] = __( 'These are product or brand names found in this post. Carry them over exactly as written; do not translate or transliterate them.', 'autopoly-ai-translation-for-polylang-pro' );

		return $payload;
	}

	/**
	 * Returns a post's terms in a taxonomy as plain arrays.
	 *
	 * @since 1.10.0
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @param string $taxonomy  Taxonomy name.
	 * @return array<int, array<string, mixed>> Term descriptors.
	 */
	private static function format_post_terms( $post_id, $post_type, $taxonomy ) {
		if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
			return array();
		}

		$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'all' ) );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$formatted = array();

		foreach ( $terms as $term ) {
			$formatted[] = array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
				'slug' => $term->slug,
			);
		}

		return $formatted;
	}

	/**
	 * Returns a term with everything needed to translate it.
	 *
	 * @since 1.10.0
	 * @param int $term_id Term ID.
	 * @return array<string, mixed>|null Term payload, or null when not found.
	 */
	public static function get_term_content( $term_id ) {
		$term_id = absint( $term_id );
		$term    = get_term( $term_id );

		if ( null === $term || is_wp_error( $term ) ) {
			return null;
		}

		$language     = function_exists( 'pll_get_term_language' ) ? (string) pll_get_term_language( $term_id, 'slug' ) : '';
		$translations = function_exists( 'pll_get_term_translations' ) ? pll_get_term_translations( $term_id ) : array();
		$trans_detail = array();

		foreach ( (array) $translations as $slug => $translation_id ) {
			$translation_id = (int) $translation_id;

			if ( $translation_id === $term_id ) {
				continue;
			}

			$translation = get_term( $translation_id );

			if ( null !== $translation && ! is_wp_error( $translation ) ) {
				$trans_detail[ $slug ] = array(
					'id'   => $translation_id,
					'name' => $translation->name,
				);
			}
		}

		$allowed = self::get_allowed_meta_keys( 'term' );
		$meta    = get_term_meta( $term_id );

		return array(
			'id'                     => $term_id,
			'name'                   => $term->name,
			'slug'                   => $term->slug,
			'description'            => $term->description,
			'taxonomy'               => $term->taxonomy,
			'parent'                 => (int) $term->parent,
			'language'               => $language,
			'translations'           => $trans_detail,
			'meta'                   => self::filter_meta( is_array( $meta ) ? $meta : array(), $allowed, 'term' ),
			'translatable_meta_keys' => array_keys( $allowed ),
		);
	}
}
