<?php

/**
 * Brijpay Orders Sync
 *
 * @version 2.0.0
 */

class Brijpay_Orders_Sync {
	/**
	 * @var string
	 */
	protected $api_url = '';

	/**
	 * @var int
	 */
	protected $limit = 50;

	/**
	 * @var WC_Logger|null
	 */
	protected $logger = null;

	/**
	 * @var string
	 */
	protected $handler = 'brijpay_orders';

	/**
	 * The single instance of the queue.
	 *
	 * @var Brijpay_Orders_Sync|null
	 */
	protected static $instance = null;

	/**
	 * @var string
	 */
	private $auth_token;

	/**
	 * @var int
	 */
	private $retried = 0;

	/**
	 * @var WP_Filesystem_Base
	 */
	private $filesystem;

	/**
	 * @var string
	 */
	private $cloud_file;

	/**
	 * @var string
	 */
	private $cloud_ids_file;

	/**
	 * Single instance of WC_Queue_Interface
	 *
	 * @return Brijpay_Orders_Sync
	 */
	final public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new Brijpay_Orders_Sync();
		}

		return self::$instance;
	}

	private function __construct() {
		/**
		 * @var WP_Filesystem_Base $wp_filesystem
		 */
		global $wp_filesystem;

		require_once ABSPATH . '/wp-admin/includes/file.php';

		if ( is_null( $wp_filesystem ) ) {
			WP_Filesystem();
		}

		$this->filesystem     = $wp_filesystem;
		$this->cloud_file     = BRIJPAY_UPLOAD_DIR . '/cloud.json';
		$this->cloud_ids_file = BRIJPAY_UPLOAD_DIR . '/cloud_ids.json';

		$this->limit = absint( get_option( 'brijpay_scheduler_pagination_limit', 50 ) );

		add_action( 'init', [ $this, 'every_day' ] );
		add_action( 'brijpay_cloud_completed', [ $this, 'schedule_completed' ], 10, 2 );
	}

	public function every_day() {
		$auth_token       = get_option( 'brijpay_cloud_token' );
		$auth_store  = get_option( 'brijpay_cloud_store' );
		$orders_endpoint = get_option( 'brijpay_orders_endpoint' );

		if ( empty( $auth_token ) || empty( $auth_store ) || empty( $orders_endpoint ) ) {
			as_unschedule_all_actions( 'brijpay_orders_sync' );

			return;
		}

		if ( false === as_next_scheduled_action( 'brijpay_orders_sync' ) ) {
			as_schedule_recurring_action(
				brijpay_scheduler_start_from_interval_type(),
				brijpay_scheduler_configuration_interval_type() * brijpay_scheduler_configuration_interval(),
				'brijpay_orders_sync',
				[],
				'brijpay'
			);
		}

		add_action( 'brijpay_orders_sync', [ $this, 'sync' ] );
	}

	public function sync() {
		$this->auth_token = get_option( 'brijpay_cloud_token' );
		$this->auth_store  = get_option( 'brijpay_cloud_store' );
		$orders_endpoint = get_option( 'brijpay_orders_endpoint' );

		if ( empty( $this->auth_token ) || empty( $this->auth_store ) || empty( $orders_endpoint ) ) {
			return;
		}

		$this->api_url = rtrim( $orders_endpoint, '/' ) . '/';

		$this->sync_with_retry();
	}

	protected function sync_with_retry() {
		try {
			$this->sync_orders();

		} catch ( Exception $e ) {
			$this->log( sprintf( 'ERROR: %s', $e->getMessage() ), WC_Log_Levels::ERROR );

			if ( $this->retried < 1 ) {
				$this->log( sprintf( __( 'Retrying in %d seconds...', 'brijpay-link' ), BRIJPAY_RETRY_TIME ) );

				sleep( BRIJPAY_RETRY_TIME );
				$this->retried = 1;
				$this->sync_with_retry();
			}
		}
	}

	protected function custom_add_product_to_order($order, $product_id, $total_amt) {
		$args = array(
			'total'        => $total_amt,
			'quantity'     => 1,
		);

		$order->add_product(  wc_get_product( $product_id ) , 1, $args );
		$order->calculate_totals();
	}

	protected function sync_orders() {
		global $product;

		if (empty($this->api_url) || $this->api_url == '') {
			$this->log( __( 'No orders url, nothing to do.', 'brijpay-link' ) );
			return;
		}

		$args = [];
//		$args['readonly'] = 'true';

		$this->auth_token = get_option( 'brijpay_cloud_token' );
		$this->auth_store  = get_option( 'brijpay_cloud_store' );
		
		$query_params = http_build_query( $args );
		$url          = $this->api_url . '' . $this->auth_store .'/'. $this->auth_token . '?' . $query_params;

		$req = wp_remote_request(
			$url,
			[
				'method'  => 'GET',
				'headers' => [
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'Token ' . $this->auth_token,
				],
				'timeout' => 30,
			]
		);

		$response_code = wp_remote_retrieve_response_code( $req );
		$res           = wp_remote_retrieve_body( $req );

		if ( is_wp_error( $req ) || $response_code >= 400 ) {
			throw new Exception( sprintf( 'RESPONSE CODE: %s, RESPONSE BODY: %s', $response_code, $res ) );
		}

		$this->log( sprintf( __( 'Response: %s', 'brijpay-link' ), $res ) );

		$result = json_decode($res, true );

		if(!empty($result)){
			foreach ($result as $rs) {
				$purchaseId = $rs['purchase']['id'];
				$paymentAmount = $rs['purchase']['amount'];
				$product_id = $rs['purchase']['productId'];
				$transactionId = $rs['purchase']['transactionId'];
				$clientContractId = $rs['purchase']['clientContractId'];
				$Original_Order = $rs['purchase']['originalTransactionId'];
				$Original_Order_Date = $rs['purchase']['originalTransactionDate'];

				try {
					$order_match = wc_get_order( $Original_Order );
					if ( $order_match ) {
						$user_id   = $order_match->get_user_id();
						$order_data = $order_match->get_data(); // The Order data
//						$paymentMethod = $order_data['payment_method'];
						$address = $order_data['billing'];
					} else {
						$this->log( sprintf( __( 'Original order not found: %s', 'brijpay-link' ), $Original_Order ) );
						continue;
					}
				} catch ( Exception $e ) {
					$this->log( sprintf( __( 'Error trying to retrieve original order [%s]: %s', 'brijpay-link' ), $Original_Order, $e->getMessage() ) );
					continue;
				}

				//create order
				$order = wc_create_order(array('customer_id' => $user_id));
				$this->custom_add_product_to_order( $order, $product_id, $paymentAmount );
				$order->set_address( $address, 'billing' );

				// add payment method
				$order->set_payment_method( 'brijpay_mindbody_payment_gateway' );
				$order->set_payment_method_title( 'Mindbody Payments Gateway' );
				$order->set_status( 'wc-completed', 'Order created by Mindbody via BRIJPAY Cloud.' );
				update_post_meta($order->id, 'PurchaseId' , $purchaseId);
				update_post_meta($order->id, 'ClientContractId' , $clientContractId);
				update_post_meta($order->id, 'TransactionId' , $transactionId);
				update_post_meta($order->id, 'Original_Order' , $Original_Order);
				update_post_meta($order->id, 'Original_Order_Date' , $Original_Order_Date);
			    $order->calculate_totals();
				$order->save();
			}
		}
	}
	
	public function schedule_completed() {
		$this->after_processed();
	}

	protected function log( $msg, $level = WC_Log_Levels::NOTICE ) {
		if ( ! $this->logger ) {
			$this->logger = wc_get_logger();
		}

		return $this->logger->add( $this->handler, $msg, $level );
	}

}
