<?php

add_filter('woocommerce_gateway_description', 'shkeeper_add_custom_checkout_description', 20, 2);
add_filter('woocommerce_thankyou_order_received_text', 'shkeeper_change_order_received_text', 10, 2 );
add_action('woocommerce_checkout_process', 'shkeeper_custom_checkout_description_validation');
add_action( 'woocommerce_checkout_update_order_meta', 'shkeeper_checkout_update_order_meta', 10, 1 );
add_action( 'woocommerce_admin_order_data_after_billing_address', 'shkeeper_order_data_after_billing_address', 10, 1 );
add_action( 'woocommerce_order_item_meta_end', 'shkeeper_order_item_meta_end', 10, 3 );

function shkeeper_add_custom_checkout_description($description, $payment_id) {

    if(!WC_Gateway_Shkeeper::is_me($payment_id)) {
        return $description;
    }

    $available_cryptos = WC_Shkeeper_API::getCryptosList();
    if(!is_array($available_cryptos)) {
        return __( 'Can not get cryptocurrency list. Please, choose another payment method.', 'shkeeper-payments-woo' );
    }

    $cryptos_options = [
        'none' => __( 'Select Cryptocurrency', 'shkeeper-payments-woo' ),
    ];
    foreach ($available_cryptos as $available_crypto) {
        $cryptos_options[$available_crypto] = __( wc_strtoupper($available_crypto), 'shkeeper-payments-woo' );
    }

    ob_start();
    echo '<div id="shkeeper_crypto_options">';
    woocommerce_form_field(
        'paying_crypto',
        [
            'type'  => 'select',
            'label' => __('Payment cryptocurrency', 'shkeeper-payments-woo'),
            'class' => [ 'form-row', 'form-row-wide' ],
            'required' => true,
            'options' => $cryptos_options,
        ],
    );
    echo '</div>';

    $description .= PHP_EOL . ob_get_clean();

    return $description;
}

function shkeeper_change_order_received_text($str, $order) {
    if(WC_Gateway_Shkeeper::is_me($order->payment_method)) {
        $str .= ' ' . __( 'Find crypto payment details bellow.', 'shkeeper-payments-woo' );
    }

    return $str;
}

function shkeeper_custom_checkout_description_validation() {
    if(WC_Gateway_Shkeeper::is_me($_POST['payment_method']) &&
        (!isset($_POST['paying_crypto']) || $_POST['paying_crypto'] == 'none') ) {
        wc_add_notice( __('Please choose cryptocurrency that you would pay', 'shkeeper-payments-woo'), 'error');
    }
}

function shkeeper_checkout_update_order_meta($order_id) {
    if(!WC_Gateway_Shkeeper::is_me($_POST['payment_method'])) {
        return;
    }

    if(isset($_POST['paying_crypto']) && $_POST['paying_crypto'] !== 'none') {
        update_post_meta($order_id, 'shkeeper_crypto_curr', $_POST['paying_crypto']);
    }
}

function shkeeper_order_data_after_billing_address($order) {
    if(WC_Gateway_Shkeeper::is_me($order->payment_method)) {
        echo '<p><strong>' . __('Payment cryptocurrency:', 'shkeeper-payments-woo') . ' ' . '</strong>' . wc_strtoupper(get_post_meta($order->get_id(), 'shkeeper_crypto_curr', true)) . '</p>';
        echo '<p><strong>' . __('Crypto address:', 'shkeeper-payments-woo') . ' ' . '</strong>' . wc_strtoupper(get_post_meta($order->get_id(), 'shkeeper_crypto_address', true)) . '</p>';
        echo '<p><strong>' . __('Crypto amount:', 'shkeeper-payments-woo') . ' ' . '</strong>' . rtrim(rtrim(sprintf('%.8F', get_post_meta($order->get_id(), 'shkeeper_crypto_amount', true)), '0'), ".") . '</p>';
    }
}

function shkeeper_order_item_meta_end($item_id, $item, $order) {
    if(WC_Gateway_Shkeeper::is_me($order->payment_method)) {
        echo '<p><strong>' . __('Payment cryptocurrency:', 'shkeeper-payments-woo') . ' ' . '</strong>' . wc_strtoupper(get_post_meta($order->get_id(), 'shkeeper_crypto_curr', true)) . '</p>';
    }
}