<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class SHKeeper_Blocks_Support extends AbstractPaymentMethodType {
    protected $name = 'shkeeper';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_shkeeper_settings', [] );
    }

    public function is_active() {
        return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'shkeeper-blocks-integration',
            plugins_url( '/assets/js/shkeeper-blocks.js', dirname(__FILE__ ) ),
            [ 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ],
            '1.0.1',
            true
        );
        return [ 'shkeeper-blocks-integration' ];
    }

    public function get_payment_method_data() {
        return [
            'title'       => $this->get_setting( 'title' ),
            'description' => $this->get_setting( 'description' ),
            'supports'    => [ 'products' ],
            'apiUrl'      => rest_url( 'shkeeper/v1/cryptos' ),
            'iconUrl'     => plugins_url( '../assets/img/shkeeper-logo.svg', __FILE__ ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'i18n'        => [
                'loading'        => __( '🔄 Loading cryptocurrencies...', 'shkeeper-payment-gateway' ),
                'unavailable'    => __( 'Payment service is temporarily unavailable.', 'shkeeper-payment-gateway' ),
                'select_crypto'  => __( 'Select Cryptocurrency', 'shkeeper-payment-gateway' ),
                'validation_msg' => __( 'Please choose cryptocurrency that you would pay', 'shkeeper-payment-gateway' ),
            ]
        ];
    }
}

add_action( 'woocommerce_store_api_checkout_update_order_from_request', function( \WC_Order $order, \WP_REST_Request $request ) {
    $payment_data = $request->get_param( 'payment_data' );

    if ( is_array( $payment_data ) ) {
        foreach ( $payment_data as $data_item ) {
            if ( isset( $data_item['key'] ) && $data_item['key'] === 'shkeeper_crypto_curr' ) {
                $crypto_currency = sanitize_text_field( $data_item['value'] );
                $order->update_meta_data( 'shkeeper_crypto_curr', $crypto_currency );
                break;
            }
        }
    }
}, 10, 2 );

add_action( 'rest_api_init', function () {
    register_rest_route( 'shkeeper/v1', '/cryptos', [
        'methods'             => 'GET',
        'permission_callback' => function( $request ) {
            $nonce = $request->get_header( 'x_wp_nonce' );
            if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                return new WP_Error( 'rest_forbidden', __( 'Access denied. Invalid token.', 'shkeeper-payment-gateway' ), [ 'status' => 403 ] );
            }
            return true;
        },
        'callback'            => function() {
            // Shkeeper_API::getCryptosList() caches the list in a transient itself.
            $cryptos = class_exists( 'Shkeeper_API' ) ? Shkeeper_API::getCryptosList() : [];

            if ( is_wp_error( $cryptos ) || empty( $cryptos ) ) {
                return rest_ensure_response( [] );
            }

            // Normalize to {name, display_name} so the blocks UI also works with
            // Shkeeper versions <v1.1.3 that return a plain list of currency codes.
            $normalized = [];
            foreach ( (array) $cryptos as $crypto ) {
                if ( is_object( $crypto ) && ! empty( $crypto->name ) ) {
                    $normalized[] = [
                        'name'         => $crypto->name,
                        'display_name' => ! empty( $crypto->display_name ) ? $crypto->display_name : strtoupper( $crypto->name ),
                    ];
                } elseif ( is_scalar( $crypto ) ) {
                    $normalized[] = [
                        'name'         => (string) $crypto,
                        'display_name' => strtoupper( (string) $crypto ),
                    ];
                }
            }

            return rest_ensure_response( $normalized );
        }
    ] );
} );