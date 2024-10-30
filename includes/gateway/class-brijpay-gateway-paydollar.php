<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brijpay Paydollar tokenization support
 */
class Brijpay_Gateway_Paydollar {

	/**
	 * @var WC_PayDollar
	 */
	private $paydollar_instance = null;

	/**
	 * Construct and initialize the gateway class
	 */
	public function __construct() {
		add_action( 'woocommerce_receipt_paydollar', [ $this, 'receipt_page' ], 9 );
	}

	public function receipt_page( $order ) {
		$payment_gateways         = WC()->payment_gateways()->payment_gateways();
		$this->paydollar_instance = $payment_gateways['paydollar'];
		remove_action( 'woocommerce_receipt_paydollar', [ $this->paydollar_instance, 'receipt_page' ] );

		echo '<p>' . __( 'Thank you for your order. We are now redirecting you to the Payment Gateway to proceed with the payment.' ) . '</p>';
		echo $this->generate_paydollar_form( $order );
	}

	/**
	 * Generate PayDollar button link
	 **/
	public function generate_paydollar_form( $order_id ) {

		global $woocommerce;

		$order = new WC_Order( $order_id );


		if ( $this->paydollar_instance->prefix == '' ) {
			$orderRef = $order_id;
		} else {
			$orderRef = $this->paydollar_instance->prefix . '-' . $order_id;
		}

		$success_url = esc_url( add_query_arg( 'utm_nooverride', '1', $this->paydollar_instance->get_return_url( $order ) ) );
		$fail_url    = esc_url( $order->get_cancel_order_url() );
		$cancel_url  = esc_url( $order->get_cancel_order_url() );


		$secureHash = '';
		if ( $this->paydollar_instance->secure_hash_secret != '' ) {
			$secureHash = $this->paydollar_instance->generatePaymentSecureHash( $this->paydollar_instance->merchant_id, $orderRef, $this->paydollar_instance->curr_code, $order->order_total, $this->paydollar_instance->pay_type, $this->paydollar_instance->secure_hash_secret );
		}

		$remarks = '';

		$paydollar_args = [
			'orderRef'          => $orderRef,
			'amount'            => $order->order_total,
			'merchantId'        => $this->paydollar_instance->merchant_id,
			'payMethod'         => $this->paydollar_instance->pay_method,
			'payType'           => $this->paydollar_instance->pay_type,
			'currCode'          => $this->paydollar_instance->curr_code,
			'lang'              => $this->paydollar_instance->language,
			'successUrl'        => $success_url,
			'failUrl'           => $fail_url,
			'cancelUrl'         => $cancel_url,
			'secureHash'        => $secureHash,
			'remark'            => $remarks,
			'memberPay_service' => 'F', // F for False, T for True
			'mpsMode'           => 'NIL',
		];

		// For instore (staff manager) we enable memberpay service for static token generation
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$paydollar_args['memberPay_service'] = 'T';

			$paydollar_args['memberPay_memberId'] = $order->get_customer_id();
			$customer                             = new WC_Customer( $order->get_customer_id() );
			if ( $customer->get_id() ) {
				$token = $customer->get_meta( 'brijpay_memberpay_token' );
				if ( ! empty( $token ) ) {
					$paydollar_args['memberPay_token'] = $order->get_customer_id();
				}
			}
		}

		$paydollar_args_array = [];
		foreach ( $paydollar_args as $key => $value ) {
			$paydollar_args_array[] = "<input type='hidden' name='$key' value='$value'/>";
		}

		return '<form action="' . $this->paydollar_instance->payment_url . '" method="post" id="paydollar_payment_form">
            	' . implode( '', $paydollar_args_array ) . '
            		</form>
		            <script type="text/javascript">
						jQuery(function(){						
							setTimeout("paydollar_payment_form();", 5000);
	    				});
						function paydollar_payment_form(){
							jQuery("#paydollar_payment_form").submit();
						}
	    			</script>
            ';

	}
}

new Brijpay_Gateway_Paydollar();
