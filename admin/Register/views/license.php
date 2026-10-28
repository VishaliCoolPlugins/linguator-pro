<?php
/**
 * License screen markup for Linguator Pro.
 *
 * @package Linguator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the license activation page
 */
function lmatp_render_license_page() {
	// The activation form collects the license key only; the address the license is
	// registered against is derived server-side from the site's admin email.
	// Escape early for security
	$admin_url = esc_url(admin_url('admin-post.php'));
	$plugin_name = \LinguatorPro\Admin\Register\Linguator_Pro_Register::lmatp_license_plugin_name();
	?>
	<div class="lmatp-dashboard-license">

		<section class="lmatp-license-card lmatp-license-box">

			<div class="lmatp-license-header">
				<div class="lmatp-license-icon lmatp-license-icon-danger" aria-hidden="true">
					<span class="dashicons dashicons-lock"></span>
				</div>
				<div>
					<h2>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: plugin name */
								__( '%s - License & Updates', 'linguator-pro' ),
								$plugin_name
							)
						);
						?>
					</h2>
					<p><?php esc_html_e('Activate your license to receive automatic updates and premium support.', 'linguator-pro'); ?></p>
				</div>
			</div>

			<form class="lmatp-license-form" method="post" action="<?php echo esc_url($admin_url); ?>">

				<input type="hidden" name="action" value="lmatp_el_activate_license"/>

				<?php wp_nonce_field('el-lmatp-license'); ?>

				<div class="lmatp-license-field">
					<label for="license_code"><?php esc_html_e('License Key', 'linguator-pro'); ?></label>
					<div class="lmatp-input-wrap">
						<span class="dashicons dashicons-admin-network lmatp-input-icon" aria-hidden="true"></span>
						<input type="text" name="license_code" id="license_code" required
							placeholder="<?php esc_attr_e('xxxxxxxx-xxxxxxxx-xxxxxxxx-xxxxxxxx', 'linguator-pro'); ?>">
					</div>
					<p class="lmatp-help-text"><?php echo wp_kses_post( __( 'Find your key in <a href="https://my.coolplugins.net/account/" target="_blank" rel="noopener noreferrer">My Account</a> > <a href="https://my.coolplugins.net/account/manage-license/" target="_blank" rel="noopener noreferrer">Manage Licenses</a>.', 'linguator-pro' ) ); ?></p>
				</div>

				<div class="lmatp-el-license-active-btn">
					<button type="submit" class="lmatp-btn lmatp-btn-primary">
						<?php echo esc_html__('Activate License', 'linguator-pro'); ?>
					</button>
				</div>

			</form>

			<div class="lmatp-separator"></div>

			<?php lmatp_render_license_help_buttons(); ?>

		</section>
	</div>
<?php
}

/**
 * Renders the license information page for Pro users
 *
 * @param object|null $license_info License information object
 */
function lmatp_render_license_page_pro($license_info = null) {

	// Fallback only if base class is available; avoids fatal error when licenser isn't loaded
	if (!$license_info && class_exists(\LinguatorPro\Admin\Register\Linguator_Pro_Base::class)) {
		$license_info = \LinguatorPro\Admin\Register\Linguator_Pro_Base::get_register_info();
	}

	if (!is_object($license_info) || !isset($license_info->is_valid) || !isset($license_info->license_title) || !isset($license_info->expire_date)) {
		wp_die(esc_html__('Error: Invalid license information', 'linguator-pro'));
		return;
	}

	// Sanitize license key before masking
	$license_key = sanitize_text_field(get_option(\LinguatorPro\Admin\Register\Linguator_Pro_Register::lmatp_license_key_option(), ''));
	$masked_key = !empty($license_key) ?
		esc_html(substr($license_key, 0, 8) . '-XXXXXXXX-XXXXXXXX-' . substr($license_key, -8)) :
		'';

	$admin_url = esc_url(admin_url('admin-post.php'));
	$plugin_name = \LinguatorPro\Admin\Register\Linguator_Pro_Register::lmatp_license_plugin_name();

	// Resolve the status badge and the header banner copy from one branch, so the
	// badge, the icon and the banner can never disagree with each other.
	$status_class = 'invalid';
	$status_label = __( 'Invalid', 'linguator-pro' );
	$is_negative  = true;
	$header_title = sprintf(
		/* translators: %s: plugin name */
		__( 'Your %s license is invalid.', 'linguator-pro' ),
		$plugin_name
	);
	$header_desc = __( 'Please check your license key or contact support for help.', 'linguator-pro' );

	if ( ! empty( $license_info->is_valid ) ) {
		if ( lmatp_is_license_expired( $license_info ) ) {
			$status_class = 'expired';
			$status_label = __( 'License Expired', 'linguator-pro' );
			$header_title = sprintf(
				/* translators: %s: plugin name */
				__( '%s license has expired.', 'linguator-pro' ),
				$plugin_name
			);
			$header_desc = __( 'Renew now to continue receiving automatic updates and support.', 'linguator-pro' );
		} elseif ( lmatp_is_support_expired( $license_info ) ) {
			$status_class = 'support-expired';
			$status_label = __( 'Support Expired', 'linguator-pro' );
			$header_title = sprintf(
				/* translators: %s: plugin name */
				__( '%s support has expired.', 'linguator-pro' ),
				$plugin_name
			);
			$header_desc = __( 'Renew now to continue receiving automatic updates and support.', 'linguator-pro' );
		} else {
			$status_class = 'valid';
			$status_label = __( 'Valid', 'linguator-pro' );
			$is_negative  = false;
			$header_title = sprintf(
				/* translators: %s: plugin name */
				__( 'Your %s license is active.', 'linguator-pro' ),
				$plugin_name
			);
			$header_desc = __( 'Premium updates and support are enabled for this website.', 'linguator-pro' );
		}
	}

	$icon_modifier   = $is_negative ? 'danger' : 'success';
	$notice_modifier = $is_negative ? 'danger' : 'success';
?>
	<div class="lmatp-dashboard-license">
		<section class="lmatp-license-card lmatp-license-box">

			<div class="lmatp-license-header">

				<div class="lmatp-license-icon lmatp-license-icon-<?php echo esc_attr($icon_modifier); ?>" aria-hidden="true">
					<?php if ($is_negative): ?>
						!
					<?php else: ?>
						<span class="dashicons dashicons-yes"></span>
					<?php endif; ?>
				</div>

				<div>
					<h2>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: plugin name */
								__( '%s - License & Updates', 'linguator-pro' ),
								$plugin_name
							)
						);
						?>
					</h2>
				</div>

				<?php if (lmatp_needs_refresh($license_info)): ?>
					<div class="lmatp-refresh-row">
						<button type="button" class="lmatp-refresh-btn" id="lmatp-refresh-license-btn">
							<?php esc_html_e('Check license status', 'linguator-pro'); ?>
						</button>
					</div>
				<?php endif; ?>
			</div>

			<div class="lmatp-notice lmatp-notice-<?php echo esc_attr($notice_modifier); ?>">
				<div class="lmatp-notice-icon" aria-hidden="true">
					<?php if ($is_negative): ?>
						!
					<?php else: ?>
						<span class="dashicons dashicons-yes"></span>
					<?php endif; ?>
				</div>
				<div>
					<strong><?php echo esc_html($header_title); ?></strong>
					<p><?php echo esc_html($header_desc); ?></p>
				</div>
			</div>

			<div class="lmatp-license-details">

				<div class="lmatp-detail-row">
					<div class="lmatp-detail-label">
						<span class="dashicons dashicons-shield" aria-hidden="true"></span>
						<strong><?php esc_html_e('Status', 'linguator-pro'); ?></strong>
					</div>
					<div class="lmatp-detail-value">
						<span class="lmatp-validity lmatp-status-badge <?php echo esc_attr($status_class); ?>">
							<strong><?php echo esc_html($status_label); ?></strong>
						</span>
					</div>
				</div>

				<div class="lmatp-detail-row">
					<div class="lmatp-detail-label">
						<span class="dashicons dashicons-media-default" aria-hidden="true"></span>
						<strong><?php esc_html_e('License Type', 'linguator-pro'); ?></strong>
					</div>
					<div class="lmatp-detail-value">
						<span class="lmatp-license-type"><?php echo esc_html($license_info->license_title); ?></span>
					</div>
				</div>

				<div class="lmatp-detail-row">
					<div class="lmatp-detail-label">
						<span class="dashicons dashicons-clock" aria-hidden="true"></span>
						<strong><?php esc_html_e('Updates & Support', 'linguator-pro'); ?></strong>
					</div>
					<div class="lmatp-detail-value">
						<span class="lmatp-validity">

						<?php
						$current_time = time();

						// Handle "No expiry" case for expire_date
						$expire_date_expired = false;
						if (strtolower($license_info->expire_date) !== 'no expiry') {
							$expire_date_timestamp = strtotime($license_info->expire_date);
							$expire_date_expired = $expire_date_timestamp && $expire_date_timestamp < $current_time;
						}

						// Handle "no support" case for support_end
						if (strtolower($license_info->support_end) === 'no support') {

							esc_html_e('No Support', 'linguator-pro');

						} else {

							// Handle "unlimited" case for support_end
							$support_end_expired = false;

							if (strtolower($license_info->support_end) !== 'unlimited') {

								$support_end_timestamp = strtotime($license_info->support_end);
								$support_end_expired = $support_end_timestamp && $support_end_timestamp < $current_time;

							}
							if ($expire_date_expired) {

								echo esc_html(lmatp_pro_formatLicenseDate($license_info->expire_date));

							} elseif ($support_end_expired) {

								echo esc_html(lmatp_pro_formatLicenseDate($license_info->support_end));

							} else {

								echo esc_html(lmatp_pro_formatLicenseDate($license_info->expire_date));

							}

						}
						?>
						</span>
					</div>
				</div>

				<?php if ($masked_key): ?>
					<div class="lmatp-detail-row">
						<div class="lmatp-detail-label">
							<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
							<strong><?php echo esc_html__('License Key', 'linguator-pro'); ?></strong>
						</div>
						<div class="lmatp-detail-value lmatp-license-key">
							<code class="license-key"><?php echo esc_html($masked_key); ?></code>
						</div>
					</div>
				<?php endif; ?>

			</div>

			<div class="lmatp-deactivate-row">
				<div class="lmatp-notice lmatp-notice-info">
					<div class="lmatp-notice-icon" aria-hidden="true"><span class="dashicons dashicons-info-outline"></span></div>
					<div class="lmatp-notice-body">
						<strong><?php esc_html_e('Moving this license to another website, staging site, or live domain?', 'linguator-pro'); ?></strong>
						<p><?php esc_html_e('Deactivate it here first.', 'linguator-pro'); ?></p>
					</div>
					<form method="post" action="<?php echo esc_url($admin_url); ?>">
						<input type="hidden" name="action" value="lmatp_el_deactivate_license" />
						<?php wp_nonce_field('el-lmatp-license'); ?>
						<div class="lmatp-submit-button">
							<button type="submit" class="lmatp-btn lmatp-btn-danger">
								<?php echo esc_html__('Deactivate License', 'linguator-pro'); ?>
							</button>
						</div>
					</form>
				</div>
			</div>


			<div class="lmatp-separator"></div>

			<?php lmatp_render_license_help_buttons($license_info); ?>
		</section>
	</div>
<?php
}

/**
 * Renders the license buttons section
 *
 * @param object|null $license_info License information object, or null on the
 *                                  activation form where no license exists yet.
 */
function lmatp_render_license_help_buttons($license_info = null) {

	$is_object          = is_object($license_info);
	$is_envato          = $is_object && isset($license_info->market) && $license_info->market === 'E';
	$manage_license_url = $is_envato
		? 'https://codecanyon.net/downloads/'
		: 'https://my.coolplugins.net/account/manage-license/';
	$downloads_url      = 'https://my.coolplugins.net/account/downloads/';
	$support_url        = 'https://coolplugins.net/support/?utm_source=lmatp_plugin&utm_medium=inside&utm_campaign=support&utm_content=dashboard_license';
	$activation_guide   = 'https://docs.coolplugins.net/';

	?>
	<div class="lmatp-license-support">

		<?php if (!$is_object): ?>
			<p><?php esc_html_e('Learn more about your subscription or license below.', 'linguator-pro'); ?></p>
		<?php else: ?>
			<p class="lmatp-license-support-label">
				<span class="dashicons dashicons-info" aria-hidden="true"></span>
				<?php esc_html_e('Manage your license or download the latest version', 'linguator-pro'); ?>
			</p>
		<?php endif; ?>

		<div class="lmatp-action-row lmatp-support-buttons">

			<a href="<?php echo esc_url($manage_license_url); ?>" target="_blank" rel="noopener noreferrer" class="lmatp-btn lmatp-btn-secondary">
				<?php echo esc_html__('Manage License', 'linguator-pro'); ?>
			</a>

			<?php if (!$is_object): ?>
				<a href="<?php echo esc_url($activation_guide); ?>" target="_blank" rel="noopener noreferrer" class="lmatp-btn lmatp-btn-secondary">
					<?php echo esc_html__('Activation Guide', 'linguator-pro'); ?>
				</a>
			<?php endif; ?>

			<?php if ($is_object && !$is_envato): ?>
				<a href="<?php echo esc_url($downloads_url); ?>" target="_blank" rel="noopener noreferrer" class="lmatp-btn lmatp-btn-secondary">
					<?php echo esc_html__('Download Latest Version', 'linguator-pro'); ?>
				</a>
			<?php endif; ?>

			<a href="<?php echo esc_url($support_url); ?>" target="_blank" rel="noopener noreferrer" class="lmatp-btn lmatp-btn-secondary">
				<?php echo esc_html__('Contact Support', 'linguator-pro'); ?>
			</a>

		</div>

	</div>
	<?php
}

function lmatp_is_license_expired($license_info) {

	// Reached with null on the activation form, where no license exists yet.
	if ( ! is_object( $license_info ) ) {
		return false;
	}

	if ( isset( $license_info->is_valid ) && 'license_expired' === $license_info->is_valid ) {
		return true;
	}

	$expire = isset( $license_info->expire_date ) ? strtolower( $license_info->expire_date ) : '';

	return '' !== $expire && ! in_array( $expire, array( 'no expiry', 'unlimited' ), true ) && strtotime( $license_info->expire_date ) && strtotime( $license_info->expire_date ) < time();
}

function lmatp_is_support_expired($license_info) {

	// An empty support_end is "unknown", not "expired": strtotime('') is false,
	// which would otherwise compare as older than now and flag a healthy license.
	$end = isset($license_info->support_end) ? strtolower($license_info->support_end) : '';

	return $end === 'no support'
		|| ( isset($license_info->is_valid) && $license_info->is_valid === 'support_expired' )
		|| ( $end !== 'unlimited' && ! empty($end) && strtotime($license_info->support_end) < time() );
}

function lmatp_needs_refresh($license_info) {

	return lmatp_is_license_expired($license_info) || lmatp_is_support_expired($license_info);
}


function lmatp_render_expiry_message($license_info, $type = 'license') {
	// Generate version available message using common helper
	$version_available_message = \LinguatorPro\Admin\Register\Linguator_Pro_Register::lmatpGetVersionAvailableMessage();

	if ($license_info->msg === 'limit_reached') {
		$support_link = sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url('https://my.coolplugins.net/account/support-tickets/'), esc_html__('clicking here', 'linguator-pro'));
		echo wp_kses_post(sprintf(
			/* translators: %s: link to support ticket page */
			__('There was an issue with your account. Please contact our plugin support team by %s.', 'linguator-pro'),
			$support_link
		));
		return;
	}

	$message = $type === 'license'
		? __('Your license has expired,', 'linguator-pro')
		: __('Your support has expired,', 'linguator-pro');

	$renew_link = isset($license_info->market) && $license_info->market === 'E'
		? ' <a href="'.esc_url('https://codecanyon.net/downloads/').'" target="_blank" rel="noopener noreferrer">'.esc_html__('Renew now', 'linguator-pro').'</a>'
		: ' <a href="'.esc_url('https://my.coolplugins.net/account/subscriptions/').'" target="_blank" rel="noopener noreferrer">'.esc_html__('Renew now', 'linguator-pro').'</a>';

	 $final_message = '';

	// Add version message if available
	if (!empty($version_available_message)) {

		wp_enqueue_script('thickbox');
		wp_enqueue_style('thickbox');

		$final_message .= wp_kses_post($version_available_message) . ' ';
	}

	// Add license expiry message
	$final_message .= esc_html($message) . $renew_link . esc_html__(' to continue receiving updates and priority support.', 'linguator-pro');

	echo wp_kses_post($final_message);
}

function lmatp_pro_formatLicenseDate($dateString) {

	if (empty($dateString)) {
		return $dateString;
	}

	// Pass non-date tokens through untouched: DateTime() throws on "unlimited".
	$token = strtolower($dateString);
	if ($token === 'no expiry' || $token === 'unlimited' || $token === 'no support') {
		return $dateString;
	}

	$date = new DateTime($dateString);
	return $date->format('d M Y');
}

/**
 * Prints the licence notice slot.
 *
 * The dashboard calls this above .tab-content, so the slot is a sibling of the
 * card and the sidebar and an expiry notice pushes both columns down by the same
 * amount. Rendered inside the card it moved only the left column and the card
 * stopped lining up with the sidebar.
 *
 * Emitted without whitespace between the tags so `:empty` collapses the slot,
 * margin included, when there is nothing to show.
 *
 * Falls back to the same registration record the card reads, so the notice and
 * the card can never disagree. The dashboard cannot supply it: on the expired
 * path check_wp_plugin() returns true without filling in its by-ref response
 * object, which left the slot empty on first load.
 *
 * @param object|null $license_info License information object. Resolved from the
 *                                  stored registration when omitted.
 */
function lmatp_render_license_notices( $license_info = null ) {
	if ( ! $license_info && class_exists(\LinguatorPro\Admin\Register\Linguator_Pro_Base::class) ) {
		$license_info = \LinguatorPro\Admin\Register\Linguator_Pro_Base::get_register_info();
	}

	echo '<div class="lmatp-license-notices">';

	if ( lmatp_is_license_expired( $license_info ) ) {
		echo '<div class="lmatp-newLicense notice notice-error is-dismissible">';
		lmatp_render_expiry_message( $license_info, 'license' );
		echo '</div>';
	} elseif ( lmatp_is_support_expired( $license_info ) ) {
		echo '<div class="lmatp-newLicense notice notice-error is-dismissible">';
		lmatp_render_expiry_message( $license_info, 'support' );
		echo '</div>';
	}

	echo '</div>';
}
