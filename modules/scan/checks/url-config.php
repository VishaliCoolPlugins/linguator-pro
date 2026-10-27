<?php
/**
 * URL configuration checks.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LinguatorPro\Modules\Scan\Abstract_Check;
use LinguatorPro\Modules\Scan\Result;

/**
 * Permalink structure, URL modifications and per-language domains.
 *
 * @since 2.3.0
 */
class Url_Config extends Abstract_Check {

	/**
	 * @var string
	 */
	protected $group = 'URLs & Routing';

	/**
	 * @var string
	 */
	protected $section = 'urls';

	/**
	 * Runs the check.
	 *
	 * @since 2.3.0
	 *
	 * @return Result[]
	 */
	public function run(): array {
		$languages = $this->active_languages();
		if ( count( $languages ) < 2 ) {
			return array();
		}

		$results    = array();
		$force_lang = (int) $this->option( 'force_lang', 1 );
		$settings   = admin_url( 'admin.php?page=lmat_settings&tab=general' );

		if ( '' === (string) get_option( 'permalink_structure' ) && $force_lang > 0 ) {
			$results[] = $this->result(
				'url_permalinks',
				Result::WARNING,
				__( 'Plain permalinks in use', 'linguator-pro' ),
				__( 'Pretty permalinks are off. Language URLs like /fr/ will not work correctly. Switch to a post-name permalink structure.', 'linguator-pro' )
			)->with_fix( admin_url( 'options-permalink.php' ), __( 'Permalinks', 'linguator-pro' ) );
		} else {
			$results[] = $this->result(
				'url_permalinks',
				Result::PASS,
				__( 'Language URLs configured', 'linguator-pro' ),
				__( 'Permalink structure supports language URLs.', 'linguator-pro' )
			);
		}

		if ( 3 === $force_lang ) {
			$results[] = $this->domains_result( $languages, $settings );
		}

		$results[] = $this->duplicate_locale_result( $languages );

		return $results;
	}

	/**
	 * In domain mode every language needs its own domain.
	 *
	 * @since 2.3.0
	 *
	 * @param object[] $languages Active languages.
	 * @param string   $fix_url   Settings URL.
	 * @return Result
	 */
	private function domains_result( array $languages, string $fix_url ): Result {
		$domains = (array) $this->option( 'domains', array() );
		$details = array();
		$hosts   = array();

		foreach ( $languages as $lang ) {
			$host = strtolower( (string) wp_parse_url( (string) ( $domains[ $lang->slug ] ?? '' ), PHP_URL_HOST ) );

			if ( '' !== $host && isset( $hosts[ $host ] ) ) {
				$details[] = array(
					'label'        => $this->lang_label( $lang ),
					'message'      => sprintf(
						/* translators: 1: domain, 2: other language name */
						__( 'The domain %1$s is already used by %2$s. Each language needs its own domain.', 'linguator-pro' ),
						$host,
						$hosts[ $host ]
					),
					'action_url'   => admin_url( 'admin.php?page=lmat' ),
					'action_label' => __( 'Edit language', 'linguator-pro' ),
					'ignore_key'   => 'url_domains_shared:' . $lang->slug,
				);
				continue;
			}
			if ( '' !== $host ) {
				$hosts[ $host ] = $this->lang_label( $lang );
				continue;
			}

			$details[] = array(
				'label'        => $this->lang_label( $lang ),
				'message'      => __( 'No domain is set for this language.', 'linguator-pro' ),
				'action_url'   => admin_url( 'admin.php?page=lmat' ),
				'action_label' => __( 'Edit language', 'linguator-pro' ),
				'ignore_key'   => 'url_domains:' . $lang->slug,
			);
		}

		if ( empty( $details ) ) {
			return $this->result(
				'url_domains',
				Result::PASS,
				__( 'Language domains set', 'linguator-pro' ),
				__( 'Every language has its own domain.', 'linguator-pro' )
			);
		}

		return $this->result(
			'url_domains',
			Result::CRITICAL,
			__( 'Language domains are incomplete', 'linguator-pro' ),
			__( 'The site uses one domain per language, but some languages have none or share one. Languages without their own working domain will not load.', 'linguator-pro' )
		)
			->with_fix( $fix_url, __( 'URL settings', 'linguator-pro' ) )
			->with_details( $details );
	}

	/**
	 * Two languages sharing a locale break translated strings and hreflang output.
	 *
	 * @since 2.3.0
	 *
	 * @param object[] $languages Active languages.
	 * @return Result
	 */
	private function duplicate_locale_result( array $languages ): Result {
		$seen    = array();
		$details = array();

		foreach ( $languages as $lang ) {
			if ( isset( $seen[ $lang->locale ] ) ) {
				$details[] = array(
					'label'        => $this->lang_label( $lang ),
					'message'      => sprintf(
						/* translators: 1: locale, 2: other language name */
						__( 'Locale %1$s is already used by %2$s.', 'linguator-pro' ),
						$lang->locale,
						$seen[ $lang->locale ]
					),
					'action_url'   => admin_url( 'admin.php?page=lmat' ),
					'action_label' => __( 'Edit language', 'linguator-pro' ),
					'ignore_key'   => 'url_locale:' . $lang->slug,
				);
				continue;
			}
			$seen[ $lang->locale ] = $lang->name;
		}

		if ( empty( $details ) ) {
			return $this->result(
				'url_locales',
				Result::PASS,
				__( 'Language locales are unique', 'linguator-pro' ),
				__( 'No two languages share a locale.', 'linguator-pro' )
			);
		}

		return $this->result(
			'url_locales',
			Result::WARNING,
			__( 'Languages share a locale', 'linguator-pro' ),
			__( 'Languages with the same locale cannot be told apart by WordPress. Give each language its own locale.', 'linguator-pro' )
		)->with_details( $details );
	}
}
