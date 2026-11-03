<?php
/**
 * Public URLs of the site, by type and language.
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
 * Lists every public URL of the site with what each page should be: its
 * language, the <html lang> it must carry and the URLs of its translations.
 *
 * Read straight from WordPress and Polylang; nothing is fetched. Used by the
 * list-urls ability.
 *
 * @since 1.1.0
 */
final class Url_Service {

	/**
	 * URL types, in the order an assistant usually checks them.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	const TYPES = array( 'home', 'pages', 'posts', 'custom_post_types', 'categories', 'tags', 'custom_taxonomies', 'archives' );

	/**
	 * Viewable post types that are not pages of the site: media files and
	 * page-builder template libraries, which only render inside other pages.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	const EXCLUDED_POST_TYPES = array( 'attachment', 'elementor_library', 'e-floating-buttons' );

	/**
	 * Viewable taxonomies with no archive worth checking: post formats and
	 * Polylang's own internal taxonomies.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	const EXCLUDED_TAXONOMIES = array( 'post_format', 'language', 'term_language', 'post_translations', 'term_translations' );

	/**
	 * One page of URLs of one type.
	 *
	 * @since 1.1.0
	 * @param string                               $type      One of TYPES.
	 * @param array<string, array<string, string>> $languages Languages by slug, from Content_Service::languages().
	 * @param string                               $language  Language filter, or ''.
	 * @param int                                  $page      Page number.
	 * @param int                                  $per_page  Page size.
	 * @return array{total: int, items: array<int, array<string, mixed>>}
	 */
	public static function listing( $type, array $languages, $language, $page, $per_page ) {
		switch ( $type ) {
			case 'home':
				return self::paginate( self::home_items( $languages, $language ), $page, $per_page );

			case 'archives':
				return self::paginate( self::archive_items( $languages, $language ), $page, $per_page );

			case 'categories':
			case 'tags':
			case 'custom_taxonomies':
				return self::term_items( self::taxonomies_for( $type ), $languages, $language, $page, $per_page );

			default:
				return self::post_items( self::post_types_for( $type ), $languages, $language, $page, $per_page );
		}
	}

	/**
	 * What every URL on the site shares: languages, URL mode and homepage setup.
	 *
	 * @since 1.1.0
	 * @param array<string, array<string, string>> $languages Languages by slug.
	 * @return array<string, mixed>
	 */
	public static function site( array $languages ) {
		$options = PLL()->options;
		$modes   = array( 'content', 'directory', 'subdomain', 'domain' );
		$mode    = isset( $options['force_lang'] ) ? (int) $options['force_lang'] : 0;

		return array(
			'home_url'                => home_url( '/' ),
			'default_language'        => function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '',
			'languages'               => array_values( $languages ),
			'language_in_url'         => $modes[ $mode ] ?? 'content',
			'default_language_in_url' => empty( $options['hide_default'] ),
			'homepage_shows'          => 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) > 0 ? 'page' : 'latest_posts',
			'likely_local_site'       => class_exists( 'DUPCAP_Routing_Scanner' ) && \DUPCAP_Routing_Scanner::is_likely_local_site(),
		);
	}

	/**
	 * Count of URLs per type, overall and per language.
	 *
	 * @since 1.1.0
	 * @param array<string, array<string, string>> $languages Languages by slug.
	 * @param string                               $language  Language filter, or ''.
	 * @return array<int, array<string, mixed>>
	 */
	public static function overview( array $languages, $language ) {
		$slugs = '' !== $language ? array( $language ) : array_keys( $languages );
		$rows  = array();

		foreach ( self::TYPES as $type ) {
			$by_language = array();
			$extra       = array();

			if ( 'home' === $type ) {
				foreach ( $slugs as $slug ) {
					$by_language[ $slug ] = 1;
				}
			} elseif ( 'archives' === $type ) {
				$archives   = self::archive_types();
				$translated = self::translated_post_types( $archives );
				foreach ( $slugs as $slug ) {
					$by_language[ $slug ] = count( $translated );
				}
				if ( '' === $language && count( $archives ) > count( $translated ) ) {
					$extra['no_language'] = count( $archives ) - count( $translated );
				}
				$extra['post_types'] = $archives;
			} elseif ( in_array( $type, array( 'categories', 'tags', 'custom_taxonomies' ), true ) ) {
				$taxonomies = self::taxonomies_for( $type );
				foreach ( $slugs as $slug ) {
					$by_language[ $slug ] = self::count_terms( self::translated_taxonomies( $taxonomies ), $slug );
				}
				$untranslated = array_values( array_diff( $taxonomies, self::translated_taxonomies( $taxonomies ) ) );
				if ( '' === $language && $untranslated ) {
					$extra['no_language'] = self::count_terms( $untranslated, '' );
				}
				$extra['taxonomies'] = $taxonomies;
			} else {
				$post_types = self::post_types_for( $type );
				foreach ( $slugs as $slug ) {
					$by_language[ $slug ] = self::count_posts( self::translated_post_types( $post_types ), $slug );
				}
				$untranslated = array_values( array_diff( $post_types, self::translated_post_types( $post_types ) ) );
				if ( '' === $language && $untranslated ) {
					$extra['no_language'] = self::count_posts( $untranslated, '' );
				}
				$extra['post_types'] = $post_types;
			}

			$rows[] = array_merge(
				array(
					'type'        => $type,
					'total'       => array_sum( $by_language ) + (int) ( $extra['no_language'] ?? 0 ),
					'by_language' => $by_language,
				),
				$extra
			);
		}

		return $rows;
	}

	/**
	 * Viewable post types behind a URL type.
	 *
	 * @since 1.1.0
	 * @param string $type pages, posts or custom_post_types.
	 * @return string[]
	 */
	private static function post_types_for( $type ) {
		if ( 'pages' === $type ) {
			return array( 'page' );
		}

		if ( 'posts' === $type ) {
			return array( 'post' );
		}

		$types = array();
		foreach ( get_post_types( array(), 'objects' ) as $object ) {
			if ( is_post_type_viewable( $object ) && ! in_array( $object->name, array_merge( array( 'post', 'page' ), self::excluded_post_types() ), true ) ) {
				$types[] = (string) $object->name;
			}
		}

		return $types;
	}

	/**
	 * Viewable taxonomies behind a URL type.
	 *
	 * @since 1.1.0
	 * @param string $type categories, tags or custom_taxonomies.
	 * @return string[]
	 */
	private static function taxonomies_for( $type ) {
		if ( 'categories' === $type ) {
			return array( 'category' );
		}

		if ( 'tags' === $type ) {
			return array( 'post_tag' );
		}

		$taxonomies = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $object ) {
			if ( is_taxonomy_viewable( $object ) && ! in_array( $object->name, array_merge( array( 'category', 'post_tag' ), self::EXCLUDED_TAXONOMIES ), true ) ) {
				$taxonomies[] = (string) $object->name;
			}
		}

		return $taxonomies;
	}

	/**
	 * Post types left out of every listing.
	 *
	 * @since 1.1.0
	 * @return string[]
	 */
	private static function excluded_post_types() {
		/**
		 * Filters the viewable post types list-urls leaves out.
		 *
		 * @since 1.1.0
		 * @param string[] $post_types Post type names.
		 */
		return array_map( 'strval', (array) apply_filters( 'dupcap_list_urls_excluded_post_types', self::EXCLUDED_POST_TYPES ) );
	}

	/**
	 * The post types among these that Polylang translates.
	 *
	 * @since 1.1.0
	 * @param string[] $post_types Post type names.
	 * @return string[]
	 */
	private static function translated_post_types( array $post_types ) {
		return array_values( array_filter( $post_types, 'pll_is_translated_post_type' ) );
	}

	/**
	 * The taxonomies among these that Polylang translates.
	 *
	 * @since 1.1.0
	 * @param string[] $taxonomies Taxonomy names.
	 * @return string[]
	 */
	private static function translated_taxonomies( array $taxonomies ) {
		return array_values( array_filter( $taxonomies, 'pll_is_translated_taxonomy' ) );
	}

	/**
	 * Post types with a public archive page.
	 *
	 * @since 1.1.0
	 * @return string[]
	 */
	private static function archive_types() {
		$types = array();
		foreach ( get_post_types( array( 'has_archive' => true ), 'objects' ) as $object ) {
			if ( is_post_type_viewable( $object ) && ! in_array( $object->name, self::excluded_post_types(), true ) ) {
				$types[] = (string) $object->name;
			}
		}

		return $types;
	}

	/**
	 * Published posts of these types, in one language or ignoring language.
	 *
	 * @since 1.1.0
	 * @param string[] $post_types Post type names.
	 * @param string   $language   Language slug, or '' for no language filter.
	 * @param int      $page       Page number.
	 * @param int      $per_page   Page size.
	 * @return \WP_Query
	 */
	private static function query_posts( array $post_types, $language, $page, $per_page ) {
		return new \WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'lang'                   => $language,
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'orderby'                => array(
					'post_type' => 'ASC',
					'ID'        => 'ASC',
				),
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => false,
				'update_post_term_cache' => false,
			)
		);
	}

	/**
	 * Number of published posts of these types in one language.
	 *
	 * @since 1.1.0
	 * @param string[] $post_types Post type names.
	 * @param string   $language   Language slug, or '' for no language filter.
	 * @return int
	 */
	private static function count_posts( array $post_types, $language ) {
		if ( empty( $post_types ) ) {
			return 0;
		}

		return (int) self::query_posts( $post_types, $language, 1, 1 )->found_posts;
	}

	/**
	 * Number of terms of these taxonomies in one language.
	 *
	 * @since 1.1.0
	 * @param string[] $taxonomies Taxonomy names.
	 * @param string   $language   Language slug, or '' for no language filter.
	 * @return int
	 */
	private static function count_terms( array $taxonomies, $language ) {
		if ( empty( $taxonomies ) ) {
			return 0;
		}

		$count = wp_count_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => false,
				'lang'       => $language,
			)
		);

		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * One page of post URLs.
	 *
	 * With a language filter, post types Polylang does not translate are left
	 * out: they have no language to match.
	 *
	 * @since 1.1.0
	 * @param string[]                             $post_types Post type names.
	 * @param array<string, array<string, string>> $languages  Languages by slug.
	 * @param string                               $language   Language filter, or ''.
	 * @param int                                  $page       Page number.
	 * @param int                                  $per_page   Page size.
	 * @return array{total: int, items: array<int, array<string, mixed>>}
	 */
	private static function post_items( array $post_types, array $languages, $language, $page, $per_page ) {
		if ( '' !== $language ) {
			$post_types = self::translated_post_types( $post_types );
		}

		if ( empty( $post_types ) ) {
			return array(
				'total' => 0,
				'items' => array(),
			);
		}

		$query = self::query_posts( $post_types, $language, $page, $per_page );
		$items = array();

		foreach ( $query->posts as $post_id ) {
			$items[] = self::post_item( (int) $post_id, $languages );
		}

		// WP_Query reports no rows at all for a page past the end.
		$total = empty( $items ) && $page > 1 ? self::count_posts( $post_types, $language ) : (int) $query->found_posts;

		return array(
			'total' => $total,
			'items' => $items,
		);
	}

	/**
	 * One published post as a URL to check.
	 *
	 * @since 1.1.0
	 * @param int                                  $post_id   Post ID.
	 * @param array<string, array<string, string>> $languages Languages by slug.
	 * @return array<string, mixed>
	 */
	private static function post_item( $post_id, array $languages ) {
		$post       = get_post( $post_id );
		$translated = pll_is_translated_post_type( (string) $post->post_type );
		$slug       = $translated ? (string) pll_get_post_language( $post_id ) : '';

		$item = array(
			'url'                => (string) get_permalink( $post ),
			'object'             => 'post',
			'id'                 => $post_id,
			'post_type'          => (string) $post->post_type,
			'title'              => get_the_title( $post ),
			'language'           => '' !== $slug ? $slug : null,
			'expected_html_lang' => isset( $languages[ $slug ] ) ? $languages[ $slug ]['expected_html_lang'] : null,
			'modified_gmt'       => gmdate( 'c', (int) strtotime( $post->post_modified_gmt . ' UTC' ) ),
		);

		if ( ! $translated ) {
			$item['note'] = __( 'This post type is not translated by Polylang: the page exists in one version only.', 'duplicate-content-addon-for-polylang' );
			return $item;
		}

		$item = array_merge( $item, self::translation_links( (array) pll_get_post_translations( $post_id ), $post_id, 'post', $languages ) );

		if ( '' === $slug ) {
			$item['note'] = __( 'This post has no language, so Polylang cannot serve it in the right language. fix-issue assign_default_language fixes it.', 'duplicate-content-addon-for-polylang' );
		}

		if ( (int) get_option( 'page_for_posts' ) === $post_id ) {
			$item['is_posts_page'] = true;
		}

		if ( post_password_required( $post ) ) {
			$item['password_protected'] = true;
		}

		if ( Content_Service::is_elementor( $post_id ) ) {
			$item['elementor'] = true;
		}

		return $item;
	}

	/**
	 * One page of term archive URLs.
	 *
	 * @since 1.1.0
	 * @param string[]                             $taxonomies Taxonomy names.
	 * @param array<string, array<string, string>> $languages  Languages by slug.
	 * @param string                               $language   Language filter, or ''.
	 * @param int                                  $page       Page number.
	 * @param int                                  $per_page   Page size.
	 * @return array{total: int, items: array<int, array<string, mixed>>}
	 */
	private static function term_items( array $taxonomies, array $languages, $language, $page, $per_page ) {
		if ( '' !== $language ) {
			$taxonomies = self::translated_taxonomies( $taxonomies );
		}

		if ( empty( $taxonomies ) ) {
			return array(
				'total' => 0,
				'items' => array(),
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => false,
				'lang'       => $language,
				'orderby'    => 'term_id',
				'number'     => $per_page,
				'offset'     => ( $page - 1 ) * $per_page,
			)
		);

		$items = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$items[] = self::term_item( $term, $languages );
			}
		}

		return array(
			'total' => self::count_terms( $taxonomies, $language ),
			'items' => $items,
		);
	}

	/**
	 * One term archive as a URL to check.
	 *
	 * @since 1.1.0
	 * @param \WP_Term                             $term      Term.
	 * @param array<string, array<string, string>> $languages Languages by slug.
	 * @return array<string, mixed>
	 */
	private static function term_item( \WP_Term $term, array $languages ) {
		$translated = pll_is_translated_taxonomy( $term->taxonomy );
		$slug       = $translated ? (string) pll_get_term_language( $term->term_id ) : '';
		$link       = get_term_link( $term );

		$item = array(
			'url'                => is_string( $link ) ? $link : '',
			'object'             => 'term',
			'id'                 => (int) $term->term_id,
			'taxonomy'           => $term->taxonomy,
			'title'              => $term->name,
			'language'           => '' !== $slug ? $slug : null,
			'expected_html_lang' => isset( $languages[ $slug ] ) ? $languages[ $slug ]['expected_html_lang'] : null,
			'post_count'         => (int) $term->count,
		);

		if ( $translated ) {
			$item = array_merge( $item, self::translation_links( (array) pll_get_term_translations( $term->term_id ), (int) $term->term_id, 'term', $languages ) );
		}

		if ( 0 === (int) $term->count ) {
			$item['note'] = __( 'No published posts use this term: the archive page is expected to show "nothing found", and Polylang leaves empty archives out of the hreflang alternates of its translations. Neither is a routing error.', 'duplicate-content-addon-for-polylang' );
		} elseif ( '' === $slug && $translated ) {
			$item['note'] = __( 'This term has no language. fix-issue assign_default_language fixes it.', 'duplicate-content-addon-for-polylang' );
		}

		return $item;
	}

	/**
	 * Translation URLs of one post or term, and the languages it lacks.
	 *
	 * Only published translations have a public URL. A translation Polylang
	 * still links to but that no longer exists is reported, since it breaks
	 * the language switcher.
	 *
	 * @since 1.1.0
	 * @param array<string, int>                   $group     Translation group: slug => ID.
	 * @param int                                  $self_id   The object being described.
	 * @param string                               $kind      post or term.
	 * @param array<string, array<string, string>> $languages Languages by slug.
	 * @return array<string, mixed>
	 */
	private static function translation_links( array $group, $self_id, $kind, array $languages ) {
		$translations = array();
		$unpublished  = array();
		$stale        = array();

		foreach ( $group as $slug => $object_id ) {
			$object_id = (int) $object_id;
			if ( $object_id === $self_id ) {
				continue;
			}

			if ( 'post' === $kind ) {
				$object = get_post( $object_id );
				if ( ! $object ) {
					$stale[] = array(
						'language' => (string) $slug,
						'id'       => $object_id,
					);
				} elseif ( 'publish' === $object->post_status ) {
					$translations[ (string) $slug ] = (string) get_permalink( $object );
				} else {
					$unpublished[] = (string) $slug;
				}
				continue;
			}

			$term = get_term( $object_id );
			$link = $term instanceof \WP_Term ? get_term_link( $term ) : null;
			if ( is_string( $link ) ) {
				$translations[ (string) $slug ] = $link;
			} else {
				$stale[] = array(
					'language' => (string) $slug,
					'id'       => $object_id,
				);
			}
		}

		$present = array_map( 'strval', array_keys( $group ) );
		$missing = array_values( array_diff( array_keys( $languages ), $present ) );

		$links = array(
			'translations'      => (object) $translations,
			'missing_languages' => $missing,
		);

		if ( $unpublished ) {
			$links['unpublished_languages'] = $unpublished;
		}

		if ( $stale ) {
			$links['stale_translation_links'] = $stale;
			$links['stale_note']              = __( 'Polylang links these translations, but they no longer exist. The language switcher may point nowhere; relink or remove them in the editor.', 'duplicate-content-addon-for-polylang' );
		}

		return $links;
	}

	/**
	 * One home URL per language.
	 *
	 * @since 1.1.0
	 * @param array<string, array<string, string>> $languages Languages by slug.
	 * @param string                               $language  Language filter, or ''.
	 * @return array<int, array<string, mixed>>
	 */
	private static function home_items( array $languages, $language ) {
		$front_id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$homes    = array();

		foreach ( $languages as $slug => $lang ) {
			$homes[ $slug ] = $lang['home_url'];
		}

		$items = array();
		foreach ( $languages as $slug => $lang ) {
			if ( '' !== $language && $language !== $slug ) {
				continue;
			}

			$item = array(
				'url'                => $lang['home_url'],
				'object'             => 'home',
				'language'           => $slug,
				'expected_html_lang' => $lang['expected_html_lang'],
				'translations'       => (object) array_diff_key( $homes, array( $slug => true ) ),
			);

			if ( $front_id > 0 ) {
				$page_id               = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $front_id, $slug ) : 0;
				$item['shows']         = 'page';
				$item['front_page_id'] = $page_id > 0 ? $page_id : null;
				if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
					$item['note'] = __( 'The front page has no published translation in this language, so this home page falls back or shows the wrong content.', 'duplicate-content-addon-for-polylang' );
				}
			} else {
				$posts                     = self::count_posts( array( 'post' ), $slug );
				$item['shows']             = 'latest_posts';
				$item['posts_in_language'] = $posts;
				if ( 0 === $posts ) {
					$item['note'] = __( 'The home page lists the latest posts and none are in this language, so it is expected to be empty. That is a content problem, not a routing error.', 'duplicate-content-addon-for-polylang' );
				}
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Post type archive URLs per language.
	 *
	 * @since 1.1.0
	 * @param array<string, array<string, string>> $languages Languages by slug.
	 * @param string                               $language  Language filter, or ''.
	 * @return array<int, array<string, mixed>>
	 */
	private static function archive_items( array $languages, $language ) {
		$items = array();

		foreach ( self::archive_types() as $post_type ) {
			$link = get_post_type_archive_link( $post_type );
			if ( ! is_string( $link ) || '' === $link ) {
				continue;
			}

			$translated = pll_is_translated_post_type( $post_type );
			if ( ! $translated ) {
				if ( '' === $language ) {
					$items[] = array(
						'url'       => $link,
						'object'    => 'archive',
						'post_type' => $post_type,
						'language'  => null,
						'note'      => __( 'This post type is not translated by Polylang: its archive exists in one version only.', 'duplicate-content-addon-for-polylang' ),
					);
				}
				continue;
			}

			$urls = array();
			foreach ( array_keys( $languages ) as $slug ) {
				$urls[ $slug ] = (string) PLL()->links_model->switch_language_in_link( $link, PLL()->model->get_language( $slug ) );
			}

			foreach ( $languages as $slug => $lang ) {
				if ( '' !== $language && $language !== $slug ) {
					continue;
				}

				$items[] = array(
					'url'                => $urls[ $slug ],
					'object'             => 'archive',
					'post_type'          => $post_type,
					'language'           => $slug,
					'expected_html_lang' => $lang['expected_html_lang'],
					'translations'       => (object) array_diff_key( $urls, array( $slug => true ) ),
					'posts_in_language'  => self::count_posts( array( $post_type ), $slug ),
				);
			}
		}

		return $items;
	}

	/**
	 * One page of an in-memory list.
	 *
	 * @since 1.1.0
	 * @param array<int, array<string, mixed>> $items    All items.
	 * @param int                              $page     Page number.
	 * @param int                              $per_page Page size.
	 * @return array{total: int, items: array<int, array<string, mixed>>}
	 */
	private static function paginate( array $items, $page, $per_page ) {
		return array(
			'total' => count( $items ),
			'items' => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
		);
	}
}
