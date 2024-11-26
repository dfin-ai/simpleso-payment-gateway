<?php

/**
 * Plugin Name: SimpleSo Payment Gateway
 * Description: This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security.
 * Author: SimpleSo
 * Author URI: https://www.simpleso.io
 * Text Domain: simpleso-payment-gateway
 * Plugin URI: https://github.com/dfin-ai/simpleso-payment-gateway
 * Version: 1.0.8
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Copyright (c) 2024 DFin
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

// Autoload classes
spl_autoload_register(function ($class) {
	if (strpos($class, 'SIMPLESO_PAYMENT_GATEWAY_') === 0) {
		$class_file = SIMPLESO_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-' . str_replace('_', '-', strtolower($class)) . '.php';
		if (file_exists($class_file)) {
			require_once $class_file;
		}
	}
});

SIMPLESO_PAYMENT_GATEWAY_Loader::get_instance();
