<?php

/**
 * Brijpay User Sync
 *
 * @version 1.2.0
 */

class Brijpay_User_Sync {
	/**
	 * The single instance of the queue.
	 *
	 * @var Brijpay_User_Sync|null
	 */
	protected static $instance = null;

	/**
	 * @var WC_Logger
	 */
	protected $logger = null;

	/**
	 * @var string
	 */
	protected $handler = 'brijpay_user';

	/**
	 * @var int
	 */
	protected $paged = 0;

	/**
	 * @var int
	 */
	protected $limit = 50;

	/**
	 * @var int
	 */
	protected $counter = 1;

	/**
	 * @var string
	 */
	private $api_url;

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

	private function __construct() {
		$this->limit = absint( get_option( 'brijpay_scheduler_pagination_limit', 50 ) );

		add_action( 'init', [ $this, 'every_day' ] );
		add_filter( 'wp_authenticate_user', [ $this, 'authenticate_user' ], 10, 2 );
	}

	/**
	 * Single instance of WC_Queue_Interface
	 *
	 * @return Brijpay_User_Sync
	 */
	final public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new Brijpay_User_Sync();
		}

		return self::$instance;
	}

	/**
	 * @param WP_User|WP_Error $user
	 * @param string $password
	 *
	 * @return WP_User|WP_Error
	 */
	public function authenticate_user( $user, $password ) {
		if ( $user instanceof WP_User ) {
			$is_active = get_user_meta( $user->ID, 'brijpay_client_active', true );
			if ( '0' === $is_active ) {
				$user = new WP_Error( 'brijpay_inactive', __( 'Your account is deactivated. Please contact site administrator.', 'brijpay-link' ) );
			}
		}

		return $user;
	}

	public function every_day() {
		$enable        = get_option( 'brijpay_user_sync_enable' );
		$cloud_token   = get_option( 'brijpay_cloud_token' );
		$user_endpoint = get_option( 'brijpay_user_endpoint' );

		if ( 'yes' !== $enable || empty( $cloud_token ) || empty( $user_endpoint ) ) {
			as_unschedule_all_actions( 'brijpay_user_sync' );

			return;
		}

		if ( false === as_next_scheduled_action( 'brijpay_user_sync' ) ) {
			as_schedule_recurring_action(
				brijpay_scheduler_start_from_interval_type(),
				brijpay_scheduler_configuration_interval_type() * brijpay_scheduler_configuration_interval(),
				'brijpay_user_sync',
				[],
				'brijpay'
			);
		}

		add_action( 'brijpay_user_sync', [ $this, 'sync' ] );
	}

	public function sync() {
		$payment_store_id = get_option( 'brijpay_cloud_store' );
		$this->auth_token = get_option( 'brijpay_cloud_token' );
		$user_endpoint    = get_option( 'brijpay_user_endpoint' );

		if ( empty( $this->auth_token ) || empty( $user_endpoint ) || empty( $payment_store_id ) ) {
			return;
		}

		$this->api_url = rtrim( $user_endpoint, '/' ) . '/' . $payment_store_id;

		do {
			$this->syncWithRetry();
		} while ( $this->api_next_url );
	}

	protected function log( $msg, $level = WC_Log_Levels::NOTICE ) {
		if ( ! $this->logger ) {
			$this->logger = wc_get_logger();
		}

		return $this->logger->add( $this->handler, $msg, $level );
	}

	protected function syncWithRetry() {
		try {
			$this->sync_clients();
		} catch ( Exception $e ) {
			$this->log( sprintf( 'ERROR: %s', $e->getMessage() ), WC_Log_Levels::ERROR );

			if ( $this->retried < 1 ) {
				$this->log( sprintf( __( 'Retrying in %d seconds...', 'brijpay-link' ), BRIJPAY_RETRY_TIME ) );

				sleep( BRIJPAY_RETRY_TIME );
				$this->retried = 1;
				$this->syncWithRetry();
			}
		}
	}

	protected function sync_clients() {
		if ( $this->api_next_url ) {
			$url = $this->api_next_url;
		} else {
			$params = http_build_query( [
				'limit'  => $this->limit,
				'offset' => 0
			] );

			$url = $this->api_url . '?' . $params;
		}

		$req = wp_remote_request(
			$url,
			[
				'method'  => 'GET',
				'headers' => [
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'Token ' . $this->auth_token,
				]
			]
		);

		$this->api_next_url = null;

		$response_code = wp_remote_retrieve_response_code( $req );
		$res           = wp_remote_retrieve_body( $req );

		if ( is_wp_error( $req ) || $response_code >= 400 ) {
			throw new Exception( sprintf( 'RESPONSE CODE: %s, RESPONSE BODY: %s', $response_code, $res ) );
		}

		$this->paged ++;
		$result = json_decode( $res );

		if ( $result->next ) {
			$this->api_next_url = $result->next;
		}

		if ( 1 === $this->paged ) {
			$this->log( sprintf( __( 'Total clients: %d', 'brijpay-link' ), $result->count ) );
			$this->log( '-' );
		}

		foreach ( $result->results as $client ) {
			$this->log( sprintf( __( '#%d) Importing client (%s): %s', 'brijpay-link' ), $this->counter, $client->UserName, $client->Contact_Name ) );
			$this->counter ++;

			$user_id = username_exists( $client->UserName );
			if ( ! $user_id ) {
				$user_id = email_exists( $client->Contact_Email );
			}

			$user_args = [
				'user_email'   => $client->Contact_Email,
				'user_login'   => $client->UserName,
				'display_name' => $client->Contact_Name,
				'first_name'   => $client->Contact_First_Name,
				'last_name'    => $client->Contact_Last_Name,
				'nickname'     => $client->Contact_Name,
			];

			$this->log( sprintf( __( 'Check if user "%s" exists', 'brijpay-link' ), $client->Contact_Name ) );

			if ( ! $user_id ) {
				if ( ! $client->Active ) {
					$this->log( __( 'User does not exist and inactive. Skip..', 'brijpay-link' ), WC_Log_Levels::WARNING );
					continue;
				}

				$this->log( __( 'Creating user', 'brijpay-link' ) );
			} else {
				$this->log( __( 'Updating user', 'brijpay-link' ) );

				$user_args['ID'] = $user_id;
			}

			$user_id = wp_insert_user( $user_args );

			if ( is_wp_error( $user_id ) ) {
				$this->log( sprintf( __( 'Failed! Error (%s): %s', 'brijpay-link' ), $user_id->get_error_code(), $user_id->get_error_message() ), WC_Log_Levels::ERROR );
				continue;
			}

			if ( ! isset( $user_args['ID'] ) || empty( $user_args['ID'] ) ) {
				$disable_notification = get_option( 'brijpay_user_disable_notification' );
				if ( 'yes' !== $disable_notification ) {
					wp_new_user_notification( $user_id, null, 'user' );
					$this->log( sprintf( __( 'User is notified for password reset mail', 'brijpay-link' ), $client->Contact_Name ) );
				}

				$this->log( sprintf( __( 'User created #%d', 'brijpay-link' ), $user_id ) );
			} else {
				$this->log( sprintf( __( 'User updated #%d', 'brijpay-link' ), $user_id ) );
			}

			if ( $client->Contact_Telephone ) {
				update_user_meta( $user_id, 'billing_phone', $client->Contact_Telephone );
			}

			update_user_meta( $user_id, 'brijpay_client_active', $client->Active );

			$this->log( sprintf( __( 'Check if group "%s" exists', 'brijpay-link' ), $client->WordPress_Group ) );

			$group_id = $this->get_create_group( $client->WordPress_Group );

			$this->log( sprintf( __( 'Using group #%d', 'brijpay-link' ), $group_id ) );

			Groups_User_Group::create( [ 'user_id' => $user_id, 'group_id' => $group_id ] );

			$this->log( sprintf( __( 'Assigned to group #%d', 'brijpay-link' ), $group_id ) );
			$this->log( __( 'Successfully imported', 'brijpay-link' ) );
			$this->log( '-' );
		}

		if ( $this->api_next_url ) {
			$this->log( '------------------------------------------------' );
			$this->log( sprintf( __( 'Next Request: %s', 'brijpay-link' ), $this->api_next_url ) );
			$this->log( '------------------------------------------------' );
		}
	}

	/**
	 * @param string $group_name
	 *
	 * @return false|int
	 */
	private function get_create_group( $group_name ) {
		$groups = Groups_Group::get_group_ids( [
			'include_by_name' => $group_name
		] );

		if ( empty( $groups ) ) {
			$this->log( sprintf( __( 'Creating group "%s"', 'brijpay-link' ), $group_name ) );

			$group_id = Groups_Group::create( [
				'name'        => $group_name,
				'description' => sprintf( __( 'Brijpay - %s', 'brijpay-link' ), $group_name ),
				'datetime'    => date( 'Y-m-d H:i:s', time() )
			] );
		} else {
			$group_id = absint( $groups[0] );
		}

		return $group_id;
	}
}
