<?php
/**
 * Plugin Name:       Brijpay Link
 * Description:       WooCommerce Inventory Sync with Mindbody with several tokenized payment integrations.
 * Version:           2.4.8
 * Requires at least: 5.0
 * Requires PHP:      7.0
 * Author:            Brijpay
 * Author URI:        https://brijpay.com
 * License:           GPLv3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       brijpay-link
 * Domain Path:       /languages
 * Plugin URI:        https://wordpress.org/plugins/brijpay-link/
 *
 * WC requires at least: 5.0.0
 * WC tested up to: 7.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BRIJPAY_LINK_VERSION = '2.4.8';

class Brijpay_Link {
	/**
	 * The single instance of the queue.
	 *
	 * @var Brijpay_Link|null
	 */
	protected static $instance = null;

	/**
	 * @var Brijpay_Settings|null
	 */
	public $settings;

	/**
	 * @var Brijpay_Cloud_Sync|null
	 */
	public $cloud_sync;

	/**
	 * @var Brijpay_Gateway_IPNS|null
	 */
	public $gateway_ipns;

	/**
	 * @var Brijpay_User_Sync|null
	 */
	public $user_sync;

	/**
	 * @var Brijpay_Orders_Sync|null
	 */
	public $orders_sync;

	/**
	 * Single instance
	 *
	 * @return Brijpay_Link
	 */
	final public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new Brijpay_Link();
		}

		return self::$instance;
	}

	private function __construct() {
		if ( is_admin() ) {
			include_once 'includes/upgrade-path.php';
		}

		// Register translations
		load_plugin_textdomain( 'brijpay-link', false, basename( dirname( __FILE__ ) ) . '/languages/' );

		define( 'BRIJPAY_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ) );
		define( 'BRIJPAY_URL', plugin_dir_url( __FILE__ ) );
		define( 'BRIJPAY_RETRY_TIME', 20 );

		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . '/brijpay-link';
		define( 'BRIJPAY_UPLOAD_DIR', $dir );

		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'settings_link' ], 10, 1 );
		add_filter( 'display_post_states', [ $this, 'mindbody_product_states' ], 10, 2 );

		add_action( 'brijpay_cloud_product_sync_completed', [ $this, 'wpml_duplicate_post' ] );
		add_action( 'edit_form_top', [ $this, 'edit_form_top' ] );

		add_action( 'admin_footer', [ $this, 'admin_footer' ] );
		add_action( 'admin_init', [ $this, 'notice_dismissal' ] );

		// load classes
		require_once 'includes/functions.php';
		require_once 'includes/admin/class-brijpay-settings.php';
		require_once 'includes/gateway/class-brijpay-gateway-paydollar.php';
		require_once 'includes/class-brijpay-cloud-sync.php';
		require_once 'includes/class-brijpay-gateway-ipns.php';
		require_once 'includes/class-brijpay-user-sync.php';
		require_once 'includes/class-brijpay-orders-sync.php';

		$this->settings     = Brijpay_Settings::instance();
		$this->cloud_sync   = Brijpay_Cloud_Sync::instance();
		$this->gateway_ipns = Brijpay_Gateway_IPNS::instance();
		$this->user_sync    = Brijpay_User_Sync::instance();
		$this->orders_sync   = Brijpay_Orders_Sync::instance();

		return $this;
	}

	public function settings_link( $links ) {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=brijpay' ) . '">' . __( 'Settings', 'brijpay-link' ) . '</a>';
		array_unshift( $links, $settings_link );

		return $links;
	}

	public function mindbody_product_states( $post_states, $post ) {
		if ( get_post_meta( $post->ID, '_mb_sale_id', true ) ) {
			$post_states['brijpay_mindbody'] = __( 'Mindbody', 'brijpay-link' );
		}

		if ( get_post_meta( $post->ID, '_mb_sale_inactive', true ) ) {
			$post_states['brijpay_mindbody_status'] = __( 'Deactivated', 'brijpay-link' );
		}

		return $post_states;
	}

	/**
	 * @param int $product_id
	 */
	public function wpml_duplicate_post( $product_id ) {
		if ( 'yes' === get_option( 'brijpay_product_wpml_disable_post_duplication' ) ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$has_translations = apply_filters( 'wpml_element_has_translations', '', $product_id, 'product' );
		if ( ! $has_translations ) {
			do_action( 'wpml_admin_make_post_duplicates', $product_id );
		}
	}

	/**
	 * Make product fields read-only
	 *
	 * @param WP_Post $post
	 */
	public function edit_form_top( $post ) {
		if ( 'product' !== $post->post_type ) {
			return;
		}

		if ( ! get_post_meta( $post->ID, '_mb_sale_id', true ) ) {
			return;
		}

		$is_package = 'Packages' === get_post_meta( $post->ID, '_mb_sale_group', true );

		echo '<div class="mb-product-meta" style="margin: 10px 0;">';

		echo <<<STYLE
<style>
.mb-product-meta {
    margin: 10px 0;
}
.mb-product-meta .order-status {
    margin-right: 5px;
}
.mb-product-meta .order-status:last-child {
    margin-right: 0;
}
</style>
<script>
    jQuery( document ).ready(() => {
        const isPackage = Boolean({$is_package})

        jQuery('#titlewrap #title').prop('readonly', true);
        jQuery('#_regular_price').prop('readonly', true);
        if (isPackage) {
        	jQuery('#_sale_price').prop('readonly', true);
        }
        jQuery('#_sku').prop('readonly', true);

        // Subscription fields
        jQuery('#_subscription_price, #_subscription_sign_up_fee, #_subscription_trial_length')
            .prop('readonly', true);

        const selectElements = [
            '_subscription_period_interval',
        	'_subscription_period',
        	'_subscription_length',
        	'_subscription_trial_period',
        ];

        selectElements.forEach(el => {
        	jQuery(`#\${el} option:not(:selected)`).prop('disabled', true);    
        });

        const intervalId = window.setInterval(variation_fields, 2000);

        function variation_fields() {
			const inputs = jQuery("div.variable_pricing input");

			if (inputs.length) {
				jQuery(inputs).each((i, obj) => {
				    jQuery('.variable_sku'+i+'_field input').prop('readonly', true);
				    if (isPackage) {
				    	jQuery(obj).attr('readonly', true);
				    } else {
				        jQuery(obj).not('[name^="variable_sale_price"]').attr('readonly', true);
				    }
				});

				jQuery('[name^="variable_subscription_price"], [name^="variable_subscription_period_interval"], [name^="variable_subscription_period"], [name^="variable_subscription_trial_length"], [name^="variable_subscription_sign_up_fee"]')
				    .prop('readonly', true);

				clearInterval(intervalId);
			}
        }
    });
</script>
STYLE;

		printf(
			'<mark class="order-status status-completed"><span>%s</span></mark>',
			__( 'Mindbody', 'brijpay-link' )
		);

		if ( get_post_meta( $post->ID, '_mb_sale_inactive', true ) ) {
			printf(
				'<mark class="order-status status-trash"><span>%s</span></mark>',
				__( 'Deactivated', 'brijpay-link' )
			);
		}

		echo '</div>';
	}

	/**
	 * Make Quick edit fields read-only
	 */
	public function admin_footer() {
		$post_type = filter_input( INPUT_GET, 'post_type' );

		if ( 'product' !== $post_type ) {
			return;
		}

		echo <<<SCRIPT
<script>
(function() {
    'use strict';
    
    const target = document.querySelector('table.posts');
    const config = {
        childList: true,
        subtree: true,
        attributes: true
    };        

    const observer = new MutationObserver((mutationRecords, observer) => {
        mutationRecords.forEach(function (mutation) {
            const quickEditEl = target.querySelector('.quick-edit-row');
            if (!quickEditEl) {
                return;
            }

            const rowSelector = jQuery(quickEditEl).attr('id').replace('edit', 'post');
            const postTrEl = document.getElementById(rowSelector);

            jQuery(postTrEl).find('.post-state').each((i, el) => {
                if (jQuery(el).text().indexOf('Mindbody') !== -1) {
                    const tags = jQuery(quickEditEl).find('.tax_input_product_tag').val();
                    if (tags.indexOf('packages') !== -1) {
                    	jQuery(quickEditEl).find('input[name="_sale_price"]').prop('readonly', true);    
                    }

                    jQuery(quickEditEl).find('input.ptitle').prop('readonly', true);
                    jQuery(quickEditEl).find('input[name="post_name"]').prop('readonly', true);
                    jQuery(quickEditEl).find('input[name="_sku"]').prop('readonly', true);
                    jQuery(quickEditEl).find('input[name="_regular_price"]').prop('readonly', true);

                    observer.disconnect();
                }
            });
        });
    });
    
    jQuery('.editinline').on('click', () => observer.observe(target, config));
})();
</script>
SCRIPT;
	}

	public function notice_dismissal() {
		$upgrade_notice = filter_input( INPUT_GET, 'brijpay-upgrade-notice' );
		if ( 'dismissed' === $upgrade_notice ) {
			update_option( 'brijpay_link_upgrade_notes_on', 'no' );
			delete_option( 'brijpay_link_old_version' );
			wp_redirect( remove_query_arg( 'brijpay-upgrade-notice' ) );
			exit;
		}
	}

	public static function create_upload_dir() {
		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . '/brijpay-link';

		if ( ! defined( 'FS_CHMOD_DIR' ) ) {
			define( 'FS_CHMOD_DIR', ( fileperms( ABSPATH ) & 0777 | 0755 ) );
		}

		if ( ! file_exists( $dir ) ) {
			mkdir( $dir, FS_CHMOD_DIR, true );
		}

		file_put_contents( $dir . '/.htaccess', 'deny from all' );
	}
}

/**
 * @return Brijpay_Link|null
 */
function brijpay() {
	$message = brijpay_dependencies_checks();

	// All good
	if ( empty( $message ) ) {
		return Brijpay_Link::instance();
	}

	// Something unmatched
	add_action(
		'admin_notices',
		function () use ( $message ) {
			$class = 'notice notice-error';
			printf( '<div class="%1$s"><p><strong>%2$s:</strong> %3$s</p></div>', esc_attr( $class ), 'Brijpay Link', esc_html( $message ) );
		}
	);

	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$plugin_path = basename( __DIR__ ) . '/' . basename( __FILE__ );
	deactivate_plugins( $plugin_path );

	return null;
}

// Fire up!
add_action( 'plugins_loaded', 'brijpay' );

/**
 * @return string
 */
function brijpay_dependencies_checks() {
	global $wp_version;
	$message = '';

	if ( version_compare( PHP_VERSION, '7.0.0', '<' ) ) {
		$message = sprintf(
			__( 'You need to upgrade your PHP to atleast version 7.0.0. Currently %s is used. To resolve the upgrade please contact your server host.', 'brijpay-link' ),
			PHP_VERSION
		);
	} elseif ( version_compare( $wp_version, '5.0.0', '<' ) ) {
		$message = sprintf(
			__( 'You need to update your WordPress version to atleast version 5.0.0. Current version %s is being used.', 'brijpay-link' ),
			$wp_version
		);
	} elseif ( ! function_exists( 'WC' ) ) {
		$message = __( 'Requires WooCommerce to be installed and activated.', 'brijpay-link' );
	} elseif ( version_compare( WC()->version, '4.3.0', '<' ) ) {
		$message = sprintf(
			__( 'You need to upgrade your WooCommerce to atleast version 4.3.0. Currently %s is used.', 'brijpay-link' ),
			WC()->version
		);
	}

	return $message;
}

/**
 * Activation hook
 */
function brijpay_activation_hook() {
	$message = brijpay_dependencies_checks();

	if ( ! empty( $message ) ) {
		die( sprintf( '<strong>%1$s:</strong> %2$s', 'Brijpay Link', esc_html( $message ) ) );
	}

	update_option( 'brijpay_link_version', BRIJPAY_LINK_VERSION );

	Brijpay_Link::create_upload_dir();
}
register_activation_hook( __FILE__, 'brijpay_activation_hook' );

/**For Compatible with High Performance Order Storage (HPOS)**/
add_action('before_woocommerce_init', function(){
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
});