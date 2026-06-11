<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shkeeper_Webhook_Handler
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

        $request_body      = wp_kses( file_get_contents( 'php://input' ), 'strip' );
        $request_signature = isset( $_SERVER[ Shkeeper_Webhook_State::SHKEEPER_HTTP_SIGNATURE ] )
            ? wp_kses( wp_unslash( $_SERVER[ Shkeeper_Webhook_State::SHKEEPER_HTTP_SIGNATURE ] ), 'strip' )
            : '';

        $validation_result = $this->validate_request( $request_signature, $request_body );
        if ( Shkeeper_Webhook_State::VALIDATION_SUCCEEDED === $validation_result ) {
            Shkeeper_Logger::log( 'Validated webhook body: ' . print_r( $request_body, true ) );

            $status_code = $this->process_webhook( $request_body ) ? 202 : 204;

            status_header( $status_code );
            exit;
        } else {
            Shkeeper_Logger::log( 'Incoming webhook failed validation: ' . $validation_result );
            status_header( 204 );
            exit;
        }
    }

    public function validate_request( $request_signature, $request_body ) {
        if ( empty( $request_signature ) ) {
            return Shkeeper_Webhook_State::VALIDATION_FAILED_MISSED_SIGNATURE;
        }
        if ( empty( $request_body ) ) {
            return Shkeeper_Webhook_State::VALIDATION_FAILED_EMPTY_BODY;
        }

        $shkeeperSettings = get_option('woocommerce_shkeeper_settings');
        $expected_key     = isset( $shkeeperSettings['api_key'] ) ? (string) $shkeeperSettings['api_key'] : '';

        if ( '' === $expected_key || ! hash_equals( $expected_key, (string) $request_signature ) ) {
            return Shkeeper_Webhook_State::VALIDATION_FAILED_API_KEY_INVALID;
        }

        return Shkeeper_Webhook_State::VALIDATION_SUCCEEDED;
    }

    /**
     * Processes the incoming webhook.
     *
     * @param string $request_body
     */
    public function process_webhook( $request_body ) {
        $request = json_decode( $request_body );

        // Validate the decoded payload structure before trusting any field.
        if ( ! is_object( $request )
            || ! isset( $request->external_id )
            || empty( $request->transactions )
            || ! is_array( $request->transactions )
        ) {
            return false;
        }

        $order = wc_get_order( $request->external_id );


        if ( ! $order || ! Shkeeper_WC_Gateway::is_me( $order->get_payment_method() ) ) {
            return false;
        }

        $triggerTransaction = '';
        foreach ( $request->transactions as $transaction ) {
            if ( is_object( $transaction ) && ! empty( $transaction->trigger ) ) {
                $triggerTransaction = $transaction;
                break;
            }
        }

        if ( empty( $triggerTransaction ) ) {
            return false;
        }


        $txid      = sanitize_text_field( $triggerTransaction->txid ?? '' );
        $processed = (array) $order->get_meta( '_shkeeper_processed_txids', true );
        if ( $txid && in_array( $txid, $processed, true ) ) {
            return true;
        }

        $note = __( "Transaction recieved. TXID: ", 'shkeeper-payment-gateway' );
        $order->add_order_note( $note
            . $txid . ' '
            . sanitize_text_field( $triggerTransaction->crypto ?? '' ) . ' '
            . sanitize_text_field( $triggerTransaction->amount_crypto ?? '' ) );

        if ( $txid ) {
            $processed[] = $txid;
            $order->update_meta_data( '_shkeeper_processed_txids', $processed );
            $order->save_meta_data();
        }

        if ( $order->is_paid() ) {
            return true;
        }

        if ( ! empty( $request->paid )
            && isset( $request->balance_fiat )
            && (float) $request->balance_fiat >= (float) $order->get_total() ) {
            return $order->payment_complete( $txid );
        } else {
            $order->update_status( 'partial' );
        }

        return true;
    }

}

new Shkeeper_Webhook_Handler();
