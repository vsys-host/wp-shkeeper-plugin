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

        $request_body    = wp_kses(file_get_contents( 'php://input' ), 'strip');
        $request_signature = wp_kses($_SERVER[Shkeeper_Webhook_State::SHKEEPER_HTTP_SIGNATURE], 'strip');

        Shkeeper_Logger::log('request body ' . print_r($request_body, 1));
        // Validate it to make sure it is legit.
        $validation_result = $this->validate_request( $request_signature, $request_body );
        if ( Shkeeper_Webhook_State::VALIDATION_SUCCEEDED === $validation_result ) {

            $status_code = $this->process_webhook( $request_body ) ? 202 : 204;

            status_header( $status_code );
            exit;
        } else {
            Shkeeper_Logger::log( 'Incoming webhook failed validation: ' .
                $validation_result . PHP_EOL . print_r( $request_body, true ) );
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
        if ( $request_signature !== $shkeeperSettings['api_key'] ) {
            return Shkeeper_Webhook_State::VALIDATION_FAILED_API_KEY_INVALID;
        }

        return Shkeeper_Webhook_State::VALIDATION_SUCCEEDED;
    }

    /**
     * Processes the incoming webhook.
     *
     * @param string $request_body
    ) */
    public function process_webhook( $request_body ) {
        $request = json_decode( $request_body );
	$order = wc_get_order( $request->external_id );

	if(!$order) {
	  return false;
	}
	
	$triggerTransaction = '';
        foreach($request->transactions as $transaction) {
          if($transaction->trigger) {
            $triggerTransaction = $transaction;
            break;
          }
        }

        if(empty($triggerTransaction)) {
           return false;
        }


	$note = __("Transaction recieved. TXID: ", 'shkeeper-payment-gateway');
        $order->add_order_note( $note . $triggerTransaction->txid . ' ' . $triggerTransaction->crypto . ' ' . $triggerTransaction->amount_crypto);        
        if($request->paid && $request->balance_fiat >= $order->get_total()) {
	    return $order->payment_complete($triggerTransaction->txid);
	} else {
	   $order->update_status('partial');
	} 

        return true;
    }
   
}

new Shkeeper_Webhook_Handler();
