<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Verifications_Verify_Phone {

	private $whitelist_table;
	private $blacklist_table;
	private $verification_code_meta_key = '_phone_verification_code';
	private $verification_time_meta_key = '_phone_verification_time'; // To track when the code was generated
	private $resend_count_meta_key = '_phone_verification_resend_count'; // To track the number of resends
	private $resend_cooldown_seconds; // Resend cooldown period in seconds
	private $resend_limit; // Max number of allowed resends
	private $verification_expiration_seconds = 300; // Expire the code after 5 minutes (300 seconds)

	public function __construct() {
		global $wpdb;
		$this->whitelist_table = $wpdb->prefix . 'wc_whitelist';
		$this->blacklist_table = $wpdb->prefix . 'wc_blacklist';

		// Retrieve the settings from the 'wc_blacklist_phone_verification' option
		$verification_settings = get_option('wc_blacklist_phone_verification', [
			'resend' => 60, // Default value for resend cooldown
			'limit'  => 5,  // Default resend limit
		]);

		// Set the resend cooldown period and limit
		$this->resend_cooldown_seconds = isset($verification_settings['resend']) ? (int) $verification_settings['resend'] : 60;
		$this->resend_limit = isset($verification_settings['limit']) ? (int) $verification_settings['limit'] : 5;

		add_action('wp_enqueue_scripts', [$this, 'enqueue_verification_scripts']);
		add_action('woocommerce_checkout_process', [$this, 'phone_verification'], 20);
		add_action('wp_ajax_verify_phone_code', [$this, 'verify_phone_code']);
		add_action('wp_ajax_nopriv_verify_phone_code', [$this, 'verify_phone_code']);
		add_action('woocommerce_checkout_update_order_meta', [$this, 'add_verified_phone_meta_to_order'], 10, 1);
		add_action('wp_ajax_resend_phone_verification_code', [$this, 'resend_verification_code']);
		add_action('wp_ajax_nopriv_resend_phone_verification_code', [$this, 'resend_verification_code']);
		add_action('wc_blacklist_manager_cleanup_verification_code', [$this, 'cleanup_expired_code']); // Scheduled task

		// Ensure WooCommerce session is started
		add_action('init', [$this, 'initialize_session'], 1);
	}

	public function enqueue_verification_scripts() {
		// Check if we're on the checkout page and the option is enabled
		if (is_checkout() && get_option('wc_blacklist_phone_verification_enabled') == '1') {
			// Enqueue the external JavaScript file
			wp_enqueue_script(
				'yobm-wc-blacklist-manager-verifications-phone',
				plugins_url('/../../../js/yobm-wc-blacklist-manager-verifications-phone.js', __FILE__),
				['jquery'], // Dependencies
				'1.0.0',    // Version
				true        // Load in footer
			);
		
			// Localize the script with necessary data
			wp_localize_script('yobm-wc-blacklist-manager-verifications-phone', 'wc_blacklist_manager_verification_data', [
				'ajax_url'                  => admin_url('admin-ajax.php'),
				'resendCooldown'            => $this->resend_cooldown_seconds,
				'resendLimit'               => $this->resend_limit,  // Use the limit value from the option
				'enter_code_placeholder'    => __('Enter code', 'wc-blacklist-manager'),
				'verify_button_label'       => __('Verify', 'wc-blacklist-manager'),
				'resend_in_label'           => __('Can resend in', 'wc-blacklist-manager'),
				'seconds_label'             => __('seconds', 'wc-blacklist-manager'),
				'resend_button_label'       => __('Resend code', 'wc-blacklist-manager'),
				'enter_code_alert'          => __('Please enter the verification code.', 'wc-blacklist-manager'),
				'code_resent_message'       => __('A new code has been sent to your phone.', 'wc-blacklist-manager'),
				'code_resend_failed_message' => __('Failed to resend the code. Please try again.', 'wc-blacklist-manager'),
				'resend_limit_reached_message' => __('You have reached the resend limit. Please contact support.', 'wc-blacklist-manager'),
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

	// Function to check phone verification at checkout
	public function phone_verification() {
		// Initialize phone verification flag
		$phone_verified = false; // Set to false initially

		// Check if we're on the checkout page and the option is enabled
		if (is_checkout() && get_option('wc_blacklist_phone_verification_enabled') == '1') {
			$user_id = get_current_user_id();
			
			// Check if the user is logged in and retrieve the phone number
			if (is_user_logged_in()) {
				$phone = get_user_meta($user_id, 'billing_phone', true);
			} else {
				$phone = sanitize_text_field($_POST['billing_phone']);
			}
	
			// Get the action option (either 'suspect' or 'all')
			$verification_action = get_option('wc_blacklist_phone_verification_action');
			
			// Get the resend count (for tracking if the limit is reached)
			if ($user_id === 0) {
				$resend_count = WC()->session->get($this->resend_count_meta_key, 0);
			} else {
				$resend_count = get_user_meta($user_id, $this->resend_count_meta_key, true) ?: 0;
			}
	
			// Check if the resend limit has been reached
			if ($resend_count >= $this->resend_limit) {
				wc_add_notice(__('You have reached the phone verification limit. Please contact support.', 'wc-blacklist-manager'), 'error');
				return; // Stop further verification
			}
	
			// If the action is set to 'all', check if the phone is not in the whitelist
			if ($verification_action === 'all') {
				if (!$this->is_phone_in_whitelist($phone)) {
					$this->send_verification_code($phone);
					wc_add_notice('<span class="phone-verification-error">' . __('Please verify your phone number before proceeding with the checkout.', 'wc-blacklist-manager') . '</span>', 'error');
				} else {
					$phone_verified = true; // Phone is already in whitelist, consider it verified
				}
			}
	
			// If the action is set to 'suspect', check if the phone is in the blacklist
			if ($verification_action === 'suspect') {
				if ($this->is_phone_in_blacklist($phone)) {
					$this->send_verification_code($phone);
					wc_add_notice('<span class="phone-verification-error">' . __('Please verify your phone number before proceeding with the checkout.', 'wc-blacklist-manager') . '</span>', 'error');
				}
			}

			// Store phone verification status in session or user meta
			if ($user_id === 0) {
				WC()->session->set('_phone_verified', $phone_verified ? 1 : 0);
			} else {
				update_user_meta($user_id, '_phone_verified', $phone_verified ? 1 : 0);
			}
		}
	}
	
	// Check if phone is in the whitelist
	private function is_phone_in_whitelist($phone) {
		global $wpdb;
		$query = $wpdb->prepare(
			"SELECT * FROM $this->whitelist_table WHERE phone = %s AND verified_phone = 1", 
			$phone
		);
		$result = $wpdb->get_row($query);
		return $result ? true : false;
	}

	// Check if phone is in the blacklist
	private function is_phone_in_blacklist($phone) {
		global $wpdb;
		$query = $wpdb->prepare(
			"SELECT * FROM $this->blacklist_table WHERE phone_number = %s AND is_blocked = 0", 
			$phone
		);
		$result = $wpdb->get_row($query);
		return $result ? true : false;
	}

	// Send a verification code to the user's phone
	private function send_verification_code($phone) {
		// Retrieve the verification settings from the 'wc_blacklist_phone_verification' option
		$verification_settings = get_option('wc_blacklist_phone_verification', [
			'code_length' => 6  // Default code length
		]);
	
		// Get the code length from the settings, ensuring it's between 4 and 10
		$code_length = max(4, min(10, (int) $verification_settings['code_length']));
	
		// Generate a random verification code with the specified length
		$verification_code = wp_rand(pow(10, $code_length - 1), pow(10, $code_length) - 1);
	
		$timestamp = time();  // Current timestamp
	
		$user_id = get_current_user_id();
		if ($user_id === 0) {
			if (WC()->session) {
				WC()->session->set($this->verification_code_meta_key, $verification_code);
				WC()->session->set($this->verification_time_meta_key, $timestamp);
				WC()->session->set('billing_phone', $phone);  // Store guest phone in session
				WC()->session->save_data();  // Ensure session is saved
			}
		} else {
			// Store code and timestamp in user meta if the user is logged in
			update_user_meta($user_id, $this->verification_code_meta_key, $verification_code);
			update_user_meta($user_id, $this->verification_time_meta_key, $timestamp);
		}
	
		// Schedule cleanup event after expiration time
		wp_schedule_single_event($timestamp + $this->verification_expiration_seconds, 'wc_blacklist_manager_cleanup_verification_code', [$user_id, $phone]);
	
		// Send the verification SMS
		$this->send_verification_sms($phone, $verification_code);
	}

	// Send SMS via an external provider (e.g., Twilio)
	private function send_verification_sms( $phone, $verification_code ) {
		// Get the message template from the 'wc_blacklist_phone_verification' option
		$verification_settings = get_option( 'wc_blacklist_phone_verification', array() );
	
		// Get the SMS key from the 'yoohw_phone_verification_sms_key' option
		$sms_key = get_option( 'yoohw_phone_verification_sms_key', '' );
	
		// Set a default message in case it's missing in the option
		$message_template = isset( $verification_settings['message'] ) ? $verification_settings['message'] : '{site_name}: Your verification code is {code}';
	
		// Replace placeholders {site_name} and {code} with actual values
		$message = str_replace(
			array( '{site_name}', '{code}' ),
			array( get_bloginfo( 'name' ), $verification_code ),
			$message_template
		);

		// Log the message content
		error_log( 'Message content: ' . $message );
	
		// Normalize phone number by adding country code if it's missing
		$phone = $this->normalize_phone_number_with_country_code( $phone );
	
		// Prepare the data to be sent to your site's API
		$data = array(
			'sms_key'  => $sms_key,             // Use the SMS key from the option
			'domain'   => home_url(),           // Use the current site URL as the domain
			'phone'    => $phone,               // The phone number to send the SMS
			'message'  => $message,             // The message with the verification code
		);
	
		// Send the POST request to the API route on your site
		$response = wp_remote_post( 'https://bmc.yoohw.com/wp-json/sms/v1/send-sms/', array(
			'body'    => json_encode( $data ),
			'headers' => array(
				'Content-Type' => 'application/json',
			),
		));
	
		// Check for errors in the response
		if ( is_wp_error( $response ) ) {
			
		} else {
			// Log the success message
			$response_body = wp_remote_retrieve_body( $response );
		}
	}    

	private function normalize_phone_number_with_country_code( $phone ) {
		// If the phone already contains a country code, return it as is
		if (substr($phone, 0, 1) === '+') {
			return $phone;
		}

		// Get the billing country (you can modify this to get the correct source of the billing country)
		$billing_country = is_user_logged_in() ? get_user_meta( get_current_user_id(), 'billing_country', true ) : sanitize_text_field( $_POST['billing_country'] );

		// Get the country code from the file
		$country_code = $this->get_country_code_from_file( $billing_country );

		// Prepend the country code to the phone number
		return '+' . $country_code . $phone;
	}

	private function get_country_code_from_file( $billing_country ) {
		$file_path = plugin_dir_path( __FILE__ ) . 'data/phone_country_codes.conf';

		if (file_exists($file_path)) {
			// Read the file contents
			$file_content = file_get_contents($file_path);

			// Parse the file contents
			$lines = explode("\n", $file_content);
			foreach ($lines as $line) {
				if (strpos($line, ':') !== false) {
					list($country, $code) = explode(':', $line);
					if (trim($country) === $billing_country) {
						return trim($code);  // Return the country code
					}
				}
			}
		}

		// Return null if no matching country code is found
		return null;
	}

	// Handle AJAX request to verify the phone code
	public function verify_phone_code() {
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
	if ($submitted_code == $stored_code) {
		$this->cleanup_expired_code($user_id, '');

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
			'verified_email' => 0, // Email is now verified
			'phone'          => sanitize_text_field($_POST['billing_phone'] ?? ''),
			'verified_phone' => 1, // Phone is not yet verified
		];

		// Add billing details to the whitelist
		$this->add_billing_details_to_whitelist($billing_details);

		// Return success message in AJAX response
		wp_send_json_success(['message' => __('Your phone number has been successfully verified!', 'wc-blacklist-manager')]);
	} else {
		wp_send_json_error(['message' => __('Invalid code. Please try again.', 'wc-blacklist-manager')]);
	}
	}

	// Add billing details to the whitelist when the phone is successfully verified
	private function add_billing_details_to_whitelist($billing_details) {
		global $wpdb;

		// Extract email and phone from billing details
		$email = $billing_details['email'];
		$phone = $billing_details['phone'];

		// If phone is not provided, exit the function
		if (empty($phone)) {
			return;
		}

		// Check if the email exists in the whitelist table
		$existing_email_entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->whitelist_table WHERE email = %s", $email));
	
		// If email exists, remove 'email' and 'verified_email' from the billing details
		if ($existing_email_entry) {
			unset($billing_details['email']);
			unset($billing_details['verified_email']);
		}

		// Check if the phone exists in the whitelist table
		$existing_phone_entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->whitelist_table WHERE phone = %s", $phone));

		// If phone exists, update the existing entry
		if ($existing_phone_entry) {
			$wpdb->update(
				$this->whitelist_table,
				$billing_details,
				['phone' => $phone]
			);
		} else {
			// If phone does not exist, insert a new record
			$wpdb->insert($this->whitelist_table, $billing_details);
		}
	}

	// Add verified phone meta to the order
	public function add_verified_phone_meta_to_order($order_id) {
		$order = wc_get_order($order_id);  // Ensure we are working with a valid order object
	
		if (!$order) {
			return;
		}
	
		$user_id = get_current_user_id();
		$phone_verified = false;
	
		// Check phone verification status for guests and logged-in users
		if ($user_id === 0) {
			$phone_verified = WC()->session->get('_phone_verified', 0); // Check session for guests
		} else {
			$phone_verified = get_user_meta($user_id, '_phone_verified', true); // Check user meta for logged-in users
		}
	
		// If the phone was verified, add the order meta
		if ($phone_verified) {
			$order->update_meta_data('_verified_phone', 1);  // Use WooCommerce method to add meta
			$order->save();  // Make sure to save the order after updating meta
	
			// Optionally clear the verification status after adding it to the order
			if ($user_id === 0) {
				WC()->session->__unset('_phone_verified'); // Clear session data for guests
			} else {
				delete_user_meta($user_id, '_phone_verified'); // Clear user meta for logged-in users
			}
		} else {

		}
	}
	
	// Handle AJAX request to resend the verification code
	public function resend_verification_code() {
		$user_id = get_current_user_id();
		$email = '';

		// Get the resend count for this user or session
		if ($user_id === 0) {
			$resend_count = WC()->session->get($this->resend_count_meta_key, 0);
		} else {
			$resend_count = get_user_meta($user_id, $this->resend_count_meta_key, true) ?: 0;
		}

		// Check if the resend limit has been reached
		if ($resend_count >= $this->resend_limit) {
			wp_send_json_error(['message' => __('You have reached the resend limit. Please contact support.', 'wc-blacklist-manager')]);
			return;
		}

		if ($user_id === 0) {
			$phone = WC()->session->get('billing_phone');
		} else {
			$phone = get_user_meta($user_id, 'billing_phone', true);
		}

		if (empty($phone)) {
			wp_send_json_error(['message' => __('Unable to resend the verification code. Phone number not found.', 'wc-blacklist-manager')]);
			return;
		}

		// Cleanup previous code and resend a new verification code
		$this->cleanup_expired_code($user_id, $phone);
		$this->send_verification_code($phone);

		// Increment the resend count
		if ($user_id === 0) {
			WC()->session->set($this->resend_count_meta_key, ++$resend_count);
		} else {
			update_user_meta($user_id, $this->resend_count_meta_key, ++$resend_count);
		}

		wp_send_json_success();
	}

	// Clean up expired or used verification codes
	public function cleanup_expired_code($user_id, $phone = '') {
		if ($user_id === 0) {
			$this->initialize_session();
			if (WC()->session) {
				WC()->session->__unset($this->verification_code_meta_key);
				WC()->session->__unset($this->verification_time_meta_key);
				WC()->session->__unset($this->resend_count_meta_key);  // Reset resend count
			}
		} else {
			delete_user_meta($user_id, $this->verification_code_meta_key);
			delete_user_meta($user_id, $this->verification_time_meta_key);
			delete_user_meta($user_id, $this->resend_count_meta_key); // Reset resend count
		}
	}
}

new WC_Blacklist_Manager_Verifications_Verify_Phone();
