<?php
/**
 * String translation checks.
 *
 * @package Linguator
 */

namespace Linguator\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Linguator\Admin\Controllers\Linguator_Admin_Strings;
use Linguator\Includes\Helpers\Linguator_MO;
use Linguator\Modules\Scan\Abstract_Check;
use Linguator\Modules\Scan\Result;

/**
 * Reports registered strings (site title, widgets…) with no translation in a secondary language.
 *
 * @since 2.3.0
 */
class Strings extends Abstract_Check {

	/**
	 * Strings that are the same in every language by design, as "context|name".
	 */
	const SKIPPED = array(
		'WordPress|date_format',
		'WordPress|time_format',
	);

	/**
	 * @var string
	 */
	protected $group = 'String Translation';

	/**
	 * Runs the check.
	 *
	 * @since 2.3.0
	 *
	 * @return Result[]
	 */
	public function run(): array {
		$languages = $this->active_languages();
		$default   = $this->default_lang();
		$strings   = $this->registered_strings();

		if ( count( $languages ) < 2 || empty( $strings ) ) {
			return array();
		}

		$results = array();

		foreach ( $languages as $lang ) {
			if ( $lang->slug === $default ) {
				continue;
			}

			if ( ! $this->wants( 'string_' . $lang->slug ) ) {
				continue;
			}

			$mo = new Linguator_MO();
			$mo->import_from_db( $lang );

			$label   = $this->lang_label( $lang );
			$details = array();

			foreach ( $strings as $item ) {
				if ( '' !== $mo->translate_if_any( $item['string'] ) ) {
					continue;
				}

				$details[] = array(
					'label'        => wp_html_excerpt( wp_strip_all_tags( $item['string'] ), 160, '…' ),
                    'subtitle' => $item['title'],
					'message'      => sprintf(
						/* translators: 1: string title, 2: language name with code */
						__( '"%1$s" has no translation in "%2$s".', 'linguator-pro' ),
						$item['title'],
						$label
					),
					'action_url'   => admin_url( 'admin.php?page=lmat_settings&tab=strings&lang=' . rawurlencode( $lang->slug ) ),
					'action_label' => __( 'Translate', 'linguator-pro' ),
					'ignore_key'   => 'string:' . $lang->slug . ':' . $item['key'],
				);
			}

			$id = 'string_' . $lang->slug;

			if ( empty( $details ) ) {
				$results[] = $this->result(
					$id,
					Result::PASS,
					/* translators: %s: language name with code */
					sprintf( __( 'Site strings translated in "%s"', 'linguator-pro' ), $label ),
					__( 'All registered strings are translated.', 'linguator-pro' )
				);
				continue;
			}

			$results[] = $this->result(
				$id,
				Result::WARNING,
				/* translators: %s: language name with code */
				sprintf( __( 'Strings not translated in "%s"', 'linguator-pro' ), $label ),
				sprintf(
					/* translators: 1: number of strings, 2: language name with code */
					_n(
						'%1$d registered string has no translation in "%2$s".',
						'%1$d registered strings have no translation in "%2$s".',
						count( $details ),
						'linguator-pro'
					),
					count( $details ),
					$label
				)
			)
				->with_fix( admin_url( 'admin.php?page=lmat_settings&tab=strings' ), __( 'Static strings', 'linguator-pro' ) )
				->with_details( $details );
		}

		return $results;
	}

	/**
	 * Strings listed on the Static Strings screen, plus the core site title and tagline.
	 *
	 * @since 2.3.0
	 *
	 * @return array[]
	 */
	private function registered_strings(): array {
		$raw = array();

		if ( class_exists( Linguator_Admin_Strings::class ) ) {
			try {
				$raw = Linguator_Admin_Strings::get_strings();
			} catch ( \Throwable $e ) {
				$raw = array();
			}
		}

		if ( ! is_array( $raw ) || empty( $raw ) ) {
			$raw = array(
				array(
					'name'    => 'blogname',
					'string'  => get_option( 'blogname' ),
					'context' => 'WordPress',
				),
				array(
					'name'    => 'blogdescription',
					'string'  => get_option( 'blogdescription' ),
					'context' => 'WordPress',
				),
			);
		}

		$strings = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['string'] ) || ! is_scalar( $entry['string'] ) ) {
				continue;
			}

			$string  = (string) $entry['string'];
			$name    = isset( $entry['name'] ) ? (string) $entry['name'] : '';
			$context = isset( $entry['context'] ) ? (string) $entry['context'] : '';

			// Strings with no letters ("#", "0", "|") have nothing to translate.
			if ( ! preg_match( '/\p{L}/u', $string ) || in_array( $context . '|' . $name, self::SKIPPED, true ) ) {
				continue;
			}

			$core_titles = array(
				'blogname'        => __( 'Site Title', 'linguator-pro' ),
				'blogdescription' => __( 'Tagline', 'linguator-pro' ),
			);
			$is_core     = 'WordPress' === $context && isset( $core_titles[ $name ] );
			$excerpt     = wp_html_excerpt( wp_strip_all_tags( $string ), 80, '…' );
			$title       = $is_core ? $core_titles[ $name ] : ( '' !== trim( $name ) ? $name . ' — ' . wp_html_excerpt( wp_strip_all_tags( $string ), 60, '…' ) : $excerpt );

			$strings[] = array(
				'key'    => md5( $context . '|' . $string ),
				'title'  => $title,
				'string' => $string,
			);
		}

		return $strings;
	}
}
