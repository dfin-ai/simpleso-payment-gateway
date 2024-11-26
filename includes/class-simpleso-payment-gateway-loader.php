<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Class SIMPLESO_PAYMENT_GATEWAY_Loader
 * Handles the loading and initialization of the SimpleSo Payment Gateway plugin.
 */
class SIMPLESO_PAYMENT_GATEWAY_Loader
{
	private static $instance = null;
	private $admin_notices;

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
		$this->admin_notices = new SIMPLESO_PAYMENT_GATEWAY_Admin_Notices();

		add_action('admin_init', [$this, 'simpleso_handle_environment_check']);
		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
		add_action('plugins_loaded', [$this, 'simpleso_init']);

		// Register the AJAX action callback for checking payment status
		add_action('wp_ajax_check_payment_status', array($this, 'simpleso_handle_check_payment_status_request'));
		add_action('wp_ajax_nopriv_check_payment_status', array($this, 'simpleso_handle_check_payment_status_request'));

		register_activation_hook(SIMPLESO_PAYMENT_GATEWAY_FILE, 'simpleso_activation_check');
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
		$rest_api->simpleso_add_cors_support();

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
				'docs'    => '<a href="' . esc_url(apply_filters('simpleso_docs_url', 'https://www.simpleso.io/api/docs/wordpress-plugin')) . '" target="_blank">' . esc_html__('Documentation', 'simpleso-payment-gateway') . '</a>',
				'support' => '<a href="' . esc_url(apply_filters('simpleso_support_url', 'https://www.simpleso.io/contact-us')) . '" target="_blank">' . esc_html__('Support', 'simpleso-payment-gateway') . '</a>',
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
		check_ajax_referer('simpleso_nonce', 'security');

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
		// Check the payment status
		if ($order) {
			if ($order->is_paid()) {
				wp_send_json_success(['status' => 'success', 'redirect_url' => $payment_return_url]);
			} elseif ($order->has_status('failed')) {
				wp_send_json_success(['status' => 'failed', 'redirect_url' => $payment_return_url]);
			}
		}

		// Default to pending status
		wp_send_json_success(['status' => 'pending']);
	}
}
