<?php
/**
 * Clean Kaspa Admin Dashboard
 * Simplified version with just the essentials
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class KASPPAGA_Admin_Dashboard
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_notices', array($this, 'maybe_show_review_notice'));
        add_action('wp_ajax_kasppaga_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_kasppaga_dismiss_review_notice', array($this, 'ajax_dismiss_review_notice'));
        add_action('wp_ajax_kasppaga_save_customizer', array($this, 'ajax_save_customizer'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        // Main menu
        add_menu_page(
            'Kaspa Payments Gateway',
            'Kaspa Payments Gateway',
            'manage_woocommerce',
            'kaspa-payments-gateway',
            array($this, 'render_dashboard_page'),
            $this->get_kaspa_icon(),
            56
        );

        // Remaining sub-menus registered at priority 30 so Wallet Setup (priority 20) appears first
        add_action('admin_menu', array($this, 'add_secondary_submenus'), 30);
    }

    /**
     * Register sub-menus that should appear after Wallet Setup
     */
    public function add_secondary_submenus()
    {
        // Sub-menu: Settings (WooCommerce gateway settings)
        add_submenu_page(
            'kaspa-payments-gateway',
            'Settings',
            'Settings',
            'manage_woocommerce',
            'kaspa-settings-redirect',
            array($this, 'redirect_to_settings')
        );

        // Sub-menu: Checkout Preview
        add_submenu_page(
            'kaspa-payments-gateway',
            'Checkout Preview',
            'Checkout Preview',
            'manage_woocommerce',
            'kaspa-checkout-preview',
            array($this, 'render_customizer_page')
        );

        // Sub-menu: Analytics
        add_submenu_page(
            'kaspa-payments-gateway',
            'Analytics',
            'Analytics',
            'manage_woocommerce',
            'kaspa-analytics',
            array($this, 'render_analytics_page')
        );

        // Sub-menu: Help & FAQ
        add_submenu_page(
            'kaspa-payments-gateway',
            'Help & FAQ',
            'Help & FAQ',
            'manage_woocommerce',
            'kaspa-help',
            array($this, 'render_help_page')
        );
    }

    /**
     * Show a dismissible notice asking for a review (only on Kaspa admin pages).
     */
    public function maybe_show_review_notice()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, array('toplevel_page_kaspa-payments-gateway', 'kaspa-payments-gateway_page_kaspa-analytics', 'kaspa-payments-gateway_page_kaspa-wallet-setup', 'kaspa-payments-gateway_page_kaspa-help'), true)) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (get_user_meta(get_current_user_id(), 'kasppaga_review_notice_dismissed', true)) {
            return;
        }
        $review_url = 'https://wordpress.org/plugins/kaspa-payments-gateway-woocommerce/#reviews';
        ?>
        <div class="notice notice-info kasppaga-review-notice" style="position: relative; padding-right: 38px;">
            <p>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: URL to WordPress.org plugin reviews */
                        __('Like this plugin? <a href="%s" target="_blank" rel="noopener noreferrer">Leave a review</a> to support the developer and spread the word about Kaspa.', 'kaspa-payments-gateway-woocommerce'),
                        esc_url($review_url)
                    ),
                    array('a' => array('href' => array(), 'target' => array(), 'rel' => array()))
                );
                ?>
            </p>
            <button type="button" class="notice-dismiss kasppaga-dismiss-review" style="position: absolute; top: 50%; right: 1px; transform: translateY(-50%); margin: 0; padding: 9px;">
                <span class="screen-reader-text"><?php esc_html_e('Dismiss', 'kaspa-payments-gateway-woocommerce'); ?></span>
            </button>
        </div>
        <script>
        (function() {
            var el = document.querySelector('.kasppaga-review-notice');
            if (!el) return;
            var btn = el.querySelector('.kasppaga-dismiss-review');
            if (btn) {
                btn.addEventListener('click', function() {
                    el.style.display = 'none';
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', <?php echo json_encode(admin_url('admin-ajax.php')); ?>, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.send('action=kasppaga_dismiss_review_notice&nonce=<?php echo esc_js(wp_create_nonce('kasppaga_dismiss_review')); ?>');
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * AJAX: dismiss the review notice (store in user meta).
     */
    public function ajax_dismiss_review_notice()
    {
        check_ajax_referer('kasppaga_dismiss_review', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error();
        }
        update_user_meta(get_current_user_id(), 'kasppaga_review_notice_dismissed', 1);
        wp_send_json_success();
    }

    /**
     * Main Dashboard Page
     */
    public function render_dashboard_page()
    {
        $stats = $this->get_payment_stats();
        $recent_orders = $this->get_recent_kaspa_orders(5);
        ?>
        <div class="wrap kaspa-admin-dashboard" style="max-width: 1200px; margin: 0 auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h1 style="margin: 0; font-size: 23px;">Kaspa Gateway</h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=kaspa-analytics')); ?>" class="button" style="background: #70D0F0; color: #fff; border: none;">Analytics</a>
            </div>

            <!-- Stats Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
                <div style="background: linear-gradient(135deg, #ffffff 0%, #f0f9fc 100%); padding: 20px; border-radius: 8px; border-left: 4px solid #49a8d4; box-shadow: 0 2px 8px rgba(0,0,0,0.06); position: relative; overflow: hidden;">
                    <div style="position: absolute; right: -10px; top: -10px; font-size: 80px; opacity: 0.06;">
                        <span class="dashicons dashicons-chart-line"></span>
                    </div>
                    <div style="position: relative; z-index: 1;">
                        <div style="font-size: 28px; font-weight: 700; margin-bottom: 4px; color: #1d2327;"><?php echo esc_html($stats['total_revenue_kas']); ?> KAS</div>
                        <div style="font-size: 14px; color: #646970; margin-bottom: 2px;">Total Sales</div>
                        <div style="font-size: 12px; color: #949494;">$<?php echo number_format($stats['total_revenue_usd'], 2); ?> USD</div>
                    </div>
                </div>

                <div style="background: linear-gradient(135deg, #ffffff 0%, #f0f8ff 100%); padding: 20px; border-radius: 8px; border-left: 4px solid #70D0F0; box-shadow: 0 2px 8px rgba(0,0,0,0.06); position: relative; overflow: hidden;">
                    <div style="position: absolute; right: -10px; top: -10px; font-size: 80px; opacity: 0.06;">
                        <span class="dashicons dashicons-cart"></span>
                    </div>
                    <div style="position: relative; z-index: 1;">
                        <div style="font-size: 28px; font-weight: 700; margin-bottom: 4px; color: #1d2327;"><?php echo esc_html($stats['total_orders']); ?></div>
                        <div style="font-size: 14px; color: #646970; margin-bottom: 2px;">Total Orders</div>
                        <div style="font-size: 12px; color: #949494;"><?php echo esc_html($stats['orders_this_month']); ?> this month</div>
                    </div>
                </div>

                <div style="background: linear-gradient(135deg, #ffffff 0%, #f0f7ff 100%); padding: 20px; border-radius: 8px; border-left: 4px solid #2c8fc1; box-shadow: 0 2px 8px rgba(0,0,0,0.06); position: relative; overflow: hidden;">
                    <div style="position: absolute; right: -10px; top: -10px; font-size: 80px; opacity: 0.06;">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </div>
                    <div style="position: relative; z-index: 1;">
                        <div style="font-size: 28px; font-weight: 700; margin-bottom: 4px; color: #1d2327;"><?php echo esc_html($stats['success_rate']); ?>%</div>
                        <div style="font-size: 14px; color: #646970; margin-bottom: 2px;">Success Rate</div>
                        <div style="font-size: 12px; color: #949494;"><?php echo esc_html($stats['total_attempts']); ?> attempts</div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions & System Health -->
            <?php 
            $health = $this->get_system_health_data(); 
            ?>
            <!-- System Status Bar -->
            <div style="margin-bottom: 20px; padding: 16px 20px; background: linear-gradient(to right, #ffffff, #f8f9fa); border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                    <!-- Left side: System Status -->
                    <div style="display: flex; gap: 24px; flex-wrap: wrap; align-items: center;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span class="dashicons dashicons-admin-network" style="color: #70D0F0; font-size: 18px;"></span>
                            <div>
                                <div style="font-size: 11px; color: #757575; line-height: 1;">System Status</div>
                                <div style="font-size: 13px; font-weight: 600; color: #1d2327; margin-top: 2px;">
                                    Wallet 
                                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: <?php echo $health['wallet_configured'] ? '#46b450' : '#dc3232'; ?>; margin: 0 8px;"></span>
                                    Price API
                                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: <?php echo $health['rate_ok'] ? '#46b450' : '#dc3232'; ?>; margin: 0 8px;" title="<?php 
                                        $last_crawl = $health['last_rate_update'] ? human_time_diff($health['last_rate_update'], time()) . ' ago' : 'Never';
                                        echo esc_attr('Cached 5min. Last: ' . $last_crawl); 
                                    ?>"></span>
                                    Monitoring
                                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: <?php echo $health['polling_active'] ? '#46b450' : '#dc3232'; ?>; margin-left: 8px;"></span>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($health['rate_ok']): ?>
                        <div style="display: flex; align-items: center; gap: 8px; padding-left: 24px; border-left: 1px solid #e0e0e0;">
                            <span class="dashicons dashicons-money-alt" style="color: #949494; font-size: 16px;"></span>
                            <div>
                                <div style="font-size: 11px; color: #757575; line-height: 1;">Current Rate</div>
                                <div style="font-size: 13px; font-weight: 600; color: #1d2327; margin-top: 2px;">$<?php echo esc_html(number_format((float) $health['rate_value'], 5)); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Right side: Quick Actions -->
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=kaspa-wallet-setup')); ?>" class="button button-small" style="font-size: 12px; padding: 4px 12px;">
                            <span class="dashicons dashicons-admin-network" style="font-size: 14px; vertical-align: middle; margin-right: 4px;"></span>
                            <?php echo $health['wallet_configured'] ? 'Wallet' : 'Setup Wallet'; ?>
                        </a>
                        <?php if ($health['wallet_configured']): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=kaspa-wallet-setup')); ?>#kaspa-auto-balance" class="button button-small kaspa-prefetch-balance" style="font-size: 12px; padding: 4px 12px;">
                                <span class="dashicons dashicons-chart-bar" style="font-size: 14px; vertical-align: middle; margin-right: 4px;"></span>
                                Balance
                            </a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=kaspa')); ?>" class="button button-small" style="font-size: 12px; padding: 4px 12px;">
                            <span class="dashicons dashicons-admin-settings" style="font-size: 14px; vertical-align: middle; margin-right: 4px;"></span>
                            Settings
                        </a>
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=shop_order&payment_method=kaspa')); ?>" class="button button-small" style="font-size: 12px; padding: 4px 12px;">
                            <span class="dashicons dashicons-list-view" style="font-size: 14px; vertical-align: middle; margin-right: 4px;"></span>
                            Orders
                        </a>
                    </div>
                </div>
            </div>

            <!-- Recent Orders -->
            <div style="background: #fff; padding: 18px; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                <h2 style="margin: 0 0 14px 0; font-size: 15px; font-weight: 600; color: #1d2327;">Recent Orders</h2>
                <div style="overflow-x: auto;">
                    <table class="wp-list-table widefat fixed striped" style="font-size: 13px;">
                        <thead>
                            <tr>
                                <th style="padding: 8px;">Order</th>
                                <th style="padding: 8px;">Customer</th>
                                <th style="padding: 8px;">Amount</th>
                                <th style="padding: 8px;">Status</th>
                                <th style="padding: 8px;">Date</th>
                                <th style="padding: 8px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_orders)): ?>
                                <?php foreach ($recent_orders as $order): ?>
                                    <tr>
                                        <td style="padding: 8px;"><strong>#<?php echo esc_html($order['id']); ?></strong></td>
                                        <td style="padding: 8px;"><?php echo esc_html($order['customer']); ?></td>
                                        <td style="padding: 8px;">
                                            <strong><?php echo esc_html($order['kas_amount']); ?> KAS</strong><br>
                                            <small style="color: #757575;">$<?php echo esc_html($order['usd_amount']); ?></small>
                                        </td>
                                        <td style="padding: 8px;">
                                            <span style="display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; <?php 
                                                $status_colors = array(
                                                    'completed' => 'background: #d4edda; color: #155724;',
                                                    'processing' => 'background: #fff3cd; color: #856404;',
                                                    'pending' => 'background: #e7f3ff; color: #004085;',
                                                    'failed' => 'background: #f8d7da; color: #721c24;',
                                                    'cancelled' => 'background: #f8d7da; color: #721c24;'
                                                );
                                                echo isset($status_colors[$order['status']]) ? $status_colors[$order['status']] : 'background: #f0f0f0; color: #666;';
                                            ?>">
                                                <?php echo esc_html(ucfirst($order['status'])); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 8px; font-size: 12px; color: #666;"><?php echo esc_html($order['date']); ?></td>
                                        <td style="padding: 8px;">
                                            <a href="<?php echo esc_url($order['edit_url']); ?>" class="button button-small" style="font-size: 11px; padding: 4px 10px;">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 30px; color: #757575;">
                                        <div style="font-weight: 600; margin-bottom: 4px;">No Orders Yet</div>
                                        <div style="font-size: 12px;">Orders will appear here once you receive Kaspa payments</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($recent_orders)): ?>
                    <div style="margin-top: 14px; text-align: right;">
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=shop_order&payment_method=kaspa')); ?>" class="button button-small" style="font-size: 12px;">View All →</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        // Add inline script for stats refresh and balance prefetch
        $consolidated_balance_nonce = wp_create_nonce('kasppaga_consolidated_balance');
        $inline_script = "jQuery(document).ready(function ($) {
            // Stats refresh
            setInterval(function () {
                $.post(ajaxurl, { action: 'kasppaga_get_stats' });
            }, 30000);
            
            // Prefetch balance when Balance button is clicked
            $('.kaspa-prefetch-balance').on('click', function(e) {
                // Start fetching balance data immediately before page navigation
                $.post(ajaxurl, {
                    action: 'kasppaga_get_consolidated_balance',
                    nonce: '" . esc_js($consolidated_balance_nonce) . "',
                    force_refresh: 'false'
                });
                // Let the navigation continue normally
            });
        });";
        wp_add_inline_script('kaspa-admin-script', $inline_script);
    }

    /**
     * AJAX: Save customizer settings.
     */
    public function ajax_save_customizer()
    {
        check_ajax_referer('kaspa_customizer_save', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
            return;
        }

        $settings = get_option('woocommerce_kaspa_settings', array());

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above
        if (isset($_POST['pro_accent_color'])) {
            $color = sanitize_hex_color(wp_unslash($_POST['pro_accent_color']));
            $settings['pro_accent_color'] = $color ?: '#49eacb';
        }
        if (isset($_POST['pro_button_text'])) {
            $settings['pro_button_text'] = sanitize_text_field(wp_unslash($_POST['pro_button_text']));
        }
        if (isset($_POST['pro_instructions'])) {
            $settings['pro_instructions'] = wp_kses(
                wp_unslash($_POST['pro_instructions']),
                array('strong' => array(), 'em' => array(), 'a' => array('href' => array(), 'target' => array()))
            );
        }
        if (isset($_POST['pro_show_logo'])) {
            $settings['show_logo'] = sanitize_text_field(wp_unslash($_POST['pro_show_logo'])) === 'yes' ? 'yes' : 'no';
        }
        if (isset($_POST['pro_title'])) {
            $settings['title'] = sanitize_text_field(wp_unslash($_POST['pro_title']));
        }
        if (isset($_POST['pro_description'])) {
            $settings['description'] = sanitize_textarea_field(wp_unslash($_POST['pro_description']));
        }
        if (isset($_POST['pro_fee_enabled'])) {
            $settings['pro_fee_enabled'] = sanitize_text_field(wp_unslash($_POST['pro_fee_enabled'])) === 'yes' ? 'yes' : 'no';
        }
        if (isset($_POST['pro_fee_type'])) {
            $type = sanitize_text_field(wp_unslash($_POST['pro_fee_type']));
            $settings['pro_fee_type'] = in_array($type, array('percent', 'flat'), true) ? $type : 'percent';
        }
        if (isset($_POST['pro_fee_amount'])) {
            $settings['pro_fee_amount'] = (string) abs((float) wp_unslash($_POST['pro_fee_amount']));
        }
        // phpcs:enable

        update_option('woocommerce_kaspa_settings', $settings);
        wp_send_json_success('Settings saved');
    }

    /**
     * Checkout Preview / Customizer Page
     */
    public function render_customizer_page()
    {
        $settings    = get_option('woocommerce_kaspa_settings', array());
        $has_key     = !empty($settings['brain_secret']);
        $accent      = !empty($settings['pro_accent_color']) ? $settings['pro_accent_color'] : '#49eacb';
        $btn_text    = !empty($settings['pro_button_text']) ? $settings['pro_button_text'] : 'Pay with KasWare';
        $instruct    = !empty($settings['pro_instructions']) ? $settings['pro_instructions'] : 'Send the exact KAS amount to the address above. Payment is detected automatically.';
        $title       = !empty($settings['title']) ? $settings['title'] : 'Kaspa (KAS)';
        $description = !empty($settings['description']) ? $settings['description'] : 'Pay with Kaspa cryptocurrency. Fast and secure.';
        $show_logo   = isset($settings['show_logo']) ? $settings['show_logo'] : 'yes';
        $fee_enabled = !empty($settings['pro_fee_enabled']) && $settings['pro_fee_enabled'] === 'yes';
        $fee_type    = !empty($settings['pro_fee_type']) ? $settings['pro_fee_type'] : 'percent';
        $fee_amount  = !empty($settings['pro_fee_amount']) ? (float) $settings['pro_fee_amount'] : 0;
        $nonce       = wp_create_nonce('kaspa_customizer_save');
        ?>
        <div class="wrap" style="max-width:1300px;">
            <h1 style="margin-bottom:4px;">Checkout Preview</h1>
            <p style="color:#666;margin-top:0;margin-bottom:24px;">Edit your checkout page appearance. Changes are saved and applied immediately.</p>

            <?php if (!$has_key): ?>
            <div style="background:#fff8e1;border-left:4px solid #ffb300;padding:12px 16px;margin-bottom:20px;border-radius:0 6px 6px 0;">
                <strong>Pro features (accent color, button text, instructions, surcharge) require an API key.</strong>
                <a href="https://kaspawoo.com/#pricing" target="_blank" style="margin-left:8px;">Upgrade to Pro →</a>
                <br><small style="color:#888;">You can still preview and save — settings activate when you add your API key.</small>
            </div>
            <?php endif; ?>

            <div id="kaspa-customizer-saved" style="display:none;background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:10px 16px;border-radius:6px;margin-bottom:16px;">
                ✓ Settings saved successfully.
            </div>

            <div style="display:grid;grid-template-columns:340px 1fr;gap:24px;align-items:start;">

                <!-- Controls Panel -->
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;">

                    <h3 style="margin-top:0;margin-bottom:20px;font-size:15px;border-bottom:1px solid #eee;padding-bottom:10px;">General</h3>

                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:5px;">Payment Method Title</label>
                        <input type="text" id="kc-title" value="<?php echo esc_attr($title); ?>" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:5px;font-size:13px;">
                        <p style="font-size:11px;color:#888;margin:4px 0 0;">Shown next to the radio button at checkout.</p>
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:5px;">Description</label>
                        <textarea id="kc-description" rows="2" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:5px;font-size:13px;resize:vertical;"><?php echo esc_textarea($description); ?></textarea>
                        <p style="font-size:11px;color:#888;margin:4px 0 0;">Shown under the title at checkout selection.</p>
                    </div>

                    <div style="margin-bottom:20px;display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" id="kc-show-logo" <?php checked($show_logo, 'yes'); ?> style="width:16px;height:16px;">
                        <label for="kc-show-logo" style="font-size:13px;cursor:pointer;">Show Kaspa logo dot next to title</label>
                    </div>

                    <h3 style="margin-top:0;margin-bottom:20px;font-size:15px;border-bottom:1px solid #eee;padding-bottom:10px;">Appearance <span style="font-size:11px;color:#49eacb;font-weight:700;letter-spacing:0.5px;">PRO</span></h3>

                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:5px;">Accent Color</label>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <input type="color" id="kc-accent-color" value="<?php echo esc_attr($accent); ?>" style="width:44px;height:34px;border:1px solid #ddd;border-radius:5px;cursor:pointer;padding:2px;">
                            <input type="text" id="kc-accent-hex" value="<?php echo esc_attr($accent); ?>" style="width:90px;padding:8px 10px;border:1px solid #ddd;border-radius:5px;font-size:13px;font-family:monospace;">
                            <button type="button" id="kc-reset-color" style="font-size:11px;color:#888;background:none;border:none;cursor:pointer;text-decoration:underline;">Reset</button>
                        </div>
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:5px;">KasWare Button Text</label>
                        <input type="text" id="kc-btn-text" value="<?php echo esc_attr($btn_text); ?>" placeholder="Pay with KasWare" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:5px;font-size:13px;">
                    </div>

                    <div style="margin-bottom:24px;">
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:5px;">Payment Instructions</label>
                        <textarea id="kc-instructions" rows="3" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:5px;font-size:13px;resize:vertical;" placeholder="Send the exact KAS amount to the address above..."><?php echo esc_textarea($instruct); ?></textarea>
                    </div>

                    <h3 style="margin-top:0;margin-bottom:20px;font-size:15px;border-bottom:1px solid #eee;padding-bottom:10px;">Surcharge <span style="font-size:11px;color:#49eacb;font-weight:700;letter-spacing:0.5px;">PRO</span></h3>

                    <div style="margin-bottom:14px;display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" id="kc-fee-enabled" <?php checked($fee_enabled, true); ?> style="width:16px;height:16px;">
                        <label for="kc-fee-enabled" style="font-size:13px;cursor:pointer;">Enable crypto surcharge</label>
                    </div>

                    <div id="kc-fee-fields" style="<?php echo $fee_enabled ? '' : 'display:none;'; ?>margin-bottom:16px;">
                        <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px;">
                            <select id="kc-fee-type" style="flex:1;padding:8px 10px;border:1px solid #ddd;border-radius:5px;font-size:13px;">
                                <option value="percent" <?php selected($fee_type, 'percent'); ?>>Percentage (%)</option>
                                <option value="flat" <?php selected($fee_type, 'flat'); ?>>Flat Amount</option>
                            </select>
                            <input type="number" id="kc-fee-amount" value="<?php echo esc_attr($fee_amount); ?>" min="0" step="0.01" style="width:90px;padding:8px 10px;border:1px solid #ddd;border-radius:5px;font-size:13px;">
                        </div>
                        <p style="font-size:11px;color:#888;margin:0;">e.g. 2 = 2% fee. Added to order before KAS conversion.</p>
                    </div>

                    <button type="button" id="kc-save-btn" style="width:100%;background:#49eacb;color:#0a0e1a;border:none;padding:11px;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer;">
                        Save Changes
                    </button>
                </div>

                <!-- Live Preview -->
                <div>
                    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 20px 12px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
                        <span style="font-size:13px;color:#666;">Live preview — updates as you type</span>
                        <span style="font-size:11px;background:#f0f0f0;padding:3px 8px;border-radius:4px;color:#555;">Order #1234 · $49.99</span>
                    </div>
                    <div id="kc-preview" style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:28px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">

                        <!-- Payment Header -->
                        <div style="text-align:center;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid #eee;">
                            <h2 id="kcp-heading" style="font-size:22px;font-weight:700;margin:0 0 6px;">Pay 421.85 KAS</h2>
                            <div style="font-size:13px;color:#888;">Rate: $0.11842 &nbsp;·&nbsp; Updated: 12:34:56</div>
                        </div>

                        <!-- KasWare Section -->
                        <div style="background:#f7f7f7;border-radius:10px;padding:16px;margin-bottom:16px;text-align:center;">
                            <div style="font-size:12px;color:#666;margin-bottom:10px;">
                                <span id="kcp-badge-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px;vertical-align:middle;"></span>
                                KasWare Wallet Detected
                            </div>
                            <button id="kcp-kasware-btn" style="border:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;width:100%;">
                                Pay with KasWare
                            </button>
                        </div>

                        <!-- Divider -->
                        <div style="text-align:center;color:#aaa;font-size:12px;margin:14px 0;position:relative;">
                            <span style="background:#fff;padding:0 10px;position:relative;z-index:1;">or pay manually</span>
                            <div style="position:absolute;top:50%;left:0;right:0;height:1px;background:#eee;z-index:0;"></div>
                        </div>

                        <!-- QR + Address -->
                        <div style="display:flex;gap:20px;align-items:flex-start;margin-bottom:16px;">
                            <div style="text-align:center;flex-shrink:0;">
                                <div style="width:120px;height:120px;background:#f0f0f0;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:11px;color:#aaa;border:2px dashed #ddd;">
                                    QR Code
                                </div>
                                <p style="font-size:11px;color:#888;margin:6px 0 0;">Scan to pay</p>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="margin-bottom:10px;">
                                    <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;">Payment Address</label>
                                    <div style="background:#f7f7f7;border:1px solid #eee;border-radius:6px;padding:8px 10px;font-family:monospace;font-size:11px;color:#333;word-break:break-all;">
                                        kaspa:qr9j2...x7km4
                                    </div>
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;">Amount to Send</label>
                                    <div style="background:#f7f7f7;border:1px solid #eee;border-radius:6px;padding:8px 10px;font-family:monospace;font-size:13px;font-weight:600;color:#333;">
                                        421.85000000 KAS
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Fee label -->
                        <div id="kcp-fee-label" style="display:none;font-size:12px;color:#888;margin-bottom:12px;padding:6px 10px;background:#f9f9f9;border-radius:5px;"></div>

                        <!-- Status -->
                        <div style="text-align:center;padding:10px;background:#fff8e1;border-radius:6px;margin-bottom:14px;font-size:13px;color:#856404;">
                            ⏳ Waiting for payment...
                        </div>

                        <!-- Instructions -->
                        <div id="kcp-instructions" style="font-size:13px;color:#555;line-height:1.6;border-top:1px solid #eee;padding-top:12px;"></div>

                    </div>
                </div>

            </div>
        </div>

        <script>
        (function() {
            const accent    = document.getElementById('kc-accent-color');
            const accentHex = document.getElementById('kc-accent-hex');
            const btnText   = document.getElementById('kc-btn-text');
            const instruct  = document.getElementById('kc-instructions');
            const feeCheck  = document.getElementById('kc-fee-enabled');
            const feeFields = document.getElementById('kc-fee-fields');
            const feeType   = document.getElementById('kc-fee-type');
            const feeAmt    = document.getElementById('kc-fee-amount');
            const saveBtn   = document.getElementById('kc-save-btn');
            const resetBtn  = document.getElementById('kc-reset-color');

            // Preview elements
            const pHeading  = document.getElementById('kcp-heading');
            const pBtn      = document.getElementById('kcp-kasware-btn');
            const pBadgeDot = document.getElementById('kcp-badge-dot');
            const pFeeLabel = document.getElementById('kcp-fee-label');
            const pInstruct = document.getElementById('kcp-instructions');

            function applyAccent(color) {
                pBtn.style.background = color;
                pBtn.style.color = '#0a0e1a';
                pHeading.style.color = color;
                pBadgeDot.style.background = color;
                saveBtn.style.background = color;
            }

            function applyButtonText(text) {
                pBtn.textContent = (text || 'Pay with KasWare') + ' — 421.85 KAS';
            }

            function applyInstructions(text) {
                pInstruct.textContent = text || '';
            }

            function applyFeeLabel() {
                if (!feeCheck.checked) { pFeeLabel.style.display = 'none'; return; }
                const amt = parseFloat(feeAmt.value) || 0;
                if (!amt) { pFeeLabel.style.display = 'none'; return; }
                pFeeLabel.style.display = 'block';
                pFeeLabel.textContent = feeType.value === 'percent'
                    ? 'Includes ' + amt + '% crypto surcharge'
                    : 'Includes $' + amt.toFixed(2) + ' flat surcharge';
            }

            // Initialise preview
            applyAccent(accent.value);
            applyButtonText(btnText.value);
            applyInstructions(instruct.value);
            applyFeeLabel();

            // Live updates
            accent.addEventListener('input', function() {
                accentHex.value = accent.value;
                applyAccent(accent.value);
            });
            accentHex.addEventListener('input', function() {
                if (/^#[0-9a-f]{6}$/i.test(accentHex.value)) {
                    accent.value = accentHex.value;
                    applyAccent(accentHex.value);
                }
            });
            resetBtn.addEventListener('click', function() {
                accent.value = '#49eacb';
                accentHex.value = '#49eacb';
                applyAccent('#49eacb');
            });
            btnText.addEventListener('input', function() { applyButtonText(btnText.value); });
            instruct.addEventListener('input', function() { applyInstructions(instruct.value); });
            feeCheck.addEventListener('change', function() {
                feeFields.style.display = feeCheck.checked ? '' : 'none';
                applyFeeLabel();
            });
            feeType.addEventListener('change', applyFeeLabel);
            feeAmt.addEventListener('input', applyFeeLabel);

            // Save
            saveBtn.addEventListener('click', function() {
                saveBtn.disabled = true;
                saveBtn.textContent = 'Saving...';

                const data = new URLSearchParams({
                    action:             'kasppaga_save_customizer',
                    nonce:              <?php echo wp_json_encode($nonce); ?>,
                    pro_accent_color:   accent.value,
                    pro_button_text:    btnText.value,
                    pro_instructions:   instruct.value,
                    pro_title:          document.getElementById('kc-title').value,
                    pro_description:    document.getElementById('kc-description').value,
                    pro_show_logo:      document.getElementById('kc-show-logo').checked ? 'yes' : 'no',
                    pro_fee_enabled:    feeCheck.checked ? 'yes' : 'no',
                    pro_fee_type:       feeType.value,
                    pro_fee_amount:     feeAmt.value || '0',
                });

                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: data.toString(),
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    const msg = document.getElementById('kaspa-customizer-saved');
                    if (res.success) {
                        msg.style.display = 'block';
                        setTimeout(function() { msg.style.display = 'none'; }, 3000);
                    } else {
                        alert('Save failed. Please try again.');
                    }
                })
                .finally(function() {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save Changes';
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Analytics Page
     */
    public function render_analytics_page()
    {
        $analytics = $this->get_analytics_data();
        ?>
        <div class="wrap kaspa-analytics" style="max-width: 1200px; margin: 0 auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h1 style="margin: 0; font-size: 23px;">Analytics</h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=kaspa-payments-gateway')); ?>" class="button">
                    ← Dashboard
                </a>
            </div>

            <!-- Key Metrics -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-bottom: 20px;">
                <div style="background: #fff; padding: 18px; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                    <h3 style="margin: 0 0 12px 0; font-size: 13px; font-weight: 600; color: #757575; text-transform: uppercase; letter-spacing: 0.5px;">Payment Performance</h3>
                    <div>
                        <div style="margin: 10px 0;">
                            <div style="font-size: 11px; color: #757575; margin-bottom: 3px;">Average Order Value</div>
                            <div style="font-size: 20px; font-weight: 700; color: #1d2327;"><?php echo esc_html($analytics['avg_order_value']); ?> KAS</div>
                        </div>
                        <div style="margin: 10px 0;">
                            <div style="font-size: 11px; color: #757575; margin-bottom: 3px;">Success Rate</div>
                            <div style="font-size: 20px; font-weight: 700; color: #1d2327;"><?php echo esc_html($analytics['success_rate']); ?>%</div>
                        </div>
                        <div style="margin: 10px 0;">
                            <div style="font-size: 11px; color: #757575; margin-bottom: 3px;">Total Volume</div>
                            <div style="font-size: 20px; font-weight: 700; color: #1d2327;"><?php echo esc_html($analytics['total_volume']); ?> KAS</div>
                        </div>
                    </div>
                </div>

                <div style="background: #fff; padding: 18px; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                    <h3 style="margin: 0 0 12px 0; font-size: 13px; font-weight: 600; color: #757575; text-transform: uppercase; letter-spacing: 0.5px;">Customer Insights</h3>
                    <div>
                        <div style="margin: 10px 0;">
                            <div style="font-size: 11px; color: #757575; margin-bottom: 3px;">Unique Customers</div>
                            <div style="font-size: 20px; font-weight: 700; color: #1d2327;"><?php echo esc_html($analytics['unique_customers']); ?></div>
                        </div>
                        <div style="margin: 10px 0;">
                            <div style="font-size: 11px; color: #757575; margin-bottom: 3px;">Repeat Customers</div>
                            <div style="font-size: 20px; font-weight: 700; color: #1d2327;"><?php echo esc_html($analytics['repeat_customers']); ?>%</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Trends -->
            <?php if (!empty($analytics['daily_trends'])): ?>
                <div style="background: #fff; padding: 18px; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                    <h3 style="margin: 0 0 14px 0; font-size: 15px; font-weight: 600; color: #1d2327;">Recent Activity (Last 7 Days)</h3>
                    <table class="wp-list-table widefat fixed striped" style="font-size: 13px;">
                        <thead>
                            <tr>
                                <th style="padding: 8px;">Date</th>
                                <th style="padding: 8px;">Orders</th>
                                <th style="padding: 8px;">Revenue (KAS)</th>
                                <th style="padding: 8px;">Revenue (USD)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($analytics['daily_trends'], -7) as $day): ?>
                                <tr>
                                    <td style="padding: 8px;"><?php echo esc_html($day['date']); ?></td>
                                    <td style="padding: 8px;">
                                        <a href="<?php 
                                            // Link to orders for this specific date
                                            echo esc_url(admin_url('edit.php?post_type=shop_order&payment_method=kaspa&m=' . date('Ymd', strtotime($day['date'])))); 
                                        ?>">
                                            <strong><?php echo esc_html($day['orders']); ?> orders</strong>
                                        </a>
                                    </td>
                                    <td style="padding: 8px;"><?php echo esc_html($day['revenue_kas']); ?></td>
                                    <td style="padding: 8px;">$<?php echo esc_html($day['revenue_usd']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 30px; background: #f9f9f9; border-radius: 8px; color: #757575;">
                    <div style="font-weight: 600; margin-bottom: 4px;">No Analytics Data Yet</div>
                    <div style="font-size: 12px;">Start receiving Kaspa payments to see detailed analytics</div>
                </div>
            <?php endif; ?>

            <div style="margin-top: 20px; padding: 16px; border-top: 1px solid #e8e8e8; display: flex; gap: 12px; align-items: center;">
                <span style="color: #757575; font-size: 12px;">Quick Links:</span>
                <a href="<?php echo esc_url(admin_url('admin.php?page=kaspa-wallet-setup')); ?>" style="text-decoration: none; font-size: 13px;">Wallet</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=kaspa')); ?>" style="text-decoration: none; font-size: 13px;">Settings</a>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=shop_order&payment_method=kaspa')); ?>" style="text-decoration: none; font-size: 13px;">Orders</a>
            </div>
        </div>
        <?php
    }

    /**
     * Help & FAQ Page
     */
    /**
     * Redirect to WooCommerce gateway settings page
     */
    public function redirect_to_settings()
    {
        wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=kaspa'));
        exit;
    }

    public function render_help_page()
    {
        ?>
        <div class="wrap kaspa-help" style="max-width: 1200px; margin: 0 auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h1 style="margin: 0; font-size: 23px;">Help & Knowledge Center</h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=kaspa-payments-gateway')); ?>" class="button">
                    ← Dashboard
                </a>
            </div>

            <div style="background: linear-gradient(135deg, #70D0F0 0%, #49a8d4 100%); color: #fff; padding: 30px; border-radius: 8px; margin-bottom: 20px;">
                <h2 style="margin: 0 0 10px 0; color: #fff;">Welcome to Kaspa Payments Gateway</h2>
                <p style="margin: 0; font-size: 14px; opacity: 0.95;">A secure, watch-only payment gateway for WooCommerce using Kaspa's KPUB technology. Find answers to common questions and learn best practices below.</p>
            </div>

            <!-- Quick Navigation -->
            <div id="kaspa-faq-nav" style="background: #fff; padding: 12px 20px; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 20px; position: sticky; top: 32px; z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                    <span style="font-size: 12px; color: #757575; font-weight: 600; margin-right: 8px;">Jump to:</span>
                    <a href="#getting-started" class="kaspa-nav-tab" style="padding: 6px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; color: #646970; background: #f6f7f7; transition: all 0.2s;" onmouseover="this.style.background='#e0e0e0'" onmouseout="if(!this.classList.contains('active')) this.style.background='#f6f7f7'">Getting Started</a>
                    <a href="#how-it-works" class="kaspa-nav-tab" style="padding: 6px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; color: #646970; background: #f6f7f7; transition: all 0.2s;" onmouseover="this.style.background='#e0e0e0'" onmouseout="if(!this.classList.contains('active')) this.style.background='#f6f7f7'">How It Works</a>
                    <a href="#troubleshooting" class="kaspa-nav-tab" style="padding: 6px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; color: #646970; background: #f6f7f7; transition: all 0.2s;" onmouseover="this.style.background='#e0e0e0'" onmouseout="if(!this.classList.contains('active')) this.style.background='#f6f7f7'">Troubleshooting</a>
                    <a href="#security" class="kaspa-nav-tab" style="padding: 6px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; color: #646970; background: #f6f7f7; transition: all 0.2s;" onmouseover="this.style.background='#e0e0e0'" onmouseout="if(!this.classList.contains('active')) this.style.background='#f6f7f7'">Security</a>
                    <a href="#technical" class="kaspa-nav-tab" style="padding: 6px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; color: #646970; background: #f6f7f7; transition: all 0.2s;" onmouseover="this.style.background='#e0e0e0'" onmouseout="if(!this.classList.contains('active')) this.style.background='#f6f7f7'">Technical</a>
                </div>
            </div>

            <!-- FAQ Sections -->
            <div style="display: grid; gap: 20px;">
                
                <!-- Getting Started -->
                <div id="getting-started" class="kaspa-faq-section" style="background: #fff; padding: 24px; border-radius: 8px; border: 1px solid #e0e0e0; scroll-margin-top: 120px;">
                    <h2 style="margin: 0 0 16px 0; font-size: 18px; color: #1d2327; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-admin-network" style="color: #70D0F0;"></span>
                        Getting Started
                    </h2>
                    
                    <div class="kaspa-faq-item" style="margin-bottom: 20px;">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">What is a KPUB (Extended Public Key)?</h3>
                        <p style="margin: 0; color: #646970; line-height: 1.6;">A KPUB is an Extended Public Key for Kaspa HD wallets. It's a master key that allows you to generate unlimited receiving addresses without exposing your private keys. Think of it like a "view-only" key - it can create new addresses and check balances, but it cannot spend any funds. This makes it perfect for e-commerce where security is paramount.</p>
                    </div>

                    <div class="kaspa-faq-item" style="margin-bottom: 20px;">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">How do I get my KPUB?</h3>
                        <p style="margin: 0 0 8px 0; color: #646970; line-height: 1.6;">You can export your KPUB from most Kaspa wallet software:</p>
                        <ul style="margin: 0; padding-left: 20px; color: #646970;">
                            <li><strong>Kaspium:</strong> Settings → Advanced → Export Extended Public Key</li>
                            <li><strong>KDX:</strong> Wallet → Settings → Extended Public Key</li>
                            <li><strong>Other wallets:</strong> Look for "Export XPUB" or "Extended Public Key" in settings</li>
                        </ul>
                        <p style="margin: 8px 0 0 0; color: #646970; line-height: 1.6;">The KPUB starts with "kpub" and is approximately 111 characters long.</p>
                    </div>

                    <div class="kaspa-faq-item" style="margin-bottom: 20px;">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">Is my KPUB safe to store on my server?</h3>
                        <p style="margin: 0; color: #646970; line-height: 1.6;"><strong>Yes!</strong> A KPUB cannot spend funds - it can only generate addresses and view balances. Even if someone gains access to your KPUB, they cannot steal your Kaspa. Your private keys remain secure in your wallet software. However, they could see your transaction history, so treat it with reasonable care (like you would any business data).</p>
                    </div>

                    <div class="kaspa-faq-item">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">Do I need to run a Kaspa node?</h3>
                        <p style="margin: 0; color: #646970; line-height: 1.6;">No! This plugin uses public Kaspa blockchain explorers and APIs to check payments. There's no need to run your own node or any special infrastructure. Everything runs through standard WordPress/PHP.</p>
                    </div>
                </div>

                <!-- How It Works -->
                <div id="how-it-works" class="kaspa-faq-section" style="background: #fff; padding: 24px; border-radius: 8px; border: 1px solid #e0e0e0; scroll-margin-top: 120px;">
                    <h2 style="margin: 0 0 16px 0; font-size: 18px; color: #1d2327; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-admin-tools" style="color: #70D0F0;"></span>
                        How It Works
                    </h2>
                    
                    <div class="kaspa-faq-item" style="margin-bottom: 20px;">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">How are payment addresses generated?</h3>
                        <p style="margin: 0 0 8px 0; color: #646970; line-height: 1.6;">When a customer chooses Kaspa as their payment method:</p>
                        <ol style="margin: 0; padding-left: 20px; color: #646970;">
                            <li>The plugin derives a unique address from your KPUB using the order ID as the derivation index</li>
                            <li>This address is displayed to the customer with a QR code</li>
                            <li>The address is stored with the order for tracking</li>
                            <li>The plugin monitors this specific address for incoming payments</li>
                        </ol>
                        <p style="margin: 8px 0 0 0; color: #646970; line-height: 1.6;">Each order gets its own unique address, making payment tracking simple and secure.</p>
                    </div>

                    <div class="kaspa-faq-item" style="margin-bottom: 20px;">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">How does the plugin detect payments?</h3>
                        <p style="margin: 0; color: #646970; line-height: 1.6;">The plugin uses WordPress's WP-Cron system to automatically check pending orders every 2 minutes. It queries the Kaspa blockchain API to check if the payment address has received the expected amount. When payment is confirmed, the order status is automatically updated to "Processing" or "Completed".</p>
                    </div>

                    <div class="kaspa-faq-item" style="margin-bottom: 20px;">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">What are the price sources and how do fallbacks work?</h3>
                        <p style="margin: 0 0 8px 0; color: #646970; line-height: 1.6;">The plugin supports 8 different price APIs (all spot markets, no futures/perpetuals):</p>
                        <ul style="margin: 0 0 8px 0; padding-left: 20px; color: #646970;">
                            <li>Kaspa API (api.kaspa.org)</li>
                            <li>CoinGecko</li>
                            <li>CryptoCompare</li>
                            <li>MEXC</li>
                            <li>KuCoin</li>
                            <li>Gate.io</li>
                            <li>HTX (Huobi)</li>
                            <li>CoinEx</li>
                        </ul>
                        <p style="margin: 0; color: #646970; line-height: 1.6;">You configure a primary, secondary, and tertiary source. If the primary fails, the plugin automatically tries the secondary, then tertiary. Prices are cached for 5 minutes to reduce API calls.</p>
                    </div>

                    <div class="kaspa-faq-item">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">What happens if a customer underpays?</h3>
                        <p style="margin: 0; color: #646970; line-height: 1.6;">The plugin checks for exact or overpayment. If a customer sends less than required, the order stays in "Pending Payment" status. You can manually review underpaid orders and either request the remaining amount, issue a partial refund, or cancel the order.</p>
                    </div>
                </div>

                <!-- Troubleshooting -->
                <div id="troubleshooting" class="kaspa-faq-section" style="background: #fff; padding: 24px; border-radius: 8px; border: 1px solid #e0e0e0; scroll-margin-top: 120px;">
                    <h2 style="margin: 0 0 16px 0; font-size: 18px; color: #1d2327; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-sos" style="color: #70D0F0;"></span>
                        Troubleshooting
                    </h2>
                    
                    <div class="kaspa-faq-item" style="margin-bottom: 20px;">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">Payments aren't being detected automatically</h3>
                        <p style="margin: 0 0 8px 0; color: #646970; line-height: 1.6;"><strong>Check these common issues:</strong></p>
                        <ul style="margin: 0; padding-left: 20px; color: #646970;">
                            <li><strong>WP-Cron disabled:</strong> Some hosts disable WP-Cron. Check with your host or set up a real cron job</li>
                            <li><strong>Low traffic site:</strong> WP-Cron only runs when your site gets visitors. Consider setting up a real cron job that hits <code>wp-cron.php</code></li>
                            <li><strong>Firewall/API blocking:</strong> Ensure your server can make outbound HTTPS requests to Kaspa APIs</li>
                            <li><strong>Check Monitoring status:</strong> Look at the dashboard - the "Monitoring" indicator should be green</li>
                        </ul>
                    </div>

                    <div class="kaspa-faq-item" style="margin-bottom: 20px;">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">Price API shows red/unavailable</h3>
                        <p style="margin: 0 0 8px 0; color: #646970; line-height: 1.6;"><strong>Try these steps:</strong></p>
                        <ul style="margin: 0; padding-left: 20px; color: #646970;">
                            <li>Check if your server can make HTTPS requests to external APIs</li>
                            <li>Try switching to a different primary price source in settings</li>
                            <li>Contact your host to ensure outbound API calls aren't blocked</li>
                            <li>Check WordPress error logs for specific API error messages</li>
                        </ul>
                    </div>

                    <div class="kaspa-faq-item" style="margin-bottom: 20px;">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">Balance isn't showing all my funds</h3>
                        <p style="margin: 0; color: #646970; line-height: 1.6;">The balance checker only shows funds in addresses generated by the plugin (order addresses). If you've sent funds directly to other addresses in your KPUB wallet, they won't appear here. This is intentional - the plugin only tracks order-related addresses to keep performance fast.</p>
                    </div>

                    <div class="kaspa-faq-item">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">Customer says they paid but order is still pending</h3>
                        <p style="margin: 0 0 8px 0; color: #646970; line-height: 1.6;"><strong>Manual verification steps:</strong></p>
                        <ol style="margin: 0; padding-left: 20px; color: #646970;">
                            <li>Check the order details for the payment address</li>
                            <li>Look up that address on a Kaspa block explorer (e.g., explorer.kaspa.org)</li>
                            <li>Verify the payment amount and transaction hash</li>
                            <li>If payment is confirmed on-chain but not in WooCommerce, click "Check Payment Status" on the order edit page</li>
                        </ol>
                    </div>
                </div>

                <!-- Security & Best Practices -->
                <div id="security" class="kaspa-faq-section" style="background: #fff; padding: 24px; border-radius: 8px; border: 1px solid #e0e0e0; scroll-margin-top: 120px;">
                    <h2 style="margin: 0 0 16px 0; font-size: 18px; color: #1d2327; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-shield" style="color: #70D0F0;"></span>
                        Security & Best Practices
                    </h2>
                    
                    <div class="kaspa-faq-item" style="margin-bottom: 20px;">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">How should I manage my private keys?</h3>
                        <p style="margin: 0 0 8px 0; color: #646970; line-height: 1.6;"><strong>Critical security practices:</strong></p>
                        <ul style="margin: 0; padding-left: 20px; color: #646970;">
                            <li><strong>Never store private keys on your server</strong> - only the KPUB goes on the server</li>
                            <li>Keep your private keys/mnemonic in secure wallet software (hardware wallet recommended)</li>
                            <li>Regularly sweep funds from your payment wallet to cold storage</li>
                            <li>Back up your mnemonic phrase in a secure, offline location</li>
                            <li>Use a dedicated wallet for payments, separate from your main holdings</li>
                        </ul>
                    </div>

                    <div class="kaspa-faq-item" style="margin-bottom: 20px;">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">Should I sweep payments to cold storage?</h3>
                        <p style="margin: 0; color: #646970; line-height: 1.6;"><strong>Yes, regularly!</strong> While your KPUB can't spend funds, best practice is to periodically move accumulated payments to a cold storage wallet. This limits exposure if your server is ever compromised. You can continue using the same KPUB after sweeping - it will keep generating new addresses for future orders.</p>
                    </div>

                    <div class="kaspa-faq-item" style="margin-bottom: 20px;">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">What about PCI compliance?</h3>
                        <p style="margin: 0; color: #646970; line-height: 1.6;">Cryptocurrency payments are generally outside the scope of PCI-DSS since you're not handling credit card data. However, you should still follow general security best practices: keep WordPress and plugins updated, use HTTPS, implement strong admin passwords, and regular backups.</p>
                    </div>

                    <div class="kaspa-faq-item">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">What data does the plugin store?</h3>
                        <p style="margin: 0 0 8px 0; color: #646970; line-height: 1.6;">The plugin stores:</p>
                        <ul style="margin: 0; padding-left: 20px; color: #646970;">
                            <li>Your KPUB (extended public key) - encrypted in WordPress database</li>
                            <li>Generated payment addresses per order</li>
                            <li>Transaction IDs when payments are confirmed</li>
                            <li>Expected and received payment amounts</li>
                        </ul>
                        <p style="margin: 8px 0 0 0; color: #646970; line-height: 1.6;">No private keys, mnemonics, or spending capabilities are ever stored.</p>
                    </div>
                </div>

                <!-- Technical Details -->
                <div id="technical" class="kaspa-faq-section" style="background: #fff; padding: 24px; border-radius: 8px; border: 1px solid #e0e0e0; scroll-margin-top: 120px;">
                    <h2 style="margin: 0 0 16px 0; font-size: 18px; color: #1d2327; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-admin-settings" style="color: #70D0F0;"></span>
                        Technical Details
                    </h2>
                    
                    <div class="kaspa-faq-item" style="margin-bottom: 20px;">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">What blockchain APIs does the plugin use?</h3>
                        <p style="margin: 0 0 8px 0; color: #646970; line-height: 1.6;">The plugin uses:</p>
                        <ul style="margin: 0; padding-left: 20px; color: #646970;">
                            <li><strong>Price data:</strong> Multiple exchange APIs (configurable)</li>
                            <li><strong>Balance checks:</strong> api.kaspa.org for address balances</li>
                            <li><strong>Transaction verification:</strong> api.kaspa.org for UTXOs and confirmations</li>
                        </ul>
                        <p style="margin: 8px 0 0 0; color: #646970; line-height: 1.6;">All connections use HTTPS. The plugin includes fallback logic if an API is temporarily unavailable.</p>
                    </div>

                    <div class="kaspa-faq-item" style="margin-bottom: 20px;">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">How often does the plugin check for payments?</h3>
                        <p style="margin: 0; color: #646970; line-height: 1.6;">The background monitoring system checks all pending Kaspa orders every 2 minutes using WP-Cron. When you're viewing an order page, you can also manually trigger a check with the "Check Payment Status" button.</p>
                    </div>

                    <div class="kaspa-faq-item" style="margin-bottom: 20px;">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">Does this work with HPOS (High-Performance Order Storage)?</h3>
                        <p style="margin: 0; color: #646970; line-height: 1.6;">Yes! The plugin is fully compatible with WooCommerce's HPOS feature. It uses WooCommerce's standard order meta APIs which work with both traditional and HPOS storage.</p>
                    </div>

                    <div class="kaspa-faq-item">
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #2c3338;">Can I use this on a multisite installation?</h3>
                        <p style="margin: 0; color: #646970; line-height: 1.6;">The plugin works on WordPress multisite, but each site needs its own WooCommerce installation and separate KPUB configuration. Payment tracking is site-specific.</p>
                    </div>
                </div>

                <!-- Support -->
                <div style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 24px; border-radius: 8px; border: 1px solid #e0e0e0; text-align: center;">
                    <h2 style="margin: 0 0 12px 0; font-size: 18px; color: #1d2327;">Still Need Help?</h2>
                    <p style="margin: 0 0 16px 0; color: #646970;">Can't find what you're looking for? We're here to help!</p>
                    <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                        <a href="https://wordpress.org/support/plugin/kaspa-payments-gateway-woocommerce/" target="_blank" class="button button-primary">
                            Visit Support Forum
                        </a>
                        <a href="https://github.com/jacoborbach/kaspa-payments-gateway-woocommerce/issues" target="_blank" class="button button-secondary">
                            Report an Issue
                        </a>
                        <a href="https://kaspa.org" target="_blank" class="button button-secondary">
                            Learn About Kaspa
                        </a>
                    </div>
                </div>

                <!-- Review Link -->
                <div style="text-align: center; padding: 16px 0; margin-top: 20px;">
                    <p style="margin: 0; font-size: 12px; color: #646970;">
                        Like this plugin? <a href="https://wordpress.org/plugins/kaspa-payments-gateway-woocommerce/#reviews" target="_blank" rel="noopener noreferrer" style="color: #2271b1; text-decoration: none; font-weight: 600;">Leave a 5-star review ⭐⭐⭐⭐⭐</a>
                    </p>
                </div>

            </div>
        </div>

        <script>
        (function() {
            // Smooth scroll to sections
            document.querySelectorAll('.kaspa-nav-tab').forEach(function(tab) {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    var targetId = this.getAttribute('href').substring(1);
                    var targetElement = document.getElementById(targetId);
                    if (targetElement) {
                        targetElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        // Update active state immediately
                        updateActiveTab(this);
                    }
                });
            });

            // Update active tab highlighting
            function updateActiveTab(activeTab) {
                document.querySelectorAll('.kaspa-nav-tab').forEach(function(tab) {
                    tab.classList.remove('active');
                    tab.style.background = '#f6f7f7';
                    tab.style.color = '#646970';
                    tab.style.fontWeight = 'normal';
                });
                activeTab.classList.add('active');
                activeTab.style.background = '#70D0F0';
                activeTab.style.color = '#fff';
                activeTab.style.fontWeight = '600';
            }

            // Highlight active section on scroll
            var sections = document.querySelectorAll('.kaspa-faq-section');
            var navTabs = document.querySelectorAll('.kaspa-nav-tab');
            
            function highlightNavOnScroll() {
                var scrollPos = window.scrollY + 150; // Offset for sticky nav
                
                sections.forEach(function(section, index) {
                    var sectionTop = section.offsetTop;
                    var sectionBottom = sectionTop + section.offsetHeight;
                    
                    if (scrollPos >= sectionTop && scrollPos < sectionBottom) {
                        updateActiveTab(navTabs[index]);
                    }
                });
            }

            // Debounce scroll event for performance
            var scrollTimeout;
            window.addEventListener('scroll', function() {
                if (scrollTimeout) {
                    window.cancelAnimationFrame(scrollTimeout);
                }
                scrollTimeout = window.requestAnimationFrame(function() {
                    highlightNavOnScroll();
                });
            });

            // Set initial active tab on page load
            if (window.location.hash) {
                var initialTab = document.querySelector('.kaspa-nav-tab[href="' + window.location.hash + '"]');
                if (initialTab) {
                    setTimeout(function() {
                        updateActiveTab(initialTab);
                    }, 100);
                }
            } else {
                // Default to first tab
                updateActiveTab(navTabs[0]);
            }
        })();
        </script>
        <?php
    }

    // Customize page removed - streamlined version without customization options

    /**
     * Get payment statistics
     */
    private function get_payment_stats()
    {
        $orders = wc_get_orders(array(
            'payment_method' => 'kaspa',
            'limit' => -1,
            'status' => array('completed', 'processing')
        ));

        $all_attempts = wc_get_orders(array(
            'payment_method' => 'kaspa',
            'limit' => -1
        ));

        $stats = array(
            'total_orders' => count($orders),
            'total_revenue_kas' => 0,
            'total_revenue_usd' => 0,
            'orders_this_month' => 0,
            'success_rate' => 0,
            'total_attempts' => count($all_attempts)
        );

        $month_start = strtotime('first day of this month');

        foreach ($orders as $order) {
            $kas_amount = $order->get_meta('_kaspa_confirmed_amount') ?: $order->get_meta('_kaspa_expected_amount');
            $stats['total_revenue_kas'] += floatval($kas_amount);
            $stats['total_revenue_usd'] += floatval($order->get_total());

            if ($order->get_date_created()->getTimestamp() >= $month_start) {
                $stats['orders_this_month']++;
            }
        }

        // Calculate success rate
        if (count($all_attempts) > 0) {
            $stats['success_rate'] = round((count($orders) / count($all_attempts)) * 100, 1);
        }

        return $stats;
    }

    /**
     * Get recent orders
     */
    private function get_recent_kaspa_orders($limit = 10)
    {
        $orders = wc_get_orders(array(
            'payment_method' => 'kaspa',
            'limit' => $limit,
            'orderby' => 'date',
            'order' => 'DESC'
        ));

        $recent_orders = array();
        foreach ($orders as $order) {
            $recent_orders[] = array(
                'id' => $order->get_id(),
                'date' => $order->get_date_created()->format('M j, Y'),
                'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'kas_amount' => $order->get_meta('_kaspa_confirmed_amount') ?: $order->get_meta('_kaspa_expected_amount') ?: '0',
                'usd_amount' => number_format($order->get_total(), 2),
                'status' => $order->get_status(),
                'edit_url' => $order->get_edit_order_url()
            );
        }

        return $recent_orders;
    }

    /**
     * Get analytics data
     */
    private function get_analytics_data()
    {
        $orders = wc_get_orders(array(
            'payment_method' => 'kaspa',
            'limit' => -1,
            'status' => array('completed', 'processing')
        ));

        $all_attempts = wc_get_orders(array(
            'payment_method' => 'kaspa',
            'limit' => -1
        ));

        $total_revenue_kas = 0;
        $unique_customers = array();
        $daily_data = array();

        foreach ($orders as $order) {
            $kas_amount = floatval($order->get_meta('_kaspa_confirmed_amount') ?: $order->get_meta('_kaspa_expected_amount'));
            $total_revenue_kas += $kas_amount;

            // Track unique customers
            $customer_email = $order->get_billing_email();
            if ($customer_email && !in_array($customer_email, $unique_customers)) {
                $unique_customers[] = $customer_email;
            }

            // Group by day
            $order_date = $order->get_date_created()->format('Y-m-d');
            if (!isset($daily_data[$order_date])) {
                $daily_data[$order_date] = array(
                    'orders' => 0,
                    'revenue_kas' => 0,
                    'revenue_usd' => 0
                );
            }
            $daily_data[$order_date]['orders']++;
            $daily_data[$order_date]['revenue_kas'] += $kas_amount;
            $daily_data[$order_date]['revenue_usd'] += floatval($order->get_total());
        }

        // Calculate metrics
        $avg_order_value = count($orders) > 0 ? $total_revenue_kas / count($orders) : 0;
        $success_rate = count($all_attempts) > 0 ? (count($orders) / count($all_attempts)) * 100 : 0;

        // Calculate repeat customers
        $customer_counts = array_count_values(array_map(function ($order) {
            return $order->get_billing_email();
        }, $orders));

        $repeat_customers = 0;
        foreach ($customer_counts as $email => $count) {
            if ($count > 1) {
                $repeat_customers++;
            }
        }
        $repeat_customer_rate = count($unique_customers) > 0 ? ($repeat_customers / count($unique_customers)) * 100 : 0;

        // Prepare daily trends
        $daily_trends = array();
        foreach ($daily_data as $date => $data) {
            $daily_trends[] = array(
                'date' => gmdate('M j', strtotime($date)),
                'orders' => $data['orders'],
                'revenue_kas' => number_format($data['revenue_kas'], 8),
                'revenue_usd' => number_format($data['revenue_usd'], 2)
            );
        }

        return array(
            'avg_order_value' => number_format($avg_order_value, 8),
            'success_rate' => number_format($success_rate, 1),
            'total_volume' => number_format($total_revenue_kas, 8),
            'unique_customers' => count($unique_customers),
            'repeat_customers' => number_format($repeat_customer_rate, 1),
            'daily_trends' => $daily_trends
        );
    }

    /**
     * Get Kaspa icon
     */
    private function get_kaspa_icon()
    {
        return 'data:image/svg+xml;base64,' . base64_encode('
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="10" cy="10" r="8" fill="#70D0F0"/>
                <text x="10" y="14" text-anchor="middle" fill="white" font-size="8" font-weight="bold">K</text>
            </svg>
        ');
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook)
    {
        if (strpos($hook, 'kaspa-') === false) {
            return;
        }

        wp_enqueue_style('kaspa-admin-style', plugin_dir_url(__DIR__) . 'assets/kaspa-admin.css', array(), '1.0.0');
        wp_enqueue_script('kaspa-admin-script', plugin_dir_url(__DIR__) . 'assets/kaspa-admin.js', array('jquery'), '1.0.0', true);
    }

    /**
     * AJAX: Get stats
     */
    public function ajax_get_stats()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $stats = $this->get_payment_stats();
        wp_send_json_success($stats);
    }

    /**
     * Get system health data for dashboard (wallet, rate API, polling).
     */
    private function get_system_health_data()
    {
        $wallet_configured = (bool) get_option('kasppaga_wallet_configured');
        $rate_ok = false;
        $rate_value = null;
        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways['kaspa']) && method_exists($gateways['kaspa'], 'get_kas_rate')) {
                $rate_value = $gateways['kaspa']->get_kas_rate();
                $rate_ok = ($rate_value !== false && $rate_value > 0);
            }
        }
        $next_poll = wp_next_scheduled('kasppaga_poll_payments') ? wp_next_scheduled('kasppaga_poll_payments') : 0;
        $polling_active = ($next_poll > 0);
        $next_poll_in = $polling_active ? max(0, $next_poll - time()) : 0;
        $last_rate_update = get_option('kaspa_rate_last_updated', 0);
        return array(
            'wallet_configured' => $wallet_configured,
            'rate_ok' => $rate_ok,
            'rate_value' => $rate_value,
            'polling_active' => $polling_active,
            'next_poll_seconds' => $next_poll_in,
            'last_rate_update' => $last_rate_update,
        );
    }

    /**
     * Render wallet balance section
     */
    public function render_wallet_balance_section()
    {
        $wallet_configured = get_option('kasppaga_wallet_configured');
        $kpub = get_option('kasppaga_wallet_kpub');

        if (!$wallet_configured || !$kpub) {
            return;
        }
        ?>
        <div class="kaspa-wallet-balance-section">
            <h2>💰 Wallet Balance</h2>
            <p style="color: #666; font-size: 14px; margin-bottom: 15px;">
                <strong>Consolidated Balance:</strong> Shows total KAS received across all order payment addresses generated by
                the plugin.
                <br><small style="color: #d63638;">⚠️ Funds sent manually to other addresses in your KPUB wallet will not appear
                    here.</small>
            </p>
            <div class="kaspa-wallet-balance-card">
                <div class="kaspa-wallet-info">
                    <div class="kaspa-wallet-address">
                        <label>KPUB Wallet:</label>
                        <div class="kaspa-address-display">
                            <?php
                            $kpub = get_option('kasppaga_wallet_kpub', '');
                            if ($kpub) {
                                $kpub_display = substr($kpub, 0, 30) . '...' . substr($kpub, -20);
                                echo '<code style="font-size: 11px;">' . esc_html($kpub_display) . '</code>';
                            } else {
                                echo '<span style="color: #d63638;">⚠️ KPUB not configured</span>';
                            }
                            ?>
                            <?php if ($kpub): ?>
                                <button type="button" class="button button-small"
                                    onclick="copyToClipboard('<?php echo esc_js($kpub); ?>')">📋 Copy KPUB</button>
                            <?php endif; ?>
                        </div>
                        <small style="color: #666; margin-top: 5px; display: block;">
                            ℹ️ Unique addresses are generated per order from your KPUB
                        </small>
                    </div>
                </div>

                <div class="kaspa-balance-display">
                    <div id="kaspa-balance-info" class="kaspa-balance-loading">
                        <div class="kaspa-loading-spinner"></div>
                        <span>Loading balance...</span>
                    </div>
                    <div class="kaspa-balance-actions">
                        <button type="button" class="button button-primary" id="kaspa-refresh-balance">
                            🔄 Refresh Balance
                        </button>
                        <small class="kaspa-last-updated" id="kaspa-last-updated"></small>
                    </div>
                </div>
            </div>
        </div>

        <?php
        // Register and enqueue script for inline JavaScript
        wp_register_script('kaspa-admin-dashboard-inline', '', array('jquery'), '1.0.0', true);
        wp_enqueue_script('kaspa-admin-dashboard-inline');

        // Prepare inline JavaScript
        $ajax_url = esc_url(admin_url('admin-ajax.php'));
        $nonce = esc_js(wp_create_nonce('kasppaga_consolidated_balance'));
        $inline_script = 'document.addEventListener("DOMContentLoaded", function () {
            // Load balance on page load
            loadWalletBalance();

            // Add refresh button event listener
            const refreshBtn = document.getElementById("kaspa-refresh-balance");
            if (refreshBtn) {
                refreshBtn.addEventListener("click", function () {
                    loadWalletBalance();
                });
            }
        });

        function loadWalletBalance() {
            const balanceInfo = document.getElementById("kaspa-balance-info");
            const lastUpdated = document.getElementById("kaspa-last-updated");
            const refreshBtn = document.getElementById("kaspa-refresh-balance");

            if (!balanceInfo || !refreshBtn) {
                return;
            }

            // Show loading state
            balanceInfo.innerHTML = "<div class=\"kaspa-loading-spinner\"></div><span>Loading consolidated balance...</span>";
            balanceInfo.className = "kaspa-balance-loading";
            refreshBtn.disabled = true;
            refreshBtn.textContent = "🔄 Loading...";

            const xhr = new XMLHttpRequest();
            xhr.open("POST", ' . json_encode($ajax_url) . ', true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    refreshBtn.disabled = false;
                    refreshBtn.textContent = "🔄 Refresh Balance";

                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                const balance = parseFloat(response.data.total_balance).toFixed(8);
                                const usdValue = response.data.total_usd_value ? "$" + parseFloat(response.data.total_usd_value).toFixed(2) : "N/A";
                                const kasRate = response.data.kas_rate ? "$" + parseFloat(response.data.kas_rate).toFixed(5) : "N/A";
                                const addressCount = response.data.address_count || 0;

                                balanceInfo.innerHTML = "<div class=\"kaspa-balance-main\"><div class=\"kaspa-balance-amount\"><span class=\"kaspa-balance-kas\">" + balance + " KAS</span><span class=\"kaspa-balance-usd\">" + usdValue + "</span></div><div class=\"kaspa-balance-details\"><small>Consolidated across " + addressCount + " addresses</small><br><small>Current Rate: " + kasRate + " per KAS</small></div></div>";
                                balanceInfo.className = "kaspa-balance-success";

                                if (lastUpdated) {
                                    lastUpdated.textContent = "Last updated: " + new Date().toLocaleString();
                                }
                            } else {
                                balanceInfo.innerHTML = "<div class=\"kaspa-balance-error\">❌ Error: " + (response.data || "Unknown error") + "</div>";
                                balanceInfo.className = "kaspa-balance-error";
                            }
                        } catch (e) {
                            balanceInfo.innerHTML = "<div class=\"kaspa-balance-error\">❌ Error parsing response</div>";
                            balanceInfo.className = "kaspa-balance-error";
                        }
                    } else {
                        balanceInfo.innerHTML = "<div class=\"kaspa-balance-error\">❌ Network error</div>";
                        balanceInfo.className = "kaspa-balance-error";
                    }
                }
            };

            const data = "action=kasppaga_get_consolidated_balance&nonce=" + ' . json_encode($nonce) . ';
            xhr.send(data);
        }

        function copyToClipboard(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    showNotification("✅ Address copied to clipboard!", "success");
                }).catch(function () {
                    fallbackCopy(text);
                });
            } else {
                fallbackCopy(text);
            }
        }

        function fallbackCopy(text) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed";
            textArea.style.left = "-999999px";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                const successful = document.execCommand("copy");
                if (successful) {
                    showNotification("✅ Address copied to clipboard!", "success");
                } else {
                    showNotification("❌ Copy failed", "error");
                }
            } catch (err) {
                showNotification("❌ Copy failed", "error");
            }

            document.body.removeChild(textArea);
        }

        function showNotification(message, type) {
            const notification = document.createElement("div");
            notification.className = "kaspa-notification kaspa-notification-" + type;
            notification.textContent = message;
            notification.style.cssText = "position: fixed; top: 20px; right: 20px; background: " + (type === "success" ? "#28a745" : "#dc3545") + "; color: white; padding: 12px 20px; border-radius: 6px; z-index: 9999; font-weight: bold; box-shadow: 0 4px 12px rgba(0,0,0,0.3); transform: translateX(400px); transition: transform 0.3s ease;";

            document.body.appendChild(notification);

            setTimeout(function () {
                notification.style.transform = "translateX(0)";
            }, 100);

            setTimeout(function () {
                notification.style.transform = "translateX(400px)";
                setTimeout(function () {
                    if (document.body.contains(notification)) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }';

        // Add inline script
        wp_add_inline_script('kaspa-admin-dashboard-inline', $inline_script);

        // Register and enqueue style for inline CSS
        wp_register_style('kaspa-admin-dashboard-inline', '', array(), '1.0.0');
        wp_enqueue_style('kaspa-admin-dashboard-inline');

        // Prepare inline CSS
        $inline_style = '.kaspa-wallet-balance-section {
            margin: 20px 0;
        }

        .kaspa-wallet-balance-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .kaspa-wallet-address {
            margin-bottom: 20px;
        }

        .kaspa-address-display {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 5px;
        }

        .kaspa-address-display code {
            flex: 1;
            background: #f8f9fa;
            padding: 8px;
            border-radius: 4px;
            font-size: 12px;
            word-break: break-all;
        }

        .kaspa-balance-display {
            text-align: center;
        }

        .kaspa-balance-main {
            margin-bottom: 15px;
        }

        .kaspa-balance-amount {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }

        .kaspa-balance-kas {
            font-size: 24px;
            font-weight: bold;
            color: #007cba;
        }

        .kaspa-balance-usd {
            font-size: 18px;
            color: #666;
        }

        .kaspa-balance-rate {
            margin-top: 10px;
        }

        .kaspa-balance-actions {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .kaspa-last-updated {
            color: #666;
            font-style: italic;
        }

        .kaspa-loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #007cba;
            border-radius: 50%;
            animation: kaspa-spin 1s linear infinite;
            margin-right: 8px;
        }

        @keyframes kaspa-spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .kaspa-balance-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
        }

        .kaspa-balance-success {
            color: #28a745;
        }

        .kaspa-balance-error {
            color: #dc3545;
        }';

        // Add inline style
        wp_add_inline_style('kaspa-admin-dashboard-inline', $inline_style);
    }
}

// Initialize
new KASPPAGA_Admin_Dashboard();