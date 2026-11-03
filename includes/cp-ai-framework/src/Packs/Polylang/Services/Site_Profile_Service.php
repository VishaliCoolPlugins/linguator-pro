<?php
/**
 * What the site is about, gathered for an AI client.
 *
 * @package AutoPoly
 * @since   1.11.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Services;

use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a profile of the site from its own content and settings.
 *
 * It reports evidence rather than a verdict: the site's name, the owner's own
 * description from AutoPoly's settings, the home page, pages such as About and
 * Services, the navigation, SEO descriptions and what the active plugins make
 * the site do. Guessing a label like "dental clinic" in PHP from keywords would
 * be wrong often enough to mislead, and would not work for non-English sites;
 * the AI reading the evidence can do that far better.
 *
 * Only public content is read: published, not password-protected, in the
 * default language. Every text is plain and length-capped.
 *
 * @since 1.11.0
 */
final class Site_Profile_Service {

	/**
	 * Option holding the owner's own context for AutoPoly's translator.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const OWNER_CONTEXT_OPTION = 'atfp_context_aware';

	/**
	 * Slug and title fragments that mark a page describing the site, with a
	 * weight so the most telling pages are chosen first.
	 *
	 * @since 1.11.0
	 * @var array<string, int>
	 */
	const KEY_PAGE_TERMS = array(
		'about'      => 10,
		'who-we-are' => 9,
		'services'   => 9,
		'what-we-do' => 8,
		'our-story'  => 8,
		'company'    => 6,
		'solutions'  => 6,
		'mission'    => 5,
		'products'   => 5,
		'team'       => 5,
		'pricing'    => 4,
		'contact'    => 4,
		'faq'        => 3,
	);

	/**
	 * Plugins whose presence says what the site does.
	 *
	 * @since 1.11.0
	 * @var array<string, string>
	 */
	const FEATURE_PLUGINS = array(
		'woocommerce'                                => 'Online store (WooCommerce)',
		'easy-digital-downloads'                     => 'Digital product store (Easy Digital Downloads)',
		'sfwd-lms'                                   => 'Online courses (LearnDash)',
		'lifterlms'                                  => 'Online courses (LifterLMS)',
		'tutor'                                      => 'Online courses (Tutor LMS)',
		'the-events-calendar'                        => 'Events calendar (The Events Calendar)',
		'ameliabooking'                              => 'Appointment booking (Amelia)',
		'bookly-responsive-appointment-booking-tool' => 'Appointment booking (Bookly)',
		'memberpress'                                => 'Paid memberships (MemberPress)',
		'paid-memberships-pro'                       => 'Paid memberships (Paid Memberships Pro)',
		'give'                                       => 'Donations (GiveWP)',
		'wp-job-manager'                             => 'Job listings (WP Job Manager)',
		'bbpress'                                    => 'Discussion forum (bbPress)',
		'buddypress'                                 => 'Community network (BuddyPress)',
	);

	/**
	 * Length caps, in characters.
	 *
	 * @since 1.11.0
	 * @var int
	 */
	const OWNER_CONTEXT_LIMIT = 2000;
	const PAGE_TEXT_LIMIT     = 600;
	const KEY_PAGE_LIMIT      = 6;

	/**
	 * The full site profile.
	 *
	 * @since 1.11.0
	 * @return array<string, mixed> Profile.
	 */
	public static function build() {
		$language = self::default_language();

		$profile = array(
			'identity' => array(
				'name'             => self::plain( get_bloginfo( 'name' ), 200 ),
				'tagline'          => self::plain( get_bloginfo( 'description' ), 300 ),
				'home_url'         => home_url( '/' ),
				'default_language' => $language,
				'locale'           => get_locale(),
			),
		);

		$owner_context = self::owner_context();

		if ( '' !== $owner_context ) {
			$profile['owner_context'] = $owner_context;
		}

		$front_page            = self::front_page( $language );
		$profile['front_page'] = $front_page;

		$exclude   = isset( $front_page['id'] ) ? array( (int) $front_page['id'] ) : array();
		$key_pages = self::key_pages( $language, $exclude );

		if ( array() !== $key_pages ) {
			$profile['key_pages'] = $key_pages;
		}

		$seo = self::seo_descriptions( isset( $front_page['id'] ) ? (int) $front_page['id'] : 0 );

		if ( array() !== $seo ) {
			$profile['seo_descriptions'] = $seo;
		}

		$navigation = self::navigation();

		if ( array() !== $navigation ) {
			$profile['navigation'] = $navigation;
		}

		$profile['content'] = self::content_overview( $language );

		$features = self::features();

		if ( array() !== $features ) {
			$profile['features'] = $features;
		}

		$profile['notice'] = __( 'Evidence of what this site is, for context: work out its purpose, industry, audience and tone from it, and keep translations consistent with that. "owner_context" is written by the site owner in AutoPoly\'s settings and AutoPoly\'s own translator follows it, so it outranks anything inferred. Only public, published content in the default language is included, and every text is shortened.', 'autopoly-ai-translation-for-polylang-pro' );

		return $profile;
	}

	/**
	 * The owner's context from AutoPoly's settings.
	 *
	 * @since 1.11.0
	 * @return string Plain text, or ''.
	 */
	private static function owner_context() {
		$value = get_option( self::OWNER_CONTEXT_OPTION, '' );

		if ( ! is_string( $value ) ) {
			return '';
		}

		// Saved through sanitize_textarea_field(); keep its line breaks.
		$value = trim( wp_strip_all_tags( $value, false ) );

		return self::truncate( $value, self::OWNER_CONTEXT_LIMIT );
	}

	/**
	 * The home page.
	 *
	 * @since 1.11.0
	 * @param string $language Default language slug.
	 * @return array<string, mixed> Description.
	 */
	private static function front_page( $language ) {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return array( 'type' => 'latest_posts' );
		}

		$id = (int) get_option( 'page_on_front' );

		// The front page setting may point at any translation of it.
		if ( $id > 0 && '' !== $language && function_exists( 'pll_get_post' ) ) {
			$translated = (int) pll_get_post( $id, $language );
			$id         = $translated > 0 ? $translated : $id;
		}

		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! self::is_public( $post ) ) {
			return array( 'type' => 'static_page' );
		}

		return array(
			'type'  => 'static_page',
			'id'    => (int) $post->ID,
			'title' => self::plain( $post->post_title, 200 ),
			'url'   => (string) get_permalink( $post ),
			'text'  => self::post_text( $post ),
		);
	}

	/**
	 * Pages that describe the site, such as About and Services.
	 *
	 * @since 1.11.0
	 * @param string $language Default language slug.
	 * @param int[]  $exclude  Page IDs already covered.
	 * @return array<int, array<string, mixed>> Pages, most telling first.
	 */
	private static function key_pages( $language, array $exclude ) {
		$args = array(
			'post_type'              => 'page',
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => 100,
			'orderby'                => array(
				'menu_order' => 'ASC',
				'ID'         => 'ASC',
			),
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( '' !== $language ) {
			$args['lang'] = $language;
		}

		$query  = new WP_Query( $args );
		$scored = array();

		foreach ( (array) $query->posts as $post ) {
			if ( ! $post instanceof WP_Post || in_array( (int) $post->ID, $exclude, true ) ) {
				continue;
			}

			$score = self::key_page_score( $post );

			if ( $score > 0 ) {
				$scored[] = array(
					'score' => $score,
					'post'  => $post,
				);
			}
		}

		// Stable: equal scores keep menu order.
		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		$pages = array();

		foreach ( array_slice( $scored, 0, self::KEY_PAGE_LIMIT ) as $entry ) {
			$post    = $entry['post'];
			$pages[] = array(
				'id'    => (int) $post->ID,
				'title' => self::plain( $post->post_title, 200 ),
				'url'   => (string) get_permalink( $post ),
				'text'  => self::post_text( $post ),
			);
		}

		return $pages;
	}

	/**
	 * How strongly a page's slug or title marks it as describing the site.
	 *
	 * @since 1.11.0
	 * @param WP_Post $post Page.
	 * @return int Score, 0 when it does not.
	 */
	private static function key_page_score( WP_Post $post ) {
		$haystack = '-' . strtolower( (string) $post->post_name ) . '-' . sanitize_title( (string) $post->post_title ) . '-';
		$score    = 0;

		foreach ( self::KEY_PAGE_TERMS as $term => $weight ) {
			if ( false !== strpos( $haystack, '-' . $term . '-' ) || false !== strpos( $haystack, '-' . $term ) ) {
				$score = max( $score, $weight );
			}
		}

		return $score;
	}

	/**
	 * Site-level and home-page SEO descriptions from Yoast SEO and Rank Math.
	 *
	 * @since 1.11.0
	 * @param int $front_page_id Home page ID, 0 when none.
	 * @return array<int, array<string, string>> Descriptions with their source.
	 */
	private static function seo_descriptions( $front_page_id ) {
		$found = array();

		if ( $front_page_id > 0 ) {
			$found['yoast_home_page']     = get_post_meta( $front_page_id, '_yoast_wpseo_metadesc', true );
			$found['rank_math_home_page'] = get_post_meta( $front_page_id, 'rank_math_description', true );
		}

		$yoast     = get_option( 'wpseo_titles' );
		$rank_math = get_option( 'rank-math-options-titles' );

		$found['yoast_site']     = is_array( $yoast ) && isset( $yoast['metadesc-home-wpseo'] ) ? $yoast['metadesc-home-wpseo'] : '';
		$found['rank_math_site'] = is_array( $rank_math ) && isset( $rank_math['homepage_description'] ) ? $rank_math['homepage_description'] : '';

		$descriptions = array();
		$seen         = array();

		foreach ( $found as $source => $text ) {
			if ( ! is_string( $text ) ) {
				continue;
			}

			// Template variables such as %%sitename%% say nothing on their own.
			$text = self::plain( (string) preg_replace( '/%%[a-z0-9_\-]+%%|%[a-z0-9_\-]+%/i', ' ', $text ), 300 );

			if ( '' === $text || isset( $seen[ $text ] ) ) {
				continue;
			}

			$seen[ $text ]  = true;
			$descriptions[] = array(
				'source' => $source,
				'text'   => $text,
			);
		}

		return $descriptions;
	}

	/**
	 * Top-level labels of the main menu.
	 *
	 * @since 1.11.0
	 * @return array<string, mixed> Location and item labels, or empty.
	 */
	private static function navigation() {
		$locations = get_nav_menu_locations();

		if ( ! is_array( $locations ) || array() === $locations ) {
			return array();
		}

		$chosen = '';

		foreach ( $locations as $location => $menu_id ) {
			// Polylang keeps other languages' menus under "location___lang".
			if ( (int) $menu_id <= 0 || false !== strpos( (string) $location, '___' ) ) {
				continue;
			}

			if ( '' === $chosen ) {
				$chosen = (string) $location;
			}

			if ( preg_match( '/primary|main|header|menu-1/i', (string) $location ) ) {
				$chosen = (string) $location;
				break;
			}
		}

		if ( '' === $chosen ) {
			return array();
		}

		$items = wp_get_nav_menu_items( (int) $locations[ $chosen ] );

		if ( ! is_array( $items ) ) {
			return array();
		}

		$labels = array();

		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! empty( $item->menu_item_parent ) ) {
				continue;
			}

			$label = self::plain( isset( $item->title ) ? $item->title : '', 60 );

			if ( '' !== $label ) {
				$labels[] = $label;
			}

			if ( count( $labels ) >= 15 ) {
				break;
			}
		}

		return array() === $labels ? array() : array(
			'location' => $chosen,
			'items'    => $labels,
		);
	}

	/**
	 * What kind of content the site publishes.
	 *
	 * @since 1.11.0
	 * @param string $language Default language slug.
	 * @return array<string, mixed> Overview.
	 */
	private static function content_overview( $language ) {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
			if ( 'attachment' === $name ) {
				continue;
			}

			$counts    = wp_count_posts( $name );
			$published = is_object( $counts ) && isset( $counts->publish ) ? (int) $counts->publish : 0;

			if ( $published > 0 ) {
				$types[] = array(
					'post_type' => (string) $name,
					'label'     => isset( $object->labels->name ) ? (string) $object->labels->name : (string) $name,
					'published' => $published,
				);
			}
		}

		usort(
			$types,
			static function ( $a, $b ) {
				return $b['published'] <=> $a['published'];
			}
		);

		$overview = array( 'published_counts_all_languages' => array_slice( $types, 0, 8 ) );

		$categories = self::top_terms( 'category', $language );

		if ( array() !== $categories ) {
			$overview['top_categories'] = $categories;
		}

		if ( taxonomy_exists( 'product_cat' ) ) {
			$product_categories = self::top_terms( 'product_cat', $language );

			if ( array() !== $product_categories ) {
				$overview['top_product_categories'] = $product_categories;
			}
		}

		$recent = self::recent_titles( $language );

		if ( array() !== $recent ) {
			$overview['recent_posts'] = $recent;
		}

		return $overview;
	}

	/**
	 * Most-used term names in a taxonomy.
	 *
	 * @since 1.11.0
	 * @param string $taxonomy Taxonomy.
	 * @param string $language Default language slug.
	 * @return string[] Names.
	 */
	private static function top_terms( $taxonomy, $language ) {
		$args = array(
			'taxonomy'   => $taxonomy,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => 10,
			'hide_empty' => true,
		);

		if ( '' !== $language ) {
			$args['lang'] = $language;
		}

		$terms = get_terms( $args );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$names = array();

		foreach ( $terms as $term ) {
			$name = is_object( $term ) && isset( $term->name ) ? self::plain( $term->name, 80 ) : '';

			// "Uncategorized" is WordPress's default, not a topic.
			if ( '' !== $name && ! ( 'category' === $taxonomy && (int) get_option( 'default_category' ) === (int) $term->term_id ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * Titles of the latest published posts.
	 *
	 * @since 1.11.0
	 * @param string $language Default language slug.
	 * @return string[] Titles.
	 */
	private static function recent_titles( $language ) {
		$args = array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => 5,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( '' !== $language ) {
			$args['lang'] = $language;
		}

		$query  = new WP_Query( $args );
		$titles = array();

		foreach ( (array) $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$title = self::plain( $post->post_title, 150 );

				if ( '' !== $title ) {
					$titles[] = $title;
				}
			}
		}

		return $titles;
	}

	/**
	 * What active plugins make the site do.
	 *
	 * @since 1.11.0
	 * @return string[] Feature descriptions.
	 */
	private static function features() {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$directories = array();

		foreach ( $active as $plugin ) {
			$directories[ strtok( (string) $plugin, '/' ) ] = true;
		}

		$features = array();

		foreach ( self::FEATURE_PLUGINS as $directory => $feature ) {
			if ( isset( $directories[ $directory ] ) ) {
				$features[] = $feature;
			}
		}

		return $features;
	}

	/**
	 * A post's own text: its excerpt if it has one, else its content.
	 *
	 * @since 1.11.0
	 * @param WP_Post $post Post.
	 * @return string Plain text.
	 */
	private static function post_text( WP_Post $post ) {
		$source = '' !== trim( (string) $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;

		return self::plain( $source, self::PAGE_TEXT_LIMIT );
	}

	/**
	 * Whether a post may be described: published and not password-protected.
	 *
	 * @since 1.11.0
	 * @param mixed $post Candidate.
	 * @return bool True when public.
	 */
	private static function is_public( $post ) {
		return $post instanceof WP_Post && 'publish' === $post->post_status && '' === (string) $post->post_password;
	}

	/**
	 * The default language slug, '' without Polylang.
	 *
	 * @since 1.11.0
	 * @return string Slug.
	 */
	private static function default_language() {
		return function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
	}

	/**
	 * Markup, shortcodes and entities reduced to one line of plain text.
	 *
	 * @since 1.11.0
	 * @param mixed $text  Text.
	 * @param int   $limit Maximum characters.
	 * @return string Plain text.
	 */
	private static function plain( $text, $limit ) {
		if ( ! is_scalar( $text ) ) {
			return '';
		}

		$text = (string) $text;

		if ( function_exists( 'strip_shortcodes' ) ) {
			$text = strip_shortcodes( $text );
		}

		// Shortcodes of inactive plugins survive strip_shortcodes().
		$text = (string) preg_replace( '/\[\/?[A-Za-z][\w\-]*[^\]]*\]/', ' ', $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		return self::truncate( $text, $limit );
	}

	/**
	 * Shortens text to a limit, at a word boundary where one is close.
	 *
	 * @since 1.11.0
	 * @param string $text  Text.
	 * @param int    $limit Maximum characters.
	 * @return string Text.
	 */
	private static function truncate( $text, $limit ) {
		if ( mb_strlen( $text, 'UTF-8' ) <= $limit ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $limit, 'UTF-8' );
		$space = strrpos( $cut, ' ' );

		if ( false !== $space && $space > strlen( $cut ) * 0.6 ) {
			$cut = substr( $cut, 0, $space );
		}

		return rtrim( $cut, " ,;:-\n" ) . '…';
	}
}
