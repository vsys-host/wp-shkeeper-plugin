<?php

/**
 * Add new Invoiced status for woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly 

add_action( 'init', 'shkeeper_register_new_order_statuses' );

function shkeeper_register_new_order_statuses() {
    register_post_status( 'wc-invoiced', array(
        'label'                     => esc_html_x( 'Invoiced', 'Order status', 'shkeeper-payment-gateway' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Invoiced <span class="count">(%s)</span>', 'Invoiced<span class="count">(%s)</span>', 'shkeeper-payment-gateway' )
    ) );

    register_post_status( 'wc-partial', array(
        'label'                     => esc_html_x( 'Partial', 'Order status', 'shkeeper-payment-gateway' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Partial <span class="count">(%s)</span>', 'Partial<span class="count">(%s)</span>', 'shkeeper-payment-gateway' )
    ) );

}

add_filter( 'wc_order_statuses', 'shkeeper_add_statuses_to_list' );
add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', 'shkeeper_valid_order_statuses_for_payment_complete', 10, 2);

function shkeeper_valid_order_statuses_for_payment_complete($array, $order) {
    array_push($array, 'invoiced', 'partial');
    return $array;
}

// Register in wc_order_statuses.
function shkeeper_add_statuses_to_list( $order_statuses ) {
    $order_statuses['wc-invoiced'] = esc_html_x( 'Invoiced', 'Order status', 'shkeeper-payment-gateway' );
    $order_statuses['wc-partial'] = esc_html_x( 'Partial', 'Order status', 'shkeeper-payment-gateway' );
    return $order_statuses;
}

function shkeeper_add_bulk_invoice_order_statuses() {
    global $post_type;

    if ( $post_type == 'shop_order' ) {
	    $status = wp_enqueue_script('shkeeper_admin_script', plugins_url('assets/js/custom_admin_script.js', SHKEEPER_WC_MAIN_FILE));

	    $json = wp_json_encode( [
	      'invoicedStatus' => esc_html_x( 'Change status to invoiced', 'Order status', 'shkeeper-payment-gateway' ),
	      'partialStatus' => esc_html_x( 'Change status to partial', 'Order status', 'shkeeper-payment-gateway' ),
            ] );

	    wp_add_inline_script('shkeeper_admin_script', "const SHKEEPER = $json", 'before');
    }
}

add_action( 'admin_enqueue_scripts', 'shkeeper_add_bulk_invoice_order_statuses' );
