<?php
/** Potential accidental copies with WordPress numbered slugs. @package Linguator */
namespace LinguatorPro\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Modules\Scan\Abstract_Check;
use LinguatorPro\Modules\Scan\Result;

class Url_Numbered_Copies extends Abstract_Check {
	protected $group = 'URLs & Routing';
	protected $section = 'urls';

	public function run(): array {
		if ( ! $this->wants( 'url_numbered_copies' ) ) {
			return array();
		}
		$types = $this->translated_post_types();
		if ( ! $types ) {
			return array();
		}
		$args = array(
			'post_type' => $types, 'post_status' => 'publish',
			'posts_per_page' => self::POST_LIMIT + 1, 'orderby' => 'ID', 'order' => 'DESC',
			'lang' => '', 'suppress_filters' => true, 'no_found_rows' => true,
		);
		$posts = get_posts( $args );
		$sampled = count( $posts ) > self::POST_LIMIT;
		$details = array();
		$originals = array();
		foreach ( array_slice( $posts, 0, self::POST_LIMIT ) as $post ) {
			if ( ! preg_match( '/^(.+)-([2-9]|[1-9][0-9]+)$/', $post->post_name, $match ) ) {
				continue;
			}
			$lang = $this->model->post->get_language( (int) $post->ID );
			if ( ! $lang || ( isset( $lang->active ) && ! $lang->active ) ) {
				continue;
			}
			$key = $post->post_type . ':' . $post->post_parent . ':' . $match[1];
			if ( ! isset( $originals[ $key ] ) ) {
				$originals[ $key ] = get_posts( array_merge( $args, array(
					'post_type' => $post->post_type, 'post_parent' => (int) $post->post_parent,
					'name' => $match[1], 'posts_per_page' => self::POST_LIMIT,
				) ) );
			}
			foreach ( $originals[ $key ] as $original ) {
				$original_lang = $this->model->post->get_language( (int) $original->ID );
				if ( ! $original_lang || $original_lang->slug !== $lang->slug || ! self::similar( $original, $post ) ) {
					continue;
				}
				$items = array();
				foreach ( array( $original, $post ) as $item ) {
					$items[] = array(
						'title' => sprintf( '%s (#%d) — %s', $item->post_title, $item->ID, get_permalink( $item->ID ) ),
						'language' => $lang->name . ' (' . $lang->slug . ')',
						'action_url' => (string) get_edit_post_link( $item->ID, 'url' ),
					);
				}
				$details[] = array(
					'label' => sprintf( __( 'Possible duplicate: "%1$s" and "%2$s"', 'linguator-pro' ), $original->post_name, $post->post_name ),
					'message' => __( 'These published items have matching titles or content in the same language and a numbered URL. Review whether both are intentional; their URLs are different.', 'linguator-pro' ),
					'posts' => $items,
					'ignore_key' => 'url:numbered_copy:' . $original->ID . ':' . $post->ID,
				);
			}
		}
		$message = $details ? __( 'Review these possible accidental copies. A numbered slug alone is not an error.', 'linguator-pro' ) : __( 'No matching published items with numbered slugs were found in the inspected content.', 'linguator-pro' );
		if ( $sampled ) {
			$message .= ' ' . sprintf( __( 'Only the newest %d published items were inspected.', 'linguator-pro' ), self::POST_LIMIT );
		}
		return array( $this->result(
			'url_numbered_copies', $details ? Result::WARNING : ( $sampled ? Result::INFO : Result::PASS ),
			__( 'Possible duplicate pages with numbered URLs', 'linguator-pro' ), $message
		)->with_details( array_slice( $details, 0, self::DETAIL_LIMIT ), count( $details ) > self::DETAIL_LIMIT ) );
	}

	private static function similar( $a, $b ): bool {
		$normalize = static function ( $value ) {
			return strtolower( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) ) ) );
		};
		foreach ( array( 'post_title', 'post_content' ) as $field ) {
			$text = $normalize( $a->$field );
			if ( '' !== $text && $text === $normalize( $b->$field ) ) {
				return true;
			}
		}
		return false;
	}
}
