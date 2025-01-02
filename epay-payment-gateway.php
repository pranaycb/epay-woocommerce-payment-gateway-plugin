<?php

/**
 * Plugin Name: Epay Payment Gateway
 * Description: Epay payment gateway for WooCommerce.
 * Version: 1.0
 * Author: Pranay Chakraborty
 * Author URI: https://github.com/pranaycb
 * Copyright: 2025 by Pranay Chakraborty.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

add_action('plugins_loaded', 'epay_payment_gateway_init', 0);
add_filter('plugin_action_links', 'epay_add_action_plugin', 10, 5);
define('Gateway_IMG', WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/logo/logo.png');

/**
 * Initialize the gateway class
 */
function epay_payment_gateway_init()
{
    if (!class_exists('WC_Payment_Gateway')) return;

    class Epay_Payment_Gateway extends WC_Payment_Gateway
    {
        public $token, $api_url;

        public function __construct()
        {
            $this->id = 'epay_payment_gateway';
            $this->icon = Gateway_IMG;
            $this->has_fields = false;
            $this->method_title = 'Epay Payment Gateway';
            $this->method_description = 'Pay using bKash, Nagad, Rocket, Upay and many more...';

            /**
             * Load the settings
             */
            $this->init_form_fields();
            $this->init_settings();

            /**
             * Define settings
             */
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->token = $this->get_option('api_token');
            $this->api_url = $this->get_option('api_url');

            /**
             * Add hooks for payment processing and webhook handling
             */
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_' . $this->id, array($this, 'webhook_handler'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        }

        /**
         * Initialize the gateway's form fields
         */
        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable Epay Payment Gateway',
                    'default' => 'yes',
                ],
                'title' => [
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default' => 'Epay',
                    'desc_tip' => true,
                ],
                'description' => [
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default' => 'Pay using bKash, Nagad, Rocket, Upay and many more...',
                    'desc_tip' => true,
                ],
                'api_token' => [
                    'title' => 'API Token',
                    'type' => 'text',
                    'description' => 'Your created api token. Create an api token and add it here',
                    'desc_tip' => true,
                ],
                'api_url' => [
                    'title' => 'API URL',
                    'type' => 'text',
                    'description' => 'Base url of your gateway domain.',
                ],
            ];
        }

        /**
         * Process payment
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            // Call the API to create a payment URL
            $response = $this->create_payment($order);

            if (is_wp_error($response)) {
                wc_add_notice('Payment failed: ' . $response->get_error_message(), 'error');
                return;
            }

            return [
                'result'   => 'success',
                'redirect' => $response['paymentURL'],
            ];
        }

        /**
         * Create payment url via api
         */
        private function create_payment($order)
        {
            $token = $this->token;

            $url = $this->api_url . '/api/v1/payment/request';

            // Prepare the request data
            $data = [
                'trxnid'        => wp_generate_password(13, false),
                'amount'        => $order->get_total(),
                'cus_name'      => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'cus_phone'     => $order->get_billing_phone(),
                'cus_email'     => $order->get_billing_email(),
                'meta_data'     => json_encode(['orderId' => $order->get_id()]),
                'callback_url'  => $this->get_return_url($order),
                'webhook_url'   => home_url('/?wc-api=' . $this->id),
            ];

            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => $data,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                ],
            ]);

            $response = curl_exec($curl);

            if (curl_errno($curl)) {
                return new WP_Error('payment_error', 'cURL error: ' . curl_error($curl));
            }

            curl_close($curl);

            // Decode json response
            $response_data = json_decode($response, true);

            if (isset($response_data['status']) && $response_data['status'] === true) {
                return $response_data;
            }

            // Check for WP_Error
            if (is_wp_error($response)) {
                return $response;
            }

            return new WP_Error('payment_error', 'Payment creation failed.');
        }


        /**
         * Webhook handler to process payment result
         */
        public function webhook_handler()
        {
            $data = json_decode(file_get_contents('php://input'), true);

            if (isset($data['status']) && $data['status'] === 'success') {

                // Update the order status in WooCommerce to 'completed'
                $order = wc_get_order($data['meta']['orderId']);

                if ($order) {

                    $order->update_status('completed', 'Payment successfully completed via Epay Payment Gateway.');

                    exit;
                }
            }

            exit;
        }

        // Display the receipt page (optional)
        public function receipt_page($order)
        {
            echo '<p>' . __('Thank you for your order. You will be redirected to the payment page shortly.', 'epay-payment-gateway') . '</p>';
        }
    }

    /**
     * Register the payment gateway
     */
    function add_epay_payment_gateway($methods)
    {
        $methods[] = 'Epay_Payment_Gateway';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_epay_payment_gateway');
}

/**
 * 'Settings' link on plugin page
 **/
function epay_add_action_plugin($actions, $plugin_file)
{
    static $plugin;

    if (!isset($plugin)) {

        $plugin = plugin_basename(__FILE__);
    }

    if ($plugin == $plugin_file) {

        $settings = array(
            'settings' => '<a href="admin.php?page=wc-settings&tab=checkout&section=epay_payment_gateway">' . __('Settings') . '</a>'
        );

        $actions = array_merge($settings, $actions);
    }

    return $actions;
}
