<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Main WooCommerce SimpleSo Payment Gateway class.
 */
class SIMPLESO_PAYMENT_GATEWAY extends WC_Payment_Gateway_CC
{
	const ID = 'simpleso';

	// Define constants for SIP URLs
	const SIP_HOST = 'www.simpleso.io'; // Live SIP host 

	private $sip_protocol; // Protocol (http:// or https://)

	protected $sandbox;

	private $public_key;
	private $secret_key;
	private $sandbox_secret_key;
	private $sandbox_public_key;

	private $admin_notices;
	private $disable_gateway = false; // Flag to disable the gateway

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		// Check if WooCommerce is active
		if (!class_exists('WC_Payment_Gateway_CC')) {
			add_action('admin_notices', array($this, 'woocommerce_not_active_notice'));
			return;
		}

		// Instantiate the notices class
		$this->admin_notices = new SIMPLESO_PAYMENT_GATEWAY_Admin_Notices();

		// Determine SIP protocol based on site protocol
		$this->sip_protocol = (is_ssl() ? 'https://' : 'http://'); // Use HTTPS if SSL is enabled, otherwise HTTP

		// Define user set variables
		$this->id = self::ID;
		$this->icon = ''; // Define an icon URL if needed.
		$this->method_title = __('SimpleSo Payment Gateway', 'simpleso-payment-gateway');
		$this->method_description = __('This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security.', 'simpleso-payment-gateway');

		// Load the settings
		$this->simpleso_init_form_fields();
		$this->init_settings();

		// Define properties
		$this->title = sanitize_text_field($this->get_option('title'));
		$this->description = !empty($this->get_option('description')) ? sanitize_textarea_field($this->get_option('description')) : ($this->get_option('show_consent_checkbox') === 'yes' ? 1 : 0);
		$this->enabled = sanitize_text_field($this->get_option('enabled'));
		$this->sandbox = 'yes' === sanitize_text_field($this->get_option('sandbox')); // Use boolean
		$this->public_key                 = $this->sandbox === 'no' ? sanitize_text_field($this->get_option('public_key')) : sanitize_text_field($this->get_option('sandbox_public_key'));
		$this->secret_key                = $this->sandbox === 'no' ? sanitize_text_field($this->get_option('secret_key')) : sanitize_text_field($this->get_option('sandbox_secret_key'));

		// Define hooks and actions.
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'simpleso_process_admin_options'));

		// Enqueue styles and scripts
		add_action('wp_enqueue_scripts', array($this, 'simpleso_enqueue_styles_and_scripts'));

		add_action('admin_enqueue_scripts', array($this, 'simpleso_admin_scripts'));

		// Add action to display test order tag in order details
		add_action('woocommerce_admin_order_data_after_order_details', array($this, 'simpleso_display_test_order_tag'));

		// Hook into WooCommerce to add a custom label to order rows
		add_filter('woocommerce_admin_order_preview_line_items', array($this, 'simpleso_add_custom_label_to_order_row'), 10, 2);

		// Add filter to disable the gateway if needed
		add_filter('woocommerce_available_payment_gateways', array($this, 'maybe_disable_gateway'));
	}

	public function simpleso_process_admin_options()
	{
		parent::process_admin_options();

		// Retrieve the options from the settings
		$title = sanitize_text_field($this->get_option('title'));
		// Check if sandbox mode is enabled and sanitize the option
		$is_sandbox = sanitize_text_field($this->get_option('sandbox')) === 'yes';

		$secret_key = $is_sandbox ? sanitize_key($this->get_option('sandbox_secret_key')) : sanitize_key($this->get_option('secret_key'));
		$public_key = $is_sandbox ? sanitize_key($this->get_option('sandbox_public_key')) : sanitize_key($this->get_option('public_key'));

		// Initialize error tracking
		$errors = array();

		// Check for Title
		if (empty($title)) {
			$errors[] = __('Title is required. Please enter a title in the settings.', 'simpleso-payment-gateway');
		}

		// Check for Public Key
		if (empty($public_key)) {
			$errors[] = __('Public Key is required. Please enter your Public Key in the settings.', 'simpleso-payment-gateway');
		}

		// Check for Secret Key
		if (empty($secret_key)) {
			$errors[] = __('Secret Key is required. Please enter your Secret Key in the settings.', 'simpleso-payment-gateway');
		}

		// Check API Keys only if there are no other errors
		if (empty($errors)) {
			$api_key_error = $this->simpleso_check_api_keys();
			if ($api_key_error) {
				$errors[] = $api_key_error;
			}
		}

		// Display all errors
		if (!empty($errors)) {
			foreach ($errors as $error) {
				$this->admin_notices->simpleso_add_notice('settings_error', 'error', $error);
			}
			add_action('admin_notices', array($this->admin_notices, 'display_notices'));
		}
	}

	/**
	 * Initialize gateway settings form fields.
	 */
	public function simpleso_init_form_fields()
	{
		$this->form_fields = $this->simpleso_get_form_fields();
	}

	/**
	 * Get form fields.
	 */
	public function simpleso_get_form_fields()
	{
		$form_fields = array(
			'enabled' => array(
				'title' => __('Enable/Disable', 'simpleso-payment-gateway'),
				'label' => __('Enable SimpleSo Payment Gateway', 'simpleso-payment-gateway'),
				'type' => 'checkbox',
				'description' => '',
				'default' => 'no',
			),
			'title' => array(
				'title' => __('Title', 'simpleso-payment-gateway'),
				'type' => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'simpleso-payment-gateway'),
				'default' => __('Credit/Debit Card', 'simpleso-payment-gateway'),
				'desc_tip' => __('Enter the title of the payment gateway as it will appear to customers during checkout.', 'simpleso-payment-gateway'),
			),
			'description' => array(
				'title' => __('Description', 'simpleso-payment-gateway'),
				'type' => 'text',
				'description' => __('Provide a brief description of the SimpleSo Payment Gateway option.', 'simpleso-payment-gateway'),
				'default' => 'Description of the SimpleSo Payment Gateway Option.',
				'desc_tip' => __('Enter a brief description that explains the SimpleSo Payment Gateway option.', 'simpleso-payment-gateway'),
			),
			'instructions' => array(
				'title' => __('Instructions', 'simpleso-payment-gateway'),
				'type' => 'title',
				// Translators comment added here
				/* translators: 1: Link to developer account */
				'description' => sprintf(
					/* translators: %1$s is a link to the developer account. %2$s is used for any additional formatting if necessary. */
					__('To configure this gateway, %1$sGet your API keys from your merchant account: Developer Settings > API Keys.%2$s', 'simpleso-payment-gateway'),
					'<strong><a class="simpleso-instructions-url" href="' . esc_url(self::SIP_HOST . '/developers') . '" target="_blank">' . __('click here to access your developer account', 'simpleso-payment-gateway') . '</a></strong><br>',
					''
				),
				'desc_tip' => true,
			),
			'sandbox' => array(
				'title'       => __('Sandbox', 'simpleso-payment-gateway'),
				'label'       => __('Enable Sandbox Mode', 'simpleso-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('Place the payment gateway in sandbox mode using sandbox API keys (real payments will not be taken).', 'simpleso-payment-gateway'),
				'default'     => 'no',
			),
			'sandbox_public_key'  => array(
				'title'       => __('Sandbox Public Key', 'simpleso-payment-gateway'),
				'type'        => 'text',
				'description' => __('Get your API keys from your merchant account: Account Settings > API Keys.', 'simpleso-payment-gateway'),
				'default'     => '',
				'desc_tip'    => true,
				'class'       => 'simpleso-sandbox-keys', // Add class for JS handling
			),
			'sandbox_secret_key' => array(
				'title'       => __('Sandbox Private Key', 'simpleso-payment-gateway'),
				'type'        => 'text',
				'description' => __('Get your API keys from your merchant account: Account Settings > API Keys.', 'simpleso-payment-gateway'),
				'default'     => '',
				'desc_tip'    => true,
				'class'       => 'simpleso-sandbox-keys', // Add class for JS handling
			),
			'public_key' => array(
				'title' => __('Public Key', 'simpleso-payment-gateway'),
				'type' => 'text',
				'default' => '',
				'desc_tip' => __('Enter your Public Key obtained from your merchant account.', 'simpleso-payment-gateway'),
				'class'       => 'simpleso-production-keys', // Add class for JS handling
			),
			'secret_key' => array(
				'title' => __('Secret Key', 'simpleso-payment-gateway'),
				'type' => 'text',
				'default' => '',
				'desc_tip' => __('Enter your Secret Key obtained from your merchant account.', 'simpleso-payment-gateway'),
				'class'       => 'simpleso-production-keys', // Add class for JS handling
			),
			'order_status' => array(
				'title' => __('Order Status', 'simpleso-payment-gateway'),
				'type' => 'select',
				'description' => __('Select the order status to be set after successful payment.', 'simpleso-payment-gateway'),
				'default' => '', // Default is empty, which is our placeholder
				'desc_tip' => true,
				'id' => 'order_status_select', // Add an ID for targeting
				'options' => array(
					// '' => __('Select order status', 'simpleso-payment-gateway'), // Placeholder option
					'processing' => __('Processing', 'simpleso-payment-gateway'),
					'completed' => __('Completed', 'simpleso-payment-gateway'),
				),
			),
			'show_consent_checkbox' => array(
				'title' => __('Show Consent Checkbox', 'simpleso-payment-gateway'),
				'label' => __('Enable consent checkbox on checkout page', 'simpleso-payment-gateway'),
				'type' => 'checkbox',
				'description' => __('Check this box to show the consent checkbox on the checkout page. Uncheck to hide it.', 'simpleso-payment-gateway'),
				'default' => 'yes',
			),
		);

		return apply_filters('woocommerce_gateway_settings_fields_' . $this->id, $form_fields, $this);
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment($order_id)
	{
		global $woocommerce;

		// Validate and sanitize order ID
		$order = wc_get_order($order_id);
		if (!$order) {
			wc_add_notice(__('Invalid order. Please try again.', 'simpleso-payment-gateway'), 'error');
			return;
		}

		// Check if sandbox mode is enabled
		if ($this->sandbox) {
			// Add a meta field to mark this order as a test order
			$order->update_meta_data('_is_test_order', true);
			$order->add_order_note(__('This is a test order in sandbox mode.', 'simpleso-payment-gateway'));
		}
		//amount, usermode, user
		// Prepare data for the API request
		$data = $this->simpleso_prepare_payment_data($order);

		$transaction_dailylimit = '/api/dailylimit';

		// Concatenate the base URL and path
		$transactionLimitApiUrl = $this->sip_protocol . self::SIP_HOST . $transaction_dailylimit;

		// Send the data to the API
		$transaction_limit_response = wp_remote_post($transactionLimitApiUrl, array(
			'method'    => 'POST',
			'timeout'   => 30,
			'body'      => $data,
			'headers'   => array(
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Bearer ' . sanitize_text_field($data['api_public_key']),
			),
			'sslverify' => true, // Ensure SSL verification
		));

		$transaction_limit_response_body = wp_remote_retrieve_body($transaction_limit_response);

		$transaction_limit_response_data = json_decode($transaction_limit_response_body, true);

		if (isset($transaction_limit_response_data['error'])) {
			// Display error message to the user
			wc_add_notice(
				__('Payment error: ', 'simpleso-payment-gateway') . "SimpleSo payment method is currently unavailable. Please contact support for assistance.",
				'error'
			);

			// Set the flag to disable the gateway
			$this->disable_gateway = true;

			return array('result' => 'fail');
		}

		$apiPath = '/api/request-payment';

		// Concatenate the base URL and path
		$url = $this->sip_protocol . self::SIP_HOST . $apiPath;

		// Remove any double slashes in the URL except for the 'http://' or 'https://'
		$cleanUrl = esc_url(preg_replace('#(?<!:)//+#', '/', $url));

		$order->update_meta_data('_order_origin', 'simpleso_payment_gateway');
		$order->save();

		// Send the data to the API
		$response = wp_remote_post($cleanUrl, array(
			'method'    => 'POST',
			'timeout'   => 30,
			'body'      => $data,
			'headers'   => array(
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Bearer ' . sanitize_text_field($data['api_public_key']),
			),
			'sslverify' => true, // Ensure SSL verification
		));


		// Log the essential response data
		if (is_wp_error($response)) {
			// Log the error message
			wc_get_logger()->error('SimpleSo Payment Request Error: ' . $response->get_error_message(), array('source' => 'simpleso_payment_gateway'));
			wc_add_notice(__('Payment error: Unable to process payment.', 'simpleso-payment-gateway') . ' ' . $response->get_error_message(), 'error');
			return array('result' => 'fail');
		} else {
			$response_code = wp_remote_retrieve_response_code($response);
			$response_body = wp_remote_retrieve_body($response);

			// Log the response code and body
			wc_get_logger()->info(
				sprintf('SimpleSo Payment Response: Code: %d, Body: %s', $response_code, $response_body),
				array('source' => 'simpleso_payment_gateway')
			);
		}
		$response_data = json_decode($response_body, true);

		if (
			isset($response_data['status']) && $response_data['status'] === 'success' &&
			isset($response_data['data']['payment_link']) && !empty($response_data['data']['payment_link'])
		) {
			// Update the order status
			$order->update_status('pending', __('Payment pending.', 'simpleso-payment-gateway'));

			// Check if the note already exists
			$existing_notes = $order->get_customer_order_notes();
			$new_note = __('Payment initiated via SimpleSo Payment Gateway. Awaiting customer action.', 'simpleso-payment-gateway');
			$note_exists = false;

			foreach ($existing_notes as $note) {
				if (trim(wp_strip_all_tags($note->comment_content)) === trim($new_note)) {
					$note_exists = true;
					break;
				}
			}

			// Add the note if it doesn't exist
			if (!$note_exists) {
				$order->add_order_note(
					__('Payment initiated via SimpleSo Payment Gateway. Awaiting customer action.', 'simpleso-payment-gateway'),
					__('Payment initiated via SimpleSo Payment Gateway. Awaiting customer action.', 'simpleso-payment-gateway'),
					__('Payment initiated via SimpleSo Payment Gateway. Awaiting customer action.', 'simpleso-payment-gateway'),
					false // The second parameter `false` ensures the note is not visible to the customer.
				);
			}

			// Return a success result without redirecting
			return array(
				'payment_link' => esc_url($response_data['data']['payment_link']),
				'result'   => 'success',
			);
		} else {
			// Handle API error response
			if (isset($response_data['status']) && $response_data['status'] === 'error') {
				// Initialize an error message
				$error_message = isset($response_data['message']) ? sanitize_text_field($response_data['message']) : __('Unable to retrieve payment link.', 'simpleso-payment-gateway');

				// Check if there are validation errors and handle them
				if (isset($response_data['errors']) && is_array($response_data['errors'])) {
					// Loop through the errors and format them into a user-friendly message
					foreach ($response_data['errors'] as $field => $field_errors) {
						foreach ($field_errors as $error) {
							// Append only the error message without the field name
							$error_message .= ' : ' . sanitize_text_field($error);
						}
					}
				}

				// Add the error message to WooCommerce notices
				wc_add_notice(__('Payment error: ', 'simpleso-payment-gateway') . $error_message, 'error');

				return array('result' => 'fail');
			} else {
				// Add the error message to WooCommerce notices
				wc_add_notice(__('Payment error: ', 'simpleso-payment-gateway') . $response_data['error'], 'error');
				return array('result' => 'fail');
			}
		}
	}

	// Display the "Test Order" tag in admin order details
	public function simpleso_display_test_order_tag($order)
	{
		if (get_post_meta($order->get_id(), '_is_test_order', true)) {
			echo '<p><strong>' . esc_html__('Test Order', 'simpleso-payment-gateway') . '</strong></p>';
		}
	}

	private function simpleso_check_api_keys()
	{
		// Check if sandbox mode is enabled
		$is_sandbox = $this->get_option('sandbox') === 'yes';

		$secret_key = $is_sandbox ? sanitize_text_field($this->get_option('sandbox_secret_key')) : sanitize_text_field($this->get_option('secret_key'));
		$public_key = $is_sandbox ? sanitize_text_field($this->get_option('sandbox_public_key')) : sanitize_text_field($this->get_option('public_key'));

		// This method should only be called if no other errors exist
		if (empty($public_key) && empty($secret_key)) {
			return __('Both Public Key and Secret Key are required. Please enter them in the settings.', 'simpleso-payment-gateway');
		} elseif (empty($public_key)) {
			return __('Public Key is required. Please enter your Public Key in the settings.', 'simpleso-payment-gateway');
		} elseif (empty($secret_key)) {
			return __('Secret Key is required. Please enter your Secret Key in the settings.', 'simpleso-payment-gateway');
		}
		return '';
	}


	private function simpleso_get_return_url_base()
	{
		return rest_url('/simpleso/v1/data');
	}

	private function simpleso_prepare_payment_data($order)
	{
		// Check if sandbox mode is enabled
		$is_sandbox = $this->get_option('sandbox') === 'yes';

		// Use sandbox keys if sandbox mode is enabled, otherwise use live keys
		$api_secret = $is_sandbox ? sanitize_text_field($this->get_option('sandbox_secret_key')) : sanitize_text_field($this->get_option('secret_key'));
		$api_public_key = $is_sandbox ? sanitize_text_field($this->get_option('sandbox_public_key')) : sanitize_text_field($this->get_option('public_key'));

		// Sanitize and get the billing email or phone
		$request_for = sanitize_email($order->get_billing_email() ?: $order->get_billing_phone());
		// Get order details and sanitize
		$first_name = sanitize_text_field($order->get_billing_first_name());
		$last_name = sanitize_text_field($order->get_billing_last_name());
		$amount = number_format($order->get_total(), 2, '.', '');

		// Get billing address details
		$billing_address_1 = sanitize_text_field($order->get_billing_address_1());
		$billing_address_2 = sanitize_text_field($order->get_billing_address_2());
		$billing_city = sanitize_text_field($order->get_billing_city());
		$billing_postcode = sanitize_text_field($order->get_billing_postcode());
		$billing_country = sanitize_text_field($order->get_billing_country());
		$billing_state = sanitize_text_field($order->get_billing_state());

		$redirect_url = esc_url_raw(
			add_query_arg(
				array(
					'order_id' => $order->get_id(), // Include order ID or any other identifier
					'key' => $order->get_order_key(),
					'nonce' => wp_create_nonce('simpleso_payment_nonce'), // Create a nonce for verification
					'mode' => 'wp',
				),
				$this->simpleso_get_return_url_base() // Use the updated base URL method
			)
		);

		$ip_address = sanitize_text_field($this->simpleso_get_client_ip());

		// Prepare meta data
		$meta_data = wp_json_encode(array(
			'source' => 'woocommerce',
			'order_id' => $order->get_id()
		));

		return array(
			'api_secret'       => $api_secret, // Use sandbox or live secret key
			'api_public_key'   => $api_public_key, // Add the public key for API calls
			'first_name' => $first_name,
			'last_name' => $last_name,
			'request_for' => $request_for,
			'amount' => $amount,
			'redirect_url' => $redirect_url,
			'redirect_time' => 3,
			'ip_address' => $ip_address,
			'source' => 'wordpress',
			'meta_data' => $meta_data,
			'remarks' => 'Order #' . $order->get_order_number(),
			// Add billing address details to the request
			'billing_address_1' => $billing_address_1,
			'billing_address_2' => $billing_address_2,
			'billing_city' => $billing_city,
			'billing_postcode' => $billing_postcode,
			'billing_country' => $billing_country,
			'billing_state' => $billing_state,
			'is_sandbox' => $is_sandbox,
		);
	}

	// Helper function to get client IP address
	private function simpleso_get_client_ip()
	{
		$ip = '';

		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			// Sanitize the client's IP directly on $_SERVER access
			$ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// Sanitize and handle multiple proxies
			$ip_list = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
			$ip = trim($ip_list[0]); // Take the first IP in the list and trim any whitespace
		} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
			// Sanitize the remote address directly
			$ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
		}

		// Validate the IP after retrieving it
		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
	}


	/**
	 * Add a custom label next to the order status in the order list.
	 *
	 * @param array $line_items The order line items array.
	 * @param WC_Order $order The WooCommerce order object.
	 * @return array Modified line items array.
	 */
	public function simpleso_add_custom_label_to_order_row($line_items, $order)
	{
		// Get the custom meta field value (e.g. '_order_origin')
		$order_origin = $order->get_meta('_order_origin');

		// Check if the meta exists and has value
		if (!empty($order_origin)) {
			// Add the label text to the first item in the order preview
			$line_items[0]['name'] .= ' <span style="background-color: #ffeb3b; color: #000; padding: 3px 5px; border-radius: 3px; font-size: 12px;">' . esc_html($order_origin) . '</span>';
		}

		return $line_items;
	}

	/**
	 * WooCommerce not active notice.
	 */
	public function simpleso_woocommerce_not_active_notice()
	{
		echo '<div class="error">
        <p>' . esc_html__('SimpleSo Payment Gateway requires WooCommerce to be installed and active.', 'simpleso-payment-gateway') . '</p>
    </div>';
	}

	/**
	 * Payment form on checkout page.
	 */
	public function payment_fields()
	{
		$description = $this->get_option('description');

		if ($description) {
			// Apply formatting
			$formatted_description = wpautop(wptexturize(trim($description)));
			// Output directly with escaping
			echo wp_kses_post($formatted_description);
		}

		// Check if the consent checkbox should be displayed
		if ('yes' === $this->get_option('show_consent_checkbox')) {
			// Add user consent checkbox with escaping
			echo '<p class="form-row form-row-wide">
                <label for="simpleso_consent">
                    <input type="checkbox" id="simpleso_consent" name="simpleso_consent" /> ' . esc_html__('I consent to the collection of my data to process this payment', 'simpleso-payment-gateway') . '
                </label>
            </p>';

			// Add nonce field for security
			wp_nonce_field('simpleso_payment', 'simpleso_nonce');
		}
	}

	/**
	 * Validate the payment form.
	 */
	public function validate_fields()
	{
		// Check if the consent checkbox setting is enabled
		if ($this->get_option('show_consent_checkbox') === 'yes') {

			// Sanitize and validate the nonce field
			$nonce = isset($_POST['simpleso_nonce']) ? sanitize_text_field(wp_unslash($_POST['simpleso_nonce'])) : '';
			if (empty($nonce) || !wp_verify_nonce($nonce, 'simpleso_payment')) {
				wc_add_notice(__('Nonce verification failed. Please try again.', 'simpleso-payment-gateway'), 'error');
				return false;
			}

			// Sanitize the consent checkbox input
			$consent = isset($_POST['simpleso_consent']) ? sanitize_text_field(wp_unslash($_POST['simpleso_consent'])) : '';

			// Validate the consent checkbox was checked
			if ($consent !== 'on') {
				wc_add_notice(__('You must consent to the collection of your data to process this payment.', 'simpleso-payment-gateway'), 'error');
				return false;
			}
		}

		return true;
	}


	/**
	 * Enqueue stylesheets for the plugin.
	 */
	public function simpleso_enqueue_styles_and_scripts()
	{
		if (is_checkout()) {
			// Enqueue stylesheets
			wp_enqueue_style(
				'simpleso-payment-loader-styles',
				plugins_url('../assets/css/loader.css', __FILE__),
				array(), // Dependencies (if any)
				'1.0', // Version number
				'all' // Media
			);

			// Enqueue simpleso.js script
			wp_enqueue_script(
				'simpleso-js',
				plugins_url('../assets/js/simpleso.js', __FILE__),
				array('jquery'), // Dependencies
				'1.0', // Version number
				true // Load in footer
			);

			// Localize script with parameters that need to be passed to simpleso.js
			wp_localize_script('simpleso-js', 'simpleso_params', array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'checkout_url' => wc_get_checkout_url(),
				'simpleso_loader' => plugins_url('../assets/images/loader.gif', __FILE__),
				'simpleso_nonce' => wp_create_nonce('simpleso_nonce'), // Create a nonce for verification
			));
		}
	}

	function simpleso_admin_scripts($hook)
	{
		if ('woocommerce_page_wc-settings' !== $hook) {
			return; // Only load on WooCommerce settings page
		}

		// Register and enqueue your script
		wp_enqueue_script('simpleso-admin-script', plugins_url('../assets/js/simpleso-admin.js', __FILE__), array('jquery'), filemtime(plugin_dir_path(__FILE__) . '../assets/js/simpleso-admin.js'), true);

		// Localize the script to pass parameters
		wp_localize_script('simpleso-admin-script', 'params', array(
			'PAYMENT_CODE' => $this->id
		));
	}

	/**
	 * Disable the gateway if the flag is set.
	 */
	public function maybe_disable_gateway($available_gateways)
	{
		if ($this->disable_gateway && isset($available_gateways[$this->id])) {
			unset($available_gateways[$this->id]);
		}

		return $available_gateways;
	}
}
