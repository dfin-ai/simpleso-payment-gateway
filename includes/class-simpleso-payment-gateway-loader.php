<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Include the configuration file
require_once plugin_dir_path(__FILE__) . 'config.php';

/**
 * Class SIMPLESO_PAYMENT_GATEWAY_Loader
 * Handles the loading and initialization of the SimpleSo Payment Gateway plugin.
 */
class SIMPLESO_PAYMENT_GATEWAY_Loader
{
	private static $instance = null;
	private $admin_notices;

	private $sip_protocol;
	private $sip_host;

	/**
	 * Get the singleton instance of this class.
	 * @return SIMPLESO_PAYMENT_GATEWAY_Loader
	 */
	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Constructor. Sets up actions and hooks.
	 */
	private function __construct()
	{

		$this->sip_protocol = SS_PROTOCOL;
		$this->sip_host = SS_HOST;

		$this->admin_notices = new SIMPLESO_PAYMENT_GATEWAY_Admin_Notices();

		add_action('admin_init', [$this, 'simpleso_handle_environment_check']);
		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
		add_action('plugins_loaded', [$this, 'simpleso_init']);

		// Register the AJAX action callback for checking payment status
		add_action('wp_ajax_check_payment_status', array($this, 'simpleso_handle_check_payment_status_request'));
		add_action('wp_ajax_nopriv_check_payment_status', array($this, 'simpleso_handle_check_payment_status_request'));

		add_action('wp_ajax_popup_closed_event', array($this, 'handle_popup_close'));
		add_action('wp_ajax_nopriv_popup_closed_event', array($this, 'handle_popup_close'));

		register_activation_hook(SIMPLESO_PAYMENT_GATEWAY_FILE, 'simpleso_activation_check');


		// Schedule the cron job on plugin activation
		register_activation_hook(SIMPLESO_PAYMENT_GATEWAY_FILE, [$this, 'activate_cron_job']);

		// Clear the cron job on plugin deactivation
		register_deactivation_hook(SIMPLESO_PAYMENT_GATEWAY_FILE, [$this, 'deactivate_cron_job']);
		add_action('wp_ajax_simpleso_manual_sync', [$this, 'simpleso_manual_sync_callback']);
		//add_action('wp_ajax_nopriv_simpleso_manual_sync', [$this, 'simpleso_manual_sync_callback']);
		add_filter('cron_schedules', [$this, 'simpleso_add_cron_interval']);
		add_action('simpleso_cron_event', [$this, 'handle_cron_event']);
	}


	/**
	 * Initializes the plugin.
	 * This method is hooked into 'plugins_loaded' action.
	 */
	public function simpleso_init()
	{
		// Check if the environment is compatible
		$environment_warning = simpleso_check_system_requirements();
		if ($environment_warning) {
			return;
		}

		// Initialize gateways
		$this->simpleso_init_gateways();

		// Initialize REST API
		$rest_api = SIMPLESO_PAYMENT_GATEWAY_REST_API::get_instance();
		$rest_api->simpleso_register_routes();

		// Add plugin action links
		add_filter('plugin_action_links_' . plugin_basename(SIMPLESO_PAYMENT_GATEWAY_FILE), [$this, 'simpleso_plugin_action_links']);

		// Add plugin row meta
		add_filter('plugin_row_meta', [$this, 'simpleso_plugin_row_meta'], 10, 2);
	}

	/**
	 * Initialize gateways.
	 */
	private function simpleso_init_gateways()
	{
		if (!class_exists('WC_Payment_Gateway')) {
			return;
		}

		include_once SIMPLESO_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-simpleso-payment-gateway.php';

		add_filter('woocommerce_payment_gateways', function ($methods) {
			$methods[] = 'SIMPLESO_PAYMENT_GATEWAY';
			return $methods;
		});
	}


	private function get_api_url($endpoint)
	{
		$base_url = $this->sip_host;
		return $this->sip_protocol . $base_url . $endpoint;
	}

	/**
	 * Add action links to the plugin page.
	 * @param array $links
	 * @return array
	 */
	public function simpleso_plugin_action_links($links)
	{
		$plugin_links = [
			'<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=simpleso')) . '">' . esc_html__('Settings', 'simpleso-payment-gateway') . '</a>',
		];

		return array_merge($plugin_links, $links);
	}

	/**
	 * Add row meta to the plugin page.
	 * @param array $links
	 * @param string $file
	 * @return array
	 */
	public function simpleso_plugin_row_meta($links, $file)
	{
		if (plugin_basename(SIMPLESO_PAYMENT_GATEWAY_FILE) === $file) {
			$row_meta = [
				'docs'    => '<a href="' . esc_url(apply_filters('simpleso_docs_url', 'https://qa.simpleso.io/api/docs/wordpress-plugin')) . '" target="_blank">' . esc_html__('Documentation', 'simpleso-payment-gateway') . '</a>',
				'support' => '<a href="' . esc_url(apply_filters('simpleso_support_url', 'https://qa.simpleso.io/reach-out')) . '" target="_blank">' . esc_html__('Support', 'simpleso-payment-gateway') . '</a>',
			];

			$links = array_merge($links, $row_meta);
		}

		return $links;
	}

	/**
	 * Check the environment and display notices if necessary.
	 */
	public function simpleso_handle_environment_check()
	{
		$environment_warning = simpleso_check_system_requirements();
		if ($environment_warning) {
			// Sanitize the environment warning before displaying it
			$this->admin_notices->simpleso_add_notice('error', 'error', sanitize_text_field($environment_warning));
		}
	}

	/**
	 * Handle the AJAX request for checking payment status.
	 * @param $request
	 */
	public function simpleso_handle_check_payment_status_request($request)
	{
		// Verify nonce for security (recommended)
		// Sanitize and unslash the 'security' value
		$security = isset($_POST['security']) ? sanitize_text_field(wp_unslash($_POST['security'])) : '';

		// Check the nonce for security
		if (empty($security) || !wp_verify_nonce($security, 'simpleso_payment')) {
			wp_send_json_error(['message' => 'Nonce verification failed.']);
			wp_die();
		}

		// Sanitize and validate the order ID from $_POST
		$order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : null;
		if (!$order_id) {
			wp_send_json_error(array('error' => esc_html__('Invalid order ID', 'simpleso-payment-gateway')));
		}

		// Call the function to check payment status with the validated order ID
		return $this->simpleso_check_payment_status($order_id);
	}

	/**
	 * Check the payment status for an order.
	 * @param int $order_id
	 * @return WP_REST_Response
	 */
	public function simpleso_check_payment_status($order_id)
	{
		// Get the order details
		$order = wc_get_order($order_id);

		if (!$order) {
			return new WP_REST_Response(['error' => esc_html__('Order not found', 'simpleso-payment-gateway')], 404);
		}

		$payment_return_url = esc_url($order->get_checkout_order_received_url());

		// Determine order status
		if ($order->is_paid()) {
			wp_send_json_success(['status' => 'success', 'redirect_url' => $payment_return_url]);
			exit;
		}

		if ($order->has_status('failed')) {
			wp_send_json_success(['status' => 'failed', 'redirect_url' => $payment_return_url]);
			exit;
		}

		if ($order->has_status(['on-hold', 'pending'])) {
			wp_send_json_success(['status' => 'pending', 'redirect_url' => $payment_return_url]);
			exit;
		}

		if ($order->has_status('canceled')) {
			wp_send_json_success(['status' => 'canceled', 'redirect_url' => $payment_return_url]);
			exit;
		}

		if ($order->has_status('refunded')) {
			wp_send_json_success(['status' => 'refunded', 'redirect_url' => $payment_return_url]);
			exit;
		}

		// Default response (unknown status)
		wp_send_json_success(['status' => 'unknown', 'redirect_url' => $payment_return_url]);
		exit;
	}

	public function handle_popup_close()
	{
		// Sanitize and unslash the 'security' value
		$security = isset($_POST['security']) ? sanitize_text_field(wp_unslash($_POST['security'])) : '';

		// Check the nonce for security
		if (empty($security) || !wp_verify_nonce($security, 'simpleso_payment')) {
			wp_send_json_error(['message' => 'Nonce verification failed.']);
			wp_die();
		}

		// Get the order ID from the request
		$order_id = isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : null;

		// Validate order ID
		if (!$order_id) {
			wp_send_json_error(['message' => 'Order ID is missing.']);
			wp_die();
		}

		// Fetch the WooCommerce order
		$order = wc_get_order($order_id);

		// Check if the order exists
		if (!$order) {
			wp_send_json_error(['message' => 'Order not found in WordPress.']);
			wp_die();
		}

		//Get uuid from WP
		$payment_token = $order->get_meta('_simpleso_pay_id');

		// Proceed only if the order status is 'pending'
		if ($order->get_status() === 'pending') {
			// Call the SimpleSo to update status
			$transactionStatusApiUrl = $this->get_api_url('/api/update-txn-status');
			$response = wp_remote_post($transactionStatusApiUrl, [
				'method'    => 'POST',
				'body'      => wp_json_encode(['order_id' => $order_id, 'payment_token' => $payment_token]),
				'headers'   => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $security,
				],
				'timeout'   => 15,
			]);

			wc_get_logger()->info("response from the popup close event :" . json_encode($response), ['source' => 'simpleso-payment-gateway']);

			// Check for errors in the API request
			if (is_wp_error($response)) {
				wp_send_json_error(['message' => 'Failed to connect to the SimpleSo.']);
				wp_die();
			}

			// Parse the API response
			$response_body = wp_remote_retrieve_body($response);
			$response_data = json_decode($response_body, true);

			// Ensure the response contains the expected data
			if (!isset($response_data['transaction_status'])) {
				wp_send_json_error(['message' => 'Invalid response from SimpleSo.']);
				wp_die();
			}

			// Get the configured order status from the payment gateway settings
			$gateway_id = 'simpleso'; // Replace with your gateway ID
			$payment_gateways = WC()->payment_gateways->payment_gateways();
			if (isset($payment_gateways[$gateway_id])) {
				$gateway = $payment_gateways[$gateway_id];
				$configured_order_status = sanitize_text_field($gateway->get_option('order_status'));
			} else {
				wp_send_json_error(['message' => 'Payment gateway not found.']);
				wp_die();
			}

			// Validate the configured order status
			$allowed_statuses = wc_get_order_statuses();
			if (!array_key_exists('wc-' . $configured_order_status, $allowed_statuses)) {
				wp_send_json_error(['message' => 'Invalid order status configured: ' . esc_html($configured_order_status)]);
				wp_die();
			}

			$payment_return_url = esc_url($order->get_checkout_order_received_url());


			if (isset($response_data['transaction_status'])) {
				// Handle transaction status from API
				switch ($response_data['transaction_status']) {
					case 'success':
					case 'paid':
					case 'processing':
						// Update the order status based on the selected value
						try {
							$order->update_status($configured_order_status, 'Order marked as ' . $configured_order_status . ' by SimpleSo.');
							wp_send_json_success(['message' => 'Order status updated successfully.', 'order_id' => $order_id, 'redirect_url' => $payment_return_url]);
						} catch (Exception $e) {
							wp_send_json_error(['message' => 'Failed to update order status: ' . $e->getMessage()]);
						}
						break;

					case 'failed':
						try {
							$order->update_status('failed', 'Order marked as failed by SimpleSo.');
							wp_send_json_success(['message' => 'Order status updated to failed.', 'order_id' => $order_id, 'redirect_url' => $payment_return_url]);
						} catch (Exception $e) {
							wp_send_json_error(['message' => 'Failed to update order status: ' . $e->getMessage()]);
						}
						break;
					case 'canceled':
					case 'expired':
						try {
							$order->update_status('canceled', 'Order marked as canceled by SimpleSo.');
							wp_send_json_success(['message' => 'Order status updated to canceled.', 'order_id' => $order_id, 'redirect_url' => $payment_return_url]);
						} catch (Exception $e) {
							wp_send_json_error(['message' => 'Failed to update order status: ' . $e->getMessage()]);
						}
						break;
					default:
						wp_send_json_error(['message' => 'Unknown transaction status received.']);
				}
			}
		} else {
			// Skip API call if the order status is not 'pending'
			wp_send_json_success(['message' => 'No update required as the order status is not pending.', 'order_id' => $order_id]);
		}

		wp_die();
	}

	/**
     * Add custom cron schedules.
     */


	public function simpleso_add_cron_interval($schedules)
	{
		$schedules['every_two_hours'] = array(
			'interval' => 2 * 60 * 60, // 2 hours in seconds = 7200
			'display'  => __('Every Two Hours', 'simpleso-payment-gateway')
		);
		return $schedules;
	}

	function activate_cron_job()
	{
		wc_get_logger()->info("activate cron job function", ['source' => 'simpleso-payment-gateway']);

		// Clear existing scheduled event if it exists
		$timestamp = wp_next_scheduled('simpleso_cron_event');
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'simpleso_cron_event');
		}

		// Schedule with new interval
		wp_schedule_event(time(), 'every_two_hours', 'simpleso_cron_event');
	}

	function deactivate_cron_job()
	{
		wc_get_logger()->info("deactivate cron job function", ['source' => 'simpleso-payment-gateway']);
		wp_clear_scheduled_hook('simpleso_cron_event');
	}


	public function handle_cron_event()
	{
		wc_get_logger()->info("Sync started via API.", ['source' => 'simpleso-payment-gateway']);

		$accounts = get_option('woocommerce_simpleso_payment_gateway_accounts');
		if (is_string($accounts)) {
			$unserialized = maybe_unserialize($accounts);
			$accounts = is_array($unserialized) ? $unserialized : [];
		}


		if (!$accounts || !is_array($accounts)) {
			wc_get_logger()->info("No accounts found or invalid format:" . $accounts, ['source' => 'simpleso-payment-gateway']);
			return;
		}

		$accountsData = [];
		$bearerToken = null;

		foreach ($accounts as &$account) {
			$isSandboxEnabled = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';

			// Prepare both live and sandbox entries
			if (!empty($account['live_public_key']) && !empty($account['live_secret_key'])) {
				$accountsData[] = [
					'account_name' => $account['title'],
					'public_key'   => $account['live_public_key'],
					'secret_key'   => $account['live_secret_key'],
					'mode'         => 'live',
				];
			}

			if ($isSandboxEnabled && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) {
				$accountsData[] = [
					'account_name' => $account['title'],
					'public_key'   => $account['sandbox_public_key'],
					'secret_key'   => $account['sandbox_secret_key'],
					'mode'         => 'sandbox',
				];
			}
		}



		$url = esc_url($this->sip_protocol . $this->sip_host . '/api/sync-account-status');
		$response = wp_remote_post($url, [
			'headers' => [
				'Content-Type'  => 'application/json',
			],
			'body' => json_encode(['accounts' => $accountsData]),
			'timeout' => 15,
		]);

		if (is_wp_error($response)) {
			wc_get_logger()->info("API error for bulk request.", ['source' => 'simpleso-payment-gateway']);
			return;
		}

		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		$updated = false;
		$statusSummary = [];
		if (!empty($response_data['statuses'])) {
			foreach ($response_data['statuses'] as $statusData) {
				if (
					isset($statusData['mode'], $statusData['public_key'], $statusData['status']) &&
					!empty($statusData['status'])
				) {
					foreach ($accounts as &$account) {
						if (
							$statusData['mode'] === 'live' &&
							$account['live_public_key'] === $statusData['public_key']
						) {
							$account['live_status'] = $statusData['status'];
							$updated = true;
							$statusSummary[] = [
								'title'  => $account['title'] ?? 'N/A',
								'mode'   => $statusData['mode'],
								'status' => $statusData['status'],
							];
						}

						if (
							$statusData['mode'] === 'sandbox' &&
							$account['sandbox_public_key'] === $statusData['public_key']
						) {
							$account['sandbox_status'] = $statusData['status'];
							$updated = true;
							$statusSummary[] = [
								'title'  => $account['title'] ?? 'N/A',
								'mode'   => $statusData['mode'],
								'status' => $statusData['status'],
							];
						}
					}
				}
			}
		}
		if (!empty($statusSummary)) {
			wc_get_logger()->info('SimpleSo sync account Response: ' . json_encode($statusSummary), ['source' => 'simpleso-payment-gateway']);
		}
		if ($updated) {
			update_option('woocommerce_simpleso_payment_gateway_accounts', $accounts);
			wc_get_logger()->info("Sync completed successfully via API.", ['source' => 'simpleso-payment-gateway']);
		} else {
			wc_get_logger()->info("Cron ran but no statuses were updated", ['source' => 'simpleso-payment-gateway']);
		}
	}

	function simpleso_manual_sync_callback()
	{

		// Verify nonce first
		if (!check_ajax_referer('simpleso_sync_nonce', 'nonce', false)) {
			wc_get_logger()->error('Nonce verification failed', ['source' => 'simpleso-payment-gateway']);
			wp_send_json_error([
				'message' => __('Security check failed. Please refresh the page and try again.', 'simpleso-payment-gateway')
			], 400);
			wp_die();
		}

		// Check user capabilities
		if (!current_user_can('manage_woocommerce')) {
			wc_get_logger()->error('Permission denied for user: ' . get_current_user_id(), ['source' => 'simpleso-payment-gateway']);
			wp_send_json_error([
				'message' => __('You do not have permission to perform this action.', 'simpleso-payment-gateway')
			], 403);
			wp_die();
		}

		wc_get_logger()->info("Manual sync initiated", ['source' => 'simpleso-payment-gateway']);

		try {
			// Start output buffering to capture any unexpected output
			ob_start();

			$this->handle_cron_event();
			$output = ob_get_clean();

			if (!empty($output)) {
				wc_get_logger()->notice("Unexpected output during sync: " . $output, ['source' => 'simpleso-payment-gateway']);
			}

			wp_send_json_success([
				'message' => __('Accounts synchronized successfully.', 'simpleso-payment-gateway'),
				'timestamp' => current_time('mysql')
			]);
		} catch (Exception $e) {
			wc_get_logger()->error("Sync error: " . $e->getMessage(), ['source' => 'simpleso-payment-gateway']);
			wp_send_json_error([
				'message' => __('Sync failed: ', 'simpleso-payment-gateway') . $e->getMessage(),
				'code' => $e->getCode()
			], 500);
		}

		wp_die(); // Always include this
	}
}
