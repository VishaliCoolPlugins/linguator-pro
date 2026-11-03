<?php
/**
 * Language reads and writes.
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
 * Describes, creates, changes and removes the languages configured in Linguator.
 *
 * @since 1.2.0
 */
final class Language_Service {

	/**
	 * Every configured language.
	 *
	 * @since 1.2.0
	 * @return array<int, array<string, mixed>> Language descriptors.
	 */
	public static function get_all() {
		$model = Linguator_Guard::model();

		if ( null === $model ) {
			return array();
		}

		$default = (string) linguator_default_language();
		$out     = array();

		foreach ( (array) $model->get_languages_list() as $language ) {
			$out[] = array(
				'slug'       => (string) $language->slug,
				'name'       => (string) $language->name,
				'locale'     => (string) $language->locale,
				'flag_url'   => (string) $language->flag_url,
				'is_default' => (string) $language->slug === $default,
				'is_rtl'     => (bool) $language->is_rtl,
				'home_url'   => (string) linguator_home_url( (string) $language->slug ),
			);
		}

		return $out;
	}

	/**
	 * Slugs of every language except the default one.
	 *
	 * @since 1.2.0
	 * @return string[] Slugs.
	 */
	public static function target_slugs() {
		$default = (string) linguator_default_language();

		return array_values( array_diff( (array) linguator_languages_list(), array( $default ) ) );
	}

	/**
	 * Describes one language for the write abilities.
	 *
	 * @since 1.2.0
	 * @param object $language Linguator language object.
	 * @return array<string, mixed> Language descriptor.
	 */
	public static function format_language( $language ) {
		return array(
			'slug'       => (string) $language->slug,
			'name'       => (string) $language->name,
			'locale'     => (string) $language->locale,
			'flag'       => isset( $language->flag_code ) ? (string) $language->flag_code : '',
			'flag_url'   => (string) $language->flag_url,
			'order'      => (int) $language->term_group,
			'is_default' => (string) $language->slug === (string) linguator_default_language(),
			'is_rtl'     => (bool) $language->is_rtl,
			'term_id'    => (int) $language->term_id,
			'home_url'   => (string) linguator_home_url( (string) $language->slug ),
		);
	}

	/**
	 * Looks a locale up in Linguator's built-in list of languages.
	 *
	 * @since 1.2.0
	 * @param string $locale WordPress locale, for example "sv_SE".
	 * @return array<string, mixed> Name, code, direction and flag, or an empty array.
	 */
	public static function get_predefined( $locale ) {
		if ( ! defined( 'LINGUATOR_DIR' ) ) {
			return array();
		}

		$file = trailingslashit( LINGUATOR_DIR ) . 'admin/settings/controllers/languages.php';

		if ( ! file_exists( $file ) ) {
			return array();
		}

		$predefined = include $file;

		if ( ! is_array( $predefined ) ) {
			return array();
		}

		if ( isset( $predefined[ $locale ] ) && is_array( $predefined[ $locale ] ) ) {
			return $predefined[ $locale ];
		}

		foreach ( $predefined as $entry ) {
			if ( is_array( $entry ) && isset( $entry['locale'] ) && (string) $entry['locale'] === $locale ) {
				return $entry;
			}
		}

		$language_part = strtolower( (string) strtok( $locale, '_-' ) );

		if ( isset( $predefined[ $language_part ] ) && is_array( $predefined[ $language_part ] ) ) {
			return $predefined[ $language_part ];
		}

		return array();
	}

	/**
	 * Adds a language.
	 *
	 * Only the locale is required; name, slug, direction and flag come from
	 * Linguator's built-in list when omitted. When another language already uses
	 * the slug, Linguator falls back to the locale as slug.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $args locale, and optionally name, slug, rtl and flag.
	 * @return array<string, mixed>|WP_Error The new language, or an error.
	 */
	public static function create( array $args ) {
		$model = Linguator_Guard::model();

		if ( null === $model || ! isset( $model->languages ) ) {
			return new WP_Error( 'lmat_not_ready', __( 'Linguator is not loaded.', 'linguator-pro' ) );
		}

		$locale = isset( $args['locale'] ) ? sanitize_text_field( (string) $args['locale'] ) : '';

		if ( '' === $locale ) {
			return new WP_Error( 'lmat_missing_locale', __( 'A locale is required to create a language.', 'linguator-pro' ) );
		}

		$defaults = self::get_predefined( $locale );

		$name = isset( $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : '';

		if ( '' === $name ) {
			$name = isset( $defaults['name'] ) ? (string) $defaults['name'] : $locale;
		}

		$slug = isset( $args['slug'] ) ? sanitize_key( (string) $args['slug'] ) : '';

		if ( '' === $slug ) {
			$slug = isset( $defaults['code'] ) ? sanitize_key( (string) $defaults['code'] ) : sanitize_key( substr( $locale, 0, 2 ) );
		}

		if ( isset( $args['rtl'] ) ) {
			$rtl = (bool) filter_var( $args['rtl'], FILTER_VALIDATE_BOOLEAN );
		} else {
			$rtl = isset( $defaults['dir'] ) && 'rtl' === (string) $defaults['dir'];
		}

		$flag = isset( $args['flag'] ) ? sanitize_key( (string) $args['flag'] ) : '';

		if ( '' === $flag && isset( $defaults['flag'] ) ) {
			$flag = sanitize_key( (string) $defaults['flag'] );
		}

		$language = $model->languages->add(
			array(
				'name'       => $name,
				'slug'       => $slug,
				'locale'     => $locale,
				'rtl'        => $rtl,
				'flag'       => $flag,
				'term_group' => 0,
			)
		);

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$result = self::format_language( $language );

		if ( $result['slug'] !== $slug ) {
			$result['slug_notice'] = sprintf(
				/* translators: 1: requested slug, 2: slug actually used. */
				__( 'The slug "%1$s" was already used by another language, so Linguator used the locale "%2$s" as the slug.', 'linguator-pro' ),
				$slug,
				$result['slug']
			);
		}

		return $result;
	}

	/**
	 * Changes an existing language. Omitted fields are left as they are.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $args slug, plus name, new_slug, locale, rtl, flag and order.
	 * @return array<string, mixed>|WP_Error The updated language, or an error.
	 */
	public static function update( array $args ) {
		$slug = isset( $args['slug'] ) ? sanitize_key( (string) $args['slug'] ) : '';

		if ( '' === $slug ) {
			return new WP_Error( 'lmat_missing_slug', __( 'The slug of the language to update is required.', 'linguator-pro' ) );
		}

		$language = Linguator_Guard::language( $slug );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$update = array( 'lang_id' => (int) $language->term_id );

		foreach ( array(
			'name'     => 'name',
			'new_slug' => 'slug',
			'locale'   => 'locale',
		) as $input_key => $field ) {
			if ( ! array_key_exists( $input_key, $args ) ) {
				continue;
			}

			$value = 'new_slug' === $input_key ? sanitize_key( (string) $args[ $input_key ] ) : sanitize_text_field( (string) $args[ $input_key ] );

			if ( '' === $value ) {
				return new WP_Error(
					'lmat_invalid_language_field',
					sprintf(
						/* translators: %s: input field name. */
						__( 'The "%s" value cannot be empty. Omit the field to leave it unchanged.', 'linguator-pro' ),
						$input_key
					)
				);
			}

			$update[ $field ] = $value;
		}

		if ( isset( $update['slug'] ) && $update['slug'] !== $slug ) {
			foreach ( (array) Linguator_Guard::model()->get_languages_list() as $other ) {
				if ( (string) $other->slug === $update['slug'] ) {
					return new WP_Error(
						'lmat_non_unique_slug',
						sprintf(
							/* translators: %s: language slug. */
							__( 'Another language already uses the slug "%s".', 'linguator-pro' ),
							$update['slug']
						)
					);
				}
			}
		}

		if ( array_key_exists( 'rtl', $args ) ) {
			$update['rtl'] = (bool) filter_var( $args['rtl'], FILTER_VALIDATE_BOOLEAN );
		}

		if ( array_key_exists( 'flag', $args ) ) {
			$update['flag'] = sanitize_key( (string) $args['flag'] );
		}

		if ( array_key_exists( 'order', $args ) ) {
			$update['term_group'] = (int) $args['order'];
		}

		if ( 1 === count( $update ) ) {
			return new WP_Error( 'lmat_nothing_to_update', __( 'Supply at least one of name, new_slug, locale, rtl, flag or order.', 'linguator-pro' ) );
		}

		$updated = Linguator_Guard::model()->languages->update( $update );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return self::format_language( $updated );
	}

	/**
	 * How much content still carries a language.
	 *
	 * @since 1.2.0
	 * @param object $language Linguator language object.
	 * @return array<string, int> Number of posts and terms in the language.
	 */
	public static function content_counts( $language ) {
		$posts = 0;
		$terms = 0;
		$types = array_values( array_filter( (array) Linguator_Guard::model()->get_translated_post_types(), 'post_type_exists' ) );

		$ttid = (int) $language->get_tax_prop( 'lmat_language', 'term_taxonomy_id' );

		if ( array() !== $types && $ttid > 0 ) {
			// The language is matched by its taxonomy term, not by the "lang" query variable, so the count cannot fall back to another language.
			$query = new \WP_Query(
				array(
					'post_type'           => $types,
					'post_status'         => 'any',
					'lang'                => '',
					'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One indexed language lookup, one row requested.
						array(
							'taxonomy' => 'lmat_language',
							'field'    => 'term_taxonomy_id',
							'terms'    => array( $ttid ),
						),
					),
					'posts_per_page'      => 1,
					'fields'              => 'ids',
					'ignore_sticky_posts' => true,
					'no_found_rows'       => false,
				)
			);
			$posts = (int) $query->found_posts;
		}

		// The default category Linguator creates for every new language is not content.
		$default_category = (int) ( linguator_get_term_translations( (int) get_option( 'default_category' ) )[ (string) $language->slug ] ?? 0 );

		foreach ( Settings_Service::taxonomies() as $taxonomy ) {
			$ids = Linguator_Guard::with_language(
				$language,
				static function () use ( $taxonomy ) {
					return get_terms(
						array(
							'taxonomy'   => $taxonomy,
							'hide_empty' => false,
							'fields'     => 'ids',
						)
					);
				}
			);

			if ( ! is_wp_error( $ids ) ) {
				$terms += count( array_diff( array_map( 'intval', (array) $ids ), array( $default_category ) ) );
			}
		}

		return array(
			'posts' => $posts,
			'terms' => $terms,
		);
	}

	/**
	 * Removes a language.
	 *
	 * Refuses the default language. A language that still has posts or terms is
	 * refused too unless $force is true: Linguator then unlinks the content from
	 * the language (it is not deleted, but it is left with no language and its
	 * translation links are lost).
	 *
	 * @since 1.2.0
	 * @param string $slug  Language slug.
	 * @param bool   $force Delete even when the language still has content.
	 * @return array<string, mixed>|WP_Error What was removed, or an error.
	 */
	public static function delete( $slug, $force = false ) {
		$language = Linguator_Guard::language( $slug );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		if ( (string) $language->slug === (string) linguator_default_language() ) {
			return new WP_Error(
				'lmat_delete_default_language',
				sprintf(
					/* translators: %s: language slug. */
					__( 'Language "%s" is the default language and cannot be deleted. Make another language the default in Linguator settings first.', 'linguator-pro' ),
					$language->slug
				)
			);
		}

		$counts = self::content_counts( $language );

		if ( ! $force && ( $counts['posts'] > 0 || $counts['terms'] > 0 ) ) {
			return new WP_Error(
				'lmat_language_has_content',
				sprintf(
					/* translators: 1: language slug, 2: number of posts, 3: number of terms. */
					__( 'Language "%1$s" still has %2$d post(s) and %3$d term(s). Deleting it leaves them with no language and removes their translation links; nothing is deleted. If that is intended, call delete-language again with force set to true.', 'linguator-pro' ),
					$language->slug,
					$counts['posts'],
					$counts['terms']
				),
				$counts
			);
		}

		// Linguator gives every language a copy of the default category and leaves it behind when the language goes, so an unused copy is removed first, while it still has its language. No current language is set: with one, WordPress would take the copy for the default category and refuse to delete it.
		$default_category = (int) get_option( 'default_category' );
		$leftover         = (int) ( linguator_get_term_translations( $default_category )[ (string) $language->slug ] ?? 0 );

		if ( $leftover > 0 && $leftover !== $default_category ) {
			Linguator_Guard::with_language(
				null,
				static function () use ( $leftover ) {
					$term = get_term( $leftover, 'category' );

					// Only the untouched copy: one that has since been used for posts is kept.
					if ( $term instanceof \WP_Term && 0 === (int) $term->count ) {
						wp_delete_term( $leftover, 'category' );
					}
				}
			);
		}

		$deleted = Linguator_Guard::model()->languages->delete( (int) $language->term_id );

		if ( ! $deleted ) {
			return new WP_Error(
				'lmat_delete_failed',
				sprintf(
					/* translators: %s: language slug. */
					__( 'Failed to delete language "%s".', 'linguator-pro' ),
					$language->slug
				)
			);
		}

		return array(
			'slug'                => (string) $language->slug,
			'posts_left_unlinked' => $counts['posts'],
			'terms_left_unlinked' => $counts['terms'],
		);
	}
}
