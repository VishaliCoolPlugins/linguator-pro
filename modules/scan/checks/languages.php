<?php
/**
 * Language setup checks.
 *
 * @package Linguator
 */

namespace Linguator\Modules\Scan\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Linguator\Modules\Scan\Abstract_Check;
use Linguator\Modules\Scan\Elementor_Fields;
use Linguator\Modules\Scan\Result;

/**
 * Default language, number of languages and static front page translations.
 *
 * @since 2.3.0
 */
class Languages extends Abstract_Check {

	/**
	 * @var string
	 */
	protected $group = 'Language Setup';

	/**
	 * Runs the check.
	 *
	 * @since 2.3.0
	 *
	 * @return Result[]
	 */
	public function run(): array {
		$results   = array();
		$languages = $this->active_languages();
		$default   = $this->default_lang();
		$manage    = admin_url( 'admin.php?page=lmat' );

		if ( '' !== $default ) {
			$results[] = $this->result(
				'lang_default',
				Result::PASS,
				__( 'Default language set', 'linguator-pro' ),
				/* translators: %s: language slug */
				sprintf( __( 'Default language is "%s".', 'linguator-pro' ), $default )
			);
		} else {
			$results[] = $this->result(
				'lang_default',
				Result::CRITICAL,
				__( 'No default language', 'linguator-pro' ),
				__( 'Linguator has no default language. Nothing will be translated until you set one.', 'linguator-pro' )
			)->with_fix( $manage, __( 'Set language', 'linguator-pro' ) );
		}

		$count = count( $languages );
		if ( $count >= 2 ) {
			$results[] = $this->result(
				'lang_secondary',
				Result::PASS,
				__( 'Secondary language present', 'linguator-pro' ),
				/* translators: %d: number of languages */
				sprintf( _n( '%d language configured.', '%d languages configured.', $count, 'linguator-pro' ), $count )
			);
		} else {
			$results[] = $this->result(
				'lang_secondary',
				Result::CRITICAL,
				__( 'Only one language', 'linguator-pro' ),
				__( 'You need at least two languages for translations to appear. Add a second language.', 'linguator-pro' )
			)->with_fix( $manage, __( 'Add language', 'linguator-pro' ) );
		}

		$homepage = $this->homepage_result( $languages );
		if ( $homepage ) {
			$results[] = $homepage;
		}

		return $results;
	}

	/**
	 * Checks that a static front page exists in every language.
	 *
	 * @since 2.3.0
	 *
	 * @param object[] $languages Active languages.
	 * @return Result|null Null when the site does not use a static front page.
	 */
	private function homepage_result( array $languages ): ?Result {
		$front_id = (int) get_option( 'page_on_front' );
		if ( 'page' !== get_option( 'show_on_front' ) || $front_id < 1 ) {
			return null;
		}

		$translations = $this->model->post->get_translations( $front_id );
		$details      = array();
		$names        = array();

		$source_layout = Elementor_Fields::has_widgets( $front_id );
		$empty_names   = array();

		foreach ( $languages as $lang ) {
			$trans_id = (int) ( $translations[ $lang->slug ] ?? 0 );

			if ( $trans_id > 0 ) {
				if ( $source_layout && $trans_id !== $front_id && ! Elementor_Fields::has_widgets( $trans_id ) ) {
					$empty_names[] = $lang->name;
					$details[]     = array(
						'label'        => sprintf(
							/* translators: %s: language name */
							__( '%s homepage has no Elementor layout', 'linguator-pro' ),
							$lang->name
						),
						'message'      => __( 'A translation exists, but its Elementor layout is empty.', 'linguator-pro' ),
						'action_url'   => (string) get_edit_post_link( $trans_id, 'url' ),
						'action_label' => sprintf(
							/* translators: %s: language name */
							__( 'Translate %s', 'linguator-pro' ),
							$lang->name
						),
						'ignore_key'   => 'lang_homepage_empty:' . $lang->slug,
						'language'     => $lang->slug,
					);
				}
				continue;
			}

			$names[]   = $lang->name;
			$details[] = array(
				'label'        => sprintf(
					/* translators: %s: language name */
					__( 'Homepage missing in %s', 'linguator-pro' ),
					$lang->name
				),
				'message'      => __( 'No homepage translation is linked in Linguator yet.', 'linguator-pro' ),
				'action_url'   => $this->new_translation_url( $front_id, $lang->slug ),
				'action_label' => sprintf(
					/* translators: %s: language name */
					__( 'Translate %s', 'linguator-pro' ),
					$lang->name
				),
				'ignore_key'   => 'lang_homepage:' . $lang->slug,
			);
		}

		foreach ( $details as &$detail ) {
            $detail['source_title'] = get_the_title( $front_id );
            $detail['post_type'] = 'page';
            $detail['edit_url'] = (string) get_edit_post_link( $front_id, 'url' );
        }
        unset( $detail );

		if ( empty( $details ) ) {
			return $this->result(
				'lang_homepage',
				Result::PASS,
				__( 'Homepage translated for all languages', 'linguator-pro' ),
				__( 'Every language has a front page.', 'linguator-pro' )
			);
		}

		return $this->result(
			'lang_homepage',
			Result::CRITICAL,
			__( 'Homepage not translated for all languages', 'linguator-pro' ),
			trim(
				( $names ? sprintf(
					/* translators: %s: comma separated language names */
					__( 'Front page is missing a translation in: %s.', 'linguator-pro' ),
					implode( ', ', $names )
				) : '' ) . ' ' . ( $empty_names ? sprintf(
					/* translators: %s: comma separated language names */
					__( 'Front page translation has no Elementor layout in: %s.', 'linguator-pro' ),
					implode( ', ', $empty_names )
				) : '' )
			)
		)
			->with_fix( (string) get_edit_post_link( $front_id, 'url' ), __( 'Edit homepage', 'linguator-pro' ) )
			->with_details( $details );
	}
}
