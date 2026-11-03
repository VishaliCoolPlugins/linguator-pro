<?php
/**
 * Translation writes for the abilities subsystem.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Services;

use AUTOMLP_PRO_WPML\Includes\Queue\Queue_Table;
use AUTOMLP_PRO_WPML\Includes\Wpml\Automlp_Wpml_Bulk_Translation_Queue as Bulk_Translation_Queue;
use AUTOMLP_PRO_WPML\Includes\Wpml\Background_Flow;
use AUTOMLP_PRO_WPML\Includes\Wpml\Job_Listener;
use AUTOMLP_PRO_WPML\Includes\Wpml\Job_Sender;
use AUTOMLP_PRO_WPML\Includes\Wpml\Writer as Wpml_Writer;
use WP_Error;
use WPML_AT_PRO_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and updates WPML translations for the AI agent abilities.
 *
 * With the background flow on (the default) a translation is saved exactly
 * the way the bulk screen and the queue save it: a WPML translation job is
 * created for the AutoMLP translator and completed with wpml_tm_save_data,
 * so WPML itself creates or updates the translated post, links it by trid,
 * applies page-builder strings and marks the job complete.
 *
 * With the background flow switched off, the direct writer
 * Create_Translated_Post is used instead. Its constructor ends the request
 * on several conditions, so every one is checked here first; the failures
 * that can only surface mid-write are handled by Json_Exit_Guard.
 *
 * @since 1.5.0
 */
final class Translation_Writer {

	/**
	 * Request constant marking AutoMLP's create-translation path.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const CREATE_CONSTANT = 'DOING_AUTOMLP_PRO_WPML_CREATE_TRANSLATED_POST';

	/**
	 * Queue row this request is writing, or 0 when none.
	 *
	 * @since 1.6.2
	 * @var int
	 */
	private static $writing_row = 0;

	/**
	 * Whether the shutdown handler is registered.
	 *
	 * @since 1.6.2
	 * @var bool
	 */
	private static $shutdown_registered = false;

	/**
	 * Remembers the queue row being written, so a crash can release it.
	 *
	 * @since 1.6.2
	 * @param int $row_id Queue row id.
	 * @return void
	 */
	private static function track_writing_row( $row_id ) {
		self::$writing_row = (int) $row_id;

		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( array( __CLASS__, 'release_writing_row_on_shutdown' ) );
		}
	}

	/**
	 * Fails the tracked row, if any, so it is not left in "writing".
	 *
	 * Queue_Table::fail() leaves a finished or stopped row alone.
	 *
	 * @since 1.6.2
	 * @param string $message Reason recorded on the row.
	 * @return void
	 */
	private static function release_writing_row( $message ) {
		$row_id            = self::$writing_row;
		self::$writing_row = 0;

		if ( $row_id <= 0 ) {
			return;
		}

		try {
			Queue_Table::fail( $row_id, $message, 0 );
		} catch ( \Throwable $e ) {
			// Nothing more can be done at this point.
			return;
		}
	}

	/**
	 * Shutdown handler: the request ended (fatal error, exit or wp_die) while a row was being written.
	 *
	 * @since 1.6.2
	 * @return void
	 */
	public static function release_writing_row_on_shutdown() {
		if ( self::$writing_row <= 0 ) {
			return;
		}

		$error   = error_get_last();
		$message = __( 'The request ended while this translation was being written. The row was released so the translation can be retried.', 'wpml-translation-check-pro' );

		if ( is_array( $error ) && in_array( (int) $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			$message .= ' ' . $error['message'];
		}

		self::release_writing_row( $message );
	}

	/**
	 * Creates the translation of one taxonomy term from translations the caller supplies.
	 *
	 * Same steps as the bulk screen's term flow: Job_Sender queues a term row,
	 * the row is claimed at once so the dispatcher never translates it a second
	 * time, and the queue Writer saves it through WPML. A term that already has a
	 * translation in the language is left untouched.
	 *
	 * @since 1.6.2
	 * @param array<string, mixed> $args {
	 *     @type int    $term_id         Source term.
	 *     @type string $taxonomy        Its taxonomy.
	 *     @type string $target_language Language to write.
	 *     @type string $name            Translated name.
	 *     @type string $description     Optional translated description.
	 * }
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function write_term( array $args ) {
		$term_id         = isset( $args['term_id'] ) ? absint( $args['term_id'] ) : 0;
		$taxonomy        = isset( $args['taxonomy'] ) ? sanitize_key( (string) $args['taxonomy'] ) : '';
		$target_language = isset( $args['target_language'] ) ? sanitize_text_field( (string) $args['target_language'] ) : '';
		$name            = isset( $args['name'] ) ? trim( (string) $args['name'] ) : '';
		$description     = isset( $args['description'] ) ? trim( (string) $args['description'] ) : '';

		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'automlp_taxonomy_not_found',
				__( 'That taxonomy does not exist on this site. Call list-content-types for the taxonomies that do.', 'wpml-translation-check-pro' )
			);
		}

		if ( ! (bool) apply_filters( 'wpml_is_translated_taxonomy', false, $taxonomy ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			return new WP_Error(
				'automlp_taxonomy_not_translated',
				sprintf(
					/* translators: %s: taxonomy slug. */
					__( 'WPML does not translate the taxonomy "%s". A site administrator has to switch it on under WPML > Settings > Taxonomies Translation. Do not retry until they confirm it is done.', 'wpml-translation-check-pro' ),
					$taxonomy
				)
			);
		}

		$term = $term_id > 0 ? get_term( $term_id, $taxonomy ) : null;

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error(
				'automlp_not_found',
				sprintf(
					/* translators: 1: term ID, 2: taxonomy slug. */
					__( 'Term %1$d was not found in the taxonomy "%2$s".', 'wpml-translation-check-pro' ),
					$term_id,
					$taxonomy
				)
			);
		}

		$tax_object = get_taxonomy( $taxonomy );
		$edit_cap   = ( $tax_object && isset( $tax_object->cap->edit_terms ) ) ? (string) $tax_object->cap->edit_terms : 'manage_categories';

		if ( ! current_user_can( $edit_cap ) && ! current_user_can( 'edit_term', $term_id ) ) {
			return new WP_Error(
				'automlp_forbidden',
				__( 'The current user cannot edit terms in this taxonomy, so a translation cannot be written.', 'wpml-translation-check-pro' )
			);
		}

		if ( ! Language_Service::is_active( $target_language ) ) {
			return new WP_Error(
				'automlp_language_inactive',
				sprintf(
					/* translators: 1: requested language code, 2: comma-separated active codes. */
					__( '"%1$s" is not an active language on this site. Active languages are: %2$s. Call list-languages and use one of those codes.', 'wpml-translation-check-pro' ),
					$target_language,
					implode( ', ', Language_Service::get_codes() )
				)
			);
		}

		if ( '' === $name ) {
			return new WP_Error(
				'automlp_nothing_to_write',
				__( 'Nothing was supplied to write. Pass the translated "name" of the term.', 'wpml-translation-check-pro' )
			);
		}

		$details = apply_filters( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'wpml_element_language_details',
			null,
			array(
				'element_id'   => (int) $term->term_taxonomy_id,
				'element_type' => 'tax_' . $taxonomy,
			)
		);

		$source_language = is_object( $details ) && ! empty( $details->language_code )
			? (string) $details->language_code
			: (string) apply_filters( 'wpml_default_language', '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( $source_language === $target_language ) {
			return new WP_Error(
				'automlp_same_language',
				sprintf(
					/* translators: %s: language code. */
					__( 'That term is already in "%s", so it cannot be translated into the same language. Pass a different code from list-languages.', 'wpml-translation-check-pro' ),
					$target_language
				)
			);
		}

		$existing = (int) apply_filters( 'wpml_object_id', $term_id, $taxonomy, false, $target_language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( $existing > 0 && $existing !== $term_id ) {
			return new WP_Error(
				'automlp_term_already_translated',
				sprintf(
					/* translators: 1: language code, 2: existing term ID. */
					__( 'That term already has a "%1$s" translation (term %2$d). Nothing was changed.', 'wpml-translation-check-pro' ),
					$target_language,
					$existing
				)
			);
		}

		Job_Listener::reset_created();

		$sent   = Job_Sender::send_terms( array( $term_id ), array( $target_language ), $taxonomy, '' );
		$row_id = 0;

		foreach ( Job_Listener::created_ids() as $created ) {
			$candidate = Queue_Table::get( $created );

			if ( $candidate && 'term' === (string) $candidate['kind'] && (int) $candidate['source_id'] === $term_id && (string) $candidate['to_lang'] === $target_language ) {
				$row_id = (int) $created;
				break;
			}
		}

		if ( 0 === $row_id ) {
			$reason = is_array( $sent ) && ! empty( $sent['errors'] ) ? (string) reset( $sent['errors'] ) : __( 'The term could not be queued for translation.', 'wpml-translation-check-pro' );

			return new WP_Error( 'automlp_not_queued', $reason );
		}

		if ( ! Queue_Table::claim( $row_id ) || ! Queue_Table::edit_if_state( $row_id, Queue_Table::STATE_CLAIMED, array( 'state' => Queue_Table::STATE_WRITING ) ) ) {
			return new WP_Error(
				'automlp_job_busy',
				__( 'AutoMLP\'s background queue picked up this term first. Wait for it to finish, then check the result with get-term-translations.', 'wpml-translation-check-pro' )
			);
		}

		self::track_writing_row( $row_id );

		try {
			$translated = array( 'name' => $name );

			if ( '' !== $description ) {
				$translated['description'] = current_user_can( 'unfiltered_html' ) ? $description : wp_kses_post( $description );
			}

			$row        = Queue_Table::get( $row_id );
			$source_map = Queue_Table::read_source_map( $row );

			/** This filter is documented in includes/queue/class-dispatcher.php */
			$written = apply_filters( 'automlp_write_translation', null, $translated, $row, $source_map );

			if ( null === $written ) {
				$written = new WP_Error( 'automlp_no_writer', __( 'No writer is registered to save this translation.', 'wpml-translation-check-pro' ) );
			}

			if ( is_wp_error( $written ) ) {
				Queue_Table::fail( $row_id, $written->get_error_message(), 0 );

				return $written;
			}

			$translated_term_id = is_array( $written ) && ! empty( $written['result_id'] ) ? (int) $written['result_id'] : 0;

			if ( 0 === $translated_term_id ) {
				Queue_Table::fail( $row_id, __( 'WPML saved the term but returned no translated term ID.', 'wpml-translation-check-pro' ), 0 );

				return new WP_Error(
					'automlp_write_failed',
					__( 'WPML reported success but returned no translated term ID, so the result could not be confirmed.', 'wpml-translation-check-pro' )
				);
			}

			Queue_Table::finish(
				$row_id,
				array(
					'field_count'       => count( $translated ),
					'fields_translated' => count( $translated ),
					'result_id'         => $translated_term_id,
				)
			);

			/** This action is documented in includes/queue/class-dispatcher.php */
			do_action(
				'automlp_job_completed',
				$row_id,
				$row,
				array(
					'translated' => $translated,
					'source_map' => $source_map,
					'written'    => $written,
					'time_taken' => 0,
					'provider'   => '',
				)
			);
		} catch ( \Throwable $e ) {
			self::release_writing_row( $e->getMessage() );

			return new WP_Error(
				'automlp_write_failed',
				sprintf(
					/* translators: %s: underlying error message. */
					__( 'The term translation could not be written: %s', 'wpml-translation-check-pro' ),
					$e->getMessage()
				)
			);
		} finally {
			self::$writing_row = 0;
		}

		$translated_term = get_term( $translated_term_id, $taxonomy );

		return array(
			'source_term_id'     => $term_id,
			'translated_term_id' => $translated_term_id,
			'taxonomy'           => $taxonomy,
			'source_language'    => $source_language,
			'target_language'    => $target_language,
			'created'            => true,
			'name'               => ( $translated_term && ! is_wp_error( $translated_term ) ) ? (string) $translated_term->name : $name,
			'slug'               => ( $translated_term && ! is_wp_error( $translated_term ) ) ? (string) $translated_term->slug : '',
			'description_saved'  => isset( $translated['description'] ),
			'outcome'            => 'created',
			'next_action'        => __( 'Confirm with get-term-translations. Translate the posts that use this term next if they are not translated yet.', 'wpml-translation-check-pro' ),
		);
	}

	/**
	 * Creates or updates a translation of a post.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $args {
	 *     @type int    $post_id         Source post.
	 *     @type string $target_language Language to write.
	 *     @type string $title           Title for the translation.
	 *     @type string $excerpt         Excerpt for the translation.
	 *     @type array  $contents        Keyed translated strings from get-content.
	 *     @type string $post_name       Optional slug.
	 *     @type string $editor_type     Optional; resolved when omitted.
	 *     @type string $post_status     Optional; applied after the write, whether created or updated. When omitted WPML's translated document status setting decides.
	 *     @type bool   $skip_duplicate_check Optional; create even when an unlinked look-alike exists.
	 *     @type bool   $copy_source     Optional; fill every field that was not supplied with the source text.
	 * }
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function write( array $args ) {
		$post_id         = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
		$target_language = isset( $args['target_language'] ) ? sanitize_text_field( (string) $args['target_language'] ) : '';
		$title           = isset( $args['title'] ) ? (string) $args['title'] : '';
		$excerpt         = isset( $args['excerpt'] ) ? (string) $args['excerpt'] : '';
		$contents        = isset( $args['contents'] ) && is_array( $args['contents'] ) ? $args['contents'] : array();
		$post_name       = isset( $args['post_name'] ) ? sanitize_text_field( (string) $args['post_name'] ) : '';
		$editor_type     = isset( $args['editor_type'] ) ? (string) $args['editor_type'] : '';
		$post_status     = isset( $args['post_status'] ) ? sanitize_key( (string) $args['post_status'] ) : '';
		$skip_duplicates = ! empty( $args['skip_duplicate_check'] );

		if ( '' !== $post_status && ! in_array( $post_status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			return new WP_Error(
				'automlp_invalid_status',
				__( 'post_status must be one of "publish", "draft", "pending", "private".', 'wpml-translation-check-pro' )
			);
		}

		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$post = $post_id > 0 ? get_post( $post_id ) : null;

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

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'automlp_forbidden',
				__( 'The current user cannot edit that post, so its translation cannot be written.', 'wpml-translation-check-pro' )
			);
		}

		if ( ! Content_Service::is_post_type_translatable( $post->post_type ) ) {
			return new WP_Error(
				'automlp_type_not_translated',
				sprintf(
					/* translators: %s: post type slug. */
					__( 'WPML is not set to translate "%s", so a translation cannot be created for it. Call list-content-types and pass on its notice: a site administrator has to switch the type on under WPML > Settings > Post Types Translation. Do not retry until they confirm it is done.', 'wpml-translation-check-pro' ),
					$post->post_type
				)
			);
		}

		if ( ! Language_Service::is_active( $target_language ) ) {
			return new WP_Error(
				'automlp_language_inactive',
				sprintf(
					/* translators: 1: requested language code, 2: comma-separated active codes. */
					__( '"%1$s" is not an active language on this site. Active languages are: %2$s. Call list-languages and use one of those codes.', 'wpml-translation-check-pro' ),
					$target_language,
					implode( ', ', Language_Service::get_codes() )
				)
			);
		}

		$source_language = self::resolve_source_language( $post_id, (string) $post->post_type );

		if ( '' === $source_language ) {
			return new WP_Error(
				'automlp_no_source_language',
				__( 'That post has no language assigned, so WPML has nothing to translate it from. This happens when a post type is switched on for translation after its content already existed. Give it a language first with manage-translation-links, action "set_language", then retry.', 'wpml-translation-check-pro' )
			);
		}

		if ( $source_language === $target_language ) {
			return new WP_Error(
				'automlp_same_language',
				sprintf(
					/* translators: %s: language code. */
					__( 'That post is already in "%s", so it cannot be translated into the same language. Pass a different code from list-languages.', 'wpml-translation-check-pro' ),
					$target_language
				)
			);
		}

		if ( '' === $editor_type ) {
			$editor_type = self::resolve_editor_type( $post_id, $post );
		}

		$supported = WPML_AT_PRO_Helper::supported_editors( (string) $post->post_type );

		if ( ! in_array( $editor_type, $supported, true ) ) {
			return new WP_Error(
				'automlp_editor_unsupported',
				sprintf(
					/* translators: 1: detected editor, 2: comma-separated supported editors. */
					__( 'AutoMLP cannot write a translation for content built with "%1$s". It supports: %2$s. Call get-editor-type to see what this post uses.', 'wpml-translation-check-pro' ),
					'' === $editor_type ? __( 'unknown', 'wpml-translation-check-pro' ) : $editor_type,
					implode( ', ', $supported )
				)
			);
		}

		/*
		 * The constructor rejects a call with nothing to write. Checking it here
		 * keeps the failure a WP_Error rather than an exit, and the message can
		 * explain what to pass.
		 */
		if ( empty( $contents ) && '' === trim( $title ) && '' === trim( $excerpt ) ) {
			// A call that carries only a status changes the status of a translation that already exists, for example to publish a draft.
			$linked = '' !== $post_status ? (int) WPML_AT_PRO_Helper::get_existing_translation_id( $post_id, (string) $post->post_type, $target_language ) : 0;

			if ( $linked > 0 && null !== get_post( $linked ) ) {
				return self::set_status_only( $post_id, $linked, $source_language, $target_language, $editor_type, $post_status );
			}

			return new WP_Error(
				'automlp_nothing_to_write',
				__( 'Nothing was supplied to write. Pass at least a title, an excerpt or one entry in "contents" (or, to change the status of a translation that already exists, post_status). Call get-content first and translate the values it returns, keeping its keys exactly as given.', 'wpml-translation-check-pro' )
			);
		}

		$contents = self::normalize_contents( $contents );
		$existing = WPML_AT_PRO_Helper::get_existing_translation_id( $post_id, (string) $post->post_type, $target_language );

		/*
		 * WPML only knows about translations in the source's own group. A copy
		 * made by hand, or by an earlier run that lost track of its result, sits
		 * outside that group, and writing now would add a second version next to
		 * it. Stop and let the caller decide instead of guessing which to keep.
		 */
		if ( 0 === (int) $existing && ! $skip_duplicates ) {
			$candidates = self::find_duplicate_candidates( $post, $target_language );

			if ( ! empty( $candidates ) ) {
				return self::duplicate_error( $post_id, $target_language, $candidates );
			}
		}

		// Updating rewrites the translated post, so the user must be able to edit it too.
		if ( (int) $existing > 0 && ! current_user_can( 'edit_post', (int) $existing ) ) {
			return new WP_Error(
				'automlp_cannot_edit_translation',
				__( 'The current user is not allowed to edit the existing translation of this post.', 'wpml-translation-check-pro' )
			);
		}

		// WordPress needs the publish capability for private posts as well.
		if ( in_array( $post_status, array( 'publish', 'private' ), true ) ) {
			$type_object = get_post_type_object( $post->post_type );

			if ( $type_object && ! current_user_can( $type_object->cap->publish_posts ) ) {
				return new WP_Error(
					'automlp_cannot_publish',
					__( 'The current user is not allowed to publish this post type. Call again without post_status to save the translation as a draft.', 'wpml-translation-check-pro' )
				);
			}
		}

		$slug_ignored = false;

		if ( self::uses_wpml_jobs() ) {
			try {
				$saved = self::write_through_wpml_job(
					$post,
					$contents,
					$title,
					$excerpt,
					$source_language,
					$target_language,
					$post_name,
					$post_status,
					(int) $existing,
					! empty( $args['copy_source'] )
				);
			} catch ( \Throwable $e ) {
				// The queue row must not stay in "writing", which would block this post and language until the stale-row sweep.
				self::release_writing_row( $e->getMessage() );

				return new WP_Error(
					'automlp_write_failed',
					sprintf(
						/* translators: %s: underlying error message. */
						__( 'The translation could not be written: %s', 'wpml-translation-check-pro' ),
						$e->getMessage()
					)
				);
			} finally {
				self::$writing_row = 0;
			}

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$translated_post_id = (int) $saved['translated_post_id'];
			$strings_written    = (int) $saved['strings_written'];
			$slug_ignored       = ! empty( $saved['slug_ignored'] );
		} else {
			$translated_post_id = self::write_through_legacy_writer( $post_id, $contents, $title, $excerpt, $source_language, $target_language, $editor_type, $post_name );

			if ( is_wp_error( $translated_post_id ) ) {
				return $translated_post_id;
			}

			$strings_written = count( $contents );

			if ( '' !== $post_status && $post_status !== get_post_status( $translated_post_id ) ) {
				wp_update_post(
					array(
						'ID'          => $translated_post_id,
						'post_status' => $post_status,
					)
				);
			}
		}

		$layout_repaired = self::repair_broken_elementor_layout( $post_id, $translated_post_id, $editor_type );
		$result = array(
			'source_post_id'     => $post_id,
			'translated_post_id' => $translated_post_id,
			'created'            => 0 === (int) $existing,
			'updated'            => 0 !== (int) $existing,
			'source_language'    => $source_language,
			'target_language'    => $target_language,
			'editor_type'        => $editor_type,
			'title'              => (string) get_the_title( $translated_post_id ),
			'post_status'        => (string) get_post_status( $translated_post_id ),
			'strings_written'    => $strings_written,
			'permalink'          => html_entity_decode( (string) get_permalink( $translated_post_id ) ),
			'edit_link'          => html_entity_decode( (string) get_edit_post_link( $translated_post_id, 'raw' ) ),
		);

		if ( $layout_repaired ) {
			$result['elementor_layout_repaired'] = true;
		}

		$warnings = array();

		// Informational: the translation is complete, so this is not a warning that asks the caller to retry.
		if ( $slug_ignored ) {
			$result['slug_notice'] = __( 'The slug you supplied was not applied: WPML\'s "Page URL" setting is not "translate", so the site builds translated URLs itself. get-translation-settings shows the setting.', 'wpml-translation-check-pro' );
		}

		/*
		 * The writer copies Elementor's data but leaves post_content empty, so
		 * the translation is not read back as builder content until Elementor
		 * saves it once. That save is the documented follow-up step, so it is done
		 * here instead of being left for the caller to remember. A refusal is
		 * reported and never fails the write: the translation itself is saved.
		 */
		if ( 'Elementor' === $editor_type ) {
			// The guard turns a wp_die() or exit inside Elementor into an error, so the saved translation is still reported.
			$rebuilt = Json_Exit_Guard::call(
				static function () use ( $translated_post_id ) {
					return Elementor_Rebuild_Service::rebuild( $translated_post_id );
				},
				'automlp_rebuild_failed',
				__( 'Elementor stopped the request while re-saving this document.', 'wpml-translation-check-pro' )
			);

			if ( is_wp_error( $rebuilt ) ) {
				$result['elementor_rebuild'] = array(
					'done'    => false,
					'message' => $rebuilt->get_error_message(),
				);
				$warnings[]                  = sprintf(
					/* translators: %s: reason the automatic Elementor rebuild did not run. */
					__( 'The automatic Elementor rebuild did not run (%s). Call rebuild-elementor-data for this post.', 'wpml-translation-check-pro' ),
					$rebuilt->get_error_message()
				);
			} else {
				$result['elementor_rebuild'] = array( 'done' => true );
			}
		}

		$status = (string) get_post_status( $translated_post_id );

		if ( array() !== $warnings ) {
			$result['outcome']  = 'needs_attention';
			$result['summary']  = implode( ' ', $warnings );
			$result['warnings'] = $warnings;
		} else {
			$result['outcome'] = 'complete';
			$result['summary'] = __( 'The translation was saved.', 'wpml-translation-check-pro' );
		}

		if ( 'publish' !== $status ) {
			$result['visibility_notice'] = sprintf(
				/* translators: %s: post status. */
				__( 'The translation is "%s", so visitors get a 404 until it is published.', 'wpml-translation-check-pro' ),
				$status
			);
		}

		$result['next_action'] = self::next_action( $result, $status );

		return $result;
	}

	/**
	 * Status WPML gives a new translation, from its "translated document status" setting.
	 *
	 * WPML > Settings > Translated documents options: either always draft, or
	 * the same status as the source.
	 *
	 * @since 1.6.1
	 * @return string "draft" or "same_as_source".
	 */
	public static function wpml_new_post_status() {
		if ( is_callable( array( '\WPML\Setup\Option', 'getReviewMode' ) ) && 'before-publish' === \WPML\Setup\Option::getReviewMode() ) {
			return 'draft';
		}

		global $sitepress;

		if ( ! $sitepress || ! method_exists( $sitepress, 'get_setting' ) ) {
			return 'draft';
		}

		return $sitepress->get_setting( 'translated_document_status' ) ? 'same_as_source' : 'draft';
	}

	/**
	 * Whether translations are saved through WPML's translation jobs.
	 *
	 * True whenever AutoMLP's background flow is on, which is the default.
	 * The bulk screen and the queue save through the same path, so an agent
	 * write and a bulk write are stored identically.
	 *
	 * @since 1.6.1
	 * @return bool True when the WPML job path is available and enabled.
	 */
	public static function uses_wpml_jobs() {
		return class_exists( Background_Flow::class )
			&& class_exists( Job_Sender::class )
			&& class_exists( Job_Listener::class )
			&& class_exists( Wpml_Writer::class )
			&& class_exists( Queue_Table::class )
			&& Background_Flow::enabled();
	}

	/**
	 * Saves a translation through a WPML translation job.
	 *
	 * Same steps as the bulk flow: Job_Sender creates a WPML job assigned to
	 * the AutoMLP translator, Job_Listener turns it into a queue row with the
	 * field to tid map, and the queue Writer saves it with wpml_tm_save_data.
	 * WPML then creates or updates the translated post, links it, applies
	 * page-builder strings and marks the job complete. The row is claimed at
	 * once, so the background dispatcher never translates it a second time.
	 *
	 * @since 1.6.1
	 * @param \WP_Post                              $post            Source post.
	 * @param array<string, array<string, string>> $contents        From normalize_contents().
	 * @param string                                $title           Translated title.
	 * @param string                                $excerpt         Translated excerpt.
	 * @param string                                $source_language Source language code.
	 * @param string                                $target_language Target language code.
	 * @param string                                $post_name       Optional slug.
	 * @param string                                $post_status     Optional status.
	 * @param int                                   $existing        Linked translation ID, or 0.
	 * @param bool                                  $copy_source     Fill fields that were not supplied with the source text.
	 * @return array{translated_post_id:int, strings_written:int}|WP_Error Result, or an error.
	 */
	private static function write_through_wpml_job( $post, array $contents, $title, $excerpt, $source_language, $target_language, $post_name, $post_status, $existing, $copy_source ) {
		$post_id  = (int) $post->ID;
		$supplied = self::supplied_fields( $contents, $title, $excerpt );

		$in_flight = Queue_Table::in_flight_for( array( $post_id ) );

		if ( isset( $in_flight[ $post_id ][ $target_language ] ) ) {
			return new WP_Error(
				'automlp_job_in_progress',
				__( 'AutoMLP\'s translation queue is already working on this post and language. Wait until it finishes (see WPML > AutoMLP AI > Queue), then call again.', 'wpml-translation-check-pro' )
			);
		}

		Job_Listener::reset_created();

		$sent = Job_Sender::send_post( $post_id, $target_language, $source_language, '' );

		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		$row_id = 0;

		foreach ( Job_Listener::created_ids() as $created ) {
			$candidate = Queue_Table::get( $created );

			if ( $candidate && (int) $candidate['source_id'] === $post_id && (string) $candidate['to_lang'] === $target_language ) {
				$row_id = (int) $created;
				break;
			}
		}

		if ( 0 === $row_id ) {
			$skipped = Job_Listener::skipped();

			return new WP_Error(
				'automlp_not_queued',
				! empty( $skipped[0]['reason'] ) ? (string) $skipped[0]['reason'] : __( 'WPML did not create a translation job for this post.', 'wpml-translation-check-pro' )
			);
		}

		if ( ! Queue_Table::claim( $row_id ) || ! Queue_Table::edit_if_state( $row_id, Queue_Table::STATE_CLAIMED, array( 'state' => Queue_Table::STATE_WRITING ) ) ) {
			return new WP_Error(
				'automlp_job_busy',
				__( 'AutoMLP\'s background queue picked up this translation job first. Wait for it to finish, then check the result with get-post-translations.', 'wpml-translation-check-pro' )
			);
		}

		self::track_writing_row( $row_id );

		$row        = Queue_Table::get( $row_id );
		$source_map = Queue_Table::read_source_map( $row );
		$previous   = $existing > 0 ? self::previous_translations( (int) $row['wpml_job_id'] ) : array();
		$translated = array();
		$missing    = array();

		foreach ( $source_map as $name => $entry ) {
			$name = (string) $name;
			$text = isset( $entry['text'] ) ? (string) $entry['text'] : '';

			if ( '' === trim( $text ) || empty( $entry['tid'] ) ) {
				continue;
			}

			if ( isset( $supplied[ $name ] ) ) {
				$value = $supplied[ $name ];
			} elseif ( $copy_source ) {
				$value = $text;
			} elseif ( isset( $previous[ $name ] ) ) {
				// Updating: a field the caller left out keeps the translation WPML already has for it.
				$value = $previous[ $name ];
			} else {
				$missing[ $name ] = $text;
				continue;
			}

			// Same filtering the post editor applies for users without unfiltered_html.
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				$value = wp_kses_post( $value );
			}

			$translated[ $name ] = $value;
		}

		if ( ! empty( $missing ) ) {
			// Nothing was saved yet, so the WPML job is cancelled and leaves no trace.
			self::abandon_job( $row_id, __( 'The translation sent through the AI agent was missing fields.', 'wpml-translation-check-pro' ) );

			return self::missing_fields_error( $post_id, $target_language, $missing );
		}

		/** This filter is documented in includes/queue/class-dispatcher.php */
		$written = apply_filters( 'automlp_write_translation', null, $translated, $row, $source_map );

		if ( null === $written ) {
			$written = new WP_Error( 'automlp_no_writer', __( 'No writer is registered to save this translation.', 'wpml-translation-check-pro' ) );
		}

		if ( is_wp_error( $written ) ) {
			// WPML may already hold part of the save, so the job is left failed (retryable from the Queue screen) rather than cancelled.
			Queue_Table::fail( $row_id, $written->get_error_message(), 0 );

			return $written;
		}

		$translated_post_id = is_array( $written ) && ! empty( $written['result_id'] ) ? (int) $written['result_id'] : 0;

		if ( 0 === $translated_post_id ) {
			Queue_Table::fail( $row_id, __( 'WPML saved the job but no translated post was found.', 'wpml-translation-check-pro' ), 0 );

			return new WP_Error(
				'automlp_write_failed',
				__( 'WPML reported success but returned no translated post ID, so the result could not be confirmed.', 'wpml-translation-check-pro' )
			);
		}

		Queue_Table::finish(
			$row_id,
			array(
				'field_count'       => count( $translated ),
				'fields_translated' => count( $translated ),
				'result_id'         => $translated_post_id,
			)
		);

		/** This action is documented in includes/queue/class-dispatcher.php */
		do_action(
			'automlp_job_completed',
			$row_id,
			$row,
			array(
				'translated' => $translated,
				'source_map' => $source_map,
				'written'    => $written,
				'time_taken' => 0,
				'provider'   => '',
			)
		);

		/*
		 * WPML has already applied its own status rules: a new translation gets
		 * the "translated document status" setting (draft, or the source's
		 * status) and an update keeps its status. Only an explicit post_status
		 * from the caller changes that.
		 */
		$update = array();

		if ( '' !== $post_status && $post_status !== get_post_status( $translated_post_id ) ) {
			$update['post_status'] = $post_status;
		}

		$slug_ignored = false;

		if ( '' !== $post_name ) {
			if ( self::slug_override_allowed() ) {
				$update['post_name'] = sanitize_title( $post_name );
			} else {
				$slug_ignored = true;
			}
		}
		if ( ! empty( $update ) ) {
			$update['ID'] = $translated_post_id;
			wp_update_post( $update );
		}

		if ( class_exists( Bulk_Translation_Queue::class ) ) {
			Bulk_Translation_Queue::persist_source_signature( $post_id, (string) $target_language );
		}

		return array(
			'translated_post_id' => $translated_post_id,
			'strings_written'    => count( $translated ),
			'slug_ignored'       => $slug_ignored,
		);
	}

	/**
	 * Whether a slug supplied by the caller may replace the one WPML chose.
	 *
	 * AutoMLP's own writer honours WPML's "Page URL" setting and takes a supplied
	 * slug only in its "translate" mode. In "copy" and "from title" modes the site
	 * owner has chosen how translated URLs are built, so the supplied slug is left
	 * out and reported. When the setting cannot be read, the slug is applied as before.
	 *
	 * @since 1.6.2
	 * @return bool True when the supplied slug should be applied.
	 */
	private static function slug_override_allowed() {
		$class = '\\AUTOMLP_PRO_WPML\\Includes\\Wpml\\Create_Translated_Post';

		if ( ! class_exists( $class ) || ! is_callable( array( $class, 'get_wpml_translated_document_page_url_mode' ) ) ) {
			return true;
		}

		try {
			return 'translate' === (string) call_user_func( array( $class, 'get_wpml_translated_document_page_url_mode' ) );
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/**
	 * Writes through the direct writer, used only when the background flow is off.
	 *
	 * @since 1.6.1
	 * @param int                                   $post_id         Source post.
	 * @param array<string, array<string, string>> $contents        From normalize_contents().
	 * @param string                                $title           Translated title.
	 * @param string                                $excerpt         Translated excerpt.
	 * @param string                                $source_language Source language code.
	 * @param string                                $target_language Target language code.
	 * @param string                                $editor_type     Editor the source uses.
	 * @param string                                $post_name       Optional slug.
	 * @return int|WP_Error Translated post ID, or an error.
	 */
	private static function write_through_legacy_writer( $post_id, array $contents, $title, $excerpt, $source_language, $target_language, $editor_type, $post_name ) {
		$class = '\AUTOMLP_PRO_WPML\Includes\Wpml\Create_Translated_Post';

		if ( ! class_exists( $class ) ) {
			return new WP_Error(
				'automlp_engine_unavailable',
				__( 'AutoMLP\'s translation writer is not loaded in this request, which normally means WPML or WPML String Translation is inactive. Check with get-site-info before retrying.', 'wpml-translation-check-pro' )
			);
		}

		if ( ! defined( self::CREATE_CONSTANT ) ) {
			define( self::CREATE_CONSTANT, true );
		}

		$translated_post_id = Json_Exit_Guard::call(
			static function () use ( $class, $post_id, $contents, $title, $excerpt, $source_language, $target_language, $editor_type, $post_name ) {
				$writer = new $class(
					$post_id,
					$contents,
					$title,
					$excerpt,
					$source_language,
					$target_language,
					$editor_type,
					$post_name
				);

				return $writer->create_post();
			},
			'automlp_write_failed',
			__( 'WPML could not save the translation and gave no reason. Check the site\'s error log.', 'wpml-translation-check-pro' )
		);

		if ( is_wp_error( $translated_post_id ) ) {
			return $translated_post_id;
		}

		$translated_post_id = absint( $translated_post_id );

		if ( 0 === $translated_post_id ) {
			return new WP_Error(
				'automlp_write_failed',
				__( 'WPML reported success but returned no translated post ID, so the result could not be confirmed.', 'wpml-translation-check-pro' )
			);
		}

		return $translated_post_id;
	}

	/**
	 * Translated values the caller supplied, keyed by WPML job field name.
	 *
	 * The title and excerpt parameters fill the "title" and "excerpt" job
	 * fields when contents does not carry them itself.
	 *
	 * @since 1.6.1
	 * @param array<string, array<string, string>> $contents From normalize_contents().
	 * @param string                                $title    Translated title.
	 * @param string                                $excerpt  Translated excerpt.
	 * @return array<string, string> Field name => translated text.
	 */
	private static function supplied_fields( array $contents, $title, $excerpt ) {
		$fields = array();

		foreach ( $contents as $key => $entry ) {
			$value = is_array( $entry ) && isset( $entry['html'] ) ? (string) $entry['html'] : '';

			if ( '' !== trim( $value ) ) {
				$fields[ (string) $key ] = $value;
			}
		}

		if ( '' !== trim( (string) $title ) && ! isset( $fields['title'] ) ) {
			$fields['title'] = (string) $title;
		}

		if ( '' !== trim( (string) $excerpt ) && ! isset( $fields['excerpt'] ) ) {
			$fields['excerpt'] = (string) $excerpt;
		}

		return $fields;
	}

	/**
	 * Translations WPML already holds for the fields of a job.
	 *
	 * WPML fills a new job for an existing translation with the previous
	 * translated values, so an update only has to carry what changed.
	 *
	 * @since 1.6.1
	 * @param int $wpml_job_id WPML job ID.
	 * @return array<string, string> Field name => translated text.
	 */
	private static function previous_translations( $wpml_job_id ) {
		if ( $wpml_job_id < 1 || ! function_exists( 'wpml_tm_load_job_factory' ) ) {
			return array();
		}

		$job = wpml_tm_load_job_factory()->get_translation_job( $wpml_job_id, false, 0, false );

		if ( ! $job || ! is_object( $job ) || empty( $job->elements ) ) {
			return array();
		}

		$values = array();

		foreach ( (array) $job->elements as $element ) {
			if ( empty( $element->field_type ) || empty( $element->field_data_translated ) ) {
				continue;
			}

			$data = (string) $element->field_data_translated;

			if ( isset( $element->field_format ) && 'base64' === $element->field_format ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				$decoded = base64_decode( $data, true );
				$data    = false === $decoded ? '' : $decoded;
			}

			if ( '' !== trim( $data ) ) {
				$values[ (string) $element->field_type ] = $data;
			}
		}

		return $values;
	}

	/**
	 * Closes a job that will not be written: the queue row stops and the WPML job is cancelled.
	 *
	 * @since 1.6.1
	 * @param int    $row_id  Queue row ID.
	 * @param string $message Reason stored on the row.
	 * @return void
	 */
	private static function abandon_job( $row_id, $message ) {
		Queue_Table::fail( $row_id, $message, 0 );
		Job_Listener::cancel_queue_job( $row_id );
	}

	/**
	 * The error returned when the call did not carry every field WPML needs.
	 *
	 * @since 1.6.1
	 * @param int                   $post_id         Source post.
	 * @param string                $target_language Target language code.
	 * @param array<string, string> $missing         Field name => source text.
	 * @return WP_Error The error, with the missing fields and their source text in its data.
	 */
	private static function missing_fields_error( $post_id, $target_language, array $missing ) {
		return new WP_Error(
			'automlp_missing_fields',
			sprintf(
				/* translators: 1: comma-separated field keys, 2: target language code. */
				__( 'Nothing was written: these keys from get-content have no translation in this call: %1$s. Translate each one into "%2$s" (the source text is in this error\'s data under "fields") and call translate-post again with all of them in "contents".', 'wpml-translation-check-pro' ),
				implode( ', ', array_keys( $missing ) ),
				$target_language
			),
			array(
				'source_post_id'  => $post_id,
				'target_language' => $target_language,
				'fields'          => $missing,
			)
		);
	}
	/**
	 * Changes only the status of an existing translation.
	 *
	 * @since 1.6.0
	 * @param int    $source_id       Source post ID.
	 * @param int    $translated_id   Existing translation ID.
	 * @param string $source_language Source language code.
	 * @param string $target_language Target language code.
	 * @param string $editor_type     Editor the source uses.
	 * @param string $post_status     New status.
	 * @return array<string, mixed>|WP_Error Result in the same shape as a full write, or an error.
	 */
	private static function set_status_only( $source_id, $translated_id, $source_language, $target_language, $editor_type, $post_status ) {
		$translation = get_post( $translated_id );
		$type_object = $translation ? get_post_type_object( $translation->post_type ) : null;

		if ( ! current_user_can( 'edit_post', $translated_id ) ) {
			return new WP_Error(
				'automlp_forbidden',
				__( 'The current user cannot edit that translation, so its status cannot be changed.', 'wpml-translation-check-pro' )
			);
		}

		if ( in_array( $post_status, array( 'publish', 'private' ), true ) && $type_object && ! current_user_can( $type_object->cap->publish_posts ) ) {
			return new WP_Error(
				'automlp_cannot_publish',
				__( 'The current user is not allowed to publish this post type, so the translation stays as it is.', 'wpml-translation-check-pro' )
			);
		}

		if ( $post_status !== get_post_status( $translated_id ) ) {
			$updated = wp_update_post(
				array(
					'ID'          => $translated_id,
					'post_status' => $post_status,
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		$result = array(
			'source_post_id'     => $source_id,
			'translated_post_id' => $translated_id,
			'created'            => false,
			'updated'            => true,
			'source_language'    => $source_language,
			'target_language'    => $target_language,
			'editor_type'        => $editor_type,
			'title'              => (string) get_the_title( $translated_id ),
			'post_status'        => (string) get_post_status( $translated_id ),
			'strings_written'    => 0,
			'permalink'          => html_entity_decode( (string) get_permalink( $translated_id ) ),
			'edit_link'          => html_entity_decode( (string) get_edit_post_link( $translated_id, 'raw' ) ),
			'outcome'            => 'complete',
			'summary'            => __( 'Only the status was changed; no text was written.', 'wpml-translation-check-pro' ),
		);

		$result['next_action'] = self::next_action( $result, $result['post_status'] );

		return $result;
	}

	/**
	 * Says what the caller should do after this write.
	 *
	 * A model that has to remember the workflow forgets it; a result that states
	 * the next step does not depend on that.
	 *
	 * @since 1.6.0
	 * @param array<string, mixed> $result Result built by write().
	 * @param string               $status Status the translation has now.
	 * @return string One plain instruction.
	 */
	private static function next_action( array $result, $status ) {
		if ( 'complete' !== $result['outcome'] ) {
			return __( 'Not finished. Read summary, repair what it names, and call translate-post again for this post. Do not mark this item done.', 'wpml-translation-check-pro' );
		}

		if ( 'publish' !== $status ) {
			return sprintf(
				/* translators: %s: post status. */
				__( 'Saved as "%s", so visitors get a 404. If the user wants it live, call translate-post again with the same post_id and target_language and post_status "publish" (nothing else is needed), then continue with the next item from get-translation-plan.', 'wpml-translation-check-pro' ),
				$status
			);
		}

		return __( 'This item is saved and published. Mark it done in your checklist and continue with the next item from get-translation-plan. When all items are done, call verify-translations.', 'wpml-translation-check-pro' );
	}

	/**
	 * Restores an Elementor layout the writer left unreadable.
	 *
	 * The writer copies the source's _elementor_data without wp_slash(), so
	 * update_post_meta() strips every backslash and the stored JSON is no longer
	 * valid. Elementor then reads it as an empty page, and rebuild-elementor-data
	 * has nothing to rebuild. When the translation holds layout data that does not
	 * decode, it is replaced with a correctly slashed copy of the source's layout.
	 * A valid layout, a missing one, or a source that is itself unreadable is left
	 * exactly as it is.
	 *
	 * @since 1.5.0
	 * @param int    $source_id     Source post ID.
	 * @param int    $translated_id Translation post ID.
	 * @param string $editor_type   Editor the write used.
	 * @return bool True when the layout was replaced.
	 */
	private static function repair_broken_elementor_layout( $source_id, $translated_id, $editor_type ) {
		if ( 'Elementor' !== $editor_type ) {
			return false;
		}

		$translated = get_post_meta( $translated_id, '_elementor_data', true );

		if ( ! is_string( $translated ) || '' === $translated || self::is_readable_layout( $translated ) ) {
			return false;
		}

		$source = get_post_meta( $source_id, '_elementor_data', true );

		if ( ! self::is_readable_layout( $source ) ) {
			return false;
		}

		update_post_meta( $translated_id, '_elementor_data', wp_slash( $source ) );

		return true;
	}

	/**
	 * Whether a stored _elementor_data value decodes to a non-empty element list.
	 *
	 * @since 1.5.0
	 * @param mixed $raw Stored meta value.
	 * @return bool True when it is usable.
	 */
	private static function is_readable_layout( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return false;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) && ! empty( $decoded );
	}

	/**
	 * Puts translated strings in the shape Create_Translated_Post reads.
	 *
	 * The writer's filter_translate_strings() reads $contents[ $key ]['html'],
	 * the shape the bulk screen's JavaScript sends. Callers of translate-post
	 * pass a plain string per key, as its description asks. Passed through
	 * unchanged, isset() on a string's 'html' offset is false, every value
	 * becomes empty and is silently dropped: the title is written, the body is
	 * not. Found on a live run; do not remove.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $contents Translated strings keyed as get-content returned them.
	 * @return array<string, array<string, string>> Entries of array( 'html' => value ).
	 */
	public static function normalize_contents( array $contents ) {
		$normalized = array();

		foreach ( $contents as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( isset( $value['html'] ) && is_scalar( $value['html'] ) ) {
					$normalized[ (string) $key ] = array( 'html' => (string) $value['html'] );
				} elseif ( isset( $value['text'] ) && is_scalar( $value['text'] ) ) {
					$normalized[ (string) $key ] = array( 'html' => (string) $value['text'] );
				}

				continue;
			}

			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$normalized[ (string) $key ] = array( 'html' => (string) $value );
			}
		}

		return $normalized;
	}

	/**
	 * Posts that look like an unlinked version of the source in the target language.
	 *
	 * Matches on exact title, and on the source slug plus the "-2" and
	 * "-{language}" suffixes WordPress and WPML add to copies. Only posts in the
	 * target language, or with no language at all, count; a post in another
	 * language is a different page that happens to share a name.
	 *
	 * @since 1.5.0
	 * @param \WP_Post $post            Source post.
	 * @param string   $target_language Target language code.
	 * @return array<int, array<string, mixed>> Candidates, strongest first.
	 */
	public static function find_duplicate_candidates( $post, $target_language ) {
		$post_id   = (int) $post->ID;
		$post_type = (string) $post->post_type;
		$title     = trim( (string) $post->post_title );
		$slug      = (string) $post->post_name;
		$base      = array(
			'post_type'        => $post_type,
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
			'post__not_in'     => array( $post_id ),
			'posts_per_page'   => 10,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			// WPML would otherwise limit the query to the current admin language.
			'suppress_filters' => true,
		);

		$reasons = array();

		if ( '' !== $slug ) {
			$slugs = array( $slug, $slug . '-2', $slug . '-' . $target_language );

			foreach ( get_posts( $base + array( 'post_name__in' => $slugs ) ) as $id ) {
				$reasons[ (int) $id ][] = 'slug_match';
			}
		}

		if ( '' !== $title ) {
			foreach ( get_posts( $base + array( 'title' => $title ) ) as $id ) {
				$reasons[ (int) $id ][] = 'title_match';
			}
		}

		$candidates = array();

		foreach ( $reasons as $id => $why ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}

			$details  = apply_filters( 'wpml_element_language_details', null, array( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				'element_id'   => $id,
				'element_type' => 'post_' . $post_type,
			) );
			$language = is_object( $details ) && ! empty( $details->language_code ) ? (string) $details->language_code : '';

			if ( '' !== $language && $language !== $target_language ) {
				continue;
			}

			$status       = (string) get_post_status( $id );
			$candidates[] = array(
				'post_id'     => $id,
				'title'       => (string) get_the_title( $id ),
				'post_status' => $status,
				'language'    => '' === $language ? null : $language,
				'reasons'     => array_values( array_unique( $why ) ),
				'edit_link'   => html_entity_decode( (string) get_edit_post_link( $id, 'raw' ) ),
			);
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				return count( $b['reasons'] ) <=> count( $a['reasons'] );
			}
		);

		return $candidates;
	}

	/**
	 * The error returned when a write would duplicate an unlinked version.
	 *
	 * @since 1.5.0
	 * @param int                              $post_id         Source post.
	 * @param string                           $target_language Target language code.
	 * @param array<int, array<string, mixed>> $candidates      From find_duplicate_candidates().
	 * @return WP_Error The error, with the candidates in its data.
	 */
	private static function duplicate_error( $post_id, $target_language, array $candidates ) {
		$lines = array();

		foreach ( $candidates as $candidate ) {
			$lines[] = sprintf(
				'#%1$d "%2$s" (%3$s, %4$s, %5$s)',
				$candidate['post_id'],
				$candidate['title'],
				$candidate['post_status'],
				null === $candidate['language'] ? __( 'no language', 'wpml-translation-check-pro' ) : $candidate['language'],
				implode( ' + ', $candidate['reasons'] )
			);
		}

		return new WP_Error(
			'automlp_possible_duplicate',
			sprintf(
				/* translators: 1: source post ID, 2: target language code, 3: list of candidate posts. */
				__( 'Post %1$d has no linked "%2$s" translation, but these posts look like one that was never linked: %3$s. Nothing was written. Show them to the user and ask which applies. To reuse one, link it with manage-translation-links (action "link", post_id the candidate, link_to_post_id the source; for a candidate with no language, run "set_language" first; restore a trashed one first), then call this again and it will update that post. If none of them is a version of this post, call again with skip_duplicate_check true to create a new one.', 'wpml-translation-check-pro' ),
				$post_id,
				$target_language,
				implode( '; ', $lines )
			),
			array(
				'source_post_id'  => $post_id,
				'target_language' => $target_language,
				'candidates'      => $candidates,
			)
		);
	}

	/**
	 * The language a post is authored in.
	 *
	 * @since 1.5.0
	 * @param int    $post_id   Post to inspect.
	 * @param string $post_type Its post type.
	 * @return string Language code, or an empty string.
	 */
	public static function resolve_source_language( $post_id, $post_type ) {
		$details = Translation_Service::get_element_language_details( $post_id );

		/*
		 * When WPML can answer at all, trust it — including when the answer is
		 * "no language". WPML_AT_PRO_Helper::get_post_source_language() falls
		 * back to the site default for an element with no language row, which
		 * is right for the bulk screen (where the post came from a language
		 * filtered list) but wrong here: it would silently treat unassigned
		 * content as if it were the default language and write a translation
		 * linked to nothing. The caller has to assign a language first.
		 */
		if ( null !== $details ) {
			return $details['language_code'];
		}

		if ( class_exists( 'WPML_AT_PRO_Helper' ) ) {
			return (string) WPML_AT_PRO_Helper::get_post_source_language( $post_id, $post_type );
		}

		return '';
	}

	/**
	 * The editor AutoMLP should use for a post.
	 *
	 * Detected from post meta, exactly as the bulk-translate route does.
	 *
	 * Do not switch this to Wpml_Context::get_package(). That defines
	 * DOING_AUTOMLP_PRO_WPML_BULK_POST_TRANSLATION, a constant cannot be
	 * undefined, and Create_Translated_Post later re-reads the package in the
	 * same request: with the bulk constant set, Get_Package_Content returns
	 * entries without their "translate" flag, the writer's filter drops every
	 * one, and the translation is saved with its title translated and its body
	 * still in the source language. Found on a live run.
	 *
	 * @since 1.5.0
	 * @param int      $post_id Post to inspect.
	 * @param \WP_Post $post    The post object.
	 * @return string Editor name, or an empty string.
	 */
	public static function resolve_editor_type( $post_id, $post ) {
		if ( ! class_exists( 'WPML_AT_PRO_Helper' ) ) {
			return '';
		}

		return WPML_AT_PRO_Helper::detect_editor_type(
			$post_id,
			has_blocks( $post->post_content ) ? 'Gutenberg' : 'Classic'
		);
	}
}
