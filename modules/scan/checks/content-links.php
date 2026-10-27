<?php
/**
 * Content link checks.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Modules\Scan\Abstract_Check;
use LinguatorPro\Modules\Scan\Elementor_Fields;
use LinguatorPro\Modules\Scan\Link_Tools;
use LinguatorPro\Modules\Scan\Result;

/**
 * Finds links in published content that go to a missing or unpublished page,
 * or to a page in another language that has a translation.
 *
 * Elementor-built posts are skipped; the Elementor scan owns those.
 *
 * @since 2.3.0
 */
class Content_Links extends Abstract_Check {

	use Link_Tools;

	/**
	 * @var string
	 */
	protected $group = 'Content';

	/**
	 * @var string
	 */
	protected $section = 'content';

	/**
	 * Runs the check.
	 *
	 * @since 2.3.0
	 *
	 * @return Result[]
	 */
	public function run(): array {
		$default   = $this->default_lang();
		$languages = $this->active_languages();
		$types     = $this->translated_post_types();

		if ( '' === $default || count( $languages ) < 2 || empty( $types ) ) {
			return array();
		}

		$default_obj = null;
		$by_slug     = array();
		foreach ( $languages as $lang ) {
			$by_slug[ $lang->slug ] = $lang;
			if ( $lang->slug === $default ) {
				$default_obj = $lang;
			}
		}
		if ( ! $default_obj ) {
			return array();
		}

		$rows   = array_fill_keys( array_keys( $by_slug ), array() );
		$totals = array_fill_keys( array_keys( $by_slug ), 0 );
		$seen   = array();

		foreach ( $types as $type ) {
			$post_ids = $this->default_language_post_ids( $type, $default_obj );

			foreach ( $post_ids as $post_id ) {
				$post_id      = (int) $post_id;
				$translations = $this->model->post->get_translations( $post_id );

				foreach ( $by_slug as $slug => $lang ) {
					$content_id = $slug === $default ? $post_id : (int) ( $translations[ $slug ] ?? 0 );
					if ( $content_id <= 0 || 'publish' !== get_post_status( $content_id ) ) {
						continue;
					}
					$content_post = get_post( $content_id );
					if ( ! $content_post instanceof \WP_Post ) {
						continue;
					}

					foreach ( $this->links_of( $content_post ) as $link ) {
						$issue = $this->classify( $link['url'], $slug );
						if ( ! $issue ) {
							continue;
						}

						$dedupe = $content_id . '|' . $slug . '|' . $issue['code'] . '|' . $this->normalize_url( $link['url'] );
						if ( isset( $seen[ $dedupe ] ) ) {
							continue;
						}
						$seen[ $dedupe ] = true;

						++$totals[ $slug ];
						if ( count( $rows[ $slug ] ) >= self::DETAIL_LIMIT ) {
							continue;
						}

						$rows[ $slug ][] = $this->row( $post_id, $content_post, $lang, $link, $issue, $type );
					}
				}
			}
		}

		$results = array();
		foreach ( $by_slug as $slug => $lang ) {
			$id = 'content_link_' . $slug;
			if ( ! $this->wants( $id ) ) {
				continue;
			}

			if ( empty( $rows[ $slug ] ) ) {
				$results[] = $this->result(
					$id,
					Result::PASS,
					/* translators: %s: language name with code */
					sprintf( __( 'Links work in "%s"', 'linguator-pro' ), $this->lang_label( $lang ) ),
					__( 'No link in the content points to a missing page or the wrong language.', 'linguator-pro' )
				);
				continue;
			}

			$results[] = $this->result(
				$id,
				Result::WARNING,
				/* translators: %s: language name with code */
				sprintf( __( 'Link issues in "%s"', 'linguator-pro' ), $this->lang_label( $lang ) ),
				sprintf(
					/* translators: 1: number of links, 2: language name with code */
					_n(
						'%1$d link in "%2$s" content points to a missing page or the wrong language.',
						'%1$d links in "%2$s" content point to a missing page or the wrong language.',
						$totals[ $slug ],
						'linguator-pro'
					),
					$totals[ $slug ],
					$this->lang_label( $lang )
				)
			)->with_details( $rows[ $slug ], $totals[ $slug ] > count( $rows[ $slug ] ) );
		}

		return $results;
	}

	/**
	 * Builds one detail row.
	 *
	 * @since 2.3.0
	 *
	 * @param int      $source_id    Default-language post ID.
	 * @param \WP_Post $content_post Post that holds the link.
	 * @param object   $lang         Language of that post.
	 * @param array    $link         Link data: url, anchor_text, context.
	 * @param array    $issue        Classified issue.
	 * @param string   $type         Post type.
	 * @return array
	 */
	private function row( int $source_id, \WP_Post $content_post, $lang, array $link, array $issue, string $type ): array {
		$object     = get_post_type_object( $type );
		$type_label = $object && ! empty( $object->labels->singular_name ) ? $object->labels->singular_name : __( 'Page', 'linguator-pro' );
		$title      = '' !== $content_post->post_title ? $content_post->post_title : __( '(no title)', 'linguator-pro' );
		$text       = '' !== $link['anchor_text'] ? $link['anchor_text'] : $link['context'];
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > 40 ) {
			$text = mb_substr( $text, 0, 39, 'UTF-8' ) . '…';
		}

		$label = '' !== $text
			? sprintf(
				/* translators: 1: link text, 2: post type label, 3: post title, 4: language name */
				__( 'Link "%1$s" in the %2$s "%3$s" (%4$s)', 'linguator-pro' ),
				$text,
				$type_label,
				$title,
				$lang->name
			)
			: sprintf(
				/* translators: 1: post type label, 2: post title, 3: language name */
				__( 'Link in the %1$s "%2$s" (%3$s)', 'linguator-pro' ),
				$type_label,
				$title,
				$lang->name
			);

		return array(
			'label'         => $label,
			'message'       => $issue['message'],
			'context'       => $link['context'],
			'action_url'    => (string) get_edit_post_link( $content_post->ID, 'url' ),
			'action_label'  => 'dead_link' === $issue['code'] ? __( 'Edit page with link', 'linguator-pro' ) : __( 'Fix link', 'linguator-pro' ),
			'ignore_key'    => 'content_link:' . $source_id . ':' . $lang->slug . ':' . md5( $issue['code'] . '|' . $this->normalize_url( $link['url'] ) ),
			'code'          => $issue['code'],
			'language'      => $lang->slug,
			'url'           => $link['url'],
			'suggested_url' => $issue['suggested_url'],
			'page_fix_url'   => $issue['page_fix_url'] ?? '',
			'page_fix_label' => $issue['page_fix_label'] ?? '',
			'post_type'     => $type,
		);
	}

	/**
	 * Internal links of a post: from the body, or from the link settings of Elementor widgets.
	 *
	 * @since 2.3.0
	 *
	 * @param \WP_Post $post Post.
	 * @return array<int,array{url:string,anchor_text:string,context:string}>
	 */
	private function links_of( \WP_Post $post ): array {
		if ( ! Elementor_Fields::is_built( (int) $post->ID ) ) {
			return $this->extract_links( (string) $post->post_content );
		}

		$links = array();
		foreach ( Elementor_Fields::element_map( Elementor_Fields::tree( (int) $post->ID ) ) as $element ) {
			foreach ( $element['links'] as $field_key => $url ) {
				$text = $element['fields']['title'] ?? $element['fields']['text'] ?? '';
				$this->collect_link( $links, $url, $text, Elementor_Fields::widget_label( $element['widget_type'] ) . ' › ' . Elementor_Fields::field_label( $field_key ) );
			}
		}

		return array_values( $links );
	}

	/**
	 * Internal links of a post body.
	 *
	 * @since 2.3.0
	 *
	 * @param string $content Post content.
	 * @return array<int,array{url:string,anchor_text:string,context:string}>
	 */
	private function extract_links( string $content ): array {
		if ( '' === $content || false === stripos( $content, 'href' ) ) {
			return array();
		}

		$links = array();

		if ( ! class_exists( 'DOMDocument' ) ) {
			if ( preg_match_all( '/\bhref\s*=\s*([\'"])(.*?)\1/i', $content, $matches ) ) {
				foreach ( $matches[2] as $href ) {
					$this->collect_link( $links, trim( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ) ), '', '' );
				}
			}
			return array_values( $links );
		}

		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div>' . $content . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		foreach ( $doc->getElementsByTagName( 'a' ) as $anchor ) {
			$this->collect_link(
				$links,
				trim( (string) $anchor->getAttribute( 'href' ) ),
				trim( (string) preg_replace( '/\s+/u', ' ', $anchor->textContent ) ),
				$this->context_of( $anchor )
			);
		}

		return array_values( $links );
	}

	/**
	 * Adds a link once per normalized URL when it is an internal page link.
	 *
	 * @since 2.3.0
	 *
	 * @param array  $links       Collected links, by reference.
	 * @param string $href        Link URL.
	 * @param string $anchor_text Link text.
	 * @param string $context     Text of the surrounding block.
	 * @return void
	 */
	private function collect_link( array &$links, string $href, string $anchor_text, string $context ): void {
		if ( '' === $href || '#' === $href[0] || ! $this->is_internal( $href ) || $this->is_asset( $href ) ) {
			return;
		}

		$key = $this->normalize_url( $href );
		if ( ! isset( $links[ $key ] ) ) {
			$links[ $key ] = array(
				'url'         => $href,
				'anchor_text' => $anchor_text,
				'context'     => $context,
			);
		}
	}

	/**
	 * Short text of the block that contains a link, so the editor user can find it.
	 *
	 * @since 2.3.0
	 *
	 * @param \DOMNode $anchor Anchor element.
	 * @return string
	 */
	private function context_of( \DOMNode $anchor ): string {
		$blocks = array( 'p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'th', 'blockquote', 'figcaption' );
		$node   = $anchor->parentNode;

		for ( $depth = 0; $node && $depth < 6; $depth++, $node = $node->parentNode ) {
			if ( XML_ELEMENT_NODE === $node->nodeType && in_array( strtolower( $node->nodeName ), $blocks, true ) ) {
				$text = trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) );
				if ( '' !== $text ) {
					return function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > 140 ? mb_substr( $text, 0, 140, 'UTF-8' ) . '…' : $text;
				}
			}
		}

		return '';
	}

	/**
	 * Classifies one link found in content of the given language.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url  Link URL.
	 * @param string $slug Language of the page that holds the link.
	 * @return array{code:string,message:string,suggested_url:string}|null Null when the link is fine.
	 */
	private function classify( string $url, string $slug ): ?array {
		$dead = $this->dead_target( $url );
		if ( $dead ) {
			return array(
				'code'          => 'dead_link',
				'message'        => $dead['message'],
				'suggested_url'  => '',
				'page_fix_url'   => $dead['fix_url'],
				'page_fix_label' => $dead['fix_label'],
			);
		}

		$post_id = $this->resolve_post_id( $url );
		if ( $post_id <= 0 ) {
			return null;
		}

		$target_lang = $this->model->post->get_language( $post_id );
		if ( ! $target_lang || $target_lang->slug === $slug ) {
			return null;
		}

		// A page in another language is only wrong when a published translation exists to link to instead.
		$translated_id = (int) $this->model->post->get_translation( $post_id, $slug );
		if ( $translated_id <= 0 || 'publish' !== get_post_status( $translated_id ) ) {
			return null;
		}

		$permalink = (string) get_permalink( $translated_id );

		return array(
			'code'          => 'untranslated_link',
			'message'       => sprintf(
				/* translators: 1: link path, 2: language name, 3: suggested path */
				__( 'This link goes to "%1$s", which is in another language. Use the %2$s page "%3$s" instead.', 'linguator-pro' ),
				$this->readable( $url ),
				$this->language_name( $slug ),
				$this->readable( $permalink )
			),
			'suggested_url' => $permalink,
		);
	}

}
