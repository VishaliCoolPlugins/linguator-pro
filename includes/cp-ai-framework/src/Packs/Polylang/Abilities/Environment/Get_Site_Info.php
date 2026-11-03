<?php
/**
 * Site info ability.
 *
 * @package AutoPoly
 * @since   1.10.0
 */

declare( strict_types=1 );

namespace CoolPlugins\AiFramework\Packs\Polylang\Abilities\Environment;

use CoolPlugins\AiFramework\Packs\Polylang\Abilities\Abstract_Ability;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Content_Service;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Language_Service;
use CoolPlugins\AiFramework\Packs\Polylang\Services\Site_Profile_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports the WordPress and Polylang configuration.
 *
 * @since 1.10.0
 */
final class Get_Site_Info extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Ability slug.
	 */
	protected function get_slug() {
		return 'get-site-info';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated label.
	 */
	public function get_label() {
		return __( 'Get Site Info', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return string Translated description.
	 */
	public function get_description() {
		return __( 'AutoPoly: get the WordPress environment and Polylang configuration, a profile of what the site is about, and the site translation workflow. Call this first. Read and follow translation_workflow before making any translation write; its quick_rules are the short version. For a site-level request (homepage, header, footer, menu pages) call get-translation-plan next and finish with verify-translations. "site_profile" carries the site\'s name and tagline, the owner\'s own context from AutoPoly\'s settings, the home page, pages such as About and Services with their text, the main navigation, SEO descriptions, what content it publishes and what its plugins make it do. Work out the site\'s purpose, industry, audience and tone from it and keep translations consistent with that; "owner_context" outranks anything inferred.', 'autopoly-ai-translation-for-polylang-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_input_schema() {
		return $this->empty_object_schema();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
	 * @return array<string, mixed> JSON Schema fragment.
	 */
	public function get_output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.10.0
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

		$default_lang = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';

		return array(
			'translation_workflow' => self::get_translation_workflow(),
			'wordpress' => array(
				'version'   => get_bloginfo( 'version' ),
				'site_name' => get_bloginfo( 'name' ),
				'site_url'  => get_site_url(),
				'home_url'  => get_home_url(),
				'theme'     => $theme->get( 'Name' ),
				'plugins'   => array_values( $plugins ),
			),
			'polylang'  => array(
				'version'      => defined( 'POLYLANG_VERSION' ) ? POLYLANG_VERSION : '',
				'is_pro'       => defined( 'POLYLANG_PRO' ) && POLYLANG_PRO,
				'default_lang' => $default_lang,
				'languages'    => Language_Service::get_all(),
			),
			'autopoly'  => array(
				'version' => defined( 'ATFPP_V' ) ? ATFPP_V : '',
			),
			'content'      => array(
				'post_types' => Content_Service::get_translatable_post_types(),
				'taxonomies' => Content_Service::get_translatable_taxonomies(),
			),
			'site_profile' => Site_Profile_Service::build(),
		);
	}

	/**
	 * Loads the canonical workflow that AI clients must follow for site translation.
	 *
	 * Keeping the instructions in a framework resource lets the workflow be updated
	 * without duplicating it across capability descriptions, while returning it here
	 * makes it available to every client that can call get-site-info.
	 *
	 * @since 1.15.0
	 * @return array{available: bool, version: string, content: string, notice: string} Workflow payload.
	 */
	private static function get_translation_workflow() {
		$path     = dirname( __DIR__, 5 ) . '/resources/site-translation-workflow.md';
		$content  = is_readable( $path ) ? file_get_contents( $path ) : false;
		$available = is_string( $content );

		return array(
			'available' => $available,
			'version'   => '1.1.0',
			'quick_rules' => array(
				__( '1. Call get-translation-plan for the requested scope; it lists every item and the order. Work through it.', 'autopoly-ai-translation-for-polylang-pro' ),
				__( '2. A successful save is not completion. Read outcome, summary, public_page and links_unresolved on every translate-post result.', 'autopoly-ai-translation-for-polylang-pro' ),
				__( '3. Translate menu destination pages before running sync-menu.', 'autopoly-ai-translation-for-polylang-pro' ),
				__( '4. Finish with verify-translations. Call the job complete only when it returns complete: true; otherwise report the problems.', 'autopoly-ai-translation-for-polylang-pro' ),
				__( '5. If you run out of capacity, say exactly what is done and what remains. Calling get-translation-plan again resumes the job.', 'autopoly-ai-translation-for-polylang-pro' ),
			),
			'content'   => $available ? $content : '',
			'notice'    => $available
				? __( 'Read and follow this workflow before any translation write. It defines discovery, dependency ordering, verification, and batching rules.', 'autopoly-ai-translation-for-polylang-pro' )
				: __( 'The site translation workflow resource could not be read. Do not begin a multi-page translation until it is restored.', 'autopoly-ai-translation-for-polylang-pro' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Stays administrator-only: the payload includes the full active plugin list,
	 * which is a fingerprinting surface.
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True when permitted.
	 */
	public static function check_permission( array $input = array() ) {
		return self::user_can( 'manage_options', $input );
	}
}
