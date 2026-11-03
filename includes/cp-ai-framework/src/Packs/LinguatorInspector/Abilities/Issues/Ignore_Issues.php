<?php
/**
 * Ignore Issues ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Issues;

use CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Inspector_Guard;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Issue_Service;
use CoolPlugins\AiFramework\Packs\LinguatorInspector\Services\Report_Service;
use LinguatorPro\Modules\Scan\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ignore Issues.
 *
 * @since 1.2.0
 */
final class Ignore_Issues extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'ignore-issues';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Ignore Issues', 'linguator-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Linguator Site Scan: ignore issues the user does not want to fix (for example a page that is meant to stay in one language), or restore ignored ones, by ignore_key from get-issues (a row key, or an issue_key to dismiss the whole issue). Ignored issues disappear from get-issues and stop lowering the health score; nothing on the site changes. Only ignore an issue when the user asks to. To see what is ignored, call get-issues with include_ignored. Linguator does not store a reason, so reason is accepted for compatibility only.', 'linguator-pro' );
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
			'required'   => array( 'action', 'keys' ),
			'properties' => array(
				'action' => array(
					'type'        => 'string',
					'enum'        => array( 'ignore', 'restore' ),
					'description' => __( 'Whether to ignore the keys or restore them.', 'linguator-pro' ),
				),
				'keys'   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'minItems'    => 1,
					'maxItems'    => 100,
					'description' => __( 'ignore_key values from get-issues.', 'linguator-pro' ),
				),
				'reason' => array(
					'type'        => 'string',
					'default'     => 'user',
					'description' => __( 'Why it is ignored. Not stored by Linguator.', 'linguator-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return $this->write_meta();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 * @param mixed $input Validated input, or null.
	 * @return array<string, mixed>|\WP_Error Result payload, or an error.
	 */
	public static function execute( $input = array() ) {
		$input = self::input_array( $input );

		if ( ! Inspector_Guard::engine_ready() ) {
			return Inspector_Guard::engine_error();
		}

		$action = isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : '';

		if ( ! in_array( $action, array( 'ignore', 'restore' ), true ) ) {
			return new \WP_Error(
				'lmat_invalid_action',
				__( 'action must be "ignore" or "restore".', 'linguator-pro' ),
				array( 'status' => 400 )
			);
		}

		$keys = array();
		foreach ( self::normalize_list( $input['keys'] ?? array() ) as $key ) {
			$key = sanitize_text_field( (string) $key );
			if ( '' !== $key && strlen( $key ) <= 200 && Scanner::RESET_KEY !== $key ) {
				$keys[] = $key;
			}
		}
		$keys = array_slice( array_values( array_unique( $keys ) ), 0, 100 );

		if ( empty( $keys ) ) {
			return new \WP_Error(
				'lmat_no_keys',
				__( 'No valid ignore_key was given. Take them from linguator-inspector/get-issues (each row has an ignore_key).', 'linguator-pro' ),
				array( 'status' => 400 )
			);
		}

		$known   = 'ignore' === $action ? Issue_Service::known_keys() : array();
		$stored  = array_flip( Scanner::get_ignored() );
		$results = array();
		$apply   = array();

		foreach ( $keys as $key ) {
			if ( 'ignore' === $action ) {
				if ( isset( $stored[ $key ] ) ) {
					$status = 'already_ignored';
				} elseif ( ! isset( $known[ $key ] ) ) {
					$status = 'not_found';
				} else {
					$status  = 'ignored';
					$apply[] = $key;
				}
			} elseif ( ! isset( $stored[ $key ] ) ) {
				$status = 'not_ignored';
			} else {
				$status  = 'restored';
				$apply[] = $key;
			}

			$results[] = array(
				'ignore_key' => $key,
				'status'     => $status,
			);
		}

		if ( ! empty( $apply ) ) {
			Scanner::set_ignored_many( $apply, 'ignore' === $action );
		}

		Report_Service::forget_context();

		return array(
			'action'    => $action,
			'changed'   => count( $apply ),
			'results'   => $results,
			'next_step' => 'ignore' === $action
				? __( 'Ignored rows no longer appear in get-issues. not_found means the key is not in the current report: check it with get-issues.', 'linguator-pro' )
				: __( 'Restored rows appear in get-issues again at once. The scan does not need to run again.', 'linguator-pro' ),
		);
	}
}
