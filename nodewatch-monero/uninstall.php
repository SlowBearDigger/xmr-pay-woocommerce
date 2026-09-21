<?php
// Remove plugin settings and scheduled tasks on uninstall.


if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'woocommerce_xmrpay_settings' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time uninstall sweep; prefixes are literal, no user input.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'xmrpay_%'
	    OR option_name LIKE '_transient_xmrpay_%'
	    OR option_name LIKE '_transient_timeout_xmrpay_%'"
);
