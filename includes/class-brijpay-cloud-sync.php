<?php

/**
 * Brijpay Cloud Sync
 *
 * @version 2.0.0
 */

class Brijpay_Cloud_Sync {
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
	protected $handler = 'brijpay_cloud';

	/**
	 * @var int
	 */
	protected $paged = 0;

	/**
	 * The single instance of the queue.
	 *
	 * @var Brijpay_Cloud_Sync|null
	 */
	protected static $instance = null;

	/**
	 * Cloud sale item ids
	 *
	 * @var int[]
	 */
	protected $cloud_sale_ids = [];

	/**
	 * @var string
	 */
	private $auth_token;

	/**
	 * @var string|null
	 */
	private $api_next_url = null;

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
	 * @return Brijpay_Cloud_Sync
	 */
	final public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new Brijpay_Cloud_Sync();
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
		add_action( 'brijpay_cloud_product_sync_contracts', [ $this, 'process_sale_contracts' ], 10, 2 );
		add_action( 'brijpay_cloud_iterator', [ $this, 'schedule_iterator' ], 10, 2 );
		add_action( 'brijpay_cloud_completed', [ $this, 'schedule_completed' ], 10, 2 );
	}

	public function every_day() {
		$auth_token       = get_option( 'brijpay_cloud_token' );
		$product_endpoint = get_option( 'brijpay_product_endpoint' );

		if ( empty( $auth_token ) || empty( $product_endpoint ) ) {
			as_unschedule_all_actions( 'brijpay_cloud_sync' );

			return;
		}

		if ( false === as_next_scheduled_action( 'brijpay_cloud_sync' ) ) {
			as_schedule_recurring_action(
				brijpay_scheduler_start_from_interval_type(),
				brijpay_scheduler_configuration_interval_type() * brijpay_scheduler_configuration_interval(),
				'brijpay_cloud_sync',
				[],
				'brijpay'
			);
		}

		add_action( 'brijpay_cloud_sync', [ $this, 'sync' ] );
	}

	public function sync() {
		$this->auth_token = get_option( 'brijpay_cloud_token' );
		$product_endpoint = get_option( 'brijpay_product_endpoint' );

		if ( empty( $this->auth_token ) || empty( $product_endpoint ) ) {
			return;
		}

		$this->api_url = rtrim( $product_endpoint, '/' ) . '/';

		$this->sync_with_retry();
	}

	protected function sync_with_retry() {
		try {
			$this->sync_products();
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

	protected function sync_products() {
		$args = [];

		if ( $this->is_product_contracts_as_normal_enable() || class_exists( 'WC_Subscriptions' ) ) {
			$args['contracts'] = 'true';
		}

		$query_params = http_build_query( $args );
		$url          = $this->api_url . '?' . $query_params;

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

		$result = json_decode( $res );

		$result_set = [];
		foreach ( $result as $sale ) {
			$sale_locations = $sale->Store_Locations;
			$sale_id        = $sale->Product_Id;

			if ( ! $this->is_product_location_enable() ) {
				$result_set[] = $sale;
				continue;
			}

			foreach ( $sale_locations as $locations_obj ) {
				$location_id                = $locations_obj->Id;
				$_sale                      = clone $sale;
				$_sale->Location_Id         = $location_id;
				$_sale->Location_Product_Id = ( ! empty( $location_id ) ) ? ( $sale_id . "_" . $location_id ) : $sale_id;
				$_sale->Location_Name       = $locations_obj->Name;

				// Unused data values, strip out..
				unset( $_sale->Store_Locations, $_sale->Locations );

				$result_set[] = $_sale;
			}
		}

		if ( empty( $result_set ) ) {
			throw new Exception( sprintf( 'Results are empty: %s', $res ) );
		}

		$this->log( sprintf( __( 'Total products: %d', 'brijpay-link' ), count( $result_set ) ) );
		if ( $this->is_product_location_enable() ) {
			$this->log( __( 'Multi location detected', 'brijpay-link' ) );
		}
		$this->log( __( 'Import process being started', 'brijpay-link' ) );
		$this->log( '' );

		$this->filesystem->put_contents(
			$this->cloud_file,
			json_encode( $result_set ),
			FS_CHMOD_FILE
		);

		as_enqueue_async_action( 'brijpay_cloud_iterator', [ 'offset' => 0, 'counter' => 1 ], 'brijpay' );
	}

	/**
	 * @param int $offset
	 * @param int $counter
	 *
	 * @return void
	 */
	public function schedule_iterator( $offset, $counter ) {
		$results = json_decode( file_get_contents( $this->cloud_file ) );

		if ( empty( $results ) ) {
			return;
		}

		foreach ( array_slice( $results, $offset, $this->limit ) as $sale ) {
			$sale_id       = $sale->Location_Product_Id ?? $sale->Product_Id;
			$sale_name     = $sale->Name;
			$sale_type     = $sale->Type;
			$location_name = $sale->Location_Name ?? '';
			$location_id   = $sale->Location_Id ?? null;

			$this->log( sprintf(
				__( '#%d) Importing item (SKU: %s): TYPE: %s | Name: %s', 'brijpay-link' ),
				$counter,
				$sale_id,
				$sale_type,
				$sale_name
			) );

			$counter ++;

			$product_id = $this->import_sale_item( $sale, $location_id );

			if ( $product_id ) {
				$this->assign_category( $sale, $product_id, $location_name );

				$this->log(
					sprintf(
						__( 'Successfully imported (ID: %d)%s', 'brijpay-link' ),
						$product_id,
						$location_id ? sprintf( __( ' on Location (ID: %d)', 'brijapy-link' ), $location_id ) : ''
					)
				);

				/**
				 * @param int $product_id
				 *
				 * @since 2.0.0
				 */
				do_action( 'brijpay_cloud_product_sync_completed', $product_id );

			} else {
				$this->log( __( 'Failed to import', 'brijpay-link' ) );
			}

			$this->log( '' );
		}

		$offset += $this->limit;

		if ( $counter >= count( $results ) ) {
			$this->log( __( 'Import process completed', 'brijpay-link' ) );

			$this->filesystem->delete( $this->cloud_file );

			as_enqueue_async_action( 'brijpay_cloud_completed', [], 'brijpay' );

			return;
		}

		$this->log( '------------------------------------------------' );
		$this->log( sprintf( __( 'Next request offset: %d', 'brijpay-link' ), $offset ) );
		$this->log( '------------------------------------------------' );

		as_enqueue_async_action( 'brijpay_cloud_iterator', [
			'offset'  => $offset,
			'counter' => $counter
		], 'brijpay' );
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

	/**
	 * @param string $type
	 *
	 * @return array|mixed
	 */
	protected function product_types( $type = '' ) {
		$types = [
			'SR' => __( 'Services', 'brijpay-link' ),
			'PG' => __( 'Packages', 'brijpay-link' ),
			'PD' => __( 'Products', 'brijpay-link' ),
			'CT' => __( 'Contracts', 'brijpay-link' ),
		];

		if ( empty( $type ) || ! array_key_exists( $type, $types ) ) {
			return $types;
		}

		return $types[ $type ];
	}

	/**
	 * @param object $sale
	 * @param null|int $location_id
	 *
	 * @return int
	 */
	protected function import_sale_item( $sale, $location_id = null ) {
		$sale_id          = $sale->Product_Id;
		$sale_existing_id = $sale->Location_Product_Id ?? $sale_id;
		$sale_name        = $sale->Name;
		$sale_type        = $sale->Type;
		$group            = $this->product_types( $sale_type );
		$lgroup           = strtolower( $group );

		$wc_product_id = $this->check_existing_cloud_product( $sale_existing_id );
		$new_product   = ! $wc_product_id;

		$product = wc_get_product( $wc_product_id );

		$this->log( __( 'Check if product exists', 'brijpay-link' ) );

		if ( ! is_a( $product, 'WC_Product' ) || ! $product->get_id() ) {
			$product = new WC_Product();
			$product->set_status( $this->get_product_default_status() );

			$this->log( __( 'Create new product...', 'brijpay-link' ) );
		} else {
			$this->log( sprintf( __( 'Exists (ID: %d). Updating the product...', 'brijpay-link' ), $product->get_id() ) );
		}

		if ( 'trash' === $product->get_status() ) {
			$this->log( __( 'Product was in trash. Restoring to the default product status.', 'brijpay-link' ) );
			$product->set_status( $this->get_product_default_status() );
		}

		$product->set_name( html_entity_decode( $sale_name ) );

		$product->set_regular_price( $sale->Price );

		// For new products
		if ( $new_product ) {
			$product->set_total_sales( 0 );
			$product->set_manage_stock( false );
			$product->set_stock_status();
			$product->set_sku( $sale_existing_id );
		} elseif ( ! $this->is_product_stock_disabled() ) {
			$product->set_manage_stock( false );
			$product->set_stock_status();
		}

		file_put_contents( $this->cloud_ids_file, $sale_existing_id . "\n", FILE_APPEND | LOCK_EX );

		$product->update_meta_data( '_mb_sale_id', $sale_existing_id );
		$product->update_meta_data( '_mb_sale_group', $group );
		$product->update_meta_data( '_mb_sale_res', json_encode( $sale ) );
		$product->update_meta_data( '_mb_sale_inactive', false );

		if ( ! empty( $location_id ) ) {
			$product->update_meta_data( '_mb_location_id', $location_id );
		}

		$product_id = $product->save();

		wp_set_object_terms( $product_id, $lgroup, 'product_tag' );

		/**
		 * @param WC_Product $product
		 * @param object $sale
		 *
		 * @since 2.0.0
		 */
		do_action( 'brijpay_cloud_product_sync_' . $lgroup, $product, $sale );

		/**
		 * @param WC_Product $product
		 * @param object $sale
		 *
		 * @since 2.0.0
		 */
		do_action( 'brijpay_cloud_product_sync', $product, $sale );

		return $product_id;
	}

	/**
	 * @param WC_Product $product
	 * @param object $sale
	 *
	 * @since 2.0.0
	 */
	public function process_sale_contracts( $product, $sale ) {
		$contract = $sale->Contract_Details;

		if ( $this->is_product_contracts_as_normal_enable() || $contract->Autopay_Enabled !== true ) {
			$product = new WC_Product_Simple( $product );

			$total_amount = floatval( $contract->Total_Contract_Amount_Total );

			if ( $this->is_product_contracts_recurring_amount() && class_exists( 'Brijpay_Mindbody_Payment_Gateway' ) ) {
				$total_amount = floatval( $contract->Recurring_Payment_Amount_Total );
			}

			$product->set_regular_price( $total_amount );
			$product->save();

			return;
		}

		$product = new WC_Product_Subscription( $product );

		$recurring_amount_total = floatval( $contract->Recurring_Payment_Amount_Total );
		$first_amount_total     = floatval( $contract->First_Payment_Amount_Total );

		$product->set_regular_price( $recurring_amount_total );
		$product->save();

		$signup_fee = $first_amount_total - $recurring_amount_total;
		$signup_fee = $signup_fee > 0 ? $signup_fee : '';

		$possible_units = [ 'M' => 'month', 'Y' => 'year' ];

		wp_set_object_terms( $product->get_id(), 'subscription', 'product_type' );

		switch ( $contract->Frequency_Type ) {
			case 'SetNumberOfAutopays':

				$time_unit = $contract->Frequency_Time_Unit;

				if ( array_key_exists( $time_unit, $possible_units ) ) {
					$frequency_unit = $possible_units[ $time_unit ];
					update_post_meta( $product->get_id(), '_subscription_period', $frequency_unit );
				}

				$frequency_value = absint( $contract->Frequency_Value );
				$frequency_value = $frequency_value ?: 1;
				$no_autopays     = $contract->Number_Of_Autopays;

				$expire_after = $no_autopays * $frequency_value;

				update_post_meta( $product->get_id(), '_subscription_period_interval', $frequency_value );
				update_post_meta( $product->get_id(), '_subscription_length', $expire_after );

				break;

			case 'MonthToMonth':

				$frequency_value = 1;
				$frequency_unit  = $possible_units['M'];
				$expire_after    = 0;

				update_post_meta( $product->get_id(), '_subscription_period_interval', $frequency_value );
				update_post_meta( $product->get_id(), '_subscription_period', $frequency_unit );
				update_post_meta( $product->get_id(), '_subscription_length', $expire_after );

				break;
		}

		update_post_meta( $product->get_id(), '_subscription_price', $recurring_amount_total );
		update_post_meta( $product->get_id(), '_subscription_sign_up_fee', $signup_fee );
	}

	protected function get_product_default_status() {
		return get_option( 'brijpay_product_status', 'draft' );
	}

	protected function is_product_stock_disabled() {
		return 'yes' === get_option( 'brijpay_product_stock_disable', 'no' );
	}

	protected function is_product_location_enable() {
		return 'yes' === get_option( 'brijpay_product_is_location_enable', 'no' );
	}

	protected function is_product_contracts_as_normal_enable() {
		return 'yes' === get_option( 'brijpay_product_contract_as_normal', 'no' );
	}

	protected function is_product_contracts_recurring_amount() {
		return 'yes' === get_option( 'brijpay_product_contracts_recurring_amount', 'no' );
	}

	/**
	 * This method is useful after all the data
	 * from brijpay cloud has been fetched and imported
	 * including and after fully paginated
	 */
	protected function after_processed() {
		$this->product_deactivation();

		$this->filesystem->delete( $this->cloud_ids_file );

		/**
		 * @param Brijpay_Cloud_Sync::$cloud_sale_ids
		 *
		 * @since 2.0.0
		 */
		do_action( 'brijpay_cloud_import_completed' );
	}

	/**
	 * Deactivates product which are no longer
	 * available on brijpay cloud response
	 */
	protected function product_deactivation() {
		$cloud_sale_ids = [];

		$file_handle = fopen( $this->cloud_ids_file, "r" );
		while ( ! feof( $file_handle ) ) {
			$sale_id = fgets( $file_handle );
			$sale_id = trim( $sale_id );

			$cloud_sale_ids[] = $sale_id;
		}
		fclose( $file_handle );

		$cloud_sale_ids = array_unique( array_filter( $cloud_sale_ids ) );

		if ( empty( $cloud_sale_ids ) ) {
			return;
		}

		$is_draft_status = get_option( 'brijpay_product_is_draft_status' );

		$args  = [
			'post_type'      => 'product',
			'post_status'    => 'any',
			'meta_query'     => [
				[
					'key'     => '_mb_sale_id',
					'compare' => 'EXISTS'
				]
			],
			'posts_per_page' => - 1,
		];
		$query = new WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return;
		}

		$deactivated_products = [];

		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );

			if ( ! $product ) {
				continue;
			}

			$mb_sale_id        = $product->get_meta( '_mb_sale_id' );
			$mb_product_exists = in_array( $mb_sale_id, $cloud_sale_ids );

			if ( $mb_product_exists ) {
				continue;
			}

			if ( 'yes' === $is_draft_status ) {
				$product->set_status( 'draft' );
			}

			$deactivated_products[] = $product;

			$product->update_meta_data( '_mb_sale_inactive', true );
			$product->save();
		}

		if ( empty( $deactivated_products ) ) {
			return;
		}

		$emails = get_option( 'brijpay_product_deactivate_report_emails' );
		$emails = explode( ',', $emails );
		$emails = array_filter( array_map( 'trim', $emails ) );

		if ( empty( $emails ) ) {
			return;
		}

		$this->log( __( 'Sending email report for deactivated products...', 'brijpay-link' ) );
		$this->log( sprintf( __( 'Found %d deactivated products', 'brijpay-link' ), count( $deactivated_products ) ) );

		$site_title = get_option( 'blogname' );
		$subject    = sprintf( __( '[BRIJPAY Link] List of deactivated products found on %s' ), $site_title );
		$message    = sprintf(
			__( "The following is a list of deactivated products ran during the BRIJPAY Cloud synchronization at %s\r\n\r\n", 'brijpay-link' ),
			current_time( 'mysql' )
		);
		$counter    = 1;

		foreach ( $deactivated_products as $product ) {
			$message .= sprintf(
				__( "%d) ID: %s | SKU: %s | Name: %s \r\n", 'brijpay-link' ),
				$counter,
				$product->get_id(),
				$product->get_sku(),
				$product->get_title()
			);
			$counter ++;
		}

		if ( wp_mail( $emails, $subject, $message ) ) {
			$this->log( __( 'Successfully sent', 'brijpay-link' ) );
		} else {
			$this->log( __( 'Unable to send the report', 'brijpay-link' ), WC_Log_Levels::ERROR );
		}
	}

	/**
	 * Slightly modified version of wc_get_product_id_by_sku()
	 *
	 * @param $sku string
	 *
	 * @return int
	 * @see WC_Product_Data_Store_CPT::get_product_id_by_sku()
	 *
	 * @since 2.0.2
	 */
	protected function check_existing_cloud_product( $sku ) {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT posts.ID
				FROM {$wpdb->posts} as posts
				INNER JOIN {$wpdb->wc_product_meta_lookup} AS lookup ON posts.ID = lookup.product_id
				WHERE
				posts.post_type IN ( 'product', 'product_variation' )
				AND lookup.sku = %s
				LIMIT 1
				",
				$sku
			)
		);

		return $id;
	}

	/**
	 * Assign revenue category
	 *
	 * @param int $product_id
	 * @param stdClass $sale
	 * @param string $location_name
	 */
	protected function assign_category( $sale, $product_id, $location_name = "" ) {
		if ( ! empty( $location_name ) ) {
			$cat_name  = html_entity_decode( $location_name );
			$set_terms = wp_set_object_terms( $product_id, [ $cat_name ], 'product_cat', true );

			if ( ! is_wp_error( $set_terms ) ) {
				$this->log( sprintf( __( 'Assigned Location Category: %s', 'brijpay-link' ), $cat_name ) );
			}
		}

		$assign_revenue_category = get_option( 'brijpay_product_append_revenue_category' );
		if ( 'yes' !== $assign_revenue_category ) {
			return;
		}

		$revenue_cat = $sale->Revenue_Category;
		if ( empty( $revenue_cat ) ) {
			return;
		}

		$cat_name  = html_entity_decode( $revenue_cat );
		$set_terms = wp_set_object_terms( $product_id, [ $cat_name ], 'product_cat', true );

		if ( ! is_wp_error( $set_terms ) ) {
			$this->log( sprintf( __( 'Assigned Category: %s', 'brijpay-link' ), $cat_name ) );
		}
	}
}
