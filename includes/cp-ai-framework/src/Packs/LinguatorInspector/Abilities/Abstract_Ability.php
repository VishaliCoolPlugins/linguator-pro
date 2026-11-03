<?php
/**
 * Base class for every Linguator Site Scan (Inspector) ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\LinguatorInspector\Abilities;

use CoolPlugins\AiFramework\Core\Abstract_Ability as Core_Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets the identity every Linguator Inspector ability shares and adds the helpers
 * the abilities use (tolerant input handling, write annotations, pagination).
 *
 * @since 1.2.0
 */
abstract class Abstract_Ability extends Core_Ability {

	/**
	 * Ability name prefix.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const ABILITY_NAMESPACE = 'linguator-inspector';

	/**
	 * Ability category slug.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const ABILITY_CATEGORY = 'linguator-inspector';

	/**
	 * Capability filter name.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const CAPABILITY_FILTER = 'lmat_inspector_ability_required_capability';

	/**
	 * Section keys the Site Scan groups issues into.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const SECTIONS = array( 'content', 'elementor', 'urls', 'site' );

	/**
	 * Severity levels a stored result can carry, most urgent first.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const SEVERITIES = array( 'critical', 'warning', 'info', 'pass' );

	/**
	 * JSON Schema describing the returned output.
	 *
	 * Kept loose: WP_Ability::validate_output() hard-fails any response that
	 * drifts from a declared schema.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * Runs the ability.
	 *
	 * Callbacks receive an array from REST, or null from an MCP call with no
	 * arguments, so subclasses start with `$input = self::input_array( $input );`.
	 *
	 * @since 1.2.0
	 * @param mixed $input Validated input, or null.
	 * @return mixed Result payload, or WP_Error on failure.
	 */
	abstract public static function execute( $input = array() );

	/**
	 * Decides whether the current user may run this ability.
	 *
	 * Defaults to manage_options, the capability the Site Scan screen and its
	 * REST routes require.
	 *
	 * @since 1.2.0
	 * @param mixed $input Validated input, or null.
	 * @return bool True when permitted.
	 */
	public static function check_permission( $input = array() ) {
		return static::user_can( 'manage_options', self::input_array( $input ) );
	}

	/**
	 * Builds the argument array consumed by wp_register_ability().
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Registration arguments.
	 */
	public function get_args() {
		$input_schema = $this->get_input_schema();

		// A REST GET with no input passes null, which fails "type: object".
		// WP_Ability::normalize_input() swaps in the schema default, so every
		// tool can be called with no arguments.
		if ( isset( $input_schema['type'] ) && 'object' === $input_schema['type'] && ! array_key_exists( 'default', $input_schema ) ) {
			$input_schema['default'] = array();
		}

		return array(
			'label'               => $this->get_label(),
			'description'         => $this->get_description(),
			'category'            => static::ABILITY_CATEGORY,
			'input_schema'        => $input_schema,
			'output_schema'       => $this->get_output_schema(),
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => array( static::class, 'check_permission' ),
			'meta'                => $this->get_meta(),
		);
	}

	/**
	 * The input as an array, whatever form it arrived in.
	 *
	 * @since 1.2.0
	 * @param mixed $input Raw input: array, object or null.
	 * @return array<string, mixed>
	 */
	protected static function input_array( $input ) {
		if ( is_array( $input ) ) {
			return $input;
		}

		return is_object( $input ) ? (array) $input : array();
	}

	/**
	 * Meta for an ability that writes but never destroys anything.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Meta array.
	 */
	protected function write_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * Schema fragment for the page / per_page pair used by list abilities.
	 *
	 * @since 1.2.0
	 * @param int $default_per_page Default page size.
	 * @return array<string, array<string, mixed>> Properties to merge in.
	 */
	protected function pagination_schema( $default_per_page = 20 ) {
		return array(
			'page'     => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'default'     => 1,
				'description' => __( 'Page number, starting at 1.', 'linguator-pro' ),
			),
			'per_page' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 100,
				'default'     => (int) $default_per_page,
				'description' => __( 'Items per page, up to 100.', 'linguator-pro' ),
			),
		);
	}
}
