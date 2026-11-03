<?php
/**
 * Translation settings reads.
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
 * Reports what Linguator is set to translate.
 *
 * @since 1.2.0
 */
final class Settings_Service {

	/**
	 * Custom field keys the translation screens may translate.
	 *
	 * @since 1.2.0
	 * @return string[] Meta keys.
	 */
	public static function allowed_meta_keys() {
		$saved = get_option( 'lmat_allowed_custom_fields', array() );
		$keys  = array();

		if ( is_array( $saved ) ) {
			foreach ( $saved as $key => $field ) {
				if ( is_array( $field ) && ! empty( $field['status'] ) && true === $field['status'] ) {
					$keys[] = (string) $key;
				}
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Post types Linguator translates, without media and reusable blocks.
	 *
	 * @since 1.2.0
	 * @return string[] Post type slugs.
	 */
	public static function post_types() {
		$model = Linguator_Guard::model();

		if ( null === $model ) {
			return array();
		}

		$types = array_values( array_diff( (array) $model->get_translated_post_types(), array( 'attachment', 'wp_block' ) ) );

		return array_values( array_filter( $types, 'post_type_exists' ) );
	}

	/**
	 * Taxonomies Linguator translates.
	 *
	 * @since 1.2.0
	 * @return string[] Taxonomy slugs.
	 */
	public static function taxonomies() {
		$model = Linguator_Guard::model();

		return null === $model ? array() : array_values( array_filter( (array) $model->get_translated_taxonomies(), 'taxonomy_exists' ) );
	}

	/**
	 * The settings a translator needs to know.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Settings.
	 */
	public static function get_settings() {
		$options = function_exists( 'LMAT' ) && is_object( LMAT() ) && isset( LMAT()->options ) ? LMAT()->options : array();
		$modes   = array(
			0 => 'content',
			1 => 'directory',
			2 => 'subdomain',
			3 => 'domain',
		);
		$force   = isset( $options['force_lang'] ) ? (int) $options['force_lang'] : 1;

		return array(
			'default_language'     => (string) linguator_default_language(),
			'url_modification'     => $modes[ $force ] ?? 'directory',
			'hide_default'         => ! empty( $options['hide_default'] ),
			'translated_types'     => self::post_types(),
			'translated_taxonomies' => self::taxonomies(),
			'translatable_meta'    => self::allowed_meta_keys(),
			'redirect_lang'        => ! empty( $options['redirect_lang'] ),
			'browser_detection'    => ! empty( $options['browser'] ),
			'media_support'        => ! empty( $options['media_support'] ),
			'menu_sync_visibility' => ! empty( $options['menu_sync_visibility'] ),
			'sync'                 => isset( $options['sync'] ) ? array_values( (array) $options['sync'] ) : array(),
		);
	}

	/**
	 * Linguator's options object, or null when Linguator is not loaded.
	 *
	 * @since 1.3.0
	 * @return \Linguator\Includes\Options\Options|null Options.
	 */
	public static function options() {
		return function_exists( 'LMAT' ) && is_object( LMAT() ) && isset( LMAT()->options ) ? LMAT()->options : null;
	}

	/**
	 * Option keys update-settings may change, with their value type.
	 *
	 * @since 1.3.0
	 * @return array<string, string> Option key => "bool" or "list".
	 */
	public static function editable_options() {
		return array(
			'hide_default'         => 'bool',
			'redirect_lang'        => 'bool',
			'browser'              => 'bool',
			'media_support'        => 'bool',
			'menu_sync_visibility' => 'bool',
			'sync'                 => 'list',
		);
	}

	/**
	 * Option keys that are refused, with the reason and where to go instead.
	 *
	 * @since 1.3.0
	 * @return array<string, string> Option key => message.
	 */
	public static function refused_options() {
		$url = __( 'These shape the URL of every page on the site and need a person to review the consequences, so they are never changed by these tools: a site administrator must change them in Linguator > Settings.', 'linguator-pro' );

		return array(
			'force_lang'                   => $url,
			'domains'                      => $url,
			'rewrite'                      => $url,
			'default_lang'                 => __( 'The default language cannot be changed here: it re-targets every untranslated page and URL. Use Linguator > Languages in wp-admin.', 'linguator-pro' ),
			'post_types'                   => __( 'Use manage-translated-types to enable or disable post types for translation.', 'linguator-pro' ),
			'taxonomies'                   => __( 'Use manage-translated-types to enable or disable taxonomies for translation.', 'linguator-pro' ),
			'nav_menus'                    => __( 'Use manage-menu-locations to assign a menu to a theme location for a language.', 'linguator-pro' ),
			'lmat_language_switcher_options' => __( 'Use update-switcher-settings to choose the language switcher types.', 'linguator-pro' ),
			'api_keys'                     => __( 'API keys are secrets and are never read or written through these tools. A site administrator sets them in Linguator > Settings, or defines them in wp-config.php.', 'linguator-pro' ),
			'ai_translation_configuration' => __( 'The AI translation configuration holds provider and key settings and is never changed through these tools. A site administrator sets it in Linguator > Settings.', 'linguator-pro' ),
			'lmat_feedback_data'           => __( 'This is a privacy consent choice and only the site owner may change it, in Linguator > Settings.', 'linguator-pro' ),
		);
	}

	/**
	 * Data kept in sync between translations that can be chosen, with labels.
	 *
	 * @since 1.3.0
	 * @return array<string, string> Sync key => label.
	 */
	public static function sync_choices() {
		if ( ! class_exists( '\Linguator\Modules\sync\Linguator_Settings_Sync' ) ) {
			return array();
		}

		return \Linguator\Modules\sync\Linguator_Settings_Sync::list_metas_to_sync();
	}

	/**
	 * Current values of some Linguator options.
	 *
	 * @since 1.3.0
	 * @param string[] $keys Option keys.
	 * @return array<string, mixed> Option key => value.
	 */
	public static function option_values( array $keys ) {
		$options = self::options();
		$values  = array();

		foreach ( $keys as $key ) {
			$value          = null === $options ? null : $options->get( $key );
			$values[ $key ] = is_array( $value ) ? array_values( $value ) : $value;
		}

		return $values;
	}

	/**
	 * The values Linguator stored in the database for some options.
	 *
	 * @since 1.3.0
	 * @param string[] $keys Option keys.
	 * @return array<string, mixed> Option key => stored value.
	 */
	public static function stored_values( array $keys ) {
		$stored = get_option( 'linguator', array() );
		$stored = is_array( $stored ) ? $stored : array();
		$values = array();

		foreach ( $keys as $key ) {
			$value          = $stored[ $key ] ?? null;
			$values[ $key ] = is_array( $value ) ? array_values( $value ) : $value;
		}

		return $values;
	}

	/**
	 * Changes a set of Linguator options through their option classes.
	 *
	 * Every value goes through the option's own validation. A dry run applies
	 * the values in memory only, reports the difference and puts the old values
	 * back; a real run saves and reads the values back from the database.
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $input   Option key => requested value.
	 * @param bool                 $dry_run Whether to change nothing.
	 * @return array<string, mixed>|WP_Error Report, or an error.
	 */
	public static function update_options( array $input, $dry_run ) {
		$options = self::options();

		if ( null === $options ) {
			return new WP_Error( 'lmat_not_ready', __( 'Linguator is not loaded.', 'linguator-pro' ) );
		}

		$editable = self::editable_options();
		$refused  = self::refused_options();
		$requests = array();

		foreach ( $input as $key => $value ) {
			$key = (string) $key;

			if ( 'dry_run' === $key ) {
				continue;
			}

			if ( isset( $refused[ $key ] ) ) {
				return new WP_Error(
					'lmat_setting_refused',
					sprintf(
						/* translators: 1: option key, 2: explanation. */
						__( 'The setting "%1$s" cannot be changed here. %2$s', 'linguator-pro' ),
						$key,
						$refused[ $key ]
					)
				);
			}

			if ( ! isset( $editable[ $key ] ) ) {
				return new WP_Error(
					'lmat_unknown_setting',
					sprintf(
						/* translators: 1: option key, 2: comma-separated list of settings. */
						__( 'Unknown setting "%1$s". Settings that can be changed: %2$s.', 'linguator-pro' ),
						$key,
						implode( ', ', array_keys( $editable ) )
					)
				);
			}

			$clean = self::normalize_value( $key, $editable[ $key ], $value );

			if ( is_wp_error( $clean ) ) {
				return $clean;
			}

			$requests[ $key ] = $clean;
		}

		if ( empty( $requests ) ) {
			return new WP_Error(
				'lmat_nothing_to_change',
				sprintf(
					/* translators: %s: comma-separated list of settings. */
					__( 'Nothing to change. Send at least one of: %s.', 'linguator-pro' ),
					implode( ', ', array_keys( $editable ) )
				)
			);
		}

		$keys   = array_keys( $requests );
		$before = self::option_values( $keys );
		$notes  = array();

		foreach ( $requests as $key => $value ) {
			$errors = $options->set( $key, $value );
			$now    = self::option_values( array( $key ) );

			if ( ! self::same( $now[ $key ], $value ) ) {
				self::restore( $options, $before );

				return new WP_Error(
					'lmat_setting_rejected',
					sprintf(
						/* translators: 1: option key, 2: reason. */
						__( 'Linguator did not accept the value for "%1$s". %2$s', 'linguator-pro' ),
						$key,
						self::error_text( $errors, $key )
					)
				);
			}

			if ( $errors->has_errors() ) {
				$notes[] = self::error_text( $errors, $key );
			}
		}

		$after   = self::option_values( $keys );
		$changes = array();

		foreach ( $keys as $key ) {
			if ( ! self::same( $before[ $key ], $after[ $key ] ) ) {
				$changes[ $key ] = array(
					'before' => $before[ $key ],
					'after'  => $after[ $key ],
				);
			}
		}

		$warnings = self::warnings_for( array_keys( $changes ) );
		$report   = array(
			'dry_run'   => (bool) $dry_run,
			'changed'   => false,
			'changes'   => (object) $changes,
			'unchanged' => array_values( array_diff( $keys, array_keys( $changes ) ) ),
			'warnings'  => array_merge( $warnings, $notes ),
		);

		if ( $dry_run || empty( $changes ) ) {
			self::restore( $options, $before );

			return $report;
		}

		$options->save();

		foreach ( $changes as $key => $change ) {
			$report['side_effects'][ $key ] = self::after_change( $key, $change['before'], $change['after'] );
		}

		$report['dry_run'] = false;
		$report['changed'] = true;
		$report['saved']   = self::stored_values( $keys );

		return $report;
	}

	/**
	 * Turns a requested value into the type the option expects.
	 *
	 * @since 1.3.0
	 * @param string $key   Option key.
	 * @param string $type  "bool" or "list".
	 * @param mixed  $value Requested value.
	 * @return bool|string[]|WP_Error Clean value, or an error.
	 */
	private static function normalize_value( $key, $type, $value ) {
		if ( 'bool' === $type ) {
			$bool = is_bool( $value ) ? $value : filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

			if ( null === $bool ) {
				return new WP_Error(
					'lmat_invalid_value',
					sprintf(
						/* translators: %s: option key. */
						__( 'The setting "%s" takes true or false.', 'linguator-pro' ),
						$key
					)
				);
			}

			return $bool;
		}

		$choices = self::sync_choices();

		if ( ! is_array( $value ) ) {
			return new WP_Error(
				'lmat_invalid_value',
				sprintf(
					/* translators: 1: option key, 2: comma-separated list of allowed values. */
					__( 'The setting "%1$s" takes a list. Allowed values: %2$s.', 'linguator-pro' ),
					$key,
					implode( ', ', array_keys( $choices ) )
				)
			);
		}

		$clean = array();

		foreach ( $value as $item ) {
			$item = is_string( $item ) ? $item : '';

			if ( ! isset( $choices[ $item ] ) ) {
				return new WP_Error(
					'lmat_invalid_value',
					sprintf(
						/* translators: 1: option key, 2: comma-separated list of allowed values. */
						__( 'Invalid value for "%1$s". Allowed values: %2$s.', 'linguator-pro' ),
						$key,
						implode( ', ', array_keys( $choices ) )
					)
				);
			}

			$clean[] = $item;
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Whether two option values are equal, ignoring list order.
	 *
	 * @since 1.3.0
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return bool True when equal.
	 */
	private static function same( $a, $b ) {
		if ( is_array( $a ) && is_array( $b ) ) {
			sort( $a );
			sort( $b );
		}

		return $a === $b;
	}

	/**
	 * Puts option values back in memory.
	 *
	 * @since 1.3.0
	 * @param object               $options Options object.
	 * @param array<string, mixed> $values  Option key => value.
	 * @return void
	 */
	private static function restore( $options, array $values ) {
		foreach ( $values as $key => $value ) {
			$options->set( (string) $key, $value );
		}
	}

	/**
	 * Readable text for an option's validation errors.
	 *
	 * @since 1.3.0
	 * @param WP_Error $errors Errors.
	 * @param string   $key    Option key.
	 * @return string Message.
	 */
	private static function error_text( WP_Error $errors, $key ) {
		$messages = $errors->get_error_messages();

		if ( ! empty( $messages ) ) {
			return implode( ' ', array_map( 'wp_strip_all_tags', $messages ) );
		}

		if ( 'hide_default' === $key ) {
			return __( 'Hiding the default language is not possible while languages use separate domains.', 'linguator-pro' );
		}

		if ( 'browser' === $key ) {
			return __( 'Browser language detection needs cookies shared between domains, which this site does not support.', 'linguator-pro' );
		}

		return __( 'The value is not valid for this setting.', 'linguator-pro' );
	}

	/**
	 * Warnings about the effect of changing some options.
	 *
	 * @since 1.3.0
	 * @param string[] $keys Changed option keys.
	 * @return string[] Warnings.
	 */
	private static function warnings_for( array $keys ) {
		$warnings = array();

		if ( in_array( 'hide_default', $keys, true ) ) {
			$warnings[] = __( 'hide_default changes the URL of every page in the default language (the language code is added or removed). Old URLs stop working unless redirected, and permalinks are rebuilt.', 'linguator-pro' );
		}

		if ( in_array( 'redirect_lang', $keys, true ) ) {
			$warnings[] = __( 'redirect_lang changes the URL of the front page in every language.', 'linguator-pro' );
		}

		if ( in_array( 'media_support', $keys, true ) ) {
			$warnings[] = __( 'media_support changes whether the media library is translated. Switching it on gives media with no language the default language (up to 1000 files per run).', 'linguator-pro' );
		}

		if ( in_array( 'sync', $keys, true ) ) {
			$warnings[] = __( 'sync applies from the next time a post is saved; existing translations are not rewritten now.', 'linguator-pro' );
		}

		return $warnings;
	}

	/**
	 * Does what Linguator's settings screen does after an option changed.
	 *
	 * @since 1.3.0
	 * @param string $key    Option key.
	 * @param mixed  $before Old value.
	 * @param mixed  $after  New value.
	 * @return array<string, mixed> What was done.
	 */
	private static function after_change( $key, $before, $after ) {
		$model = Linguator_Guard::model();
		$done  = array();

		if ( 'hide_default' === $key ) {
			delete_option( 'rewrite_rules' );
			$done['rewrite_rules'] = 'rebuilt on the next request';
		}

		if ( 'redirect_lang' === $key && null !== $model ) {
			$model->clean_languages_cache();
			$done['language_cache'] = 'cleared';
		}

		if ( 'media_support' === $key && null !== $model && ! $before && $after ) {
			$default = $model->get_default_language();
			$ids     = $default ? $model->get_posts_with_no_lang( array( 'attachment' ), 1000 ) : array();

			if ( ! empty( $ids ) ) {
				$model->translatable_objects->get( 'post' )->set_language_in_mass( $ids, $default );
			}

			$done['media_given_default_language'] = count( $ids );
		}

		return $done;
	}
}
