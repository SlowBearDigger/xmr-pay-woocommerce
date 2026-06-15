<?php
/**
 * Uninstall cleanup. Runs only on plugin DELETE (not deactivate). Removes the
 * gateway settings. Order meta (_xmrpay_*) is left on existing orders on purpose
 * — it is part of the order's payment record and deleting it would rewrite history.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'woocommerce_xmrpay_settings' );

// price-feed transients we may have cached
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_xmrpay_rate_%' OR option_name LIKE '_transient_timeout_xmrpay_rate_%'" );
