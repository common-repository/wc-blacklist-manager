jQuery(document).ready(function($) {
    var resendCooldown = wc_blacklist_manager_verification_data.resendCooldown;  // Use data from localized script
    var resendButtonEnabled = false;

    // Function to disable the "Place order" button
    function disablePlaceOrderButton() {
        $('form.checkout #place_order').prop('disabled', true).addClass('disabled');
    }

    // Function to enable the "Place order" button
    function enablePlaceOrderButton() {
        $('form.checkout #place_order').prop('disabled', false).removeClass('disabled');
    }

    // Function to start the countdown for resend
    function startCountdown() {
        $('#resend_timer').show();
        $('#resend_button').hide();
        var timeLeft = resendCooldown;
        var countdownInterval = setInterval(function() {
            if (timeLeft <= 0) {
                clearInterval(countdownInterval);
                $('#resend_timer').hide();
                $('#resend_button').show();
                resendButtonEnabled = true;
            } else {
                $('#resend_timer').text(wc_blacklist_manager_verification_data.resend_in_label + ' ' + timeLeft + ' ' + wc_blacklist_manager_verification_data.seconds_label);
                timeLeft--;
            }
        }, 1000);
    }

    // Function to check for the notice dynamically after form submission
    function checkForVerificationNotice() {
        if ($('.woocommerce-error li .email-verification-error').length > 0) {
            disablePlaceOrderButton(); // Disable the "Place order" button

            // Append the input field and button for the verification code and resend timer/button
            $('.woocommerce-error li .email-verification-error').append(`
                <div>
                    <input type="text" id="verification_code" name="verification_code" placeholder="${wc_blacklist_manager_verification_data.enter_code_placeholder}" style="max-width: 120px; margin-top: 10px;" />
                    <button type="button" id="submit_verification_code" class="button">${wc_blacklist_manager_verification_data.verify_button_label}</button>
                    <div id="verification_message" style="display:none;"></div>
                    <div id="resend_timer">${wc_blacklist_manager_verification_data.resend_in_label} 60 seconds</div>
                    <button type="button" id="resend_button" class="button" style="display:none;">${wc_blacklist_manager_verification_data.resend_button_label}</button>
                </div>
            `);

            // Start the countdown timer
            startCountdown();

            // Handle the verification code submission
            $('#submit_verification_code').on('click', function() {
                var verificationCode = $('#verification_code').val().trim();  // Trim any spaces

                if (verificationCode === '') {
                    alert(wc_blacklist_manager_verification_data.enter_code_alert);
                    return;
                }

                // Ensure billing fields are pulled from the form when verification is submitted
                var billingDetails = {
                    billing_first_name: $('input[name="billing_first_name"]').val() || '',
                    billing_last_name: $('input[name="billing_last_name"]').val() || '',
                    billing_address_1: $('input[name="billing_address_1"]').val() || '',
                    billing_address_2: $('input[name="billing_address_2"]').val() || '',
                    billing_city: $('input[name="billing_city"]').val() || '',
                    billing_state: $('select[name="billing_state"]').val() || '',
                    billing_postcode: $('input[name="billing_postcode"]').val() || '',
                    billing_country: $('select[name="billing_country"]').val() || '',
                    billing_email: $('input[name="billing_email"]').val() || '',
                    billing_phone: $('input[name="billing_phone"]').val() || ''
                };

                $.ajax({
                    url: wc_blacklist_manager_verification_data.ajax_url,  // Use localized AJAX URL
                    type: 'POST',
                    data: {
                        action: 'verify_email_code',
                        code: verificationCode,
                        ...billingDetails // Pass all billing details to the AJAX request
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#verification_message').text(response.data.message).show();
                            $('.woocommerce-error').hide(); // Hide the error notice after success
                            $('<div class="woocommerce-message">' + response.data.message + '</div>').insertBefore('.woocommerce');
                            enablePlaceOrderButton(); // Re-enable the "Place order" button after success
                        } else {
                            $('#verification_message').text(response.data.message).show();
                        }
                    }
                });
            });

            // Handle resend button click
            $('#resend_button').on('click', function() {
                if (!resendButtonEnabled) return;

                var billingEmail = $('input[name="billing_email"]').val();

                $.ajax({
                    url: wc_blacklist_manager_verification_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'resend_verification_code',
                        billing_email: billingEmail
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#verification_message').text(wc_blacklist_manager_verification_data.code_resent_message).show();
                            startCountdown(); // Restart the countdown timer
                        } else {
                            $('#verification_message').text(wc_blacklist_manager_verification_data.code_resend_failed_message).show();
                        }
                    }
                });
            });
        }
    }

    // Listen for checkout validation errors with updated_checkout event
    $(document.body).on('checkout_error', function() {
        checkForVerificationNotice();
    });

    // Initial check when the page loads
    checkForVerificationNotice();
});
