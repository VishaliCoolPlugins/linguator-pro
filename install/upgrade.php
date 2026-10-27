<?php
/**
 * @package Linguator
 */

namespace LinguatorPro\Install;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

use LinguatorPro\Includes\Options\Options;

/**
 * Runs one-shot data upgrades when the stored plugin version is older than the code.
 *
 * Each upgrade routine is gated by a version number and runs only when the version
 * stored in the plugin options is lower than that gate. Once the caller stores the
 * current plugin version, the routine never runs again.
 *
 * @since 2.2.1
 */
class Linguator_Upgrade {

	/**
	 * Option set once the ISO flag code migration has run.
	 *
	 * @var string
	 */
	const FLAG_CODES_MIGRATED_OPTION = 'lmat_flag_codes_migrated';

	/**
	 * Plugin options.
	 *
	 * @var Options
	 */
	protected $options;

	/**
	 * Constructor.
	 *
	 * @since 2.2.1
	 *
	 * @param Options $options Plugin options.
	 */
	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Runs the pending upgrades if the stored version is older than the plugin version.
	 *
	 * Fresh installs (no stored version) are skipped since they have no legacy data.
	 *
	 * @since 2.2.1
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$previous_version = $this->options['version'];

		if ( empty( $previous_version ) || ! is_string( $previous_version ) ) {
			return;
		}

		if ( version_compare( $previous_version, LINGUATOR_PRO_VERSION, '<' ) ) {
			$this->upgrade( $previous_version );
		}

		// Sites already storing 2.2.1 never hit the version gate above, so the flag code
		// migration is also keyed on a one-shot option and runs exactly once per site.
		if ( ! get_option( self::FLAG_CODES_MIGRATED_OPTION ) ) {
			$this->upgrade_2_2_1();
		}
	}

	/**
	 * Calls each upgrade routine whose version gate is above the previous version.
	 *
	 * Routines are named `upgrade_{version}` with dots replaced by underscores.
	 *
	 * @since 2.2.1
	 *
	 * @param string $previous_version Version stored before this upgrade.
	 * @return void
	 */
	protected function upgrade( string $previous_version ): void {
		foreach ( array( '2.2.1' ) as $version ) {
			$method = 'upgrade_' . str_replace( '.', '_', $version );

			if ( version_compare( $previous_version, $version, '<' ) && method_exists( $this, $method ) ) {
				$this->$method();
			}
		}

		/**
		 * Fires after Linguator data upgrades have been run.
		 *
		 * @since 2.2.1
		 *
		 * @param string $previous_version Version stored before this upgrade.
		 */
		do_action( 'lmat_upgrade', $previous_version );
	}

	/**
	 * Upgrades if the previous version is < 2.2.1, or once on sites already at 2.2.1.
	 * Replaces legacy flag file names stored in language terms by their ISO based codes.
	 * Custom or unknown flag codes are left untouched.
	 *
	 * @since 2.2.1
	 *
	 * @return void
	 */
	protected function upgrade_2_2_1(): void {
		if ( get_option( self::FLAG_CODES_MIGRATED_OPTION ) ) {
			return;
		}

		// Flag first so a failure mid-way can never cause repeated runs.
		update_option( self::FLAG_CODES_MIGRATED_OPTION, 1 );

		$to_migrate = array(
			'arab'      => 'ar.lang',
			'basque'    => 'es-pv',
			'catalonia' => 'es-ct',
			'england'   => 'gb-eng',
			'esperanto' => 'eo.lang',
			'galicia'   => 'es-ga',
			'kurdistan' => 'iq-kr',
			'occitania' => 'oci.lang',
			'quebec'    => 'ca-qc',
			'scotland'  => 'gb-sct',
			'tibet'     => 'bo.lang',
			'veneto'    => 'it-34',
			'wales'     => 'gb-wls',
		);

		$terms = get_terms(
			array(
				'taxonomy'   => 'lmat_language',
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $terms ) ) {
			return;
		}

		$updated = false;

		foreach ( $terms as $term ) {
			$description = linguator_pro_maybe_unserialize( $term->description );
			$description = is_array( $description ) ? $description : array();

			if ( isset( $description['flag_code'] ) && is_string( $description['flag_code'] ) && isset( $to_migrate[ $description['flag_code'] ] ) ) {
				$description['flag_code'] = $to_migrate[ $description['flag_code'] ];

				/** @var string $description */
				$description = maybe_serialize( $description );

				$result  = wp_update_term( $term->term_id, 'lmat_language', array( 'description' => $description ) );
				$updated = $updated || ! is_wp_error( $result );
			}
		}

		if ( $updated ) {
			// Make sure the cached languages list is rebuilt with the new flag codes.
			delete_transient( 'lmat_languages_list' );
		}
	}
}