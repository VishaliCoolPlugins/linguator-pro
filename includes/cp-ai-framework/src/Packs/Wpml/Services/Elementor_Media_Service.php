<?php
/**
 * Elementor gallery media audit and repair for the abilities subsystem.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor's gallery and image widgets keep their own reference to a media
 * attachment ID inside _elementor_data, separate from WPML's translation of
 * the post itself. Duplicating a page into a new language duplicates that
 * reference too, so a translated page can end up serving broken thumbnails
 * (the target-language attachment was never told to regenerate the sizes
 * Elementor asks for) or captions that were never localized in the first
 * place. Nothing else in AutoMLP touches attachment media at all.
 *
 * @since 1.5.0
 */
final class Elementor_Media_Service {

	/**
	 * Reports the gallery/image attachments used on one post and their state.
	 *
	 * @since 1.5.0
	 * @param int    $post_id Post to scan.
	 * @param string $size    Image size to check for.
	 * @return array<string, mixed>|WP_Error Report, or an error.
	 */
	public static function audit( $post_id, $size ) {
		$scan = self::scan( $post_id );

		if ( is_wp_error( $scan ) ) {
			return $scan;
		}

		list( $post, $attachment_ids, $target_language, $source_language ) = $scan;

		$items = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$items[] = self::describe_attachment( $attachment_id, $size, $target_language, $source_language );
		}

		return array(
			'post_id'          => (int) $post->ID,
			'target_language'  => $target_language,
			'source_language'  => $source_language,
			'size'             => $size,
			'attachment_count' => count( $items ),
			'items'            => $items,
		);
	}

	/**
	 * Regenerates missing thumbnail metadata for the gallery/image attachments
	 * used on one post.
	 *
	 * @since 1.5.0
	 * @param int    $post_id Post to scan.
	 * @param string $size    Image size to ensure exists.
	 * @param bool   $force   Regenerate even when the size already exists.
	 * @return array<string, mixed>|WP_Error Report, or an error.
	 */
	public static function repair( $post_id, $size, $force ) {
		$scan = self::scan( $post_id );

		if ( is_wp_error( $scan ) ) {
			return $scan;
		}

		list( $post, $attachment_ids ) = $scan;

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$items = array();
		$regenerated_count = 0;

		foreach ( $attachment_ids as $attachment_id ) {
			$before = self::size_status( $attachment_id, $size );
			$regenerated = false;
			$error = '';

			if ( $force || ! $before['has_size'] || ! $before['file_exists'] ) {
				$file = get_attached_file( $attachment_id );

				if ( ! is_string( $file ) || '' === $file || ! file_exists( $file ) ) {
					$error = __( 'Original attachment file is missing on disk.', 'wpml-translation-check-pro' );
				} else {
					$new_meta = wp_generate_attachment_metadata( $attachment_id, $file );

					if ( is_array( $new_meta ) && ! empty( $new_meta ) ) {
						wp_update_attachment_metadata( $attachment_id, $new_meta );
						clean_attachment_cache( $attachment_id );
						++$regenerated_count;
						$regenerated = true;
					} else {
						$error = __( 'wp_generate_attachment_metadata returned no metadata.', 'wpml-translation-check-pro' );
					}
				}
			}

			$items[] = array(
				'attachment_id' => $attachment_id,
				'title'         => (string) get_the_title( $attachment_id ),
				'before'        => $before,
				'after'         => self::size_status( $attachment_id, $size ),
				'regenerated'   => $regenerated,
				'error'         => $error,
			);
		}

		clean_post_cache( (int) $post->ID );

		if ( class_exists( '\Elementor\Plugin' ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return array(
			'post_id'            => (int) $post->ID,
			'size'               => $size,
			'force'              => (bool) $force,
			'attachment_count'   => count( $items ),
			'regenerated_count'  => $regenerated_count,
			'items'              => $items,
		);
	}

	/**
	 * Bulk-updates attachment title/caption/description/alt text.
	 *
	 * Used to fix captions still left in the source language after a page
	 * translation, since AutoMLP's post translation never touches media.
	 *
	 * @since 1.5.0
	 * @param array<int, array<string, mixed>> $updates One entry per attachment.
	 * @return array<string, mixed> Result, keyed by attachment.
	 */
	public static function update_captions( array $updates ) {
		$items = array();

		foreach ( $updates as $update ) {
			if ( ! is_array( $update ) ) {
				continue;
			}

			$id   = isset( $update['id'] ) ? absint( $update['id'] ) : 0;
			$post = $id > 0 ? get_post( $id ) : null;

			if ( ! $post || 'attachment' !== $post->post_type ) {
				$items[] = array(
					'attachment_id' => $id,
					'success'       => false,
					'message'       => __( 'Attachment not found.', 'wpml-translation-check-pro' ),
				);
				continue;
			}

			$postarr = array( 'ID' => $id );

			if ( isset( $update['title'] ) ) {
				$postarr['post_title'] = sanitize_text_field( (string) $update['title'] );
			}

			if ( isset( $update['caption'] ) ) {
				$postarr['post_excerpt'] = sanitize_text_field( (string) $update['caption'] );
			}

			if ( isset( $update['description'] ) ) {
				$postarr['post_content'] = wp_kses_post( (string) $update['description'] );
			}

			$result = wp_update_post( wp_slash( $postarr ), true );

			if ( is_wp_error( $result ) ) {
				$items[] = array(
					'attachment_id' => $id,
					'success'       => false,
					'message'       => $result->get_error_message(),
				);
				continue;
			}

			if ( isset( $update['alt_text'] ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $update['alt_text'] ) );
			}

			clean_attachment_cache( $id );

			$items[] = array(
				'attachment_id' => $id,
				'success'       => true,
				'title'         => (string) get_the_title( $id ),
			);
		}

		$failed = array_values( array_filter( $items, static function ( $item ) {
			return empty( $item['success'] );
		} ) );

		return array(
			'count'       => count( $items ),
			'failed_count' => count( $failed ),
			'items'       => $items,
		);
	}

	/**
	 * Loads a post's _elementor_data and the gallery/image attachment IDs it
	 * references, plus its WPML language details.
	 *
	 * @since 1.5.0
	 * @param int $post_id Post to scan.
	 * @return array{0: \WP_Post, 1: int[], 2: string, 3: string}|WP_Error Scan result, or an error.
	 */
	private static function scan( $post_id ) {
		$post_id = absint( $post_id );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return new WP_Error(
				'automlp_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d was not found.', 'wpml-translation-check-pro' ),
					$post_id
				)
			);
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return new WP_Error(
				'automlp_not_elementor',
				__( 'This post has no Elementor data, so it has no gallery or image widgets to scan.', 'wpml-translation-check-pro' )
			);
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'automlp_decode_failed',
				__( 'This post\'s Elementor data could not be decoded as JSON.', 'wpml-translation-check-pro' )
			);
		}

		$attachment_ids = array();

		self::collect_attachment_ids( $data, $attachment_ids );

		$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) );

		$details = Translation_Service::get_element_language_details( $post_id );

		$target_language = null === $details ? '' : $details['language_code'];
		$source_language  = null === $details
			? ''
			: ( $details['is_source'] ? $details['language_code'] : $details['source_language_code'] );

		return array( $post, $attachment_ids, $target_language, $source_language );
	}

	/**
	 * Recursively collects attachment IDs from Elementor gallery and image
	 * widget settings.
	 *
	 * Elementor stores a gallery widget's images as settings.gallery, an
	 * array of {id, url} entries, and a single image field (image,
	 * background_image, and similar) as one {id, url} object. Both are
	 * covered; other widget-specific media fields are not.
	 *
	 * @since 1.5.0
	 * @param array<int|string, mixed> $nodes Elementor element tree.
	 * @param int[]                    $ids   Accumulator, passed by reference.
	 * @return void
	 */
	private static function collect_attachment_ids( array $nodes, array &$ids ) {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
				foreach ( $node['settings'] as $key => $value ) {
					if ( 'gallery' === $key && is_array( $value ) ) {
						foreach ( $value as $item ) {
							if ( is_array( $item ) && isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
								$ids[] = (int) $item['id'];
							}
						}
						continue;
					}

					if ( is_array( $value ) && isset( $value['id'] ) && is_numeric( $value['id'] ) && is_string( $key ) && false !== stripos( $key, 'image' ) ) {
						$ids[] = (int) $value['id'];
					}
				}
			}

			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				self::collect_attachment_ids( $node['elements'], $ids );
			}
		}
	}

	/**
	 * One attachment's title/caption/description plus its language and, when
	 * it sits in the target language, whether its caption text still matches
	 * the source-language attachment's caption verbatim.
	 *
	 * @since 1.5.0
	 * @param int    $attachment_id   Attachment to describe.
	 * @param string $size            Image size to check for.
	 * @param string $target_language The scanned post's own language.
	 * @param string $source_language The scanned post's source language.
	 * @return array<string, mixed> Attachment row.
	 */
	private static function describe_attachment( $attachment_id, $size, $target_language, $source_language ) {
		$post = get_post( $attachment_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return array(
				'attachment_id' => $attachment_id,
				'found'         => false,
			);
		}

		$details = Translation_Service::get_element_language_details( $attachment_id );
		$language = null === $details ? '' : $details['language_code'];

		$title       = (string) $post->post_title;
		$caption     = (string) $post->post_excerpt;
		$description = (string) $post->post_content;

		$row = array(
			'attachment_id' => $attachment_id,
			'found'         => true,
			'language'      => $language,
			'title'         => $title,
			'caption'       => $caption,
			'description'   => $description,
			'size_status'   => self::size_status( $attachment_id, $size ),
		);

		if ( '' === $language || $language !== $target_language || '' === $source_language || $source_language === $target_language ) {
			return $row;
		}

		$source_id = apply_filters( 'wpml_object_id', $attachment_id, 'attachment', false, $source_language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$source_id = is_numeric( $source_id ) ? (int) $source_id : 0;

		$row['source_attachment_id'] = $source_id;

		if ( 0 === $source_id || $source_id === $attachment_id ) {
			return $row;
		}

		$source_post = get_post( $source_id );

		if ( ! $source_post ) {
			return $row;
		}

		$source_caption = trim( (string) $source_post->post_excerpt );

		if ( '' !== $source_caption && trim( $caption ) === $source_caption ) {
			$row['possibly_untranslated_caption'] = true;
		}

		return $row;
	}

	/**
	 * Whether a given image size exists for an attachment, both in its
	 * metadata and as an actual file on disk.
	 *
	 * @since 1.5.0
	 * @param int    $attachment_id Attachment to check.
	 * @param string $size          Registered image size, or "full".
	 * @return array{has_size: bool, file_exists: bool} Status.
	 */
	private static function size_status( $attachment_id, $size ) {
		if ( 'full' === $size ) {
			$file = get_attached_file( $attachment_id );

			return array(
				'has_size'    => true,
				'file_exists' => is_string( $file ) && '' !== $file && file_exists( $file ),
			);
		}

		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $meta ) || ! isset( $meta['sizes'][ $size ]['file'] ) ) {
			return array(
				'has_size'    => false,
				'file_exists' => false,
			);
		}

		$file = get_attached_file( $attachment_id );
		$path = is_string( $file ) && '' !== $file ? path_join( dirname( $file ), $meta['sizes'][ $size ]['file'] ) : '';

		return array(
			'has_size'    => true,
			'file_exists' => '' !== $path && file_exists( $path ),
		);
	}
}
