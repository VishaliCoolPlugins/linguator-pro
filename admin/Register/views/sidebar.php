<?php
/**
 * License tab sidebar for Linguator Pro.
 *
 * Same cards as the sidebar on the other Linguator settings tabs: translation status,
 * the LocoAI add-on and the review request.
 *
 * @package Linguator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'lmatp_format_number' ) ) {
	/**
	 * Shorten a large number: 1200 becomes 1.2K.
	 *
	 * @param int|float $number Number to format.
	 * @return int|float|string
	 */
	function lmatp_format_number( $number ) {
		if ( $number >= 1000000000 ) {
			return round( $number / 1000000000, 1 ) . esc_html__( 'B', 'linguator-pro' );
		}
		if ( $number >= 1000000 ) {
			return round( $number / 1000000, 1 ) . esc_html__( 'M', 'linguator-pro' );
		}
		if ( $number >= 1000 ) {
			return round( $number / 1000, 1 ) . esc_html__( 'K', 'linguator-pro' );
		}

		return $number;
	}
}

if ( ! function_exists( 'lmatp_format_time_taken' ) ) {
	/**
	 * Format a number of seconds as sec, min or hours.
	 *
	 * @param int $time_taken Seconds.
	 * @return string
	 */
	function lmatp_format_time_taken( $time_taken ) {
		$time_taken = (int) $time_taken;

		if ( 0 === $time_taken ) {
			return '0';
		}
		if ( $time_taken < 60 ) {
			/* translators: %d: seconds. */
			return sprintf( esc_html__( '%d sec', 'linguator-pro' ), $time_taken );
		}
		if ( $time_taken < 3600 ) {
			/* translators: 1: minutes, 2: seconds. */
			return sprintf( esc_html__( '%1$d min %2$d sec', 'linguator-pro' ), floor( $time_taken / 60 ), $time_taken % 60 );
		}

		/* translators: 1: hours, 2: minutes. */
		return sprintf( esc_html__( '%1$d hours %2$d min', 'linguator-pro' ), floor( $time_taken / 3600 ), floor( ( $time_taken % 3600 ) / 60 ) );
	}
}

if ( ! function_exists( 'lmatp_get_translation_stats' ) ) {
	/**
	 * Totals for the "Auto Translation Status" card, read the same way as the settings page.
	 *
	 * @return array{characters:int|float|string,strings:int|float|string,pages:int,time:string,providers:string[]}
	 */
	function lmatp_get_translation_stats() {
		$stats = array(
			'characters' => 0,
			'strings'    => 0,
			'pages'      => 0,
			'time'       => '0',
			'providers'  => array(),
		);

		$dashboard = '\\LinguatorPro\\Includes\\Other\\Linguator_Translation_Dashboard';
		if ( ! class_exists( $dashboard ) ) {
			return $stats;
		}

		$labels = array(
			'chrome_local_ai'       => 'Chrome AI',
			'edge_local_ai'         => 'Edge AI',
			'google'                => 'Google',
			'gemini'                => 'Gemini AI',
			'localAiTranslator'     => 'Chrome AI',
			'edgeLocalAiTranslator' => 'Edge AI',
		);

		$data = $dashboard::get_translation_data( 'lmat' );
		if ( ! is_array( $data ) ) {
			return $stats;
		}

		$stats['characters'] = isset( $data['total_character_count'] ) ? lmatp_format_number( $data['total_character_count'] ) : 0;
		$stats['strings']    = isset( $data['total_string_count'] ) ? lmatp_format_number( $data['total_string_count'] ) : 0;
		$stats['time']       = isset( $data['total_time_taken'] ) ? lmatp_format_time_taken( $data['total_time_taken'] ) : '0';
		$stats['pages']      = ( isset( $data['data'] ) && is_array( $data['data'] ) ) ? count( $data['data'] ) : 0;

		$providers = ( isset( $data['service_providers'] ) && is_array( $data['service_providers'] ) ) ? $data['service_providers'] : array();
		foreach ( $providers as $provider ) {
			$key = is_string( $provider ) ? $provider : '';
			if ( '' === $key ) {
				continue;
			}
			$stats['providers'][] = isset( $labels[ $key ] ) ? $labels[ $key ] : ucfirst( $key );
		}

		// Chrome AI is stored under two keys; show each provider once.
		$stats['providers'] = array_values( array_unique( $stats['providers'] ) );

		return $stats;
	}
}

if ( ! function_exists( 'lmatp_get_locoai_status' ) ) {
	/**
	 * Whether the LocoAI add-on is installed or active.
	 *
	 * @return string 'active', 'installed' or 'not_installed'.
	 */
	function lmatp_get_locoai_status() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_path = 'automatic-translator-addon-for-loco-translate/automatic-translator-addon-for-loco-translate.php';
		$plugins     = get_plugins();

		if ( is_plugin_active( $plugin_path ) ) {
			return 'active';
		}

		return isset( $plugins[ $plugin_path ] ) ? 'installed' : 'not_installed';
	}
}

/**
 * Renders the sidebar next to the license card.
 */
function lmatp_render_license_sidebar() {
	$stats  = lmatp_get_translation_stats();
	$locoai = lmatp_get_locoai_status();
	?>
	<!-- Right Sidebar -->
	<aside class="lmatp-dashboard-sidebar">

		<div class="lmatp-dashboard-status lmatp-dashboard-card">
			<h3><?php esc_html_e( 'Auto Translation Status', 'linguator-pro' ); ?></h3>
			<div class="lmatp-dashboard-sts-top">
				<span><?php echo esc_html( $stats['characters'] ); ?></span>
				<span><?php esc_html_e( 'Total Characters Translated!', 'linguator-pro' ); ?></span>
			</div>
			<ul class="lmatp-dashboard-sts-btm">
				<li><span><?php esc_html_e( 'Total Strings', 'linguator-pro' ); ?></span> <span><?php echo esc_html( $stats['strings'] ); ?></span></li>
				<li><span><?php esc_html_e( 'Total Pages / Posts', 'linguator-pro' ); ?></span> <span><?php echo esc_html( $stats['pages'] ); ?></span></li>
				<li><span><?php esc_html_e( 'Time Taken', 'linguator-pro' ); ?></span> <span><?php echo esc_html( $stats['time'] ); ?></span></li>
			</ul>
			<?php if ( ! empty( $stats['providers'] ) ) : ?>
				<div class="lmatp-dashboard-providers">
					<span class="lmatp-dashboard-providers-label"><?php esc_html_e( 'Service Providers', 'linguator-pro' ); ?></span>
					<div class="lmatp-dashboard-providers-list">
						<?php foreach ( $stats['providers'] as $provider ) : ?>
							<span class="lmatp-dashboard-provider"><?php echo esc_html( $provider ); ?></span>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( 'not_installed' === $locoai ) : ?>
			<div class="lmatp-dashboard-translate-full lmatp-dashboard-card">
				<h3><?php esc_html_e( 'Automatically Translate Plugins & Themes', 'linguator-pro' ); ?></h3>
				<div class="lmatp-dashboard-addon first">
					<div class="lmatp-dashboard-addon-l">
						<strong><?php esc_html_e( 'LocoAI - Auto Translation for Loco Translate', 'linguator-pro' ); ?></strong>
						<a class="lmatp-dashboard-btn primary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( admin_url( 'plugin-install.php?s=locoai&tab=search&type=term' ) ); ?>">
							<?php esc_html_e( 'Install', 'linguator-pro' ); ?>
						</a>
					</div>
					<div class="lmatp-dashboard-addon-r">
						<img src="<?php echo esc_url( LINGUATOR_PRO_URL . 'assets/logo/loco.png' ); ?>" alt="<?php esc_attr_e( 'LocoAI', 'linguator-pro' ); ?>">
					</div>
				</div>
			</div>
		<?php endif; ?>

		<div class="lmatp-dashboard-rate-us lmatp-dashboard-card">
			<h3>
				<a href="https://wordpress.org/support/plugin/translate-words/reviews/#new-post" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Rate Us ⭐⭐⭐⭐⭐', 'linguator-pro' ); ?>
				</a>
			</h3>
			<p><?php esc_html_e( "We'd love your feedback! Hope this addon made auto-translations easier for you.", 'linguator-pro' ); ?></p>
			<a class="review-link" href="https://wordpress.org/support/plugin/translate-words/reviews/#new-post" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Submit a Review →', 'linguator-pro' ); ?>
			</a>
		</div>

	</aside>
	<?php
}
