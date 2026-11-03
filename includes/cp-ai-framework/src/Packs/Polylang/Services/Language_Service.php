<?php
/**
 * Language read and write operations.
 *
 * @package AutoPoly
 * @since   1.10.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Services;

use PLL_Language;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps Polylang's language model for the abilities layer.
 *
 * @since 1.10.0
 */
final class Language_Service {

	/**
	 * Returns every configured language.
	 *
	 * @since 1.10.0
	 * @return array<int, array<string, mixed>> Serialisable language descriptors.
	 */
	public static function get_all() {
		if ( ! Polylang_Guard::model_ready() ) {
			return array();
		}

		$languages = array();

		foreach ( PLL()->model->get_languages_list() as $language ) {
			if ( $language instanceof PLL_Language ) {
				$languages[] = self::format_language( $language );
			}
		}

		return $languages;
	}

	/**
	 * Looks up a language by slug.
	 *
	 * @since 1.10.0
	 * @param string $slug Language slug, e.g. "fr".
	 * @return PLL_Language|null The language, or null when not found.
	 */
	public static function get_by_slug( $slug ) {
		if ( ! Polylang_Guard::model_ready() ) {
			return null;
		}

		$language = PLL()->model->get_language( sanitize_key( (string) $slug ) );

		return $language instanceof PLL_Language ? $language : null;
	}

	/**
	 * Converts a PLL_Language into a serialisable array.
	 *
	 * @since 1.10.0
	 * @param PLL_Language $language Language object.
	 * @return array<string, mixed> Language descriptor.
	 */
	public static function format_language( PLL_Language $language ) {
		$flag_url = '';

		if ( method_exists( $language, 'get_display_flag_url' ) ) {
			$flag_url = (string) $language->get_display_flag_url();
		}

		return array(
			'slug'       => $language->slug,
			'name'       => $language->name,
			'locale'     => $language->locale,
			'w3c'        => $language->w3c,
			'flag_url'   => $flag_url,
			/*
			 * flag_url is a rendered image URL and cannot be fed back in. The
			 * flag code and display order are what update-language accepts, so
			 * both are exposed here or a caller has no way to read the current
			 * value before changing it.
			 */
			'flag'       => isset( $language->flag_code ) ? (string) $language->flag_code : '',
			'order'      => isset( $language->term_group ) ? (int) $language->term_group : 0,
			'is_default' => (bool) $language->is_default,
			'is_rtl'     => (bool) $language->is_rtl,
			'term_id'    => (int) $language->term_id,
		);
	}

	/**
	 * Loads Polylang's predefined defaults for a locale.
	 *
	 * The upstream reference implementation used an undefined PLL_SETTINGS_INC
	 * constant here, which is a fatal error on PHP 8. Resolved against
	 * POLYLANG_DIR instead, with graceful degradation when the file is absent.
	 *
	 * @since 1.10.0
	 * @param string $locale WordPress locale, e.g. "fr_FR".
	 * @return array<string, mixed> Predefined values, or an empty array.
	 */
	private static function get_predefined_language( $locale ) {
		if ( ! defined( 'POLYLANG_DIR' ) ) {
			return array();
		}

		$file = POLYLANG_DIR . '/src/settings/languages.php';

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

		/*
		 * Polylang keys its list by the locale WordPress ships translations for,
		 * which is often just the language part ("ja", not "ja_JP"). Without this
		 * fallback those locales get no name, direction or flag.
		 */
		$language_part = strtolower( (string) strtok( $locale, '_-' ) );

		if ( isset( $predefined[ $language_part ] ) && is_array( $predefined[ $language_part ] ) ) {
			return $predefined[ $language_part ];
		}

		return array();
	}

	/**
	 * Makes WordPress rebuild the rewrite rules on the next ordinary request.
	 *
	 * Polylang's add(), update() and delete() call flush_rewrite_rules() in the
	 * current request. The rules that give each language its /xx/ prefix come
	 * from filters PLL_Links_Directory::prepare_rewrite_rules() attaches only
	 * under conditions an ability request does not reliably meet, so the flush
	 * can save rules with no language prefix at all: every URL in the new
	 * language then 404s until someone re-saves the permalinks.
	 *
	 * Deleting the option is what PLL_Settings_Module::save_options() does for
	 * the same reason, and WordPress regenerates the rules on the next request,
	 * where Polylang's filters are in place.
	 *
	 * @since 1.12.0
	 * @return void
	 */
	private static function defer_rewrite_rules() {
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Creates a new language.
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $args Must include 'locale'. Optional: name, slug, rtl, flag.
	 * @return array<string, mixed>|WP_Error Language descriptor, or an error.
	 */
	public static function create( array $args ) {
		if ( ! Polylang_Guard::languages_writable() ) {
			return new WP_Error(
				'atfpp_polylang_unsupported',
				__( 'Creating languages requires Polylang 3.4 or later.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		$locale = isset( $args['locale'] ) ? sanitize_text_field( (string) $args['locale'] ) : '';

		if ( '' === $locale ) {
			return new WP_Error(
				'atfpp_missing_locale',
				__( 'A locale is required to create a language.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		$defaults = self::get_predefined_language( $locale );

		$name = isset( $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : '';

		if ( '' === $name ) {
			$name = isset( $defaults['name'] ) ? (string) $defaults['name'] : $locale;
		}

		$slug = isset( $args['slug'] ) ? sanitize_title( (string) $args['slug'] ) : '';

		if ( '' === $slug ) {
			$slug = isset( $defaults['code'] ) ? (string) $defaults['code'] : strtolower( substr( $locale, 0, 2 ) );
		}

		if ( isset( $args['rtl'] ) ) {
			$rtl = (int) filter_var( $args['rtl'], FILTER_VALIDATE_BOOLEAN );
		} else {
			$direction = isset( $defaults['dir'] ) ? (string) $defaults['dir'] : 'ltr';
			$rtl       = ( 'rtl' === $direction ) ? 1 : 0;
		}

		$flag = isset( $args['flag'] ) ? sanitize_key( (string) $args['flag'] ) : '';

		if ( '' === $flag && isset( $defaults['flag'] ) ) {
			$flag = (string) $defaults['flag'];
		}

		$language_args = array(
			'name'       => $name,
			'slug'       => $slug,
			'locale'     => $locale,
			'rtl'        => $rtl,
			'flag'       => $flag,
			'term_group' => 0,
		);

		$result = PLL()->model->languages->add( $language_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::defer_rewrite_rules();

		$language = self::get_by_slug( $language_args['slug'] );

		if ( null === $language ) {
			return new WP_Error(
				'atfpp_language_not_found',
				__( 'The language was created but could not be retrieved.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		return self::format_language( $language );
	}

	/**
	 * Updates an existing language.
	 *
	 * Only the fields the caller actually supplied are sent. Polylang's update()
	 * falls back to the stored value for anything absent, so an omitted field is
	 * left alone rather than blanked -- which matters because a caller fixing a
	 * typo in the name must not silently reset the flag or the display order.
	 *
	 * Renaming the slug is supported and is the main reason this exists
	 * alongside create/delete: Polylang migrates the slug across post and term
	 * translations, widgets and nav-menu locations, which deleting and
	 * recreating the language would not do.
	 *
	 * @since 1.11.0
	 * @param array<string, mixed> $args Must include 'slug'. Optional: name, new_slug, locale, rtl, flag, order.
	 * @return array<string, mixed>|WP_Error Language descriptor, or an error.
	 */
	public static function update( array $args ) {
		if ( ! Polylang_Guard::languages_writable() ) {
			return new WP_Error(
				'atfpp_polylang_unsupported',
				__( 'Updating languages requires Polylang 3.4 or later.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		$slug = isset( $args['slug'] ) ? sanitize_key( (string) $args['slug'] ) : '';

		if ( '' === $slug ) {
			return new WP_Error(
				'atfpp_missing_slug',
				__( 'The slug of the language to update is required.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		$language = self::get_by_slug( $slug );

		if ( null === $language ) {
			return new WP_Error(
				'atfpp_language_not_found',
				sprintf(
					/* translators: %s: language slug. */
					__( 'Language "%s" was not found.', 'autopoly-ai-translation-for-polylang-pro' ),
					$slug
				)
			);
		}

		$update = array( 'lang_id' => (int) $language->term_id );

		/*
		 * Blanking any of these is never a deliberate edit and Polylang's own
		 * validation message for it is opaque, so they are refused here with an
		 * explanation instead.
		 */
		foreach ( array(
			'name'     => 'name',
			'new_slug' => 'slug',
			'locale'   => 'locale',
		) as $input_key => $pll_key ) {
			if ( ! array_key_exists( $input_key, $args ) ) {
				continue;
			}

			$value = ( 'new_slug' === $input_key )
				? sanitize_title( (string) $args[ $input_key ] )
				: sanitize_text_field( (string) $args[ $input_key ] );

			if ( '' === $value ) {
				return new WP_Error(
					'atfpp_invalid_language_field',
					sprintf(
						/* translators: %s: input field name. */
						__( 'The "%s" value cannot be empty. Omit the field to leave it unchanged.', 'autopoly-ai-translation-for-polylang-pro' ),
						$input_key
					)
				);
			}

			$update[ $pll_key ] = $value;
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

		// Only lang_id: the caller named a language but asked for no change.
		if ( 1 === count( $update ) ) {
			return new WP_Error(
				'atfpp_nothing_to_update',
				__( 'Supply at least one of name, new_slug, locale, rtl, flag or order.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		$result = PLL()->model->languages->update( $update );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::defer_rewrite_rules();

		// Polylang 3.7+ hands back the language; older builds return a flag.
		if ( $result instanceof PLL_Language ) {
			return self::format_language( $result );
		}

		$updated = self::get_by_slug( isset( $update['slug'] ) ? $update['slug'] : $slug );

		if ( null === $updated ) {
			return new WP_Error(
				'atfpp_language_not_found',
				__( 'The language was updated but could not be retrieved.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		return self::format_language( $updated );
	}

	/**
	 * Deletes a language by slug.
	 *
	 * @since 1.10.0
	 * @param string $slug Language slug to remove.
	 * @return true|WP_Error True on success, or an error.
	 */
	public static function delete( $slug ) {
		if ( ! Polylang_Guard::languages_writable() ) {
			return new WP_Error(
				'atfpp_polylang_unsupported',
				__( 'Deleting languages requires Polylang 3.4 or later.', 'autopoly-ai-translation-for-polylang-pro' )
			);
		}

		$slug     = sanitize_key( (string) $slug );
		$language = self::get_by_slug( $slug );

		if ( null === $language ) {
			return new WP_Error(
				'atfpp_language_not_found',
				sprintf(
					/* translators: %s: language slug. */
					__( 'Language "%s" was not found.', 'autopoly-ai-translation-for-polylang-pro' ),
					$slug
				)
			);
		}

		$deleted = PLL()->model->languages->delete( (int) $language->term_id );

		if ( ! $deleted ) {
			return new WP_Error(
				'atfpp_delete_failed',
				sprintf(
					/* translators: %s: language slug. */
					__( 'Failed to delete language "%s".', 'autopoly-ai-translation-for-polylang-pro' ),
					$slug
				)
			);
		}

		self::defer_rewrite_rules();

		return true;
	}
}
