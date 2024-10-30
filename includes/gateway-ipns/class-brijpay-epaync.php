<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brijpay_EpayNC extends Brijpay_Gateway {
	protected $gateway_name = 'EpayNC';

	protected function init() {
		add_action(
			'woocommerce_api_wc_gateway_epaync',
			[
				$this,
				'gateway_response',
			],
			9
		);
	}

	public function gateway_response() {
		$raw_response     = (array) stripslashes_deep( $_REQUEST );
		$general_settings = get_option( 'woocommerce_epaync_settings', null );

		if ( empty( $general_settings ) || ! is_array( $general_settings ) ) {
			return;
		}

		$epaync_response = new EpayncResponse(
			$raw_response,
			$general_settings['ctx_mode'],
			$general_settings['key_test'],
			$general_settings['key_prod'],
			$general_settings['sign_algo']
		);

		if ( ! $epaync_response->isAuthentified() ) {
			return;
		}

		$order_id = $epaync_response->get( 'order_id' );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->log( __( 'Invalid order ID', 'brijpay-link' ), WC_Log_Levels::ERROR );

			return;
		}

		$this->log( 'Postdata: ' . json_encode( $raw_response ) );

		$order_data = [ 'datafeed' => $this->clean( $_REQUEST ) ];

		$token    = $epaync_response->get( 'identifier' );
		$txn_id   = $epaync_response->get( 'trans_id' );
		$err_code = $epaync_response->get( 'result' );
		$err_msg  = $epaync_response->getLogMessage();

		$order_data['datafeed']['__token'] = $token;
		$order_data['transaction_id']      = $txn_id;
		$order_data['success']             = $epaync_response->isAcceptedPayment();
		$order_data['error_code']          = $err_code;
		$order_data['error_message']       = $err_msg;

		$this->push_to_webhooks( $order, $order_data );
	}
}
