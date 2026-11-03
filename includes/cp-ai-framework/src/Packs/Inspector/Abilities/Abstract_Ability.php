<?php
/**
 * Base class for every Translation Inspector ability.
 *
 * @package CoolPluginsAiFramework
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Inspector\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared plumbing for abilities: naming, args assembly and permission checks.
 *
 * Subclasses declare only the short slug; the "translation-inspector/"
 * namespace is composed here so it can never drift between abilities.
 *
 * @since 1.1.0
 */
abstract class Abstract_Ability {

	/**
	 * Ability name prefix.
	 *
	 * Deliberately not "dupcap": ability names are part of the public surface
	 * AI clients and saved prompts depend on.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const ABILITY_NAMESPACE = 'translation-inspector';

	/**
	 * Ability category slug.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const ABILITY_CATEGORY = 'translation-inspector';

	/**
	 * Section keys the dashboard groups issues into.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	const SECTIONS = array( 'content', 'elementor', 'urls', 'site' );

	/**
	 * Severity levels a stored result can carry, most urgent first.
	 *
	 * These are DUPCAP_Result's statuses. The dashboard's "Notice" filter is a
	 * display label for "info", not a stored value.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	const SEVERITIES = array( 'critical', 'warning', 'info', 'pass' );

	/**
	 * Short ability slug, e.g. "get-issues".
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
	 * Written for the AI, not the user: say what the tool returns, when to call
	 * it, and which ability to call next.
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
	 * Keep it loose ({'type': 'object'}): WP_Ability::validate_output() hard-fails
	 * any response that drifts from a declared schema.
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * Runs the ability.
	 *
	 * @since 1.1.0
	 * Callbacks receive whatever the caller passed: an array from REST, or
	 * null from an MCP call with no arguments. Start with
	 * `$input = self::input_array( $input );`.
	 *
	 * @param mixed $input Validated input, or null.
	 * @return mixed Result payload, or WP_Error on failure.
	 */
	abstract public static function execute( $input = array() );

	/**
	 * Decides whether the current user may run this ability.
	 *
	 * Defaults to manage_options, the capability the Inspector dashboard and
	 * every one of its AJAX handlers require.
	 *
	 * @since 1.1.0
	 * @param mixed $input Validated input, or null.
	 * @return bool True when permitted.
	 */
	public static function check_permission( $input = array() ) {
		return static::user_can( 'manage_options', self::input_array( $input ) );
	}

	/**
	 * The input as an array, whatever form it arrived in.
	 *
	 * WP_Ability::check_permissions() is public and the MCP adapter calls it
	 * before normalize_input() applies the schema default, so a tool called
	 * with no arguments receives null here.
	 *
	 * @since 1.1.0
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
	 * Fully qualified ability name, e.g. "translation-inspector/get-issues".
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
	 * Meta for an ability that writes but never destroys anything.
	 *
	 * Scans, rechecks and ignore toggles: they change stored reports or state,
	 * and running them twice leaves the same result.
	 *
	 * @since 1.1.0
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
	 * Builds the argument array consumed by wp_register_ability().
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> Registration arguments.
	 */
	public function get_args() {
		$input_schema = $this->get_input_schema();

		// A REST GET with no input passes null, which fails "type: object".
		// WP_Ability::normalize_input() swaps in the schema default, as core's
		// own abilities rely on, so every tool can be called with no arguments.
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
		 * @since 1.1.0
		 * @param string               $capability Capability being tested.
		 * @param string               $ability    Ability class name.
		 * @param array<string, mixed> $input      Validated ability input.
		 */
		$capability = (string) apply_filters(
			'dupcap_ability_required_capability',
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
	 * Schema fragment for the page / per_page pair used by list abilities.
	 *
	 * @since 1.1.0
	 * @param int $default_per_page Default page size.
	 * @return array<string, array<string, mixed>> Properties to merge in.
	 */
	protected function pagination_schema( $default_per_page = 20 ) {
		return array(
			'page'     => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'default'     => 1,
				'description' => __( 'Page number, starting at 1.', 'duplicate-content-addon-for-polylang' ),
			),
			'per_page' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 100,
				'default'     => (int) $default_per_page,
				'description' => __( 'Items per page, up to 100.', 'duplicate-content-addon-for-polylang' ),
			),
		);
	}
}
