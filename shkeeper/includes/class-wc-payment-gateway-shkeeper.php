<?php
/**
 * Shkeeper Gateway.
 *
 * Provides a Shkeeper Payment Gateway.
 *
 * @class       WC_Gateway_Shkeeper
 * @extends     WC_Payment_Gateway
 * @version     1.0.0
 */
class WC_Gateway_Shkeeper extends WC_Payment_Gateway
{

    /**
     * Constructor for the gateway.
     */
    public function __construct()
    {
        // Setup general properties.
        $this->setup_properties();

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();
        // Get settings.
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->instructions = $this->get_option('instructions');
        $this->enable_for_methods = $this->get_option('enable_for_methods', array());
        $this->enable_for_virtual = $this->get_option('enable_for_virtual', 'yes') === 'yes';

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

        // Customer Emails.
        add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);

        //Plugin Css
        wp_register_style( 'shkeeper_styles', plugins_url( 'assets/css/shkeeper-styles.css', WC_SHKEEPER_MAIN_FILE ), [], WC_SHKEEPER_VERSION );
        wp_enqueue_style( 'shkeeper_styles' );
    }

    /**
     * Setup general properties for the gateway.
     */
    protected function setup_properties()
    {
        $this->id = 'shkeeper';
        $this->icon = apply_filters('woocommerce_shkeeper_icon', plugins_url( 'assets/img/shkeeper-logo.svg', WC_SHKEEPER_MAIN_FILE ));
        $this->method_title = __('Shkeeper', 'shkeeper-payments-woo');
        $this->method_description = __('Have your customers pay with crypto coins.', 'shkeeper-payments-woo');
        $this->has_fields = false;
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'shkeeper-payments-woo'),
                'label' => __('Enable shkeeper', 'shkeeper-payments-woo'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ),
            'api_key' => array(
                'title' => __('Api key', 'shkeeper-payments-woo'),
                'type' => 'password',
                'description' => __('Api key for shkeeper gateway', 'shkeeper-payments-woo'),
                'desc_tip' => true,
            ),
            'api_url' => array(
                'title' => __('Api url', 'shkeeper-payments-woo'),
                'type' => 'text',
                'description' => __('Api url for shkeeper gateway', 'shkeeper-payments-woo'),
                'desc_tip' => true,
            ),
            'title' => array(
                'title' => __('Title', 'shkeeper-payments-woo'),
                'type' => 'text',
                'description' => __('Payment method description that the customer will see on your checkout.', 'shkeeper-payments-woo'),
                'default' => __('Shkeeper', 'shkeeper-payments-woo'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'shkeeper-payments-woo'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your website.', 'shkeeper-payments-woo'),
                'default' => __('Pay with crypto.', 'shkeeper-payments-woo'),
                'desc_tip' => true,
            ),
            'instructions' => array(
                'title' => __('Instructions', 'shkeeper-payments-woo'),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page.', 'shkeeper-payments-woo'),
                'default' => __('Pay with crypto.', 'shkeeper-payments-woo'),
                'desc_tip' => true,
            ),
            'enable_for_methods' => array(
                'title' => __('Enable for shipping methods', 'shkeeper-payments-woo'),
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'css' => 'width: 400px;',
                'default' => '',
                'description' => __('If Shkeeper is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'shkeeper-payments-woo'),
                'options' => $this->load_shipping_method_options(),
                'desc_tip' => true,
                'custom_attributes' => array(
                    'data-placeholder' => __('Select shipping methods', 'shkeeper-payments-woo'),
                ),
            ),
            'enable_for_virtual' => array(
                'title' => __('Accept for virtual orders', 'shkeeper-payments-woo'),
                'label' => __('Accept Shkeeper if the order is virtual', 'shkeeper-payments-woo'),
                'type' => 'checkbox',
                'default' => 'yes',
            ),
            'logging' => array(
                'title' => __('Logging', 'shkeeper-payments-woo'),
                'label' => __('Enable logging', 'shkeeper-payments-woo'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ),
        );
    }

    /**
     * Check If The Gateway Is Available For Use.
     *
     * @return bool
     */
    public function is_available()
    {
        $order = null;
        $needs_shipping = false;

        // Test if shipping is needed first.
        if (WC()->cart && WC()->cart->needs_shipping()) {
            $needs_shipping = true;
        } elseif (is_page(wc_get_page_id('checkout')) && 0 < get_query_var('order-pay')) {
            $order_id = absint(get_query_var('order-pay'));
            $order = wc_get_order($order_id);

            // Test if order needs shipping.
            if ($order && 0 < count($order->get_items())) {
                foreach ($order->get_items() as $item) {
                    $_product = $item->get_product();
                    if ($_product && $_product->needs_shipping()) {
                        $needs_shipping = true;
                        break;
                    }
                }
            }
        }

        $needs_shipping = apply_filters('woocommerce_cart_needs_shipping', $needs_shipping);

        // Virtual order, with virtual disabled.
        if (!$this->enable_for_virtual && !$needs_shipping) {
            return false;
        }

        // Only apply if all packages are being shipped via chosen method, or order is virtual.
        if (!empty($this->enable_for_methods) && $needs_shipping) {
            $order_shipping_items = is_object($order) ? $order->get_shipping_methods() : false;
            $chosen_shipping_methods_session = WC()->session->get('chosen_shipping_methods');

            if ($order_shipping_items) {
                $canonical_rate_ids = $this->get_canonical_order_shipping_item_rate_ids($order_shipping_items);
            } else {
                $canonical_rate_ids = $this->get_canonical_package_rate_ids($chosen_shipping_methods_session);
            }

            if (!count($this->get_matching_rates($canonical_rate_ids))) {
                return false;
            }
        }

        return parent::is_available();
    }

    /**
     * Checks to see whether or not the admin settings are being accessed by the current request.
     *
     * @return bool
     */
    private function is_accessing_settings()
    {
        if (is_admin()) {
            // phpcs:disable WordPress.Security.NonceVerification
            if (!isset($_REQUEST['page']) || 'wc-settings' !== $_REQUEST['page']) {
                return false;
            }
            if (!isset($_REQUEST['tab']) || 'checkout' !== $_REQUEST['tab']) {
                return false;
            }
            if (!isset($_REQUEST['section']) || 'shkeeper' !== $_REQUEST['section']) {
                return false;
            }
            // phpcs:enable WordPress.Security.NonceVerification

            return true;
        }

        return false;
    }

    /**
     * Loads all of the shipping method options for the enable_for_methods field.
     *
     * @return array
     */
    private function load_shipping_method_options()
    {
        // Since this is expensive, we only want to do it if we're actually on the settings page.
        if (!$this->is_accessing_settings()) {
            return array();
        }

        $data_store = WC_Data_Store::load('shipping-zone');
        $raw_zones = $data_store->get_zones();

        foreach ($raw_zones as $raw_zone) {
            $zones[] = new WC_Shipping_Zone($raw_zone);
        }

        $zones[] = new WC_Shipping_Zone(0);

        $options = array();
        foreach (WC()->shipping()->load_shipping_methods() as $method) {

            $options[$method->get_method_title()] = array();

            // Translators: %1$s shipping method name.
            $options[$method->get_method_title()][$method->id] = sprintf(__('Any &quot;%1$s&quot; method', 'shkeeper-payments-woo'), $method->get_method_title());

            foreach ($zones as $zone) {

                $shipping_method_instances = $zone->get_shipping_methods();

                foreach ($shipping_method_instances as $shipping_method_instance_id => $shipping_method_instance) {

                    if ($shipping_method_instance->id !== $method->id) {
                        continue;
                    }

                    $option_id = $shipping_method_instance->get_rate_id();

                    // Translators: %1$s shipping method title, %2$s shipping method id.
                    $option_instance_title = sprintf(__('%1$s (#%2$s)', 'shkeeper-payments-woo'), $shipping_method_instance->get_title(), $shipping_method_instance_id);

                    // Translators: %1$s zone name, %2$s shipping method instance name.
                    $option_title = sprintf(__('%1$s &ndash; %2$s', 'shkeeper-payments-woo'), $zone->get_id() ? $zone->get_zone_name() : __('Other locations', 'shkeeper-payments-woo'), $option_instance_title);

                    $options[$method->get_method_title()][$option_id] = $option_title;
                }
            }
        }

        return $options;
    }

    /**
     * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
     *
     * @param array $order_shipping_items Array of WC_Order_Item_Shipping objects.
     * @return array $canonical_rate_ids    Rate IDs in a canonical format.
     * @since  3.4.0
     *
     */
    private function get_canonical_order_shipping_item_rate_ids($order_shipping_items)
    {

        $canonical_rate_ids = array();

        foreach ($order_shipping_items as $order_shipping_item) {
            $canonical_rate_ids[] = $order_shipping_item->get_method_id() . ':' . $order_shipping_item->get_instance_id();
        }

        return $canonical_rate_ids;
    }

    /**
     * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
     *
     * @param array $chosen_package_rate_ids Rate IDs as generated by shipping methods. Can be anything if a shipping method doesn't honor WC conventions.
     * @return array $canonical_rate_ids  Rate IDs in a canonical format.
     * @since  3.4.0
     *
     */
    private function get_canonical_package_rate_ids($chosen_package_rate_ids)
    {

        $shipping_packages = WC()->shipping()->get_packages();
        $canonical_rate_ids = array();

        if (!empty($chosen_package_rate_ids) && is_array($chosen_package_rate_ids)) {
            foreach ($chosen_package_rate_ids as $package_key => $chosen_package_rate_id) {
                if (!empty($shipping_packages[$package_key]['rates'][$chosen_package_rate_id])) {
                    $chosen_rate = $shipping_packages[$package_key]['rates'][$chosen_package_rate_id];
                    $canonical_rate_ids[] = $chosen_rate->get_method_id() . ':' . $chosen_rate->get_instance_id();
                }
            }
        }

        return $canonical_rate_ids;
    }

    /**
     * Indicates whether a rate exists in an array of canonically-formatted rate IDs that activates this gateway.
     *
     * @param array $rate_ids Rate ids to check.
     * @return boolean
     * @since  3.4.0
     *
     */
    private function get_matching_rates($rate_ids)
    {
        // First, match entries in 'method_id:instance_id' format. Then, match entries in 'method_id' format by stripping off the instance ID from the candidates.
        return array_unique(array_merge(array_intersect($this->enable_for_methods, $rate_ids), array_intersect($this->enable_for_methods, array_unique(array_map('wc_get_string_before_colon', $rate_ids)))));
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        if ($order->get_total() > 0) {
            $this->shkeeper_payment_processing($order);

        } else {
            $order->payment_complete();
        }

        if(wc_notice_count()) {
            $this->delete_shkeeper_custom_meta($order);
            return [
                'result' => 'failure',
                'redirect' => ''
            ];
        }

        // Remove cart.
        WC()->cart->empty_cart();

        // Return thankyou redirect.
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }

    private function shkeeper_payment_processing($order)
    {
        $payment_request = WC_Shkeeper_API::sendPaymentRequest($order);
        WC_Shkeeper_Logger::log( 'Processing response: ' . print_r( $payment_request, true ) );

        if (is_wp_error($payment_request)) {
            wc_add_notice( __('Shkeeper error: ', 'shkeeper-payments-woo') . $payment_request->get_error_message(), 'error' );
            return;
        }
        if($payment_request->status != 'success') {
            wc_add_notice( __('Shkeeper error: ', 'shkeeper-payments-woo') . $payment_request->message, 'error' );
            return;
        }

        $order_id = $order->get_id();

        update_post_meta($order_id, 'shkeeper_crypto_address', $payment_request->wallet);
        update_post_meta($order_id, 'shkeeper_crypto_amount', $payment_request->amount);

        wc_reduce_stock_levels( $order_id );

        $order->update_status( 'wc-invoiced', sprintf( __( 'Shkeeper charge awaiting payment to address %s.', 'woocommerce-gateway-stripe' ), $payment_request->address ) );

    }

    /**
     * Output for the order received page.
     */
    public function thankyou_page($order_id)
    {

        $order = wc_get_order($order_id);
        $cryptoAddr = $order->get_meta('shkeeper_crypto_address');
        $cryptoAmount = $order->get_meta('shkeeper_crypto_amount');
        $cryptoCurr = $order->get_meta('shkeeper_crypto_curr');

        ob_start();
        echo '<section class="shkeeper-payment-details">';
        echo '<h2 class="shkeeper-payment-details__title">'. __('Crypto payment details', 'shkeeper-payments-woo') .'</h2>';
        echo '<section class="woocommerce-columns woocommerce-columns--2 col2-set shkeeper-crypto">';

		echo '<div class="woocommerce-column woocommerce-column--1 col-1 shkeeper-crypto-qr" >';
        if($cryptoQr = $this->get_qr_code_base64($this->build_str_for_qr($cryptoAddr, $cryptoAmount, $cryptoCurr))) {
            echo '<p><img src="data:image/png;base64,'.$cryptoQr.'"/></p>';
        }
        echo '</div>';

        echo '<div class="woocommerce-column woocommerce-column--2 col-2 shkeeper-crypto-payment" >';
        echo '<p> ' . __('Please, pay', 'shkeeper-payments-woo') . ' ';
        echo '<b>' . rtrim(rtrim(sprintf('%.8F', $cryptoAmount), '0'), ".") . '</b>';
        echo ' ' . wc_strtoupper($cryptoCurr) . '</p>';
        echo '<p>' . __('To:', 'shkeeper-payments-woo') . ' ' . $cryptoAddr . '</p>';
        echo '</div>';
        echo '</section>';
        echo '</section>';
        $paymentSection = ob_get_clean();
        echo $paymentSection;

        if ($this->instructions) {
            echo wp_kses_post(wpautop(wptexturize($this->instructions)));
        }
    }

    /**
     * Add content to the WC emails.
     *
     * @param WC_Order $order Order object.
     * @param bool $sent_to_admin Sent to admin.
     * @param bool $plain_text Email format: plain text or HTML.
     */
    public function email_instructions($order, $sent_to_admin, $plain_text = false)
    {
        if ($this->instructions && !$sent_to_admin && $this->id === $order->get_payment_method()) {
            echo wp_kses_post(wpautop(wptexturize($this->instructions)) . PHP_EOL);
        }
    }

    public static function is_me($gateway_id) {
        return $gateway_id === 'shkeeper';
    }

    private function get_qr_code_base64($str) {
        if(!extension_loaded('gd')) {
            return false;
        }
        require_once plugin_dir_path(WC_SHKEEPER_MAIN_FILE) . 'vendor/phpqrcode/qrlib.php';
        return QRcode::png($str,'*', QR_ECLEVEL_H, 5);

    }

    private function build_str_for_qr($addr, $amount, $curr) {
        $str = '';

        switch (wc_strtolower($curr)) {
            case 'btc':
                $str .= 'bitcoin:';
                break;
            case 'ltc':
                $str .= 'litecoin:';
                break;
            case 'doge':
                $str .= 'dogecoin:';
                break;
            default:
                $str .= '';
                break;
        }

        $str .= $addr . '?amount=' . $amount;

        return $str;
    }

    private function delete_shkeeper_custom_meta($order) {
        $custom_meta_names = ['shkeeper_crypto_address', 'shkeeper_crypto_amount', 'shkeeper_crypto_curr'];

        foreach ($custom_meta_names as $custom_meta_name) {
            delete_post_meta($order->get_id(), $custom_meta_name);
        }
    }
}