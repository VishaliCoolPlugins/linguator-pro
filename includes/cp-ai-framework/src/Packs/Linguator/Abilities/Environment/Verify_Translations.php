<?php
/**
 * Translation verification ability.
 *
 * @package CoolPluginsAiFramework
 * @since 1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Linguator\Abilities\Environment;

use CoolPlugins\AiFramework\Packs\Linguator\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Linguator\Services\Scope_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks that finished translations exist, are published and load without errors.
 *
 * @since 1.2.0
 */
final class Verify_Translations extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'verify-translations';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Verify Translations', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator: the final check of a translation job. Call it after the writes, with the source post IDs from get-translation-plan (its verify_batches). For each source post it confirms that a translation exists, is assigned to the target language, is published, and that its public page answers without an error (a 404 is reported) and declares the target language; for a header or footer template it checks the target language home page where it appears and whether Linguator\'s Elementor integration is loaded. It also checks the static front page mapping, and, with menu_id, that the translated menu exists and every item points to a published page in the target language. Read only; it can be repeated safely. Up to 5 posts per call because each page is requested over HTTP: split longer lists. Do not tell the user the job is complete unless "complete" is true. Each problem has a code and a message, and next_action says how to fix the first one. A page the server cannot request is reported as a problem (http_not_verified), not as success: ask the user to check it in a browser. This confirms status, language and links, not the quality of the wording.', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'target_language' => array(
					'type'        => 'string',
					'description' => __( 'Language slug the content was translated into, for example "fr".', 'linguator-pro' ),
				),
				'source_post_ids' => array(
					'type'        => array( 'string', 'array' ),
					'description' => __( 'IDs of the SOURCE posts (default language), not of the translations. Up to 5. Accepts an array of integers or a comma-separated string.', 'linguator-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'menu_id'         => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Optional. ID of the source menu that was synced, to check the translated menu too.', 'linguator-pro' ),
				),
				'check_http'      => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'When true, the default, each published page is requested to catch 404s and a wrong page language. Set false only to check the database state quickly.', 'linguator-pro' ),
				),
			),
			'required'   => array( 'target_language', 'source_post_ids' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'complete'    => array( 'type' => 'boolean' ),
				'outcome'     => array( 'type' => 'string' ),
				'summary'     => array( 'type' => 'string' ),
				'next_action' => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Report, or an error.
	 */
	public static function execute( array $input = array() ) {
		return Scope_Service::verify(
			isset( $input['target_language'] ) ? (string) $input['target_language'] : '',
			self::normalize_list( isset( $input['source_post_ids'] ) ? $input['source_post_ids'] : array() ),
			! isset( $input['check_http'] ) || rest_sanitize_boolean( $input['check_http'] ),
			isset( $input['menu_id'] ) ? absint( $input['menu_id'] ) : 0
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Administrator-only: it requests pages from the server and reports configuration.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
