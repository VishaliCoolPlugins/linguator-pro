<?php
/**
 * Untranslated text on a translated page.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds text on a translated post that is still identical to its source post.
 *
 * The source is the default-language translation of the same post. Elementor posts are compared widget field by
 * widget field, with the same rule as the site scan; classic and block posts are compared title and text segment
 * by text segment.
 *
 * @since 2.3.0
 */
class Untranslated_Collector {

	const STATUS_OK                 = 'ok';
	const STATUS_NOT_TRANSLATABLE   = 'not_translatable';
	const STATUS_SOURCE_LANGUAGE    = 'source_language';
	const STATUS_NO_SOURCE          = 'no_source';
	const STATUS_SOURCE_UNPUBLISHED = 'source_unpublished';

	/**
	 * Block-level tags a content segment ends at.
	 */
	const SEGMENT_SPLIT_PATTERN = '#</?(?:p|h[1-6]|li|ul|ol|blockquote|figcaption|figure|td|th|tr|dt|dd|div|pre|section|article|header|footer|button)\b[^>]*>|<br\s*/?>#i';

	/**
	 * Translation model, or null when Linguator is not ready.
	 *
	 * @since 2.3.0
	 *
	 * @return \LinguatorPro\Includes\Other\Linguator_Model|null
	 */
	private static function model() {
		return function_exists( 'LMATP' ) ? ( LMATP()->model ?? null ) : null;
	}

	/**
	 * Untranslated rows for one post.
	 *
	 * "total" is how many strings were compared, so rows / total is the share left untranslated.
	 *
	 * @since 2.3.0
	 *
	 * @param int $post_id Post being viewed.
	 * @return array{status:string,current_lang:string,source_lang:string,rows:array[],total:int}
	 */
	public static function for_post( int $post_id ): array {
		$result = array(
			'status'       => self::STATUS_OK,
			'current_lang' => '',
			'source_lang'  => '',
			'rows'         => array(),
			'total'        => 0,
		);

		$model   = self::model();
		$default = $model ? $model->get_default_language() : null;
		$lang    = $model && $post_id > 0 ? $model->post->get_language( $post_id ) : false;

		if ( ! $model || ! $default || ! $lang ) {
			$result['status'] = self::STATUS_NOT_TRANSLATABLE;
			return $result;
		}

		$result['current_lang'] = (string) $lang->slug;
		$result['source_lang']  = (string) $default->slug;

		if ( $lang->slug === $default->slug ) {
			$result['status'] = self::STATUS_SOURCE_LANGUAGE;
			return $result;
		}

		$source_id = (int) ( $model->post->get_translations( $post_id )[ $default->slug ] ?? 0 );
		if ( $source_id <= 0 || $source_id === $post_id ) {
			$result['status'] = self::STATUS_NO_SOURCE;
			return $result;
		}
		if ( 'publish' !== get_post_status( $source_id ) ) {
			$result['status'] = self::STATUS_SOURCE_UNPUBLISHED;
			return $result;
		}

		$source      = get_post( $source_id );
		$translation = get_post( $post_id );
		if ( ! $source instanceof \WP_Post || ! $translation instanceof \WP_Post ) {
			$result['status'] = self::STATUS_NO_SOURCE;
			return $result;
		}

		$cache_key = 'lmat_untr_' . md5( $post_id . '_' . $source_id . '_' . $translation->post_modified . '_' . $source->post_modified . '_' . ( defined( 'LINGUATOR_PRO_VERSION' ) ? LINGUATOR_PRO_VERSION : '1' ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['rows'], $cached['total'] ) ) {
			$result['rows']  = $cached['rows'];
			$result['total'] = (int) $cached['total'];
			return $result;
		}

		$total = 0;
		$rows  = self::title_rows( $source, $translation, (string) $lang->slug, $total );

		$source_built      = Elementor_Fields::is_built( $source_id );
		$translation_built = Elementor_Fields::is_built( $post_id );

		if ( $source_built && $translation_built ) {
			$rows = array_merge( $rows, self::elementor_rows( $source_id, $post_id, (string) $lang->slug, $total ) );
		} elseif ( ! $source_built && ! $translation_built ) {
			$rows = array_merge( $rows, self::content_rows( $source, $translation, (string) $lang->slug, $total ) );
		}

		set_transient( $cache_key, array( 'rows' => $rows, 'total' => $total ), DAY_IN_SECONDS );

		$result['rows']  = $rows;
		$result['total'] = $total;

		return $result;
	}

	/**
	 * Untranslated rows for an Elementor template rendered on the page (header, footer...).
	 *
	 * A template still in the source language is reported field by field, with a link to create its translation.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $template_id  Rendered template post ID.
	 * @param string $current_lang Language of the page being viewed.
	 * @param string $source_lang  Default language.
	 * @return array{rows:array[],total:int}
	 */
	public static function for_template( int $template_id, string $current_lang, string $source_lang ): array {
		$none  = array( 'rows' => array(), 'total' => 0 );
		$model = self::model();

		if ( ! $model || 'elementor_library' !== get_post_type( $template_id ) || ! Elementor_Fields::is_built( $template_id ) || 'kit' === get_post_meta( $template_id, '_elementor_template_type', true ) ) {
			return $none;
		}

		$template_post = get_post( $template_id );
		$cache_key     = 'lmat_untr_tpl_' . md5( $template_id . '_' . $current_lang . '_' . $source_lang . '_' . ( $template_post instanceof \WP_Post ? $template_post->post_modified : '' ) . '_' . ( defined( 'LINGUATOR_PRO_VERSION' ) ? LINGUATOR_PRO_VERSION : '1' ) );
		$cached        = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['rows'], $cached['total'] ) ) {
			return $cached;
		}

		$language      = $model->post->get_language( $template_id );
		$template_lang = $language ? (string) $language->slug : '';
		$total         = 0;
		$title         = get_the_title( $template_id );
		$type          = (string) get_post_meta( $template_id, '_elementor_template_type', true );
		$type_label    = ucwords( str_replace( array( '-', '_' ), ' ', '' !== $type ? $type : 'template' ) );

		if ( '' !== $template_lang && $template_lang === $current_lang ) {
			$source_id = (int) ( $model->post->get_translations( $template_id )[ $source_lang ] ?? 0 );
			if ( $source_id <= 0 || $source_id === $template_id ) {
				return $none;
			}
			$rows  = self::elementor_rows( $source_id, $template_id, $current_lang, $total );
			$group = sprintf(
				/* translators: 1: template type, 2: title, 3: language code */
				__( '%1$s: %2$s (%3$s)', 'linguator-pro' ),
				$type_label,
				$title,
				strtoupper( $current_lang )
			);
		} elseif ( '' !== $template_lang && $template_lang === $source_lang ) {
			$rows          = self::elementor_rows( $template_id, $template_id, $current_lang, $total );
			$translate_url = add_query_arg(
				array(
					'post_type' => 'elementor_library',
					'from_post' => $template_id,
					'new_lang'  => sanitize_key( $current_lang ),
					'_wpnonce'  => wp_create_nonce( 'new-post-translation' ),
				),
				admin_url( 'post-new.php' )
			);
			foreach ( $rows as $index => $row ) {
				$rows[ $index ]['edit_url'] = $translate_url;
				$rows[ $index ]['post_id']  = 0; // No translation yet, so no quick edit.
			}
			$target_id = (int) ( $model->post->get_translations( $template_id )[ $current_lang ] ?? 0 );
			$group     = $target_id > 0
				? sprintf(
					/* translators: 1: template type, 2: title, 3: source language, 4: target language, 5: translated template ID */
					__( '%1$s: %2$s (Loaded: %3$s — %4$s template #%5$d exists)', 'linguator-pro' ),
					$type_label,
					$title,
					strtoupper( $template_lang ),
					strtoupper( $current_lang ),
					$target_id
				)
				: sprintf(
					/* translators: 1: template type, 2: title, 3: source language */
					__( '%1$s: %2$s (Loaded: %3$s — Not translated yet)', 'linguator-pro' ),
					$type_label,
					$title,
					strtoupper( $template_lang )
				);
		} else {
			return $none;
		}

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['group'] = $group;
		}

		$result = array( 'rows' => $rows, 'total' => $total );
		set_transient( $cache_key, $result, DAY_IN_SECONDS );

		return $result;
	}

	/**
	 * Row for a title left identical to the source title.
	 *
	 * @since 2.3.0
	 *
	 * @param \WP_Post $source      Source post.
	 * @param \WP_Post $translation Translated post.
	 * @param string   $lang        Target language slug.
	 * @param int      $total       Compared-string counter, increased in place.
	 * @return array[]
	 */
	private static function title_rows( \WP_Post $source, \WP_Post $translation, string $lang, int &$total ): array {
		$original   = self::normalize_text( $source->post_title );
		$translated = self::normalize_text( $translation->post_title );

		if ( '' === $original || self::is_trivial( $original ) ) {
			return array();
		}

		++$total;

		if ( ! Elementor_Fields::text_untranslated( $original, $translated, $lang ) ) {
			return array();
		}

		return array(
			array(
				'kind'       => 'title',
				'element_id' => '',
				'widget'     => '',
				'field'      => __( 'Title', 'linguator-pro' ),
				'match'      => $translated,
				'post_id'    => (int) $translation->ID,
				'field_key'  => 'post_title',
				'edit_url'   => self::with_focus_text( self::edit_url( $translation->ID ), $translated ),
			),
		);
	}

	/**
	 * Elementor widget fields whose text matches the source widget, matched by element ID.
	 *
	 * Multi-paragraph fields are reported per paragraph, compared on the raw HTML because the element map keeps
	 * tag-stripped text only.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $source_id      Source post ID.
	 * @param int    $translation_id Translated post ID.
	 * @param string $lang           Target language slug.
	 * @param int    $total          Compared-string counter, increased in place.
	 * @return array[]
	 */
	private static function elementor_rows( int $source_id, int $translation_id, string $lang, int &$total ): array {
		$source_tree      = Elementor_Fields::tree( $source_id );
		$translation_tree = Elementor_Fields::tree( $translation_id );
		$source_map       = Elementor_Fields::element_map( $source_tree );
		$translation_map  = Elementor_Fields::element_map( $translation_tree );
		$source_raw       = Elementor_Fields::raw_settings( $source_tree );
		$translation_raw  = Elementor_Fields::raw_settings( $translation_tree );
		$edit_url         = (string) get_edit_post_link( $translation_id, 'url' );
		$edit_url         = $edit_url ? add_query_arg( 'action', 'elementor', $edit_url ) : '';
		$rows             = array();

		foreach ( $source_map as $element_id => $source_element ) {
			$element_id = (string) $element_id;

			if ( ! isset( $translation_map[ $element_id ] ) ) {
				// A widget added to the original page that is missing from the translation layout.
				foreach ( $source_element['fields'] as $field_key => $original ) {
					if ( Elementor_Fields::skip_same_text( $field_key, $original ) ) {
						continue;
					}
					$segments = self::segments( Elementor_Fields::raw_value( $source_raw, $element_id, $field_key ) );
					$total   += max( 1, count( $segments ) );
					foreach ( count( $segments ) < 2 ? array( self::normalize_text( $original ) ) : $segments as $text ) {
						$row                = self::elementor_row( $element_id, $source_element['widget_type'], $field_key, $text, $edit_url, count( $segments ) >= 2, $translation_id );
						$row['field']       = sprintf(
							/* translators: %s: field name */
							__( '%s (missing)', 'linguator-pro' ),
							Elementor_Fields::location_label( $field_key )
						);
						$row['note']        = __( 'This widget was added to the original page but is missing from this translation layout.', 'linguator-pro' );
						$rows[]             = $row;
					}
				}
				continue;
			}

			$other = $translation_map[ $element_id ];

			foreach ( $source_element['fields'] as $field_key => $original ) {
				$translated = $other['fields'][ $field_key ] ?? '';
				$raw        = Elementor_Fields::raw_value( $translation_raw, $element_id, $field_key );

				if ( Elementor_Fields::is_untranslated( $field_key, $original, $translated, $lang ) ) {
					$segments = self::segments( $raw );
					if ( count( $segments ) < 2 ) {
						++$total;
						$rows[] = self::elementor_row( $element_id, $source_element['widget_type'], $field_key, self::normalize_text( $translated ), $edit_url, false, $translation_id );
						continue;
					}
					$total += count( $segments );
				} elseif ( '' !== $translated && ! Elementor_Fields::skip_same_text( $field_key, $original ) ) {
					$source_segments = self::segments( Elementor_Fields::raw_value( $source_raw, $element_id, $field_key ) );
					$total          += max( 1, count( $source_segments ) );
					$segments        = self::match_untranslated( self::segments( $raw ), $source_segments, $lang );
				} else {
					continue;
				}

				foreach ( $segments as $segment ) {
					$rows[] = self::elementor_row( $element_id, $source_element['widget_type'], $field_key, $segment, $edit_url, true, $translation_id );
				}
			}
		}

		return $rows;
	}

	/**
	 * One Elementor row.
	 *
	 * @since 2.3.0
	 *
	 * @param string $element_id  Element ID.
	 * @param string $widget_type Widget type.
	 * @param string $field_key   Field key.
	 * @param string $text        Untranslated text.
	 * @param string $edit_url    Elementor editor URL.
	 * @param bool   $is_segment  Whether the text is one paragraph of the field.
	 * @param int    $post_id     Post that holds the translation, used by quick edit.
	 * @return array
	 */
	private static function elementor_row( string $element_id, string $widget_type, string $field_key, string $text, string $edit_url, bool $is_segment, int $post_id ): array {
		$field = Elementor_Fields::location_label( $field_key );
		if ( $is_segment ) {
			/* translators: %s: field name, e.g. Content */
			$field = sprintf( __( '%s (paragraph)', 'linguator-pro' ), $field );
		}

		return array(
			'kind'       => 'elementor',
			'element_id' => $element_id,
			'widget'     => Elementor_Fields::widget_label( $widget_type ),
			'field'      => $field,
			'match'      => $text,
			'segment'    => $is_segment,
			'post_id'    => $post_id,
			'field_key'  => $field_key,
			'edit_url'   => '' !== $edit_url ? add_query_arg( Frontend_Inspector::FOCUS_ARG, rawurlencode( $element_id ), $edit_url ) : '',
		);
	}

	/**
	 * Classic and block content segments that also appear in the source content.
	 *
	 * @since 2.3.0
	 *
	 * @param \WP_Post $source      Source post.
	 * @param \WP_Post $translation Translated post.
	 * @param string   $lang        Target language slug.
	 * @param int      $total       Compared-string counter, increased in place.
	 * @return array[]
	 */
	private static function content_rows( \WP_Post $source, \WP_Post $translation, string $lang, int &$total ): array {
		$source_segments = self::segments( $source->post_content );
		if ( empty( $source_segments ) ) {
			return array();
		}

		$total   += count( $source_segments );
		$edit_url = self::edit_url( $translation->ID );
		$rows     = array();

		foreach ( self::match_untranslated( self::segments( $translation->post_content ), $source_segments, $lang ) as $segment ) {
			$rows[] = array(
				'kind'       => 'text',
				'element_id' => '',
				'widget'     => '',
				'field'      => __( 'Content', 'linguator-pro' ),
				'match'      => $segment,
				'edit_url'   => self::with_focus_text( $edit_url, $segment ),
			);
		}

		return $rows;
	}

	/**
	 * Translated segments that are identical, or nearly, to a source segment.
	 *
	 * @since 2.3.0
	 *
	 * @param string[] $translated Segments from the translated post.
	 * @param string[] $source     Segments from the source post.
	 * @param string   $lang       Target language slug.
	 * @return string[]
	 */
	private static function match_untranslated( array $translated, array $source, string $lang ): array {
		$matched = array();
		foreach ( $translated as $t_segment ) {
			foreach ( $source as $s_segment ) {
				if ( Elementor_Fields::text_untranslated( $s_segment, $t_segment, $lang ) ) {
					$matched[] = $t_segment;
					break;
				}
			}
		}

		return $matched;
	}

	/**
	 * Unique, non-trivial text segments of post content.
	 *
	 * @since 2.3.0
	 *
	 * @param string $content Raw post content or HTML.
	 * @return string[]
	 */
	public static function segments( string $content ): array {
		if ( '' === trim( $content ) ) {
			return array();
		}

		$html  = has_blocks( $content ) ? $content : wpautop( $content );
		$html  = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$found = array();

		foreach ( (array) preg_split( self::SEGMENT_SPLIT_PATTERN, $html ) as $part ) {
			$text = self::normalize_text( (string) $part );
			if ( '' !== $text && ! self::is_trivial( $text ) ) {
				$found[ $text ] = true;
			}
		}

		return array_keys( $found );
	}

	/**
	 * Strips markup and collapses whitespace so equal text compares equal.
	 *
	 * @since 2.3.0
	 *
	 * @param string $text Raw text or HTML.
	 * @return string
	 */
	public static function normalize_text( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Text that is identical on purpose: numbers, symbols, shortcodes, URLs, emails, one letter.
	 *
	 * @since 2.3.0
	 *
	 * @param string $text Normalized text.
	 * @return bool
	 */
	private static function is_trivial( string $text ): bool {
		return Elementor_Fields::skip_same_text( '', $text ) || ! preg_match( '/\p{L}.*\p{L}/u', $text );
	}

	/**
	 * Editor link for a post.
	 *
	 * @since 2.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function edit_url( int $post_id ): string {
		return (string) get_edit_post_link( $post_id, 'url' );
	}

	/**
	 * Adds the start of the text to an editor link so the block editor can select it.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url  Editor URL.
	 * @param string $text Normalized text.
	 * @return string
	 */
	private static function with_focus_text( string $url, string $text ): string {
		if ( '' === $url || '' === $text ) {
			return $url;
		}

		return add_query_arg( Frontend_Inspector::FOCUS_TEXT_ARG, rawurlencode( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 150 ) : substr( $text, 0, 150 ) ), $url );
	}
}
