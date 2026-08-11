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

            // Non-202 tells the gateway the callback was not applied, so it can retry.
            $status_code = $this->process_webhook( $request_body ) ? 202 : 400;

            status_header( $status_code );
            exit;
        } else {
            Shkeeper_Logger::log( 'Incoming webhook failed validation: ' . $validation_result );
            status_header( 401 );
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

        $triggerTransaction = null;
        foreach ( $request->transactions as $transaction ) {
            if ( is_object( $transaction ) && ! empty( $transaction->trigger ) ) {
                $triggerTransaction = $transaction;
                break;
            }
        }

        if ( null === $triggerTransaction ) {
            return false;
        }


        $txid      = sanitize_text_field( $triggerTransaction->txid ?? '' );
        $processed = array_filter( (array) $order->get_meta( '_shkeeper_processed_txids', true ) );
        if ( $txid && in_array( $txid, $processed, true ) ) {
            return true;
        }

        // Simultaneous duplicates of the same callback race the meta-based
        // dedup above (its read and write are not atomic), so serialize per
        // txid. The loser answers non-2xx: the gateway's retry then lands on
        // the dedup guard if the winner succeeded, or reprocesses if it died.
        $lock = $txid ? 'shkeeper_txlock_' . md5( $order->get_id() . ':' . $txid ) : '';
        if ( $lock && ! $this->acquire_txid_lock( $lock ) ) {
            Shkeeper_Logger::log( 'Concurrent callback for txid ' . $txid . ' is already being processed, asking for a retry.' );
            return false;
        }

        try {
            return $this->apply_trigger_transaction( $order, $request, $triggerTransaction, $txid, $processed );
        } finally {
            if ( $lock ) {
                $this->release_txid_lock( $lock );
            }
        }
    }

    /**
     * Applies the trigger transaction to the order.
     *
     * The txid is recorded as processed only after the outcome (paid/partial)
     * has been applied: recording it first would let a failed
     * payment_complete() swallow the gateway's retry in the dedup guard and
     * leave a fully paid order stuck at "invoiced".
     *
     * @param WC_Order $order
     * @param object   $request            Decoded webhook payload.
     * @param object   $triggerTransaction Transaction that fired the callback.
     * @param string   $txid
     * @param array    $processed          Previously processed txids.
     * @return bool True when applied (2xx), false to make the gateway retry.
     */
    private function apply_trigger_transaction( $order, $request, $triggerTransaction, $txid, array $processed ) {
        $note = __( "Transaction received. TXID: ", 'shkeeper-payment-gateway' );
        $order->add_order_note( $note
            . $txid . ' '
            . sanitize_text_field( $triggerTransaction->crypto ?? '' ) . ' '
            . sanitize_text_field( $triggerTransaction->amount_crypto ?? '' ) );

        if ( ! $order->is_paid() ) {
            $required_fiat = (float) $order->get_meta( 'shkeeper_fiat_amount', true );
            if ( $required_fiat <= 0 ) {
                $required_fiat = (float) $order->get_total();
            }

            if ( ! empty( $request->paid )
                && isset( $request->balance_fiat )
                && (float) $request->balance_fiat + 0.1 >= (float)$required_fiat ) {
                if ( ! $order->payment_complete( $txid ) ) {
                    Shkeeper_Logger::log( 'payment_complete() failed for order ' . $order->get_id() . ', txid ' . $txid . '; leaving the callback retryable.' );
                    return false;
                }
            } else {
                $order->update_status( 'partial' );
            }
        }

        if ( $txid ) {
            $processed[] = $txid;
            $order->update_meta_data( '_shkeeper_processed_txids', $processed );
            $order->save_meta_data();
        }

        return true;
    }

    /**
     * Take the per-txid processing lock. Mirrors WP_Upgrader::create_lock():
     * an INSERT IGNORE against the options table's UNIQUE option_name key is
     * atomic, unlike add_option(), whose exists-check can race. A lock older
     * than a minute is treated as abandoned by a crashed request and taken
     * over.
     *
     * @param string $lock_name
     * @return bool Whether the lock was acquired.
     */
    private function acquire_txid_lock( $lock_name ) {
        global $wpdb;

        $acquired = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO `{$wpdb->options}` ( `option_name`, `option_value`, `autoload` ) VALUES (%s, %s, 'no') /* LOCK */",
            $lock_name,
            (string) time()
        ) );

        if ( $acquired ) {
            return true;
        }

        $held_since = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s",
            $lock_name
        ) );

        if ( $held_since > time() - MINUTE_IN_SECONDS ) {
            return false;
        }

        // Stale lock from a crashed request: take it over.
        $wpdb->query( $wpdb->prepare(
            "UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s",
            (string) time(),
            $lock_name
        ) );

        return true;
    }

    /**
     * Release the per-txid processing lock.
     *
     * @param string $lock_name
     */
    private function release_txid_lock( $lock_name ) {
        global $wpdb;

        $wpdb->delete( $wpdb->options, array( 'option_name' => $lock_name ) );
        wp_cache_delete( $lock_name, 'options' );
    }

}
