<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

//http://yoursite.com/?wc-api=shkeeper-callback
class WC_Shkeeper_Webhook_Handler
{
    public function __construct()
    {
        add_action( 'woocommerce_api_shkeeper-callback', array( $this, 'webhook' ) );
    }

    public function webhook() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] )
            || ( 'POST' !== $_SERVER['REQUEST_METHOD'] )
            || ! isset( $_GET['wc-api'] )
            || ( 'shkeeper-callback' !== $_GET['wc-api'] )
        ) {
            return;
        }

        $request_body    = file_get_contents( 'php://input' );
        $request_headers = array_change_key_case( $this->get_request_headers(), CASE_UPPER );

        //WC_Shkeeper_Logger::log('request headers ' . print_r($request_headers, 1));
        WC_Shkeeper_Logger::log('request body ' . print_r($request_body, 1));
        // Validate it to make sure it is legit.
        $validation_result = $this->validate_request( $request_headers, $request_body );
        if ( WC_Shkeeper_Webhook_State::VALIDATION_SUCCEEDED === $validation_result ) {

            $status_code = $this->process_webhook( $request_body ) ? 202 : 204;

            status_header( $status_code );
            exit;
        } else {
            WC_Shkeeper_Logger::log( 'Incoming webhook failed validation: ' .
                $validation_result . PHP_EOL . print_r( $request_body, true ) );
            status_header( 204 );
            exit;
        }
    }

    public function validate_request( $request_headers, $request_body ) {
        if ( empty( $request_headers ) ) {
            return WC_Shkeeper_Webhook_State::VALIDATION_FAILED_EMPTY_HEADERS;
        }
        if ( empty( $request_body ) ) {
            return WC_Shkeeper_Webhook_State::VALIDATION_FAILED_EMPTY_BODY;
        }

        $shkeeperSettings = get_option('woocommerce_shkeeper_settings');
        if ( !isset($request_headers['X-SHKEEPER-API-KEY']) ||
            $request_headers['X-SHKEEPER-API-KEY'] !== $shkeeperSettings['api_key'] ) {
            return WC_Shkeeper_Webhook_State::VALIDATION_FAILED_API_KEY_INVALID;
        }

        return WC_Shkeeper_Webhook_State::VALIDATION_SUCCEEDED;
    }

    /**
     * Gets the incoming request headers. Some servers are not using
     * Apache and "getallheaders()" will not work so we may need to
     * build our own headers.
     *
     */
    public function get_request_headers() {
        if ( ! function_exists( 'getallheaders' ) ) {
            $headers = [];

            foreach ( $_SERVER as $name => $value ) {
                if ( 'HTTP_' === substr( $name, 0, 5 ) ) {
                    $headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
                }
            }

            return $headers;
        } else {
            return getallheaders();
        }
    }

    /**
     * Processes the incoming webhook.
     *
     * @param string $request_body
     */
    public function process_webhook( $request_body ) {
        $request = json_decode( $request_body );

        if($request->paid) {
            return $this->process_webhook_payment($request);
        }

        //TODO run add transaction method if partial payments supports
        return true;
    }

    public function process_webhook_payment($request) {

        $order = wc_get_order( $request->external_id );
        $transaction = $this->get_last_transaction($request);

        if(wc_strtolower($request->crypto) == wc_strtolower($order->get_meta('shkeeper_crypto_curr')) &&
            $request->balance_fiat >= $order->get_total() ) {

            return $order->payment_complete($transaction->txid);

        }

        return false;

    }

    public function get_last_transaction($request) {
        $keys = array_keys($request->transactions);
        return $request->transactions[$keys[count($keys)-1]];
    }

}

new WC_Shkeeper_Webhook_Handler();