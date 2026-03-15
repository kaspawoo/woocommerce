<?php
/**
 * Kaspa Gateway Class - KPUB Watch-Only (Secure)
 * 
 * Uses KPUB (Extended Public Key) watch-only wallets for security.
 * No private keys or mnemonics are stored on the server.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kaspa Payment Gateway - KPUB Watch-Only
 */
class KASPPAGA_WC_Gateway extends WC_Payment_Gateway
{
    protected $show_logo;

    public function __construct()
    {
        $this->id = 'kaspa';
        $this->method_title = 'Kaspa Payments Gateway (Watch-Only)';
        $this->method_description = 'Accept Kaspa (KAS) payments using a secure watch-only wallet (KPUB). No private keys stored.';
        $this->has_fields = false;
        $this->supports = array('products');

        // Initialize properties
        $this->show_logo = 'yes';

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Set values from settings
        $this->title = $this->get_option('title', 'Kaspa (KAS)');
        $this->description = $this->get_option('description', 'Pay with Kaspa cryptocurrency. Secure and fast payments.');
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->show_logo = $this->get_option('show_logo', 'yes');
        $this->brain_url    = $this->get_option('brain_url', '');
        $this->brain_secret = $this->get_option('brain_secret', '');

        // Pro feature settings
        $this->pro_fee_enabled  = $this->get_option('pro_fee_enabled', 'no');
        $this->pro_fee_type     = $this->get_option('pro_fee_type', 'percent');
        $this->pro_fee_amount   = (float) $this->get_option('pro_fee_amount', 0);
        $this->pro_accent_color = $this->get_option('pro_accent_color', '#49eacb');
        $this->pro_button_text  = $this->get_option('pro_button_text', '');
        $this->pro_instructions = $this->get_option('pro_instructions', '');

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_kaspa_payment_details'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        // AJAX handler for price testing moved to main plugin file
        // Balance checking moved to Kaspa plugin admin page

        add_action('template_redirect', array($this, 'handle_payment_page'));
        add_action('woocommerce_order_status_changed', array($this, 'handle_payment_completion'), 10, 3);
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable Kaspa Payments',
                'default' => 'yes'
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => 'Payment method title shown to customers.',
                'default' => 'Kaspa (KAS)',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'description' => 'Payment method description for customers.',
                'default' => 'Pay with Kaspa cryptocurrency. Secure and fast payments.',
                'desc_tip' => true,
            ),
            'wallet_status' => array(
                'title' => __('Wallet Status', 'kaspa-payments-gateway-woocommerce'),
                'type' => 'title',
                'description' => $this->get_wallet_status_html(),
            ),
            'show_logo' => array(
                'title' => 'Show Logo',
                'type' => 'checkbox',
                'label' => 'Display Kaspa logo next to payment method',
                'default' => 'yes'
            ),
            'price_api_heading' => array(
                'title' => __('Exchange rate source', 'kaspa-payments-gateway-woocommerce'),
                'type' => 'title',
                'description' => $this->get_price_api_description(),
            ),
            'price_api_primary' => array(
                'title' => __('1st choice', 'kaspa-payments-gateway-woocommerce'),
                'type' => 'select',
                'description' => __('Primary price source. Required.', 'kaspa-payments-gateway-woocommerce'),
                'default' => 'coingecko',
                'options' => $this->get_price_source_options(false),
                'desc_tip' => true,
            ),
            'price_api_secondary' => array(
                'title' => __('2nd choice', 'kaspa-payments-gateway-woocommerce'),
                'type' => 'select',
                'description' => __('Fallback if 1st fails.', 'kaspa-payments-gateway-woocommerce'),
                'default' => 'cryptocompare',
                'options' => $this->get_price_source_options(true),
                'desc_tip' => true,
            ),
            'price_api_tertiary' => array(
                'title' => __('3rd choice', 'kaspa-payments-gateway-woocommerce'),
                'type' => 'select',
                'description' => __('Fallback if 1st and 2nd fail.', 'kaspa-payments-gateway-woocommerce'),
                'default' => 'kaspa_api',
                'options' => $this->get_price_source_options(true),
                'desc_tip' => true,
            ),
            'brain_heading' => array(
                'title' => 'Server-Side Derivation (Optional)',
                'type' => 'title',
                'description' => 'Connect your Kaspa Payments Service for instant server-side address generation. If unreachable, falls back to browser-side derivation automatically.',
            ),
            'brain_url' => array(
                'title' => 'Service URL',
                'type' => 'url',
                'description' => 'Your Vercel endpoint (e.g. https://kaspa-payments-service.vercel.app/api/derive)',
                'default' => '',
                'desc_tip' => true,
            ),
            'brain_secret' => array(
                'title' => 'API Key',
                'type' => 'password',
                'description' => 'Your KaspaWoo API key (starts with kps_). Get one at kaspawoo.com.',
                'default' => '',
                'desc_tip' => true,
            ),

            // ── Pro Features ──────────────────────────────────────────────
            'pro_fee_heading' => array(
                'title'       => 'Pro: Crypto Surcharge',
                'type'        => 'title',
                'description' => empty($this->brain_secret)
                    ? '🔒 <strong>Pro feature</strong> — requires an API key. <a href="https://kaspawoo.com/#pricing" target="_blank">Upgrade to Pro →</a>'
                    : '✓ <strong style="color:#46b450;">Pro active.</strong> Add a small surcharge to offset conversion costs.',
            ),
            'pro_fee_enabled' => array(
                'title'             => 'Enable Surcharge',
                'type'              => 'checkbox',
                'label'             => 'Add a crypto surcharge to Kaspa orders',
                'default'           => 'no',
                'custom_attributes' => array_merge(
                    array('data-pro' => '1'),
                    empty($this->brain_secret) ? array('disabled' => 'disabled') : array()
                ),
            ),
            'pro_fee_type' => array(
                'title'             => 'Surcharge Type',
                'type'              => 'select',
                'options'           => array(
                    'percent' => 'Percentage (%)',
                    'flat'    => 'Flat Amount (added to order total)',
                ),
                'default'           => 'percent',
                'custom_attributes' => array_merge(
                    array('data-pro' => '1'),
                    empty($this->brain_secret) ? array('disabled' => 'disabled') : array()
                ),
            ),
            'pro_fee_amount' => array(
                'title'             => 'Surcharge Amount',
                'type'              => 'number',
                'description'       => 'e.g. 1.5 = 1.5% or $1.50 flat. Fee is applied before KAS conversion.',
                'default'           => '0',
                'desc_tip'          => true,
                'custom_attributes' => array_merge(
                    array('data-pro' => '1', 'step' => '0.01', 'min' => '0'),
                    empty($this->brain_secret) ? array('disabled' => 'disabled') : array()
                ),
            ),

            'pro_ui_heading' => array(
                'title'       => 'Pro: Checkout Appearance',
                'type'        => 'title',
                'description' => empty($this->brain_secret)
                    ? '🔒 <strong>Pro feature</strong> — requires an API key. <a href="https://kaspawoo.com/#pricing" target="_blank">Upgrade to Pro →</a>'
                    : '✓ <strong style="color:#46b450;">Pro active.</strong> Customize the look and feel of your Kaspa checkout.',
            ),
            'pro_accent_color' => array(
                'title'             => 'Accent Color',
                'type'              => 'color',
                'description'       => 'Default: #49eacb (Kaspa teal). Applied to buttons and highlights.',
                'default'           => '#49eacb',
                'desc_tip'          => true,
                'custom_attributes' => array_merge(
                    array('data-pro' => '1'),
                    empty($this->brain_secret) ? array('disabled' => 'disabled') : array()
                ),
            ),
            'pro_button_text' => array(
                'title'             => 'KasWare Button Text',
                'type'              => 'text',
                'description'       => 'Custom label for the KasWare pay button. Leave blank for default.',
                'placeholder'       => 'Pay with KasWare',
                'default'           => '',
                'desc_tip'          => true,
                'custom_attributes' => array_merge(
                    array('data-pro' => '1'),
                    empty($this->brain_secret) ? array('disabled' => 'disabled') : array()
                ),
            ),
            'pro_instructions' => array(
                'title'             => 'Custom Checkout Instructions',
                'type'              => 'textarea',
                'description'       => 'Replaces the default instructions on the payment page. Leave blank to use default.',
                'default'           => '',
                'desc_tip'          => true,
                'custom_attributes' => array_merge(
                    array('data-pro' => '1'),
                    empty($this->brain_secret) ? array('disabled' => 'disabled') : array()
                ),
            ),
        );
    }

    /**
     * Override admin_options to grey out Pro fields when no API key is set.
     */
    public function admin_options()
    {
        parent::admin_options();
        if (empty($this->brain_secret)) {
            ?>
            <style>
            tr.kaspa-pro-disabled { opacity: 0.45; }
            tr.kaspa-pro-disabled input,
            tr.kaspa-pro-disabled select,
            tr.kaspa-pro-disabled textarea { pointer-events: none; cursor: not-allowed; }
            </style>
            <script>
            (function() {
                document.querySelectorAll('[data-pro="1"]').forEach(function(el) {
                    var tr = el.closest('tr');
                    if (tr) tr.classList.add('kaspa-pro-disabled');
                });
            })();
            </script>
            <?php
        }
    }

    /**
     * Build the description for the exchange rate source setting.
     * Shows a currency-specific note for non-USD stores.
     */
    private function get_price_api_description()
    {
        $currency = get_woocommerce_currency();
        $is_usd = in_array(strtoupper($currency), array('USD', 'USDT'), true);

        $desc = 'Choose the APIs used to fetch the KAS exchange rate. First successful response is used and cached for 5 minutes. Use the Test buttons to verify each source.';

        if (!$is_usd) {
            $desc .= '<br><br><strong>Your store currency is ' . esc_html($currency) . '.</strong> We fetch the KAS price from your choices in order. For the ' . esc_html($currency) . ' conversion, only CoinGecko and CryptoCompare support your currency — these will be used in the order you picked, even if they are not your 1st choice. If you don\'t select either, we\'ll automatically fall back to CoinGecko then CryptoCompare.';
        }

        return $desc;
    }

    /**
     * Build dropdown options for price source selects.
     * Non-USD stores see currency compatibility labels. USD stores see clean names.
     */
    private function get_price_source_options($include_none = false)
    {
        $is_usd = in_array(strtoupper(get_woocommerce_currency()), array('USD', 'USDT'), true);

        $options = array();
        if ($include_none) {
            $options['none'] = 'None';
        }

        if ($is_usd) {
            $options['coingecko']     = 'CoinGecko';
            $options['cryptocompare'] = 'CryptoCompare';
            $options['kaspa_api']     = 'Kaspa API';
        } else {
            $options['coingecko']     = 'CoinGecko (All currencies)';
            $options['cryptocompare'] = 'CryptoCompare (All currencies)';
            $options['kaspa_api']     = 'Kaspa API (USD only)';
        }

        return $options;
    }

    public function get_icon()
    {
        if ($this->show_logo === 'yes') {
            $icon = '<span style="display: inline-block; width: 20px; height: 20px; background: #70D0F0; border-radius: 50%; margin-left: 8px; vertical-align: middle;"></span>';
            return $icon;
        }
        return '';
    }

    public function is_available()
    {
        $available = ('yes' === $this->enabled);

        // Require wallet to be configured
        if ($available && !get_option('kasppaga_wallet_configured')) {
            $available = false;
        }

        return $available;
    }

    /**
     * Get current KAS rate with caching.
     * Tries price sources in the order set in gateway settings (default: Kaspa API, CoinGecko, CryptoCompare).
     * Cache TTL is 5 minutes (CoinGecko free tier 10k calls/month when used).
     * Automatically uses the WooCommerce store currency (USD, EUR, GBP, etc.).
     */
    public function get_kas_rate()
    {
        $currency = get_woocommerce_currency();
        $cache_key = 'kaspa_rate_cache_' . strtolower($currency);
        $cached_rate = get_transient($cache_key);
        if ($cached_rate !== false) {
            return $cached_rate;
        }

        $order = array(
            $this->get_option('price_api_primary', 'coingecko'),
            $this->get_option('price_api_secondary', 'cryptocompare'),
            $this->get_option('price_api_tertiary', 'kaspa_api'),
        );
        // Remove "none", empty values, and duplicates
        $order = array_unique(array_filter($order, function ($v) {
            return !empty($v) && $v !== 'none';
        }));
        if (empty($order)) {
            $order = array('coingecko', 'cryptocompare', 'kaspa_api');
        }

        foreach ($order as $source) {
            $rate = $this->fetch_rate_from_source($source, $currency);
            if ($rate !== false && $rate > 0) {
                set_transient($cache_key, $rate, 300); // 5 min
                update_option('kaspa_rate_last_updated', time());
                return $rate;
            }
        }

        // Safety net: if all selected sources failed and store is non-USD,
        // try CoinGecko/CryptoCompare as emergency fallbacks (they support 45+ currencies)
        if (!in_array(strtoupper($currency), array('USD', 'USDT'), true)) {
            $fallbacks = array_diff(array('coingecko', 'cryptocompare'), $order);
            foreach ($fallbacks as $source) {
                $rate = $this->fetch_rate_from_source($source, $currency);
                if ($rate !== false && $rate > 0) {
                    set_transient($cache_key, $rate, 300);
                    update_option('kaspa_rate_last_updated', time());
                    return $rate;
                }
            }
        }

        return false;
    }

    /**
     * Fetch KAS rate from a single source in the given store currency.
     * CoinGecko and CryptoCompare support 45+ fiat currencies natively.
     * Kaspa API and exchange tickers only return USD/USDT — skipped for non-USD stores.
     *
     * @param string $source   One of: kaspa_api, coingecko, cryptocompare, mexc, kucoin, gateio, htx, coinex.
     * @param string $currency Store currency code (e.g. 'USD', 'EUR', 'GBP').
     * @return float|false
     */
    private function fetch_rate_from_source($source, $currency = 'USD')
    {
        $is_usd = in_array(strtoupper($currency), array('USD', 'USDT'), true);

        switch ($source) {
            case 'kaspa_api':
                if (!$is_usd) {
                    return false; // Kaspa API only returns USD
                }
                $response = wp_remote_get('https://api.kaspa.org/info/price', array('timeout' => 10));
                if (is_wp_error($response)) {
                    error_log('Kaspa rate fetch (api.kaspa.org): ' . $response->get_error_message());
                    return false;
                }
                $data = json_decode(wp_remote_retrieve_body($response), true);
                return isset($data['price']) ? floatval($data['price']) : false;

            case 'coingecko':
                $cg_currency = strtolower($currency);
                $response = wp_remote_get('https://api.coingecko.com/api/v3/simple/price?ids=kaspa&vs_currencies=' . $cg_currency, array('timeout' => 10));
                if (is_wp_error($response)) {
                    error_log('Kaspa rate fetch (CoinGecko): ' . $response->get_error_message());
                    return false;
                }
                $data = json_decode(wp_remote_retrieve_body($response), true);
                return isset($data['kaspa'][$cg_currency]) ? floatval($data['kaspa'][$cg_currency]) : false;

            case 'cryptocompare':
                $cc_currency = strtoupper($currency);
                $response = wp_remote_get('https://min-api.cryptocompare.com/data/price?fsym=KAS&tsyms=' . $cc_currency, array('timeout' => 10));
                if (is_wp_error($response)) {
                    error_log('Kaspa rate fetch (CryptoCompare): ' . $response->get_error_message());
                    return false;
                }
                $data = json_decode(wp_remote_retrieve_body($response), true);
                return isset($data[$cc_currency]) ? floatval($data[$cc_currency]) : false;

            default:
                return false;
        }
    }

    /**
     * Calculate Kaspa amount needed
     */
    public function calculate_kaspa_amount($fiat_amount)
    {
        // Apply Pro surcharge if API key is active and fee is configured
        $effective_fiat = (float) $fiat_amount;
        if (!empty($this->brain_secret) && $this->pro_fee_enabled === 'yes' && $this->pro_fee_amount > 0) {
            if ($this->pro_fee_type === 'percent') {
                $effective_fiat = $fiat_amount * (1 + $this->pro_fee_amount / 100);
            } else {
                $effective_fiat = $fiat_amount + $this->pro_fee_amount;
            }
        }

        $rate = $this->get_kas_rate();
        if (!$rate || $rate <= 0) {
            return 0; // Caller must check rate; do not use a hardcoded fallback
        }

        return round($effective_fiat / $rate, 8);
    }

    /**
     * Generate payment address — tries server-side derivation first, then browser fallback.
     */
    private function generate_payment_address($order_id)
    {
        $kpub = get_option('kasppaga_wallet_kpub');

        if (!$kpub) {
            return $this->get_fallback_address($order_id);
        }

        // Try server-side derivation first
        if (!empty($this->brain_url)) {
            $index = intval(get_option('kasppaga_next_address_index', 0));
            $address = $this->derive_address_from_service($index);

            if ($address) {
                global $wpdb;
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->options} SET option_value = %d WHERE option_name = 'kasppaga_next_address_index' AND option_value <= %d",
                    $index + 1,
                    $index
                ));
                return $address;
            }
            // If service failed, fall through to existing browser flow
        }

        // Existing browser-side fallback (unchanged)
        $main_address = get_option('kasppaga_wallet_address');

        if ($main_address === 'pending-derivation' || empty($main_address)) {
            return 'pending-' . $order_id;
        }

        if ($main_address && is_string($main_address) && preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $main_address)) {
            return sanitize_text_field($main_address);
        }

        return $this->get_fallback_address($order_id);
    }

    /**
     * Derive address from the Kaspa Payments Service (Vercel endpoint).
     * Returns the address string on success, or false on failure.
     */
    private function derive_address_from_service($index)
    {
        if (empty($this->brain_url) || empty($this->brain_secret)) {
            return false;
        }

        $response = wp_remote_post($this->brain_url, array(
            'timeout' => 5,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->brain_secret,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'kpub' => get_option('kasppaga_wallet_kpub'),
                'index' => intval($index),
            )),
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            error_log('KaspaWoo: Service request failed — ' .
                (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response))
            );
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['address']) || !preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $body['address'])) {
            error_log('KaspaWoo: Invalid address in service response');
            return false;
        }

        return $body['address'];
    }

    /**
     * Handle the payment page display
     */
    public function handle_payment_page()
    {
        // Check if this is a Kaspa payment page request
        if (!isset($_GET['kaspa_payment']) || $_GET['kaspa_payment'] !== 'true') {
            return;
        }

        $order_id = isset($_GET['order_id']) ? intval(sanitize_text_field(wp_unslash($_GET['order_id']))) : 0;
        $order_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

        if (!$order_id || !$order_key) {
            wp_die('Invalid payment link.');
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_order_key() !== $order_key) {
            wp_die('Invalid order or order key.');
        }

        // Check if payment is already completed
        if (in_array($order->get_status(), ['processing', 'completed'])) {
            // Redirect to actual thank you page
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        // Display the payment page
        $this->display_payment_page($order);
        exit;
    }

    /**
     * Display the payment page (not thank you page)
     */
    private function display_payment_page($order)
    {
        get_header();
        ?>
        <div class="kaspa-payment-page-wrapper" style="padding: 20px 0;">
            <div style="max-width: 800px; margin: 0 auto;">

                <!-- Page Title -->
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1>💰 Send Kaspa Payment</h1>
                    <p style="font-size: 18px; color: #666;">
                        Order #<?php echo esc_html($order->get_id()); ?> -
                        Please send your Kaspa payment to complete your order.
                    </p>
                </div>

                <!-- Display the payment interface -->
                <?php kasppaga_display_thankyou_page($order->get_id(), $this); ?>

            </div>
        </div>
        <?php
        get_footer();
    }

    //  Handle when payment is completed - redirect to real thank you page
    public function handle_payment_completion($order_id, $old_status, $new_status)
    {
        // When order becomes processing/completed, customer can access thank you page
        if (in_array($new_status, ['processing', 'completed'])) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_payment_method() === 'kaspa') {
                // Payment completed - they can now see the real thank you page
            }
        }
    }

    /**
     * Process Payment - Redirect to Dedicated Payment Page
     */
    public function process_payment($order_id)
    {
        try {
            $order = wc_get_order($order_id);

            if (!$order) {
                return array(
                    'result' => 'failure',
                    'messages' => 'Invalid order.'
                );
            }

            // Get order total and calculate KAS amount
            $order_total = $order->get_total();
            $kas_rate = $this->get_kas_rate();

            if (!$kas_rate || $kas_rate <= 0) {
                throw new Exception(__('Unable to fetch current exchange rate. Please try again or choose another payment method.', 'kaspa-payments-gateway-woocommerce'));
            }

            $kas_amount = $this->calculate_kaspa_amount($order_total);

            // Generate a unique payment address for this order
            $payment_address = $this->generate_payment_address($order_id);

            if (!$payment_address || !is_string($payment_address)) {
                throw new Exception('Unable to generate valid payment address. Please try again.');
            }

            // For addresses that start with "pending-", we'll allow them temporarily
            $is_placeholder = (strpos($payment_address, 'pending-') === 0);

            if (!$is_placeholder) {
                // Validate address format for real addresses
                if (!preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $payment_address)) {
                    throw new Exception('Invalid payment address format generated. Please contact support.');
                }
            }

            // Store order meta
            if (!$is_placeholder && $payment_address && preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $payment_address)) {
                $order->update_meta_data('_kaspa_payment_address', $payment_address);
                $order->update_meta_data('_kaspa_address', $payment_address);
            } else {
                $order->update_meta_data('_kaspa_address_pending', true);
            }
            $order->update_meta_data('_kaspa_amount', floatval($kas_amount));
            $order->update_meta_data('_kaspa_expected_amount', floatval($kas_amount));
            $order->update_meta_data('_kaspa_rate', floatval($kas_rate));
            $order->update_meta_data('_kaspa_payment_started', time());
            $order->update_meta_data('_kaspa_order_total', floatval($order_total));

            // Update order status to ON-HOLD for polling system
            $address_display = (!$is_placeholder && $payment_address && preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $payment_address))
                ? $payment_address
                : '(address will be generated)';
            $order->update_status('on-hold', sprintf(
                'Awaiting Kaspa payment of %s KAS to address %s (Order #%d)',
                number_format($kas_amount, 8),
                $address_display,
                $order_id
            ));

            $order->save();

            // Reduce stock levels
            wc_reduce_stock_levels($order_id);

            // Remove cart
            WC()->cart->empty_cart();

            // Use dedicated payment page
            $payment_url = home_url("/kaspa-payment/{$order_id}/{$order->get_order_key()}/");

            return array(
                'result' => 'success',
                'redirect' => $payment_url
            );

        } catch (Exception $e) {
            wc_add_notice('Payment error: ' . $e->getMessage(), 'error');
            return array(
                'result' => 'failure',
                'messages' => $e->getMessage()
            );
        }
    }

    /**
     * FIXED: Fallback address with proper string return
     */
    private function get_fallback_address($order_id)
    {
        // Try to get the main wallet address as fallback
        $wallet_address = get_option('kasppaga_wallet_address');

        // Only use fallback if it's a valid Kaspa address format
        if (
            $wallet_address && is_string($wallet_address) &&
            preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $wallet_address) &&
            $wallet_address !== 'pending-derivation'
        ) {
            return sanitize_text_field($wallet_address);
        }

        // No valid address available - return placeholder for client-side generation
        return 'pending-' . $order_id;
    }

    private function get_wallet_status_html()
    {
        $wallet_configured = get_option('kasppaga_wallet_configured');
        $wallet_address = get_option('kasppaga_wallet_address');
        $wallet_kpub = get_option('kasppaga_wallet_kpub');

        // Consider wallet configured if we have KPUB (even if address is pending derivation)
        if ($wallet_configured && ($wallet_address || $wallet_kpub)) {
            $setup_url = admin_url('admin.php?page=kaspa-wallet-setup');
            return '<div style="background: #d1e7dd; padding: 12px; border-radius: 4px; border: 1px solid #a3cfbb;">
                <strong style="color: #0f5132;">✅ Non-Custodial Wallet Active</strong><br>
                <small>Your secure wallet is configured and ready to receive payments.</small><br>
                <small><strong>Address:</strong> <code>' . esc_html(substr($wallet_address, 0, 20) . '...' . substr($wallet_address, -10)) . '</code></small><br>
                <div style="margin: 8px 0;">
                    <a href="' . $setup_url . '" class="button button-small">Manage Wallet</a>
                </div>
            </div>';
        } else {
            $setup_url = admin_url('admin.php?page=kaspa-wallet-setup');
            return '<div style="background: #f8d7da; padding: 12px; border-radius: 4px; border: 1px solid #f1aeb5;">
                <strong style="color: #842029;">❌ Wallet Not Configured</strong><br>
                <small>You need to set up your Kaspa wallet to accept payments.</small><br>
                <a href="' . $setup_url . '" class="button button-primary button-small" style="margin-top: 8px;">Set Up Wallet Now</a>
            </div>';
        }
    }

    /**
     * Enhanced admin order details display
     */
    public function display_kaspa_payment_details($order)
    {
        if ($order->get_payment_method() !== 'kaspa') {
            return;
        }

        $expected_amount = $order->get_meta('_kaspa_expected_amount');
        $payment_address = $order->get_meta('_kaspa_payment_address');
        $payment_started = $order->get_meta('_kaspa_payment_started');
        $kas_rate = $order->get_meta('_kaspa_rate');
        $txid = $order->get_meta('_kaspa_txid');
        $confirmed_amount = $order->get_meta('_kaspa_confirmed_amount');

        ?>
        <div class="kaspa-admin-payment-info"
            style="background: #f0f8ff; padding: 15px; border-left: 4px solid #70D0F0; margin: 10px 0;">
            <h4>💎 Kaspa Payment Details</h4>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 10px 0;">
                <div>
                    <?php if ($payment_address): ?>
                        <p><strong>Payment Address:</strong><br>
                            <code style="font-size: 11px; word-break: break-all;"><?php echo esc_html($payment_address); ?></code>
                        </p>
                    <?php endif; ?>

                    <p><strong>Expected Amount:</strong><br>
                        <span
                            style="font-size: 16px; color: #2271b1; font-weight: bold;"><?php echo esc_html($expected_amount); ?>
                            KAS</span>
                    </p>

                    <?php if ($confirmed_amount): ?>
                        <p><strong>Confirmed Amount:</strong><br>
                            <span
                                style="font-size: 16px; color: #00a32a; font-weight: bold;"><?php echo esc_html($confirmed_amount); ?>
                                KAS</span>
                        </p>
                    <?php else: ?>
                        <p><strong>Payment Status:</strong><br>
                            <span style="color: #d63638; font-weight: bold;">⏳ Pending</span>
                        </p>
                    <?php endif; ?>
                </div>

                <div>
                    <p><strong>KAS Rate:</strong> $<?php echo esc_html(number_format($kas_rate, 6)); ?></p>
                    <p><strong>Payment Started:</strong> <?php echo esc_html(gmdate('Y-m-d H:i:s', $payment_started)); ?></p>

                    <?php if ($txid): ?>
                        <p><strong>Transaction ID:</strong><br>
                            <code style="font-size: 10px; word-break: break-all;"><?php echo esc_html($txid); ?></code>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div style="background: #fff; padding: 10px; border-radius: 4px; margin-top: 10px;">
                <?php if ($txid && $confirmed_amount): ?>
                    <p style="margin: 0; color: green; font-weight: bold;">
                        ✅ Payment Confirmed: Received <?php echo esc_html($confirmed_amount); ?> KAS
                    </p>
                <?php elseif ($payment_address): ?>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <p style="margin: 0; color: orange; font-weight: bold; flex: 1;">
                            ⏳ Waiting for payment to address: <?php echo esc_html(substr($payment_address, -20)); ?>
                        </p>
                        <button type="button" class="button button-secondary kaspa-manual-check-btn"
                            data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                            data-address="<?php echo esc_attr($payment_address); ?>"
                            data-expected="<?php echo esc_attr($expected_amount); ?>">
                            🔍 Check Payment
                        </button>
                    </div>
                    <div id="kaspa-check-result-<?php echo esc_attr($order->get_id()); ?>" style="margin-top: 10px; display: none;">
                    </div>
                <?php else: ?>
                    <p style="margin: 0; color: gray; font-style: italic;">
                        🔄 Generating payment address...
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Thank you page handler
     */
    public function thankyou_page($order_id)
    {
        kasppaga_display_thankyou_page($order_id, $this);
    }

    /**
     * Enhanced admin options page
     */
    public function admin_options()
    {
        ?>
        <h3><?php echo esc_html($this->method_title); ?></h3>
        <p><?php echo esc_html($this->method_description); ?></p>

        <?php if (!get_option('kasppaga_wallet_configured')): ?>
            <div class="notice notice-warning">
                <p><strong>⚠️ Setup Required:</strong> Please set up your Kaspa wallet to enable payments.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=kaspa-wallet-setup')); ?>"
                        class="button button-primary">Set Up
                        Wallet</a>
                </p>
            </div>
        <?php endif; ?>

        <div class="notice notice-info">
            <p><strong>💡 How Watch-Only Payments Work:</strong></p>
            <ul style="margin-left: 20px;">
                <li><strong>KPUB Wallet:</strong> Uses Extended Public Key (KPUB) - safe to store, cannot spend funds</li>
                <li><strong>Direct Payments:</strong> Customers send payments directly to addresses generated from your KPUB
                </li>
                <li><strong>Auto-Detection:</strong> The system monitors for incoming payments automatically</li>
                <li><strong>Security:</strong> No private keys or mnemonics are stored - maximum security</li>
            </ul>
        </div>

        <?php
        $store_currency = get_woocommerce_currency();
        $selected = array_filter(array(
            $this->get_option('price_api_primary', 'coingecko'),
            $this->get_option('price_api_secondary', 'cryptocompare'),
            $this->get_option('price_api_tertiary', 'kaspa_api'),
        ), function ($v) { return !empty($v) && $v !== 'none'; });
        $source_count = count(array_unique($selected));

        // Warn if only 1 source selected (no fallback)
        if ($source_count <= 1) {
            ?>
            <div class="notice notice-warning">
                <p><strong>Heads up:</strong> Only one price source selected. If it becomes unavailable, checkout will be temporarily disabled until it recovers. We recommend adding a fallback.</p>
            </div>
            <?php
        }

        // Warn non-USD stores if none of their selected sources support their currency
        if (!in_array(strtoupper($store_currency), array('USD', 'USDT'), true)) {
            $multi_currency_sources = array('coingecko', 'cryptocompare');
            $has_multi = !empty(array_intersect($selected, $multi_currency_sources));
            if (!$has_multi) {
                ?>
                <div class="notice notice-warning">
                    <p><strong>Currency notice:</strong> Your store uses <?php echo esc_html($store_currency); ?>, but your selected price sources only support USD. Exchange rates will automatically fall back to CoinGecko/CryptoCompare, but for best results, select at least one of these as a primary source.</p>
                </div>
                <?php
            }
        }
        ?>

        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>

        <script>
        (function() {
            var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            var nonce = '<?php echo esc_js(wp_create_nonce('kasppaga_test_rate')); ?>';
            var currency = '<?php echo esc_js(get_woocommerce_currency()); ?>';

            var selects = document.querySelectorAll('#woocommerce_kaspa_price_api_primary, #woocommerce_kaspa_price_api_secondary, #woocommerce_kaspa_price_api_tertiary');
            selects.forEach(function(select) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'button button-small';
                btn.textContent = 'Test';
                btn.style.marginLeft = '8px';
                btn.style.verticalAlign = 'middle';

                var result = document.createElement('span');
                result.style.marginLeft = '8px';
                result.style.fontSize = '13px';

                select.parentNode.insertBefore(btn, select.nextSibling);
                select.parentNode.insertBefore(result, btn.nextSibling);

                btn.addEventListener('click', function() {
                    var source = select.value;
                    btn.disabled = true;
                    btn.textContent = 'Testing...';
                    result.textContent = '';
                    result.style.color = '';

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4) {
                            btn.disabled = false;
                            btn.textContent = 'Test';
                            if (xhr.status === 200) {
                                try {
                                    var resp = JSON.parse(xhr.responseText);
                                    if (resp.success) {
                                        result.textContent = '1 KAS = ' + resp.data.rate + ' ' + resp.data.currency;
                                        if (resp.data.note) {
                                            result.style.color = '#996800';
                                            result.textContent += ' (' + resp.data.note + ')';
                                        } else {
                                            result.style.color = '#00a32a';
                                        }
                                    } else {
                                        result.style.color = '#d63638';
                                        result.textContent = resp.data || 'Failed';
                                    }
                                } catch(e) {
                                    result.style.color = '#d63638';
                                    result.textContent = 'Error parsing response';
                                }
                            } else {
                                result.style.color = '#d63638';
                                result.textContent = 'Network error';
                            }
                        }
                    };
                    xhr.send('action=kasppaga_test_rate&source=' + encodeURIComponent(source) + '&nonce=' + nonce);
                });
            });
        })();
        </script>

        <?php
    }

    /**
     * Enqueue admin scripts for order pages
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only add on order edit pages
        if ($hook !== 'post.php' || !isset($_GET['post']) || get_post_type(intval(sanitize_text_field(wp_unslash($_GET['post'])))) !== 'shop_order') {
            return;
        }

        // Register and enqueue a script handle
        wp_register_script('kaspa-gateway-admin', '', array(), '1.0.0', true);
        wp_enqueue_script('kaspa-gateway-admin');

        // Prepare nonces
        $manual_check_nonce = wp_create_nonce('kasppaga_manual_check');
        $mark_complete_nonce = wp_create_nonce('kasppaga_mark_complete');
        $ajax_url = admin_url('admin-ajax.php');

        // Build inline script
        $inline_script = "document.addEventListener('DOMContentLoaded', function () {
            document.addEventListener('click', function (e) {
                if (e.target && e.target.classList.contains('kaspa-manual-check-btn')) {
                    e.preventDefault();
                    checkPaymentManually(e.target);
                }
            });
        });
        function checkPaymentManually(button) {
            const orderId = button.getAttribute('data-order-id');
            const address = button.getAttribute('data-address');
            const expected = button.getAttribute('data-expected');
            const resultDiv = document.getElementById('kaspa-check-result-' + orderId);
            button.disabled = true;
            button.textContent = '🔄 Checking...';
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<div style=\"color: #666; font-style: italic;\">Checking payment status...</div>';
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '" . esc_url($ajax_url) . "', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    button.disabled = false;
                    button.textContent = '🔍 Check Payment';
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                if (response.data.payment_found) {
                                    resultDiv.innerHTML = '<div style=\"background: #d1e7dd; padding: 10px; border-radius: 4px; border: 1px solid #a3cfbb;\"><strong style=\"color: #0f5132;\">✅ Payment Found!</strong><br><small>Amount: ' + response.data.amount + ' KAS (Expected: ' + response.data.expected + ' KAS)</small><br><button type=\"button\" class=\"button button-primary\" onclick=\"markOrderComplete(' + orderId + ')\" style=\"margin-top: 8px;\">Mark Order Complete</button></div>';
                                } else {
                                    resultDiv.innerHTML = '<div style=\"background: #f8d7da; padding: 10px; border-radius: 4px; border: 1px solid #f1aeb5;\"><strong style=\"color: #842029;\">❌ No Payment Found</strong><br><small>No payment detected at this address yet.</small></div>';
                                }
                            } else {
                                resultDiv.innerHTML = '<div style=\"background: #f8d7da; padding: 10px; border-radius: 4px; border: 1px solid #f1aeb5;\"><strong style=\"color: #842029;\">❌ Error</strong><br><small>' + response.data + '</small></div>';
                            }
                        } catch (e) {
                            resultDiv.innerHTML = '<div style=\"background: #f8d7da; padding: 10px; border-radius: 4px; border: 1px solid #f1aeb5;\"><strong style=\"color: #842029;\">❌ Error</strong><br><small>Failed to parse response</small></div>';
                        }
                    } else {
                        resultDiv.innerHTML = '<div style=\"background: #f8d7da; padding: 10px; border-radius: 4px; border: 1px solid #f1aeb5;\"><strong style=\"color: #842029;\">❌ Network Error</strong><br><small>Failed to check payment status</small></div>';
                    }
                }
            };
                const data = 'action=kasppaga_manual_check_payment&order_id=' + orderId + '&address=' + encodeURIComponent(address) + '&expected=' + encodeURIComponent(expected) + '&nonce=" . esc_js($manual_check_nonce) . "';
            xhr.send(data);
        }
        function markOrderComplete(orderId) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '" . esc_url($ajax_url) . "', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            alert('✅ Order marked as complete! The page will refresh.');
                            location.reload();
                        } else {
                            alert('❌ Error: ' + response.data);
                        }
                    } catch (e) {
                        alert('❌ Error marking order complete');
                    }
                }
            };
            const data = 'action=kasppaga_mark_order_complete&order_id=' + orderId + '&nonce=" . esc_js($mark_complete_nonce) . "';
            xhr.send(data);
        }";

        wp_add_inline_script('kaspa-gateway-admin', $inline_script);
    }

    // Balance checking functionality moved to Kaspa plugin admin page
}

//  FIXED: Move the AJAX handler function outside the class or make it a class method

/**
 * Get next sequential address index for KPUB derivation
 * 
 * Strategy: Sequential indexing starting from 0
 * - Starts at index 0 for maximum visibility in Kaspium
 * - No offset needed - addresses are watch-only, so reusing addresses is fine
 * - Simplest approach - merchants see all payments in their wallet automatically
 */
function kasppaga_get_next_address_index()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_get_index')) {
        wp_send_json_error('Invalid nonce');
        return;
    }

    // Get current sequential index (starts at 0)
    $current_index = get_option('kasppaga_next_address_index', 0);

    wp_send_json_success(array(
        'index' => $current_index,
        'message' => 'Next sequential address index'
    ));
}

add_action('wp_ajax_kasppaga_get_next_address_index', 'kasppaga_get_next_address_index');
add_action('wp_ajax_nopriv_kasppaga_get_next_address_index', 'kasppaga_get_next_address_index');

function kasppaga_save_order_address()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_save_address')) {
        wp_send_json_error('Invalid nonce');
        return;
    }

    $order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : 0;
    $address = isset($_POST['address']) ? sanitize_text_field(wp_unslash($_POST['address'])) : '';
    $order_key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';
    $address_index = isset($_POST['address_index']) ? intval(sanitize_text_field(wp_unslash($_POST['address_index']))) : -1;

    if (!$order_id || !$address || !$order_key) {
        wp_send_json_error('Missing required parameters');
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error('Order not found');
        return;
    }

    // Verify the order key matches — prevents unauthorized address overwrites
    if ($order->get_order_key() !== $order_key) {
        wp_send_json_error('Permission denied');
        return;
    }

    // Don't allow address changes on already-confirmed orders
    if (in_array($order->get_status(), array('processing', 'completed'), true)) {
        wp_send_json_error('Order already confirmed');
        return;
    }

    // Validate address format
    if (!preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $address)) {
        wp_send_json_error('Invalid address format');
        return;
    }

    // Update order with the unique address
    $order->update_meta_data('_kaspa_payment_address', $address);
    $order->update_meta_data('_kaspa_address', $address);
    $order->update_meta_data('_kaspa_address_generated_time', time());

    // Store the address index and atomically increment the global counter
    if ($address_index >= 0) {
        $order->update_meta_data('_kaspa_address_index', $address_index);
        kasppaga_atomic_increment_address_index($address_index);
    }

    $order->save();

    wp_send_json_success(array(
        'message' => 'Address saved successfully',
        'address' => $address,
        'index' => $address_index
    ));
}

/**
 * Atomically increment the address index to prevent race conditions.
 * Uses a database-level UPDATE with a WHERE clause so only one concurrent
 * request can claim a given index.
 */
function kasppaga_atomic_increment_address_index($used_index)
{
    global $wpdb;

    // Atomic UPDATE: only increment if the current value hasn't already moved past this index
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %d WHERE option_name = 'kasppaga_next_address_index' AND CAST(option_value AS UNSIGNED) <= %d",
        $used_index + 1,
        $used_index
    ));

    // If the option doesn't exist yet, create it
    if ($wpdb->rows_affected === 0) {
        add_option('kasppaga_next_address_index', $used_index + 1, '', 'no');
    }
}

add_action('wp_ajax_kasppaga_save_order_address', 'kasppaga_save_order_address');
add_action('wp_ajax_nopriv_kasppaga_save_order_address', 'kasppaga_save_order_address');

/**
 * AJAX handler for manual payment check
 */
function kasppaga_manual_check_payment()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_manual_check') || !current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permission denied');
        return;
    }

    $order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : 0;
    $address = isset($_POST['address']) ? sanitize_text_field(wp_unslash($_POST['address'])) : '';
    $expected = isset($_POST['expected']) ? floatval(sanitize_text_field(wp_unslash($_POST['expected']))) : 0;

    if (!$order_id || !$address || !$expected) {
        wp_send_json_error('Missing required parameters');
        return;
    }

    try {
        // Use the transaction polling class to check balance
        $polling = new KASPPAGA_Transaction_Polling();
        $balance = $polling->get_kaspa_balance($address);

        if ($balance === false) {
            wp_send_json_error('Failed to fetch balance from Kaspa API');
            return;
        }

        // Check if payment was received
        if ($balance >= $expected) {
            wp_send_json_success(array(
                'payment_found' => true,
                'amount' => $balance,
                'expected' => $expected,
                'timestamp' => time()
            ));
        } else {
            wp_send_json_success(array(
                'payment_found' => false,
                'current_balance' => $balance,
                'expected' => $expected
            ));
        }

    } catch (Exception $e) {
        error_log('Kaspa manual check error: ' . $e->getMessage());
        wp_send_json_error('Error checking payment: ' . $e->getMessage());
    }
}

add_action('wp_ajax_kasppaga_manual_check_payment', 'kasppaga_manual_check_payment');

/**
 * AJAX handler for marking order as complete
 */
function kasppaga_mark_order_complete()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_mark_complete') || !current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permission denied');
        return;
    }

    $order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : 0;

    if (!$order_id) {
        wp_send_json_error('Missing order ID');
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error('Order not found');
        return;
    }

    if ($order->get_payment_method() !== 'kaspa') {
        wp_send_json_error('Not a Kaspa order');
        return;
    }

    // Mark as processing (which will trigger completion)
    $order->update_status('processing', 'Payment confirmed via manual check');
    $order->save();

    wp_send_json_success(array(
        'message' => 'Order marked as complete',
        'new_status' => $order->get_status()
    ));
}

add_action('wp_ajax_kasppaga_mark_order_complete', 'kasppaga_mark_order_complete');

/**
 * AJAX handler for testing a single price source from the admin settings page.
 * Returns the live rate so the merchant can verify each source works.
 */
function kasppaga_test_rate()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_test_rate') || !current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permission denied');
        return;
    }

    $source = isset($_POST['source']) ? sanitize_text_field(wp_unslash($_POST['source'])) : '';
    $allowed = array('coingecko', 'cryptocompare', 'kaspa_api');
    if (!in_array($source, $allowed, true)) {
        wp_send_json_error('Invalid source');
        return;
    }

    $currency = get_woocommerce_currency();
    $gateway = new KASPPAGA_WC_Gateway();

    // Use reflection to call the private method for testing
    $method = new ReflectionMethod($gateway, 'fetch_rate_from_source');
    $method->setAccessible(true);
    $rate = $method->invoke($gateway, $source, $currency);

    if ($rate !== false && $rate > 0) {
        wp_send_json_success(array(
            'rate' => number_format($rate, 6),
            'currency' => $currency,
            'source' => $source,
        ));
    } else {
        $is_usd = in_array(strtoupper($currency), array('USD', 'USDT'), true);
        if (!$is_usd && $source === 'kaspa_api') {
            // Kaspa API only returns USD — fetch the USD rate and show it with context
            $usd_rate = $method->invoke($gateway, 'kaspa_api', 'USD');
            if ($usd_rate !== false && $usd_rate > 0) {
                wp_send_json_success(array(
                    'rate' => number_format($usd_rate, 6),
                    'currency' => 'USD',
                    'source' => $source,
                    'note' => 'USD rate only — ' . $currency . ' rate will come from your next available source.',
                ));
            } else {
                wp_send_json_error('No rate returned. Source may be temporarily unavailable.');
            }
        } else {
            wp_send_json_error('No rate returned. Source may be temporarily unavailable.');
        }
    }
}

add_action('wp_ajax_kasppaga_test_rate', 'kasppaga_test_rate');