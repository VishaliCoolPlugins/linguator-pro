<?php
/**
 * Site info ability.
 *
 * @package WPML_Auto_Translate
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Wpml\Abilities\Environment;

use CoolPlugins\AiFramework\Packs\Wpml\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Content_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Language_Service;
use CoolPlugins\AiFramework\Packs\Wpml\Services\Wpml_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports the WordPress, WPML and AutoMLP configuration.
 *
 * @since 1.5.0
 */
final class Get_Site_Info extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-site-info';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Site Info', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoMLP: get the WordPress environment and the WPML configuration. Call this first. "wpml" reports whether WPML core and WPML String Translation are both active and whether WPML\'s setup was finished; AutoMLP needs all three before anything can be translated, and "requirements.blocker" names the first one missing. "automlp" reports whether an AI provider is configured. "content" lists the post types and taxonomies WPML is set to translate, and "page_builders" the builders AutoMLP can decompose. If a requirement is unmet, tell the user what to fix rather than calling other tools that will fail the same way. Read and follow translation_workflow (its quick_rules are the short version) before any translation write. For a site-level request (homepage, header, footer, menu pages) call get-translation-plan next and finish with verify-translations.', 'wpml-translation-check-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return $this->empty_object_schema();
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
	 * @return array<string, mixed> Site information.
	 */
	public static function execute( array $input = array() ) {
		$theme   = wp_get_theme();
		$plugins = (array) get_option( 'active_plugins', array() );
		$plugins = array_map(
			static function ( $plugin ) {
				$parts = explode( '/', (string) $plugin );

				return $parts[0];
			},
			$plugins
		);

		$blocker = Wpml_Guard::unavailable_error();
		$types   = Content_Service::get_content_types();

		return array(
			'translation_workflow' => self::get_translation_workflow(),
			'wordpress'    => array(
				'version'   => get_bloginfo( 'version' ),
				'site_name' => get_bloginfo( 'name' ),
				'site_url'  => get_site_url(),
				'home_url'  => get_home_url(),
				'theme'     => $theme->get( 'Name' ),
				'plugins'   => array_values( $plugins ),
			),
			'wpml'         => array(
				'core_version'           => defined( 'ICL_SITEPRESS_VERSION' ) ? ICL_SITEPRESS_VERSION : '',
				'string_translation_version' => defined( 'WPML_ST_VERSION' ) ? WPML_ST_VERSION : '',
				'core_active'            => Wpml_Guard::has_core(),
				'string_translation_active' => Wpml_Guard::has_string_translation(),
				'setup_complete'         => Wpml_Guard::setup_complete(),
				'default_language'       => Language_Service::get_default_code(),
				'languages'              => Language_Service::get_all(),
			),
			'automlp'      => array(
				'version'        => defined( 'AUTOMLP_AI_PRO_VERSION' ) ? AUTOMLP_AI_PRO_VERSION : '',
				'setup_complete' => Wpml_Guard::automlp_setup_complete(),
			),
			'content'      => array(
				'post_types' => $types['post_types'],
				'taxonomies' => $types['taxonomies'],
			),
			'page_builders' => array(
				'supported' => class_exists( 'WPML_AT_PRO_Helper' ) ? \WPML_AT_PRO_Helper::supported_editors() : array(),
				'detected'  => self::detected_builders(),
			),
			'requirements' => array(
				'ready'   => null === $blocker,
				'blocker' => null === $blocker ? '' : $blocker->get_error_code(),
				'message' => null === $blocker ? '' : $blocker->get_error_message(),
			),
		);
	}

	/**
	 * Page-builder plugins active in this request.
	 *
	 * Lets an agent see which builder a translation run will actually have to
	 * decompose before it reads any content.
	 *
	 * @since 1.5.0
	 * @return string[] Builder names.
	 */
	private static function detected_builders() {
		$builders = array();

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$builders[] = 'Elementor';
		}
		if ( defined( 'ET_CORE' ) ) {
			$builders[] = 'Divi';
		}
		if ( defined( 'CS_VERSION' ) || class_exists( 'Cornerstone_Plugin' ) ) {
			$builders[] = 'Cornerstone';
		}

		$builders[] = 'Gutenberg';

		return $builders;
	}

	/**
	 * Loads the workflow that AI clients should follow for site translation.
	 *
	 * Kept in a framework resource so it can be updated without repeating it in
	 * tool descriptions, and returned here so every client that can call
	 * get-site-info receives it.
	 *
	 * @since 1.6.0
	 * @return array{available: bool, version: string, content: string, quick_rules: string[], notice: string} Workflow payload.
	 */
	private static function get_translation_workflow() {
		$path      = dirname( __DIR__, 5 ) . '/resources/wpml-translation-workflow.md';
		$content   = is_readable( $path ) ? file_get_contents( $path ) : false;
		$available = is_string( $content );

		return array(
			'available'   => $available,
			'version'     => '1.0.0',
			'content'     => $available ? $content : '',
			'quick_rules' => array(
				__( '1. Call get-translation-plan for the requested scope; it lists every item and the order. Work through it.', 'wpml-translation-check-pro' ),
				__( '2. New translations are created as drafts unless you pass post_status "publish"; a draft gives visitors a 404.', 'wpml-translation-check-pro' ),
				__( '3. A successful save is not completion. Read outcome, summary and next_action on every translate-post result.', 'wpml-translation-check-pro' ),
				__( '4. Menus are synced by an administrator under WPML > Translations > Menus (older WPML: WPML > WP Menus Sync); these tools cannot do it. Tell the user.', 'wpml-translation-check-pro' ),
				__( '5. Finish with verify-translations. Call the job complete only when it returns complete: true; otherwise report the problems.', 'wpml-translation-check-pro' ),
			),
			'notice'      => $available
				? __( 'Read and follow this workflow before any translation write.', 'wpml-translation-check-pro' )
				: __( 'The translation workflow resource could not be read. Do not begin a multi-page translation until it is restored.', 'wpml-translation-check-pro' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Stays administrator-only: the payload includes the full active plugin
	 * list, which is a fingerprinting surface.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
