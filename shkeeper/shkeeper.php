<?php
/*
 * Plugin Name: Shkeeper Payment Gateway
 * Plugin URI: https://github.com/vsys-host/wp-shkeeper-plugin
 * Description: Crypto payments gateway to accept the payment on your woocommerce store.
 * Author: Virtual Systems
 * Author URI: https://vsys.host/
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.en.html
 * Stable tag: 1.1.1
 * Version: 1.1.1
 * WC requires at least: 5.7
 * WC tested up to: 9.9.5
 * Text Domain: shkeeper-payment-gateway
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SHKEEPER_WC_VERSION', '1.1.1' );
define( 'SHKEEPER_WC_MAIN_FILE', __FILE__ );

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

add_action( 'plugins_loaded', 'shkeeper_payment_init' );
function shkeeper_payment_init() {
    if(class_exists('WC_Payment_Gateway')) {
        require_once plugin_dir_path(SHKEEPER_WC_MAIN_FILE) . '/includes/class-shkeeper-logger.php';
        require_once plugin_dir_path(SHKEEPER_WC_MAIN_FILE) . '/includes/class-shkeeper-api.php';
        require_once plugin_dir_path(SHKEEPER_WC_MAIN_FILE) . '/includes/class-wc-payment-gateway-shkeeper.php';
        require_once plugin_dir_path(SHKEEPER_WC_MAIN_FILE) . '/includes/shkeeper-order-statuses.php';
        require_once plugin_dir_path(SHKEEPER_WC_MAIN_FILE) . '/includes/shkeeper-checkout-description.php';
        require_once plugin_dir_path(SHKEEPER_WC_MAIN_FILE) . '/includes/class-shkeeper-webhook-state.php';
        require_once plugin_dir_path(SHKEEPER_WC_MAIN_FILE) . '/includes/class-shkeeper-webhook-handler.php';
    }

}

add_filter( 'woocommerce_payment_gateways', 'shkeeper_add_gateway_class' );
function shkeeper_add_gateway_class( $gateways ) {
    $gateways[] = 'Shkeeper_WC_Gateway';
    return $gateways;
}
