<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brijpay Pelecard Integration
 *
 * @version 1.0.0
 */
class Brijpay_Pelecard extends Brijpay_Gateway {
	protected $gateway_name = 'Pelecard';

	protected function init() {
		add_filter( 'add_post_metadata', [ $this, 'api_gateway_response' ], 1, 5 );
	}

	public function api_gateway_response( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		if ( '_transaction_data' !== $meta_key ) {
			return $check;
		}

		if ( ! is_array( $meta_value ) ) {
			return $check;
		}

		$order = wc_get_order( $object_id );
		if ( ! $order ) {
			$this->log( __( 'Invalid order ID', 'brijpay-link' ), WC_Log_Levels::ERROR );

			return $check;
		}

		remove_filter( 'add_post_metadata', [ $this, 'api_gateway_response' ], 1 );

		$transaction = new WC_Pelecard_Transaction( null, $meta_value );

		$this->log( 'Postdata: ' . json_encode( $transaction ) );

		$txn_id = $transaction->TransactionId;

		if ( ! $txn_id ) {
			$txn_id = $transaction->PelecardTransactionId;
		}

		$transaction->__token = $transaction->Token;
		$order_data           = [
			'datafeed'       => (array) $transaction,
			'transaction_id' => $txn_id,
			'success'        => $transaction->is_success(),
			'error_message'  => $transaction->ErrorMessage,
			'error_code'     => $transaction->StatusCode,
		];

		$this->push_to_webhooks( $order, $order_data );

		return $check;
	}
}
