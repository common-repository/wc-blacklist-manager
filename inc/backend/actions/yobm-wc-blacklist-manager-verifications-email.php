<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Verifications_Verify_Email {

	private $whitelist_table;
	private $blacklist_table;
	private $verification_code_meta_key = '_email_verification_code';
	private $verification_time_meta_key = '_email_verification_time'; // To track when the code was generated
	private $resend_cooldown_seconds = 60; // Resend cooldown period in seconds
	private $verification_expiration_seconds = 300; // Expire the code after 5 minutes (300 seconds)

	public function __construct() {
		global $wpdb;
		$this->whitelist_table = $wpdb->prefix . 'wc_whitelist';
		$this->blacklist_table = $wpdb->prefix . 'wc_blacklist';

		add_action('wp_enqueue_scripts', [$this, 'enqueue_verification_scripts']);
		add_action('woocommerce_checkout_process', [$this, 'email_verification'], 20);
		add_action('wp_ajax_verify_email_code', [$this, 'verify_email_code']);
		add_action('wp_ajax_nopriv_verify_email_code', [$this, 'verify_email_code']);
		add_action('woocommerce_checkout_update_order_meta', [$this, 'add_verified_email_meta_to_order'], 10, 1);
		add_action('wp_ajax_resend_verification_code', [$this, 'resend_verification_code']);
		add_action('wp_ajax_nopriv_resend_verification_code', [$this, 'resend_verification_code']);
		add_action('wc_blacklist_manager_cleanup_verification_code', [$this, 'cleanup_expired_code']); // Scheduled task

		// Ensure WooCommerce session is started
		add_action('init', [$this, 'initialize_session'], 1);
	}

	public function enqueue_verification_scripts() {
		// Check if we're on the checkout page and the option is enabled
		if (is_checkout() && get_option('wc_blacklist_email_verification_enabled') == '1') {
			// Enqueue the external JavaScript file
			wp_enqueue_script(
				'yobm-wc-blacklist-manager-verifications-email',
				plugins_url('/../../../js/yobm-wc-blacklist-manager-verifications-email.js', __FILE__),
				['jquery'], // Dependencies
				'1.0.0',    // Version
				true        // Load in footer
			);
		
			// Localize the script with necessary data
			wp_localize_script('yobm-wc-blacklist-manager-verifications-email', 'wc_blacklist_manager_verification_data', [
				'ajax_url'                  => admin_url('admin-ajax.php'),
				'resendCooldown'            => $this->resend_cooldown_seconds,
				'enter_code_placeholder'    => __('Enter code', 'wc-blacklist-manager'),
				'verify_button_label'       => __('Verify', 'wc-blacklist-manager'),
				'resend_in_label'           => __('Can resend in', 'wc-blacklist-manager'),
				'seconds_label'             => __('seconds', 'wc-blacklist-manager'),
				'resend_button_label'       => __('Resend code', 'wc-blacklist-manager'),
				'enter_code_alert'          => __('Please enter the verification code.', 'wc-blacklist-manager'),
				'code_resent_message'       => __('A new code has been sent to your email.', 'wc-blacklist-manager'),
				'code_resend_failed_message' => __('Failed to resend the code. Please try again.', 'wc-blacklist-manager'),
			]);
		}
	}
	
	// Initialize WooCommerce session if not already started
	public function initialize_session() {
		if (class_exists('WC_Session') && WC()->session) {
			if (!WC()->session->has_session()) {
				WC()->session->set_customer_session_cookie(true);
			}
		}
	}

	// Function to check email verification at checkout
	public function email_verification() {
		// Initialize email verification flag
		$email_verified = false; // Set to false initially

		// Check if we're on the checkout page and the option is enabled
		if (is_checkout() && get_option('wc_blacklist_email_verification_enabled') == '1') {
			$user_id = get_current_user_id();
			
			// Check if the user is logged in and retrieve the email
			if (is_user_logged_in()) {
				$email = wp_get_current_user()->user_email;
			} else {
				$email = sanitize_email($_POST['billing_email']);
			}
	
			// Get the action option (either 'suspect' or 'all')
			$verification_action = get_option('wc_blacklist_email_verification_action');
	
			// If the action is set to 'all', check if the email is not in the whitelist
			if ($verification_action === 'all') {
				if (!$this->is_email_in_whitelist($email)) {
					$this->send_verification_code($email);
					wc_add_notice('<span class="email-verification-error">' . __('Please verify your email before proceeding with the checkout.', 'wc-blacklist-manager') . '</span>', 'error');
				} else {
					$email_verified = true; // Email is already in whitelist, consider it verified
				}
			}

			// If the action is set to 'suspect', check if the email is in the blacklist
			if ($verification_action === 'suspect') {
				if ($this->is_email_in_blacklist($email)) {
					$this->send_verification_code($email);
					wc_add_notice('<span class="email-verification-error">' . __('Please verify your email before proceeding with the checkout.', 'wc-blacklist-manager') . '</span>', 'error');
				}
			}

			// Store email verification status in session or user meta
			if ($user_id === 0) {
				WC()->session->set('_email_verified', $email_verified ? 1 : 0);
			} else {
				update_user_meta($user_id, '_email_verified', $email_verified ? 1 : 0);
			}
		}
	}
	
	// Check if email is in the whitelist and verified_email is set to 1
	private function is_email_in_whitelist($email) {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT * FROM $this->whitelist_table WHERE email = %s AND verified_email = 1", 
			$email
		);

		$result = $wpdb->get_row($query);

		return $result ? true : false;
	}

	// Check if email is in the blacklist and is_blocked is set to 0
	private function is_email_in_blacklist($email) {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT * FROM $this->blacklist_table WHERE email_address = %s AND is_blocked = 0", 
			$email
		);

		$result = $wpdb->get_row($query);

		return $result ? true : false;
	}	

	// Send a verification code to the user's email
	private function send_verification_code($email) {
		$verification_code = wp_rand(100000, 999999);  // Generate a 6-digit verification code
		$timestamp = time();  // Current timestamp
	
		$user_id = get_current_user_id();
		if ($user_id === 0) {
			if (WC()->session) {
				WC()->session->set($this->verification_code_meta_key, $verification_code);
				WC()->session->set($this->verification_time_meta_key, $timestamp);
				WC()->session->set('billing_email', $email);  // Store guest email in session
				WC()->session->save_data();  // Ensure session is saved
			}
		} else {
			// Store code and timestamp in user meta if the user is logged in
			update_user_meta($user_id, $this->verification_code_meta_key, $verification_code);
			update_user_meta($user_id, $this->verification_time_meta_key, $timestamp);
		}
	
		// Schedule cleanup event after expiration time
		wp_schedule_single_event($timestamp + $this->verification_expiration_seconds, 'wc_blacklist_manager_cleanup_verification_code', [$user_id, $email]);
	
		// Send the verification email using WooCommerce's email template
		$this->send_verification_email($email, $verification_code);
	}

	private function send_verification_email($email, $verification_code) {
		// Load WooCommerce mailer
		$mailer = WC()->mailer();
	
		// Define the email subject and content
		$subject = 'Verify your email address';
		$heading = 'Verify your email address';
	
		// Construct the email content
		$message = sprintf(
			'Hi there,<br><br>To complete your checkout process, please verify your email address by entering the following code:<br><br><strong>%s</strong><br><br>If you did not request this, please ignore this email.<br><br>Thank you.',
			esc_html($verification_code)
		);
	
		// Wrap message using WooCommerce email template
		$wrapped_message = $mailer->wrap_message($heading, $message);
	
		// Create a new instance of WC_Email to access its HTML template
		$email_instance = new WC_Email();
	
		// Use the WooCommerce email template and inline styles
		$styled_message = $email_instance->style_inline($wrapped_message);
	
		// Send the email using WooCommerce's built-in mailer
		$mailer->send(
			$email,  // Recipient email address
			$subject,  // Email subject
			$styled_message,  // Message with inline styles
			'Content-Type: text/html; charset=UTF-8'  // Headers
		);
	}	

	// Handle AJAX request to verify the email code
	public function verify_email_code() {
		$submitted_code = isset($_POST['code']) ? sanitize_text_field(trim($_POST['code'])) : '';
		$user_id = get_current_user_id();

		// Retrieve code and timestamp for logged-in or guest users
		if ($user_id === 0) {
			$stored_code = WC()->session->get($this->verification_code_meta_key);
			$stored_time = WC()->session->get($this->verification_time_meta_key);
		} else {
			$stored_code = get_user_meta($user_id, $this->verification_code_meta_key, true);
			$stored_time = get_user_meta($user_id, $this->verification_time_meta_key, true);
		}

		// Check if the code has expired
		if (time() - $stored_time > $this->verification_expiration_seconds) {
			$this->cleanup_expired_code($user_id, '');
			wp_send_json_error(['message' => __('Code expired. Please request a new one.', 'wc-blacklist-manager')]);
			return;
		}

		// Check if the submitted code matches the stored code
		if ($submitted_code == $stored_code) {  // Loose comparison
			$this->cleanup_expired_code($user_id, '');

			$verification_action = get_option('wc_blacklist_email_verification_action');

			// Retrieve billing details from the POST data (not session)
			$billing_details = [
				'first_name'     => sanitize_text_field($_POST['billing_first_name'] ?? ''),
				'last_name'      => sanitize_text_field($_POST['billing_last_name'] ?? ''),
				'address_1'      => sanitize_text_field($_POST['billing_address_1'] ?? ''),
				'address_2'      => sanitize_text_field($_POST['billing_address_2'] ?? ''),
				'city'           => sanitize_text_field($_POST['billing_city'] ?? ''),
				'state'          => sanitize_text_field($_POST['billing_state'] ?? ''),
				'postcode'       => sanitize_text_field($_POST['billing_postcode'] ?? ''),
				'country'        => sanitize_text_field($_POST['billing_country'] ?? ''),
				'email'          => sanitize_email($_POST['billing_email'] ?? ''),
				'verified_email' => 1, // Email is now verified
				'phone'          => sanitize_text_field($_POST['billing_phone'] ?? ''),
				'verified_phone' => 0, // Phone is not yet verified
			];

			// Add billing details to the whitelist
			$this->add_billing_details_to_whitelist($billing_details);

			if ($verification_action === 'suspect') {
				$this->remove_email_address_from_blacklist($billing_details['email']);
			}

			// Return success message in AJAX response
			wp_send_json_success(['message' => __('Your email has been successfully verified!', 'wc-blacklist-manager')]);
		} else {
			wp_send_json_error(['message' => __('Invalid code. Please try again.', 'wc-blacklist-manager')]);
		}
	}

	// Add billing details to the whitelist when the email is successfully verified
	private function add_billing_details_to_whitelist($billing_details) {
		global $wpdb;
	
		// Extract email and phone from billing details
		$email = $billing_details['email'];
		$phone = $billing_details['phone'];
	
		// If email is not provided, exit the function
		if (empty($email)) {
			return;
		}
	
		// Check if the phone exists in the whitelist table
		$existing_phone_entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->whitelist_table WHERE phone = %s", $phone));
	
		// If phone exists, remove 'phone' and 'verified_phone' from the billing details
		if ($existing_phone_entry) {
			unset($billing_details['phone']);
			unset($billing_details['verified_phone']);
		}
	
		// Check if the email exists in the whitelist table
		$existing_email_entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->whitelist_table WHERE email = %s", $email));
	
		// If email exists, update the existing entry with the remaining billing details
		if ($existing_email_entry) {
			$wpdb->update(
				$this->whitelist_table,
				$billing_details,
				['email' => $email]
			);
		} else {
			// If email does not exist, insert a new record with the remaining billing details
			$wpdb->insert($this->whitelist_table, $billing_details);
		}
	}

	// Add verified email meta to the order
	public function add_verified_email_meta_to_order($order_id) {
		$order = wc_get_order($order_id);  // Ensure we are working with a valid order object
	
		if (!$order) {
			return;
		}
	
		$user_id = get_current_user_id();
		$email_verified = false;
	
		// Check email verification status for guests and logged-in users
		if ($user_id === 0) {
			$email_verified = WC()->session->get('_email_verified', 0); // Check session for guests
		} else {
			$email_verified = get_user_meta($user_id, '_email_verified', true); // Check user meta for logged-in users
		}
	
		// If the email was verified, add the order meta
		if ($email_verified) {
			$order->update_meta_data('_verified_email', 1);  // Use WooCommerce method to add meta
			$order->save();  // Make sure to save the order after updating meta
	
			// Optionally clear the verification status after adding it to the order
			if ($user_id === 0) {
				WC()->session->__unset('_email_verified'); // Clear session data for guests
			} else {
				delete_user_meta($user_id, '_email_verified'); // Clear user meta for logged-in users
			}
		} else {

		}
	}	
	
	// Remove the email from the suspect list
	private function remove_email_address_from_blacklist($email) {
		global $wpdb;
	
		// Assuming the blacklist table is named 'wc_blacklist' and the email field is 'email'
		$blacklist_table = $wpdb->prefix . 'wc_blacklist';
	
		// Update the row by setting the email field to an empty value where the email matches
		$wpdb->update(
			$blacklist_table,
			['email_address' => ''],  // Set the email to an empty string
			['email_address' => $email]  // Condition: where the email matches
		);
	}
	
	// Handle AJAX request to resend the verification code
	public function resend_verification_code() {
		$user_id = get_current_user_id();
		$email = '';
	
		if ($user_id === 0) {
			// For guests, retrieve the email from the session
			$email = WC()->session->get('billing_email');
		} else {
			// For logged-in users, get the email from the user data
			$email = wp_get_current_user()->user_email;
		}
	
		// If no email is found (in case session data is missing), return an error response
		if (empty($email)) {
			wp_send_json_error(['message' => __('Unable to resend the verification code. Email not found.', 'wc-blacklist-manager')]);
			return;
		}
	
		// Cleanup previous code and resend a new verification code
		$this->cleanup_expired_code($user_id, $email);
		$this->send_verification_code($email);
		wp_send_json_success();
	}
	
	// Clean up expired or used verification codes
	public function cleanup_expired_code($user_id, $email = '') {
		if ($user_id === 0) {
			$this->initialize_session();
			if (WC()->session) {
				WC()->session->__unset($this->verification_code_meta_key);
				WC()->session->__unset($this->verification_time_meta_key);
			}
		} else {
			delete_user_meta($user_id, $this->verification_code_meta_key);
			delete_user_meta($user_id, $this->verification_time_meta_key);
		}
	}
}

new WC_Blacklist_Manager_Verifications_Verify_Email();
