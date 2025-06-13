<?php

function simpleso_migrate_old_settings() {
    // First, check if beta option exists
    $beta_accounts = get_option('woocommerce_simpleso_payment_gateway_accounts');

    if ($beta_accounts) {
        $beta_accounts = maybe_unserialize($beta_accounts);

        if (is_array($beta_accounts) && !empty($beta_accounts)) {
            // Enhance each account to ensure all required keys exist
            $enhanced_accounts = array_map(function ($account) {
                $account['live_status'] = $account['live_status'] ?? 'active';
                $account['sandbox_status'] = $account['sandbox_status'] ?? 'active';
                $account['has_sandbox'] = (!empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) ? 'on' : 'off';
                $account['priority'] = $account['priority'] ?? 1;
                $account['title'] = $account['title'] ?? 'Default Account';
                return $account;
            }, $beta_accounts);

            // Save updated accounts back
            update_option('woocommerce_simpleso_payment_gateway_accounts', serialize($enhanced_accounts));
            simpleso_trigger_sync();
            return; // Migration complete for beta
        }
    }

    // Fallback to legacy `woocommerce_simpleso_settings`
    $old_settings = get_option('woocommerce_simpleso_settings');
    $old_settings = maybe_unserialize($old_settings);
    if (!$old_settings || !is_array($old_settings)) {
        return; // Nothing to migrate
    }

    // Extract old settings
    $live_public_key = $old_settings['public_key'] ?? '';
    $live_secret_key = $old_settings['secret_key'] ?? '';
    $sandbox_public_key = $old_settings['sandbox_public_key'] ?? '';
    $sandbox_secret_key = $old_settings['sandbox_secret_key'] ?? '';
    $sandbox_enabled = isset($old_settings['sandbox']) && $old_settings['sandbox'] === 'yes';
    
    $has_sandbox = (!empty($sandbox_public_key) && !empty($sandbox_secret_key)) ? 'on' : 'off';
    $live_status = 'active';
    $sandbox_status = $sandbox_enabled ? 'active' : 'inactive';

    if (empty($live_public_key) && empty($live_secret_key) && empty($sandbox_public_key) && empty($sandbox_secret_key)) {
        return; // No keys to migrate
    }

    $new_accounts = [
        [
            'title' => 'Default Account',
            'priority' => 1,
            'live_public_key' => $live_public_key,
            'live_secret_key' => $live_secret_key,
            'sandbox_public_key' => $sandbox_public_key,
            'sandbox_secret_key' => $sandbox_secret_key,
            'has_sandbox' => $has_sandbox,
            'live_status' => $live_status,
            'sandbox_status' => $sandbox_status,
        ]
    ];

    update_option('woocommerce_simpleso_payment_gateway_accounts', serialize($new_accounts));
    simpleso_trigger_sync();
}


function simpleso_trigger_sync() {
    if (class_exists('SIMPLESO_PAYMENT_GATEWAY_Loader')) {
        $loader = SIMPLESO_PAYMENT_GATEWAY_Loader::get_instance();
        if (method_exists($loader, 'handle_cron_event')) {
            wc_get_logger()->info('SimpleSo sync account for migrations', ['source' => 'simpleso-payment-gateway']);
            $loader->handle_cron_event();
        }
    }
}


// Hook migration to plugin activation
register_activation_hook(SIMPLESO_PAYMENT_GATEWAY_FILE, 'simpleso_migrate_old_settings');
