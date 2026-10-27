<?php
/**
 * Block theme navigation checks.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Modules\Scan\Abstract_Check;
use LinguatorPro\Modules\Scan\Link_Tools;
use LinguatorPro\Modules\Scan\Result;

/**
 * Checks the header navigation of a block theme for every language.
 *
 * Block themes keep navigation in template parts and navigation entities, not in menu locations, so the classic
 * menu checks do not apply. This finds a language that reuses another language's header, a translation that is
 * missing, and links that are dead or lead to another language.
 *
 * @since 2.3.0
 */
class Navigation extends Abstract_Check {

	use Link_Tools;

	const NAV_BLOCK   = 'core/navigation';
	const LINK_BLOCKS = array( 'core/navigation-link', 'core/navigation-submenu' );

	/**
	 * @var string
	 */
	protected $group = 'Navigation';

	/**
	 * @var string
	 */
	protected $section = 'site';

	/**
	 * Runs the check.
	 *
	 * @since 2.3.0
	 *
	 * @return Result[]
	 */
	public function run(): array {
		$languages = $this->active_languages();
		if ( count( $languages ) < 2 || ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return array();
		}

		$default     = $this->default_lang();
		$site_editor = admin_url( 'site-editor.php' );
		$data        = $this->scan( $languages, $default );

		$language_rows = array();
		foreach ( $data['language_status'] as $slug => $status ) {
			$language_rows[] = array(
				'label'        => $status['label'],
				'message'      => $status['message'],
				'language'     => $slug,
				'action_url'   => $site_editor,
				'action_label' => __( 'Open Site Editor', 'linguator-pro' ),
			);
		}

		$link_rows = array();
		foreach ( $data['wrong_language_links'] as $index => $issue ) {
			$link_rows[] = array(
				'label'        => $issue['label'],
				'message'      => $issue['message'],
				'context'      => $issue['context'],
				'language'     => $issue['language'],
				'action_url'   => $site_editor,
				'action_label' => __( 'Review Navigation', 'linguator-pro' ),
				'ignore_key'   => 'nav_link:' . $issue['language'] . ':' . md5( $issue['code'] . '|' . $issue['url'] . '|' . $issue['context'] ),
			);
		}

		$result = $this->result_for( $data, $language_rows, $link_rows, $site_editor );

		return $result && $this->wants( $result->id ) ? array( $result ) : array();
	}

	/**
	 * Builds the single navigation result, most serious problem first.
	 *
	 * @since 2.3.0
	 *
	 * @param array  $data          Scan data.
	 * @param array  $language_rows Per-language status rows.
	 * @param array  $link_rows     Link issue rows.
	 * @param string $site_editor   Site Editor URL.
	 * @return Result|null
	 */
	private function result_for( array $data, array $language_rows, array $link_rows, string $site_editor ): ?Result {
		if ( $link_rows ) {
			$has_dead = false;
			foreach ( $data['wrong_language_links'] as $issue ) {
				if ( 'dead_link' === $issue['code'] ) {
					$has_dead = true;
				}
			}
			$count = count( $link_rows );

			return $this->result(
				'nav_wrong_language_links',
				Result::WARNING,
				1 === $count
					? __( '1 navigation link needs review', 'linguator-pro' )
					: sprintf(
						/* translators: %d: number of links */
						__( '%d navigation links need review', 'linguator-pro' ),
						$count
					),
				$has_dead
					? __( 'Some navigation links point to missing pages or content in another language.', 'linguator-pro' )
					: __( 'Some navigation links point to content in another language.', 'linguator-pro' )
			)
				->with_fix( $site_editor, __( 'Review Navigation', 'linguator-pro' ) )
				->with_details( array_merge( $language_rows, $link_rows ) );
		}

		if ( $data['uses_other_language'] ) {
			$count = count( $data['uses_other_language'] );

			return $this->result(
				'nav_uses_other_language',
				Result::WARNING,
				1 === $count
					? __( '1 language uses another language\'s navigation', 'linguator-pro' )
					: sprintf(
						/* translators: %d: number of languages */
						__( '%d languages use another language\'s navigation', 'linguator-pro' ),
						$count
					),
				__( 'Some language versions of the header still point at the default-language navigation menu. Visitors can see default-language menu labels on translated pages.', 'linguator-pro' )
			)
				->with_fix( $site_editor, __( 'Review Site Editor', 'linguator-pro' ) )
				->with_details( $language_rows );
		}

		if ( $data['missing_translations'] ) {
			$count = count( $data['missing_translations'] );

			return $this->result(
				'nav_missing_translation',
				Result::WARNING,
				1 === $count
					? __( '1 navigation translation needs review', 'linguator-pro' )
					: sprintf(
						/* translators: %d: number of languages */
						__( '%d navigation translations need review', 'linguator-pro' ),
						$count
					),
				__( 'A translated header or navigation was not found for every language. Visitors may see navigation from another language if no language-specific version is configured.', 'linguator-pro' )
			)
				->with_fix( $site_editor, __( 'Review Site Editor', 'linguator-pro' ) )
				->with_details( $language_rows );
		}

		if ( $data['found_any_navigation'] ) {
			return $this->result(
				'nav_fse_ok',
				Result::PASS,
				__( 'Navigation looks good', 'linguator-pro' ),
				__( 'Block theme detected. Language-specific header navigation was found and checked for active languages.', 'linguator-pro' )
			)->with_details( $language_rows );
		}

		return $this->result(
			'nav_fse_review',
			Result::INFO,
			__( 'Block navigation needs review', 'linguator-pro' ),
			__( 'Block theme detected. This site uses block-based navigation instead of traditional menu locations. Classic menu checks do not apply here. Review the site Header and Navigation in the Site Editor.', 'linguator-pro' )
		)
			->with_fix( $site_editor, __( 'Open Site Editor', 'linguator-pro' ) )
			->with_details( $language_rows );
	}

	/**
	 * Reads navigation entities and header template parts, and judges each language.
	 *
	 * @since 2.3.0
	 *
	 * @param object[] $languages Active languages.
	 * @param string   $default   Default language slug.
	 * @return array
	 */
	private function scan( array $languages, string $default ): array {
		$slugs            = array_map(
			static function ( $lang ) {
				return $lang->slug;
			},
			$languages
		);
		$navigation_posts = $this->published( 'wp_navigation' );
		$parts            = $this->template_parts();

		$found_any   = ! empty( $navigation_posts );
		$with_nav    = array();
		$header_part = array();

		foreach ( $parts as $part ) {
			if ( ! $this->find_navigation_blocks( parse_blocks( (string) $part->post_content ) ) ) {
				continue;
			}
			$found_any                    = true;
			$with_nav[ (int) $part->ID ]  = true;
			$header_part[]                = $part;
		}

		$headers             = $this->headers( $header_part );
		$status              = array();
		$missing             = array();
		$uses_other_language = array();

		foreach ( $slugs as $slug ) {
			$label     = $this->language_name( $slug );
			$effective = $this->effective_headers( $slug, $default, $header_part, $with_nav );
			$ref_issue = $this->ref_issues( $effective, $slug, $languages );
			$name      = sprintf(
				/* translators: %s: language name */
				__( '%s navigation', 'linguator-pro' ),
				$label
			);

			if ( $ref_issue ) {
				$uses_other_language = array_merge( $uses_other_language, $ref_issue );
				$status[ $slug ]     = array(
					'label'   => $name,
					'message' => sprintf(
						/* translators: %s: language name */
						__( 'Uses %s navigation', 'linguator-pro' ),
						$this->language_name( $ref_issue[0]['source_language'] )
					),
				);
				continue;
			}

			if ( empty( $effective ) ) {
				if ( $slug === $default || empty( $headers ) ) {
					$status[ $slug ] = array(
						'label'   => $name,
						'message' => __( 'No header navigation found for this language', 'linguator-pro' ),
					);
					continue;
				}
				$missing[]       = $slug;
				$status[ $slug ] = array(
					'label'   => $name,
					'message' => __( 'Header or navigation translation missing', 'linguator-pro' ),
				);
				continue;
			}

			if ( $this->missing_entity( $slug, $default, $navigation_posts ) ) {
				$missing[]       = $slug;
				$status[ $slug ] = array(
					'label'   => $name,
					'message' => __( 'Navigation entity translation missing', 'linguator-pro' ),
				);
				continue;
			}

			$status[ $slug ] = array(
				'label'   => $name,
				'message' => __( 'Language-specific navigation found', 'linguator-pro' ),
			);
		}

		return array(
			'found_any_navigation' => $found_any,
			'language_status'      => $status,
			'missing_translations' => array_values( array_unique( $missing ) ),
			'uses_other_language'  => $uses_other_language,
			'wrong_language_links' => $this->wrong_links( $slugs, $default, $header_part, $with_nav, $navigation_posts, $languages ),
		);
	}

	/**
	 * Links in each language's navigation that are dead or lead to another language.
	 *
	 * @since 2.3.0
	 *
	 * @param string[]  $slugs            Language slugs.
	 * @param string    $default          Default language slug.
	 * @param \WP_Post[] $header_part      Template parts with navigation.
	 * @param array     $with_nav         Part IDs containing navigation.
	 * @param \WP_Post[] $navigation_posts Navigation entities.
	 * @param object[]  $languages        Languages.
	 * @return array[]
	 */
	private function wrong_links( array $slugs, string $default, array $header_part, array $with_nav, array $navigation_posts, array $languages ): array {
		$issues = array();

		foreach ( $slugs as $slug ) {
			$instances = array();

			foreach ( $this->effective_headers( $slug, $default, $header_part, $with_nav ) as $part ) {
				foreach ( $this->find_navigation_blocks( parse_blocks( (string) $part->post_content ) ) as $block ) {
					$instances[] = array(
						'title' => $this->part_label( $part ),
						'links' => $this->collect_links( array( $block ) ),
					);
				}
			}
			foreach ( $navigation_posts as $post ) {
				if ( $this->language_of( (int) $post->ID ) === $slug ) {
					$instances[] = array(
						'title' => get_the_title( $post ),
						'links' => $this->collect_links( parse_blocks( (string) $post->post_content ) ),
					);
				}
			}

			foreach ( $instances as $instance ) {
				foreach ( $instance['links'] as $link ) {
					$issue = $this->classify( $link, $slug, $languages );
					if ( $issue ) {
						$issues[] = $issue + array(
							'context'  => '' !== $instance['title'] ? $instance['title'] : __( 'Navigation', 'linguator-pro' ),
							'language' => $slug,
						);
					}
				}
			}
		}

		return $issues;
	}

	/**
	 * Judges one navigation link.
	 *
	 * @since 2.3.0
	 *
	 * @param array    $link     Link: label, url, id, kind.
	 * @param string   $expected Language the navigation belongs to.
	 * @param object[] $languages Languages.
	 * @return array|null
	 */
	private function classify( array $link, string $expected, array $languages ): ?array {
		$url     = $link['url'];
		$post_id = $link['id'];
		$label   = '' !== $link['label'] ? $link['label'] : $this->readable( $url );

		if ( $post_id <= 0 && '' !== $url ) {
			if ( ! $this->is_internal( $url ) || $this->is_asset( $url ) ) {
				return null;
			}
			$dead = $this->dead_target( $url );
			if ( $dead ) {
				return array(
					'code'    => 'dead_link',
					'label'   => $label,
					'url'     => $url,
					'message' => $dead['message'],
				);
			}
			$post_id = $this->resolve_post_id( $url );
		}

		if ( $post_id > 0 ) {
			$dead = $this->dead_for_post( $post_id, $url );
			if ( $dead ) {
				return array(
					'code'    => 'dead_link',
					'label'   => $label,
					'url'     => $url,
					'message' => $dead['message'],
				);
			}

			$lang = $this->language_of( $post_id );
			if ( '' !== $lang && $lang !== $expected ) {
				return array(
					'code'    => 'wrong_language_link',
					'label'   => $label,
					'url'     => $url,
					'message' => sprintf(
						/* translators: %s: language name */
						__( 'Links to %s content', 'linguator-pro' ),
						$this->language_name( $lang )
					),
				);
			}
		}

		return null;
	}

	/**
	 * Header template parts that apply to one language.
	 *
	 * Falls back to the translation of a default-language header when no part is tagged with the language.
	 *
	 * @since 2.3.0
	 *
	 * @param string     $slug        Language slug.
	 * @param string     $default     Default language slug.
	 * @param \WP_Post[] $header_part Template parts with navigation.
	 * @param array      $with_nav    Part IDs containing navigation.
	 * @return \WP_Post[]
	 */
	private function effective_headers( string $slug, string $default, array $header_part, array $with_nav ): array {
		$headers = $this->headers( $header_part );
		$matched = array();

		foreach ( $headers as $part ) {
			if ( isset( $with_nav[ (int) $part->ID ] ) && $this->language_of( (int) $part->ID ) === $slug ) {
				$matched[ (int) $part->ID ] = $part;
			}
		}
		if ( $matched || $slug === $default ) {
			return array_values( $matched );
		}

		foreach ( $headers as $part ) {
			if ( $this->language_of( (int) $part->ID ) !== $default ) {
				continue;
			}
			$translated_id = (int) $this->model->post->get_translation( (int) $part->ID, $slug );
			$translated    = $translated_id > 0 ? get_post( $translated_id ) : null;
			if ( $translated instanceof \WP_Post && 'publish' === $translated->post_status ) {
				$matched[ $translated_id ] = $translated;
			}
		}

		return array_values( $matched );
	}

	/**
	 * Navigation blocks, in a language's header, that reference another language's menu.
	 *
	 * @since 2.3.0
	 *
	 * @param \WP_Post[] $parts     Header parts.
	 * @param string     $expected  Expected language slug.
	 * @param object[]   $languages Languages.
	 * @return array[]
	 */
	private function ref_issues( array $parts, string $expected, array $languages ): array {
		$issues = array();

		foreach ( $parts as $part ) {
			foreach ( $this->find_navigation_blocks( parse_blocks( (string) $part->post_content ) ) as $block ) {
				$ref_id = ! empty( $block['attrs']['ref'] ) ? (int) $block['attrs']['ref'] : 0;
				$source = $ref_id > 0 ? $this->language_of( $ref_id ) : $this->language_of( (int) $part->ID );

				if ( '' !== $source && $source !== $expected ) {
					$issues[] = array(
						'language'        => $expected,
						'source_language' => $source,
						'ref_id'          => $ref_id,
						'part_id'         => (int) $part->ID,
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * Whether a language lacks the translation of a default-language navigation entity.
	 *
	 * @since 2.3.0
	 *
	 * @param string     $slug             Language slug.
	 * @param string     $default          Default language slug.
	 * @param \WP_Post[] $navigation_posts Navigation entities.
	 * @return bool
	 */
	private function missing_entity( string $slug, string $default, array $navigation_posts ): bool {
		if ( $slug === $default ) {
			return false;
		}

		foreach ( $navigation_posts as $post ) {
			if ( $this->language_of( (int) $post->ID ) === $default && (int) $this->model->post->get_translation( (int) $post->ID, $slug ) <= 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Published posts of a type.
	 *
	 * @since 2.3.0
	 *
	 * @param string $type Post type.
	 * @return \WP_Post[]
	 */
	private function published( string $type ): array {
		if ( ! post_type_exists( $type ) ) {
			return array();
		}

		return (array) get_posts(
			array(
				'post_type'              => $type,
				'post_status'            => 'publish',
				'posts_per_page'         => 100,
				'no_found_rows'          => true,
				'suppress_filters'       => true,
				'lang'                   => '',
				'update_post_term_cache' => false,
			)
		);
	}

	/**
	 * Header and footer template parts.
	 *
	 * @since 2.3.0
	 *
	 * @return \WP_Post[]
	 */
	private function template_parts(): array {
		$out = array();
		foreach ( $this->published( 'wp_template_part' ) as $part ) {
			$area = $this->area_of( (int) $part->ID );
			if ( in_array( $area, array( 'header', 'footer' ), true ) || false !== strpos( (string) $part->post_name, 'header' ) || false !== strpos( (string) $part->post_name, 'footer' ) ) {
				$out[] = $part;
			}
		}

		return $out;
	}

	/**
	 * Only the header parts.
	 *
	 * @since 2.3.0
	 *
	 * @param \WP_Post[] $parts Template parts.
	 * @return \WP_Post[]
	 */
	private function headers( array $parts ): array {
		return array_values(
			array_filter(
				$parts,
				function ( $part ) {
					return 'header' === $this->area_of( (int) $part->ID ) || false !== strpos( (string) $part->post_name, 'header' );
				}
			)
		);
	}

	/**
	 * Template part area.
	 *
	 * @since 2.3.0
	 *
	 * @param int $post_id Template part ID.
	 * @return string
	 */
	private function area_of( int $post_id ): string {
		$area = get_post_meta( $post_id, '_wp_template_part_area', true );
		if ( is_string( $area ) && '' !== $area ) {
			return $area;
		}
		$post = get_post( $post_id );

		return $post instanceof \WP_Post ? (string) $post->post_name : '';
	}

	/**
	 * Readable name of a template part.
	 *
	 * @since 2.3.0
	 *
	 * @param \WP_Post $part Template part.
	 * @return string
	 */
	private function part_label( \WP_Post $part ): string {
		$title = get_the_title( $part );
		if ( '' !== trim( (string) $title ) ) {
			return (string) $title;
		}
		$area = $this->area_of( (int) $part->ID );

		return '' !== $area ? ucfirst( $area ) : (string) $part->post_name;
	}

	/**
	 * Navigation blocks inside parsed blocks.
	 *
	 * @since 2.3.0
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array[]
	 */
	private function find_navigation_blocks( array $blocks ): array {
		$found = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( self::NAV_BLOCK === ( $block['blockName'] ?? '' ) ) {
				$found[] = $block;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = array_merge( $found, $this->find_navigation_blocks( $block['innerBlocks'] ) );
			}
		}

		return $found;
	}

	/**
	 * Links in navigation blocks, following a reference to a navigation entity.
	 *
	 * @since 2.3.0
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array<int,array{label:string,url:string,id:int,kind:string}>
	 */
	private function collect_links( array $blocks ): array {
		$links = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = (string) ( $block['blockName'] ?? '' );

			if ( self::NAV_BLOCK === $name && ! empty( $block['attrs']['ref'] ) ) {
				$ref = get_post( (int) $block['attrs']['ref'] );
				if ( $ref instanceof \WP_Post ) {
					$links = array_merge( $links, $this->collect_links( parse_blocks( (string) $ref->post_content ) ) );
				}
			}
			if ( in_array( $name, self::LINK_BLOCKS, true ) ) {
				$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$links[] = array(
					'label' => (string) ( $attrs['label'] ?? '' ),
					'url'   => (string) ( $attrs['url'] ?? '' ),
					'id'    => (int) ( $attrs['id'] ?? 0 ),
					'kind'  => (string) ( $attrs['kind'] ?? '' ),
				);
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$links = array_merge( $links, $this->collect_links( $block['innerBlocks'] ) );
			}
		}

		return $links;
	}

	/**
	 * Language slug of a post, or an empty string when it has none.
	 *
	 * @since 2.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function language_of( int $post_id ): string {
		$lang = $this->model->post->get_language( $post_id );

		return $lang ? (string) $lang->slug : '';
	}

}
