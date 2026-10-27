<?php
/**
 * Shortcode and placeholder mismatch checks.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Modules\Scan\Abstract_Check;
use LinguatorPro\Modules\Scan\Elementor_Fields;
use LinguatorPro\Modules\Scan\Result;

/**
 * Flags translations whose shortcodes or placeholders differ from the source.
 *
 * A dropped shortcode usually means a form, button or widget vanished from the translated page, and a
 * changed %s or {{tag}} shows raw codes to visitors. Counts matter: three buttons in the source and one in
 * the translation is reported. Attribute values are ignored, since ids legitimately differ per language.
 * Elementor-built posts are skipped; the Elementor scan owns those. A language with no shortcode or
 * placeholder on either side gets no row instead of a reassuring pass.
 *
 * @since 2.3.0
 */
class Content_Placeholders extends Abstract_Check {

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
		$types     = array_diff( $this->translated_post_types(), array( 'attachment' ) );

		if ( '' === $default || count( $languages ) < 2 || empty( $types ) ) {
			return array();
		}

		$default_obj = null;
		$targets     = array();
		foreach ( $languages as $lang ) {
			if ( $lang->slug === $default ) {
				$default_obj = $lang;
			} elseif ( $this->wants( 'content_placeholder_' . $lang->slug ) ) {
				$targets[ $lang->slug ] = $lang;
			}
		}
		if ( ! $default_obj || empty( $targets ) ) {
			return array();
		}

		$rows     = array_fill_keys( array_keys( $targets ), array() );
		$compared = array_fill_keys( array_keys( $targets ), 0 );
		$found    = array_fill_keys( array_keys( $targets ), 0 );

		foreach ( $types as $type ) {
			$post_ids = $this->default_language_post_ids( $type, $default_obj );

			foreach ( $post_ids as $post_id ) {
				$post_id = (int) $post_id;
				$post    = get_post( $post_id );
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}

				$translations  = $this->model->post->get_translations( $post_id );
				$source_tokens = null;

				foreach ( $targets as $slug => $lang ) {
					$translation_id = (int) ( $translations[ $slug ] ?? 0 );
					if ( $translation_id <= 0 || 'publish' !== get_post_status( $translation_id ) ) {
						continue;
					}

					$translation = get_post( $translation_id );
					if ( ! $translation instanceof \WP_Post ) {
						continue;
					}

					if ( null === $source_tokens ) {
						$source_tokens = $this->tokens( $this->text_of( $post ) );
					}
					$target_tokens = $this->tokens( $this->text_of( $translation ) );

					// Nothing to compare when neither side has a shortcode or placeholder.
					if ( empty( $source_tokens ) && empty( $target_tokens ) ) {
						continue;
					}
					++$compared[ $slug ];

					$differences = $this->differences( $source_tokens, $target_tokens );
					if ( empty( $differences ) ) {
						continue;
					}

					++$found[ $slug ];
					if ( count( $rows[ $slug ] ) >= self::DETAIL_LIMIT ) {
						continue;
					}

					$object     = get_post_type_object( $type );
					$type_label = $object && ! empty( $object->labels->singular_name ) ? $object->labels->singular_name : __( 'Item', 'linguator-pro' );
					$title      = '' !== $translation->post_title ? $translation->post_title : $post->post_title;
					$what       = '' !== $title
						? sprintf(
							/* translators: 1: content type, e.g. Page, 2: its title */
							__( '%1$s "%2$s"', 'linguator-pro' ),
							$type_label,
							$title
						)
						: sprintf(
							/* translators: 1: content type, e.g. Page, 2: its ID number */
							__( '%1$s #%2$d', 'linguator-pro' ),
							$type_label,
							$translation_id
						);

					$rows[ $slug ][] = array(
						'label'          => sprintf(
							/* translators: 1: the page, e.g. Page "About us", 2: language name */
							__( '%1$s (%2$s)', 'linguator-pro' ),
							$what,
							$lang->name
						),
						'message'        => implode( ' · ', $differences ),
						'action_url'     => (string) get_edit_post_link( $translation_id, 'url' ),
						'action_label'   => __( 'Edit translation', 'linguator-pro' ),
						'ai_url'         => admin_url( 'edit.php?post_type=' . rawurlencode( $type ) ),
						'ignore_key'     => 'content_placeholder:' . $post_id . ':' . $slug . ':' . md5( (string) wp_json_encode( array( $source_tokens, $target_tokens ) ) ),
						'translation_id' => $translation_id,
						'language'       => $slug,
						'post_type'      => $type,
					);
				}
			}
		}

		$results = array();
		foreach ( $targets as $slug => $lang ) {
			$id    = 'content_placeholder_' . $slug;
			$label = $this->lang_label( $lang );

			if ( $found[ $slug ] > 0 ) {
				$results[] = $this->result(
					$id,
					Result::WARNING,
					/* translators: %s: language name with code */
					sprintf( __( 'Shortcode and placeholder mismatch in "%s"', 'linguator-pro' ), $label ),
					sprintf(
						/* translators: 1: number of pages, 2: language name with code */
						_n(
							'%1$d translated page or post has shortcodes or placeholders that differ from its source in "%2$s".',
							'%1$d translated pages and posts have shortcodes or placeholders that differ from their source in "%2$s".',
							$found[ $slug ],
							'linguator-pro'
						),
						$found[ $slug ],
						$label
					)
				)->with_details( $rows[ $slug ], $found[ $slug ] > count( $rows[ $slug ] ) );
			} elseif ( $compared[ $slug ] > 0 ) {
				$results[] = $this->result(
					$id,
					Result::PASS,
					/* translators: %s: language name with code */
					sprintf( __( 'Placeholders match in "%s"', 'linguator-pro' ), $label ),
					sprintf(
						/* translators: %d: number of pages and posts compared */
						_n(
							'Shortcodes and placeholders match in %d translated page or post.',
							'Shortcodes and placeholders match in %d translated pages and posts.',
							$compared[ $slug ],
							'linguator-pro'
						),
						$compared[ $slug ]
					)
				);
			}
		}

		return $results;
	}

	/**
	 * The text to read shortcodes and placeholders from.
	 *
	 * Gutenberg and classic posts keep everything in post_content. An Elementor page keeps its text in widget
	 * settings, so those text fields are read instead. Styles and settings are not included.
	 *
	 * @since 2.3.0
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function text_of( \WP_Post $post ): string {
		if ( ! Elementor_Fields::is_built( (int) $post->ID ) ) {
			return (string) $post->post_content;
		}

		$lines = array();
		foreach ( Elementor_Fields::element_map( Elementor_Fields::tree( (int) $post->ID ) ) as $element ) {
			foreach ( $element['fields'] as $text ) {
				$lines[] = $text;
			}
		}

		return implode( "
", $lines );
	}

	/**
	 * Shortcodes, printf specifiers and {{merge tags}} in post content, with how often each appears.
	 *
	 * Only registered shortcodes count, so "[1]" or "[sic]" in the text is ignored. Block markup comments are
	 * removed first because they carry JSON such as {"ids":[12]}. printf specifiers and merge tags are read
	 * from the visible text only, with tags and URLs removed, so a percent-encoded link slug or "20%discount"
	 * is not mistaken for a placeholder.
	 *
	 * @since 2.3.0
	 *
	 * @param string $html Raw post content.
	 * @return array<string,int> Token => number of occurrences.
	 */
	private function tokens( string $html ): array {
		$tokens = array();
		if ( '' === trim( $html ) ) {
			return $tokens;
		}

		$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$this->count_shortcodes( $html, $tokens );

		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '#(?:https?:)?//\S+#i', ' ', $text );

		// %s, %d, %1$s, %.2f, %05d. Not after a word character or another %, and not followed by a letter.
		if ( preg_match_all( '/(?<![\w%])%(?:\d+\$)?[-+0#]*\d*(?:\.\d+)?[sdfFeEu](?![A-Za-z])/', $text, $matches ) ) {
			foreach ( $matches[0] as $token ) {
				$tokens[ $token ] = ( $tokens[ $token ] ?? 0 ) + 1;
			}
		}

		if ( preg_match_all( '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $text, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				$token            = '{{' . $tag . '}}';
				$tokens[ $token ] = ( $tokens[ $token ] ?? 0 ) + 1;
			}
		}

		return $tokens;
	}

	/**
	 * Counts registered shortcodes in content, including ones nested inside another shortcode.
	 *
	 * @since 2.3.0
	 *
	 * @param string             $content Content to read.
	 * @param array<string,int>  $tokens  Token => count, by reference.
	 * @return void
	 */
	private function count_shortcodes( string $content, array &$tokens ): void {
		global $shortcode_tags;

		if ( empty( $shortcode_tags ) || false === strpos( $content, '[' ) ) {
			return;
		}

		if ( ! preg_match_all( '/' . get_shortcode_regex( array_keys( $shortcode_tags ) ) . '/', $content, $matches, PREG_SET_ORDER ) ) {
			return;
		}

		foreach ( $matches as $match ) {
			// [[tag]] is an escaped shortcode: shown as text, never run.
			if ( '[' === $match[1] && ']' === $match[6] ) {
				continue;
			}

			$token            = '[' . $match[2] . ']';
			$tokens[ $token ] = ( $tokens[ $token ] ?? 0 ) + 1;

			if ( '' !== $match[5] ) {
				$this->count_shortcodes( $match[5], $tokens );
			}
		}
	}

	/**
	 * Plain-language list of the placeholders that differ between source and translation.
	 *
	 * @since 2.3.0
	 *
	 * @param array<string,int> $source Source tokens.
	 * @param array<string,int> $target Translation tokens.
	 * @return string[] One sentence per direction; empty when they match.
	 */
	private function differences( array $source, array $target ): array {
		$missing = array();
		$extra   = array();

		foreach ( array_unique( array_merge( array_keys( $source ), array_keys( $target ) ) ) as $token ) {
			$in_source = $source[ $token ] ?? 0;
			$in_target = $target[ $token ] ?? 0;

			// Gone completely or only in the translation: name it. Numbers help only when it is there a different number of times.
			if ( $in_source > 0 && $in_target > 0 ) {
				$entry = sprintf(
					/* translators: 1: what it is, e.g. shortcode, 2: the token, 3: times in the original, 4: times in the translation */
					__( '%1$s %2$s (%3$d in the original, %4$d in the translation)', 'linguator-pro' ),
					$this->kind( $token ),
					$token,
					$in_source,
					$in_target
				);
			} else {
				$entry = $this->kind( $token ) . ' ' . $token;
			}

			if ( $in_source > $in_target ) {
				$missing[] = $entry;
			} elseif ( $in_target > $in_source ) {
				$extra[] = $entry;
			}
		}

		$parts = array();
		if ( $missing ) {
			/* translators: %s: list of shortcodes and placeholders */
			$parts[] = sprintf( __( 'Missing in the translation: %s', 'linguator-pro' ), $this->list_of( $missing ) );
		}
		if ( $extra ) {
			/* translators: %s: list of shortcodes and placeholders */
			$parts[] = sprintf( __( 'Not in the original: %s', 'linguator-pro' ), $this->list_of( $extra ) );
		}

		return $parts;
	}

	/**
	 * Plain name for a token, so the row says what kind of thing it is.
	 *
	 * @since 2.3.0
	 *
	 * @param string $token Shortcode like [button], merge tag like {{name}}, or printf code like %s.
	 * @return string
	 */
	private function kind( string $token ): string {
		if ( 0 === strpos( $token, '[' ) ) {
			return __( 'shortcode', 'linguator-pro' );
		}

		return 0 === strpos( $token, '{{' ) ? __( 'merge tag', 'linguator-pro' ) : __( 'placeholder', 'linguator-pro' );
	}

	/**
	 * Comma-separated list, cut after five entries.
	 *
	 * @since 2.3.0
	 *
	 * @param string[] $items Entries.
	 * @return string
	 */
	private function list_of( array $items ): string {
		$list = implode( ', ', array_slice( $items, 0, 5 ) );

		return count( $items ) > 5 ? $list . ' …' : $list;
	}
}
