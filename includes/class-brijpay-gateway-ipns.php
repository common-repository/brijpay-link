<?php

/**
 * Brijpay Gateway IPNS
 *
 * @version 1.0.0
 */

class Brijpay_Gateway_IPNS {

	/**
	 * The single instance of the queue.
	 *
	 * @var Brijpay_Gateway_IPNS|null
	 */
	protected static $instance = null;

	/**
	 * @var string[]
	 */
	protected $ipn_classes = [];

	/**
	 * Single instance of WC_Queue_Interface
	 *
	 * @return Brijpay_Gateway_IPNS
	 */
	final public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new Brijpay_Gateway_IPNS();
		}

		return self::$instance;
	}

	private function __construct() {
		include_once 'gateway-ipns/class-brijpay-gateway.php';
		include_once 'gateway-ipns/class-brijpay-epaync.php';
		include_once 'gateway-ipns/class-brijpay-paydollar.php';
		include_once 'gateway-ipns/class-brijpay-paygate.php';
		include_once 'gateway-ipns/class-brijpay-pelecard.php';
		include_once 'gateway-ipns/class-brijpay-telr.php';

		$this->brijpay_webhook_send = get_option( 'brijpay_cloud_send_order' );

		$this->ipn_classes = apply_filters(
			'brijpay_gateway_ipns_integration',
			[
				'Brijpay_EpayNC',
				'Brijpay_Paydollar',
				'Brijpay_Paygate',
				'Brijpay_Pelecard',
				'Brijpay_Telr',
			]
		);

		if ( 'yes' !== $this->brijpay_webhook_send ) {
			foreach ( $this->ipn_classes as $ipn_class ) {
				if ( class_exists( $ipn_class ) ) {
					new $ipn_class();
				}
			}
		}

		add_action( 'init', [ $this, 'every_day' ] );
		add_action( 'woocommerce_order_status_changed', [ $this, 'send_order_to_webhook' ], 10, 3 );
	}

	public function every_day() {
		$ch_token = get_option( 'brijpay_cloud_token' );
		if ( empty( $ch_token ) ) {
			as_unschedule_all_actions( 'brijpay_webhook_sync' );

			return;
		}

		if ( false === as_next_scheduled_action( 'brijpay_webhook_sync' ) ) {
			as_schedule_recurring_action( time(), DAY_IN_SECONDS, 'brijpay_webhook_sync', [], 'brijpay' );
		}

		add_action( 'brijpay_webhook_sync', [ $this, 'sync' ] );
	}

	public function sync() {
		$orders = wc_get_orders(
			[
				'numberposts' => - 1,
			]
		);

		if ( ! defined( 'BRIJPAY_WEBHOOK_SYNC' ) ) {
			define( 'BRIJPAY_WEBHOOK_SYNC', true );
		}

		foreach ( $orders as $order ) {
			$datafeed     = get_post_meta( $order->get_id(), 'brijpay_webhook_datafeed', true );
			$send_order   = get_option( 'brijpay_cloud_send_order' );
			$order_status = get_option( 'brijpay_cloud_order_status' );
			$order_status = str_replace( 'wc-', '', $order_status );

			if ( empty( $datafeed ) || ( 'yes' === $send_order && $order->get_status() !== $order_status ) ) {
				continue;
			}

			if ( 'yes' !== $send_order && ! $order->is_paid() ) {
				continue;
			}

			$gateway = new Brijpay_Gateway();
			$gateway->set_log_handler( 'brijpay_webhook_sync' );
			$gateway->push_to_webhooks( $order, $datafeed );
		}
	}

	public function send_order_to_webhook( $order_id, $old_status, $new_status ) {
		$order        = wc_get_order( $order_id );
		$send_order   = get_option( 'brijpay_cloud_send_order' );
		$order_status = get_option( 'brijpay_cloud_order_status' );
		$order_status = str_replace( 'wc-', '', $order_status );
		$success      = $order->is_paid();
		$webhook_sent = get_post_meta( $order_id, 'brijpay_webhook_sent', true );
		$ContractId   = get_post_meta( $order_id, 'ContractId', true );
		$ClientContractId   = get_post_meta( $order_id, 'ClientContractId', true );
		$Original_Order   = get_post_meta( $order_id, 'Original_Order', true );
		
		$order_data   = [
			'datafeed'       => [],
			'transaction_id' => $order_id,
			'ContractId'     => $ContractId,
			'ClientContractId' => $ClientContractId,
			'success'        => $success,
			'error_code'     => false,
			'error_message'  => '',
		];

		if ( ! empty( $webhook_sent ) ) {
			return;
		}

		if ( $order && 'yes' === $send_order && $new_status === $order_status ) {
			$gateway = new Brijpay_Gateway();
			$gateway->set_log_handler( 'brijpay_webhook' );
			$gateway->push_to_webhooks( $order, $order_data );
		} elseif(!empty($Original_Order)){
			delete_post_meta( $order->get_id(), 'brijpay_webhook_brijpay_pending' );
		} else {
			$datafeed = get_post_meta( $order_id, 'brijpay_webhook_datafeed', true );
			if ( empty( $datafeed ) ) {
				update_post_meta( $order_id, 'brijpay_webhook_brijpay_pending', 1 );
				update_post_meta( $order_id, 'brijpay_webhook_datafeed', $order_data );
			}
		}
	}
}
