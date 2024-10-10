<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly 

add_filter('woocommerce_gateway_description', 'shkeeper_add_custom_checkout_description', 20, 2);
add_filter('woocommerce_thankyou_order_received_text', 'shkeeper_change_order_received_text', 10, 2 );
add_action('woocommerce_checkout_process', 'shkeeper_custom_checkout_description_validation');
add_action('woocommerce_checkout_update_order_meta', 'shkeeper_checkout_update_order_meta', 10, 1 );
add_action('woocommerce_admin_order_data_after_billing_address', 'shkeeper_order_data_after_billing_address', 10, 1 );
add_action('woocommerce_order_item_meta_end', 'shkeeper_order_item_meta_end', 10, 3 );

function shkeeper_add_custom_checkout_description($description, $payment_id) {
    if(!Shkeeper_WC_Gateway::is_me($payment_id)) {
        return $description;
    }

    $available_cryptos = Shkeeper_API::getCryptosList();
    if(!is_array($available_cryptos)) {
        return esc_html__( 'Can not get cryptocurrency list. Please, choose another payment method.', 'shkeeper-payment-gateway' );
    }

    $cryptos_options = [
        'none' => esc_html__( 'Select Cryptocurrency', 'shkeeper-payment-gateway' ),
    ];
    foreach ($available_cryptos as $available_crypto) {
        $cryptos_options[$available_crypto] = wc_strtoupper($available_crypto);
    }

    ob_start();
    echo '<div id="shkeeper_crypto_options">';
    woocommerce_form_field(
        'paying_crypto',
        [
            'type'  => 'select',
            'label' => __('Payment cryptocurrency', 'shkeeper-payment-gateway'),
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
    if(Shkeeper_WC_Gateway::is_me($order->payment_method)) {
        $str .= ' ' . esc_html__( 'Find crypto payment details bellow.', 'shkeeper-payment-gateway' );
    }

    return $str;
}

function shkeeper_custom_checkout_description_validation() {
    $payment_method = sanitize_text_field( wp_unslash( $_POST['payment_method'] ?? '' ) );
    $paying_crypto = sanitize_text_field( wp_unslash( $_POST['paying_crypto'] ?? '' ) );

    if(Shkeeper_WC_Gateway::is_me($payment_method) &&
        (!$paying_crypto || $paying_crypto == 'none') ) {
        wc_add_notice( esc_html__('Please choose cryptocurrency that you would pay', 'shkeeper-payment-gateway'), 'error');
    }
}

function shkeeper_checkout_update_order_meta($order_id) {
   $payment_method = sanitize_text_field( wp_unslash( $_POST['payment_method'] ?? '' ) );
   $paying_crypto = sanitize_text_field( wp_unslash( $_POST['paying_crypto'] ?? '' ) );

    if(!Shkeeper_WC_Gateway::is_me($payment_method)) {
        return;
    }

    if($paying_crypto && $paying_crypto !== 'none') {
        update_post_meta($order_id, 'shkeeper_crypto_curr', $paying_crypto);
    }
}

function shkeeper_order_data_after_billing_address($order) {
    if(Shkeeper_WC_Gateway::is_me($order->payment_method)) {
        echo '<p><strong>' . esc_html__('Payment cryptocurrency:', 'shkeeper-payment-gateway') . ' ' . '</strong>' . esc_html(wc_strtoupper(get_post_meta($order->get_id(), 'shkeeper_crypto_curr', true))) . '</p>';
        echo '<p><strong>' . esc_html__('Crypto address:', 'shkeeper-payment-gateway') . ' ' . '</strong>' . esc_html(wc_strtoupper(get_post_meta($order->get_id(), 'shkeeper_crypto_address', true))) . '</p>';
        echo '<p><strong>' . esc_html__('Crypto amount:', 'shkeeper-payment-gateway') . ' ' . '</strong>' . esc_html(rtrim(rtrim(sprintf('%.8F', get_post_meta($order->get_id(), 'shkeeper_crypto_amount', true)), '0'), ".")) . '</p>';
    }
}

function shkeeper_order_item_meta_end($item_id, $item, $order) {
    if(Shkeeper_WC_Gateway::is_me($order->payment_method)) {
        echo '<p><strong>' . esc_html__('Payment cryptocurrency:', 'shkeeper-payment-gateway') . ' ' . '</strong>' . esc_html(wc_strtoupper(get_post_meta($order->get_id(), 'shkeeper_crypto_curr', true))) . '</p>';
    }
}
