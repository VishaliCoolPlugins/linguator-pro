<?php
/**
 * Base class for every ability in every pack.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared plumbing for abilities: naming, args assembly and permission checks.
 *
 * A pack extends this once, defining the three constants below, and its
 * abilities extend that pack class. Subclasses declare only the short slug; the
 * namespace prefix is composed here so it can never drift between abilities.
 *
 * @since 1.1.0
 */
abstract class Abstract_Ability {

	/**
	 * Ability name prefix, e.g. "autopoly". Set by the pack base class.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const ABILITY_NAMESPACE = '';

	/**
	 * Ability category slug. Set by the pack base class.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const ABILITY_CATEGORY = '';

	/**
	 * Filter that lets a site owner change the capability a tool requires.
	 * Set by the pack base class; kept per pack so existing filters keep working.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const CAPABILITY_FILTER = '';

	/**
	 * Short ability slug, e.g. "get-site-info".
	 *
	 * @since 1.1.0
	 * @return string The slug, without the namespace prefix.
	 */
	abstract protected function get_slug();

	/**
	 * Human-readable ability label.
	 *
	 * @since 1.1.0
	 * @return string Translated label.
	 */
	abstract public function get_label();

	/**
	 * Ability description shown to AI clients.
	 *
	 * @since 1.1.0
	 * @return string Translated description.
	 */
	abstract public function get_description();

	/**
	 * JSON Schema describing the accepted input.
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	abstract public function get_input_schema();

	/**
	 * JSON Schema describing the returned output.
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	abstract public function get_output_schema();

	/**
	 * Runs the ability.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Validated input.
	 * @return mixed Result payload, or WP_Error on failure.
	 */
	abstract public static function execute( array $input = array() );

	/**
	 * Decides whether the current user may run this ability.
	 *
	 * Core passes the validated input, so subclasses can perform object-level
	 * capability checks rather than a blanket check.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	abstract public static function check_permission( array $input = array() );

	/**
	 * Fully qualified ability name, e.g. "autopoly/get-site-info".
	 *
	 * @since 1.1.0
	 * @return string Ability name.
	 */
	final public function get_name() {
		return static::ABILITY_NAMESPACE . '/' . $this->get_slug();
	}

	/**
	 * Ability metadata and MCP annotations.
	 *
	 * Defaults describe a safe read-only tool. Write abilities override this.
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * Builds the argument array consumed by wp_register_ability().
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> Registration arguments.
	 */
	public function get_args() {
		return array(
			'label'               => $this->get_label(),
			'description'         => $this->get_description(),
			'category'            => static::ABILITY_CATEGORY,
			'input_schema'        => $this->get_input_schema(),
			'output_schema'       => $this->get_output_schema(),
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => array( static::class, 'check_permission' ),
			'meta'                => $this->get_meta(),
		);
	}

	/**
	 * Capability check routed through a filter so site owners can adjust it.
	 *
	 * @since 1.1.0
	 * @param string               $capability Capability to test.
	 * @param array<string, mixed> $input      Validated ability input.
	 * @param mixed                ...$args    Optional object ID for meta capabilities.
	 * @return bool True when the current user has the capability.
	 */
	protected static function user_can( $capability, array $input = array(), ...$args ) {
		/**
		 * Filters the capability required to run a given ability.
		 *
		 * The hook name is set per pack (CAPABILITY_FILTER), e.g.
		 * "atfpp_ability_required_capability" for the Polylang pack.
		 *
		 * @since 1.1.0
		 * @param string               $capability Capability being tested.
		 * @param string               $ability    Ability class name.
		 * @param array<string, mixed> $input      Validated ability input.
		 */
		$capability = (string) apply_filters(
			static::CAPABILITY_FILTER,
			(string) $capability,
			static::class,
			$input
		);

		if ( '' === $capability ) {
			return false;
		}

		return current_user_can( $capability, ...$args );
	}

	/**
	 * Coerces a list-valued input into a plain array.
	 *
	 * Accepts a real array, a JSON array as text, or a comma-separated list, so
	 * an ability behaves the same however the client encoded the parameter.
	 *
	 * @since 1.1.0
	 * @param mixed $value Raw input value.
	 * @return array<int|string, mixed> List items, unsanitised.
	 */
	protected static function normalize_list( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return array();
		}

		if ( '[' === $value[0] ) {
			$decoded = json_decode( $value, true );

			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return explode( ',', $value );
	}

	/**
	 * Schema fragment for an object with no properties.
	 *
	 * The (object) cast matters: it serialises as {} rather than [], and strict
	 * MCP clients reject the array form.
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	protected function empty_object_schema() {
		return array(
			'type'       => 'object',
			'properties' => (object) array(),
		);
	}
}
