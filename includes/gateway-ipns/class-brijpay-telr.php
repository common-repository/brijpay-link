<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brijpay Telr Integration
 *
 * @version 1.1.0
 */
class Brijpay_Telr extends Brijpay_Gateway {
	protected $gateway_name = 'Telr';

	protected function init() {
		add_action( 'woocommerce_api_wc_gateway_telr', [ $this, 'api_gateway_response' ], 9 );
	}

	/**
	 * @param WP_Query $query
	 */
	public function api_gateway_response() {
		$tran_cartid   = filter_input( INPUT_POST, 'tran_cartid' );
		$cartIdExtract = explode( "_", $tran_cartid );
		$order_id      = $cartIdExtract[0];

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->log( __( 'Invalid order ID', 'brijpay-link' ), WC_Log_Levels::ERROR );

			return;
		}

		$this->log( 'Postdata: ' . file_get_contents( "php://input" ) );

		$is_subscription = $this->is_subscription_order( $order_id );

		// If not a subscription, authorize cartid
		if ( ! $is_subscription ) {
			$cart_id = get_post_meta( $order_id, '_telr_cartid', true );
			if ( $cart_id !== $tran_cartid ) {
				$this->log( __( 'Cart ID did not match with the transaction', 'brijpay-link' ), WC_Log_Levels::ERROR );

				return;
			}
		}

		$tran_type   = filter_input( INPUT_POST, 'tran_type' );
		$tran_status = filter_input( INPUT_POST, 'tran_status' );
		$txn_id      = filter_input( INPUT_POST, 'tran_ref' );
		$err_msg     = filter_input( INPUT_POST, 'tran_authmessage' );
		$err_code    = filter_input( INPUT_POST, 'tran_authcode' );
		$success     = $tran_status === 'A' && ( $tran_type === 'sale' || $tran_type === 'capture' );

		$order_data = [
			'datafeed'       => $this->clean( $_POST ),
			'transaction_id' => $txn_id,
			'success'        => $success,
			'error_message'  => $err_msg,
			'error_code'     => $err_code,
			'subscription'   => $is_subscription,
		];

		$this->push_to_webhooks( $order, $order_data );
	}
}
