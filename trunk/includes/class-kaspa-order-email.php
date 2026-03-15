<?php
/**
 * Kaspa Order Confirmation Email
 *
 * Sends the customer their Kaspa payment address and KAS amount
 * when an order goes on-hold via the Kaspa gateway.
 * Free feature — no API key required.
 */

if (!defined('ABSPATH')) {
    exit;
}

class KASPPAGA_Order_Email extends WC_Email
{
    public function __construct()
    {
        $this->id          = 'kaspa_order_confirmation';
        $this->title       = 'Kaspa Payment Details';
        $this->description = 'Sent to the customer when their order goes on-hold with Kaspa payment, with the address and KAS amount to send.';
        $this->placeholders = array(
            '{site_title}'   => $this->get_blogname(),
            '{order_number}' => '',
        );

        add_action('woocommerce_order_status_on-hold_notification', array($this, 'trigger'), 10, 2);

        parent::__construct();
    }

    public function trigger($order_id, $order = false)
    {
        $this->setup_locale();

        if ($order_id && !is_a($order, 'WC_Order')) {
            $order = wc_get_order($order_id);
        }

        if (!is_a($order, 'WC_Order') || $order->get_payment_method() !== 'kaspa') {
            $this->restore_locale();
            return;
        }

        $this->object                         = $order;
        $this->recipient                      = $order->get_billing_email();
        $this->placeholders['{order_number}'] = $order->get_order_number();

        if ($this->is_enabled() && $this->get_recipient()) {
            $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $this->get_content(),
                $this->get_headers(),
                $this->get_attachments()
            );
        }

        $this->restore_locale();
    }

    public function get_subject()
    {
        return apply_filters(
            'woocommerce_email_subject_' . $this->id,
            $this->format_string($this->get_option('subject', $this->get_default_subject())),
            $this->object,
            $this
        );
    }

    public function get_heading()
    {
        return apply_filters(
            'woocommerce_email_heading_' . $this->id,
            $this->format_string($this->get_default_heading()),
            $this->object,
            $this
        );
    }

    public function get_default_subject()
    {
        return 'Your Kaspa payment details for order #{order_number}';
    }

    public function get_default_heading()
    {
        return 'Kaspa Payment Details — Order #{order_number}';
    }

    public function get_content()
    {
        $this->sending = true;
        if ($this->get_email_type() === 'plain') {
            return wordwrap($this->get_content_plain(), 70);
        }
        return $this->get_content_html();
    }

    public function get_content_plain()
    {
        $order   = $this->object;
        $address = $this->get_order_address($order);
        $amount  = $order->get_meta('_kaspa_expected_amount');
        $total   = html_entity_decode(strip_tags($order->get_formatted_order_total()));
        $link    = $order->get_view_order_url();
        $name    = $order->get_billing_first_name();

        return sprintf(
            "Hi %s,\n\nThank you for your order!\n\nPlease send exactly %s KAS to complete your payment.\n\nAmount: %s KAS\nAddress: %s\n\nOrder #%s — Total: %s\n\nView your order: %s\n\nPayment is detected automatically once confirmed on the blockchain.\n\n— KaspaWoo",
            $name ?: 'there',
            $amount,
            $amount,
            $address,
            $order->get_order_number(),
            $total,
            $link
        );
    }

    public function get_content_html()
    {
        $order   = $this->object;
        $address = $this->get_order_address($order);
        $amount  = $order->get_meta('_kaspa_expected_amount');
        $total   = $order->get_formatted_order_total();
        $link    = $order->get_view_order_url();
        $name    = $order->get_billing_first_name();

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kaspa Payment Details</title>
</head>
<body style="margin:0;padding:0;background:#f0f2f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f2f5;padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;max-width:600px;width:100%;">

                    <!-- Header -->
                    <tr>
                        <td style="background:#0a0e1a;padding:28px 40px;text-align:center;">
                            <span style="font-size:26px;font-weight:800;color:#49eacb;letter-spacing:-0.5px;">KaspaWoo</span>
                            <p style="margin:6px 0 0;color:#6b7c9a;font-size:13px;">Kaspa Payments for WooCommerce</p>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:36px 40px;">
                            <p style="margin:0 0 20px;font-size:16px;color:#333;">Hi <?php echo esc_html($name ?: 'there'); ?>,</p>
                            <p style="margin:0 0 28px;font-size:15px;color:#555;line-height:1.6;">
                                Thank you for your order! Please send your Kaspa payment to the address below. Your order will be confirmed automatically once the payment is detected on the blockchain.
                            </p>

                            <!-- Payment Box -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f7fffe;border:2px solid #49eacb;border-radius:10px;margin-bottom:28px;">
                                <tr>
                                    <td style="padding:24px 28px;">
                                        <p style="margin:0 0 4px;font-size:11px;font-weight:700;color:#49eacb;letter-spacing:1.5px;text-transform:uppercase;">Amount to Send</p>
                                        <p style="margin:0 0 20px;font-size:30px;font-weight:800;color:#0a0e1a;line-height:1;">
                                            <?php echo esc_html($amount); ?> <span style="font-size:18px;color:#49eacb;">KAS</span>
                                        </p>
                                        <p style="margin:0 0 4px;font-size:11px;font-weight:700;color:#49eacb;letter-spacing:1.5px;text-transform:uppercase;">Send to This Address</p>
                                        <p style="margin:0;font-family:'Courier New',Courier,monospace;font-size:12px;color:#222;word-break:break-all;background:#fff;border:1px solid #d0f5ec;border-radius:6px;padding:12px 14px;line-height:1.5;">
                                            <?php echo esc_html($address); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Order Summary -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eaeaea;border-radius:8px;margin-bottom:28px;">
                                <tr style="background:#f9f9f9;">
                                    <td colspan="2" style="padding:10px 18px;font-size:12px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:0.8px;border-bottom:1px solid #eaeaea;">Order Summary</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 18px 6px;font-size:14px;color:#777;">Order Number</td>
                                    <td style="padding:12px 18px 6px;font-size:14px;color:#333;font-weight:600;text-align:right;">#<?php echo esc_html($order->get_order_number()); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 18px;font-size:14px;color:#777;">Order Total</td>
                                    <td style="padding:6px 18px;font-size:14px;color:#333;font-weight:600;text-align:right;"><?php echo wp_kses_post($total); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 18px 14px;font-size:14px;color:#777;">KAS Amount</td>
                                    <td style="padding:6px 18px 14px;font-size:15px;color:#49eacb;font-weight:700;text-align:right;"><?php echo esc_html($amount); ?> KAS</td>
                                </tr>
                            </table>

                            <p style="margin:0 0 8px;font-size:13px;color:#888;line-height:1.6;">
                                <strong style="color:#555;">Important:</strong> Send exactly <strong><?php echo esc_html($amount); ?> KAS</strong>. Sending a different amount may delay confirmation.
                            </p>

                            <a href="<?php echo esc_url($link); ?>" style="display:inline-block;background:#49eacb;color:#0a0e1a;text-decoration:none;font-weight:700;font-size:15px;padding:13px 30px;border-radius:8px;margin-top:16px;">
                                View Your Order →
                            </a>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background:#f9f9f9;padding:20px 40px;border-top:1px solid #eaeaea;text-align:center;">
                            <p style="margin:0;font-size:12px;color:#aaa;">
                                Powered by <a href="https://kaspawoo.com" style="color:#49eacb;text-decoration:none;">KaspaWoo</a> — Kaspa Payments for WooCommerce
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    private function get_order_address($order)
    {
        $address = $order->get_meta('_kaspa_payment_address');
        if (empty($address)) {
            $address = $order->get_meta('_kaspa_address');
        }
        if (empty($address) || strpos($address, 'pending') !== false) {
            return 'Your address is being generated — please visit your order page to see it.';
        }
        return $address;
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable Kaspa payment confirmation emails',
                'default' => 'yes',
            ),
            'subject' => array(
                'title'       => 'Subject',
                'type'        => 'text',
                'description' => sprintf('Available placeholders: %s', '<code>{site_title}, {order_number}</code>'),
                'placeholder' => $this->get_default_subject(),
                'default'     => '',
                'desc_tip'    => true,
            ),
        );
    }
}
