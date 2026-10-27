<?php
/**
 * Reads translatable text and links out of Elementor documents.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knows which widget settings hold visible text, links and repeater rows.
 *
 * @since 2.3.0
 */
class Elementor_Fields {

	/**
	 * Widgets nested deeper than this are ignored.
	 */
	const MAX_DEPTH = 50;

	/**
	 * Text settings by widget type.
	 *
	 * @since 2.3.0
	 *
	 * @return array<string,string[]>
	 */
	public static function simple_fields(): array {
		$fields = array(
			'heading'               => array( 'title', 'sub_title' ),
			'text-editor'           => array( 'editor' ),
			'button'                => array( 'text' ),
			'icon-box'              => array( 'title_text', 'description_text' ),
			'image-box'             => array( 'title_text', 'description_text' ),
			'alert'                 => array( 'alert_title', 'alert_description' ),
			'call-to-action'        => array( 'title', 'description', 'button' ),
			'testimonial'           => array( 'testimonial_content', 'testimonial_name', 'testimonial_job' ),
			'counter'               => array( 'title', 'prefix', 'suffix' ),
			'progress'              => array( 'title' ),
			'star-rating'           => array( 'title' ),
			'html'                  => array( 'html' ),
			'shortcode'             => array( 'shortcode' ),
			'divider'               => array( 'text' ),
			'read-more'             => array( 'text' ),
			'price-table'           => array( 'heading', 'sub_heading', 'button_text', 'footer_additional_info' ),
			'flip-box'              => array( 'title_text_a', 'description_text_a', 'title_text_b', 'description_text_b', 'button_text' ),
			'animated-headline'     => array( 'before_text', 'highlighted_text', 'rotating_text', 'after_text' ),
			'loop-grid'             => array( 'pagination_prev_label', 'pagination_next_label', 'text', 'load_more_no_posts_custom_message' ),
			'loop-carousel'         => array( 'pagination_prev_label', 'pagination_next_label', 'text', 'load_more_no_posts_custom_message' ),
			// Elementor v4 atomic widgets wrap their text in a typed value.
			'e-heading'             => array( 'title' ),
			'e-paragraph'           => array( 'paragraph' ),
			'e-button'              => array( 'text' ),
			'e-form-submit-button'  => array( 'text' ),
			'e-form-input'          => array( 'placeholder' ),
			'e-form-textarea'       => array( 'placeholder' ),
			'e-form-label'          => array( 'text' ),
			'e-image'               => array( 'image.src.alt' ),
		);

		/**
		 * Filters the text settings the site scan reads from Elementor widgets.
		 *
		 * @since 2.3.0
		 *
		 * @param array $fields Widget type => setting keys.
		 */
		return (array) apply_filters( 'lmat_scan_elementor_simple_fields', $fields );
	}

	/**
	 * Link settings by widget type.
	 *
	 * @since 2.3.0
	 *
	 * @return array<string,string[]>
	 */
	public static function link_fields(): array {
		$fields = array();
		foreach ( array( 'button', 'heading', 'icon', 'icon-box', 'image-box', 'image', 'call-to-action', 'read-more', 'flip-box', 'price-table' ) as $widget ) {
			$fields[ $widget ] = array( 'link' );
		}

		/**
		 * Filters the link settings the site scan reads from Elementor widgets.
		 *
		 * @since 2.3.0
		 *
		 * @param array $fields Widget type => setting keys.
		 */
		return (array) apply_filters( 'lmat_scan_elementor_link_fields', $fields );
	}

	/**
	 * Repeater settings by widget type, with the sub settings that hold text or links.
	 *
	 * @since 2.3.0
	 *
	 * @return array<string,array<string,string[]>>
	 */
	public static function repeater_fields(): array {
		$fields = array(
			'icon-list'       => array( 'icon_list' => array( 'text', 'link' ) ),
			'tabs'            => array( 'tabs' => array( 'tab_title', 'tab_content' ) ),
			'accordion'       => array( 'tabs' => array( 'tab_title', 'tab_content' ) ),
			'toggle'          => array( 'tabs' => array( 'tab_title', 'tab_content' ) ),
			'slides'          => array( 'slides' => array( 'heading', 'description', 'button_text', 'link' ) ),
			'price-list'      => array( 'price_list' => array( 'title', 'item_description', 'price', 'link' ) ),
			'price-table'     => array( 'features_list' => array( 'item_text' ) ),
			'social-icons'    => array( 'social_icon_list' => array( 'link' ) ),
		);

		/**
		 * Filters the repeater settings the site scan reads from Elementor widgets.
		 *
		 * @since 2.3.0
		 *
		 * @param array $fields Widget type => repeater key => sub setting keys.
		 */
		return (array) apply_filters( 'lmat_scan_elementor_repeater_fields', $fields );
	}

	/**
	 * Decodes the element tree of a document.
	 *
	 * @since 2.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return array Element tree, empty when the document has none or it is invalid.
	 */
	public static function tree( int $post_id ): array {
		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_array( $raw ) ? $raw : ( is_string( $raw ) && '' !== trim( $raw ) ? json_decode( $raw, true, 128 ) : null );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Whether a post is laid out with Elementor.
	 *
	 * @since 2.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_built( int $post_id ): bool {
		return '' !== trim( (string) get_post_meta( $post_id, '_elementor_data', true ) );
	}

	/**
	 * Number of widgets in a document.
	 *
	 * @since 2.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function count_widgets( int $post_id ): int {
		$count = 0;
		$stack = array( array( self::tree( $post_id ), 0 ) );

		while ( $stack ) {
			list( $elements, $depth ) = array_pop( $stack );
			if ( $depth > self::MAX_DEPTH ) {
				continue;
			}
			foreach ( $elements as $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}
				$count += empty( $element['widgetType'] ) ? 0 : 1;
				if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
					$stack[] = array( $element['elements'], $depth + 1 );
				}
			}
		}

		return $count;
	}

	/**
	 * Whether a document holds at least one widget.
	 *
	 * @since 2.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_widgets( int $post_id ): bool {
		return self::tree_has_widget( self::tree( $post_id ), 0 );
	}

	/**
	 * Whether an element tree holds a widget.
	 *
	 * @since 2.3.0
	 *
	 * @param array $elements Element tree.
	 * @param int   $depth    Current depth.
	 * @return bool
	 */
	private static function tree_has_widget( array $elements, int $depth ): bool {
		if ( $depth > self::MAX_DEPTH ) {
			return false;
		}
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( ! empty( $element['widgetType'] ) || ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && self::tree_has_widget( $element['elements'], $depth + 1 ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Widgets of a document keyed by element ID, each with its text, link and repeater row counts.
	 *
	 * @since 2.3.0
	 *
	 * @param array $elements Element tree.
	 * @param array $map      Result, by reference.
	 * @param int   $depth    Current depth.
	 * @return array<string,array{widget_type:string,fields:array<string,string>,links:array<string,string>,repeaters:array<string,int>}>
	 */
	public static function element_map( array $elements, array &$map = array(), int $depth = 0 ): array {
		if ( $depth > self::MAX_DEPTH ) {
			return $map;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$widget = (string) ( $element['widgetType'] ?? '' );
			if ( '' !== $widget && ! empty( $element['id'] ) ) {
				$extracted = self::extract( $element );
				// Retain empty widgets so the scanner can compare missing translated text.
				$map[ (string) $element['id'] ] = array( 'widget_type' => $widget ) + $extracted;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::element_map( $element['elements'], $map, $depth + 1 );
			}
		}

		return $map;
	}

	/**
	 * Text, links and repeater row counts of one widget.
	 *
	 * @since 2.3.0
	 *
	 * @param array $element Widget element.
	 * @return array{fields:array<string,string>,links:array<string,string>,repeaters:array<string,int>,loops:array<string,int>}
	 */
	public static function extract( array $element ): array {
		$widget   = (string) ( $element['widgetType'] ?? '' );
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$out      = array(
			'fields'    => array(),
			'links'     => array(),
			'repeaters' => array(),
			'loops'     => array(),
		);

		if ( in_array( $widget, array( 'loop-grid', 'loop-carousel' ), true ) ) {
			if ( isset( $settings['template_id'] ) && is_numeric( $settings['template_id'] ) && (int) $settings['template_id'] > 0 ) {
				$out['loops']['template_id'] = (int) $settings['template_id'];
			}
			foreach ( (array) ( $settings['alternate_templates'] ?? array() ) as $index => $row ) {
				if ( is_array( $row ) && isset( $row['template_id'] ) && is_numeric( $row['template_id'] ) && (int) $row['template_id'] > 0 ) {
					$out['loops'][ 'alternate_templates[' . (int) $index . '].template_id' ] = (int) $row['template_id'];
				}
			}
		}

		foreach ( self::simple_fields()[ $widget ] ?? array() as $key ) {
			$value = self::setting_text( $settings, $key );
			if ( '' !== $value ) {
				$out['fields'][ $key ] = $value;
			}
		}

		foreach ( self::link_fields()[ $widget ] ?? array() as $key ) {
			$url = self::link_url( $settings[ $key ] ?? null );
			if ( '' !== $url ) {
				$out['links'][ $key ] = $url;
			}
		}

		foreach ( self::repeater_fields()[ $widget ] ?? array() as $repeater => $sub_keys ) {
			$rows = is_array( $settings[ $repeater ] ?? null ) ? $settings[ $repeater ] : array();
			$out['repeaters'][ $repeater ] = count( $rows );

			foreach ( $rows as $index => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				foreach ( $sub_keys as $sub_key ) {
					$field_key = $repeater . '[' . $index . '].' . $sub_key;
					if ( self::is_link_key( $sub_key ) ) {
						$url = self::link_url( $row[ $sub_key ] ?? null );
						if ( '' !== $url ) {
							$out['links'][ $field_key ] = $url;
						}
						continue;
					}
					$value = self::setting_text( $row, $sub_key );
					if ( '' !== $value ) {
						$out['fields'][ $field_key ] = $value;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Visible text of a setting, with markup stripped.
	 *
	 * Handles Elementor v4 atomic values, which are stored as `{ "$$type": ..., "value": ... }`.
	 *
	 * @since 2.3.0
	 *
	 * @param array  $settings Settings.
	 * @param string $key      Setting key; a dotted path reaches into atomic values.
	 * @return string
	 */
	public static function setting_text( array $settings, string $key ): string {
		if ( false !== strpos( $key, '.' ) ) {
			$value = $settings;
			foreach ( explode( '.', $key ) as $segment ) {
				if ( is_array( $value ) && isset( $value['$$type'], $value['value'] ) && is_array( $value['value'] ) ) {
					$value = $value['value'];
				}
				if ( ! is_array( $value ) || ! isset( $value[ $segment ] ) ) {
					return '';
				}
				$value = $value[ $segment ];
			}
		} elseif ( isset( $settings[ $key ] ) ) {
			$value = $settings[ $key ];
		} else {
			return '';
		}

		if ( is_array( $value ) && isset( $value['$$type'], $value['value'] ) ) {
			$inner = $value['value'];
			if ( is_array( $inner ) && ( array_key_exists( 'content', $inner ) || array_key_exists( 'children', $inner ) ) ) {
				$inner = isset( $inner['content']['value'] ) && is_string( $inner['content']['value'] ) ? trim( $inner['content']['value'] ) : '';
			}
			if ( is_string( $inner ) ) {
				$value = (string) preg_replace( '#<br\s*/?>#i', ' ', $inner );
			}
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' ) );

		return '&nbsp;' === $text ? '' : $text;
	}

	/**
	 * URL held by a link setting.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $value Raw setting.
	 * @return string
	 */
	public static function link_url( $value ): string {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( is_array( $value ) && isset( $value['url'] ) && is_scalar( $value['url'] ) ) {
			return trim( (string) $value['url'] );
		}
		// Atomic link: { "$$type": "link", "value": { "destination": { "$$type": "url", "value": "..." } } }.
		if ( is_array( $value ) && isset( $value['value']['destination']['$$type'], $value['value']['destination']['value'] ) && 'url' === $value['value']['destination']['$$type'] && is_string( $value['value']['destination']['value'] ) ) {
			return trim( $value['value']['destination']['value'] );
		}

		return '';
	}

	/**
	 * Whether a setting key names a link.
	 *
	 * @since 2.3.0
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_link_key( string $key ): bool {
		return (bool) preg_match( '/(^|_)link$/', strtolower( $key ) );
	}

	/**
	 * Whether a translated value still holds the original text.
	 *
	 * Same text, same letters without punctuation, or for longer text very similar text.
	 *
	 * @since 2.3.0
	 *
	 * @param string $field_key  Field key.
	 * @param string $original   Source text.
	 * @param string $translated Translated text.
	 * @param string $target_lang Target language slug, used to spot text in the wrong script.
	 * @return bool
	 */
	public static function is_untranslated( string $field_key, string $original, string $translated, string $target_lang = '' ): bool {
		if ( '' === $translated || self::skip_same_text( $field_key, $original ) ) {
			return false;
		}

		return self::text_untranslated( $original, $translated, $target_lang );
	}

	/**
	 * Whether translated text is the original text or close to it.
	 *
	 * Same text, same letters without punctuation, text in the wrong script, or for longer text very similar text.
	 * Does not look at which setting the text came from.
	 *
	 * @since 2.3.0
	 *
	 * @param string $original    Source text.
	 * @param string $translated  Translated text.
	 * @param string $target_lang Target language slug.
	 * @return bool
	 */
	public static function text_untranslated( string $original, string $translated, string $target_lang = '' ): bool {
		$a = trim( (string) preg_replace( '/\s+/u', ' ', $original ) );
		$b = trim( (string) preg_replace( '/\s+/u', ' ', $translated ) );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b ) {
			return true;
		}

		$lower = function_exists( 'mb_strtolower' ) ? 'mb_strtolower' : 'strtolower';
		$clean = static function ( string $text ) use ( $lower ): string {
			return (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', $lower( $text ) );
		};
		if ( '' !== $clean( $a ) && $clean( $a ) === $clean( $b ) ) {
			return true;
		}

		if ( '' !== $target_lang && self::is_wrong_script( $a, $b, $target_lang ) ) {
			return true;
		}

		// Short text is judged by equality only, so cognates are not reported.
		$words  = preg_split( '/\s+/u', $a, -1, PREG_SPLIT_NO_EMPTY );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $a, 'UTF-8' ) : strlen( $a );
		if ( $length < 20 || count( (array) $words ) < 3 ) {
			return false;
		}

		similar_text( $lower( $a ), $lower( $b ), $percent );

		return $percent >= 80;
	}

	/**
	 * Whether a translation is written in Latin letters although the language uses another script, while sharing
	 * its words with the Latin source. A Hindi translation with no Devanagari that reuses the English words is not
	 * a translation.
	 *
	 * @since 2.3.0
	 *
	 * @param string $original    Source text.
	 * @param string $translated  Translated text.
	 * @param string $target_lang Target language slug.
	 * @return bool
	 */
	public static function is_wrong_script( string $original, string $translated, string $target_lang ): bool {
		$prefix   = (string) preg_replace( '/[-_].*$/', '', strtolower( trim( $target_lang ) ) );
		$patterns = array(
			'hi' => '/\p{Devanagari}/u',
			'mr' => '/\p{Devanagari}/u',
			'ne' => '/\p{Devanagari}/u',
			'ar' => '/\p{Arabic}/u',
			'ur' => '/\p{Arabic}/u',
			'fa' => '/\p{Arabic}/u',
			'ru' => '/\p{Cyrillic}/u',
			'uk' => '/\p{Cyrillic}/u',
			'bg' => '/\p{Cyrillic}/u',
			'el' => '/\p{Greek}/u',
			'he' => '/\p{Hebrew}/u',
			'zh' => '/\p{Han}/u',
			'ja' => '/[\p{Hiragana}\p{Katakana}\p{Han}]/u',
			'ko' => '/\p{Hangul}/u',
			'th' => '/\p{Thai}/u',
			'bn' => '/\p{Bengali}/u',
			'ta' => '/\p{Tamil}/u',
			'te' => '/\p{Telugu}/u',
			'gu' => '/\p{Gujarati}/u',
			'pa' => '/\p{Gurmukhi}/u',
		);

		if ( ! isset( $patterns[ $prefix ] ) || preg_match( $patterns[ $prefix ], $translated ) || ! preg_match( '/\p{L}/u', $translated ) ) {
			return false;
		}
		if ( ! preg_match( '/\p{Latin}/u', $original ) || ! preg_match( '/\p{Latin}/u', $translated ) ) {
			return false;
		}

		$lower = function_exists( 'mb_strtolower' ) ? 'mb_strtolower' : 'strtolower';
		$a     = preg_split( '/[\s\p{P}]+/u', $lower( $original ), -1, PREG_SPLIT_NO_EMPTY );
		$b     = preg_split( '/[\s\p{P}]+/u', $lower( $translated ), -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $a ) || empty( $b ) ) {
			return false;
		}

		return ( count( array_intersect( $b, $a ) ) / max( 1, count( $a ) ) ) >= 0.30;
	}

	/**
	 * Values that read the same in every language and are never reported as untranslated.
	 *
	 * @since 2.3.0
	 *
	 * @param string $field_key Field key.
	 * @param string $value     Text.
	 * @return bool
	 */
	public static function skip_same_text( string $field_key, string $value ): bool {
		$base = strtolower( (string) preg_replace( '/^.*\./', '', $field_key ) );
		if ( in_array( $base, array( 'shortcode', 'html', 'prefix', 'suffix', 'price' ), true ) || self::is_link_key( $base ) ) {
			return true;
		}

		$value = trim( $value );

		return '' === $value
			|| (bool) preg_match( '/^\[.+\]$/s', $value )
			|| (bool) preg_match( '/^\{\{.+\}\}$/s', $value )
			|| (bool) preg_match( '/^[\d\s\.\,\%\$\x{20AC}\x{00A3}\x{00A5}\/\-\+]+$/u', $value )
			|| (bool) preg_match( '#^(?:https?:)?//\S+$#i', $value )
			|| (bool) preg_match( '#^www\.\S+$#i', $value )
			|| is_email( $value );
	}

	/**
	 * Shortens text for a label, cutting at a character limit.
	 *
	 * @since 2.3.0
	 *
	 * @param string $text  Text.
	 * @param int    $limit Characters to keep.
	 * @return string
	 */
	public static function snippet( string $text, int $limit ): string {
		$text   = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		if ( $length <= $limit ) {
			return $text;
		}

		return ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit - 1, 'UTF-8' ) : substr( $text, 0, $limit - 1 ) ) . '…';
	}

	/**
	 * Turns a detail row into the "same text as the original language" card for one Elementor widget.
	 *
	 * @since 2.3.0
	 *
	 * @param string $widget_type Widget type.
	 * @param string $field_key   Field key.
	 * @param string $text        Text that still matches the original.
	 * @param string $post_title  Title of the page or template that holds the widget.
	 * @param string $language    Language name.
	 * @param array  $row         Base detail row.
	 * @return array
	 */
	public static function same_text_row( string $widget_type, string $field_key, string $text, string $post_title, string $language, array $row ): array {
		$row['layout']       = 'widget';
		$row['label']        = sprintf(
			/* translators: 1: widget name, 2: its text, 3: page or template title, 4: language name */
			__( '%1$s "%2$s" in "%3$s" (%4$s)', 'linguator-pro' ),
			self::widget_label( $widget_type ),
			self::snippet( $text, 40 ),
			'' !== $post_title ? $post_title : __( '(no title)', 'linguator-pro' ),
			$language
		);
		$row['message']      = sprintf(
			/* translators: 1: the text, 2: language name */
			__( 'This widget text shows "%1$s". It still matches the default language and has not been translated into %2$s yet.', 'linguator-pro' ),
			self::snippet( $text, 90 ),
			$language
		);
		$row['action_label'] = __( 'Translate in Elementor', 'linguator-pro' );

		return $row;
	}

	/**
	 * Raw settings of every element, keyed by element ID.
	 *
	 * @since 2.3.0
	 *
	 * @param array $elements Element tree.
	 * @param array $map      Map built so far.
	 * @return array<string,array>
	 */
	public static function raw_settings( array $elements, array $map = array() ): array {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( ! empty( $element['id'] ) && is_array( $element['settings'] ?? null ) ) {
				$map[ (string) $element['id'] ] = $element['settings'];
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$map = self::raw_settings( $element['elements'], $map );
			}
		}

		return $map;
	}

	/**
	 * Raw setting value for a field key such as `title` or `tabs[0].tab_title`.
	 *
	 * @since 2.3.0
	 *
	 * @param array  $settings_map Element ID => settings.
	 * @param string $element_id   Element ID.
	 * @param string $field_key    Field key.
	 * @return string
	 */
	public static function raw_value( array $settings_map, string $element_id, string $field_key ): string {
		$value = $settings_map[ $element_id ] ?? array();
		$path  = preg_match( '/^([^\[]+)\[(\d+)\]\.(.+)$/', $field_key, $parts ) ? array( $parts[1], (int) $parts[2], $parts[3] ) : array( $field_key );

		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
				return '';
			}
			$value = $value[ $key ];
		}

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Where a field sits in a widget, such as "Tabs #2 › Tab title".
	 *
	 * @since 2.3.0
	 *
	 * @param string $field_key Field key.
	 * @return string
	 */
	public static function location_label( string $field_key ): string {
		if ( preg_match( '/^([^\[]+)\[(\d+)\]\.(.+)$/', $field_key, $parts ) ) {
			return sprintf(
				/* translators: 1: list name, 2: row number, 3: field name */
				__( '%1$s #%2$d › %3$s', 'linguator-pro' ),
				ucwords( str_replace( array( '-', '_' ), ' ', $parts[1] ) ),
				(int) $parts[2] + 1,
				self::field_label( $parts[3] )
			);
		}

		return self::field_label( $field_key );
	}

	/**
	 * Readable name of a widget type.
	 *
	 * @since 2.3.0
	 *
	 * @param string $widget Widget type.
	 * @return string
	 */
	public static function widget_label( string $widget ): string {
		$labels = array(
			'text-editor'          => __( 'Text Editor', 'linguator-pro' ),
			'icon-box'             => __( 'Icon Box', 'linguator-pro' ),
			'image-box'            => __( 'Image Box', 'linguator-pro' ),
			'call-to-action'       => __( 'Call to Action', 'linguator-pro' ),
			'price-table'          => __( 'Price Table', 'linguator-pro' ),
			'price-list'           => __( 'Price List', 'linguator-pro' ),
			'icon-list'            => __( 'Icon List', 'linguator-pro' ),
			'star-rating'          => __( 'Star Rating', 'linguator-pro' ),
			'e-heading'            => __( 'Heading', 'linguator-pro' ),
			'e-paragraph'          => __( 'Paragraph', 'linguator-pro' ),
			'e-button'             => __( 'Button', 'linguator-pro' ),
			'e-form-submit-button' => __( 'Form Button', 'linguator-pro' ),
			'e-form-input'         => __( 'Form Field', 'linguator-pro' ),
			'e-form-textarea'      => __( 'Form Field', 'linguator-pro' ),
			'e-form-label'         => __( 'Form Label', 'linguator-pro' ),
			'e-image'              => __( 'Image', 'linguator-pro' ),
		);

		return $labels[ $widget ] ?? ucwords( str_replace( array( '-', '_' ), ' ', $widget ) );
	}

	/**
	 * Readable name of a setting.
	 *
	 * @since 2.3.0
	 *
	 * @param string $field_key Field key, which may include a repeater row.
	 * @return string
	 */
	public static function field_label( string $field_key ): string {
		$base   = (string) preg_replace( '/^.*\./', '', $field_key );
		$labels = array(
			'title'               => __( 'Title', 'linguator-pro' ),
			'sub_title'           => __( 'Subtitle', 'linguator-pro' ),
			'editor'              => __( 'Content', 'linguator-pro' ),
			'text'                => __( 'Text', 'linguator-pro' ),
			'title_text'          => __( 'Title', 'linguator-pro' ),
			'description_text'    => __( 'Description', 'linguator-pro' ),
			'description'         => __( 'Description', 'linguator-pro' ),
			'button'              => __( 'Button text', 'linguator-pro' ),
			'button_text'         => __( 'Button text', 'linguator-pro' ),
			'tab_title'           => __( 'Tab title', 'linguator-pro' ),
			'tab_content'         => __( 'Tab content', 'linguator-pro' ),
			'heading'             => __( 'Heading', 'linguator-pro' ),
			'item_text'           => __( 'Feature text', 'linguator-pro' ),
			'alt'                 => __( 'Image alt text', 'linguator-pro' ),
			'paragraph'           => __( 'Paragraph', 'linguator-pro' ),
			'placeholder'         => __( 'Placeholder', 'linguator-pro' ),
		);

		return $labels[ $base ] ?? ucwords( str_replace( array( '-', '_' ), ' ', $base ) );
	}
}
