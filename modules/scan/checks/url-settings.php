<?php
/**
 * URL settings checks.
 *
 * @package Linguator
 */

namespace Linguator\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Linguator\Modules\Scan\Abstract_Check;
use Linguator\Modules\Scan\Result;

/**
 * Plain-language summary of the URL settings and the common ways they go wrong.
 *
 * Linguator's own Site Health prints these as raw values ("force_lang: 1"). The interpretation is the whole
 * value of this check.
 *
 * @since 2.3.0
 */
class Url_Settings extends Abstract_Check {

	/**
	 * The language is set from the content, not the URL.
	 */
	const FORCE_LANG_CONTENT = 0;

	/**
	 * Language is a directory, such as /fr/.
	 */
	const FORCE_LANG_DIRECTORY = 1;

	/**
	 * Language is a subdomain.
	 */
	const FORCE_LANG_SUBDOMAIN = 2;

	/**
	 * Each language has its own domain.
	 */
	const FORCE_LANG_DOMAIN = 3;

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

		$force_lang = (int) $this->option( 'force_lang', self::FORCE_LANG_DIRECTORY );

		$found = array_filter(
			array(
				$this->summary( $force_lang ),
				$this->no_lang_in_url( $force_lang ),
				$this->default_prefix( $languages ),
				$this->stale_rewrites(),
				$this->home_mismatch(),
				$this->browser_cache_conflict(),
				$this->blog_page( $languages ),
				$this->front_page_posts_mode(),
			)
		);

		return array_values(
			array_filter(
				$found,
				function ( Result $result ) {
					return $this->wants( $result->id );
				}
			)
		);
	}

	/**
	 * Builds a warning whose single row carries the explanation and the fix, so the card can list it.
	 *
	 * @since 2.3.0
	 *
	 * @param string $id        Result id.
	 * @param string $label     Title.
	 * @param string $message   Explanation.
	 * @param string $fix_url   Where to fix it.
	 * @param string $fix_label Fix button text.
	 * @return Result
	 */
	private function warning( string $id, string $label, string $message, string $fix_url, string $fix_label ): Result {
		return $this->result( $id, Result::WARNING, $label, $message )
			->with_fix( $fix_url, $fix_label )
			->with_details(
				array(
					array(
						'label'        => $label,
						'message'      => $message,
						'action_url'   => $fix_url,
						'action_label' => $fix_label,
						'ignore_key'   => 'url_config:' . $id,
					),
				)
			);
	}

	/**
	 * Always-present summary of the URL settings.
	 *
	 * @since 2.3.0
	 *
	 * @param int $force_lang Language URL mode.
	 * @return Result
	 */
	private function summary( int $force_lang ): Result {
		$lines = array(
			/* translators: %s: where the language appears in the URL */
			sprintf( __( 'Language in URL: %s.', 'linguator-pro' ), $this->force_lang_label( $force_lang ) ),
			/* translators: %s: Yes or No */
			sprintf( __( 'Default language hidden from URLs: %s.', 'linguator-pro' ), $this->yes_no( $this->hides_default() ) ),
			/* translators: %s: Yes or No */
			sprintf( __( 'Pretty permalinks: %s.', 'linguator-pro' ), $this->yes_no( (bool) get_option( 'permalink_structure' ) ) ),
			/* translators: %s: Yes or No */
			sprintf( __( 'Browser language detection: %s.', 'linguator-pro' ), $this->yes_no( $this->option( 'browser', false ) ) ),
		);

		return $this->result( 'url_config_summary', Result::PASS, __( 'URL settings', 'linguator-pro' ), implode( ' ', $lines ) )
			->with_fix( admin_url( 'admin.php?page=lmat_settings&tab=general' ), __( 'Linguator URL settings', 'linguator-pro' ) );
	}

	/**
	 * The language is not in the URL at all.
	 *
	 * @since 2.3.0
	 *
	 * @param int $force_lang Language URL mode.
	 * @return Result|null
	 */
	private function no_lang_in_url( int $force_lang ): ?Result {
		if ( self::FORCE_LANG_CONTENT !== $force_lang ) {
			return null;
		}

		return $this->warning(
			'url_config_no_lang_in_url',
			__( 'Language is not shown in your URLs', 'linguator-pro' ),
			__( 'Linguator is set to detect the language from the content instead of the URL. Every language shares one address, so search engines and visitors cannot link to a specific language, and caching plugins will serve whichever language was cached first.', 'linguator-pro' ),
			admin_url( 'admin.php?page=lmat_settings&tab=general' ),
			__( 'Change URL modifications', 'linguator-pro' )
		);
	}

	/**
	 * The default language still carries its own prefix, e.g. /it/ on an Italian-default site.
	 *
	 * @since 2.3.0
	 *
	 * @param object[] $languages Active languages.
	 * @return Result|null
	 */
	private function default_prefix( array $languages ): ?Result {
		if ( $this->hides_default() ) {
			return null;
		}

		$default = $this->default_lang();
		$home    = '';
		foreach ( $languages as $lang ) {
			if ( $lang->slug === $default && method_exists( $lang, 'get_home_url' ) ) {
				$home = (string) $lang->get_home_url();
			}
		}
		if ( '' === $home ) {
			return null;
		}

		$default_path = untrailingslashit( (string) wp_parse_url( $home, PHP_URL_PATH ) );
		$site_path    = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		if ( $default_path === $site_path ) {
			return null;
		}

		return $this->warning(
			'url_config_default_prefix',
			__( 'Your main language has an extra code in its URLs', 'linguator-pro' ),
			sprintf(
				/* translators: 1: default language home URL, 2: site home URL */
				__( 'Pages in your default language are served from %1$s instead of %2$s. Turn on "Hide URL language information for default language" in Linguator settings to remove the code.', 'linguator-pro' ),
				$home,
				home_url( '/' )
			),
			admin_url( 'admin.php?page=lmat_settings&tab=general' ),
			__( 'Hide the default language code', 'linguator-pro' )
		);
	}

	/**
	 * Rewrite rules were never regenerated after a settings or permalink change.
	 *
	 * @since 2.3.0
	 *
	 * @return Result|null
	 */
	private function stale_rewrites(): ?Result {
		if ( ! get_option( 'permalink_structure' ) ) {
			// Plain permalinks have no rewrite rules by design; the permalink check covers this.
			return null;
		}

		$rules = get_option( 'rewrite_rules' );
		if ( ! empty( $rules ) && is_array( $rules ) ) {
			return null;
		}

		return $this->warning(
			'url_config_stale_rewrites',
			__( 'Your URL rules need to be refreshed', 'linguator-pro' ),
			__( 'WordPress has no saved rewrite rules, so language URLs may return "Page not found" until they are rebuilt. Opening Settings → Permalinks and pressing Save Changes rebuilds them. You do not need to change anything on that screen.', 'linguator-pro' ),
			admin_url( 'options-permalink.php' ),
			__( 'Open Permalinks and save', 'linguator-pro' )
		);
	}

	/**
	 * WordPress Address and Site Address disagree on scheme or host.
	 *
	 * @since 2.3.0
	 *
	 * @return Result|null
	 */
	private function home_mismatch(): ?Result {
		$home = wp_parse_url( (string) get_option( 'home' ) );
		$site = wp_parse_url( (string) get_option( 'siteurl' ) );

		if ( empty( $home['host'] ) || empty( $site['host'] ) ) {
			return null;
		}

		$host_differs   = strtolower( $home['host'] ) !== strtolower( $site['host'] );
		$scheme_differs = strtolower( $home['scheme'] ?? '' ) !== strtolower( $site['scheme'] ?? '' );
		if ( ! $host_differs && ! $scheme_differs ) {
			return null;
		}

		return $this->warning(
			'url_config_home_mismatch',
			__( 'Your two site addresses do not match', 'linguator-pro' ),
			sprintf(
				/* translators: 1: Site Address, 2: WordPress Address */
				__( 'WordPress Address is %2$s but Site Address is %1$s. When these differ, switching language can bounce visitors between addresses or fail to load. Make both use the same address unless you set this up deliberately.', 'linguator-pro' ),
				(string) get_option( 'home' ),
				(string) get_option( 'siteurl' )
			),
			admin_url( 'options-general.php' ),
			__( 'Check site addresses', 'linguator-pro' )
		);
	}

	/**
	 * Browser language detection behind a full-page cache serves one language to everybody.
	 *
	 * @since 2.3.0
	 *
	 * @return Result|null
	 */
	private function browser_cache_conflict(): ?Result {
		if ( ! $this->option( 'browser', false ) ) {
			return null;
		}

		$cache = $this->page_cache();
		if ( '' === $cache ) {
			return null;
		}

		return $this->warning(
			'url_config_browser_cache_conflict',
			__( 'Browser language detection conflicts with your cache', 'linguator-pro' ),
			sprintf(
				/* translators: %s: caching plugin name */
				__( 'Linguator is set to detect the visitor language from the browser, but %s is caching full pages. The first visitor decides which language gets stored, and everyone after them is served that same cached language regardless of their browser. Either turn off browser detection, or exclude the home page from caching.', 'linguator-pro' ),
				$cache
			),
			admin_url( 'admin.php?page=lmat_settings&tab=general' ),
			__( 'Review browser detection', 'linguator-pro' )
		);
	}

	/**
	 * A language has no posts page while the default language does.
	 *
	 * @since 2.3.0
	 *
	 * @param object[] $languages Active languages.
	 * @return Result|null
	 */
	private function blog_page( array $languages ): ?Result {
		if ( ! (int) get_option( 'page_for_posts' ) ) {
			return null;
		}

		$default = $this->default_lang();
		$missing = array();
		foreach ( $languages as $lang ) {
			if ( $lang->slug !== $default && ! (int) $lang->page_for_posts ) {
				$missing[] = $this->lang_label( $lang );
			}
		}
		if ( empty( $missing ) ) {
			return null;
		}

		return $this->warning(
			'url_config_blog_page',
			__( 'Your blog page is missing in some languages', 'linguator-pro' ),
			sprintf(
				/* translators: %s: comma separated language names */
				__( 'No posts page is set for: %s. Visitors in those languages may get "Page not found" where your blog should be. Translate the page you use as the posts page, and Linguator will use the translation automatically.', 'linguator-pro' ),
				implode( ', ', $missing )
			),
			admin_url( 'options-reading.php' ),
			__( 'Reading settings', 'linguator-pro' )
		);
	}

	/**
	 * The site shows latest posts on the front page, so there is no homepage to translate.
	 *
	 * @since 2.3.0
	 *
	 * @return Result|null
	 */
	private function front_page_posts_mode(): ?Result {
		if ( 'posts' !== get_option( 'show_on_front' ) ) {
			return null;
		}

		return $this->warning(
			'url_config_front_page_posts_mode',
			__( 'Your homepage shows latest posts, so it cannot be translated directly', 'linguator-pro' ),
			__( 'Your front page is set to show your latest posts rather than a page. There is no homepage to translate, so each language shows whatever posts exist in it — and a language with no posts shows an empty homepage. Create a page, translate it, and set it as your front page in Settings → Reading.', 'linguator-pro' ),
			admin_url( 'options-reading.php' ),
			__( 'Reading settings', 'linguator-pro' )
		);
	}

	/**
	 * Name of an active full-page caching plugin, or an empty string.
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 */
	private function page_cache(): string {
		$signatures = array(
			'LSCWP_V'                      => 'LiteSpeed Cache',
			'WP_ROCKET_VERSION'            => 'WP Rocket',
			'W3TC'                         => 'W3 Total Cache',
			'WPCACHEHOME'                  => 'WP Super Cache',
			'WPFC_MAIN_PATH'               => 'WP Fastest Cache',
			'SiteGround_Optimizer\VERSION' => 'SiteGround Optimizer',
		);

		foreach ( $signatures as $constant => $label ) {
			if ( defined( $constant ) ) {
				return $label;
			}
		}

		return '';
	}

	/**
	 * Whether the default language code is hidden from URLs.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	private function hides_default(): bool {
		return (bool) $this->option( 'hide_default', false );
	}

	/**
	 * Human label for a force_lang value.
	 *
	 * @since 2.3.0
	 *
	 * @param int $force_lang Language URL mode.
	 * @return string
	 */
	private function force_lang_label( int $force_lang ): string {
		switch ( $force_lang ) {
			case self::FORCE_LANG_CONTENT:
				return __( 'not shown in the URL (detected from the content)', 'linguator-pro' );
			case self::FORCE_LANG_SUBDOMAIN:
				return __( 'a subdomain, such as fr.example.com', 'linguator-pro' );
			case self::FORCE_LANG_DOMAIN:
				return __( 'a separate domain for each language', 'linguator-pro' );
			default:
				return __( 'a directory, such as /fr/', 'linguator-pro' );
		}
	}

	/**
	 * Translated Yes / No.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $value Truthy value.
	 * @return string
	 */
	private function yes_no( $value ): string {
		return $value ? __( 'Yes', 'linguator-pro' ) : __( 'No', 'linguator-pro' );
	}
}
