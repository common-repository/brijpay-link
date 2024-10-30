<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brijpay_Paydollar extends Brijpay_Gateway {
	protected $gateway_name = 'PayDollar';

	protected function init() {
		add_action(
			'woocommerce_api_wc_paydollar',
			[
				$this,
				'gateway_response',
			],
			9
		);
	}

	public function gateway_response() {
		$order_id = filter_input( INPUT_POST, 'Ref' );
		if ( empty( $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->log( __( 'Invalid order ID', 'brijpay-link' ), WC_Log_Levels::ERROR );

			return;
		}

		$this->log( 'Postdata: ' . file_get_contents( 'php://input' ) );

		$order_data   = [ 'datafeed' => $this->clean( $_POST ) ];
		$static_token = filter_input( INPUT_POST, 'mpLatestStaticToken' );
		$token        = $this->decrypt_static_token( $static_token );

		$txn_id      = filter_input( INPUT_POST, 'PayRef' );
		$err_msg     = filter_input( INPUT_POST, 'errMsg' );
		$prc         = filter_input( INPUT_POST, 'prc' );
		$src         = filter_input( INPUT_POST, 'src' );
		$successcode = filter_input( INPUT_POST, 'successcode' );

		$order_data['datafeed']['__token'] = $token;
		$order_data['transaction_id']      = $txn_id;
		$order_data['success']             = '0' === $prc && '0' === $src && '0' === $successcode;
		$order_data['error_code']          = $prc;
		$order_data['error_message']       = $err_msg;

		$this->push_to_webhooks( $order, $order_data );
	}

	/**
	 * Decrypt static token
	 *
	 * @param string $data
	 *
	 * @return false|string
	 */
	private function decrypt_static_token( $data ) {
		$paydollar_settings = get_option( 'woocommerce_paydollar_settings', [] );
		$key                = $paydollar_settings['mb_token_key'] ?? '';
		$salt               = $paydollar_settings['mb_token_salt'] ?? '';

		return openssl_decrypt( $data, 'aes-256-cbc', $key, 0, $salt );
	}
}
