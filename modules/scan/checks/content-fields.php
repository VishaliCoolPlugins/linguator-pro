<?php
/**
 * ACF and custom field checks.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Modules\Scan\Abstract_Check;
use LinguatorPro\Modules\Scan\Result;
use LinguatorPro\Modules\Scan\Untranslated_Collector;

/**
 * Flags translations whose ACF or custom field text still matches the default language.
 *
 * A field that Linguator copies between translations is skipped: identical values are expected there.
 *
 * @since 2.3.0
 */
class Content_Fields extends Abstract_Check {

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
			} elseif ( $this->wants( 'content_acf_' . $lang->slug ) ) {
				$targets[ $lang->slug ] = $lang;
			}
		}
		if ( ! $default_obj || empty( $targets ) ) {
			return array();
		}

		$rows     = array_fill_keys( array_keys( $targets ), array() );
		$totals   = array_fill_keys( array_keys( $targets ), 0 );
		$compared = array_fill_keys( array_keys( $targets ), 0 );

		foreach ( $types as $type ) {
			$post_ids = $this->default_language_post_ids( $type, $default_obj );

			foreach ( $post_ids as $post_id ) {
				$post_id = (int) $post_id;
				$fields  = $this->translatable_fields( $post_id );
				if ( empty( $fields ) ) {
					continue;
				}

				$translations = $this->model->post->get_translations( $post_id );

				foreach ( $targets as $slug => $lang ) {
					$translation_id = (int) ( $translations[ $slug ] ?? 0 );
					if ( $translation_id <= 0 || 'publish' !== get_post_status( $translation_id ) ) {
						continue;
					}

					$translation = get_post( $translation_id );
					if ( ! $translation instanceof \WP_Post ) {
						continue;
					}

					$title = '' !== $translation->post_title ? $translation->post_title : __( '(no title)', 'linguator-pro' );

					foreach ( $fields as $field => $data ) {
						// A field copied between translations is expected to match.
						if ( $this->is_synced( $data['meta_key'], $post_id, $translation_id, $slug ) ) {
							continue;
						}

						++$compared[ $slug ];
						$original   = $data['value'];
						$translated = Untranslated_Collector::normalize_text( $this->field_text( $translation_id, $field, $data['meta_key'] ) );

						if ( '' === $original || $original !== $translated ) {
							continue;
						}
						// Counters, prices and symbols read the same in every language.
						if ( preg_match( '/^[\d\s\.\,\%\$€£₹\/\-\+]+$/u', $original ) ) {
							continue;
						}

						++$totals[ $slug ];
						if ( count( $rows[ $slug ] ) >= self::DETAIL_LIMIT ) {
							continue;
						}

						$rows[ $slug ][] = array(
							'label'          => sprintf(
								/* translators: 1: field name, 2: post title, 3: language name */
								__( '%1$s of "%2$s" (%3$s)', 'linguator-pro' ),
								$data['label'],
								$title,
								$lang->name
							),
							'message'        => sprintf(
								/* translators: %s: text that still matches the original language */
								__( 'Not translated yet — still shows: "%s"', 'linguator-pro' ),
								Elementor_Fields::snippet( $original, 80 )
							),
							'action_url'     => (string) get_edit_post_link( $translation_id, 'url' ),
							'action_label'   => __( 'Edit translation', 'linguator-pro' ),
							'ai_url'         => admin_url( 'edit.php?post_type=' . rawurlencode( $type ) ),
							'ignore_key'     => 'content_acf:' . $post_id . ':' . $slug . ':' . md5( $field . '|' . $original ),
							'translation_id' => $translation_id,
							'language'       => $slug,
							'post_type'      => $type,
						);
					}
				}
			}
		}

		$results = array();
		foreach ( $targets as $slug => $lang ) {
			$id    = 'content_acf_' . $slug;
			$label = $this->lang_label( $lang );

			if ( $totals[ $slug ] > 0 ) {
				$results[] = $this->result(
					$id,
					Result::WARNING,
					/* translators: %s: language name with code */
					sprintf( __( 'Identical ACF fields in "%s"', 'linguator-pro' ), $label ),
					sprintf(
						/* translators: 1: number of fields, 2: language name with code */
						_n(
							'%1$d translated custom field still matches the original text in "%2$s".',
							'%1$d translated custom fields still match the original text in "%2$s".',
							$totals[ $slug ],
							'linguator-pro'
						),
						$totals[ $slug ],
						$label
					)
				)->with_details( $rows[ $slug ], $totals[ $slug ] > count( $rows[ $slug ] ) );
			} elseif ( $compared[ $slug ] > 0 ) {
				$results[] = $this->result(
					$id,
					Result::PASS,
					/* translators: %s: language name with code */
					sprintf( __( 'ACF fields translated in "%s"', 'linguator-pro' ), $label ),
					__( 'Linked translations use translated custom fields.', 'linguator-pro' )
				);
			}
		}

		return $results;
	}

	/**
	 * Whether Linguator copies this custom field to translations, so identical values are expected.
	 *
	 * @since 2.3.0
	 *
	 * @param string $meta_key       Meta key.
	 * @param int    $source_id      Source post ID.
	 * @param int    $translation_id Translation post ID.
	 * @param string $slug           Target language slug.
	 * @return bool
	 */
	private function is_synced( string $meta_key, int $source_id, int $translation_id, string $slug ): bool {
		if ( '' === $meta_key ) {
			return false;
		}

		$sync   = (array) $this->option( 'sync', array() );
		$global = in_array( 'post_meta', $sync, true );

		/** This filter is documented in modules/sync/sync-post-metas.php */
		$keys = apply_filters( 'lmat_copy_post_metas', $global ? array( $meta_key ) : array(), true, $source_id, $translation_id, $slug );

		return is_array( $keys ) ? in_array( $meta_key, $keys, true ) : $global;
	}

	/**
	 * Text of one custom field on a post.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $field_key Field key with an acf_ or meta_ prefix.
	 * @param string $meta_key  Underlying meta key.
	 * @return string
	 */
	private function field_text( int $post_id, string $field_key, string $meta_key ): string {
		$text = '';

		if ( 0 === strpos( $field_key, 'acf_' ) && function_exists( 'get_field_object' ) ) {
			$object = get_field_object( $meta_key, $post_id, false );
			if ( is_array( $object ) && isset( $object['value'] ) ) {
				$value = $object['value'];
				if ( isset( $object['type'] ) && 'link' === $object['type'] && is_array( $value ) ) {
					$text = isset( $value['title'] ) ? (string) $value['title'] : '';
				} elseif ( is_string( $value ) ) {
					$text = $value;
				}
			}
		}

		if ( '' === $text ) {
			$raw = get_post_meta( $post_id, $meta_key, true );
			if ( is_string( $raw ) ) {
				$text = $raw;
			}
		}

		return $text;
	}

	/**
	 * Candidate translatable custom fields of a post: text ACF fields, or plain public post meta without ACF.
	 *
	 * @since 2.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,array{meta_key:string,label:string,value:string}>
	 */
	private function translatable_fields( int $post_id ): array {
		$fields = array();

		if ( function_exists( 'get_field_objects' ) ) {
			$objects = get_field_objects( $post_id, false );
			if ( is_array( $objects ) ) {
				foreach ( $objects as $name => $object ) {
					if ( '' === (string) $name || ! is_array( $object ) ) {
						continue;
					}
					$label = ! empty( $object['label'] ) ? (string) $object['label'] : (string) $name;
					$this->collect_acf( $fields, $post_id, $object, (string) $name, $object['value'] ?? null, $label );
				}
			}

			return $fields;
		}

		$all_meta = get_post_meta( $post_id );
		if ( ! is_array( $all_meta ) ) {
			return $fields;
		}

		foreach ( $all_meta as $key => $values ) {
			$key = (string) $key;
			// Internal keys, SEO and theme settings are not content.
			if ( '' === $key || '_' === $key[0] || preg_match( '/^(ast[-_]|astra[-_]|site[-_]|surerank|rank_math|seopress|aioseo|inline_featured_image)/i', $key ) ) {
				continue;
			}
			if ( ! is_array( $values ) || empty( $values ) || ! is_string( $values[0] ) || '' === trim( $values[0] ) ) {
				continue;
			}
			$value = $values[0];
			if ( is_serialized( $value ) || ( 0 === strpos( $value, '{' ) && null !== json_decode( $value ) ) ) {
				continue;
			}

			$normalized = Untranslated_Collector::normalize_text( $value );
			if ( '' === $normalized ) {
				continue;
			}

			$fields[ 'meta_' . $key ] = array(
				'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Array key, not a database query.
				'label'    => sprintf(
					/* translators: %s: custom field label */
					__( 'Custom field "%s"', 'linguator-pro' ),
					ucwords( str_replace( array( '-', '_' ), ' ', $key ) )
				),
				'value'    => $normalized,
			);
		}

		return $fields;
	}

	/**
	 * Adds one ACF field to the candidates, or walks into it when it holds sub fields.
	 *
	 * ACF stores nested values under meta keys built from the path, so a repeater row is "team_0_name" and a
	 * group member is "hero_title". The same key is used on the translation, which keeps the comparison aligned.
	 *
	 * @since 2.3.0
	 *
	 * @param array  $fields   Candidates, by reference.
	 * @param int    $post_id  Post ID.
	 * @param array  $object   ACF field definition.
	 * @param string $meta_key Meta key for this field, including its path.
	 * @param mixed  $value    Unformatted value, or null to read it from post meta.
	 * @param string $label    Display label including its path.
	 * @return void
	 */
	private function collect_acf( array &$fields, int $post_id, array $object, string $meta_key, $value, string $label ): void {
		$type = isset( $object['type'] ) ? (string) $object['type'] : '';

		if ( in_array( $type, array( 'group', 'repeater', 'flexible_content' ), true ) ) {
			if ( null === $value ) {
				$value = get_post_meta( $post_id, $meta_key, true );
			}

			if ( 'group' === $type ) {
				foreach ( (array) ( $object['sub_fields'] ?? array() ) as $sub ) {
					if ( ! is_array( $sub ) || empty( $sub['name'] ) ) {
						continue;
					}
					$sub_value = is_array( $value ) && isset( $sub['key'], $value[ $sub['key'] ] ) ? $value[ $sub['key'] ] : null;
					$this->collect_acf( $fields, $post_id, $sub, $meta_key . '_' . $sub['name'], $sub_value, $label . ' › ' . ( ! empty( $sub['label'] ) ? $sub['label'] : $sub['name'] ) );
				}

				return;
			}

			$limit = 50;
			$rows  = array();
			if ( 'repeater' === $type ) {
				$rows = array_fill( 0, max( 0, min( $limit, is_array( $value ) ? count( $value ) : (int) $value ) ), '' );
			} elseif ( is_array( $value ) ) {
				$rows = array_slice( array_values( $value ), 0, $limit );
			}

			foreach ( $rows as $index => $row ) {
				$sub_fields = (array) ( $object['sub_fields'] ?? array() );
				if ( 'flexible_content' === $type ) {
					$sub_fields = array();
					foreach ( (array) ( $object['layouts'] ?? array() ) as $layout ) {
						if ( is_array( $layout ) && isset( $layout['name'] ) && is_string( $row ) && $row === (string) $layout['name'] ) {
							$sub_fields = (array) ( $layout['sub_fields'] ?? array() );
							break;
						}
					}
				}

				foreach ( $sub_fields as $sub ) {
					if ( ! is_array( $sub ) || empty( $sub['name'] ) ) {
						continue;
					}
					$this->collect_acf(
						$fields,
						$post_id,
						$sub,
						$meta_key . '_' . $index . '_' . $sub['name'],
						null,
						sprintf(
							/* translators: 1: repeater or flexible content label, 2: row number, 3: sub field label */
							__( '%1$s #%2$d › %3$s', 'linguator-pro' ),
							$label,
							$index + 1,
							! empty( $sub['label'] ) ? $sub['label'] : $sub['name']
						)
					);
				}
			}

			return;
		}

		if ( ! in_array( $type, array( 'text', 'textarea', 'wysiwyg', 'url', 'email', 'link' ), true ) ) {
			return;
		}

		if ( null === $value ) {
			$value = get_post_meta( $post_id, $meta_key, true );
		}

		$text = '';
		if ( 'link' === $type && is_array( $value ) ) {
			$text = isset( $value['title'] ) ? (string) $value['title'] : '';
		} elseif ( is_string( $value ) ) {
			$text = $value;
		}

		$normalized = Untranslated_Collector::normalize_text( $text );
		if ( '' === $normalized ) {
			return;
		}

		$fields[ 'acf_' . $meta_key ] = array(
			'meta_key' => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Array key, not a database query.
			'label'    => sprintf(
				/* translators: %s: custom field label */
				__( 'Custom field "%s"', 'linguator-pro' ),
				$label
			),
			'value'    => $normalized,
		);
	}
}
