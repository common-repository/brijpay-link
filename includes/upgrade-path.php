<?php

global $wpdb;

if ( ! defined( 'BRIJPAY_LINK_VERSION' ) ) {
	exit;
}

if ( wp_doing_ajax() ) {
	return;
}

$current_saved_version = get_option( 'brijpay_link_version' );

if ( version_compare( BRIJPAY_LINK_VERSION, $current_saved_version, '>' ) ) {
	update_option( 'brijpay_link_version', BRIJPAY_LINK_VERSION );
	update_option( 'brijpay_link_old_version', $current_saved_version );
	update_option( 'brijpay_link_upgrade_notes_on', 'yes' );
}

$upgrade_notes = get_option( 'brijpay_link_upgrade_notes_on' );

if ( 'yes' === $upgrade_notes ) {
	add_action(
		'admin_notices',
		function () {
			$old_version = get_option( 'brijpay_link_old_version' );

			$changelog = <<<HTML
<p>The following is a list of changes in this version</p>
<p>
&bull; New: Introduced Mindbody affiliate order processing, recreates already processed orders from the backend<br>
</p>

<p>See the complete list of previous <a target="_blank" href="https://wordpress.org/plugins/brijpay-link/#developers">changelogs</a></p>
<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>
<script>
(function() {
    const \$dismissalBtn = document.querySelector('#brijpay-link-upgrade-notes .notice-dismiss');
    \$dismissalBtn.addEventListener('click', function() {
        const separator = location.search ? '&' : '?';
        const { href } = location;
        location.replace(`\${href}\${separator}brijpay-upgrade-notice=dismissed`);
    })
})();
</script>
HTML;

			printf(
				'<div class="notice notice-info is-dismissible" id="brijpay-link-upgrade-notes"><p>%1$s</p>%2$s</div>',
				sprintf(
					__( '%s has been upgraded from v%s to v%s', 'brijpay-link' ),
					'<strong>Brijpay Link</strong>',
					$old_version,
					BRIJPAY_LINK_VERSION
				),
				$changelog
			);
		}
	);
}

/**
 * v2.0.0 upgrade
 *
 * @since 2.0.0
 */
if ( ! $current_saved_version || version_compare( BRIJPAY_LINK_VERSION, '2.0.0', '<' ) ) {
	/**
	 * Remove "brijpay_mb_sync" cron
	 */
	$wpdb->delete(
		"{$wpdb->prefix}actionscheduler_actions",
		[ 'hook' => 'brijpay_mb_sync', 'status' => 'pending' ]
	);

	$url = get_option( 'brijpay_webhook_url' );
	if ( $url ) {
		update_option( 'brijpay_cloud_url', $url );
	}

	$store = get_option( 'brijpay_webhook_store' );
	if ( $store ) {
		update_option( 'brijpay_cloud_store', $store );
	}

	$token = get_option( 'brijpay_webhook_token' );
	if ( $token ) {
		update_option( 'brijpay_cloud_token', $token );
	}

	$send_order = get_option( '_cloud_send_order' );
	if ( $send_order ) {
		update_option( '_cloud_send_order', $send_order );
	}

	$order_status = get_option( '_cloud_order_status' );
	if ( $order_status ) {
		update_option( '_cloud_order_status', $order_status );
	}

	$product_status = get_option( 'brijpay_mindbody_product_status' );
	if ( $product_status ) {
		update_option( 'brijpay_product_status', $product_status );
	}

	$stock_disable = get_option( 'brijpay_mindbody_product_stock_disable' );
	if ( $stock_disable ) {
		update_option( 'brijpay_product_stock_disable', $stock_disable );
	}

	$post_duplication = get_option( 'brijpay_wpml_post_duplication' );
	if ( $post_duplication ) {
		update_option( 'brijpay_product_wpml_disable_post_duplication', $post_duplication );
	}

	$user_sync_enable = get_option( 'brijpay_mindbody_user_sync_enable' );
	if ( $user_sync_enable ) {
		update_option( 'brijpay_user_sync_enable', $user_sync_enable );
	}

	$user_disable_notification = get_option( 'brijpay_mindbody_user_disable_notification' );
	if ( $user_disable_notification ) {
		update_option( 'brijpay_user_disable_notification', $user_disable_notification );
	}
}

/**
 * v2.4.0 upgrade
 *
 * @since 2.4.0
 */
if ( version_compare( $current_saved_version, '2.4.0', '<' ) ) {
	Brijpay_Link::create_upload_dir();

	$wpdb->delete(
		"{$wpdb->prefix}actionscheduler_actions",
		[ 'hook' => 'brijpay_cloud_sync', 'status' => 'pending' ]
	);
}
