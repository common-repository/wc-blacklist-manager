<?php
if (!defined('ABSPATH')) {
	exit;
}
?>

<div class="wrap">
	<?php if (!$premium_active): ?>
		<p>Please support us by <a href="https://wordpress.org/plugins/wc-blacklist-manager/#reviews" target="_blank">leaving a review</a> <span style="color: #e26f56;">&#9733;&#9733;&#9733;&#9733;&#9733;</span> to keep updating & improving.</p>
	<?php endif; ?>
	<h1>
		<?php echo esc_html__('Notification Settings', 'wc-blacklist-manager'); ?> 
		<a href="https://yoohw.com/docs/category/woocommerce-blacklist-manager/notifications/" target="_blank" style="text-decoration: none;"><span class="dashicons dashicons-editor-help"></span></a>
		<?php if (!$premium_active): ?>
			<a href="https://wordpress.org/support/plugin/wc-blacklist-manager/" target="_blank" class="button button-secondary">Support / Suggestion</a>
		<?php endif; ?>
	</h1>

	<?php if (!empty($data['message'])): ?>
		<div id="setting-error-settings_updated" class="updated settings-error notice is-dismissible"> 
			<p><strong><?php echo esc_html($data['message']); ?></strong></p>
		</div>
	<?php endif; ?>

	<form method="post">
		<?php wp_nonce_field('wc_blacklist_email_settings_action', 'wc_blacklist_email_settings_nonce'); ?>

		<h2><?php echo esc_html__( 'Suspect email', 'wc-blacklist-manager' ); ?></h2>
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Enable:', 'wc-blacklist-manager' ); ?></th>
					<td>
						<input type="checkbox" id="wc_blacklist_email_notification" name="wc_blacklist_email_notification" value="yes" <?php checked($data['email_notification_enabled'], 'yes'); ?> />
						<label for="wc_blacklist_email_notification"><?php echo esc_html__( 'Send email notification to admin when an order is placed by a suspect', 'wc-blacklist-manager' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wc_blacklist_email_subject"><?php echo esc_html__( 'Subject:', 'wc-blacklist-manager' ); ?></label></th>
					<td>
						<input type="text" id="wc_blacklist_email_subject" name="wc_blacklist_email_subject" value="<?php echo esc_attr($data['email_subject']); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wc_blacklist_email_message"><?php echo esc_html__( 'Message:', 'wc-blacklist-manager' ); ?></label></th>
					<td>
						<textarea id="wc_blacklist_email_message" name="wc_blacklist_email_message" rows="5" class="regular-text"><?php echo esc_textarea($data['email_message']); ?></textarea>
						<p class="description"><?php echo esc_html__( 'You can use {first_name}, {last_name}, {phone}, {email}, {user_ip}, {address} and {order_id} in message and subject. HTML allowed.', 'wc-blacklist-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wc_blacklist_additional_emails"><?php echo esc_html__( 'Additional email(s):', 'wc-blacklist-manager' ); ?></label></th>
					<td>
						<input type="text" id="wc_blacklist_additional_emails" name="wc_blacklist_additional_emails" value="<?php echo esc_attr($data['additional_emails']); ?>" class="regular-text" />
						<p class="description"><?php echo esc_html__( 'Enter additional email addresses separated by commas.', 'wc-blacklist-manager' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<h2><?php echo esc_html__( 'Alert notices', 'wc-blacklist-manager' ); ?></h2>
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row"><label for="wc_blacklist_checkout_notice"><?php echo esc_html__('Checkout notice:', 'wc-blacklist-manager'); ?></label></th>
					<td>
						<textarea id="wc_blacklist_checkout_notice" name="wc_blacklist_checkout_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['checkout_notice']); ?></textarea>
						<p class="description" style="margin-bottom:20px;"><?php echo esc_html__('Enter the notice message to display when an order is blocked at checkout.', 'wc-blacklist-manager'); ?></p>
						<?php if ($premium_active): ?>
							<p><textarea id="wc_blacklist_payment_method_notice" name="wc_blacklist_payment_method_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['payment_method_notice']); ?></textarea></p>
							<p class="description"><?php echo esc_html__('Enter the notice message to display when a payment method is not available for a suspected customer.', 'wc-blacklist-manager'); ?></p>
						<?php endif; ?>
						<?php if (!$premium_active): ?>
							<p><textarea rows="3" class="regular-text" disabled><?php echo esc_textarea($data['payment_method_notice']); ?></textarea></p>
							<p class="premium-text"><?php echo esc_html__('Enter the notice message to display when a payment method is not available for a suspected customer.', 'wc-blacklist-manager'); ?><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Upgrade</a></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wc_blacklist_registration_notice"><?php echo esc_html__('Registration notice:', 'wc-blacklist-manager'); ?></label></th>
					<td>
						<p><textarea id="wc_blacklist_registration_notice" name="wc_blacklist_registration_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['registration_notice']); ?></textarea></p>
						<p class="description" style="margin-bottom:20px;"><?php echo esc_html__('Enter the notice message to display when a blocked visitor tries to register an account.', 'wc-blacklist-manager'); ?></p>
						<?php if ($premium_active): ?>
							<p><textarea id="wc_blacklist_vpn_proxy_registration_notice" name="wc_blacklist_vpn_proxy_registration_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['vpn_proxy_registration_notice']); ?></textarea></p>
							<p class="description"><?php echo esc_html__('Enter the notice message to display when a visitor uses Proxy or VPN to register an account.', 'wc-blacklist-manager'); ?></p>
						<?php endif; ?>
						<?php if (!$premium_active): ?>
							<p><textarea rows="3" class="regular-text" disabled><?php echo esc_textarea($data['vpn_proxy_registration_notice']); ?></textarea></p>
							<p class="premium-text"><?php echo esc_html__('Enter the notice message to display when a visitor uses Proxy or VPN to register an account.', 'wc-blacklist-manager'); ?><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Upgrade</a></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wc_blacklist_blocked_user_notice"><?php echo esc_html__('Blocked user notice:', 'wc-blacklist-manager'); ?></label></th>
					<td>
						<textarea id="wc_blacklist_blocked_user_notice" name="wc_blacklist_blocked_user_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['blocked_user_notice']); ?></textarea>
						<p class="description"><?php echo esc_html__('Enter the notice message to display when a blocked user tries to login or force out.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<p class="submit">
			<input type="submit" class="button-primary" value="<?php echo esc_attr__( 'Save Changes', 'wc-blacklist-manager' ); ?>" />
		</p>
	</form>
</div>