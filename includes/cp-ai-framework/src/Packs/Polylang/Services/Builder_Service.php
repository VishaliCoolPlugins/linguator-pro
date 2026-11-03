<?php
/**
 * Page-builder content extraction and write-back for the abilities subsystem.
 *
 * Page builders keep their layout in post meta, not in post_content. For an
 * Elementor page the tree lives in _elementor_data and post_content only holds
 * a flattened render that Elementor rewrites on save. Translating post_content
 * therefore produces a page that loses its layout entirely, because Elementor
 * never reads it back.
 *
 * This service reuses AutoPoly's own code wherever a reusable entry point
 * exists, so the abilities cannot drift from the editor and bulk flows:
 *
 *   - ATFPP_Helper::get_post_editor_type()          detection
 *   - ATFPP_Helper::compare_divi_version()          Divi 5 gate
 *   - ATFPP_Helper::replace_links_with_translations() internal link remapping
 *
 * Two things could not be reused, and both are deliberate:
 *
 * 1. WHICH STRINGS ARE TRANSLATABLE. These rules live only in JavaScript, in
 *    includes/src/automatic-translate/src/store-source-string/elementor/index.js
 *    and .../create-translated-post/elementor/index.js. There is no PHP
 *    equivalent to call, so CSS_PROPERTIES, DYNAMIC_SUBSTRINGS, STATIC_KEYS and
 *    ATOMIC_KEYS below are a port of those files and MUST be updated together
 *    with them. If the editor starts translating a new control and this list is
 *    not updated, an MCP translation will silently leave that control in the
 *    source language.
 *
 * 2. THE SAVE ITSELF. AutoPoly has no shared save routine to call:
 *    ATFP_Posts_Clone::update_elementor_data() is private and
 *    ATFP_Ajax_Handler::update_elementor_data() reads $_POST directly. Both
 *    inline the same two Elementor API calls, which is what apply_strings()
 *    does here. Saving through the document API rather than update_post_meta()
 *    is what regenerates the per-post CSS.
 *
 * @package AutoPoly
 * @since   1.11.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Services;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes page-builder content for the abilities subsystem.
 *
 * @since 1.11.0
 */
final class Builder_Service {

	/**
	 * Separator joining the segments of a string's path into the element tree.
	 *
	 * Matches the delimiter used by the editor bundle so paths are portable
	 * between the two flows.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const PATH_DELIMITER = '_atfp_';

	/**
	 * Builder slug for Elementor.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const BUILDER_ELEMENTOR = 'elementor';

	/**
	 * Outcome of set_by_path() when the string was written.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const APPLIED = 'applied';

	/**
	 * The path does not exist in the element tree any more.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const SKIP_PATH_NOT_FOUND = 'path_not_found';

	/**
	 * The path resolves to a nested structure rather than a piece of text.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const SKIP_NOT_A_STRING = 'not_a_string';

	/**
	 * The caller's "source" did not match the stored text, so nothing was written.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const SKIP_SOURCE_MISMATCH = 'source_mismatch';

	/**
	 * The string is a builder setting (an animation name, yes/no, a font style), not copy.
	 *
	 * @since 1.14.0
	 * @var string
	 */
	const SKIP_NOT_TRANSLATABLE = 'not_translatable';

	/**
	 * The translation sent equals the text already stored, so there was nothing to write.
	 *
	 * @since 1.14.0
	 * @var string
	 */
	const UNCHANGED = 'unchanged';

	/**
	 * Parts of a setting key that mark a single-word value as a setting, not copy.
	 *
	 * Used together with a single-word ASCII value, so real copy under a key such
	 * as "title" is never caught.
	 *
	 * @since 1.14.0
	 * @var string[]
	 */
	const NON_COPY_KEY_PARTS = array(
		'animation',
		'font_style',
		'typography',
		'shadow_type',
		'html_tag',
		'title_tag',
		'header_size',
		'_style',
		'_effect',
		'_transition',
		'_direction',
	);

	/**
	 * Single-word values that are builder settings wherever they appear under a non-copy key.
	 *
	 * Lowercase only: copy such as "Custom" or "Center" starts with a capital.
	 *
	 * @since 1.14.0
	 * @var string[]
	 */
	const NON_COPY_VALUES = array(
		'yes',
		'no',
		'true',
		'false',
		'custom',
		'italic',
		'normal',
		'oblique',
		'inherit',
		'initial',
		'none',
		'default',
		'solid',
		'dashed',
		'dotted',
		'double',
		'block',
		'inline',
		'flex',
		'grid',
		'left',
		'right',
		'center',
		'top',
		'bottom',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'div',
		'span',
		'p',
		'a',
	);

	/**
	 * Setting keys that always hold copy, so a value-only rule never applies to them.
	 *
	 * @since 1.14.0
	 * @var string[]
	 */
	const COPY_KEYS = array(
		'title',
		'text',
		'label',
		'description',
		'editor',
		'heading',
		'sub_heading',
		'caption',
		'placeholder',
		'paragraph',
		'name',
	);

	/**
	 * Builder slug for Divi.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const BUILDER_DIVI = 'divi';

	/**
	 * Storage mode: the page lives in post meta, away from post_content.
	 *
	 * Callers translate the extracted builder_strings.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const STORAGE_META = 'meta';

	/**
	 * Storage mode: the page lives in post_content itself.
	 *
	 * Callers translate the content field as usual.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const STORAGE_CONTENT = 'content';

	/**
	 * Setting keys that carry styling rather than copy.
	 *
	 * Matched as substrings, so 'title_color' and 'content_width' are excluded
	 * while 'title' and 'editor' survive. Must stay lowercase.
	 *
	 * @since 1.11.0
	 * @var string[]
	 */
	const CSS_PROPERTIES = array(
		'content_width',
		'title_size',
		'font_size',
		'margin',
		'padding',
		'background',
		'border',
		'color',
		'text_align',
		'font_weight',
		'font_family',
		'line_height',
		'letter_spacing',
		'text_transform',
		'border_radius',
		'box_shadow',
		'opacity',
		'width',
		'height',
		'display',
		'position',
		'z_index',
		'visibility',
		'align',
		'max_width',
		'content_typography_typography',
		'flex_justify_content',
		'title_color',
		'description_color',
		'email_content',
	);

	/**
	 * Substrings marking a setting key as translatable copy.
	 *
	 * @since 1.11.0
	 * @var string[]
	 */
	const DYNAMIC_SUBSTRINGS = array( 'title', 'description', 'editor', 'text', 'content', 'label' );

	/**
	 * Setting keys that are translatable only on an exact match.
	 *
	 * @since 1.11.0
	 * @var string[]
	 */
	const STATIC_KEYS = array(
		'caption',
		'heading',
		'sub_heading',
		'testimonial_content',
		'testimonial_job',
		'testimonial_name',
		'name',
	);

	/**
	 * Atomic-widget keys translatable regardless of the substring rules.
	 *
	 * @since 1.11.0
	 * @var string[]
	 */
	const ATOMIC_KEYS = array( 'placeholder', 'paragraph' );

	/**
	 * Post meta copied onto a translation so Elementor recognises it as a page.
	 *
	 * @since 1.11.0
	 * @var string[]
	 */
	const SEEDED_META_KEYS = array( '_elementor_template_type', '_elementor_page_settings', '_elementor_version' );

	/**
	 * Most links find_unresolved_links() lists, so one broken menu cannot flood the result.
	 *
	 * @since 1.13.0
	 * @var int
	 */
	const MAX_UNRESOLVED_LINKS = 20;

	/**
	 * Most string ids a write report lists, so a large page cannot flood the result.
	 *
	 * @since 1.14.0
	 * @var int
	 */
	const MAX_REPORTED_IDS = 25;

	/**
	 * Unresolved link: the address belongs to a post in another language.
	 *
	 * @since 1.13.0
	 * @var string
	 */
	const LINK_OTHER_LANGUAGE_POST = 'other_language_post';

	/**
	 * Unresolved link: the address is in another language but is not a post,
	 * such as an archive, a taxonomy page, a redirect or a custom route.
	 *
	 * @since 1.13.0
	 * @var string
	 */
	const LINK_UNRECOGNISED_ADDRESS = 'unrecognised_address';

	/**
	 * Returns the page builder a post was built with.
	 *
	 * Deliberately gated the same way AutoPoly's own translation flow is, so the
	 * abilities never claim to handle a builder the plugin itself does not. In
	 * particular Divi is recognised only from version 5, matching the
	 * compare_divi_version() check in ATFP_Register_Route::get_post_data(): Divi
	 * 4 stores its layout as shortcodes, which AutoPoly does not translate, so a
	 * Divi 4 page must fall through to the ordinary content path.
	 *
	 * @since 1.11.0
	 * @param int $post_id Post ID.
	 * @return string Builder slug, or '' for a post no supported builder owns.
	 */
	public static function detect_editor_type( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! class_exists( 'ATFPP_Helper' ) || ! method_exists( 'ATFPP_Helper', 'get_post_editor_type' ) ) {
			return '';
		}

		// AutoPoly's own detector, so the abilities can never disagree with it.
		$detected = \ATFPP_Helper::get_post_editor_type( $post_id );

		if ( self::BUILDER_ELEMENTOR === $detected ) {
			return self::BUILDER_ELEMENTOR;
		}

		/*
		 * get_post_editor_type() reports Divi without a version check, but the
		 * translation flow in ATFP_Register_Route::get_post_data() gates on
		 * compare_divi_version(). Applying the same gate here keeps the abilities
		 * aligned with what AutoPoly will actually translate: Divi 4 stores its
		 * layout as shortcodes and must fall through to the ordinary content path.
		 */
		if ( self::BUILDER_DIVI === $detected && method_exists( 'ATFPP_Helper', 'compare_divi_version' ) && \ATFPP_Helper::compare_divi_version() ) {
			return self::BUILDER_DIVI;
		}

		// 'block' and 'classic' are not builders and need no special handling.
		return '';
	}

	/**
	 * Returns where a builder keeps the page, which decides what to translate.
	 *
	 * @since 1.11.0
	 * @param string $editor_type Builder slug from detect_editor_type().
	 * @return string One of the STORAGE_* constants, or '' when unknown.
	 */
	public static function get_storage_mode( $editor_type ) {
		switch ( (string) $editor_type ) {
			case self::BUILDER_ELEMENTOR:
				return self::STORAGE_META;

			case self::BUILDER_DIVI:
				// Divi 5 stores Gutenberg-style blocks in post_content itself.
				return self::STORAGE_CONTENT;

			default:
				return '';
		}
	}

	/**
	 * Prepares translated post_content for a builder that renders from it.
	 *
	 * Divi 5 will not render its blocks unless they sit inside the placeholder
	 * wrapper, so a caller that returns clean block markup would otherwise
	 * produce a blank page. Mirrors the wrapping done for the bulk flow in
	 * ATFP_Register_Route::create_translate_post().
	 *
	 * @since 1.11.0
	 * @param string $content        Translated post content.
	 * @param int    $source_post_id Source post ID.
	 * @return string Content, wrapped when the builder requires it.
	 */
	public static function prepare_content( $content, $source_post_id ) {
		$content = (string) $content;

		if ( self::BUILDER_DIVI !== self::detect_editor_type( $source_post_id ) ) {
			return $content;
		}

		if ( '' === trim( $content ) ) {
			return $content;
		}

		// Already wrapped, either by the caller or by copying the source.
		if ( false !== strpos( $content, 'wp:divi/placeholder' ) ) {
			return $content;
		}

		$utils = '\ET\Builder\Migration\Utils\MigrationUtils';

		if ( class_exists( $utils ) && method_exists( $utils, 'ensure_placeholder_wrapper' ) ) {
			return $utils::ensure_placeholder_wrapper( $content );
		}

		return "<!-- wp:divi/placeholder -->\n" . $content . "\n<!-- /wp:divi/placeholder -->";
	}

	/**
	 * Returns a post's Elementor element tree.
	 *
	 * Prefers Elementor's document API, which is what the editor flow reads, and
	 * falls back to the raw meta so the abilities keep working if the API moves.
	 *
	 * @since 1.11.0
	 * @param int $post_id Post ID.
	 * @return array<int, mixed> Element tree, empty when the post has none.
	 */
	public static function get_elements( $post_id ) {
		$post_id = absint( $post_id );

		if ( self::BUILDER_ELEMENTOR !== self::detect_editor_type( $post_id ) ) {
			return array();
		}

		if ( class_exists( '\Elementor\Plugin' ) && property_exists( '\Elementor\Plugin', 'instance' ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id );

			if ( $document ) {
				$elements = $document->get_elements_data();

				if ( is_array( $elements ) ) {
					return $elements;
				}
			}
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( is_array( $raw ) ) {
			return $raw;
		}

		$decoded = json_decode( (string) $raw, true );

		return ( is_array( $decoded ) && JSON_ERROR_NONE === json_last_error() ) ? $decoded : array();
	}

	/**
	 * Returns every translatable string in a post's builder content.
	 *
	 * @since 1.11.0
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, string>> String descriptors.
	 */
	public static function get_strings( $post_id ) {
		return self::extract_strings( self::get_elements( absint( $post_id ) ) );
	}

	/**
	 * Walks an element tree and collects its translatable strings.
	 *
	 * @since 1.11.0
	 * @param array<int, mixed> $elements Element tree.
	 * @return array<int, array<string, string>> String descriptors.
	 */
	public static function extract_strings( array $elements ) {
		$found = array();

		foreach ( array_values( $elements ) as $index => $element ) {
			self::walk_element( $element, $index, array(), $found );
		}

		return $found;
	}

	/**
	 * Collects translatable strings from one element, then recurses into it.
	 *
	 * @since 1.11.0
	 * @param mixed                             $element Element node.
	 * @param int                               $index   Index within its parent.
	 * @param array<int, string|int>            $ids     Path segments so far.
	 * @param array<int, array<string, string>> $found   Accumulator, by reference.
	 * @return void
	 */
	private static function walk_element( $element, $index, array $ids, array &$found ) {
		if ( ! is_array( $element ) ) {
			return;
		}

		$ids[]     = $index;
		$widget_id = isset( $element['id'] ) ? (string) $element['id'] : '';
		$settings  = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

		$widget_type = '';

		if ( isset( $element['widgetType'] ) ) {
			$widget_type = (string) $element['widgetType'];
		} elseif ( isset( $element['elType'] ) ) {
			$widget_type = (string) $element['elType'];
		}

		foreach ( $settings as $key => $value ) {
			$key = (string) $key;

			if ( self::is_css_property( $key ) ) {
				continue;
			}

			$path = array_merge( $ids, array( 'settings', $key ) );

			if ( self::is_translatable_key( $key ) && is_string( $value ) && '' !== trim( $value ) ) {
				self::add_string( $found, $path, $value, $widget_id, $widget_type, $key );
				continue;
			}

			if ( is_array( $value ) && isset( $value['$$type'] ) ) {
				self::walk_atomic( $value, $path, $found, $widget_id, $widget_type );
				continue;
			}

			if ( is_array( $value ) && ! empty( $value ) ) {
				self::walk_repeater( $value, $path, $found, $widget_id, $widget_type );
			}
		}

		if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$child_ids = array_merge( $ids, array( 'elements' ) );

			foreach ( array_values( $element['elements'] ) as $child_index => $child ) {
				self::walk_element( $child, $child_index, $child_ids, $found );
			}
		}
	}

	/**
	 * Collects translatable strings from a repeater control's rows.
	 *
	 * Repeater keys are excluded on an exact CSS-property match rather than a
	 * substring one, matching the editor bundle.
	 *
	 * @since 1.11.0
	 * @param array<int, mixed>                 $rows        Repeater rows.
	 * @param array<int, string|int>            $path        Path to the repeater.
	 * @param array<int, array<string, string>> $found       Accumulator, by reference.
	 * @param string                            $widget_id   Owning widget ID.
	 * @param string                            $widget_type Owning widget type.
	 * @return void
	 */
	private static function walk_repeater( array $rows, array $path, array &$found, $widget_id, $widget_type ) {
		foreach ( array_values( $rows ) as $row_index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			foreach ( $row as $row_key => $row_value ) {
				$row_key = (string) $row_key;

				if ( in_array( strtolower( $row_key ), self::CSS_PROPERTIES, true ) ) {
					continue;
				}

				$row_path = array_merge( $path, array( $row_index, $row_key ) );

				if ( self::is_translatable_key( $row_key ) && is_string( $row_value ) && '' !== trim( $row_value ) ) {
					self::add_string( $found, $row_path, $row_value, $widget_id, $widget_type, $row_key );
					continue;
				}

				if ( is_array( $row_value ) && isset( $row_value['$$type'] ) ) {
					self::walk_atomic( $row_value, $row_path, $found, $widget_id, $widget_type );
				}
			}
		}
	}

	/**
	 * Collects the string carried by an atomic (v4) widget control.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed>              $element     Atomic control value.
	 * @param array<int, string|int>            $ids         Path to the control.
	 * @param array<int, array<string, string>> $found       Accumulator, by reference.
	 * @param string                            $widget_id   Owning widget ID.
	 * @param string                            $widget_type Owning widget type.
	 * @return void
	 */
	private static function walk_atomic( array $element, array $ids, array &$found, $widget_id, $widget_type ) {
		$current_key = (string) end( $ids );

		if ( ! self::is_translatable_key( $current_key ) && ! in_array( $current_key, self::ATOMIC_KEYS, true ) ) {
			return;
		}

		$type = isset( $element['$$type'] ) ? (string) $element['$$type'] : '';

		if ( 'html-v3' === $type ) {
			$content = isset( $element['value']['content'] ) && is_array( $element['value']['content'] )
				? $element['value']['content']
				: array();

			$is_string_node = isset( $content['$$type'] ) && 'string' === $content['$$type'];

			if ( $is_string_node && isset( $content['value'] ) && '' !== $content['value'] ) {
				self::add_string(
					$found,
					array_merge( $ids, array( 'value', 'content', 'value' ) ),
					(string) $content['value'],
					$widget_id,
					$widget_type,
					$current_key
				);
			}

			return;
		}

		if ( 'string' === $type && isset( $element['value'] ) && '' !== $element['value'] ) {
			self::add_string(
				$found,
				array_merge( $ids, array( 'value' ) ),
				(string) $element['value'],
				$widget_id,
				$widget_type,
				$current_key
			);
		}
	}

	/**
	 * Records one translatable string.
	 *
	 * @since 1.11.0
	 * @param array<int, array<string, string>> $found       Accumulator, by reference.
	 * @param array<int, string|int>            $path        Path segments.
	 * @param mixed                             $value       Candidate string.
	 * @param string                            $widget_id   Owning widget ID.
	 * @param string                            $widget_type Owning widget type.
	 * @param string                            $field       Setting key.
	 * @return void
	 */
	private static function add_string( array &$found, array $path, $value, $widget_id, $widget_type, $field ) {
		if ( ! is_string( $value ) || '' === trim( $value ) || empty( $path ) ) {
			return;
		}

		// A bare URL or address is the same in every language and must not be sent.
		if ( self::is_email_or_url( $value ) ) {
			return;
		}

		$entry = array(
			'id'          => implode( self::PATH_DELIMITER, $path ),
			'value'       => $value,
			'widget_id'   => $widget_id,
			'widget_type' => $widget_type,
			'field'       => $field,
		);

		// Only flagged when it is not copy, so the common entry keeps its small shape.
		$reason = self::non_copy_reason( $field, $value );

		if ( '' !== $reason ) {
			$entry['translate'] = false;
			$entry['reason']    = $reason;
		}

		$found[] = $entry;
	}

	/**
	 * Why a string must not be translated, or '' when it is copy.
	 *
	 * Deliberately narrow: wrongly flagging a real sentence would make it
	 * untranslatable, so only a single-word ASCII value is ever considered.
	 *
	 * @since 1.14.0
	 * @param string $field Setting key.
	 * @param string $value Candidate string.
	 * @return string Reason text, or '' when the string is copy.
	 */
	private static function non_copy_reason( $field, $value ) {
		$field = strtolower( (string) $field );
		$value = trim( (string) $value );

		if ( 1 !== preg_match( '/^[A-Za-z0-9_\-]+$/', $value ) ) {
			return '';
		}

		$message = __( 'This is a page-builder setting (an animation, style or yes/no value), not text. Translating it would break the widget, so leave it unchanged.', 'autopoly-ai-translation-for-polylang-pro' );

		foreach ( self::NON_COPY_KEY_PARTS as $part ) {
			if ( false !== strpos( $field, $part ) ) {
				return $message;
			}
		}

		if ( in_array( $value, self::NON_COPY_VALUES, true ) && ! in_array( $field, self::COPY_KEYS, true ) ) {
			return $message;
		}

		return '';
	}

	/**
	 * Text prepared for comparing two copies of the same string.
	 *
	 * Decodes HTML entities, folds non-breaking spaces and collapses whitespace,
	 * so a copy retyped with an ordinary space still matches.
	 *
	 * @since 1.14.0
	 * @param string $text Text.
	 * @return string Normalised text.
	 */
	private static function normalize_for_compare( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\u{00A0}", ' ', $text );
		$out  = preg_replace( '/\s+/u', ' ', $text );

		return trim( null === $out ? $text : $out );
	}

	/**
	 * Whether a value is nothing but an email address or a URL.
	 *
	 * @since 1.11.0
	 * @param string $value Candidate string.
	 * @return bool True when the value should not be translated.
	 */
	private static function is_email_or_url( $value ) {
		$value = trim( $value );

		if ( 1 === preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $value ) ) {
			return true;
		}

		$url_pattern = '%^(https?://)?([\w-]+\.)+[\w-]+(/[^\s"<>]*)?$%';

		return 1 === preg_match( $url_pattern, $value );
	}

	/**
	 * Whether a setting key carries styling rather than copy.
	 *
	 * @since 1.11.0
	 * @param string $key Setting key.
	 * @return bool True when the key must be skipped.
	 */
	private static function is_css_property( $key ) {
		$key = strtolower( $key );

		foreach ( self::CSS_PROPERTIES as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a setting key names translatable copy.
	 *
	 * @since 1.11.0
	 * @param string $key Setting key.
	 * @return bool True when the key holds copy.
	 */
	private static function is_translatable_key( $key ) {
		$lower = strtolower( $key );

		foreach ( self::DYNAMIC_SUBSTRINGS as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) {
				return true;
			}
		}

		return in_array( $key, self::STATIC_KEYS, true );
	}

	/**
	 * Writes translated builder strings onto a translation.
	 *
	 * The source tree is used as the template so the translation inherits every
	 * layout, style and structural setting, and only the collected strings are
	 * replaced.
	 *
	 * @since 1.11.0
	 * @param int                      $target_post_id Translation post ID.
	 * @param int                      $source_post_id Source post ID.
	 * @param array<int|string, mixed> $translations   Translated strings.
	 * @param string                   $target_lang    Target language slug.
	 * @param string                   $source_lang    Source language slug.
	 * @return array<string, mixed>|WP_Error Result summary, or an error.
	 */
	public static function apply_strings( $target_post_id, $source_post_id, array $translations, $target_lang = '', $source_lang = '' ) {
		$target_post_id = absint( $target_post_id );
		$source_post_id = absint( $source_post_id );

		if ( ! class_exists( '\Elementor\Plugin' ) || ! property_exists( '\Elementor\Plugin', 'instance' ) ) {
			return new WP_Error(
				'atfpp_elementor_inactive',
				__( 'Elementor is not active, so builder content could not be written.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		$elements = self::get_elements( $source_post_id );

		if ( empty( $elements ) ) {
			return new WP_Error(
				'atfpp_no_builder_content',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d has no Elementor content to translate.', 'autopoly-ai-translation-for-polylang-pro' ),
					$source_post_id
				)
			);
		}

		/*
		 * The translation is built from the source layout, so any string not sent
		 * in this call stays in the source language. Counting what was extracted
		 * lets the result say how many were left, instead of only how many were
		 * written.
		 */
		$translatable = array();
		$locked       = array();

		foreach ( self::extract_strings( $elements ) as $descriptor ) {
			if ( isset( $descriptor['translate'] ) && false === $descriptor['translate'] ) {
				$locked[ $descriptor['id'] ] = true;
			} else {
				$translatable[ $descriptor['id'] ] = true;
			}
		}

		$applied   = 0;
		$unchanged = array();
		$ignored   = array();
		$sent      = array();
		$skipped   = array();
		$details   = array();

		foreach ( self::normalize_translations( $translations ) as $entry ) {
			$sent[ $entry['id'] ] = true;

			// Never write a setting such as an animation name or yes/no: it would break the widget.
			if ( isset( $locked[ $entry['id'] ] ) ) {
				$ignored[] = $entry['id'];
				continue;
			}

			$outcome = self::set_by_path( $elements, $entry['id'], $entry['translation'], $entry['source'] );

			if ( self::APPLIED === $outcome ) {
				++$applied;
				continue;
			}

			if ( self::UNCHANGED === $outcome ) {
				$unchanged[] = $entry['id'];
				continue;
			}

			// The id list keeps its original shape; the reasons are additive.
			$skipped[] = $entry['id'];
			$details[] = array(
				'id'     => $entry['id'],
				'reason' => $outcome,
				'hint'   => self::skip_hint( $outcome ),
			);
		}

		$not_sent = array_keys( array_diff_key( $translatable, $sent ) );

		$links_by_path = array();
		$elements      = self::remap_links( $elements, $target_lang, $source_lang, $links_by_path );

		// The layout is rebuilt from the source, so keep the menus a translated header or footer was already given.
		list( $elements, $kept_menus ) = Template_Service::keep_existing_menus( $elements, self::get_elements( $target_post_id ) );

		self::seed_builder_meta( $source_post_id, $target_post_id );

		/*
		 * Signals AutoPoly's own hooks that a builder translation is in flight,
		 * exactly as the editor's AJAX save does.
		 */
		if ( ! defined( 'DOING_ATFPP_ELEMENTOR_PAGE_TRANSLATION' ) ) {
			define( 'DOING_ATFPP_ELEMENTOR_PAGE_TRANSLATION', true );
		}

		$plugin   = \Elementor\Plugin::$instance;
		$document = $plugin->documents->get( $target_post_id );

		if ( ! $document ) {
			return new WP_Error(
				'atfpp_no_document',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Elementor could not open post %d for saving.', 'autopoly-ai-translation-for-polylang-pro' ),
					$target_post_id
				)
			);
		}

		/*
		 * Saving through the document API rather than update_post_meta() is what
		 * regenerates the per-post CSS file. Writing the meta directly leaves the
		 * translation styled by the source page's stale stylesheet.
		 */
		$document->save( array( 'elements' => $elements ) );

		if ( isset( $plugin->files_manager ) ) {
			$plugin->files_manager->clear_cache();
		}

		update_post_meta( $target_post_id, '_atfp_elementor_translated', 'true' );

		$result = array(
			'editor_type'       => 'elementor',
			'strings_applied'   => $applied,
			'strings_skipped'   => $skipped,
			'strings_unchanged' => count( $unchanged ),
			'strings_total'     => count( $translatable ),
			'strings_not_sent'  => count( $not_sent ),
		);

		if ( array() !== $details ) {
			$result['skipped_details'] = $details;
		}

		// Ids only, capped: enough to find them in get-content without flooding the result.
		if ( array() !== $unchanged ) {
			$result['unchanged_ids'] = array_slice( $unchanged, 0, self::MAX_REPORTED_IDS );
		}

		if ( array() !== $ignored ) {
			$result['strings_ignored']      = $ignored;
			$result['strings_ignored_note'] = self::skip_hint( self::SKIP_NOT_TRANSLATABLE );
		}

		if ( array() !== $not_sent ) {
			$result['not_sent_ids'] = array_slice( $not_sent, 0, self::MAX_REPORTED_IDS );
		}

		if ( array() !== $kept_menus ) {
			$result['menu_widgets_kept'] = $kept_menus;
		}

		if ( array() !== $links_by_path ) {
			$result['links_resolved_by_page_path'] = $links_by_path;
		}

		$unresolved = self::find_unresolved_links( $elements, $target_lang );

		if ( array() !== $unresolved ) {
			$result['links_unresolved'] = $unresolved;
		}

		return $result;
	}

	/**
	 * Normalises caller-supplied translations into a single shape.
	 *
	 * Accepts either a list of { id, translation, source? } objects or a plain
	 * map of path => translation, because both are natural for an MCP client.
	 *
	 * @since 1.11.0
	 * @param array<int|string, mixed> $translations Raw input.
	 * @return array<int, array<string, string|null>> Normalised entries.
	 */
	private static function normalize_translations( array $translations ) {
		$entries = array();

		foreach ( $translations as $key => $value ) {
			if ( is_array( $value ) ) {
				$id = isset( $value['id'] ) ? (string) $value['id'] : (string) $key;

				if ( ! isset( $value['translation'] ) || ! is_string( $value['translation'] ) ) {
					continue;
				}

				$entries[] = array(
					'id'          => $id,
					'translation' => $value['translation'],
					'source'      => isset( $value['source'] ) && is_string( $value['source'] ) ? $value['source'] : null,
				);

				continue;
			}

			if ( is_string( $value ) && is_string( $key ) ) {
				$entries[] = array(
					'id'          => $key,
					'translation' => $value,
					'source'      => null,
				);
			}
		}

		return $entries;
	}

	/**
	 * Replaces one string in the element tree, addressed by its path.
	 *
	 * @since 1.11.0
	 * @param array<int|string, mixed> $tree   Element tree, by reference.
	 * @param string                   $path   Delimited path to the string.
	 * @param string                   $value  Translated string.
	 * @param string|null              $source Expected current value, when known.
	 * @return string APPLIED, or the SKIP_* constant explaining the refusal.
	 */
	private static function set_by_path( array &$tree, $path, $value, $source = null ) {
		$parts = explode( self::PATH_DELIMITER, (string) $path );

		if ( empty( $parts ) ) {
			return self::SKIP_PATH_NOT_FOUND;
		}

		$last    = array_pop( $parts );
		$current = &$tree;

		foreach ( $parts as $part ) {
			if ( ! is_array( $current ) || ! array_key_exists( $part, $current ) ) {
				return self::SKIP_PATH_NOT_FOUND;
			}

			$current = &$current[ $part ];
		}

		if ( ! is_array( $current ) || ! array_key_exists( $last, $current ) ) {
			return self::SKIP_PATH_NOT_FOUND;
		}

		// Only ever overwrite copy, never a nested structure.
		if ( ! is_string( $current[ $last ] ) ) {
			return self::SKIP_NOT_A_STRING;
		}

		/*
		 * When the caller echoes the source string back, verify it still matches
		 * before writing. This is what stops a translation generated against an
		 * older revision from landing on unrelated copy.
		 */
		if ( null !== $source && $current[ $last ] !== $source && self::normalize_for_compare( $current[ $last ] ) !== self::normalize_for_compare( $source ) ) {
			return self::SKIP_SOURCE_MISMATCH;
		}

		if ( $current[ $last ] === $value ) {
			return self::UNCHANGED;
		}

		$current[ $last ] = $value;

		return self::APPLIED;
	}

	/**
	 * Human-readable explanation and remedy for a skip reason.
	 *
	 * The reasons need opposite fixes -- a stale path means re-reading the post,
	 * a source mismatch means the copy was not echoed back byte for byte -- so
	 * reporting only that a string was skipped leaves the caller guessing.
	 *
	 * @since 1.11.0
	 * @param string $reason One of the SKIP_* constants.
	 * @return string Translated hint.
	 */
	private static function skip_hint( $reason ) {
		if ( self::SKIP_SOURCE_MISMATCH === $reason ) {
			return __( 'The "source" you supplied does not match the text currently stored at this path, so the write was refused. Copy "value" verbatim from get-content rather than retyping it: builder copy often contains non-breaking spaces or HTML entities that look identical but are different characters. If you cannot copy it exactly, omit "source".', 'autopoly-ai-translation-for-polylang-pro' );
		}

		if ( self::SKIP_NOT_TRANSLATABLE === $reason ) {
			return __( 'This is a page-builder setting, not text, so it was left as it is. get-content marks such entries translate: false.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		if ( self::UNCHANGED === $reason ) {
			return __( 'The translation is identical to the stored text, so nothing was written. That is right for a brand name or a word that is the same in the target language; otherwise translate it.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		if ( self::SKIP_NOT_A_STRING === $reason ) {
			return __( 'This path points at a nested structure rather than a piece of text, so it was not overwritten. Use an id exactly as given in builder_strings.', 'autopoly-ai-translation-for-polylang-pro' );
		}

		return __( 'This path no longer exists in the page. The layout has probably changed since get-content was called; read the post again and translate the current strings.', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * Points internal links at their translated equivalents.
	 *
	 * @since 1.11.0
	 * @param array<int, mixed>                  $elements      Element tree.
	 * @param string                             $target_lang   Target language slug.
	 * @param string                             $source_lang   Source language slug.
	 * @param array<int, array<string, string>> $links_by_path Links resolved by page path, by reference.
	 * @return array<int, mixed> Element tree, remapped where possible.
	 */
	private static function remap_links( array $elements, $target_lang, $source_lang, array &$links_by_path = array() ) {
		$can_remap = class_exists( 'ATFPP_Helper' )
			&& method_exists( 'ATFPP_Helper', 'replace_links_with_translations' )
			&& '' !== (string) $target_lang
			&& '' !== (string) $source_lang;

		if ( ! $can_remap ) {
			return $elements;
		}

		$json = wp_json_encode( $elements );

		if ( is_string( $json ) && '' !== $json ) {
			$remapped = \ATFPP_Helper::replace_links_with_translations( $json, $target_lang, $source_lang );

			if ( is_string( $remapped ) && '' !== $remapped ) {
				$decoded = json_decode( $remapped, true );

				if ( is_array( $decoded ) && JSON_ERROR_NONE === json_last_error() ) {
					$elements = $decoded;
				}
			}
		}

		$cache    = array();
		$elements = self::remap_relative_links( $elements, $target_lang, $source_lang, $cache );

		if ( ! function_exists( 'pll_get_post' ) || ! function_exists( 'pll_get_post_language' ) ) {
			return $elements;
		}

		$path_cache = array();

		return self::remap_page_path_links( $elements, $target_lang, $source_lang, $path_cache, $links_by_path );
	}

	/**
	 * Points links that name a page by its path at that page's translation, when the passes above could not.
	 *
	 * Those passes resolve a link through url_to_postid(), which returns 0 when
	 * the path is also claimed by a rewrite rule matched before the page's own,
	 * such as a "docs" page sharing its slug with the "docs" post type archive.
	 * Such a link kept leading to the source-language page. Runs last, so it
	 * only ever sees links still in the source language, and changes one only
	 * when every check in translate_page_path_url() passes.
	 *
	 * @since 1.13.0
	 * @param array<int|string, mixed>           $tree          Element tree, or a branch of it.
	 * @param string                             $target_lang   Target language slug.
	 * @param string                             $source_lang   Source language slug.
	 * @param array<string, string>              $cache         Resolved links, shared across the walk.
	 * @param array<int, array<string, string>> $links_by_path Links changed here, by reference.
	 * @return array<int|string, mixed> The tree, remapped where possible.
	 */
	private static function remap_page_path_links( array $tree, $target_lang, $source_lang, array &$cache, array &$links_by_path ) {
		foreach ( $tree as $key => $value ) {
			if ( is_array( $value ) ) {
				$tree[ $key ] = self::remap_page_path_links( $value, $target_lang, $source_lang, $cache, $links_by_path );
				continue;
			}

			if ( ! is_string( $value ) || false === strpos( $value, '/' ) ) {
				continue;
			}

			if ( 'url' === $key ) {
				$tree[ $key ] = self::translate_page_path_url( $value, $target_lang, $source_lang, $cache, $links_by_path );
				continue;
			}

			if ( false === stripos( $value, 'href' ) ) {
				continue;
			}

			$replaced = preg_replace_callback(
				'~(href\s*=\s*)(["\'])([^"\']+)\2~i',
				function ( $match ) use ( $target_lang, $source_lang, &$cache, &$links_by_path ) {
					return $match[1] . $match[2] . self::translate_page_path_url( $match[3], $target_lang, $source_lang, $cache, $links_by_path ) . $match[2];
				},
				$value
			);

			if ( is_string( $replaced ) ) {
				$tree[ $key ] = $replaced;
			}
		}

		return $tree;
	}

	/**
	 * Returns the translation's address for a link that is exactly a source-language page's address.
	 *
	 * Leaves the link unchanged unless all of these hold: it points at this
	 * site; its path names a published page; that page is in the source
	 * language; the link is that page's own permalink; and the page has a
	 * published translation in the target language. The form the author chose,
	 * absolute or root-relative, and any query string or #fragment are kept.
	 *
	 * @since 1.13.0
	 * @param string                             $url           Link as stored in the page.
	 * @param string                             $target_lang   Target language slug.
	 * @param string                             $source_lang   Source language slug.
	 * @param array<string, string>              $cache         Resolved links.
	 * @param array<int, array<string, string>> $links_by_path Links changed here, by reference.
	 * @return string The translated link, or the URL unchanged.
	 */
	private static function translate_page_path_url( $url, $target_lang, $source_lang, array &$cache, array &$links_by_path ) {
		if ( isset( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}

		$cache[ $url ] = $url;
		$home          = untrailingslashit( get_home_url() );

		// Root-relative only: "//host/path" is protocol-relative and points elsewhere.
		$relative = '' !== $url && '/' === $url[0] && ! ( isset( $url[1] ) && '/' === $url[1] );

		if ( ! $relative && 0 !== stripos( $url, $home . '/' ) ) {
			return $url;
		}

		$absolute = $relative ? $home . $url : $url;
		$split    = strcspn( $absolute, '?#' );
		$base     = (string) substr( $absolute, 0, $split );
		$suffix   = (string) substr( $absolute, $split );
		$path     = trim( (string) substr( $base, strlen( $home ) ), '/' );

		if ( '' === $path || 0 === strpos( $path, 'wp-content/' ) ) {
			return $url;
		}

		$page = get_page_by_path( rawurldecode( $path ), OBJECT, 'page' );

		if ( ! $page instanceof WP_Post || 'publish' !== $page->post_status ) {
			return $url;
		}

		if ( (string) pll_get_post_language( $page->ID, 'slug' ) !== (string) $source_lang ) {
			return $url;
		}

		$permalink = get_permalink( $page );

		if ( ! is_string( $permalink ) || untrailingslashit( rawurldecode( $permalink ) ) !== untrailingslashit( rawurldecode( $base ) ) ) {
			return $url;
		}

		$translated_id = (int) pll_get_post( $page->ID, $target_lang );

		if ( $translated_id <= 0 || 'publish' !== get_post_status( $translated_id ) ) {
			return $url;
		}

		$link = get_permalink( $translated_id );

		if ( ! is_string( $link ) || '' === $link ) {
			return $url;
		}

		// Same form as ATFPP_Helper::replace_links_with_translations() returns.
		$link = esc_url( urldecode_deep( $link ) );

		if ( $relative ) {
			if ( 0 !== strpos( $link, $home ) ) {
				return $url;
			}

			$link = (string) substr( $link, strlen( $home ) );
			$link = '' === $link ? '/' : $link;
		}

		$result        = $link . $suffix;
		$cache[ $url ] = $result;

		$links_by_path[] = array(
			'from' => $url,
			'to'   => $result,
		);

		return $result;
	}

	/**
	 * Lists internal links that still lead to content in a language other than the target.
	 *
	 * Read only: nothing is changed. Every site routes addresses differently,
	 * through SEO redirects, post type archives, custom routes and the like,
	 * and AutoPoly can only point a link at a translation when the address
	 * belongs to a translated post. Reporting what is left tells the caller
	 * where to look instead of letting a half-translated page pass as done.
	 *
	 * A link counts as another language's when its address carries that
	 * language (or no language, where the default language is hidden from
	 * URLs), or when it belongs to a post in that language. Where the address
	 * cannot say, as when Polylang takes the language from the content, only
	 * the post check applies. Links off this site, fragments, the home page and
	 * files under wp-content are never listed.
	 *
	 * @since 1.13.0
	 * @param array<int|string, mixed> $tree        Element tree, or any array of strings holding HTML.
	 * @param string                   $target_lang Target language slug.
	 * @return array<int, array<string, mixed>> Unresolved links, at most MAX_UNRESOLVED_LINKS.
	 */
	public static function find_unresolved_links( array $tree, $target_lang ) {
		$target_lang = (string) $target_lang;

		if ( '' === $target_lang || ! function_exists( 'pll_get_post_language' ) || ! function_exists( 'pll_get_post' ) ) {
			return array();
		}

		$urls = array();
		self::collect_link_values( $tree, $urls );

		$found = array();

		foreach ( array_keys( $urls ) as $url ) {
			$entry = self::classify_link( (string) $url, $target_lang );

			if ( null === $entry ) {
				continue;
			}

			$found[] = $entry;

			if ( count( $found ) >= self::MAX_UNRESOLVED_LINKS ) {
				break;
			}
		}

		return $found;
	}

	/**
	 * Collects every link address in a tree: Elementor "url" controls and href attributes in copy.
	 *
	 * @since 1.13.0
	 * @param array<int|string, mixed> $tree Element tree, or a branch of it.
	 * @param array<string, bool>      $urls Addresses found, as keys so each is listed once, by reference.
	 * @return void
	 */
	private static function collect_link_values( array $tree, array &$urls ) {
		foreach ( $tree as $key => $value ) {
			if ( is_array( $value ) ) {
				self::collect_link_values( $value, $urls );
				continue;
			}

			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			if ( 'url' === $key ) {
				$urls[ $value ] = true;
				continue;
			}

			if ( false !== stripos( $value, 'href' ) && preg_match_all( '~href\s*=\s*(["\'])([^"\']+)\1~i', $value, $matches ) ) {
				foreach ( $matches[2] as $href ) {
					$urls[ html_entity_decode( $href, ENT_QUOTES ) ] = true;
				}
			}
		}
	}

	/**
	 * Decides whether one link still leads to another language, and why.
	 *
	 * @since 1.13.0
	 * @param string $url         Link as stored.
	 * @param string $target_lang Target language slug.
	 * @return array<string, mixed>|null An unresolved-link entry, or null when the link is fine or cannot be judged.
	 */
	private static function classify_link( $url, $target_lang ) {
		$home = untrailingslashit( get_home_url() );

		// Root-relative only: "//host/path" is protocol-relative and points elsewhere.
		$relative = '' !== $url && '/' === $url[0] && ! ( isset( $url[1] ) && '/' === $url[1] );

		if ( ! $relative && 0 !== stripos( $url, $home . '/' ) ) {
			return null;
		}

		$absolute = $relative ? $home . $url : $url;
		// The fragment never affects which content an address serves; the query string can (?page_id=, ?lang=).
		$absolute = (string) substr( $absolute, 0, strcspn( $absolute, '#' ) );
		$path     = trim( (string) wp_parse_url( substr( $absolute, strlen( $home ) ), PHP_URL_PATH ), '/' );
		$query    = (string) wp_parse_url( $absolute, PHP_URL_QUERY );

		if ( '' === $path && '' === $query ) {
			return null;
		}

		foreach ( array( 'wp-content/', 'wp-admin/', 'wp-json/', 'wp-includes/' ) as $system_path ) {
			if ( 0 === strpos( $path . '/', $system_path ) ) {
				return null;
			}
		}

		$url_lang = self::language_from_url( $absolute );

		if ( $url_lang === $target_lang ) {
			return null;
		}

		$post_id = (int) url_to_postid( $absolute );

		if ( $post_id > 0 ) {
			$post_lang = (string) pll_get_post_language( $post_id, 'slug' );

			// A post type Polylang does not translate has no language, and needs no translated link.
			if ( '' === $post_lang || $post_lang === $target_lang ) {
				return null;
			}

			$entry = array(
				'url'      => $url,
				'reason'   => self::LINK_OTHER_LANGUAGE_POST,
				'post_id'  => $post_id,
				'language' => $post_lang,
			);

			$translation_id = (int) pll_get_post( $post_id, $target_lang );

			if ( $translation_id > 0 ) {
				$entry['translation_id']     = $translation_id;
				$entry['translation_status'] = (string) get_post_status( $translation_id );
				$entry['explanation']        = __( 'A translation exists but is not published yet. Publish it, then translate this post again.', 'autopoly-ai-translation-for-polylang-pro' );
			} else {
				$entry['explanation'] = __( 'No translation exists yet for the page this link points to. Translate that page, then translate this post again.', 'autopoly-ai-translation-for-polylang-pro' );
			}

			return $entry;
		}

		// The address is not a post. Only list it when its own language is known to differ.
		if ( '' === $url_lang ) {
			return null;
		}

		return array(
			'url'         => $url,
			'reason'      => self::LINK_UNRECOGNISED_ADDRESS,
			'language'    => $url_lang,
			'explanation' => __( 'This address is on this site but is not a post or page (an archive, a redirect or a custom route), so there is nothing to translate it to. It was left unchanged.', 'autopoly-ai-translation-for-polylang-pro' ),
		);
	}

	/**
	 * The language an address is in, going by the address alone.
	 *
	 * @since 1.13.0
	 * @param string $absolute Absolute URL on this site.
	 * @return string Language slug, or '' when the address does not say.
	 */
	private static function language_from_url( $absolute ) {
		if ( ! function_exists( 'PLL' ) ) {
			return '';
		}

		$polylang = PLL();

		if ( ! isset( $polylang->links_model ) || ! is_object( $polylang->links_model ) || ! method_exists( $polylang->links_model, 'get_language_from_url' ) ) {
			return '';
		}

		$slug = (string) $polylang->links_model->get_language_from_url( $absolute );

		if ( '' !== $slug ) {
			return $slug;
		}

		$options    = isset( $polylang->options ) ? $polylang->options : array();
		$force_lang = isset( $options['force_lang'] ) ? (int) $options['force_lang'] : 0;
		$hides      = ! empty( $options['hide_default'] );

		// Only when the language is part of the address, and the default language is left out of it, does no code mean the default language.
		if ( $force_lang > 0 && $hides && function_exists( 'pll_default_language' ) ) {
			return (string) pll_default_language( 'slug' );
		}

		return '';
	}

	/**
	 * Points root-relative internal links, such as "/blog/" or "/#features", at their translations.
	 *
	 * replace_links_with_translations() only recognises absolute URLs on the
	 * site's own domain, so a link entered as a path kept leading to the
	 * source-language page. Each path is resolved as an absolute URL through
	 * that same helper and written back as a path, keeping the form the author
	 * chose. Covers Elementor link controls ("url") and href attributes inside
	 * HTML copy.
	 *
	 * @since 1.12.0
	 * @param array<int|string, mixed> $tree        Element tree, or a branch of it.
	 * @param string                   $target_lang Target language slug.
	 * @param string                   $source_lang Source language slug.
	 * @param array<string, string>    $cache       Resolved paths, shared across the walk.
	 * @return array<int|string, mixed> The tree, remapped where possible.
	 */
	private static function remap_relative_links( array $tree, $target_lang, $source_lang, array &$cache ) {
		foreach ( $tree as $key => $value ) {
			if ( is_array( $value ) ) {
				$tree[ $key ] = self::remap_relative_links( $value, $target_lang, $source_lang, $cache );
				continue;
			}

			if ( ! is_string( $value ) || false === strpos( $value, '/' ) ) {
				continue;
			}

			if ( 'url' === $key ) {
				$tree[ $key ] = self::translate_relative_url( $value, $target_lang, $source_lang, $cache );
				continue;
			}

			if ( false === stripos( $value, 'href' ) ) {
				continue;
			}

			$replaced = preg_replace_callback(
				'~(href\s*=\s*)(["\'])(/(?!/)[^"\']*)\2~i',
				function ( $match ) use ( $target_lang, $source_lang, &$cache ) {
					return $match[1] . $match[2] . self::translate_relative_url( $match[3], $target_lang, $source_lang, $cache ) . $match[2];
				},
				$value
			);

			if ( is_string( $replaced ) ) {
				$tree[ $key ] = $replaced;
			}
		}

		return $tree;
	}

	/**
	 * Returns the translated equivalent of one root-relative URL, still root-relative.
	 *
	 * The query string and #fragment are set aside while the path is resolved,
	 * because url_to_postid() discards the fragment and the helper would return
	 * the bare permalink, turning "/#features" into a link to the top of the page.
	 *
	 * @since 1.12.0
	 * @param string                $url         URL as stored in the page.
	 * @param string                $target_lang Target language slug.
	 * @param string                $source_lang Source language slug.
	 * @param array<string, string> $cache       Resolved paths.
	 * @return string The translated path, or the URL unchanged.
	 */
	private static function translate_relative_url( $url, $target_lang, $source_lang, array &$cache ) {
		// Root-relative only: "//host/path" is protocol-relative and points elsewhere.
		if ( '' === $url || '/' !== $url[0] || ( isset( $url[1] ) && '/' === $url[1] ) ) {
			return $url;
		}

		if ( isset( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}

		$split  = strcspn( $url, '?#' );
		$path   = (string) substr( $url, 0, $split );
		$suffix = (string) substr( $url, $split );

		$home       = untrailingslashit( get_home_url() );
		$absolute   = $home . $path;
		$translated = \ATFPP_Helper::replace_links_with_translations( $absolute, $target_lang, $source_lang );
		$result     = $url;

		if ( is_string( $translated ) && $translated !== $absolute && 0 === strpos( $translated, $home ) ) {
			$relative = (string) substr( $translated, strlen( $home ) );
			$result   = ( '' === $relative ? '/' : $relative ) . $suffix;
		}

		$cache[ $url ] = $result;

		return $result;
	}

	/**
	 * Copies the meta Elementor needs before a translation can be saved.
	 *
	 * A translation created by pll_insert_post() is a plain post. Without these
	 * keys Elementor does not treat it as one of its own documents and the saved
	 * tree is never rendered.
	 *
	 * @since 1.11.0
	 * @param int $source_post_id Source post ID.
	 * @param int $target_post_id Translation post ID.
	 * @return void
	 */
	private static function seed_builder_meta( $source_post_id, $target_post_id ) {
		foreach ( self::SEEDED_META_KEYS as $key ) {
			$existing = get_post_meta( $target_post_id, $key, true );

			if ( '' !== $existing && array() !== $existing && null !== $existing ) {
				continue;
			}

			$value = get_post_meta( $source_post_id, $key, true );

			if ( '' === $value || array() === $value || null === $value ) {
				continue;
			}

			// update_post_meta() unslashes, so round-trip the value unchanged.
			update_post_meta( $target_post_id, $key, wp_slash( $value ) );
		}

		update_post_meta( $target_post_id, '_elementor_edit_mode', 'builder' );
	}
}
