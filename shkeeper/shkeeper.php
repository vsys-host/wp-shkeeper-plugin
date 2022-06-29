<?php
/*
 * Plugin Name: Shkeeper Payment Gateway
 * Plugin URI: https://github.com/vsys-host/wp-shkeeper-plugin
 * Description: Crypto payments gateway to accept the payment on your woocommerce store.
 * Author: Virtual Systems
 * Author URI: https://vsys.host/
 * License GPL v3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.en.html
 * Version: 1.0.0
 * WC requires at least: 5.7
 * WC tested up to: 6.4
 * Text Domain: shkeeper-payments-woo
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WC_SHKEEPER_VERSION', '1.0.0' );
define( 'WC_SHKEEPER_MAIN_FILE', __FILE__ );

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;


add_action( 'plugins_loaded', 'shkeeper_payment_init' );
function shkeeper_payment_init() {
    if(class_exists('WC_Payment_Gateway')) {
        require_once plugin_dir_path(WC_SHKEEPER_MAIN_FILE) . '/includes/class-wc-shkeeper-logger.php';
        require_once plugin_dir_path(WC_SHKEEPER_MAIN_FILE) . '/includes/class-wc-shkeeper-api.php';
        require_once plugin_dir_path(WC_SHKEEPER_MAIN_FILE) . '/includes/class-wc-payment-gateway-shkeeper.php';
        require_once plugin_dir_path(WC_SHKEEPER_MAIN_FILE) . '/includes/shkeeper-order-statuses.php';
        require_once plugin_dir_path(WC_SHKEEPER_MAIN_FILE) . '/includes/shkeeper-checkout-description.php';
        require_once plugin_dir_path(WC_SHKEEPER_MAIN_FILE) . '/includes/class-wc-shkeeper-webhook-state.php';
        require_once plugin_dir_path(WC_SHKEEPER_MAIN_FILE) . '/includes/class-wc-shkeeper-webhook-handler.php';
    }

}

add_filter( 'woocommerce_payment_gateways', 'add_shkeeper_gateway_class' );
function add_shkeeper_gateway_class( $gateways ) {
    $gateways[] = 'WC_Gateway_Shkeeper';
    return $gateways;
}
