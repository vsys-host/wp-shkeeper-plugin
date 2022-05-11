<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Shkeeper_API {
    private static $shkeeper_settings;

    public static function getCryptosList($endpoint = '/crypto') {
        $cryptos = self::request($endpoint, '', 'GET');
        if($cryptos->status == 'success' && $cryptos->crypto) {
            return $cryptos->crypto;
        }

        WC_Shkeeper_Logger::log(
            'Unexpected Response: ' . print_r( $cryptos, true ) . PHP_EOL . PHP_EOL . 'Failed request: ' . print_r(
                [
                    'api endpoint'    => $endpoint,
                ],
                true
            )
        );
        return new WP_Error( 'shkeeper_error', __( 'There was a problem getting crypto currencies from Shkeeper API.', 'shkeeper-payments-woo' ) .
            ' ' . $cryptos->message ?? '' );
    }

    public static function sendPaymentRequest($order) {
        $payment_request = [
            'external_id'  => $order->get_id(),
            'fiat'         => $order->get_currency(),
            'amount'       => $order->get_total(),
            'callback_url' => get_site_url() . '/?wc-api=shkeeper-callback',
        ];

        $endpoint = '/' . wc_strtoupper(get_post_meta( $order->get_id(), 'shkeeper_crypto_curr', true )) . '/payment_request';
        $request =  self::request($endpoint, json_encode($payment_request, JSON_UNESCAPED_SLASHES));

        if($request->status == 'success') {
            return $request;
        }

        WC_Shkeeper_Logger::log(
            'Unexpected Response: ' . print_r( $request, true ) . PHP_EOL . PHP_EOL . 'Failed request: ' . print_r(
                [
                    'api endpoint'    => $endpoint,
                    'api_key'         => substr_replace(self::getShkeeperSettings()['api_key'], '********', - (strlen(self::getShkeeperSettings()['api_key']) / 2 + 4), - (strlen(self::getShkeeperSettings()['api_key']) / 2 - 4)),
                ],
                true
            )
        );
        return new WP_Error( 'shkeeper_error', __( 'There was a problem with creating payment request in Shkeeper API.', 'shkeeper-payments-woo' ) .
            ' ' . $request->message ?? '' );
    }

    public static function request($endpoint, $data, $method = 'POST', $include_headers = false){

        WC_Shkeeper_Logger::log($method . ' request ' . $endpoint . PHP_EOL . 'Request data: ' . print_r($data, true));

        $headers = self::getHeaders();

        switch ( $method ) {
            case 'GET':
                $response = wp_remote_get(
                    self::getShkeeperSettings()['api_url'] . $endpoint,
                    [
                        'method'  => $method,
                        'headers' => $headers,
                        'timeout' => 70,
                    ]
                );
                break;
            case 'POST':
                $response = wp_remote_post(
                    self::getShkeeperSettings()['api_url'] . $endpoint,
                    [
                        'method'  => $method,
                        'headers' => $headers,
                        'body'    => $data,
                        'timeout' => 70,
                    ]
                );
                break;
        }

        if ( is_wp_error( $response ) || empty( $response['body'] ) ) {
            WC_Shkeeper_Logger::log(
                'Error Response: ' . print_r( $response, true ) . PHP_EOL . PHP_EOL . 'Failed request: ' . print_r(
                    [
                        'api url'    => self::getShkeeperSettings()['api_url'] . $endpoint,
                        'request data'    => $data,
                    ],
                    true
                )
            );
            return new WP_Error( 'shkeeper_error', __( 'There was a problem connecting to the Shkeeper API endpoint.', 'shkeeper-payments-woo' ) );
        }

        $jsonObj = json_decode( $response['body'] );
        if ($jsonObj === null && json_last_error() !== JSON_ERROR_NONE) {
            WC_Shkeeper_Logger::log(
                'Received JSON data is incorrect: ' . print_r( $response['body'], true ) . PHP_EOL . PHP_EOL . 'Failed request: ' . print_r(
                    [
                        'api url'    => self::getShkeeperSettings()['api_url'] . $endpoint,
                        'request data'    => $data,
                    ],
                    true
                )
            );
            return new WP_Error( 'shkeeper_error', __( 'There was a problem getting response from Shkeeper API.', 'shkeeper-payments-woo' ) );
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
            'X-Shkeeper-API-Key' => self::getShkeeperSettings()['api_key'],
        ];

        return $headers;
    }

    private static function getShkeeperSettings() {
        if(!self::$shkeeper_settings) {
            self::$shkeeper_settings = get_option('woocommerce_shkeeper_settings');
        }
        return self::$shkeeper_settings;
    }

}