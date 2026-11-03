<?php
/**
 * Base class for every LocoAI ability.
 *
 * @package LocoAI
 * @since   2.3.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Loco\Abilities;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared plumbing for abilities: naming, args assembly, input coercion and
 * permission checks.
 *
 * Subclasses declare only the short slug; the "locoai/" namespace is composed
 * here so it can never drift between abilities.
 *
 * @since 2.3.0
 */
abstract class Abstract_Ability {

	/**
	 * Ability name prefix.
	 *
	 * @since 2.3.0
	 * @var string
	 */
	const ABILITY_NAMESPACE = 'locoai';

	/**
	 * Ability category slug.
	 *
	 * @since 2.3.0
	 * @var string
	 */
	const ABILITY_CATEGORY = 'locoai-loco-translate';

	/**
	 * Capability Loco Translate uses for every translation screen.
	 *
	 * @since 2.3.0
	 * @var string
	 */
	const LOCO_CAPABILITY = 'loco_admin';

	/**
	 * Short ability slug, e.g. "get-site-info".
	 *
	 * @since 2.3.0
	 * @return string The slug, without the namespace prefix.
	 */
	abstract protected function get_slug();

	/**
	 * Human-readable ability label.
	 *
	 * @since 2.3.0
	 * @return string Translated label.
	 */
	abstract public function get_label();

	/**
	 * Ability description shown to AI clients.
	 *
	 * @since 2.3.0
	 * @return string Translated description.
	 */
	abstract public function get_description();

	/**
	 * JSON Schema describing the accepted input.
	 *
	 * @since 2.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	abstract public function get_input_schema();

	/**
	 * Runs the ability.
	 *
	 * @since 2.3.0
	 * @param mixed $input Validated input.
	 * @return mixed Result payload, or WP_Error on failure.
	 */
	abstract public static function execute( $input = [] );

	/**
	 * JSON Schema describing the returned output.
	 *
	 * Deliberately loose: WP_Ability::validate_output() hard-fails any response
	 * that drifts from a declared schema, and these payloads are open-shaped.
	 *
	 * @since 2.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return [ 'type' => 'object' ];
	}

	/**
	 * Decides whether the current user may run this ability.
	 *
	 * Every current ability reads or writes Loco Translate files, so they share
	 * Loco's own access rule. Subclasses may override for a stricter check.
	 *
	 * @since 2.3.0
	 * @param mixed $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( $input = [] ) {
		return static::user_can_translate( self::to_array( $input ) );
	}

	/**
	 * Fully qualified ability name, e.g. "locoai/get-site-info".
	 *
	 * @since 2.3.0
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
	 * @since 2.3.0
	 * @return array<string, mixed> Meta array.
	 */
	public function get_meta() {
		return [
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}

	/**
	 * Builds the argument array consumed by wp_register_ability().
	 *
	 * @since 2.3.0
	 * @return array<string, mixed> Registration arguments.
	 */
	public function get_args() {
		return [
			'label'               => $this->get_label(),
			'description'         => $this->get_description(),
			'category'            => static::ABILITY_CATEGORY,
			'input_schema'        => $this->get_input_schema(),
			'output_schema'       => $this->get_output_schema(),
			'execute_callback'    => [ static::class, 'execute' ],
			'permission_callback' => [ static::class, 'check_permission' ],
			'meta'                => $this->get_meta(),
		];
	}

	/**
	 * Whether the current user may manage Loco Translate files.
	 *
	 * Loco grants administrators an implied loco_admin capability, but only
	 * through a user_has_cap filter registered by its AdminHooks class, which
	 * Loco loads on wp-admin requests alone. REST and MCP requests are not
	 * is_admin(), so administrators are accepted through manage_options here;
	 * any other role still needs loco_admin granted in Loco's settings.
	 *
	 * @since 2.3.0
	 * @param array<string, mixed> $input Validated ability input.
	 * @return bool True when permitted.
	 */
	protected static function user_can_translate( array $input = [] ) {
		return static::user_can( self::LOCO_CAPABILITY, $input );
	}

	/**
	 * Whether the current user holds a given capability for this ability.
	 *
	 * Abilities that govern site-wide settings rather than translation files
	 * ask for a stricter capability than Loco's own. Routing those checks
	 * through here keeps the atlt_ability_required_capability filter applicable
	 * to every ability, not just the ones using the default.
	 *
	 * @since 2.5.0
	 * @param string               $capability Capability to test.
	 * @param array<string, mixed> $input      Validated ability input.
	 * @return bool True when permitted.
	 */
	protected static function user_can( $capability, array $input = [] ) {
		/**
		 * Filters the capability required to run a given LocoAI ability.
		 *
		 * Returning an empty string denies access to everyone.
		 *
		 * @since 2.3.0
		 * @param string               $capability Capability being tested. Default "loco_admin".
		 * @param string               $ability    Ability class name.
		 * @param array<string, mixed> $input      Validated ability input.
		 */
		$capability = (string) apply_filters(
			'atlt_ability_required_capability',
			(string) $capability,
			static::class,
			$input
		);

		if ( '' === $capability ) {
			return false;
		}

		if ( current_user_can( $capability ) ) {
			return true;
		}

		// Loco grants administrators loco_admin only on wp-admin requests; see the note above.
		return self::LOCO_CAPABILITY === $capability && current_user_can( 'manage_options' );
	}

	/**
	 * Coerces raw ability input into an array.
	 *
	 * WP_Ability validates input but does not coerce it, and a direct
	 * wp_get_ability()->execute() call may pass null.
	 *
	 * @since 2.3.0
	 * @param mixed $input Raw input.
	 * @return array<string, mixed> Input as an array.
	 */
	protected static function to_array( $input ) {
		return is_array( $input ) ? $input : [];
	}

	/**
	 * Reads a string input value.
	 *
	 * @since 2.3.0
	 * @param array<string, mixed> $input   Ability input.
	 * @param string               $key     Input key.
	 * @param string               $default Value when absent.
	 * @return string Trimmed string value.
	 */
	protected static function input_string( array $input, $key, $default = '' ) {
		if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
			return (string) $default;
		}

		return trim( (string) $input[ $key ] );
	}

	/**
	 * Reads an integer input value clamped to a range.
	 *
	 * @since 2.3.0
	 * @param array<string, mixed> $input   Ability input.
	 * @param string               $key     Input key.
	 * @param int                  $default Value when absent.
	 * @param int                  $min     Lowest accepted value.
	 * @param int                  $max     Highest accepted value.
	 * @return int Clamped integer.
	 */
	protected static function input_int( array $input, $key, $default, $min, $max ) {
		$value = isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? (int) $input[ $key ] : (int) $default;

		return max( (int) $min, min( (int) $max, $value ) );
	}

	/**
	 * Reads a boolean input value.
	 *
	 * Accepts real booleans and the string forms a query string produces.
	 *
	 * @since 2.3.0
	 * @param array<string, mixed> $input   Ability input.
	 * @param string               $key     Input key.
	 * @param bool                 $default Value when absent.
	 * @return bool Boolean value.
	 */
	protected static function input_bool( array $input, $key, $default = false ) {
		if ( ! array_key_exists( $key, $input ) ) {
			return (bool) $default;
		}

		$value = $input[ $key ];

		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'on' ], true );
	}

	/**
	 * Schema for an ability that takes no input.
	 *
	 * The (object) casts matter: they serialise as {} rather than [], and strict
	 * MCP clients reject the array form. The default lets a call without any
	 * input pass validation.
	 *
	 * @since 2.3.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	protected function empty_object_schema() {
		return [
			'type'       => 'object',
			'properties' => (object) [],
			'default'    => (object) [],
		];
	}

	/**
	 * Schema fragment for the bundle/domain pair every project ability accepts.
	 *
	 * @since 2.3.0
	 * @return array<string, mixed> Schema properties.
	 */
	protected function project_properties() {
		return [
			'bundle' => [
				'type'        => 'string',
				'description' => __( 'Bundle ID from list-projects, for example "plugin.woocommerce/woocommerce.php", "theme.twentytwentyfive" or "core".', 'loco-automatic-translate-addon-pro' ),
			],
			'domain' => [
				'type'        => 'string',
				'description' => __( 'Project "domain" value from list-projects. Omit to use the bundle\'s default project, which is right for almost every plugin and theme.', 'loco-automatic-translate-addon-pro' ),
			],
		];
	}

	/**
	 * Schema fragment for a locale code.
	 *
	 * @since 2.3.0
	 * @return array<string, mixed> Schema property.
	 */
	protected function locale_property() {
		return [
			'type'        => 'string',
			'description' => __( 'WordPress locale code, for example "fr_FR", "de_DE", "hi_IN" or "pt_BR".', 'loco-automatic-translate-addon-pro' ),
		];
	}

	/**
	 * Schema fragment for a translation file location.
	 *
	 * @since 2.3.0
	 * @param string   $description Property description.
	 * @param string[] $values      Accepted location keys.
	 * @return array<string, mixed> Schema property.
	 */
	protected function location_property( $description, array $values ) {
		return [
			'type'        => 'string',
			'enum'        => $values,
			'description' => $description,
		];
	}
}
