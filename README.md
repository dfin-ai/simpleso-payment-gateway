# Simple So Payment Gateway

The Simple So Payment Gateway plugin for WooCommerce 8.9+ allows you to accept fiat payments to sell products on your WooCommerce store.

## Plugin Information

**Contributors:** Simple So  
**Tags:** woocommerce, payment gateway, fiat, Simple So  
**Requires at least:** 6.2  
**Tested up to:** 6.7  
**Stable tag:** 1.0.0   
**License:** GPLv3 or later  
**License URI:** [GPLv3 License](https://www.gnu.org/licenses/gpl-3.0.html)

## Support

For any issues or enhancement requests with this plugin, please contact the SimpleSo support team. Ensure you provide your plugin, WooCommerce, and WordPress version where applicable to expedite troubleshooting.

## Getting Started

1. Obtain your API keys from your SimpleSo dashboard in your Developer Settings - API Keys.
2. Follow the plugin installation instructions below.
3. You are ready to take payments in your WooCommerce store!

## Installation

### Minimum Requirements

- WooCommerce 8.9 or greater
- PHP version 8.0 or greater

### Steps

## 1. Download Plugin from GitHub

- Visit the GitHub repository for the Simple So Payment Gateway plugin at [GitHub Repository URL](https://github.com/dfin-ai/simpleso-payment-gateway).
- Download the plugin ZIP file to your local machine.

## 2. Install the Plugin in WordPress

- **Extract the Downloaded ZIP file to your Local Machine:**
  Extract the ZIP file containing the plugin files.

- **Upload via FTP or File Manager:**
  - Connect to your WordPress site via FTP or use File Manager in your hosting control panel.
  - Navigate to `wp-content/plugins` directory.
  - Upload the extracted plugin folder to the plugins directory on your server.

## 3. Activate the Plugin

- **Log in to WordPress Admin Dashboard:**
  Log in to your WordPress Admin Dashboard.
- **Navigate to Installed Plugins:**
  Go to `Plugins` > `Installed Plugins`.
- **Activate Simple So Payment Gateway:**
  - Locate the Simple So Payment Gateway plugin in the list.
  - Click `Activate` to enable the plugin.

## 4. Obtain API Keys from SimpleSo Developer Settings Dashboard

- **Log in to SimpleSo Account:**
  Visit the SimpleSo website and log in to your account.
- **Navigate to Developer Settings to get API Keys:**
  Once logged in, find and access the Developer Settings.
- **Generate or Retrieve API Keys:**
  If API keys are not already generated, you can create new ones.
  Locate the API Keys or Credentials section.
  Generate or retrieve the required API keys (e.g., Public Key, Secret Key) needed for integration with the Simple So Payment Gateway plugin.

## 5. Update API Keys in WooCommerce Settings

- **Navigate to WooCommerce Settings:**
  Log in to your WordPress Admin Dashboard.
  Go to `WooCommerce` > `Settings`.
- **Access the Payments Tab:**
  Click on the `Payments` tab at the top of the settings page.
- **Select Simple So Payment Gateway:**
  Scroll down to find and select the Simple So Payment Gateway among the available payment methods.

- **Add Plugin General Details:**
    - **Title** : Simple So Payment Gateway
    Description
    - **Description** : Secure payments with Simple So Payment Gateway.
    - **Enable/Disable Sandbox Mode** : Toggle sandbox mode per account.
    - **Payment Accounts (Add Multiple Accounts)** : 
        - **Adding a New Account**
            1. Click on **Add Account** to create a new account.
            2. Enter a **unique account title**.
            3. Provide details for each account:
                - *Account Title*
                - *Priority:* Set an order for the accounts.
                - *Live Mode:* Public & Secret Keys (mandatory)
                - *Sandbox Mode:* Public & Secret Keys (optional)
    - **Order Status** : Select Processing or Completed.
    - **Show Consent Checkbox** : Enabling this option will display a consent checkbox on the checkout page.

- **Save Changes:**
  Click `Save changes` at the bottom of the page to update and save your API key settings.

## 6. Place Order via SimpleSo Payment Option

- **Visit Your Store Page and Add Products to Cart:**
  Navigate to your WordPress site's store page.
  Browse and add desired products to the cart.

- **Proceed to Checkout:**
  Go to your WordPress site's checkout page to review your order details.

- **Check Available Payment Methods:**
  Ensure that the Simple So Payment Gateway option is visible among the available payment methods listed on the checkout page.

- **Verify Integration:**
  Confirm that customers can select the Simple So Payment Gateway as a payment option when placing their orders.

## 7. Popup Window for Payment

- **Secure Payment Processing:**
  Upon selecting SimpleSo, a secure popup window will open for payment processing.

## 8. Complete the Payment Process

- **Follow Instructions:**
  Follow the instructions provided in the popup window to securely complete the payment.

## 9. Redirect to WordPress Website with Order Status

- **After Successful Payment:**
  Once the payment is successfully processed, the popup window will automatically close.
  Customers will be redirected back to your WordPress site.

## 10. Check Orders in WordPress

- **Verify Order Status:**
  Log in to your WordPress Admin Dashboard.
  Navigate to `WooCommerce` > `Orders` to view all orders.
  Check for the latest orders placed using the Simple So Payment Gateway to verify their status.

## Documentation

The official documentation for this plugin is available at: [https://www.simpleso.io/api/docs/wordpress-plugin](https://www.simpleso.io/api/docs/wordpress-plugin)

## Changelog

### Version 1.0.0 (Initial Release)

- **Initial Release:** Launched the Simple So Payment Gateway plugin with core payment integration functionality for WooCommerce.

## Support

For customer support, visit: [https://www.simpleso.io/reach-out](https://www.simpleso.io/reach-out)

## Why Choose Simple So Payment Gateway?

With the Simple So Payment Gateway, you can easily transfer fiat payments to sell products. Choose Simple So Payment Gateway as your WooCommerce payment gateway to access your funds quickly through a powerful and secure payment engine provided by SimpleSo.
