<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

class SIMPLESO_PAYMENT_GATEWAY_REST_API
{
	private $logger;
	private static $instance = null;

	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct()
	{
		// Initialize the logger
		$this->logger = wc_get_logger();
	}

	public function simpleso_register_routes()
	{
		add_action('rest_api_init', function () {
			register_rest_route('simpleso/v1', '/data', array(
				'methods' => 'POST',
				'callback' => array($this, 'simpleso_handle_api_request'),
				'permission_callback' => '__return_true',
			));
		});
	}
	public function simpleso_add_cors_support()
	{
		// Handle CORS preflight requests
		add_action('rest_api_init', function () {
			if (isset($_SERVER['REQUEST_METHOD']) && sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) === 'OPTIONS') {
				// Validate and set CORS headers
				header('Access-Control-Allow-Origin: *');  // Be cautious about using '*' in production, restrict it to specific origins if needed.
				header('Access-Control-Allow-Methods: POST, OPTIONS');
				header('Access-Control-Allow-Headers: Content-Type, Authorization');
				header('Access-Control-Max-Age: 86400'); // Caching preflight request for 24 hours
				exit;
			}
		});

		// Set CORS headers for regular requests
		add_action('rest_api_init', function () {
			// Validate and set CORS headers
			header('Access-Control-Allow-Origin: *');  // Be cautious about using '*' in production, restrict it to specific origins if needed.
			header('Access-Control-Allow-Methods: POST, OPTIONS');
			header('Access-Control-Allow-Headers: Content-Type, Authorization');
		});
	}

	private function simpleso_verify_api_key($api_key)
	{
		// Sanitize the API key parameter early
		$api_key = sanitize_text_field($api_key);

		// Get DFinSell public key from WooCommerce settings
		$simpleso_settings = get_option('woocommerce_simpleso_settings');

		// Sanitize WooCommerce settings fields
		$sandbox = isset($simpleso_settings['sandbox']) && 'yes' === sanitize_text_field($simpleso_settings['sandbox']);

		// Choose between sandbox and production keys, sanitize them
		$public_key = $sandbox ? sanitize_text_field($simpleso_settings['sandbox_public_key']) : sanitize_text_field($simpleso_settings['public_key']);

		// Ensure public key is not empty and verify API key with a secure hash comparison
		return !empty($public_key) && hash_equals($public_key, $api_key);
	}


	public function simpleso_handle_api_request(WP_REST_Request $request)
	{
		$parameters = $request->get_json_params();

		// Sanitize incoming data
		$api_key = isset($parameters['nonce']) ? sanitize_text_field($parameters['nonce']) : '';
		$order_id = isset($parameters['order_id']) ? intval($parameters['order_id']) : 0;
		$api_order_status = isset($parameters['order_status']) ? sanitize_text_field($parameters['order_status']) : '';

		// Verify API key
		if (!$this->simpleso_verify_api_key(base64_decode($api_key))) {
			$this->logger->error('Unauthorized access attempt.', array('source' => 'simpleso_payment_gateway'));
			return new WP_REST_Response(['error' => 'Unauthorized'], 401);
		}

		if ($order_id <= 0) {
			$this->logger->error('Invalid order ID.', array('source' => 'simpleso_payment_gateway'));
			return new WP_REST_Response(['error' => 'Invalid data'], 400);
		}

		$order = wc_get_order($order_id);
		if (!$order) {
			$this->logger->error('Order not found: ' . $order_id, array('source' => 'simpleso_payment_gateway'));
			return new WP_REST_Response(['error' => 'Order not found'], 404);
		}

		if ($api_order_status == 'completed' && in_array($order->get_status(), ['pending', 'failed'])) {
			// Get the configured order status from the payment gateway settings
			$gateway_id = 'simpleso';
			$payment_gateways = WC()->payment_gateways->payment_gateways();
			if (isset($payment_gateways[$gateway_id])) {
				$gateway = $payment_gateways[$gateway_id];
				$order_status = sanitize_text_field($gateway->get_option('order_status', 'processing'));
			} else {
				$this->logger->error('Payment gateway not found.', array('source' => 'simpleso_payment_gateway'));
				return new WP_REST_Response(['error' => 'Payment gateway not found'], 500);
			}

			// Validate the order status against allowed statuses
			$allowed_statuses = wc_get_order_statuses();
			if (!array_key_exists('wc-' . esc_html($order_status), $allowed_statuses)) {
				$this->logger->error('Invalid order status: ' . esc_html($order_status), array('source' => 'simpleso_payment_gateway'));
				return new WP_REST_Response(['error' => 'Invalid order status'], 400);
			}
		} else {
			$order_status = $order->get_status();
		}

		$updated = $order->update_status($order_status, __('Order status updated via API', 'simpleso-payment-gateway'));

		if (WC()->cart) {
			// Remove cart
			WC()->cart->empty_cart();
		}

		if ($updated) {
			$payment_return_url = esc_url($order->get_checkout_order_received_url());
			$this->logger->info('Order status updated successfully: ' . esc_html($order_id), array('source' => 'simpleso_payment_gateway'));
			return new WP_REST_Response(['success' => true, 'message' => 'Order status updated successfully', 'payment_return_url' => $payment_return_url], 200);
		} else {
			$this->logger->error('Failed to update order status: ' . esc_html($order_id), array('source' => 'simpleso_payment_gateway'));
			return new WP_REST_Response(['error' => 'Failed to update order status'], 500);
		}
	}
}
