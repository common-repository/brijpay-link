<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brijpay_General_Settings extends WC_Settings_Page {
	/**
	 * Constructs the settings page.
	 */
	public function __construct() {

		$this->id    = 'brijpay';
		$this->label = __( 'BRIJPAY', 'brijpay-link' );

		add_filter( 'woocommerce_admin_settings_sanitize_option_brijpay_scheduler_confirm_change', [
			$this,
			'discard_value'
		] );

		parent::__construct();

		add_action( 'woocommerce_admin_field_brijpay_settings_html', [ $this, 'quick_script' ] );
	}

	public function quick_script( $value ) {
		$is_product_contracts_as_normal_enable = 'yes' === get_option( 'brijpay_product_contract_as_normal', 'no' );
		?>
		<script>
            (function() {
                let is_product_contracts_as_normal_enable = <?php echo $is_product_contracts_as_normal_enable === true ? "true" : "false"; ?>;
                const tr = document.querySelector('input[name="brijpay_product_contracts_recurring_amount"]').closest('tr');
                if (is_product_contracts_as_normal_enable) {
	                tr.style.display = "table-row";
                } else {
                    tr.style.display = "none";
                }

                document
	                .querySelector('input[name="brijpay_product_contract_as_normal"]')
	                .addEventListener('change', function() {
                        if (this.checked) {
                            tr.style.display = "table-row";
                        } else {
                            tr.style.display = "none";
                        }
	                });
            })();
		</script>
		<?php
	}

	public function output() {
		add_action( 'woocommerce_admin_field_button.link', [ $this, 'get_connection_html' ] );

		echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">';
		echo '<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>';

		echo '<table class="form-table">';
		parent::output();
		echo '</table>';

		echo <<<SCRIPT
<script>
	(function() {
        flatpickr("#brijpay_scheduler_start_time", {
            enableTime: true,
		    noCalendar: true,
		    dateFormat: "H:i",
        });
	})();
</script>
SCRIPT;


		remove_action( 'woocommerce_admin_field_button.link', [ $this, 'get_connection_html' ] );
	}

	/**
	 * Saves the settings.
	 *
	 * @internal
	 */
	public function save() {
		global $current_section;

		$settings = $this->get_settings();
		WC_Admin_Settings::save_fields( $settings );

		if ( $current_section ) {
			do_action( 'woocommerce_update_options_' . $this->id . '_' . $current_section );
		}
	}

	/**
	 * Gets the settings sections.
	 *
	 * @return array
	 * @internal
	 */
	public function get_sections() {

		$sections = [
			'' => __( 'General', 'brijpay-link' ),
		];

		/**
		 * Filters the WooCommerce Square settings sections.
		 *
		 * @param array $sections settings sections
		 *
		 */
		return (array) apply_filters( 'woocommerce_get_sections_' . $this->get_id(), $sections );
	}

	/**
	 * Gets the settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		global $current_section;

		return (array) apply_filters( 'woocommerce_get_settings_' . $this->get_id(), $this->get_form_fields( $current_section ), $current_section );
	}

	/**
	 * @param string $current_section
	 *
	 * @return array[]
	 */
	public function get_form_fields( $current_section = '' ) {
		global $current_section;
		switch ( $current_section ) {
			default:
				$fields = $this->get_general_fields();
				break;
		}

		return $fields;
	}

	/**
	 * @return array
	 */
	public function get_general_fields() {
		$id = $this->get_id();

		$fields = [
			[
				'title' => __( 'General', 'brijpay-link' ),
				'desc'  => __( 'Configure BRIJPAY Cloud', 'brijpay-link' ),
				'type'  => 'title',
				'id'    => $id . '_general',
			],
			[
				'title' => __( 'Webhook Endpoint', 'brijpay-link' ),
				'type'  => 'url',
				'desc'  => __( 'Enter your provided webhook endpoint', 'brijpay-link' ),
				'id'    => $id . '_cloud_url',
			],
			[
				'title' => __( 'Payment Store ID', 'brijpay-link' ),
				'type'  => 'text',
				'desc'  => __( 'Enter your provided Store ID', 'brijpay-link' ),
				'id'    => $id . '_cloud_store',
			],
			[
				'title' => __( 'Authorization Token', 'brijpay-link' ),
				'type'  => 'text',
				'desc'  => __( 'Enter your provided authorization token', 'brijpay-link' ),
				'id'    => $id . '_cloud_token',
			],
			[
				'title'   => __( 'Send Order Data', 'brijpay-link' ),
				'desc'    => __( 'Send woocommerce order data to BRIJPAY Cloud', 'brijpay-link' ),
				'id'      => $id . '_cloud_send_order',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title'   => __( 'Order Status', 'brijpay-link' ),
				'desc'    => __( 'The status of the woocommerce order when sending to BRIJPAY Cloud', 'brijpay-link' ),
				'id'      => $id . '_cloud_order_status',
				'class'   => 'wc-enhanced-select',
				'css'     => 'min-width:300px;',
				'default' => 'wc-completed',
				'type'    => 'select',
				'options' => wc_get_order_statuses(),
			],
			[
				'type' => 'sectionend',
				'id'   => $id . '_general',
			],
			[
				'title' => __( 'Product Configuration', 'brijpay-link' ),
				'desc'  => __( 'Synchronize products from BRIJPAY Cloud', 'brijpay-link' ),
				'type'  => 'title',
				'id'    => $id . '_product_config',
			],
			[
				'title' => __( 'Product Endpoint', 'brijpay-link' ),
				'type'  => 'url',
				'desc'  => __( 'Enter your provided product endpoint', 'brijpay-link' ),
				'id'    => $id . '_product_endpoint',
			],
			[
				'title'   => __( 'Product Status', 'brijpay-link' ),
				'desc'    => __( 'The default status of the product when syncing NEW BRIJPAY Cloud items in WooCommerce', 'brijpay-link' ),
				'id'      => $id . '_product_status',
				'class'   => 'wc-enhanced-select',
				'css'     => 'min-width:300px;',
				'default' => 'draft',
				'type'    => 'select',
				'options' => get_post_statuses(),
			],
			[
				'title'   => __( 'Disable Product Stock', 'brijpay-link' ),
				'desc'    => __( 'Enable to avoid BRIJPAY Cloud from overriding product stock status', 'brijpay-link' ),
				'id'      => $id . '_product_stock_disable',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title'   => __( 'Assign Revenue Category', 'brijpay-link' ),
				'desc'    => __( 'Enable to append Revenue Category from BRIJPAY Cloud to the products', 'brijpay-link' ),
				'id'      => $id . '_product_append_revenue_category',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title'   => __( 'Deactivate Product as Draft', 'brijpay-link' ),
				'desc'    => __( "Enable to make deactivated products set to 'draft' status (unpublished) during BRIJPAY Cloud sync", 'brijpay-link' ),
				'id'      => $id . '_product_is_draft_status',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title'   => __( 'Enable Product Locations', 'brijpay-link' ),
				'desc'    => __( 'Populate products available on multiple locations', 'brijpay-link' ),
				'id'      => $id . '_product_is_location_enable',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title'   => __( 'Enable Contracts as Normal product', 'brijpay-link' ),
				'desc'    => __( 'Enable to make Sale contracts as normal product. No dependency required for WooCommerce Subscription extension.', 'brijpay-link' ),
				'id'      => $id . '_product_contract_as_normal',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title'   => __( 'Enable Contracts recurring amount', 'brijpay-link' ),
				'desc'    => __( 'Enable to have recurring amount of Sale contracts. Primarily used with the Mindbody Payment Gateway.', 'brijpay-link' ),
				'id'      => $id . '_product_contracts_recurring_amount',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title'   => '',
				'desc'    => '',
				'id'      => $id . '_product_brijpay_settings_html',
				'type'    => 'brijpay_settings_html',
			],
			[
				'title'       => __( 'Email Report for Deactivated Products', 'brijpay-link' ),
				'desc'        => __( 'Sends an email report with a list of products that have been deactivated. (You can add multiple emails by separating them with commas.)', 'brijpay-link' ),
				'id'          => $id . '_product_deactivate_report_emails',
				'type'        => 'text',
				'placeholder' => 'john@example.com, ryan@example.com'
			],
		];

		if ( function_exists( 'icl_object_id' ) ) {
			$fields[] = [
				'title'   => __( 'Disable WPML Duplicates', 'brijpay-link' ),
				'label'   => __( 'Disable WPML Duplicates', 'brijpay-link' ),
				'desc'    => __( 'Select if you want to manually duplicate BRIJPAY Cloud products for other languages supported by WPML', 'brijpay-link' ),
				'id'      => $id . '_product_wpml_disable_post_duplication',
				'type'    => 'checkbox',
				'default' => 'no',
			];
		}

		$fields[] = [
			'type' => 'sectionend',
			'id'   => $id . '_product_config',
		];

		if ( defined( 'GROUPS_FILE' ) ) {
			$fields[] = [
				'title' => __( 'User Configuration', 'brijpay-link' ),
				'desc'  => __( 'Synchronize clients from BRIJPAY Cloud', 'brijpay-link' ),
				'type'  => 'title',
				'id'    => $id . '_user_config',
			];

			$fields[] = [
				'title'   => __( 'Enable', 'brijpay-link' ),
				'label'   => __( 'Enable', 'brijpay-link' ),
				'desc'    => __( 'Enable the synchronization of BRIJPAY Cloud clients and register them in WordPress', 'brijpay-link' ),
				'id'      => $id . '_user_sync_enable',
				'type'    => 'checkbox',
				'default' => 'no',
			];

			$fields[] = [
				'title' => __( 'User Endpoint', 'brijpay-link' ),
				'type'  => 'url',
				'desc'  => __( 'Enter your provided user endpoint', 'brijpay-link' ),
				'id'    => $id . '_user_endpoint',
			];

			$fields[] = [
				'title'   => __( 'User Notification', 'brijpay-link' ),
				'label'   => __( 'Disable', 'brijpay-link' ),
				'desc'    => __( 'Disable password reset email when new users are created', 'brijpay-link' ),
				'id'      => $id . '_user_disable_notification',
				'type'    => 'checkbox',
				'default' => 'no',
			];

			$fields[] = [
				'type' => 'sectionend',
				'id'   => $id . '_user_config',
			];
		}

		$fields[] = [
			'title' => __( 'Scheduler Configuration', 'brijpay-link' ),
			'desc'  => sprintf(
				__( 'Configure intervals and pagination limit for the %s', 'brijpay-link' ),
				sprintf(
					'<a href="%s" target="_blank">%s</a>',
					admin_url( 'admin.php?page=wc-status&tab=action-scheduler&status=pending&s=brijpay_' ),
					__( 'scheduled actions', 'brijpay-link' )
				)
			),
			'type'  => 'title',
			'id'    => $id . '_scheduler_config',
		];

		$fields[] = [
			'title'   => __( 'Interval Type', 'brijpay-link' ),
			'desc'    => __( 'Set the frequency type for the scheduler. Default "Day"', 'brijpay-link' ),
			'id'      => $id . '_scheduler_interval_type',
			'class'   => 'wc-enhanced-select',
			'css'     => 'min-width:300px;',
			'default' => 'day',
			'type'    => 'select',
			'options' => [
				'hour'  => __( 'Hour', 'brijpay-link' ),
				'day'   => __( 'Day', 'brijpay-link' ),
				'week'  => __( 'Week', 'brijpay-link' ),
				'month' => __( 'Month', 'brijpay-link' ),
			],
		];

		$fields[] = [
			'title'       => __( 'Frequency of Interval', 'brijpay-link' ),
			'desc'        => __( 'Set the frequency of the interval selected above. Default 1', 'brijpay-link' ),
			'id'          => $id . '_scheduler_freq_interval',
			'type'        => 'number',
			'placeholder' => '1'
		];

		$fields[] = [
			'title' => __( 'Start time', 'brijpay-link' ),
			'desc'  => __( 'Set the schedulers start time. The default value is the time of plugin activation. This is not compatible with the Interval type Hour', 'brijpay-link' ),
			'id'    => $id . '_scheduler_start_time',
			'type'  => 'text',
		];

		$fields[] = [
			'title'   => __( 'Confirm Changes?', 'brijpay-link' ),
			'desc'    => __( 'Interval changes will only take effect if you check this box. Current scheduled actions will be discarded and replaced with new ones based on your settings.', 'brijpay-link' ),
			'id'      => $id . '_scheduler_confirm_change',
			'type'    => 'checkbox',
			'default' => 'no',
		];

		$fields[] = [
			'title'   => __( 'Pagination Limit', 'brijpay-link' ),
			'desc'    => __( 'Number of per page items retrieved from Brijpay Cloud for products and users sync. Default 50', 'brijpay-link' ),
			'id'      => $id . '_scheduler_pagination_limit',
			'class'   => 'wc-enhanced-select',
			'css'     => 'min-width:300px;',
			'default' => 50,
			'type'    => 'select',
			'options' => [
				10  => 10,
				30  => 30,
				50  => 50,
				100 => 100,
				200 => 200,
			],
		];

		$fields[] = [
			'type' => 'sectionend',
			'id'   => $id . '_scheduler_config',
		];

		return $fields;
	}

	public function discard_value( $value ) {
		if ( ! empty( $value ) ) {
			as_unschedule_all_actions( 'brijpay_cloud_sync' );
			as_unschedule_all_actions( 'brijpay_user_sync' );
		}

		return "";
	}
}

return new Brijpay_General_Settings();
