<?php
/**
 * Shkeeper Gateway.
 *
 * Provides a Shkeeper Payment Gateway.
 *
 * @class       Shkeeper_WC_Gateway
 * @extends     WC_Payment_Gateway
 * @version     1.1.0
 */
class Shkeeper_WC_Gateway extends WC_Payment_Gateway
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
        wp_register_style( 'shkeeper_styles', plugins_url( 'assets/css/shkeeper-styles.css', SHKEEPER_WC_MAIN_FILE ), [], SHKEEPER_WC_VERSION );
	wp_enqueue_style( 'shkeeper_styles' );
    }

    /**
     * Setup general properties for the gateway.
     */
    protected function setup_properties()
    {
        $this->id = 'shkeeper';
        $this->icon = apply_filters('woocommerce_shkeeper_icon', plugins_url( 'assets/img/shkeeper-logo.svg', SHKEEPER_WC_MAIN_FILE ));
        $this->method_title = __('Shkeeper', 'shkeeper-payment-gateway');
        $this->method_description = __('Have your customers pay with crypto coins.', 'shkeeper-payment-gateway');
        $this->has_fields = false;
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'shkeeper-payment-gateway'),
                'label' => __('Enable shkeeper', 'shkeeper-payment-gateway'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ),
            'api_key' => array(
                'title' => __('Api key', 'shkeeper-payment-gateway'),
                'type' => 'password',
                'description' => __('Api key for shkeeper gateway', 'shkeeper-payment-gateway'),
                'desc_tip' => true,
            ),
            'api_url' => array(
                'title' => __('Api url', 'shkeeper-payment-gateway'),
                'type' => 'text',
                'description' => __('Api url for shkeeper gateway', 'shkeeper-payment-gateway'),
                'desc_tip' => true,
            ),
            'title' => array(
                'title' => __('Title', 'shkeeper-payment-gateway'),
                'type' => 'text',
                'description' => __('Payment method description that the customer will see on your checkout.', 'shkeeper-payment-gateway'),
                'default' => __('Shkeeper', 'shkeeper-payment-gateway'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'shkeeper-payment-gateway'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your website.', 'shkeeper-payment-gateway'),
                'default' => __('Pay with crypto.', 'shkeeper-payment-gateway'),
                'desc_tip' => true,
            ),
            'instructions' => array(
                'title' => __('Instructions', 'shkeeper-payment-gateway'),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page.', 'shkeeper-payment-gateway'),
                'default' => __('Pay with crypto.', 'shkeeper-payment-gateway'),
                'desc_tip' => true,
            ),
            'enable_for_methods' => array(
                'title' => __('Enable for shipping methods', 'shkeeper-payment-gateway'),
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'css' => 'width: 400px;',
                'default' => '',
                'description' => __('If Shkeeper is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'shkeeper-payment-gateway'),
                'options' => $this->load_shipping_method_options(),
                'desc_tip' => true,
                'custom_attributes' => array(
                    'data-placeholder' => __('Select shipping methods', 'shkeeper-payment-gateway'),
                ),
            ),
            'enable_for_virtual' => array(
                'title' => __('Accept for virtual orders', 'shkeeper-payment-gateway'),
                'label' => __('Accept Shkeeper if the order is virtual', 'shkeeper-payment-gateway'),
                'type' => 'checkbox',
                'default' => 'yes',
            ),
            'logging' => array(
                'title' => __('Logging', 'shkeeper-payment-gateway'),
                'label' => __('Enable logging', 'shkeeper-payment-gateway'),
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
	return true;
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
            $options[$method->get_method_title()][$method->id] = sprintf(__('Any &quot;%1$s&quot; method', 'shkeeper-payment-gateway'), $method->get_method_title());

            foreach ($zones as $zone) {

                $shipping_method_instances = $zone->get_shipping_methods();

                foreach ($shipping_method_instances as $shipping_method_instance_id => $shipping_method_instance) {

                    if ($shipping_method_instance->id !== $method->id) {
                        continue;
                    }

                    $option_id = $shipping_method_instance->get_rate_id();

                    // Translators: %1$s shipping method title, %2$s shipping method id.
                    $option_instance_title = sprintf(__('%1$s (#%2$s)', 'shkeeper-payment-gateway'), $shipping_method_instance->get_title(), $shipping_method_instance_id);

                    // Translators: %1$s zone name, %2$s shipping method instance name.
                    $option_title = sprintf(__('%1$s &ndash; %2$s', 'shkeeper-payment-gateway'), $zone->get_id() ? $zone->get_zone_name() : __('Other locations', 'shkeeper-payment-gateway'), $option_instance_title);

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
        $payment_request = Shkeeper_API::sendPaymentRequest($order);
        Shkeeper_Logger::log( 'Processing response: ' . print_r( $payment_request, true ) );

        if (is_wp_error($payment_request)) {
            wc_add_notice( __('Shkeeper error: ', 'shkeeper-payment-gateway') . $payment_request->get_error_message(), 'error' );
            return;
        }
        if($payment_request->status != 'success') {
            wc_add_notice( __('Shkeeper error: ', 'shkeeper-payment-gateway') . $payment_request->message, 'error' );
            return;
        }

        $order_id = $order->get_id();

        $order->update_meta_data('shkeeper_crypto_address', $payment_request->wallet);
        $order->update_meta_data('shkeeper_crypto_amount', $payment_request->amount);

        //Not necessary as update_status save whole object
        //$order->save_meta_data();
        wc_reduce_stock_levels( $order_id );

        $order->update_status( 'wc-invoiced', sprintf( __( 'Shkeeper charge awaiting payment to address %s.', 'shkeeper-payment-gateway' ), $payment_request->address ) );

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
        wp_enqueue_script( 'qrcodejs', plugins_url( 'assets/js/qrcode.min.js', SHKEEPER_WC_MAIN_FILE ), [], '1.0.0', true);
        $qrCodeJs = "new QRCode(document.getElementById('shkeeper-payment-qr'), {
                text: '$cryptoAddr?amount=$cryptoAmount',
                width: 128,
                height: 128,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H
         });";

        wp_add_inline_script('qrcodejs', $qrCodeJs);

	ob_start();
        echo '<section class="shkeeper-payment-details">';
        echo '<h2 class="shkeeper-payment-details__title">'. esc_html__('Crypto payment details', 'shkeeper-payment-gateway') .'</h2>';

	if ($this->instructions) {
            echo wp_kses_post(wpautop(wptexturize($this->instructions)));
        }
        echo '<section class="wp-block-columns alignwide woocommerce-order-confirmation-address-wrapper is-layout-flex wp-container-core-columns-is-layout-1 wp-block-columns-is-layout-flex shkeeper-crypto">';

        echo '<div class="is-layout-flow wp-block-column-is-layout-flow shkeeper-crypto-payment">';
        echo '<p> ' . esc_html__('Please, pay', 'shkeeper-payment-gateway') . ' ';
        echo '<b>' . esc_html(rtrim(rtrim(sprintf('%.8F', $cryptoAmount), '0'), ".")) . '</b>';
        echo ' ' . wc_strtoupper(esc_html($cryptoCurr)) . '</p>';
        echo '<p>' . esc_html__('To:', 'shkeeper-payment-gateway') . ' <span class="shkeeper-payment-address">' . esc_html($cryptoAddr) . '</span></p>';
        echo '</div>';
	echo '<div id="shkeeper-payment-qr" class="wp-block-column-is-layout-flow shkeeper-crypto-qr"> </div>';
        echo '</section>';

	echo '</section>';

        $paymentSection = ob_get_clean();
	echo $paymentSection;
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

    private function delete_shkeeper_custom_meta($order) {
        $custom_meta_names = ['shkeeper_crypto_address', 'shkeeper_crypto_amount', 'shkeeper_crypto_curr'];

        foreach ($custom_meta_names as $custom_meta_name) {
            $order->delete_meta_data($custom_meta_name);
        }

        $order->save_meta_data();
    }
}
