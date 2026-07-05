<?php
/**
 * Uninstall cleanup. Runs only on plugin DELETE (not deactivate). Removes the gateway settings,
 * our own options (the atomic-lock rows xmrpay_lock_* — which, unlike transients, do NOT auto-
 * expire — and the agent network/tested-url hints), and every xmr-pay transient (price-feed
 * cache, scan cooldowns, rate limits, receipt cache). Order meta (_xmrpay_*) is left on existing
 * orders on purpose — it is part of the order's payment record and deleting it would rewrite history.
 */

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
