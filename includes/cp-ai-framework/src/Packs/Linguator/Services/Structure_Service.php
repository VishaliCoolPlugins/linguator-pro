<?php
/**
 * Translated types, custom fields and language switcher settings.
 *
 * @package CoolPluginsAiFramework
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and changes which post types, taxonomies, custom fields and switcher types Linguator uses.
 *
 * @since 1.3.0
 */
final class Structure_Service {

	/**
	 * Option holding the custom fields Linguator may translate.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const OPTION_CUSTOM_FIELDS = 'lmat_allowed_custom_fields';

	/**
	 * Option holding the enabled language switcher types.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const OPTION_SWITCHER = 'lmat_language_switcher_options';

	/**
	 * Post types and taxonomies with how each is translated.
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Post types and taxonomies.
	 */
	public static function list_types() {
		$result = array(
			'post_types' => array(),
			'taxonomies' => array(),
		);

		foreach ( array( 'post_types', 'taxonomies' ) as $kind ) {
			$info = self::type_info( $kind );

			foreach ( $info['candidates'] as $slug => $label ) {
				$source = self::source_of( $info, $slug );

				$result[ $kind ][] = array(
					'slug'        => $slug,
					'label'       => $label,
					'translated'  => 'not_translated' !== $source,
					'source'      => $source,
					'can_enable'  => 'not_translated' === $source && 'attachment' !== $slug,
					'can_disable' => 'setting' === $source,
					'items'       => 'post_types' === $kind ? self::count_posts( $slug ) : self::count_terms( $slug ),
				);
			}
		}

		$result['notes'] = array(
			'source' => __( '"always" types are built in (posts, pages, categories, tags). "setting" types were switched on by an administrator and can be switched off. "code" types are forced on by a theme or plugin and cannot be switched off here. "media_setting" is the media library, controlled by the media_support setting in update-settings.', 'linguator-pro' ),
		);

		return $result;
	}

	/**
	 * Enables or disables post types and taxonomies for translation.
	 *
	 * @since 1.3.0
	 * @param string   $action     "enable" or "disable".
	 * @param string[] $post_types Post type slugs.
	 * @param string[] $taxonomies Taxonomy slugs.
	 * @param bool     $confirm    Whether disabling is confirmed.
	 * @param bool     $dry_run    Whether to change nothing.
	 * @return array<string, mixed>|WP_Error Report, or an error.
	 */
	public static function change_types( $action, array $post_types, array $taxonomies, $confirm, $dry_run ) {
		$options = Settings_Service::options();

		if ( null === $options ) {
			return new WP_Error( 'lmat_not_ready', __( 'Linguator is not loaded.', 'linguator-pro' ) );
		}

		if ( ! in_array( $action, array( 'enable', 'disable' ), true ) ) {
			return new WP_Error( 'lmat_invalid_action', __( 'action must be "list", "enable" or "disable".', 'linguator-pro' ) );
		}

		$requested = array(
			'post_types' => self::clean_slugs( $post_types ),
			'taxonomies' => self::clean_slugs( $taxonomies ),
		);

		if ( empty( $requested['post_types'] ) && empty( $requested['taxonomies'] ) ) {
			return new WP_Error( 'lmat_nothing_to_change', __( 'Name at least one post type in post_types or one taxonomy in taxonomies. Call with action "list" to see the slugs.', 'linguator-pro' ) );
		}

		$disable = 'disable' === $action;

		if ( $disable && true !== $confirm ) {
			return new WP_Error(
				'lmat_confirm_required',
				__( 'Disabling translation for a content type is refused unless confirm is true. Existing translations are NOT deleted, but they stop being managed: the posts or terms keep their language, yet Linguator no longer filters them by language, links them as translations, shows them in the language switcher, or lets them be translated. Review the dry run, then repeat with confirm true.', 'linguator-pro' )
			);
		}

		$plan     = array();
		$stored   = array();
		$warnings = array();

		foreach ( $requested as $kind => $slugs ) {
			$info             = self::type_info( $kind );
			$stored[ $kind ]  = $info['stored'];
			$plan[ $kind ]    = array();

			foreach ( $slugs as $slug ) {
				if ( ! isset( $info['candidates'][ $slug ] ) && ! isset( $info['all'][ $slug ] ) ) {
					return new WP_Error(
						'lmat_unknown_type',
						sprintf(
							/* translators: 1: slug, 2: comma-separated list of slugs. */
							__( '"%1$s" is not a type that can be switched here. Valid: %2$s.', 'linguator-pro' ),
							$slug,
							implode( ', ', array_keys( $info['candidates'] ) )
						)
					);
				}

				$source = self::source_of( $info, $slug );
				$entry  = array(
					'slug'   => $slug,
					'source' => $source,
				);

				if ( $disable ) {
					if ( 'setting' !== $source ) {
						$entry['result'] = 'not_translated' === $source ? 'already_off' : 'refused';
						$entry['reason'] = 'not_translated' === $source
							? __( 'Not translated, nothing to disable.', 'linguator-pro' )
							: self::fixed_reason( $source );

						if ( 'refused' === $entry['result'] ) {
							return new WP_Error( 'lmat_type_locked', sprintf( /* translators: 1: slug, 2: reason. */ __( '"%1$s" cannot be disabled. %2$s', 'linguator-pro' ), $slug, $entry['reason'] ) );
						}
					} else {
						$entry['result']  = 'would_disable';
						$stored[ $kind ]  = array_values( array_diff( $stored[ $kind ], array( $slug ) ) );
						$entry['items']   = 'post_types' === $kind ? self::count_posts( $slug ) : self::count_terms( $slug );
						$warnings[]       = sprintf(
							/* translators: 1: slug, 2: number of items. */
							__( 'Disabling "%1$s": its %2$d existing items and their translations stay in the database but stop being managed by Linguator.', 'linguator-pro' ),
							$slug,
							(int) $entry['items']
						);
					}
				} else {
					if ( 'not_translated' !== $source ) {
						$entry['result'] = 'already_on';
					} elseif ( 'attachment' === $slug ) {
						return new WP_Error( 'lmat_type_locked', __( 'Media follows the media_support setting: use update-settings.', 'linguator-pro' ) );
					} elseif ( ! isset( $info['candidates'][ $slug ] ) ) {
						return new WP_Error( 'lmat_type_locked', sprintf( /* translators: %s: slug. */ __( '"%s" cannot be translated: it is not a public custom type.', 'linguator-pro' ), $slug ) );
					} else {
						$entry['result'] = 'would_enable';
						$stored[ $kind ][] = $slug;
					}
				}

				$plan[ $kind ][] = $entry;
			}
		}

		$before = array(
			'post_types' => Settings_Service::option_values( array( 'post_types' ) )['post_types'],
			'taxonomies' => Settings_Service::option_values( array( 'taxonomies' ) )['taxonomies'],
		);
		$write  = array();

		foreach ( array( 'post_types', 'taxonomies' ) as $kind ) {
			$new = array_values( array_unique( $stored[ $kind ] ) );

			if ( (array) $before[ $kind ] !== $new ) {
				$write[ $kind ] = $new;
			}
		}

		$report = array(
			'dry_run'  => (bool) $dry_run,
			'action'   => $action,
			'changed'  => false,
			'plan'     => $plan,
			'before'   => $before,
			'after'    => array_merge( $before, $write ),
			'warnings' => $warnings,
		);

		if ( ! $disable ) {
			$report['warnings'][] = __( 'Enabling gives existing items of the type that have no language the default language (up to 1000 per type per run), exactly as Linguator\'s settings screen does. Run again, or use manage-translation-links, if more remain.', 'linguator-pro' );
		}

		if ( $dry_run || empty( $write ) ) {
			return $report;
		}

		foreach ( $write as $kind => $value ) {
			$errors = $options->set( $kind, $value );
			$now    = Settings_Service::option_values( array( $kind ) )[ $kind ];

			if ( $now !== $value ) {
				$options->set( $kind, $before[ $kind ] );

				return new WP_Error(
					'lmat_setting_rejected',
					sprintf(
						/* translators: %s: option key. */
						__( 'Linguator did not accept the new list for "%s".', 'linguator-pro' ),
						$kind
					) . ' ' . implode( ' ', $errors->get_error_messages() )
				);
			}
		}

		$options->save();
		delete_option( 'rewrite_rules' );

		$model = Linguator_Guard::model();

		if ( ! $disable && null !== $model ) {
			$default = $model->get_default_language();

			foreach ( $write as $kind => $value ) {
				$added = array_diff( $value, (array) $before[ $kind ] );

				if ( empty( $added ) || ! $default ) {
					continue;
				}

				$ids    = 'post_types' === $kind ? $model->get_posts_with_no_lang( $added, 1000 ) : $model->get_terms_with_no_lang( $added, 1000 );
				$object = $model->translatable_objects->get( 'post_types' === $kind ? 'post' : 'term' );

				if ( ! empty( $ids ) ) {
					$object->set_language_in_mass( $ids, $default );
				}

				$report['given_default_language'][ $kind ] = count( $ids );
			}
		}

		foreach ( $report['plan'] as $kind => $rows ) {
			foreach ( $rows as $i => $row ) {
				$report['plan'][ $kind ][ $i ]['result'] = str_replace( array( 'would_enable', 'would_disable' ), array( 'enabled', 'disabled' ), $row['result'] );
			}
		}

		$report['dry_run'] = false;
		$report['changed'] = true;
		$report['saved']   = Settings_Service::stored_values( array_keys( $write ) );

		return $report;
	}

	/**
	 * Candidate, stored and forced types of one kind.
	 *
	 * @since 1.3.0
	 * @param string $kind "post_types" or "taxonomies".
	 * @return array<string, mixed> Info.
	 */
	private static function type_info( $kind ) {
		$is_post = 'post_types' === $kind;

		if ( $is_post ) {
			$public = get_post_types( array( 'public' => true, '_builtin' => false ) );
			$filter = 'lmat_get_post_types';
			$always = array( 'post', 'page' );
		} else {
			$public = array_diff( get_taxonomies( array( 'public' => true, '_builtin' => false ) ), get_taxonomies( array( '_lmat' => true ) ) );
			$filter = 'lmat_get_taxonomies';
			$always = array( 'category', 'post_tag' );
		}

		/** This filter is documented in Linguator's includes/models/translated/translated-post.php. */
		$candidates = array_unique( (array) apply_filters( $filter, $public, true ) );
		$forced     = array_unique( (array) apply_filters( $filter, array(), false ) );
		$stored     = Settings_Service::option_values( array( $kind ) )[ $kind ];
		$stored     = is_array( $stored ) ? array_values( $stored ) : array();
		$labels     = array();

		foreach ( $candidates as $slug ) {
			$object = $is_post ? get_post_type_object( (string) $slug ) : get_taxonomy( (string) $slug );

			if ( $object ) {
				$labels[ (string) $slug ] = (string) $object->labels->name;
			}
		}

		$all = array();

		foreach ( $always as $slug ) {
			$object = $is_post ? get_post_type_object( $slug ) : get_taxonomy( $slug );

			if ( $object ) {
				$all[ $slug ] = (string) $object->labels->name;
				$labels       = array( $slug => $all[ $slug ] ) + $labels;
			}
		}

		if ( $is_post && get_post_type_object( 'attachment' ) ) {
			$all['attachment'] = (string) get_post_type_object( 'attachment' )->labels->name;
			$labels            = $labels + array( 'attachment' => $all['attachment'] );
		}

		return array(
			'kind'       => $kind,
			'candidates' => $labels,
			'forced'     => array_map( 'strval', $forced ),
			'stored'     => $stored,
			'always'     => $always,
			'all'        => $all,
		);
	}

	/**
	 * How one type is translated.
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $info Info from type_info().
	 * @param string               $slug Type slug.
	 * @return string always, media_setting, code, setting or not_translated.
	 */
	private static function source_of( array $info, $slug ) {
		if ( in_array( $slug, $info['always'], true ) ) {
			return 'always';
		}

		if ( 'attachment' === $slug ) {
			return Settings_Service::option_values( array( 'media_support' ) )['media_support'] ? 'media_setting' : 'not_translated';
		}

		if ( in_array( $slug, $info['forced'], true ) ) {
			return 'code';
		}

		return in_array( $slug, $info['stored'], true ) ? 'setting' : 'not_translated';
	}

	/**
	 * Why a type cannot be switched off.
	 *
	 * @since 1.3.0
	 * @param string $source Source of the type.
	 * @return string Reason.
	 */
	private static function fixed_reason( $source ) {
		if ( 'always' === $source ) {
			return __( 'Posts, pages, categories and tags are always translated.', 'linguator-pro' );
		}

		if ( 'media_setting' === $source ) {
			return __( 'Media follows the media_support setting: use update-settings.', 'linguator-pro' );
		}

		return __( 'A theme or plugin turns translation on for this type in code, so the setting cannot switch it off.', 'linguator-pro' );
	}

	/**
	 * Clean list of slugs.
	 *
	 * @since 1.3.0
	 * @param array<int, mixed> $slugs Raw slugs.
	 * @return string[] Slugs.
	 */
	private static function clean_slugs( array $slugs ) {
		$clean = array();

		foreach ( $slugs as $slug ) {
			$slug = is_string( $slug ) ? sanitize_key( $slug ) : '';

			if ( '' !== $slug ) {
				$clean[] = $slug;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Number of posts of a type that are not in the trash.
	 *
	 * @since 1.3.0
	 * @param string $post_type Post type.
	 * @return int Count.
	 */
	private static function count_posts( $post_type ) {
		$counts = wp_count_posts( $post_type );
		$total  = 0;

		foreach ( (array) $counts as $status => $number ) {
			if ( ! in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
				$total += (int) $number;
			}
		}

		return $total;
	}

	/**
	 * Number of terms of a taxonomy, across all languages.
	 *
	 * @since 1.3.0
	 * @param string $taxonomy Taxonomy.
	 * @return int Count.
	 */
	private static function count_terms( $taxonomy ) {
		$count = Linguator_Guard::with_language(
			null,
			static function () use ( $taxonomy ) {
				return wp_count_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'lang'       => '',
					)
				);
			}
		);

		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * Every custom field Linguator knows, with its status.
	 *
	 * @since 1.3.0
	 * @return array<string, array<string, mixed>>|WP_Error Meta key => type and status, or an error.
	 */
	public static function known_custom_fields() {
		$class = '\Linguator\Custom_Fields\Custom_Fields';

		if ( ! class_exists( $class ) && defined( 'LINGUATOR_DIR' ) ) {
			$file = trailingslashit( LINGUATOR_DIR ) . 'admin/custom-fields/custom-fields.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}

		if ( ! class_exists( $class ) || ! method_exists( $class, 'linguator_get_custom_fields_data' ) ) {
			return new WP_Error( 'lmat_not_ready', __( 'Linguator\'s Custom Fields screen is not available.', 'linguator-pro' ) );
		}

		$fields = call_user_func( array( $class, 'linguator_get_custom_fields_data' ) );
		$result = array();

		foreach ( is_array( $fields ) ? $fields : array() as $key => $field ) {
			$result[ (string) $key ] = array(
				'type'    => isset( $field['type'] ) ? (string) $field['type'] : 'string',
				'enabled' => isset( $field['status'] ) && 'Supported' === $field['status'],
			);
		}

		ksort( $result );

		return $result;
	}

	/**
	 * Lists custom fields, or switches some on or off.
	 *
	 * @since 1.3.0
	 * @param string   $action  "list", "enable" or "disable".
	 * @param string[] $keys    Meta keys to switch.
	 * @param bool     $dry_run Whether to change nothing.
	 * @return array<string, mixed>|WP_Error Report, or an error.
	 */
	public static function custom_fields( $action, array $keys, $dry_run ) {
		$known = self::known_custom_fields();

		if ( is_wp_error( $known ) ) {
			return $known;
		}

		if ( 'list' === $action ) {
			$list = array();

			foreach ( $known as $key => $field ) {
				$list[] = array(
					'meta_key' => $key,
					'type'     => $field['type'],
					'enabled'  => $field['enabled'],
				);
			}

			return array(
				'fields'  => $list,
				'enabled' => array_values( array_filter( array_keys( $known ), static function ( $key ) use ( $known ) {
					return $known[ $key ]['enabled'];
				} ) ),
				'notice'  => __( 'Only fields listed here can be switched on or off. Enabled fields are offered to translate-post in translated_meta and to the translation screens. Fields starting with an underscore appear only when a default rule or an integration lists them.', 'linguator-pro' ),
			);
		}

		if ( ! in_array( $action, array( 'enable', 'disable' ), true ) ) {
			return new WP_Error( 'lmat_invalid_action', __( 'action must be "list", "enable" or "disable".', 'linguator-pro' ) );
		}

		$clean = array();

		foreach ( $keys as $key ) {
			if ( is_string( $key ) && '' !== $key ) {
				$clean[] = $key;
			}
		}

		$clean = array_values( array_unique( $clean ) );

		if ( empty( $clean ) ) {
			return new WP_Error( 'lmat_nothing_to_change', __( 'Name at least one meta key in meta_keys. Call with action "list" to see them.', 'linguator-pro' ) );
		}

		foreach ( $clean as $key ) {
			if ( ! isset( $known[ $key ] ) ) {
				return new WP_Error(
					'lmat_unknown_field',
					sprintf(
						/* translators: %s: meta key. */
						__( '"%s" is not in the custom fields list, so it cannot be switched. Call this ability with action "list" to see the fields.', 'linguator-pro' ),
						$key
					)
				);
			}
		}

		$saved  = get_option( self::OPTION_CUSTOM_FIELDS, array() );
		$saved  = is_array( $saved ) ? $saved : array();
		$new    = $saved;
		$enable = 'enable' === $action;
		$plan   = array();

		foreach ( $clean as $key ) {
			$was = isset( $saved[ $key ]['status'] ) && true === $saved[ $key ]['status'];
			$row = array(
				'meta_key' => $key,
				'was'      => $was,
				'will_be'  => $enable,
				'type'     => $known[ $key ]['type'],
			);

			if ( $was === $enable ) {
				$row['result'] = 'unchanged';
			} else {
				$row['result'] = $enable ? 'would_enable' : 'would_disable';
				$new[ $key ]   = array(
					'status' => $enable,
					'type'   => isset( $saved[ $key ]['type'] ) ? (string) $saved[ $key ]['type'] : $known[ $key ]['type'],
				);
			}

			$plan[] = $row;
		}

		$report = array(
			'dry_run' => (bool) $dry_run,
			'action'  => $action,
			'changed' => false,
			'plan'    => $plan,
			'notice'  => __( 'Disabling a field does not delete any stored translation; the field is simply no longer offered for translation.', 'linguator-pro' ),
		);

		if ( $dry_run || $new === $saved ) {
			return $report;
		}

		update_option( self::OPTION_CUSTOM_FIELDS, $new );

		$readback = get_option( self::OPTION_CUSTOM_FIELDS, array() );
		$readback = is_array( $readback ) ? $readback : array();
		$state    = array();

		foreach ( $clean as $key ) {
			$state[ $key ] = isset( $readback[ $key ]['status'] ) && true === $readback[ $key ]['status'];
		}

		foreach ( $report['plan'] as $i => $row ) {
			$report['plan'][ $i ]['result'] = str_replace( array( 'would_enable', 'would_disable' ), array( 'enabled', 'disabled' ), $row['result'] );
		}

		$report['dry_run'] = false;
		$report['changed'] = true;
		$report['saved']   = $state;

		return $report;
	}

	/**
	 * Language switcher types that can be enabled.
	 *
	 * @since 1.3.0
	 * @return array<string, string> Type => label.
	 */
	public static function switcher_choices() {
		$choices = array(
			'default' => __( 'Classic (widget) based', 'linguator-pro' ),
			'block'   => __( 'Block based', 'linguator-pro' ),
		);

		if ( function_exists( 'linguator_is_plugin_active' ) && linguator_is_plugin_active( 'elementor/elementor.php' ) ) {
			$choices['elementor'] = __( 'Elementor widget based', 'linguator-pro' );
		}

		return $choices;
	}

	/**
	 * Enabled language switcher types.
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Switcher settings.
	 */
	public static function get_switcher() {
		$value   = Settings_Service::option_values( array( self::OPTION_SWITCHER ) )[ self::OPTION_SWITCHER ];
		$enabled = is_array( $value ) ? array_values( $value ) : array( 'default' );
		$types   = array();

		foreach ( self::switcher_choices() as $type => $label ) {
			$types[] = array(
				'type'    => $type,
				'label'   => $label,
				'enabled' => in_array( $type, $enabled, true ),
			);
		}

		return array(
			'enabled' => $enabled,
			'types'   => $types,
			'notice'  => __( 'These settings only decide which kinds of language switcher can be used. Placing a switcher in a menu, widget area or template is done in wp-admin (Appearance > Menus, Widgets or the Site Editor).', 'linguator-pro' ),
		);
	}

	/**
	 * Changes the enabled language switcher types.
	 *
	 * @since 1.3.0
	 * @param mixed $types   Requested types.
	 * @param bool  $dry_run Whether to change nothing.
	 * @return array<string, mixed>|WP_Error Report, or an error.
	 */
	public static function update_switcher( $types, $dry_run ) {
		$options = Settings_Service::options();

		if ( null === $options ) {
			return new WP_Error( 'lmat_not_ready', __( 'Linguator is not loaded.', 'linguator-pro' ) );
		}

		if ( ! is_array( $types ) ) {
			return new WP_Error( 'lmat_invalid_value', __( 'enabled_types must be a list such as ["default","block"].', 'linguator-pro' ) );
		}

		$choices = self::switcher_choices();
		$clean   = array();

		foreach ( $types as $type ) {
			$type = is_string( $type ) ? sanitize_key( $type ) : '';

			if ( ! isset( $choices[ $type ] ) ) {
				return new WP_Error(
					'lmat_invalid_value',
					sprintf(
						/* translators: %s: comma-separated list of types. */
						__( 'Invalid switcher type. Allowed: %s.', 'linguator-pro' ),
						implode( ', ', array_keys( $choices ) )
					)
				);
			}

			$clean[] = $type;
		}

		$clean = array_values( array_unique( $clean ) );

		if ( empty( $clean ) ) {
			return new WP_Error( 'lmat_invalid_value', __( 'At least one switcher type must stay enabled, otherwise no language switcher can be shown.', 'linguator-pro' ) );
		}

		$before = self::get_switcher()['enabled'];
		$report = array(
			'dry_run' => (bool) $dry_run,
			'changed' => false,
			'before'  => $before,
			'after'   => $clean,
		);

		if ( $before === $clean ) {
			$report['notice'] = __( 'Already set to this; nothing to change.', 'linguator-pro' );

			return $report;
		}

		if ( $dry_run ) {
			return $report;
		}

		$errors = $options->set( self::OPTION_SWITCHER, $clean );
		$now    = Settings_Service::option_values( array( self::OPTION_SWITCHER ) )[ self::OPTION_SWITCHER ];

		if ( $now !== $clean ) {
			$options->set( self::OPTION_SWITCHER, $before );

			return new WP_Error( 'lmat_setting_rejected', __( 'Linguator did not accept the switcher types.', 'linguator-pro' ) . ' ' . implode( ' ', $errors->get_error_messages() ) );
		}

		$options->save();

		$report['dry_run'] = false;
		$report['changed'] = true;
		$report['saved']   = Settings_Service::stored_values( array( self::OPTION_SWITCHER ) )[ self::OPTION_SWITCHER ];

		return $report;
	}
}
