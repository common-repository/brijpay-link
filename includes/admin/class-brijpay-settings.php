<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brijpay_Settings {
	/**
	 * The single instance of the queue.
	 *
	 * @var Brijpay_Settings|null
	 */
	protected static $instance = null;

	/**
	 * @var Brijpay_General_Settings
	 */
	public $general_settings = null;

	/**
	 * Single instance of WC_Queue_Interface
	 *
	 * @return Brijpay_Settings
	 */
	final public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new Brijpay_Settings();
		}

		return self::$instance;
	}

	private function __construct() {
		add_filter(
			'woocommerce_get_settings_pages',
			function ( $pages ) {
				$pages[] = $this->general_settings = include 'class-brijpay-general-settings.php';

				return $pages;
			},
			19
		);
	}
}
