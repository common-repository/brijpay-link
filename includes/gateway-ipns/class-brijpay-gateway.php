<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brijpay_Gateway {
	/**
	 * @var WC_Logger
	 */
	protected $logger = null;

	/**
	 * @var string
	 */
	protected $gateway_name = '';

	/**
	 * @var string
	 */
	protected $handler = 'brijpay_webhook';

	public function __construct() {
		$this->brijpay_webhook_url    = get_option( 'brijpay_cloud_url' );
		$this->brijpay_webhook_store  = get_option( 'brijpay_cloud_store' );
		$this->brijpay_webhook_token  = get_option( 'brijpay_cloud_token' );
		$this->brijpay_webhook_send   = get_option( 'brijpay_cloud_send_order' );
		$this->brijpay_webhook_status = str_replace( 'wc-', '', get_option( 'brijpay_cloud_order_status' ) );

		$this->init();
	}

	protected function init() {
	}

	/**
	 * @return void
	 */
	public function gateway_response() {
	}

	/**
	 * @param WC_Order $order
	 * @param array $order_data
	 *
	 * @return void
	 */
	public function push_to_webhooks( WC_Order $order, $order_data ) {
		if ( ! defined( 'BRIJPAY_WEBHOOK_SYNC' ) || ! BRIJPAY_WEBHOOK_SYNC ) {
			update_post_meta( $order->get_id(), 'brijpay_webhook_datafeed', $order_data );
		}

		$items       = $order->get_items();
		$is_contract = false;

		foreach ( $items as $item ) {
			if ( 'Contracts' !== get_post_meta( $item['product_id'], '_mb_sale_group', true ) ) {
				continue;
			}

			$is_contract = true;
			break;
		}

		$brijpay_order_data             = $order_data;
		$brijpay_order_data['raw_data'] = $brijpay_order_data['datafeed'];

		if ( array_key_exists( '__token', $brijpay_order_data['datafeed'] ) ) {
			unset( $brijpay_order_data['datafeed'], $brijpay_order_data['raw_data']['__token'] );
		}

		$brijpay_order_data['raw_type']         = 'json';
		$brijpay_order_data['products']         = [];
		$brijpay_order_data['currency']         = $order->get_currency();
		$brijpay_order_data['amount']           = $order->get_total();
		$brijpay_order_data['description']      = $order->get_customer_note();
		$brijpay_order_data['subscription_end'] = null;
		$brijpay_order_data['transaction_date'] = $order->get_date_created()->format( DATE_ATOM . '\Z' );
		$brijpay_order_data['payment_gateway']  = $order->get_payment_method_title();

		$data = $order->get_data();
		
		$custom_fields = array();
		$meta = get_post_meta($order->get_id());
		
		foreach($meta as $key => $value) {
			if (fnmatch("custom_*", $key)) {
		       	$custom_fields[$key] = $value[0];
		    }
		}
		
		$data['billing'] = array_merge($data['billing'],$custom_fields);
		$brijpay_order_data['client'] = $data['billing'];
		
		/**
		 * @var WC_Order_Item_Product[] $line_items
		 */
		$line_items     = $data['line_items'];
		$new_line_items = [];
		foreach ( $line_items as $line_item ) {
			$new_line_items[ $line_item->get_id() ] = $line_item->get_data();

			$mb_product     = get_post_meta( $line_item->get_product_id(), '_mb_sale_res', true );
			$mb_product_id  = get_post_meta( $line_item->get_product_id(), '_mb_sale_id', true );

			if ( empty( $mb_product ) || empty( $mb_product_id ) ) {
				continue;
			}

			$mb_product = json_decode( $mb_product, true );
			$amount     = $order->get_line_total( $line_item, wc_prices_include_tax() );
			$qty        = absint( $line_item->get_quantity() );
			$amount     /= $qty;

			$brijpay_order_detail = [
				'id'     => $line_item->get_product()->get_sku(),
				'name'   => $line_item->get_name(),
				'amount' => (string) $amount,
			];

			// Explode products based on quantity
			for ( $i = 1; $i <= $qty; $i ++ ) {
				$brijpay_order_data['products'][] = $brijpay_order_detail;
			}

			if ( 'Contracts' === get_post_meta( $line_item->get_product_id(), '_mb_sale_group', true ) ) {
				$new_line_items[ $line_item->get_id() ]['contracts'] = $mb_product;
			}
		}

		if ( empty( $brijpay_order_data['products'] ) ) {
			$this->log( __( 'Order does not have any products from BRIJPAY Cloud.', 'brijpay-link' ) );

			return;
		}

		$brijpay_webhook = 1;

		if ( defined( 'BRIJPAY_WEBHOOK_SYNC' ) && BRIJPAY_WEBHOOK_SYNC ) {
			$brijpay_webhook = absint( get_post_meta( $order->get_id(), 'brijpay_webhook_brijpay_pending', true ) );
		}

		$brijpay_wh = true;

		$brijpay_order_data['brijpay_link_version'] = get_option( 'brijpay_link_version' );

		if ( $brijpay_webhook ) {
			$brijpay_wh = $this->push_to_brijpay_wh( $order, $brijpay_order_data );
		}

		if ( ! $brijpay_wh ) {
			update_post_meta( $order->get_id(), 'brijpay_webhook_brijpay_pending', 1 );
		}

		if ( $brijpay_wh ) {
			delete_post_meta( $order->get_id(), 'brijpay_webhook_datafeed' );
		}
	}

	/**
	 * @param WC_Order $order
	 * @param array $order_data
	 *
	 * @return bool
	 */
	public function push_to_brijpay_wh( WC_Order $order, $order_data ) {
		$order_data = json_encode( $order_data );

		if ( empty( $this->brijpay_webhook_url ) || ! filter_var( $this->brijpay_webhook_url, FILTER_VALIDATE_URL ) ) {
			$this->log( 'Invalid Webhook URL: ' . $this->brijpay_webhook_url, WC_Log_Levels::ERROR );

			return false;
		}

		if ( empty( $this->brijpay_webhook_store ) ) {
			$this->log( 'Payment store is empty', WC_Log_Levels::ERROR );

			return false;
		}

		if ( empty( $this->brijpay_webhook_token ) ) {
			$this->log( 'Token is empty', WC_Log_Levels::ERROR );

			return false;
		}

		$this->log( sprintf( __( 'Processing Order #%d', 'brijpay-link' ), $order->get_id() ) );
		$this->log( 'Brijpay Cloud Payload: ' . $order_data );
		$this->log( __( 'Transmitting data to webhook Brijpay...', 'brijpay-link' ) );

		$url = rtrim( $this->brijpay_webhook_url, '/' ) . '/' . $this->brijpay_webhook_store;

		$req = wp_remote_request(
			$url,
			[
				'method'  => 'POST',
				'body'    => $order_data,
				'headers' => [
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'Token ' . $this->brijpay_webhook_token,
				],
			]
		);

		if ( is_wp_error( $req ) ) {
			$this->log( $req->get_error_message() );

			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $req );

		if ( 200 === $response_code ) {
			$this->log( 'Success.' );
			delete_post_meta( $order->get_id(), 'brijpay_webhook_brijpay_pending' );
			update_post_meta( $order->get_id(), 'brijpay_webhook_sent', 1 );

			return true;
		}

		$this->log( sprintf( 'Failed with response code {%s}', $response_code ) );

		return false;
	}

	protected function log( $msg, $level = WC_Log_Levels::NOTICE ) {
		if ( ! $this->logger ) {
			$this->logger = wc_get_logger();
		}

		$msg = $this->gateway_name ? "{$this->gateway_name} - {$msg}" : $msg;

		return $this->logger->add( $this->handler, $msg, $level );
	}

	public function set_log_handler( $handler ) {
		$this->handler = $handler;
	}

	protected function clean( array $data ) {
		$data_c = $data;
		array_walk_recursive( $data_c, [ $this, 'sanitize' ] );

		return $data_c;
	}

	protected function sanitize( $value, $key ) {
		wc_clean( $value );
	}

	/**
	 * @param int $order_id
	 *
	 * @return bool
	 */
	protected function is_subscription_order( $order_id ) {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return false;
		}

		$subscription_orders = wcs_get_subscriptions_for_order( $order_id, [
			'order_type' => [
				'renewal'
			]
		] );

		return count( $subscription_orders ) > 0;
	}
}
