<?php
/**
 * Diagnostics for Linguator's built-in Elementor template integration.
 *
 * @package Linguator
 */
namespace LinguatorPro\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Modules\Scan\Abstract_Check;
use LinguatorPro\Modules\Scan\Elementor_Fields;
use LinguatorPro\Modules\Scan\Freshness;
use LinguatorPro\Modules\Scan\Frontend_Inspector;
use LinguatorPro\Modules\Scan\Link_Tools;
use LinguatorPro\Modules\Scan\Result;

class Elementor extends Abstract_Check {
	use Link_Tools;

	const DOCUMENT_LIMIT = 200;
	const DETAIL_LIMIT = 100;
	protected $section = 'elementor';
	protected $group = 'Elementor';

	public static function available(): bool {
		return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\Elementor\Plugin' ) || did_action( 'elementor/loaded' ) > 0;
	}

	/** Parse the actual element tree, rather than matching text in widget settings. */
	public static function contains_switcher( array $elements, int $depth = 0 ): bool {
		if ( $depth > 50 ) {
			return false;
		}
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( 'lmat_widget' === ( $element['widgetType'] ?? '' ) ) {
				return true;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && self::contains_switcher( $element['elements'], $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Template counts by document type, for the Elementor insights panel.
	 *
	 * @var array{checked:int,types:array<string,int>,sampled:bool}
	 */
	private $summary = array( 'checked' => 0, 'widgets' => 0, 'types' => array(), 'sampled' => false );

	public function extra(): array {
		return $this->summary['checked'] > 0 && ! $this->row_keys && null === $this->only ? array( 'elementor' => $this->summary ) : array();
	}

	public function run(): array {
		$this->summary = array( 'checked' => 0, 'widgets' => 0, 'types' => array(), 'sampled' => false );
		if ( ! self::available() ) {
			return array();
		}
		$template_types = array_values( array_filter( array( 'elementor_library', 'elementor_component' ), 'post_type_exists' ) );
		$types = array_values( array_unique( array_merge( $template_types, $this->translated_post_types() ) ) );
		if ( ! $types ) {
			return array();
		}
		$query = array(
			'post_type' => $template_types,
			'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => self::DOCUMENT_LIMIT,
			'orderby' => 'ID', 'order' => 'DESC', 'suppress_filters' => true,
			'post__in' => $this->scoped_document_ids(),
			'lang' => '',
		);
		$documents = $template_types ? $this->posts_in_batches( $query ) : array();
		$content_types = array_values( array_diff( $types, $template_types ) );
		if ( $content_types ) {
			$builder_query = $query;
			$builder_query['post_type'] = $content_types;
			$builder_query['meta_query'] = array( 'relation' => 'OR', array( 'key' => '_elementor_edit_mode', 'value' => 'builder' ), array( 'key' => '_elementor_data', 'compare' => 'EXISTS' ) );
			$documents = array_merge( $documents, $this->posts_in_batches( $builder_query ) );
		}
		$documents = array_values( array_column( $documents, null, 'ID' ) );
		usort( $documents, static function ( $a, $b ) { return $b->ID <=> $a->ID; } );
		$buckets = array_fill_keys( array( 'missing', 'unpublished', 'language', 'layout', 'type', 'orphan', 'empty', 'fields', 'outdated', 'untranslated', 'repeater', 'links' ), array() );
		$default = $this->default_lang();
		$languages = $this->active_languages();
		$active_slugs = array_map( static function ( $language ) { return $language->slug; }, $languages );
		$checked = 0;
		foreach ( $documents as $document ) {
			$is_template = in_array( $document->post_type, $template_types, true );
			if ( ! $is_template && ! Elementor_Fields::is_built( (int) $document->ID ) && 'builder' !== get_post_meta( $document->ID, '_elementor_edit_mode', true ) ) {
				continue;
			}
			$type = (string) get_post_meta( $document->ID, '_elementor_template_type', true );
			if ( 'kit' === $type ) {
				continue;
			}
			++$checked;
			$label = '' !== $type ? $type : (string) $document->post_type;
			$this->summary['types'][ $label ] = ( $this->summary['types'][ $label ] ?? 0 ) + 1;
			$this->summary['widgets']        += Elementor_Fields::count_widgets( (int) $document->ID );
			$language = $this->model->post->get_language( $document->ID );
			$slug = $language ? $language->slug : '';
			if ( ! $slug ) {
				$buckets['language'][] = $this->detail( $document, '', __( 'This template has no language assigned.', 'linguator-pro' ), 'language' );
			}
			$raw = get_post_meta( $document->ID, '_elementor_data', true );
			$data = is_array( $raw ) ? $raw : ( is_string( $raw ) && '' !== trim( $raw ) ? json_decode( $raw, true, 128 ) : null );
			$has_data = is_array( $raw ) || ( is_string( $raw ) && '' !== trim( $raw ) );
			$valid_data = is_array( $data ) && ( ! $data || array_keys( $data ) === range( 0, count( $data ) - 1 ) );
			if ( ( $has_data && ! $valid_data ) || ( 'publish' === $document->post_status && empty( $data ) ) ) {
				$buckets['layout'][] = $this->detail( $document, $slug, __( 'Elementor layout data is empty or invalid. Open the template in Elementor and review its layout.', 'linguator-pro' ), 'layout' );
			}
			$translations = $this->model->post->get_translations( $document->ID );
			if ( $slug && $default && $slug !== $default && in_array( $slug, $active_slugs, true ) && empty( $translations[ $default ] ) ) {
				$buckets['orphan'][] = $this->detail( $document, $slug, __( 'This translation is not linked to a default-language template.', 'linguator-pro' ), 'orphan' );
			}
			if ( ! $default || $slug !== $default || 'publish' !== $document->post_status ) {
				continue;
			}
			foreach ( $languages as $target ) {
				if ( $target->slug === $default ) {
					continue;
				}
				$translated = ! empty( $translations[ $target->slug ] ) ? get_post( (int) $translations[ $target->slug ] ) : null;
				if ( ! $translated || in_array( $translated->post_status, array( 'trash', 'auto-draft' ), true ) ) {
					// Missing page/post translations are already owned by the Content scan.
					if ( ! $is_template ) continue;
					$row = $this->detail( $document, $target->slug, sprintf( __( 'No template translation exists for %s.', 'linguator-pro' ), $this->lang_label( $target ) ), 'missing' );
					$row['action_url'] = $this->new_translation_url( $document->ID, $target->slug );
					$row['action_label'] = sprintf( __( 'Translate %s', 'linguator-pro' ), $target->name );
					$buckets['missing'][] = $row;
					continue;
				}
				if ( 'publish' !== $translated->post_status ) {
					$buckets['unpublished'][] = $this->detail( $translated, $target->slug, __( 'The linked Elementor translation is not published.', 'linguator-pro' ), 'unpublished' );
				}
				$translated_type = (string) get_post_meta( $translated->ID, '_elementor_template_type', true );
				if ( $translated->post_type !== $document->post_type || ( $type && $type !== $translated_type ) ) {
					$buckets['type'][] = $this->detail( $translated, $target->slug, sprintf( __( 'The translation should use the same template type as its source: %s.', 'linguator-pro' ), $type ?: $document->post_type ), 'type' );
				}
				if ( 'publish' !== $translated->post_status ) {
					continue;
				}
				if ( Elementor_Fields::has_widgets( $document->ID ) && ! Elementor_Fields::has_widgets( $translated->ID ) ) {
					$buckets['empty'][] = $this->detail( $translated, $target->slug, __( 'This translation has no Elementor widgets, but the original has. Visitors may see an empty template.', 'linguator-pro' ), 'empty' );
				}
				$this->compare_widgets( $document, $translated, $target, $buckets, $is_template );
				$fresh = $fresh ?? new Freshness( $this->model );
				if ( 'source_updated' === $fresh->classify( $document->ID, $target->slug, $fresh->changed_at( $document->ID ), $fresh->changed_at( $translated->ID ) ) ) {
					$buckets['outdated'][] = $this->detail( $translated, $target->slug, __( 'The original was updated after this translation. Review the changes and update the translated template.', 'linguator-pro' ), 'outdated' );
				}
			}
		}
		$this->summary['checked'] = $checked;
		$labels = array(
			'fields' => array( __( 'Empty translated Elementor fields', 'linguator-pro' ), __( 'The original widget has text, but the matching translated field is empty.', 'linguator-pro' ) ),
			'missing' => array( __( 'Missing Template Translations', 'linguator-pro' ), __( 'Published source templates need a translation in each active language.', 'linguator-pro' ) ),
			'unpublished' => array( __( 'Unpublished Elementor Translations', 'linguator-pro' ), __( 'Publish linked translations so visitors can use the translated pages and templates.', 'linguator-pro' ) ),
			'language' => array( __( 'Templates Without a Language', 'linguator-pro' ), __( 'Assign a language before linking or translating these templates.', 'linguator-pro' ) ),
			'layout' => array( __( 'Template Layout Issues', 'linguator-pro' ), __( 'Review empty or invalid Elementor layout data.', 'linguator-pro' ) ),
			'type' => array( __( 'Template Type Mismatches', 'linguator-pro' ), __( 'Source templates and their translations should use the same document type.', 'linguator-pro' ) ),
			'orphan' => array( __( 'Unlinked Template Translations', 'linguator-pro' ), __( 'Link these translations to their default-language source templates.', 'linguator-pro' ) ),
			'untranslated' => array( __( 'Same texts as the original language', 'linguator-pro' ), __( 'These widgets still show the exact same words as the original language. Open each one in Elementor and translate the text — or ignore the warning if it should stay the same (for example a brand or product name).', 'linguator-pro' ) ),
			'repeater' => array( __( 'Repeater Row Mismatches', 'linguator-pro' ), __( 'A list, tab or accordion has a different number of rows in the translation. Match the rows to the original.', 'linguator-pro' ) ),
			'links' => array( __( 'Template Link Issues', 'linguator-pro' ), __( 'Links in the translated template go to a missing page or to a page in another language.', 'linguator-pro' ) ),
			'empty' => array( __( 'Empty Template Translations', 'linguator-pro' ), __( 'Copy the layout from the original template, then translate its text.', 'linguator-pro' ) ),
			'outdated' => array( __( 'Outdated Template Translations', 'linguator-pro' ), __( 'The original template changed after these translations. Review the changes and update the translated templates.', 'linguator-pro' ) ),
		);
		$results = array();
		foreach ( $buckets as $key => $details ) {
			$id = 'elementor_' . $key;
			if ( $this->row_keys ) {
				$details = array_values( array_filter( $details, function ( $row ) { return in_array( $row['ignore_key'], $this->row_keys, true ); } ) );
			}
			if ( ! $this->wants( $id ) || ! $checked ) {
				continue;
			}
			$result = $this->result( $id, $details ? Result::WARNING : Result::PASS, $labels[ $key ][0], $details ? $labels[ $key ][1] : __( 'No issues found in the scanned Elementor templates.', 'linguator-pro' ) );
			$result->with_details( array_slice( $details, 0, self::DETAIL_LIMIT ), count( $details ) > self::DETAIL_LIMIT );
			$has_content_rows = array_filter( $details, static function ( $detail ) { return ! in_array( $detail['post_type'] ?? '', array( 'elementor_library', 'elementor_component' ), true ); } );
			if ( ! $has_content_rows ) $result->with_fix( admin_url( 'edit.php?post_type=elementor_library' ), __( 'Manage templates', 'linguator-pro' ) );
			$results[] = $result;
		}
		if ( ! $checked && $this->wants( 'elementor_none' ) ) {
			$results[] = $this->result( 'elementor_none', Result::INFO, __( 'No Elementor templates found', 'linguator-pro' ), __( 'No saved Elementor templates or components were found to scan.', 'linguator-pro' ) );
		}
		return $results;
	}

	private function scoped_document_ids(): array {
		if ( ! $this->row_keys ) {
			return array();
		}
		$ids = array();
		foreach ( $this->row_keys as $key ) {
			if ( ! preg_match( '/^elementor:([^:]+):(\d+):/', $key, $parts ) ) {
				continue;
			}
			$id = (int) $parts[2];
			$ids[] = $id;
			if ( in_array( $parts[1], array( 'missing', 'unpublished', 'type', 'empty', 'fields', 'outdated', 'untranslated', 'repeater', 'links' ), true ) ) {
				$translations = $this->model->post->get_translations( $id );
				if ( ! empty( $translations[ $this->default_lang() ] ) ) {
					$ids[] = (int) $translations[ $this->default_lang() ];
				}
			}
		}
		return $ids ? array_values( array_unique( $ids ) ) : array( 0 );
	}

	/**
	 * Widget-level differences between a template and one translation: text still in the original language,
	 * different repeater row counts, and links that lead nowhere or to another language.
	 */
	private function compare_widgets( $source, $translated, $target, array &$buckets, bool $report_same_text = true ): void {
		$source_map      = Elementor_Fields::element_map( Elementor_Fields::tree( $source->ID ) );
		$translation_map = Elementor_Fields::element_map( Elementor_Fields::tree( $translated->ID ) );

		foreach ( $source_map as $element_id => $element ) {
			if ( empty( $translation_map[ $element_id ] ) ) {
				continue;
			}
			$other  = $translation_map[ $element_id ];
			$widget      = Elementor_Fields::widget_label( $element['widget_type'] );
			$widget_type = $element['widget_type'];

			foreach ( $element['fields'] as $field_key => $original ) {
				$translated_text = $other['fields'][ $field_key ] ?? '';
				if ( '' !== trim( wp_strip_all_tags( (string) $original ) ) && '' === trim( wp_strip_all_tags( (string) $translated_text ) ) ) {
					$row = $this->detail( $translated, $target->slug, sprintf( __( 'The original contains "%1$s", but the translated %2$s field is empty.', 'linguator-pro' ), Elementor_Fields::snippet( (string) $original, 80 ), Elementor_Fields::field_label( $field_key ) ), 'fields' );
					$row['layout'] = 'widget';
					$row['label'] = $widget . ' — ' . $row['label'] . ' (' . $this->lang_label( $target ) . ')';
					$row['ignore_key'] .= ':' . $element_id . ':' . md5( $field_key );
					$row['action_url'] = add_query_arg( Frontend_Inspector::FOCUS_ARG, rawurlencode( (string) $element_id ), $row['action_url'] );
					$buckets['fields'][] = $row;
				}
				if ( $report_same_text && Elementor_Fields::is_untranslated( $field_key, $original, $translated_text, $target->slug ) ) {
					$row                       = Elementor_Fields::same_text_row(
						$widget_type,
						$field_key,
						$original,
						(string) get_the_title( $translated->ID ),
						(string) $target->name,
						$this->detail( $translated, $target->slug, '', 'untranslated' )
					);
					$row['ignore_key']        .= ':' . $element_id . ':' . md5( $field_key );
					$row['action_url']         = '' !== $row['action_url'] ? add_query_arg( Frontend_Inspector::FOCUS_ARG, rawurlencode( (string) $element_id ), $row['action_url'] ) : '';
					$buckets['untranslated'][] = $row;
				}
			}

			foreach ( $element['repeaters'] as $repeater => $count ) {
				$other_count = (int) ( $other['repeaters'][ $repeater ] ?? 0 );
				if ( $count !== $other_count ) {
					$row = $this->detail( $translated, $target->slug, sprintf( /* translators: 1: widget name, 2: repeater name, 3: rows in the original, 4: rows in the translation */ __( '%1$s › "%2$s" has %3$d rows in the original but %4$d in the translation.', 'linguator-pro' ), $widget, $repeater, $count, $other_count ), 'repeater' );
					$row['ignore_key'] .= ':' . $element_id . ':' . md5( $repeater );
					$row['layout'] = 'widget';
					$buckets['repeater'][] = $row;
				}
			}

			foreach ( $other['links'] as $field_key => $url ) {
				if ( ! $this->is_internal( $url ) || $this->is_asset( $url ) ) {
					continue;
				}
				$message = '';
				$dead    = $this->dead_target( $url );
				if ( $dead ) {
					$message = $dead['message'];
				} else {
					$post_id    = $this->resolve_post_id( $url );
					$link_lang  = $post_id > 0 ? $this->model->post->get_language( $post_id ) : null;
					$suggestion = $link_lang && $link_lang->slug !== $target->slug ? (int) $this->model->post->get_translation( $post_id, $target->slug ) : 0;
					if ( $suggestion > 0 && 'publish' === get_post_status( $suggestion ) ) {
						$message = sprintf( /* translators: 1: link path, 2: language name */ __( 'This link goes to "%1$s", which is in another language. Use the %2$s page instead.', 'linguator-pro' ), $this->readable( $url ), $target->name );
					}
				}
				if ( '' !== $message ) {
					$row = $this->detail( $translated, $target->slug, $widget . ' › ' . Elementor_Fields::field_label( $field_key ) . ': ' . $message, 'links' );
					$row['ignore_key'] .= ':' . $element_id . ':' . md5( $url );
					$row['layout'] = 'widget';
					$buckets['links'][] = $row;
				}
			}
		}
	}

	private function detail( $document, string $slug, string $message, string $kind ): array {
		$edit = (string) get_edit_post_link( $document->ID, 'url' );
		$editor = $edit ? add_query_arg( 'action', 'elementor', $edit ) : '';
		return array(
			'label' => get_the_title( $document->ID ) ?: __( '(no title)', 'linguator-pro' ),
			'message' => $message, 'post_type' => $document->post_type, 'language' => $slug,
			'edit_url' => $editor, 'action_url' => $editor,
			'action_label' => __( 'Edit in Elementor', 'linguator-pro' ),
			'ignore_key' => 'elementor:' . $kind . ':' . $document->ID . ':' . $slug,
			'template_type' => (string) ( get_post_meta( $document->ID, '_elementor_template_type', true ) ?: $document->post_type ),
		);
	}
}
