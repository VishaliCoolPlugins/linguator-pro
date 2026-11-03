<?php
/**
 * Ignore Issues ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Inspector\Abilities\Issues;

use CoolPlugins\AiFramework\Packs\Inspector\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Inspector_Guard;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Issue_Service;
use CoolPlugins\AiFramework\Packs\Inspector\Services\Report_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ignores issues the user does not want to fix, or restores ignored ones.
 *
 * Changes only the dupcap_ignored_issues list; site content is untouched.
 *
 * @since 1.1.0
 */
final class Ignore_Issues extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'ignore-issues';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Ignore Issues', 'duplicate-content-addon-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'Translation Inspector: ignore issues the user does not want to fix (for example a page that is meant to stay in one language), or restore ignored ones, by ignore_key from get-issues. Ignored issues disappear from get-issues and stop lowering the health score; nothing on the site changes. Only ignore an issue when the user asks to. To see what is ignored, call get-issues with include_ignored.', 'duplicate-content-addon-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
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
					'description' => __( 'Whether to ignore the keys or restore them.', 'duplicate-content-addon-for-polylang' ),
				),
				'keys' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'minItems'    => 1,
					'maxItems'    => 100,
					'description' => __( 'ignore_key values from get-issues.', 'duplicate-content-addon-for-polylang' ),
				),
				'reason' => array(
					'type'        => 'string',
					'default'     => 'user',
					'description' => __( 'Why it is ignored, stored with the key.', 'duplicate-content-addon-for-polylang' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return $this->write_meta();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Ignoring only accepts keys present in the current report, so an assistant
	 * cannot store a key it made up. Restoring accepts any stored key.
	 *
	 * @since 1.1.0
	 * @param mixed $input Validated input, or null.
	 * @return array<string, mixed>|\WP_Error Result per key.
	 */
	public static function execute( $input = array() ) {
		$input = self::input_array( $input );

		if ( ! Inspector_Guard::engine_ready() ) {
			return Inspector_Guard::engine_error();
		}

		$action = isset( $input['action'] ) ? (string) $input['action'] : '';
		$reason = isset( $input['reason'] ) ? sanitize_key( (string) $input['reason'] ) : 'user';
		$reason = '' !== $reason ? $reason : 'user';
		$keys   = array_values( array_unique( array_filter( array_map( array( '\DUPCAP_Ignored_Issues', 'sanitize_key' ), array_map( 'strval', self::normalize_list( $input['keys'] ?? array() ) ) ) ) ) );

		if ( empty( $keys ) ) {
			return new \WP_Error(
				'dupcap_no_keys',
				__( 'No valid ignore_key was given. Take them from translation-inspector/get-issues (each row has an ignore_key).', 'duplicate-content-addon-for-polylang' ),
				array( 'status' => 400 )
			);
		}

		$known   = 'ignore' === $action ? Issue_Service::known_keys() : array();
		$stored  = \DUPCAP_Ignored_Issues::get_all();
		$results = array();
		$changed = 0;

		foreach ( $keys as $key ) {
			if ( 'ignore' === $action ) {
				if ( isset( $stored[ $key ] ) ) {
					$status = 'already_ignored';
				} elseif ( ! isset( $known[ $key ] ) ) {
					$status = 'not_found';
				} else {
					$status = \DUPCAP_Ignored_Issues::ignore( $key, $reason ) ? 'ignored' : 'failed';
				}
			} elseif ( ! isset( $stored[ $key ] ) ) {
				$status = 'not_ignored';
			} else {
				$status = \DUPCAP_Ignored_Issues::unignore( $key ) ? 'restored' : 'failed';
			}

			if ( in_array( $status, array( 'ignored', 'restored' ), true ) ) {
				++$changed;
			}

			$results[] = array(
				'ignore_key' => $key,
				'status'     => $status,
			);
		}

		Report_Service::forget_context();

		return array(
			'action'    => $action,
			'changed'   => $changed,
			'results'   => $results,
			'next_step' => 'ignore' === $action
				? __( 'Ignored rows no longer appear in get-issues. not_found means the key is not in the current report: check it with get-issues.', 'duplicate-content-addon-for-polylang' )
				: __( 'Restored rows appear in get-issues again at once. The scan does not need to run again.', 'duplicate-content-addon-for-polylang' ),
		);
	}
}
