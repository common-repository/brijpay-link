<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brijpay Paygate Integration
 *
 * @version 1.0.0
 */
class Brijpay_Paygate extends Brijpay_Gateway {
	protected $gateway_name = 'PayGate';

	protected function init() {
		add_action(
			'woocommerce_api_wc_gateway_paygate_notify',
			[
				$this,
				'gateway_response',
			],
			9
		);

		add_action(
			'woocommerce_api_wc_gateway_paygate_redirect',
			[
				$this,
				'gateway_redirect_response',
			],
			9
		);
	}

	public function gateway_response() {
		$ref = filter_input( INPUT_POST, 'REFERENCE' );

		if ( empty( $ref ) ) {
			return;
		}

		$order_refs = explode( '-', $ref );
		$order_id   = absint( $order_refs[0] );

		if ( empty( $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->log( __( 'Invalid order ID', 'brijpay-link' ), WC_Log_Levels::ERROR );
		}

		$token = filter_input( INPUT_POST, 'VAULT_ID' );

		$this->log( 'Postdata: ' . file_get_contents( "php://input" ) );

		$order_data = [ 'datafeed' => $this->clean( $_POST ) ];

		$order_data['datafeed']['__token'] = $token;

		$txn_id     = filter_input( INPUT_POST, 'TRANSACTION_ID' );
		$txn_status = filter_input( INPUT_POST, 'TRANSACTION_STATUS' );
		$err_msg    = filter_input( INPUT_POST, 'RESULT_DESC' );

		$order_data['transaction_id'] = $txn_id;
		$order_data['success']        = '1' === $txn_status;
		$order_data['error_code']     = $txn_status;
		$order_data['error_message']  = $err_msg;

		$this->push_to_webhooks( $order, $order_data );
	}

	public function gateway_redirect_response() {
		$request_id = filter_input( INPUT_POST, 'PAY_REQUEST_ID' );
		$gid = filter_input( INPUT_GET, 'gid' );

		if ( ! ( $request_id && $gid ) ) {
			return;
		}

		$order_id = absint( $gid );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return $this->log( __( 'Invalid order ID', 'brijpay-link' ), WC_Log_Levels::ERROR );
		}

		$this->log( 'Postdata: ' . file_get_contents( "php://input" ) );

		$customer_id = $order->get_customer_id();

		$http_response = function ( $response, $parsed_args, $url ) use ( $customer_id, $order, &$http_response ) {
			remove_filter( 'http_response', $http_response, 9 );

			parse_str( $response['body'], $parsed_response );

			$paygate = get_option( 'woocommerce_paygate_settings' );

			if ( 'yes' === $paygate['payvault'] ) {
				$vault_card = get_post_meta( $customer_id, 'wc-paygate-new-payment-method', true );

				if ( true === $vault_card && array_key_exists( 'VAULT_ID', $parsed_response ) ) {
					$order_data                        = [ 'datafeed' => $parsed_response ];
					$order_data['datafeed']['__token'] = $parsed_response['VAULT_ID'];

					$txn_id     = $parsed_response['TRANSACTION_ID'];
					$txn_status = $parsed_response['TRANSACTION_STATUS'];
					$err_msg    = $parsed_response['RESULT_DESC'];

					$order_data['transaction_id'] = $txn_id;
					$order_data['success']        = '1' === $txn_status;
					$order_data['error_code']     = $txn_status;
					$order_data['error_message']  = $err_msg;

					$this->push_to_webhooks( $order, $order_data );
				}
			}

			return $response;
		};

		add_filter(
			'http_response',
			$http_response,
			9,
			3
		);
	}
}
