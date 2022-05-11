<?php

/**
 * Add new Invoiced status for woocommerce
 */
add_action( 'init', 'register_invoiced_new_order_status' );

function register_invoiced_new_order_status() {
    register_post_status( 'wc-invoiced', array(
        'label'                     => _x( 'Invoiced', 'Order status', 'shkeeper-payments-woo' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Invoiced <span class="count">(%s)</span>', 'Invoiced<span class="count">(%s)</span>', 'shkeeper-payments-woo' )
    ) );
}

add_filter( 'wc_order_statuses', 'invoiced_wc_order_status' );
add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', 'shkeeper_valid_order_statuses_for_payment_complete', 10, 2);

function shkeeper_valid_order_statuses_for_payment_complete($array, $order) {
    $array[] = 'invoiced';
    return $array;
}

// Register in wc_order_statuses.
function invoiced_wc_order_status( $order_statuses ) {
    $order_statuses['wc-invoiced'] = _x( 'Invoiced', 'Order status', 'shkeeper-payments-woo' );
    return $order_statuses;
}

function shkeeper_add_bulk_invoice_order_status() {
    global $post_type;

    if ( $post_type == 'shop_order' ) {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function() {
                jQuery('<option>').val('mark_invoiced').text('<?php _e( 'Change status to invoiced', 'shkeeper-payments-woo' ); ?>').appendTo("select[name='action']");
                jQuery('<option>').val('mark_invoiced').text('<?php _e( 'Change status to invoiced', 'shkeeper-payments-woo' ); ?>').appendTo("select[name='action2']");
            });
        </script>
        <?php
    }
}

add_action( 'admin_footer', 'shkeeper_add_bulk_invoice_order_status' );