<?php
/**
 * Content reads: coverage, missing translations and the content to translate.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads posts and terms the way a translator needs them.
 *
 * @since 1.2.0
 */
final class Content_Service {

	/**
	 * Ways to list content for get-untranslated-content.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const LANGUAGE_STATES = array( 'missing_translation', 'no_language' );

	/**
	 * Statuses that count as content.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Most posts looked at in one listing.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const SCAN_LIMIT = 2000;

	/**
	 * IDs of the posts of a type in one language.
	 *
	 * @since 1.2.0
	 * @param string $post_type Post type.
	 * @param string $lang      Language slug, or an empty string for every language.
	 * @return int[] Post IDs, oldest first.
	 */
	public static function post_ids( $post_type, $lang ) {
		$ids = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => self::STATUSES,
				'lang'             => $lang,
				'fields'           => 'ids',
				'posts_per_page'   => self::SCAN_LIMIT,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Translated content types with the number of posts in the default language.
	 *
	 * @since 1.2.0
	 * @return array<int, array<string, mixed>> Types.
	 */
	public static function list_types() {
		$default = (string) linguator_default_language();
		$out     = array();

		foreach ( Settings_Service::post_types() as $type ) {
			$object = get_post_type_object( $type );
			$out[]  = array(
				'name'                   => $type,
				'label'                  => $object ? (string) $object->labels->name : $type,
				'kind'                   => 'post_type',
				'default_language_count' => (int) linguator_count_posts( $default, array( 'post_type' => $type ) ),
			);
		}

		foreach ( Settings_Service::taxonomies() as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			$out[]  = array(
				'name'  => $taxonomy,
				'label' => $object ? (string) $object->labels->name : $taxonomy,
				'kind'  => 'taxonomy',
			);
		}

		return $out;
	}

	/**
	 * Translation coverage of every translated type in every other language.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Coverage.
	 */
	public static function get_status() {
		$default = (string) linguator_default_language();
		$targets = Language_Service::target_slugs();
		$types   = array();

		foreach ( Settings_Service::post_types() as $type ) {
			$ids       = self::post_ids( $type, $default );
			$languages = array_fill_keys(
				$targets,
				array(
					'translated'  => 0,
					'unpublished' => 0,
					'missing'     => 0,
				)
			);

			foreach ( $ids as $id ) {
				$translations = linguator_get_post_translations( $id );

				foreach ( $targets as $slug ) {
					$target_id = (int) ( $translations[ $slug ] ?? 0 );
					$status    = $target_id > 0 ? get_post_status( $target_id ) : false;

					if ( ! $status || 'trash' === $status ) {
						++$languages[ $slug ]['missing'];
					} elseif ( 'publish' === $status ) {
						++$languages[ $slug ]['translated'];
					} else {
						++$languages[ $slug ]['unpublished'];
					}
				}
			}

			$types[ $type ] = array(
				'source_count' => count( $ids ),
				'languages'    => $languages,
			);
		}

		return array(
			'default_language' => $default,
			'post_types'       => $types,
		);
	}

	/**
	 * Content missing a translation, or content without a language.
	 *
	 * @since 1.2.0
	 * @param string      $target Target language slug.
	 * @param string|null $type   Post type, or null for every translated type.
	 * @param int         $limit  Page size, 1 to 100.
	 * @param int         $offset Items to skip.
	 * @param string      $state  One of LANGUAGE_STATES.
	 * @return array<string, mixed>|WP_Error Items, total and has_more.
	 */
	public static function get_untranslated( $target, $type, $limit, $offset, $state ) {
		$language = Linguator_Guard::language( $target );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$limit   = max( 1, min( 100, (int) $limit ) );
		$offset  = max( 0, (int) $offset );
		$types   = Settings_Service::post_types();
		$default = (string) linguator_default_language();
		$matches = array();
		$notice  = '';

		if ( null !== $type && '' !== $type ) {
			if ( ! in_array( $type, $types, true ) ) {
				return array(
					'items'    => array(),
					'total'    => 0,
					'has_more' => false,
					'notice'   => __( 'This post type is not translated by Linguator. Switch it on in Linguator settings, under Post types, then try again.', 'linguator-pro' ),
				);
			}

			$types = array( $type );
		}

		if ( 'no_language' !== $state && $target === $default ) {
			$notice = __( 'The target language is the default language, so there is nothing to translate into it.', 'linguator-pro' );
		} else {
			foreach ( $types as $post_type ) {
				if ( 'no_language' === $state ) {
					foreach ( self::post_ids( $post_type, '' ) as $id ) {
						if ( ! linguator_get_post_language( $id ) ) {
							$matches[] = $id;
						}
					}
					continue;
				}

				foreach ( self::post_ids( $post_type, $default ) as $id ) {
					$translated = (int) ( linguator_get_post_translations( $id )[ $target ] ?? 0 );

					if ( $translated < 1 || ! get_post( $translated ) || 'trash' === get_post_status( $translated ) ) {
						$matches[] = $id;
					}
				}
			}
		}

		$items = array();

		foreach ( array_slice( $matches, $offset, $limit ) as $id ) {
			$post    = get_post( $id );
			$items[] = array(
				'id'              => (int) $id,
				'title'           => get_the_title( $post ),
				'type'            => $post->post_type,
				'status'          => $post->post_status,
				'source_language' => (string) linguator_get_post_language( $id ),
				'modified'        => $post->post_modified_gmt,
			);
		}

		$result = array(
			'items'    => $items,
			'total'    => count( $matches ),
			'has_more' => $offset + $limit < count( $matches ),
		);

		if ( '' !== $notice ) {
			$result['notice'] = $notice;
		}

		return $result;
	}

	/**
	 * Everything needed to translate one post.
	 *
	 * @since 1.2.0
	 * @param int    $post_id Post ID.
	 * @param string $target  Target language slug, optional.
	 * @return array<string, mixed>|null Payload, or null when the post does not exist.
	 */
	public static function get_post_content( $post_id, $target = '' ) {
		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return null;
		}

		$target   = sanitize_key( $target );
		$builder  = '' !== (string) get_post_meta( $post->ID, '_elementor_edit_mode', true ) ? 'elementor' : ( has_blocks( $post->post_content ) ? 'gutenberg' : 'classic' );
		$existing = (int) ( linguator_get_post_translations( $post->ID )[ $target ] ?? 0 );
		$meta     = array();

		foreach ( Settings_Service::allowed_meta_keys() as $key ) {
			$value = get_post_meta( $post->ID, $key, true );

			if ( '' !== $value && null !== $value ) {
				$meta[ $key ] = $value;
			}
		}

		$payload = array(
			'id'                   => (int) $post->ID,
			'type'                 => $post->post_type,
			'status'               => $post->post_status,
			'language'             => (string) linguator_get_post_language( $post->ID ),
			'title'                => $post->post_title,
			'content'              => $post->post_content,
			'excerpt'              => $post->post_excerpt,
			'slug'                 => $post->post_name,
			'translations'         => linguator_get_post_translations( $post->ID ),
			'translatable_meta'    => $meta,
			'terms'                => self::post_terms( $post->ID, $target ),
			'editor_type'          => $builder,
			'builder_storage'      => 'elementor' === $builder ? 'meta' : 'content',
			'existing_translation' => $existing > 0 && get_post( $existing ) ? $existing : 0,
		);

		$payload = self::attach_glossary( $payload, $post, $target );

		if ( 'elementor' === $builder ) {
			$payload['notice'] = __( 'This page is built with Elementor, which keeps its layout in post meta. translate-post cannot translate that layout: translate it in the Linguator editor or with bulk translation.', 'linguator-pro' );
		}

		return $payload;
	}

	/**
	 * Elementor setting keys whose text a translator reads.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const ELEMENTOR_TEXT_KEYS = array( 'title', 'editor', 'text', 'description', 'content', 'sub_title', 'title_text', 'description_text', 'button_text', 'heading', 'caption', 'alert_title', 'alert_description', 'tab_title', 'tab_content', 'item_description', 'testimonial_content' );

	/**
	 * Adds the glossary terms that appear in this post, when there are any.
	 *
	 * The glossary is advice a client can skip if it only has to remember to call get-glossary, so
	 * the entries relevant to this post are handed over with the content. Both keys are omitted when
	 * nothing matches.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $payload Payload built so far.
	 * @param \WP_Post             $post    Source post.
	 * @param string               $target  Target language slug, or an empty string.
	 * @return array<string, mixed> The payload, with glossary keys when relevant.
	 */
	private static function attach_glossary( array $payload, $post, $target ) {
		$source_language = (string) $payload['language'];

		if ( '' === $source_language || ! Glossary_Service::is_available() ) {
			return $payload;
		}

		$texts = array( $post->post_title, $post->post_content, $post->post_excerpt );

		foreach ( (array) $payload['translatable_meta'] as $value ) {
			if ( is_string( $value ) ) {
				$texts[] = $value;
			}
		}

		if ( 'elementor' === $payload['editor_type'] ) {
			self::collect_elementor_texts( Template_Service::get_elements( $post->ID ), $texts );
		}

		$matched = Glossary_Service::get_matching_entries( $texts, $source_language, $target );

		if ( array() === $matched ) {
			return $payload;
		}

		$payload['glossary']        = $matched;
		$payload['glossary_notice'] = __( 'These glossary terms appear in this post and have mandated translations. Use the listed translation for the target language exactly, including capitalisation. A term of type "name" is a proper noun and should be carried over unchanged unless a translation is given. Linguator\'s own translation tools apply these, so ignoring them makes this page disagree with the rest of the site.', 'linguator-pro' );

		return $payload;
	}

	/**
	 * Collects the text values of an Elementor element tree.
	 *
	 * @since 1.2.0
	 * @param array<int, mixed> $elements Elementor elements.
	 * @param string[]          $texts    Texts collected so far, by reference.
	 * @return void
	 */
	private static function collect_elementor_texts( array $elements, array &$texts ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				foreach ( self::ELEMENTOR_TEXT_KEYS as $key ) {
					if ( isset( $element['settings'][ $key ] ) && is_string( $element['settings'][ $key ] ) ) {
						$texts[] = $element['settings'][ $key ];
					}
				}
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect_elementor_texts( $element['elements'], $texts );
			}
		}
	}

	/**
	 * Terms attached to a post, with their translation in the target language.
	 *
	 * @since 1.2.0
	 * @param int    $post_id Post ID.
	 * @param string $target  Target language slug.
	 * @return array<string, array<int, array<string, mixed>>> Terms by taxonomy.
	 */
	private static function post_terms( $post_id, $target ) {
		$out = array();

		foreach ( Settings_Service::taxonomies() as $taxonomy ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$out[ $taxonomy ][] = array(
					'id'             => (int) $term->term_id,
					'name'           => $term->name,
					'slug'           => $term->slug,
					'translation_id' => '' !== $target ? (int) ( linguator_get_term_translations( $term->term_id )[ $target ] ?? 0 ) : 0,
				);
			}
		}

		return $out;
	}

	/**
	 * Everything needed to translate one term.
	 *
	 * @since 1.2.0
	 * @param int $term_id Term ID.
	 * @return array<string, mixed>|null Payload, or null when the term does not exist.
	 */
	public static function get_term_content( $term_id ) {
		$term = get_term( (int) $term_id );

		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		return array(
			'id'           => (int) $term->term_id,
			'taxonomy'     => $term->taxonomy,
			'name'         => $term->name,
			'slug'         => $term->slug,
			'description'  => $term->description,
			'parent'       => (int) $term->parent,
			'language'     => (string) linguator_get_term_language( $term->term_id ),
			'translations' => linguator_get_term_translations( $term->term_id ),
		);
	}
}
