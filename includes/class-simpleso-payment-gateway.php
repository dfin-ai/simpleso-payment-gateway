<?php
if (!defined('ABSPATH')) {
	exit(); // Exit if accessed directly.
}
// Include the configuration file
require_once plugin_dir_path(__FILE__) . 'config.php';

/**
 * Main WooCommerce SimpleSo Payment Gateway class.
 */
class SIMPLESO_PAYMENT_GATEWAY extends WC_Payment_Gateway_CC
{
	const ID = 'simpleso';

	private $sip_protocol;
	private $sip_host;

	protected $sandbox;

	private $public_key;
	private $secret_key;
	private $sandbox_secret_key;
	private $sandbox_public_key;

	private $admin_notices;
	private $accounts = [];
	private $current_account_index = 0;
	private $used_accounts = [];

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		// Check if WooCommerce is active
		if (!class_exists('WC_Payment_Gateway_CC')) {
			add_action('admin_notices', [$this, 'woocommerce_not_active_notice']);
			return;
		}

		// Instantiate the notices class
		$this->admin_notices = new SIMPLESO_PAYMENT_GATEWAY_Admin_Notices();

		// Determine SIP protocol based on site protocol
		$this->sip_protocol = SS_PROTOCOL;
		$this->sip_host = SS_HOST;

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
		$this->public_key = $this->sandbox === 'no' ? sanitize_text_field($this->get_option('public_key')) : sanitize_text_field($this->get_option('sandbox_public_key'));
		$this->secret_key = $this->sandbox === 'no' ? sanitize_text_field($this->get_option('secret_key')) : sanitize_text_field($this->get_option('sandbox_secret_key'));
		$this->current_account_index = 0;

		// Define hooks and actions.
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'simpleso_process_admin_options']);

		// Enqueue styles and scripts
		add_action('wp_enqueue_scripts', [$this, 'simpleso_enqueue_styles_and_scripts']);

		add_action('admin_enqueue_scripts', [$this, 'simpleso_admin_scripts']);

		// Add action to display test order tag in order details
		add_action('woocommerce_admin_order_data_after_order_details', [$this, 'simpleso_display_test_order_tag']);

		// Hook into WooCommerce to add a custom label to order rows
		add_filter('woocommerce_admin_order_preview_line_items', [$this, 'simpleso_add_custom_label_to_order_row'], 10, 2);

		add_filter('woocommerce_available_payment_gateways', [$this, 'hide_custom_payment_gateway_conditionally']);
	}

	private function get_api_url($endpoint)
	{
		return $this->sip_protocol . $this->sip_host . $endpoint;
	}

	public function simpleso_process_admin_options()
	{
		parent::process_admin_options();

		$errors = [];
		$valid_accounts = [];

		if (!isset($_POST['simpleso_accounts_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['simpleso_accounts_nonce'])), 'simpleso_accounts_nonce_action')) {
			wp_die(esc_html__('Security check failed!', 'simpleso-payment-gateway'));
		}

		//  CHECK IF ACCOUNTS EXIST
		if (!isset($_POST['accounts']) || !is_array($_POST['accounts']) || empty($_POST['accounts'])) {
			$errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'simpleso-payment-gateway');
		} else {
			$normalized_index = 0;
			$unique_live_keys = [];
			$unique_sandbox_keys = [];

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Input is sanitized below
			$raw_accounts = isset($_POST['accounts']) ? wp_unslash($_POST['accounts']) : [];

			if (!is_array($raw_accounts)) {
				$raw_accounts = [];
			}

			$accounts = array_map(function ($account) {
				if (is_array($account)) {
					return array_map('sanitize_text_field', $account);
				}
				return sanitize_text_field($account);
			}, $raw_accounts);

			foreach ($accounts as $index => $account) {
				// Sanitize input
				$account_title = sanitize_text_field($account['title'] ?? '');
				$priority = isset($account['priority']) ? intval($account['priority']) : 1;
				$live_public_key = sanitize_text_field($account['live_public_key'] ?? '');
				$live_secret_key = sanitize_text_field($account['live_secret_key'] ?? '');
				$sandbox_public_key = sanitize_text_field($account['sandbox_public_key'] ?? '');
				$sandbox_secret_key = sanitize_text_field($account['sandbox_secret_key'] ?? '');
				$has_sandbox = isset($account['has_sandbox']); // Checkbox handling

				//  Ignore empty accounts
				if (empty($account_title) && empty($live_public_key) && empty($live_secret_key) && empty($sandbox_public_key) && empty($sandbox_secret_key)) {
					continue;
				}

				//  Validate required fields
				if (empty($account_title) || empty($live_public_key) || empty($live_secret_key)) {
					// Translators: %s is the account title.
					$errors[] = sprintf(__('Account "%s": Title, Live Public Key, and Live Secret Key are required.', 'simpleso-payment-gateway'), $account_title);
					continue;
				}

				//  Ensure live keys are unique
				$live_combined = $live_public_key . '|' . $live_secret_key;
				if (in_array($live_combined, $unique_live_keys)) {
					// Translators: %s is the account title.
					$errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be unique.', 'simpleso-payment-gateway'), $account_title);
					continue;
				}
				$unique_live_keys[] = $live_combined;

				//  Ensure live keys are different
				if ($live_public_key === $live_secret_key) {
					// Translators: %s is the account title.
					$errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be different.', 'simpleso-payment-gateway'), $account_title);
				}

				//  Sandbox Validation
				if ($has_sandbox) {
					if (!empty($sandbox_public_key) && !empty($sandbox_secret_key)) {
						// Sandbox keys must be unique
						$sandbox_combined = $sandbox_public_key . '|' . $sandbox_secret_key;
						if (in_array($sandbox_combined, $unique_sandbox_keys)) {
							// Translators: %s is the account title.
							$errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be unique.', 'simpleso-payment-gateway'), $account_title);
							continue;
						}
						$unique_sandbox_keys[] = $sandbox_combined;

						// Sandbox keys must be different
						if ($sandbox_public_key === $sandbox_secret_key) {
							// Translators: %s is the account title.
							$errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be different.', 'simpleso-payment-gateway'), $account_title);
						}
					}
				}
				// Add the 'status' field, defaulting to 'active' for new accounts
				$sandbox_status = isset($account['sandbox_status']) ? sanitize_text_field($account['sandbox_status']) : 'active';
				$live_status = isset($account['live_status']) ? sanitize_text_field($account['live_status']) : 'active';
				// Store valid account
				$valid_accounts[$normalized_index] = [
					'title' => $account_title,
					'priority' => $priority,
					'live_public_key' => $live_public_key,
					'live_secret_key' => $live_secret_key,
					'sandbox_public_key' => $sandbox_public_key,
					'sandbox_secret_key' => $sandbox_secret_key,
					'has_sandbox' => $has_sandbox ? 'on' : 'off',
					'sandbox_status' => $has_sandbox ? $sandbox_status : '',
					'live_status' => $live_status,
				];
				$normalized_index++;
			}
		}

		//  Ensure at least one valid account exists
		if (empty($valid_accounts) && empty($errors)) {
			$errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'simpleso-payment-gateway');
		}

		//  Stop saving if there are any errors
		if (empty($errors)) {
			update_option('woocommerce_simpleso_payment_gateway_accounts', $valid_accounts);
			$this->admin_notices->simpleso_add_notice('settings_success', 'notice notice-success', __('Settings saved successfully.', 'simpleso-payment-gateway'));
			if (class_exists('SIMPLESO_PAYMENT_GATEWAY_Loader')) {
				$loader = SIMPLESO_PAYMENT_GATEWAY_Loader::get_instance(); // Use the static method
				if (method_exists($loader, 'handle_cron_event')) {
					$loader->handle_cron_event(); // Perform sync immediately
				}
			}
		} else {
			foreach ($errors as $error) {
				$this->admin_notices->simpleso_add_notice('settings_error', 'notice notice-error', $error);
			}
		}

		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
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
		$form_fields = [
			'enabled' => [
				'title' => __('Enable/Disable', 'simpleso-payment-gateway'),
				'label' => __('Enable SimpleSo Payment Gateway', 'simpleso-payment-gateway'),
				'type' => 'checkbox',
				'description' => '',
				'default' => 'no',
			],
			'title' => [
				'title' => __('Title', 'simpleso-payment-gateway'),
				'type' => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'simpleso-payment-gateway'),
				'default' => __('Credit and Debit Card', 'simpleso-payment-gateway'),
				'desc_tip' => __('Enter the title of the payment gateway as it will appear to customers during checkout.', 'simpleso-payment-gateway'),
			],
			'description' => [
				'title' => __('Description', 'simpleso-payment-gateway'),
				'type' => 'text',
				'description' => __('Provide a brief description of the SimpleSo Payment Gateway option.', 'simpleso-payment-gateway'),
				'default' => 'Payment Gateway',
				'desc_tip' => __('Enter a brief description that explains the SimpleSo Payment Gateway option.', 'simpleso-payment-gateway'),
			],
			'instructions' => [
				'title' => __('Instructions', 'simpleso-payment-gateway'),
				'type' => 'title',
				// Translators comment added here
				/* translators: 1: Link to developer account */
				'description' => sprintf(
					/* translators: %1$s is a link to the developer account. %2$s is used for any additional formatting if necessary. */
					__('To configure this gateway, %1$sGet your API keys from your merchant account: Developer Settings > API Keys.%2$s', 'simpleso-payment-gateway'),
					'<strong><a class="simpleso-instructions-url" href="' .
						esc_url($this->sip_host . '/developers') .
						'" target="_blank">' .
						__('click here to access your developer account', 'simpleso-payment-gateway') .
						'</a></strong><br>',
					''
				),
				'desc_tip' => true,
			],
			'sandbox' => [
				'title' => __('Sandbox', 'simpleso-payment-gateway'),
				'label' => __('Enable Sandbox Mode', 'simpleso-payment-gateway'),
				'type' => 'checkbox',
				'description' => __('Place the payment gateway in sandbox mode using sandbox API keys (real payments will not be taken).', 'simpleso-payment-gateway'),
				'default' => 'no',
			],
			'accounts' => [
				'title' => __('Payment Accounts', 'simpleso-payment-gateway'),
				'type' => 'accounts_repeater', // Custom field type for dynamic accounts
				'description' => __('Add multiple payment accounts dynamically.', 'simpleso-payment-gateway'),
			],
			'order_status' => [
				'title' => __('Order Status', 'simpleso-payment-gateway'),
				'type' => 'select',
				'description' => __('Select the order status to be set after successful payment.', 'simpleso-payment-gateway'),
				'default' => '', // Default is empty, which is our placeholder
				'desc_tip' => true,
				'id' => 'order_status_select', // Add an ID for targeting
				'options' => [
					// '' => __('Select order status', 'simpleso-payment-gateway'), // Placeholder option
					'processing' => __('Processing', 'simpleso-payment-gateway'),
					'completed' => __('Completed', 'simpleso-payment-gateway'),
				],
			],
			'show_consent_checkbox' => [
				'title' => __('Show Consent Checkbox', 'simpleso-payment-gateway'),
				'label' => __('Enable consent checkbox on checkout page', 'simpleso-payment-gateway'),
				'type' => 'checkbox',
				'description' => __('Check this box to show the consent checkbox on the checkout page. Uncheck to hide it.', 'simpleso-payment-gateway'),
				'default' => 'yes',
			],
		];

		return apply_filters('woocommerce_gateway_settings_fields_' . $this->id, $form_fields, $this);
	}

	public function generate_accounts_repeater_html($key, $data)
	{

		$option_value = get_option('woocommerce_simpleso_payment_gateway_accounts', []);
		$option_value = maybe_unserialize($option_value);
		$active_account = get_option('simpleso_active_account', 0); // Store active account ID
		$global_settings = get_option('woocommerce_simpleso_settings', []);
		$global_settings = maybe_unserialize($global_settings);
		$sandbox_enabled = !empty($global_settings['sandbox']) && $global_settings['sandbox'] === 'yes';

		ob_start();


?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html($data['title']); ?></label>
			</th>
			<td class="forminp">
				<div id="global-error" class="error-message" style="color: red; margin-bottom: 10px;"></div>
				<div class="simpleso-accounts-container">
					<?php if (!empty($option_value)): ?>
						<div class="simpleso-sync-account">
							<span id="simpleso-sync-status"></span>
							<button class="button" class="simpleso-sync-accounts" id="simpleso-sync-accounts"><span><i class="fa fa-refresh" aria-hidden="true"></i></span> <?php esc_html_e('Sync Accounts', 'simpleso-payment-gateway'); ?></button>
						</div>
					<?php endif; ?>


					<?php if (empty($option_value)): ?>
						<div class="empty-account"><?php esc_html_e('No accounts available. Please add one to continue.', 'simpleso-payment-gateway'); ?></div>
					<?php else: ?>
						<?php foreach (array_values($option_value) as $index => $account): ?>
							<?php
							$live_status = (!empty($account['live_status'])) ? $account['live_status'] : '';
							$sandbox_status = (!empty($account['sandbox_status'])) ? $account['sandbox_status'] : 'unknown';
							?>
							<div class="simpleso-account" data-index="<?php echo esc_attr($index); ?>">
								<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][live_status]"
									value="<?php echo esc_attr($account['live_status'] ?? ''); ?>">
								<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][sandbox_status]"
									value="<?php echo esc_attr($account['sandbox_status'] ?? ''); ?>">
								<div class="title-blog">

									<h4>

										<span class="account-name-display">
											<?php echo !empty($account['title']) ? esc_html($account['title']) : esc_html__('Untitled Account', 'simpleso-payment-gateway'); ?>
										</span>
										&nbsp;<i class="fa fa-caret-down account-toggle-btn" aria-hidden="true"></i>
									</h4>


									<div class="action-button">
										<div class="account-status-block" style="float: right;">
											<span class="account-status-label 
									    <?php echo esc_attr($sandbox_enabled ? 'sandbox-status' : 'live-status'); ?> 
									    <?php echo esc_attr(strtolower($sandbox_enabled ? ($sandbox_status ?? '') : ($live_status ?? ''))); ?>">
												<?php
												if ($sandbox_enabled) {
													echo esc_html__('Sandbox Account Status: ', 'simpleso-payment-gateway') . esc_html(ucfirst($sandbox_status));
												} else {
													echo esc_html__('Live Account Status: ', 'simpleso-payment-gateway') . esc_html(ucfirst($live_status));
												} ?>
											</span>
										</div>
										<button type="button" class="delete-account-btn">
											<i class="fa fa-trash" aria-hidden="true"></i>
										</button>
									</div>
								</div>

								<div class="account-info">
									<div class="add-blog title-priority">
										<div class="account-input account-name">
											<label><?php esc_html_e('Account Name', 'simpleso-payment-gateway'); ?></label>
											<input type="text" class="account-title"
												name="accounts[<?php echo esc_attr($index); ?>][title]"
												placeholder="<?php esc_attr_e('Account Title', 'simpleso-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['title'] ?? ''); ?>">
										</div>
										<div class="account-input priority-name">
											<label><?php esc_html_e('Priority', 'simpleso-payment-gateway'); ?></label>
											<input type="number" class="account-priority"
												name="accounts[<?php echo esc_attr($index); ?>][priority]"
												placeholder="<?php esc_attr_e('Priority', 'simpleso-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['priority'] ?? '1'); ?>" min="1">
										</div>
									</div>

									<div class="add-blog">

										<div class="account-input">
											<label><?php esc_html_e('Live Keys', 'simpleso-payment-gateway'); ?></label>
											<input type="text" class="live-public-key"
												name="accounts[<?php echo esc_attr($index); ?>][live_public_key]"
												placeholder="<?php esc_attr_e('Public Key', 'simpleso-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['live_public_key'] ?? ''); ?>">
										</div>
										<div class="account-input">
											<input type="text" class="live-secret-key"
												name="accounts[<?php echo esc_attr($index); ?>][live_secret_key]"
												placeholder="<?php esc_attr_e('Secret Key', 'simpleso-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['live_secret_key'] ?? ''); ?>">
										</div>
									</div>

									<div class="account-checkbox">
										<input type="checkbox" class="sandbox-checkbox"
											name="accounts[<?php echo esc_attr($index); ?>][has_sandbox]"
											<?php checked(!empty($account['sandbox_public_key'])); ?>>
										<?php esc_html_e('Do you have the sandbox keys?', 'simpleso-payment-gateway'); ?>
									</div>

									<div class="sandbox-key" style="<?php echo empty($account['sandbox_public_key']) ? 'display: none;' : ''; ?>">
										<div class="add-blog">

											<div class="account-input">
												<label><?php esc_html_e('Sandbox Keys', 'simpleso-payment-gateway'); ?></label>
												<input type="text" class="sandbox-public-key"
													name="accounts[<?php echo esc_attr($index); ?>][sandbox_public_key]"
													placeholder="<?php esc_attr_e('Public Key', 'simpleso-payment-gateway'); ?>"
													value="<?php echo esc_attr($account['sandbox_public_key'] ?? ''); ?>">
											</div>
											<div class="account-input">
												<input type="text" class="sandbox-secret-key"
													name="accounts[<?php echo esc_attr($index); ?>][sandbox_secret_key]"
													placeholder="<?php esc_attr_e('Secret Key', 'simpleso-payment-gateway'); ?>"
													value="<?php echo esc_attr($account['sandbox_secret_key'] ?? ''); ?>">
											</div>
										</div>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php wp_nonce_field('simpleso_accounts_nonce_action', 'simpleso_accounts_nonce'); ?>
					<div class="add-account-btn">
						<button type="button" class="button simpleso-add-account">
							<span>+</span> <?php esc_html_e('Add Account', 'simpleso-payment-gateway'); ?>
						</button>
					</div>
				</div>
			</td>
		</tr>
<?php return ob_get_clean();
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment($order_id, $used_accounts = [])
	{
		global $wpdb;
		// Retrieve client IP
		$ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
		if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
			$ip_address = 'invalid';
		}

		// **Rate Limiting**
		$window_size = 30; // 30 seconds
		$max_requests = 100;
		$timestamp_key = "rate_limit_{$ip_address}_timestamps";
		$request_timestamps = get_transient($timestamp_key) ?: [];

		// Remove old timestamps
		$timestamp = time();
		$request_timestamps = array_filter($request_timestamps, fn($ts) => $timestamp - $ts <= $window_size);

		if (count($request_timestamps) >= $max_requests) {
			wc_add_notice(__('Too many requests. Please try again later.', 'simpleso-payment-gateway'), 'error');
			return ['result' => 'fail'];
		}

		// Add the current timestamp
		$request_timestamps[] = $timestamp;
		set_transient($timestamp_key, $request_timestamps, $window_size);

		// **Retrieve Order**
		$order = wc_get_order($order_id);
		if (!$order) {
			wc_add_notice(__('Invalid order.', 'simpleso-payment-gateway'), 'error');
			return ['result' => 'fail'];
		}

		// **Sandbox Mode Handling**
		if ($this->sandbox) {
			$test_note = __('This is a test order processed in sandbox mode.', 'simpleso-payment-gateway');
			$existing_notes = get_comments(['post_id' => $order->get_id(), 'type' => 'order_note', 'approve' => 'approve']);

			if (!array_filter($existing_notes, fn($note) => trim($note->comment_content) === trim($test_note))) {
				$order->update_meta_data('_is_test_order', true);
				$order->add_order_note($test_note);
			}
		}
		$last_failed_account = null; // Track the last account that reached the limit
		$previous_account = null;
		// **Start Payment Process**
		while (true) {
			$account = $this->get_next_available_account($used_accounts);
			if (!$account) {
				// **Ensure email is sent to the last failed account**
				if ($last_failed_account) {
					wc_get_logger()->info("Sending email to last failed account: '{$last_failed_account['title']}'", ['source' => 'simpleso-payment-gateway']);
					$this->send_account_switch_email($last_failed_account, $account);
				}
				wc_add_notice(__('No available payment accounts.', 'simpleso-payment-gateway'), 'error');
				return ['result' => 'fail'];
			}
			$lock_key = $account['lock_key'] ?? null;

			//wc_get_logger()->info("Using account '{$account['title']}' for payment.", ['source' => 'simpleso-payment-gateway']);
			// Add order note mentioning account name
			$order->add_order_note(__('Processing Payment Via: ', 'simpleso-payment-gateway') . $account['title']);

			// **Prepare API Data**
			$public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
			$secret_key = $this->sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];
			$data = $this->simpleso_prepare_payment_data($order, $public_key, $secret_key);

			// **Check Transaction Limit**
			$transactionLimitApiUrl = $this->get_api_url('/api/dailylimit');
			$transaction_limit_response = wp_remote_post($transactionLimitApiUrl, [
				'method' => 'POST',
				'timeout' => 30,
				'body' => $data,
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Authorization' => 'Bearer ' . sanitize_text_field($data['api_public_key']),
				],
				'sslverify' => true,
			]);

			$transaction_limit_data = json_decode(wp_remote_retrieve_body($transaction_limit_response), true);

			// **Handle Account Limit Error**
			if (isset($transaction_limit_data['error'])) {
				$error_message = sanitize_text_field($transaction_limit_data['error']);
				wc_get_logger()->error("Account '{$account['title']}' limit reached: $error_message", ['source' => 'simpleso-payment-gateway']);
				if (!empty($lock_key)) {
					$this->release_lock($lock_key);
				}

				$last_failed_account = $account;
				// Switch to next available account
				$used_accounts[] = $account['title'];
				$new_account = $this->get_next_available_account($used_accounts);

				// **Send Email Notification **
				if ($new_account) {
					wc_get_logger()->info("Switching from '{$account['title']}' to '{$new_account['title']}' due to limit.", ['source' => 'simpleso-payment-gateway']);

					// Send email only to the previously failed account
					if ($previous_account) {
						//$this->send_account_switch_email($previous_account, $account);
					}

					$previous_account = $account;
					continue; // Retry with the new account
				} else {
					// **No available accounts left, send email to the last failed account**
					if ($last_failed_account) {
						$this->send_account_switch_email($last_failed_account, $account);
					}
					wc_add_notice(__('All accounts have reached their transaction limit.', 'simpleso-payment-gateway'), 'error');
					return ['result' => 'fail'];
				}
			}

			// **Proceed with Payment**
			$apiPath = '/api/request-payment';
			$url = esc_url($this->sip_protocol . $this->sip_host . $apiPath);

			$order->update_meta_data('_order_origin', 'simpleso_payment_gateway');
			$order->save();
			wc_get_logger()->info('SimpleSo Payment Request: ' . wp_json_encode($data), ['source' => 'simpleso-payment-gateway']);

			$response = wp_remote_post($url, [
				'method' => 'POST',
				'timeout' => 30,
				'body' => $data,
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Authorization' => 'Bearer ' . sanitize_text_field($data['api_public_key']),
				],
				'sslverify' => true,
			]);

			// **Handle Response**
			if (is_wp_error($response)) {
				wc_get_logger()->error('SimpleSo Payment Request Error: ' . $response->get_error_message(), ['source' => 'simpleso-payment-gateway']);
				if (!empty($lock_key)) {
					$this->release_lock($lock_key);
				}
				wc_add_notice(__('Payment error: Unable to process.', 'simpleso-payment-gateway'), 'error');
				return ['result' => 'fail'];
			}

			$response_data = json_decode(wp_remote_retrieve_body($response), true);
			wc_get_logger()->info('SimpleSo Payment Response: ' . json_encode($response_data), ['source' => 'simpleso-payment-gateway']);

			if (!empty($response_data['status']) && $response_data['status'] === 'success' && !empty($response_data['data']['payment_link'])) {
				if ($last_failed_account) {
					//wc_get_logger()->info("Sending email before returning success to: '{$last_failed_account['title']}'", ['source' => 'simpleso-payment-gateway']);
					$this->send_account_switch_email($last_failed_account, $account);
				}
				//$last_successful_account = $account;
				// Save pay_id to order meta
				$pay_id = $response_data['data']['pay_id'] ?? '';
				if (!empty($pay_id)) {
					$order->update_meta_data('_simpleso_pay_id', $pay_id);
				}

				// **Update Order Status**
				$order->update_status('pending', __('Payment pending.', 'simpleso-payment-gateway'));


				// **Add Order Note (If Not Exists)**
				// translators: %s represents the account title.
				$new_note = sprintf(
					/* translators: %s represents the account title. */
					esc_html__('Payment initiated via SimpleSo. Awaiting your completion ( %s )', 'simpleso-payment-gateway'),
					esc_html($account['title'])
				);
				$existing_notes = $order->get_customer_order_notes();

				if (!array_filter($existing_notes, fn($note) => trim(wp_strip_all_tags($note->comment_content)) === trim($new_note))) {
					$order->add_order_note($new_note, false, true);
				}

				// =========================== / start code for payment link / ================= 


				$table_name = $wpdb->prefix . 'order_payment_link';
				$table_name = esc_sql($table_name); // sanitize as an extra precaution
				$order_id   = $order->get_id();
				$uuid = sanitize_text_field($response_data['data']['pay_id']);

				$json_data = json_encode($response_data);
				wc_get_logger()->info("Order Payment link data: $json_data", ['source' => 'simpleso-payment-gateway']);


				// ========================== Check if table exists, create if not ==========================
				$charset_collate = $wpdb->get_charset_collate();

				// Use dbDelta() to create/update the table schema. dbDelta() handles the "table exists" check.
				//  Important:  Use placeholders for any variables in the CREATE TABLE statement, even though
				//  dbDelta() is designed to handle this.  It's good practice.  In this case, we are using $table_name
				$sql = "
				CREATE TABLE IF NOT EXISTS {$table_name} (
				    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				    order_id BIGINT(20) UNSIGNED NOT NULL,
				    uuid TEXT NOT NULL,
				    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				    PRIMARY KEY (id),
				    INDEX (order_id)
				) ENGINE=InnoDB {$charset_collate};
				";

				require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // Only include upgrade.php when needed.
				dbDelta($sql);

				// Check for errors after dbDelta()
				if ($wpdb->last_error) {
					wc_get_logger()->error(
						"Error creating table {$table_name}: " . $wpdb->last_error,
						['source' => 'simpleso-payment-gateway']
					);
				}


				try {
					$cache_key   = 'order_uuid_' . $order_id;
					$cache_group = 'simpleso_order_uuid';

					// Try to get the result from the cache
					$existing_uuid = wp_cache_get($cache_key, $cache_group);

					if (false === $existing_uuid) {
						// Validate table name to avoid injection
						if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
							throw new Exception('Invalid table name');
						}

						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This query is for a custom table, and no higher-level WordPress API exists to retrieve data from it.
						$existing_uuid = $wpdb->get_row(
							$wpdb->prepare(
								// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is validated via preg_match and not passed as a placeholder as prepare() does not handle identifiers.
								"SELECT uuid FROM " . $table_name . " WHERE order_id = %d ORDER BY id DESC",
								$order_id
							)
						);

						// Cache result for 1 hour
						wp_cache_set($cache_key, $existing_uuid, $cache_group, 3600);
					}

					if (!empty($existing_uuid)) {

						wc_get_logger()->info(
							'existing_uuid: ' . wp_json_encode($existing_uuid),
							['source' => 'simpleso-payment-gateway']
						);

						
						$old_uuid = sanitize_text_field($existing_uuid->uuid);

						// Cancel API call
						$apiPath  = '/api/cancel-order-link';
						$url      = $this->sip_protocol . $this->sip_host . $apiPath;
						$cleanUrl = esc_url_raw(preg_replace('#(?<!:)//+#', '/', $url));

						$response = wp_remote_post($cleanUrl, array(
							'method'    => 'POST',
							'timeout'   => 30,
							'body'      => wp_json_encode(array(
								'order_id'   => $order_id,
								'order_uuid' => $old_uuid,
								'status'     => 'canceled',
							)),
							'headers'   => array(
								'Content-Type' => 'application/json',
							),
							'sslverify' => true,
						));

						if (is_wp_error($response)) {
							wc_get_logger()->error(
								'Cancel API Error: ' . $response->get_error_message(),
								['source' => 'simpleso-payment-gateway']
							);
						} else {
							$body = wp_remote_retrieve_body($response);
							wc_get_logger()->info(
								'Cancel API Response: ' . wp_json_encode($body),
								['source' => 'simpleso-payment-gateway']
							);
						}
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Safe use of $wpdb->insert() for custom table
					$inserted = $wpdb->insert(
						$table_name,
						[
							'order_id' => $order_id,
							'uuid'     => $uuid,
						],
						[
							'%d',
							'%s',
						]
					);

					if ($inserted !== false) {
						wc_get_logger()->info(
							"SimlpeSo: Inserted new payment link for order ID $order_id",
							['source' => 'simpleso-payment-gateway']
						);
					} else {
						wc_get_logger()->error(
							"SimlpeSo: Failed to insert uuid — WPDB error: " . $wpdb->last_error,
							['source' => 'simpleso-payment-gateway']
						);
					}
				} catch (Exception $e) {
					wc_get_logger()->error(
						"SimlpeSo: Exception during payment link handling: " . $e->getMessage(),
						['source' => 'simpleso-payment-gateway']
					);
				}


				// =====================/ end code for payment link /
				if (!empty($lock_key)) {
					$this->release_lock($lock_key);
				}
				return [
					'payment_link' => esc_url($response_data['data']['payment_link']),
					'result' => 'success',
				];
			}

			// **Handle Payment Failure**
			$error_message = isset($response_data['message']) ? sanitize_text_field($response_data['message']) : __('Payment failed.', 'simpleso-payment-gateway');
			wc_get_logger()->error("Payment failed on account '{$account['title']}':
            $error_message", ['source' => 'simpleso-payment-gateway']);
			// **Add Order Note for Failed Payment**
			$order->add_order_note(
				sprintf(
					/* translators: 1: Account title, 2: Error message. */
					esc_html__('Payment failed using account: %1$s. Error: %2$s', 'simpleso-payment-gateway'),
					esc_html($account['title']),
					esc_html($error_message)
				)
			);

			// Add WooCommerce error notice
			wc_add_notice(__('Payment error: ', 'simpleso-payment-gateway') . $error_message, 'error');
			if (!empty($lock_key)) {
				$this->release_lock($lock_key);
			}
			return ['result' => 'fail'];
		}
	}

	// Display the "Test Order" tag in admin order details
	public function simpleso_display_test_order_tag($order)
	{
		if (get_post_meta($order->get_id(), '_is_test_order', true)) {
			echo '<p><strong>' . esc_html__('Test Order', 'simpleso-payment-gateway') . '</strong></p>';
		}
	}

	private function simpleso_get_return_url_base()
	{
		return rest_url('/simpleso/v1/data');
	}

	private function simpleso_prepare_payment_data($order, $api_public_key, $api_secret)
	{
		$order_id = $order->get_id(); // Validate order ID
		// Check if sandbox mode is enabled
		$is_sandbox = $this->get_option('sandbox') === 'yes';

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
				[
					'order_id' => $order_id, // Include order ID or any other identifier
					'key' => $order->get_order_key(),
					'nonce' => wp_create_nonce('simpleso_payment_nonce'), // Create a nonce for verification
					'mode' => 'wp',
				],
				$this->simpleso_get_return_url_base() // Use the updated base URL method
			)
		);

		$ip_address = sanitize_text_field($this->simpleso_get_client_ip());

		if (empty($order_id)) {
			wc_get_logger()->error('Order ID is missing or invalid.', ['source' => 'simpleso-payment-gateway']);
			return ['result' => 'fail'];
		}

		// Create the meta data array
		$meta_data_array = [
			'order_id' => $order_id,
			'amount' => $amount,
			'source' => 'woocommerce',
		];

		// Log errors but continue processing
		foreach ($meta_data_array as $key => $value) {
			$meta_data_array[$key] = sanitize_text_field($value); // Sanitize each field
			if (is_object($value) || is_resource($value)) {
				wc_get_logger()->error('Invalid value for key ' . $key . ': ' . wp_json_encode($value), ['source' => 'simpleso-payment-gateway']);
			}
		}

		return [
			'api_secret' => $api_secret, // Use sandbox or live secret key
			'api_public_key' => $api_public_key, // Add the public key for API calls
			'first_name' => $first_name,
			'last_name' => $last_name,
			'request_for' => $request_for,
			'amount' => $amount,
			'redirect_url' => $redirect_url,
			'redirect_time' => 3,
			'ip_address' => $ip_address,
			'source' => 'wordpress',
			'meta_data' => $meta_data_array,
			'remarks' => 'Order ' . $order->get_order_number(),
			// Add billing address details to the request
			'billing_address_1' => $billing_address_1,
			'billing_address_2' => $billing_address_2,
			'billing_city' => $billing_city,
			'billing_postcode' => $billing_postcode,
			'billing_country' => $billing_country,
			'billing_state' => $billing_state,
			'is_sandbox' => $is_sandbox,
		];
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
        <p>' .
			esc_html__('SimpleSo Payment Gateway requires WooCommerce to be installed and active.', 'simpleso-payment-gateway') .
			'</p>
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
                    <input type="checkbox" id="simpleso_consent" name="simpleso_consent" /> ' .
				esc_html__('I consent to the collection of my data to process this payment', 'simpleso-payment-gateway') .
				'
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
		// Check for SQL injection attempts
		if (!$this->check_for_sql_injection()) {
			return false;
		}
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
				[], // Dependencies (if any)
				'1.0', // Version number
				'all' // Media
			);

			// Enqueue simpleso.js script
			wp_enqueue_script(
				'simpleso-js',
				plugins_url('../assets/js/simpleso.js', __FILE__),
				['jquery'], // Dependencies
				'1.0', // Version number
				true // Load in footer
			);

			// Localize script with parameters that need to be passed to simpleso.js
			wp_localize_script('simpleso-js', 'simpleso_params', [
				'ajax_url' => admin_url('admin-ajax.php'),
				'checkout_url' => wc_get_checkout_url(),
				'simpleso_loader' => plugins_url('../assets/images/loader.gif', __FILE__),
				'simpleso_nonce' => wp_create_nonce('simpleso_payment'), // Create a nonce for verification
				'payment_method' => $this->id,
			]);
		}
	}

	function simpleso_admin_scripts($hook)
	{
		if ('woocommerce_page_wc-settings' !== $hook) {
			return; // Only load on WooCommerce settings page
		}

		// Enqueue Admin CSS
		wp_enqueue_style('simpleso-font-awesome', plugins_url('../assets/css/font-awesome.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/font-awesome.css'), 'all');

		// Enqueue Admin CSS
		wp_enqueue_style('simpleso-admin-css', plugins_url('../assets/css/admin.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/admin.css'), 'all');

		// Register and enqueue your script
		wp_enqueue_script('simpleso-admin-script', plugins_url('../assets/js/simpleso-admin.js', __FILE__), ['jquery'], filemtime(plugin_dir_path(__FILE__) . '../assets/js/simpleso-admin.js'), true);



		// Localize the script to pass parameters
		wp_localize_script('simpleso-admin-script', 'params', [
			'PAYMENT_CODE' => $this->id,
		]);
		wp_localize_script('simpleso-admin-script', 'simpleso_ajax_object', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('simpleso_sync_nonce'),
		]);
	}

	public function hide_custom_payment_gateway_conditionally($available_gateways)
	{
		$gateway_id = self::ID;

		if (is_checkout()) {
			// Force refresh WooCommerce session cache
			WC()->session->set('simpleso_gateway_hidden_logged', false);
			WC()->session->set('simpleso_gateway_status', '');

			// Retrieve cached gateway status (to prevent redundant API calls)
			$gateway_status = WC()->session->get('simpleso_gateway_status');

			if ($gateway_status === 'hidden') {
				unset($available_gateways[$gateway_id]);
				return $available_gateways;
			} elseif ($gateway_status === 'visible') {
				return $available_gateways;
			}

			// Get cart total amount
			$amount = number_format(WC()->cart->get_total('edit'), 2, '.', '');

			if (!method_exists($this, 'get_all_accounts')) {
				wc_get_logger()->error('Method get_all_accounts() is missing!', ['source' => 'simpleso-payment-gateway']);
				return $available_gateways;
			}

			$accounts = $this->get_all_accounts();

			if (empty($accounts)) {
				//wc_get_logger()->warning('No accounts available. Hiding payment gateway.', ['source' => 'simpleso-payment-gateway']);
				unset($available_gateways[$gateway_id]); // Unset gateway properly
				WC()->session->set('simpleso_gateway_status', 'hidden');
				return $available_gateways; // Return updated list
			}

			// Sort accounts by priority (higher priority first)
			usort($accounts, function ($a, $b) {
				return $a['priority'] <=> $b['priority'];
			});

			$all_high_priority_accounts_limited = true;

			// Clear Transient Cache Before Checking API Limits
			// Use delete_transient instead of direct database query
			delete_transient('_simpleso_daily_limit'); // Adjusted transient key pattern if needed

			foreach ($accounts as $account) {
				$public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
				$transactionLimitApiUrl = $this->get_api_url('/api/dailylimit');

				$data = [
					'is_sandbox' => $this->sandbox,
					'amount' => $amount,
					'api_public_key' => $public_key,
				];

				// Cache key to avoid redundant API requests
				$cache_key = 'simpleso_daily_limit_' . md5($public_key . $amount);
				$transaction_limit_response_data = $this->get_cached_api_response($transactionLimitApiUrl, $data, $cache_key);

				if (!isset($transaction_limit_response_data['error'])) {
					// At least one high-priority account is available
					$all_high_priority_accounts_limited = false;
					break; // Stop checking after the first valid account
				}
			}

			if ($all_high_priority_accounts_limited) {
				if (!WC()->session->get('simpleso_gateway_hidden_logged')) {
					wc_get_logger()->warning('All high-priority accounts have reached transaction limits. Hiding gateway.', ['source' => 'simpleso-payment-gateway']);
					WC()->session->set('simpleso_gateway_hidden_logged', true);
				}

				unset($available_gateways[$gateway_id]);
				WC()->session->set('simpleso_gateway_status', 'hidden');
			} else {
				WC()->session->set('simpleso_gateway_status', 'visible');
			}
		}

		return $available_gateways;
	}

	/**
	 * Validate an individual account.
	 *
	 * @param array $account The account data to validate.
	 * @param int $index The index of the account (for error messages).
	 * @return bool|string True if valid, error message if invalid.
	 */
	protected function validate_account($account, $index)
	{
		$is_empty = empty($account['title']) && empty($account['sandbox_public_key']) && empty($account['sandbox_secret_key']) && empty($account['live_public_key']) && empty($account['live_secret_key']);
		$is_filled = !empty($account['title']) && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']) && !empty($account['live_public_key']) && !empty($account['live_secret_key']);

		if (!$is_empty && !$is_filled) {
			/* Translators: %d is the keys are valid or leave empty.*/
			return sprintf(__('Account %d is invalid. Please fill all fields or leave the account empty.', 'simpleso-payment-gateway'), $index + 1);
		}

		return true;
	}

	/**
	 * Validate all accounts.
	 *
	 * @param array $accounts The list of accounts to validate.
	 * @return bool|string True if valid, error message if invalid.
	 */
	protected function validate_accounts($accounts)
	{
		$valid_accounts = [];
		$errors = [];

		foreach ($accounts as $index => $account) {
			// Check if the account is completely empty
			$is_empty = empty($account['title']) && empty($account['sandbox_public_key']) && empty($account['sandbox_secret_key']) && empty($account['live_public_key']) && empty($account['live_secret_key']);

			// Check if the account is completely filled
			$is_filled = !empty($account['title']) && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']) && !empty($account['live_public_key']) && !empty($account['live_secret_key']);

			// If the account is neither empty nor fully filled, it's invalid
			if (!$is_empty && !$is_filled) {
				/* Translators: %d is the keys are valid or leave empty.*/
				$errors[] = sprintf(__('Account %d is invalid. Please fill all fields or leave the account empty.', 'simpleso-payment-gateway'), $index + 1);
			} elseif ($is_filled) {
				// If the account is fully filled, add it to the valid accounts array
				$valid_accounts[] = $account;
			}
		}

		// If there are validation errors, return them
		if (!empty($errors)) {
			return ['errors' => $errors, 'valid_accounts' => $valid_accounts];
		}

		// If no errors, return the valid accounts
		return ['valid_accounts' => $valid_accounts];
	}

	private function get_cached_api_response($url, $data, $cache_key)
	{
		// Check if the response is already cached
		$cached_response = get_transient($cache_key);

		if ($cached_response !== false) {
			return $cached_response;
		}

		// Make the API call
		$response = wp_remote_post($url, [
			'method' => 'POST',
			'timeout' => 30,
			'body' => $data,
			'headers' => [
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Authorization' => 'Bearer ' . $data['api_public_key'],
			],
			'sslverify' => true,
		]);

		if (is_wp_error($response)) {
			return false;
		}

		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		// Cache the response for 2 minutes
		set_transient($cache_key, $response_data, 2 * MINUTE_IN_SECONDS);

		return $response_data;
	}

	private function get_all_accounts()
	{
		$accounts = get_option('woocommerce_simpleso_payment_gateway_accounts', []);

		// Try to unserialize if it's a string
		if (is_string($accounts)) {
			$unserialized = maybe_unserialize($accounts);
			$accounts = is_array($unserialized) ? $unserialized : [];
		}


		$valid_accounts = [];

		if (!empty($accounts)) {
			foreach ($accounts as $account) {
				// If in sandbox mode, check sandbox status and keys
				if ($this->sandbox) {
					if (isset($account['sandbox_status']) && $account['sandbox_status'] === 'active') {
						if (!empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) {
							$valid_accounts[] = $account;
						}
					}
				}
				// If in live mode, check live status and keys
				else {
					if (isset($account['live_status']) && $account['live_status'] === 'active') {
						if (!empty($account['live_public_key']) && !empty($account['live_secret_key'])) {
							$valid_accounts[] = $account;
						}
					}
				}
			}
		}

		$this->accounts = $valid_accounts;
		return $this->accounts;
	}


	function simpleso_enqueue_admin_styles($hook)
	{
		// Load only on WooCommerce settings pages
		if (strpos($hook, 'woocommerce') === false) {
			return;
		}

		wp_enqueue_style('simpleso-admin-style', plugin_dir_url(__FILE__) . 'assets/css/admin-style.css', [], '1.0.0');
	}

	/**
	 * Send an email notification via SimpleSo API
	 */
	private function send_account_switch_email($oldAccount, $newAccount)
	{
		$simlpeSoApiUrl = $this->get_api_url('/api/switch-account-email'); // SimpleSo API Endpoint

		// Use the credentials of the old (current) account to authenticate
		$api_key = $this->sandbox ? $oldAccount['sandbox_public_key'] : $oldAccount['live_public_key'];
		$api_secret = $this->sandbox ? $oldAccount['sandbox_secret_key'] : $oldAccount['live_secret_key'];

		// Prepare data for API request
		$emailData = [
			'old_account' => [
				'title' => $oldAccount['title'],
				'secret_key' => $api_secret,
			],
			'new_account' => [
				'title' => $newAccount['title'],
			],
			'message' => "Payment processing account has been switched. Please review the details.",
		];
		$emailData['is_sandbox'] = $this->sandbox;

		// API request headers using old account credentials
		$headers = [
			'Content-Type' => 'application/json',
			'Authorization' => 'Bearer ' . sanitize_text_field($api_key),
		];

		// Log API request details
		//wc_get_logger()->info('Request Data: ' . json_encode($emailData), ['source' => 'simpleso-payment-gateway']);

		// Send data to SimlpeSo API
		$response = wp_remote_post($simlpeSoApiUrl, [
			'method' => 'POST',
			'timeout' => 30,
			'body' => json_encode($emailData),
			'headers' => $headers,
			'sslverify' => true,
		]);

		// Handle API response
		if (is_wp_error($response)) {
			wc_get_logger()->error('Failed to send switch email: ' . $response->get_error_message(), ['source' => 'simpleso-payment-gateway']);
			return false;
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		// Check if authentication failed
		if ($response_code == 401 || $response_code == 403 || (!empty($response_data['error']) && strpos($response_data['error'], 'invalid credentials') !== false)) {
			wc_get_logger()->error('Email Sending Failed : Authentication failed: Invalid API key or secret for old account', ['source' => 'simpleso-payment-gateway']);
			return false; // Stop further execution
		}

		// Check if the API response has errors
		if (!empty($response_data['error'])) {
			wc_get_logger()->error('SimlpeSo API Error: ' . json_encode($response_data), ['source' => 'simpleso-payment-gateway']);
			return false;
		}

		wc_get_logger()->info("Switch email successfully sent to: '{$oldAccount['title']}'", ['source' => 'simpleso-payment-gateway']);
		return true;
	}

	/**
	 * Get the next available payment account, handling concurrency.
	 */
	private function get_next_available_account($used_accounts = [])
	{
		global $wpdb;

		// Fetch all accounts ordered by priority
		$settings = get_option('woocommerce_simpleso_payment_gateway_accounts', []);

		if (is_string($settings)) {
			$settings = maybe_unserialize($settings);
		}

		if (!is_array($settings)) {
			return false;
		}

		$mode = $this->sandbox ? 'sandbox' : 'live';
		$status_key = $mode . '_status';
		$public_key = $mode . '_public_key';
		$secret_key = $mode . '_secret_key';

		// Filter out used accounts and check correct mode status & keys
		$available_accounts = array_filter($settings, function ($account) use ($used_accounts, $status_key, $public_key, $secret_key) {
			return !in_array($account['title'], $used_accounts)
				&& isset($account[$status_key]) && $account[$status_key] === 'active'
				&& !empty($account[$public_key]) && !empty($account[$secret_key]);
		});

		if (empty($available_accounts)) {
			return false;
		}

		// Sort by priority (lower number = higher priority)
		usort($available_accounts, function ($a, $b) {
			return $a['priority'] <=> $b['priority'];
		});

		// Concurrency Handling: Lock the selected account
		foreach ($available_accounts as $account) {
			$lock_key = "simpleso_lock_{$account['title']}";

			// Try to acquire lock
			if ($this->acquire_lock($lock_key)) {
				$account['lock_key'] = $lock_key;
				return $account;
			}
		}

		return false;
	}

	/**
	 * Acquire a lock to prevent concurrent access to the same account.
	 */
	private function acquire_lock($lock_key)
	{
		$lock_timeout = 10; // Lock expires after 10 seconds

		// Set lock expiry time
		$lock_value = time() + $lock_timeout;

		// Try to add or update the lock in the options table
		$result = update_option($lock_key, $lock_value, false); // 'false' ensures no autoload

		if (!$result) {
			// Log the error if update_option fails
			wc_get_logger()->error(
				"DB Error: Unable to acquire lock for '{$lock_key}'",
				['source' => 'simpleso-payment-gateway']
			);
			return false; // Lock acquisition failed
		}

		// Log successful lock acquisition
		wc_get_logger()->info("Lock acquired for '{$lock_key}'", ['source' => 'simpleso-payment-gateway']);

		return true;
	}


	/**
	 * Release a lock after payment processing is complete.
	 */
	private function release_lock($lock_key)
	{
		// Delete the lock entry using WordPress options API
		delete_option($lock_key);

		// Log the release of the lock
		wc_get_logger()->info("Released lock for '{$lock_key}'", ['source' => 'simpleso-payment-gateway']);
	}


	function check_for_sql_injection()
	{
		
		$sql_injection_patterns = ['/\b(SELECT|INSERT|UPDATE|DELETE|DROP|ALTER)\b(?![^{}]*})/i', '/(\-\-|\#|\/\*|\*\/)/i', '/(\b(AND|OR)\b\s*\d+\s*[=<>])/i'];

		$errors = []; // Store multiple errors

		// Get checkout fields dynamically
		$checkout_fields = WC()
			->checkout()
			->get_checkout_fields();

		foreach ($_POST as $key => $value) {
			if (is_string($value)) {
				foreach ($sql_injection_patterns as $pattern) {
					if (preg_match($pattern, $value)) {
						// Get the field label dynamically
						$field_label = isset($checkout_fields['billing'][$key]['label'])
							? $checkout_fields['billing'][$key]['label']
							: (isset($checkout_fields['shipping'][$key]['label'])
								? $checkout_fields['shipping'][$key]['label']
								: (isset($checkout_fields['account'][$key]['label'])
									? $checkout_fields['account'][$key]['label']
									: (isset($checkout_fields['order'][$key]['label'])
										? $checkout_fields['order'][$key]['label']
										: ucfirst(str_replace('_', ' ', $key)))));

						// Log error for debugging
						$ip_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
						wc_get_logger()->info(
							"Potential SQL Injection Attempt - Field: $field_label, Value: $value, IP: {$ip_address}",
							['source' => 'simpleso-payment-gateway']
						);
						// This comment must be directly above the i18n function call with no blank line
						/* translators: %s is the field label, like "Email Address" or "Username". */
						$errors[] = sprintf(esc_html__('Please enter a valid "%s".', 'simpleso-payment-gateway'), $field_label);
						break; // Stop checking other patterns for this field
					}
				}
			}
		}

		// Display all collected errors at once
		if (!empty($errors)) {
			foreach ($errors as $error) {
				wc_add_notice($error, 'error');
			}
			return false;
		}

		return true;
	}


}
