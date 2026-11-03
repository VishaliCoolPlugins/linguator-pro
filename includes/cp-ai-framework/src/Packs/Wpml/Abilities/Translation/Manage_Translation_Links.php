<?php
/**
 * Translation link management ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Translation;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Link_Service;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assigns languages and rewires WPML translation groups.
 *
 * One ability with an action argument rather than three, so a caller cannot
 * reach for "unlink" while looking for "set_language". Dry run by default.
 *
 * @since 1.5.0
 */
final class Manage_Translation_Links extends Abstract_Ability {

	/**
	 * Supported actions.
	 *
	 * @since 1.5.0
	 * @var string[]
	 */
	const ACTIONS = array( 'set_language', 'link', 'unlink' );

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'manage-translation-links';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Manage Translation Links', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: change how WPML links content across languages. "set_language" gives a language to a post that has none, which is what content needs when its post type was switched on for translation after the content already existed. "link" puts a post into another post\'s translation group, so two existing pages become versions of each other. "unlink" detaches a post from its group; it keeps its language and content and nothing is deleted. Every call is a dry run unless you pass dry_run false: the response describes exactly what would change, so show that to the user and get agreement before applying it. Linking is refused when it would displace an existing version in the same language, when the two posts are the same language or a different post type, or when either has no language yet.', 'wpml-translation-check-pro' );
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
				'action'          => array(
					'type'        => 'string',
					'enum'        => self::ACTIONS,
					'description' => __( '"set_language" to give a language to a post that has none, "link" to join another post\'s translation group, "unlink" to detach from the current group.', 'wpml-translation-check-pro' ),
				),
				'post_id'         => array(
					'type'        => 'integer',
					'description' => __( 'The post being changed.', 'wpml-translation-check-pro' ),
				),
				'language'        => array(
					'type'        => 'string',
					'description' => __( 'Language code to assign. Required for "set_language", ignored otherwise.', 'wpml-translation-check-pro' ),
				),
				'link_to_post_id' => array(
					'type'        => 'integer',
					'description' => __( 'The post whose translation group to join. Required for "link", ignored otherwise.', 'wpml-translation-check-pro' ),
				),
				'dry_run'         => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'True, the default, reports what would change without changing it. Pass false only after the user has agreed to the plan.', 'wpml-translation-check-pro' ),
				),
			),
			'required'   => array( 'action', 'post_id' ),
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
	 * Not destructive: unlink detaches without deleting, and every action is
	 * reversible by calling the opposite one. Not idempotent, because "link"
	 * and "unlink" move a post between groups.
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error Result payload, or an error.
	 */
	public static function execute( array $input = array() ) {
		// Core validates the schema but does not coerce, so cast defensively.
		$action  = isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : '';
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		// Default true, so an omitted flag never applies a change.
		$dry_run = ! array_key_exists( 'dry_run', $input ) || (bool) $input['dry_run'];

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new WP_Error(
				'automlp_invalid_action',
				sprintf(
					/* translators: %s: comma-separated list of valid actions. */
					__( 'Unknown action. Pass one of: %s.', 'wpml-translation-check-pro' ),
					implode( ', ', self::ACTIONS )
				)
			);
		}

		if ( 'set_language' === $action ) {
			$language = isset( $input['language'] ) ? sanitize_text_field( (string) $input['language'] ) : '';

			if ( '' === $language ) {
				return new WP_Error(
					'automlp_missing_language',
					__( 'The "set_language" action needs a language. Call list-languages and pass one of its codes.', 'wpml-translation-check-pro' )
				);
			}

			return Link_Service::set_language( $post_id, $language, $dry_run );
		}

		if ( 'link' === $action ) {
			$link_to = isset( $input['link_to_post_id'] ) ? absint( $input['link_to_post_id'] ) : 0;

			if ( 0 === $link_to ) {
				return new WP_Error(
					'automlp_missing_link_target',
					__( 'The "link" action needs link_to_post_id: the post in the other language whose translation group this post should join.', 'wpml-translation-check-pro' )
				);
			}

			return Link_Service::link( $post_id, $link_to, $dry_run );
		}

		return Link_Service::unlink( $post_id, $dry_run );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Both posts are checked for a link; Link_Service re-checks the second one
	 * because only it knows which action is running.
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

		if ( ! self::user_can( 'edit_post', $input, $post_id ) ) {
			return false;
		}

		$link_to = isset( $input['link_to_post_id'] ) ? absint( $input['link_to_post_id'] ) : 0;

		if ( $link_to > 0 ) {
			return self::user_can( 'edit_post', $input, $link_to );
		}

		return true;
	}
}
