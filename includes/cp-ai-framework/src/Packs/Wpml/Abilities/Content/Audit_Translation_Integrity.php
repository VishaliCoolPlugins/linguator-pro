<?php
/**
 * Translation integrity audit ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Content;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Integrity_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks a translated post for problems WPML's own "complete" status does not
 * catch: content that is marked translated but is textually identical to the
 * source, internal links still pointing at the source-language original, and
 * Elementor templates or global widgets still wired to a source-language
 * asset.
 *
 * Read-only: nothing here is fixed automatically. Findings are meant to be
 * acted on by hand or by another ability such as ensure-translation or
 * manage-translation-links.
 *
 * @since 1.5.0
 */
final class Audit_Translation_Integrity extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'audit-translation-integrity';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Audit Translation Integrity', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: audit one translated post for problems WPML\'s own "complete" status does not catch. "content" flags fields that are identical to the source text, which usually means they were never actually translated even though the post is marked done. "links" flags internal links that still point at the source-language original of a page instead of its translation. "assets" flags Elementor templates or global widgets still wired to a source-language template instead of the target-language one. This is read-only: it reports problems, it does not fix them. Pass the translated post\'s own ID, not the source post\'s ID.', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the translated post to audit. This must be the translation, not the source-language original.', 'wpml-translation-check-pro' ),
				),
				'checks'  => array(
					'type'        => 'array',
					'description' => __( 'Which checks to run: "content", "links", "assets". Omit to run all three.', 'wpml-translation-check-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => Integrity_Service::ALL_CHECKS,
					),
				),
			),
			'required'   => array( 'post_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error Audit report, or an error.
	 */
	public static function execute( array $input = array() ) {
		$blocker = Wpml_Guard::unavailable_error();

		if ( null !== $blocker ) {
			return $blocker;
		}

		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( 0 === $post_id || ! get_post( $post_id ) ) {
			return new WP_Error(
				'automlp_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d was not found.', 'wpml-translation-check-pro' ),
					$post_id
				)
			);
		}

		$checks = array();

		if ( isset( $input['checks'] ) && is_array( $input['checks'] ) ) {
			foreach ( $input['checks'] as $check ) {
				$checks[] = sanitize_key( (string) $check );
			}
		}

		if ( empty( $checks ) ) {
			$checks = Integrity_Service::ALL_CHECKS;
		}

		$report = Integrity_Service::audit( $post_id, $checks );

		if ( null === $report ) {
			return new WP_Error(
				'automlp_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d was not found.', 'wpml-translation-check-pro' ),
					$post_id
				)
			);
		}

		if ( '' === $report['target_language'] ) {
			$report['notice'] = __( 'This post has no WPML language details, so it may not be a translation at all. Call get-element-language-details first to confirm it is linked into a translation group.', 'wpml-translation-check-pro' );
		} elseif ( 0 === $report['source_id'] ) {
			$report['notice'] = __( 'The source-language original for this post could not be resolved, so the "content" check was skipped. "links" and "assets" checks, if requested, still ran.', 'wpml-translation-check-pro' );
		}

		return $report;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( 0 === $post_id ) {
			return self::user_can( 'edit_posts', $input );
		}

		return self::user_can( 'edit_post', $input, $post_id );
	}
}
