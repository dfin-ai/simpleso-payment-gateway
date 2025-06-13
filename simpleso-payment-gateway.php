<?php

/**
 * Plugin Name: SimpleSo Payment Gateway
 * Description: This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security.
 * Author: SimpleSo
 * Author URI: https://www.simpleso.io
 * Text Domain: simpleso-payment-gateway
 * Plugin URI: https://github.com/dfin-ai/simpleso-payment-gateway
 * Version: 1.0.0
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Copyright (c) 2024 SimpleSo
 */

if (!defined('ABSPATH')) {
	exit;
}

define('SIMPLESO_PAYMENT_GATEWAY_MIN_PHP_VER', '8.0');
define('SIMPLESO_PAYMENT_GATEWAY_MIN_WC_VER', '6.5.4');
define('SIMPLESO_PAYMENT_GATEWAY_FILE', __FILE__);
define('SIMPLESO_PAYMENT_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Include utility functions
require_once SIMPLESO_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/simpleso-payment-gateway-utils.php';

// Migrations functions
include_once plugin_dir_path(__FILE__) . 'migration.php';

// Autoload classes
spl_autoload_register(function ($class) {
	if (strpos($class, 'SIMPLESO_PAYMENT_GATEWAY') === 0) {
		$class_file = SIMPLESO_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-' . str_replace('_', '-', strtolower($class)) . '.php';
		if (file_exists($class_file)) {
			require_once $class_file;
		}
	}
});

SIMPLESO_PAYMENT_GATEWAY_Loader::get_instance();

add_action('woocommerce_cancel_unpaid_order', 'ss_cancel_unpaid_order_action');
add_action('woocommerce_order_status_cancelled', 'ss_cancel_unpaid_order_action');

function ss_cancel_unpaid_order_action($order_id)
{
	global $wpdb;

	if (empty($order_id) || !is_numeric($order_id) || $order_id <= 0) {
		return;
	}

	$order = wc_get_order($order_id);
	
	// If order_id is not provided or invalid, find the latest 'shop_order_placehold'
	if (! $order_id || ! is_numeric($order_id)) {
		$args = array(
			'post_type'      => 'shop_order_placehold',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);

		$placeholder_orders = get_posts($args);

		if (! empty($placeholder_orders)) {
			$order_id = $placeholder_orders[0];
			wc_get_logger()->info('Auto-fetched latest unpaid order ID: ' . $order_id, ['source' => 'simpleso-payment-gateway']);
		} else {
			wc_get_logger()->error('Error: No unpaid placeholder orders found.', ['source' => 'simpleso-payment-gateway']);
			return;
		}
	}

	$order = wc_get_order($order_id);
	if (!$order) {
		wc_get_logger()->error('Error: No unpaid orders found.', ['source' => 'simpleso-payment-gateway']);
		return;
	}

	$pending_time = get_post_meta($order_id, '_pending_order_time', true);
	$pending_time = is_numeric($pending_time) ? (int) $pending_time : 0;

	if ($order->has_status('pending')) {
		if ((time() - $pending_time) < (30 * 60)) {
			wc_get_logger()->info("Order {$order_id} is still pending and not timed out, skipping cancel API.", ['source' => 'simpleso-payment-gateway']);
			return;
		}

		// Cancel order and reduce stock if timeout occurred
		$order->update_status('cancelled', 'Order automatically cancelled due to unpaid timeout.');
		wc_reduce_stock_levels($order_id);

		// Invalidate cache
		$cache_key = 'simpleso_payment_link_uuid_' . $order_id;
		wp_cache_delete($cache_key, 'simpleso_payment_gateway');
	}

	// ========================== start code for expiring payment link ==========================
	$table_name = $wpdb->prefix . 'order_payment_link';

	// BEFORE this code block, ENSURE you have this crucial validation for $table_name:
	if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
		throw new Exception('Invalid table name');
	}

	$latest_uuid = null;
	$cache_key   = 'simpleso_payment_link_uuid_' . $order_id;
	$cache_group = 'simpleso_payment_gateway'; // A unique group name for your plugin's cache

	// Try to get from cache first
	$cached_uuid_data = wp_cache_get($cache_key, $cache_group);

	if (false !== $cached_uuid_data) {
		$latest_uuid = (object) $cached_uuid_data; // Cast back to object if it was stored as array/object
		wc_get_logger()->info('latest uuid (from cache) - ' . $latest_uuid->uuid, ['source' => 'simpleso-payment-gateway']);
	} else {
		// If not in cache, query the database
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This query is for a custom table, and no higher-level WordPress API exists to retrieve data from it.
		$latest_uuid = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is validated via preg_match and cannot be prepared by $wpdb->prepare() as it's an identifier.
				"SELECT uuid FROM " . $table_name . " WHERE order_id = %d ORDER BY id DESC",
				$order_id
			)
		);

		// Store in cache
		if ($latest_uuid) {
			wp_cache_set($cache_key, $latest_uuid, $cache_group, HOUR_IN_SECONDS); // Cache for 1 hour
			wc_get_logger()->info('latest uuid (from DB, cached) - ' . $latest_uuid->uuid, ['source' => 'simpleso-payment-gateway']);
		}
	}

	if (empty($latest_uuid?->uuid)) {
		wc_get_logger()->error(
			'No record found for order ID - ' . (int) $order_id,
			['source' => 'simpleso-payment-gateway']
		);
		return;
	}

	$encoded_uuid_from_db = sanitize_text_field($latest_uuid->uuid);

	// Call cancel API
	$apiPath = '/api/cancel-order-link';
	$url = SS_PROTOCOL . SS_HOST . $apiPath;
	$cleanUrl = esc_url(preg_replace('#(?<!:)//+#', '/', $url));

	$response = wp_remote_post($cleanUrl, array(
		'method'    => 'POST',
		'timeout'   => 30,
		'body'      => json_encode(array(
			'order_id'     => $order_id,
			'order_uuid'   => $encoded_uuid_from_db,
			'status'       => 'canceled'
		)),
		'headers'   => array(
			'Content-Type' => 'application/json',
		),
		'sslverify' => true,
	));

	if (is_wp_error($response)) {
		wc_get_logger()->error('Cancel API Error: ' . $response->get_error_message(), ['source' => 'simpleso-payment-gateway']);
	} else {
		$response_body = wp_remote_retrieve_body($response);
		$response_json = is_array($response_body) || is_object($response_body)
			? json_encode($response_body)
			: $response_body;
		wc_get_logger()->info('Cancel API Response: ' . $response_json, ['source' => 'simpleso-payment-gateway']);
	}
	// ========================== end code for expiring payment link ==========================
}
