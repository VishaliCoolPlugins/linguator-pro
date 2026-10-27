	<?php
	/**
	 * Displays the wizard notice content
	 *
	 * @package Linguator
	 *
	 *  
	 */
	if ( ! defined( 'ABSPATH' ) ) {
		exit; // Don't access directly.
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$wizard_url = add_query_arg(
		array(
			'page' => 'lmat_wizard',
		),
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		admin_url( 'admin.php' )
	);
	?>
	<p>
	<strong>
	<?php
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	esc_html_e( 'Welcome to ', 'linguator-pro' );
	?>
	<a href="https://wordpress.org/plugins/translate-words/" target="_blank" rel="noopener noreferrer">
	<?php
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	esc_html_e( 'Linguator AI – Auto Translate & Create Multilingual Sites', 'linguator-pro' );
	?>
	</a>
	</strong>
		<?php
		echo ' &#8211; ';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		esc_html_e( 'You&lsquo;re almost ready to translate your contents!', 'linguator-pro' );
		?>
	</p>
	<p class="buttons" style="margin-top: 20px;">
		<a
			href="<?php echo esc_url( $wizard_url ); ?>"
			class="button button-primary"
		>
			<?php esc_html_e( 'Run the Setup Wizard', 'linguator-pro' ); ?>
		</a>
		<a
			class="button button-secondary skip"
			href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'lmat-hide-notice', 'wizard' ), 'wizard', '_lmat_notice_nonce' ) ); ?>"
		>
			<?php esc_html_e( 'Skip setup', 'linguator-pro' ); ?>
		</a>
	</p>
