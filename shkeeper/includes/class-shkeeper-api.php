<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shkeeper_API {
    const CRYPTO_LIST_TRANSIENT = 'shkeeper_crypto_list_cache';

    private static $shkeeper_settings;

    public static function getCryptosList($endpoint = '/crypto') {
        $cached = get_transient( self::CRYPTO_LIST_TRANSIENT );
        if ( false !== $cached ) {
            return $cached;
        }

        $cryptos = self::request($endpoint, '', 'GET');
        if ( is_wp_error( $cryptos ) ) {
            return $cryptos;
        }

        if ( 'success' === ( $cryptos->status ?? '' ) && ! empty( $cryptos->crypto ) ) {
            $crypto_list = $cryptos->crypto_list ?? $cryptos->crypto;
            set_transient( self::CRYPTO_LIST_TRANSIENT, $crypto_list, 10 * MINUTE_IN_SECONDS );
            return $crypto_list;
        }

        Shkeeper_Logger::log(
            'Unexpected Response: ' . print_r( $cryptos, true ) . PHP_EOL . PHP_EOL . 'Failed request: ' . print_r(
                [
                    'api endpoint'    => $endpoint,
                ],
                true
            )
        );
        return new WP_Error( 'shkeeper_error', __( 'There was a problem getting crypto currencies from Shkeeper API.', 'shkeeper-payment-gateway' ) .
            ' ' . ( $cryptos->message ?? '' ) );
    }

    public static function sendPaymentRequest($order) {
        $crypto = strtoupper( (string) $order->get_meta( 'shkeeper_crypto_curr', true ) );
        if ( ! preg_match( '/^[A-Za-z0-9._-]{1,32}$/', $crypto ) ) {
            Shkeeper_Logger::log( 'Rejected invalid crypto currency value: ' . print_r( $crypto, true ) );
            return new WP_Error( 'shkeeper_error', __( 'Invalid cryptocurrency selected.', 'shkeeper-payment-gateway' ) );
        }

        $fiat   = strtoupper( (string) $order->get_currency() );
        $amount = (float) $order->get_total();

        // SHKeeper processes the invoice in the configured fiat. WooCommerce has no exchange rates of
        // its own, so conversion uses the configured rate; an absent setting
        // (plugin updated without re-saving) keeps the pre-conversion behavior.
        $settings   = self::getShkeeperSettings();
        $processing = strtoupper( trim( (string) ( $settings['processing_currency'] ?? '' ) ) );

        if ( '' !== $processing && $processing !== $fiat ) {
            $rate = (float) apply_filters(
                'shkeeper_exchange_rate',
                (float) ( $settings['exchange_rate'] ?? 0 ),
                $fiat,
                $processing,
                $order
            );

            if ( $rate <= 0 ) {
                // fail closed: a 1:1 fallback would silently mischarge the customer
                Shkeeper_Logger::log( sprintf(
                    'Cannot convert %s to the processing currency %s: no exchange rate configured.',
                    $fiat,
                    $processing
                ) );
                return new WP_Error( 'shkeeper_error', __( 'The payment method is misconfigured. Please contact the store owner.', 'shkeeper-payment-gateway' ) );
            }

            $amount = round( $amount * $rate, 2 );
            $fiat   = $processing;
        }

        // Recorded so the webhook compares the paid balance against the exact
        // amount the invoice was created for; persisted by the caller's save.
        $order->update_meta_data( 'shkeeper_fiat_currency', $fiat );
        $order->update_meta_data( 'shkeeper_fiat_amount', (string) $amount );

        $payment_request = [
            'external_id'  => $order->get_id(),
            'fiat'         => $fiat,
            'amount'       => $amount,
            'callback_url' => get_site_url() . '/?wc-api=shkeeper-callback',
        ];

        $endpoint = '/' . rawurlencode( $crypto ) . '/payment_request';
        $request =  self::request($endpoint, wp_json_encode($payment_request, JSON_UNESCAPED_SLASHES));

        if ( is_wp_error( $request ) ) {
            return $request;
        }

        if ( 'success' === ( $request->status ?? '' ) ) {
            return $request;
        }

        $api_key = (string) ( self::getShkeeperSettings()['api_key'] ?? '' );
        $masked  = strlen( $api_key ) > 4
            ? str_repeat( '*', max( 0, strlen( $api_key ) - 4 ) ) . substr( $api_key, -4 )
            : '****';
        Shkeeper_Logger::log(
            'Unexpected Response: ' . print_r( $request, true ) . PHP_EOL . PHP_EOL . 'Failed request: ' . print_r(
                [
                    'api endpoint'    => $endpoint,
                    'api_key'         => $masked,
                ],
                true
            )
        );
        return new WP_Error( 'shkeeper_error', __( 'There was a problem with creating payment request in Shkeeper API.', 'shkeeper-payment-gateway' ) .
            ' ' . ( $request->message ?? '' ) );
    }

    public static function request($endpoint, $data, $method = 'POST', $include_headers = false){

        Shkeeper_Logger::log($method . ' request ' . $endpoint . PHP_EOL . 'Request data: ' . print_r($data, true));

        $headers = self::getHeaders();

        switch ( $method ) {
            case 'GET':
                $response = wp_remote_get(
                    self::getShkeeperApiUrl() . $endpoint,
                    [
                        'method'  => $method,
                        'headers' => $headers,
                        'timeout' => 40,
                    ]
                );
                break;
            case 'POST':
                $response = wp_remote_post(
                    self::getShkeeperApiUrl() . $endpoint,
                    [
                        'method'  => $method,
                        'headers' => $headers,
                        'body'    => $data,
                        'timeout' => 40,
                    ]
                );
                break;
            default:
                return new WP_Error( 'shkeeper_error', __( 'Unsupported HTTP method for Shkeeper API request.', 'shkeeper-payment-gateway' ) );
        }

        if ( is_wp_error( $response ) || empty( $response['body'] ) ) {
            Shkeeper_Logger::log(
                'Error Response: ' . print_r( $response, true ) . PHP_EOL . PHP_EOL . 'Failed request: ' . print_r(
                    [
                        'api url'    => self::getShkeeperApiUrl() . $endpoint,
                        'request data'    => $data,
                    ],
                    true
                )
            );
            return new WP_Error( 'shkeeper_error', __( 'There was a problem connecting to the Shkeeper API endpoint.', 'shkeeper-payment-gateway' ) );
        }

        $jsonObj = json_decode( $response['body'] );
        if ($jsonObj === null && json_last_error() !== JSON_ERROR_NONE) {
            Shkeeper_Logger::log(
                'Received JSON data is incorrect: ' . print_r( $response['body'], true ) . PHP_EOL . PHP_EOL . 'Failed request: ' . print_r(
                    [
                        'api url'    => self::getShkeeperApiUrl() . $endpoint,
                        'request data'    => $data,
                    ],
                    true
                )
            );
            return new WP_Error( 'shkeeper_error', __( 'There was a problem getting response from Shkeeper API.', 'shkeeper-payment-gateway' ) );
        }

        if ( $include_headers ) {
            return [
                'headers' => wp_remote_retrieve_headers( $response ),
                'body'    => $jsonObj,
            ];
        }

        return $jsonObj;
    }

    private static function getHeaders() {
        $headers = [
            'X-Shkeeper-API-Key' => self::getShkeeperSettings()['api_key'] ?? '',
        ];

        return $headers;
    }

    private static function getShkeeperApiUrl() {
    	return rtrim( (string) ( self::getShkeeperSettings()['api_url'] ?? '' ), '/' );
    }

    private static function getShkeeperSettings() {
        if(!self::$shkeeper_settings) {
            $settings = get_option('woocommerce_shkeeper_settings');
            self::$shkeeper_settings = is_array( $settings ) ? $settings : [];
        }
        return self::$shkeeper_settings;
    }

}
