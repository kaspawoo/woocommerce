<?php
/**
 * Plugin Name: Kaspa Payments Gateway for WooCommerce
 * Plugin URI: https://kaspawoo.com/
 * Description: Accept Kaspa (KAS) cryptocurrency payments in WooCommerce with automatic order confirmation and real-time verification. KPUB watch-only wallet for secure, non-custodial payments. This plugin is not officially affiliated with Kaspa or WooCommerce.
 * Version: 1.3.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Tested up to: 6.9.4
 * Author: KaspaWoo
 * Author URI: https://kaspawoo.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Copyright (C) 2024–2025 Jorbach
 * Text Domain: kaspa-payments-gateway-woocommerce
 * Requires Plugins: woocommerce
 * WC requires at least: 3.0
 * WC tested up to: 10.6.1
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * DEDICATED PAYMENT PAGE FUNCTIONALITY
 */

// Add rewrite rule for payment page
add_action('init', 'kasppaga_add_payment_rewrite_rule');
function kasppaga_add_payment_rewrite_rule()
{
    add_rewrite_rule(
        '^kaspa-payment/([0-9]+)/([a-zA-Z0-9_]+)/?$',
        'index.php?kaspa_payment_page=1&order_id=$matches[1]&order_key=$matches[2]',
        'top'
    );
}

// Add query vars
add_filter('query_vars', 'kasppaga_add_payment_query_vars');
function kasppaga_add_payment_query_vars($vars)
{
    $vars[] = 'kaspa_payment_page';
    $vars[] = 'order_id';
    $vars[] = 'order_key';
    return $vars;
}

// Handle the payment page
add_action('template_redirect', 'kasppaga_handle_dedicated_payment_page');
function kasppaga_handle_dedicated_payment_page()
{
    if (get_query_var('kaspa_payment_page')) {
        $order_id = intval(get_query_var('order_id'));
        $order_key = sanitize_text_field(get_query_var('order_key'));

        if (!$order_id || !$order_key) {
            wp_die('Invalid payment link.');
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_order_key() !== $order_key) {
            wp_die('Invalid order or order key.');
        }

        // Check if payment is already completed
        if (in_array($order->get_status(), ['processing', 'completed'])) {
            $gateway = new KASPPAGA_WC_Gateway();
            wp_safe_redirect($gateway->get_return_url($order));
            exit;
        }

        // Display the payment page
        kasppaga_display_dedicated_payment_page($order);
        exit;
    }
}

// Display the dedicated payment page
function kasppaga_display_dedicated_payment_page($order)
{
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>

    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Complete Kaspa Payment - Order #<?php echo esc_html($order->get_id()); ?></title>
        <?php
        // Load WordPress header scripts
        wp_head();

        // Enqueue wallet library
        wp_enqueue_script(
            'kaspa-wallet-bundle',
            plugin_dir_url(__FILE__) . 'assets/kaspa-wallet.js',
            array('jquery'),
            '1.0.0',
            true
        );
        ?>
        <style>
            body {
                margin: 0;
                padding: 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }

            .kaspa-payment-wrapper {
                min-height: 100vh;
                background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                padding: 40px 20px;
            }

            .kaspa-container {
                max-width: 900px;
                margin: 0 auto;
            }

            .kaspa-header {
                text-align: center;
                margin-bottom: 40px;
                background: rgba(255, 255, 255, 0.9);
                padding: 30px;
                border-radius: 20px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            }

            .kaspa-header h1 {
                font-size: 36px;
                margin-bottom: 16px;
                color: #1d2327;
            }

            .kaspa-header p {
                font-size: 18px;
                color: #666;
                margin: 0;
            }
        </style>
    </head>

    <body <?php body_class(); ?>>

        <div class="kaspa-payment-wrapper">
            <div class="kaspa-container">

                <!-- Page Header -->
                <div class="kaspa-header">
                    <h1>Complete Your Payment</h1>
                    <p>
                        Order #<?php echo esc_html($order->get_id()); ?> •
                        <?php echo wp_kses_post(wc_price($order->get_total())); ?>
                    </p>
                </div>

                <!-- Payment Interface -->
                <?php
                $gateway = new KASPPAGA_WC_Gateway();
                kasppaga_display_thankyou_page($order->get_id(), $gateway);
                ?>

            </div>
        </div>

        <?php wp_footer(); ?>
    </body>

    </html>
    <?php
}

/**
 * Main plugin class
 */
class KASPPAGA_Plugin
{
    public function __construct()
    {
        add_action('plugins_loaded', array($this, 'init'), 11);
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));

        // Register blocks integration for WooCommerce block checkout support
        add_action('woocommerce_blocks_loaded', array($this, 'register_blocks_integration'));
        // WebSocket AJAX hooks removed - using polling system instead

        // Add settings link to Plugins page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
    }

    /**
     * Add "Settings" link to Plugins page
     */
    public function add_settings_link($links)
    {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=kaspa') . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Initialize the plugin
     */
    public function init()
    {
        if (!class_exists('WooCommerce') || !class_exists('WC_Payment_Gateway')) {
            return;
        }

        $this->load_includes();
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));

        // Initialize polling system to ensure cron is scheduled
        if (class_exists('KASPPAGA_Transaction_Polling')) {
            new KASPPAGA_Transaction_Polling();
        }
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    /**
     * Load required files
     */
    private function load_includes()
    {
        $gateway_file = plugin_dir_path(__FILE__) . 'includes/class-wc-kaspa-gateway.php';
        $frontend_file = plugin_dir_path(__FILE__) . 'includes/kaspa-frontend-assets.php';
        $polling_file = plugin_dir_path(__FILE__) . 'includes/kaspa-transaction-polling.php';
        $admin_file = plugin_dir_path(__FILE__) . 'includes/class-kaspa-admin-dashboard.php';
        $xpub_setup_file = plugin_dir_path(__FILE__) . 'includes/kaspa-wallet-setup.php';

        // Check if files exist before loading
        if (file_exists($gateway_file)) {
            require_once $gateway_file;
        } else {
            add_action('admin_notices', array($this, 'missing_files_notice'));
            error_log('Kaspa Gateway file not found at: ' . $gateway_file);
            return;
        }

        if (file_exists($frontend_file)) {
            require_once $frontend_file;
        } else {
            add_action('admin_notices', array($this, 'missing_files_notice'));
            error_log('Kaspa Frontend file not found at: ' . $frontend_file);
            return;
        }

        if (file_exists($polling_file)) {
            require_once $polling_file;
        } else {
            add_action('admin_notices', array($this, 'missing_files_notice'));
            error_log('Kaspa Polling file not found at: ' . $polling_file);
            return;
        }

        if (file_exists($admin_file)) {
            require_once $admin_file;
        } else {
            add_action('admin_notices', array($this, 'missing_files_notice'));
            error_log('Kaspa Admin file not found at: ' . $admin_file);
            return;
        }

        if (file_exists($xpub_setup_file)) {
            require_once $xpub_setup_file;
        } else {
            add_action('admin_notices', array($this, 'missing_files_notice'));
            error_log('Kaspa Setup file not found at: ' . $xpub_setup_file);
            return;
        }

        // Register email class — require the file inside the filter callback
        // so WC_Email is guaranteed to exist when the class definition is loaded.
        add_filter('woocommerce_email_classes', array($this, 'register_kaspa_email'));
    }

    /**
     * Register the Kaspa order confirmation email with WooCommerce.
     * File is required here (not in load_includes) so WC_Email is already defined.
     */
    public function register_kaspa_email($email_classes)
    {
        $email_file = plugin_dir_path(__FILE__) . 'includes/class-kaspa-order-email.php';
        if (file_exists($email_file)) {
            require_once $email_file;
            $email_classes['KASPPAGA_Order_Email'] = new KASPPAGA_Order_Email();
        }
        return $email_classes;
    }

    /**
     * Show admin notice for missing files
     */
    public function missing_files_notice()
    {
        $gateway_file = plugin_dir_path(__FILE__) . 'includes/class-wc-kaspa-gateway.php';
        $frontend_file = plugin_dir_path(__FILE__) . 'includes/kaspa-frontend-assets.php';
        ?>
        <div class="notice notice-error">
            <p><strong>Kaspa Payments:</strong> Plugin files issue detected.</p>
            <p>Gateway file exists: <?php echo file_exists($gateway_file) ? 'YES' : 'NO'; ?>
                (<?php echo esc_html($gateway_file); ?>)</p>
            <p>Frontend file exists: <?php echo file_exists($frontend_file) ? 'YES' : 'NO'; ?>
                (<?php echo esc_html($frontend_file); ?>)
            </p>
        </div>
        <?php
    }

    /**
     * Add gateway to WooCommerce
     */
    public function add_gateway($methods)
    {
        if (class_exists('KASPPAGA_WC_Gateway')) {
            $methods[] = 'KASPPAGA_WC_Gateway';
        }
        return $methods;
    }

    /**
     * Register streamlined blocks integration for both classic and block checkout
     */
    public function register_blocks_integration()
    {
        // Only register once
        static $registered = false;
        if ($registered) {
            return;
        }

        // Check if required classes exist
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            // Blocks not available, skip silently
            return;
        }

        // Load the blocks integration class
        kasppaga_load_blocks_integration_class();

        // Register with WooCommerce Blocks
        add_action('woocommerce_blocks_payment_method_type_registration', function ($payment_method_registry) {
            if (class_exists('Kaspa_Blocks_Integration')) {
                $integration = new Kaspa_Blocks_Integration();
                $payment_method_registry->register($integration);
            }
        });

        $registered = true;
    }
}

/**
 * Load streamlined blocks integration class
 */
function kasppaga_load_blocks_integration_class()
{
    if (class_exists('Kaspa_Blocks_Integration')) {
        return; // Already loaded
    }

    class Kaspa_Blocks_Integration extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
    {
        protected $name = 'kaspa';
        protected $settings = array();

        public function initialize()
        {
            $this->settings = get_option('woocommerce_kaspa_settings', array());

            // Add settings data for JavaScript
            add_action('wp_enqueue_scripts', array($this, 'add_payment_method_data'), 20);
        }

        /**
         * Add payment method data to JavaScript (for blocks checkout)
         */
        public function add_payment_method_data()
        {
            // Only on checkout pages
            if (!is_checkout() && !has_block('woocommerce/checkout')) {
                return;
            }

            // Add the settings data for blocks
            $data = $this->get_payment_method_data();

            wp_add_inline_script(
                'wc-settings',
                'window.wc = window.wc || {}; window.wc.wcSettings = window.wc.wcSettings || {}; window.wc.wcSettings.allSettings = window.wc.wcSettings.allSettings || {}; window.wc.wcSettings.allSettings.kaspa_data = ' . wp_json_encode($data) . ';',
                'after'
            );
        }

        public function is_active()
        {
            $is_enabled = isset($this->settings['enabled']) && $this->settings['enabled'] === 'yes';
            $wallet_configured = get_option('kasppaga_wallet_configured', false);
            return $is_enabled && $wallet_configured;
        }

        public function get_payment_method_script_handles()
        {
            // Use the assets/js/index.js file
            $script_path = plugin_dir_path(__FILE__) . 'assets/js/index.js';
            $script_url = plugin_dir_url(__FILE__) . 'assets/js/index.js';
            $asset_path = plugin_dir_path(__FILE__) . 'assets/js/index.asset.php';

            // Check if files exist
            if (!file_exists($script_path)) {
                return array();
            }

            $asset_file = file_exists($asset_path)
                ? require($asset_path)
                : array(
                    'dependencies' => array('wc-blocks-registry', 'wp-element', 'wp-i18n', 'wc-settings'),
                    'version' => '1.0.2'
                );

            wp_register_script(
                'kaspa-blocks-integration',
                $script_url,
                $asset_file['dependencies'],
                $asset_file['version'],
                true
            );

            return array('kaspa-blocks-integration');
        }

        public function get_payment_method_data()
        {
            return array(
                'title' => isset($this->settings['title']) ? $this->settings['title'] : 'Kaspa (KAS)',
                'description' => isset($this->settings['description']) ? $this->settings['description'] : 'Pay with Kaspa cryptocurrency. Fast and secure.',
                'supports' => array(
                    'features' => array('products')
                )
            );
        }
    }
}

// Initialize the plugin
new KASPPAGA_Plugin();

/**
 * Plugin activation hook - flush rewrite rules for custom payment page URL
 */
register_activation_hook(__FILE__, 'kasppaga_plugin_activate');
function kasppaga_plugin_activate()
{
    // Add the rewrite rule first
    kasppaga_add_payment_rewrite_rule();
    // Then flush to register it
    flush_rewrite_rules();
}

/**
 * Plugin deactivation hook - clean up rewrite rules
 */
register_deactivation_hook(__FILE__, 'kasppaga_plugin_deactivate');
function kasppaga_plugin_deactivate()
{
    wp_clear_scheduled_hook('kasppaga_poll_payments');
    flush_rewrite_rules();
}