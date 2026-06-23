<?php
/**
 * Plugin Name:       xmr-pay for WooCommerce
 * Plugin URI:        https://github.com/SlowBearDigger/xmr-pay
 * Description:        Accept Monero (XMR) in WooCommerce — non-custodial, funds go straight to your address. Verifies payments in pure PHP against a Monero node (no backend), or via your own xmr-pay agent. No third party in the payment path.
 * Version:           1.1.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            SlowBearDigger
 * License:           MIT
 * Text Domain:       xmr-pay-for-woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   9.5
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'XMRPAY_WC_VERSION', '1.1.1' );
define( 'XMRPAY_WC_FILE', __FILE__ );

// Declare HPOS (High-Performance Order Storage) compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// a 5-minute cron interval for the reconcile safety net (WP ships hourly+ only).
add_filter( 'cron_schedules', function ( $s ) {
	if ( ! isset( $s['xmrpay_5min'] ) ) {
		$s['xmrpay_5min'] = array( 'interval' => 300, 'display' => __( 'Every 5 minutes (xmr-pay)', 'xmr-pay-for-woocommerce' ) );
	}
	return $s;
} );
// translations: WP.org auto-loads, but this covers self-hosted installs too.
add_action( 'init', function () {
	load_plugin_textdomain( 'xmr-pay-for-woocommerce', false, dirname( plugin_basename( XMRPAY_WC_FILE ) ) . '/languages' );
	// hourly cleanup of expired unpaid orders (no-op unless the merchant set a window)
	if ( ! wp_next_scheduled( 'xmrpay_expire_orders' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'xmrpay_expire_orders' );
	}
	// safety net: every 5 min, poll the agent for on-hold orders the webhook may
	// have missed (endpoint down, or the buyer closed the tab). the webhook +
	// buyer-poll are the fast paths; this guarantees eventual fulfillment.
	if ( ! wp_next_scheduled( 'xmrpay_reconcile' ) ) {
		wp_schedule_event( time() + 300, 'xmrpay_5min', 'xmrpay_reconcile' );
	}
} );
add_action( 'xmrpay_expire_orders', function () {
	if ( class_exists( 'WC_Gateway_XmrPay' ) ) {
		( new WC_Gateway_XmrPay() )->expire_orders();
	}
} );
add_action( 'xmrpay_reconcile', function () {
	if ( class_exists( 'WC_Gateway_XmrPay' ) ) {
		( new WC_Gateway_XmrPay() )->reconcile_on_hold();
	}
} );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'xmrpay_expire_orders' );
	wp_clear_scheduled_hook( 'xmrpay_reconcile' );
} );
// flag a fresh activation so we can offer the guided setup wizard once.
register_activation_hook( __FILE__, function () {
	require_once __DIR__ . '/includes/class-xmrpay-setup.php';
	XmrPay_Setup::flag_activation();
} );

add_action( 'plugins_loaded', 'xmrpay_wc_init' );
function xmrpay_wc_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'xmr-pay for WooCommerce needs WooCommerce active.', 'xmr-pay-for-woocommerce' ) . '</p></div>';
		} );
		return;
	}

	require_once __DIR__ . '/includes/class-xmrpay-util.php';
	require_once __DIR__ . '/includes/class-xmrpay-agent.php';
	require_once __DIR__ . '/includes/class-wc-gateway-xmrpay.php';

	// guided setup wizard (admin) — the store-side half of `npx xmr-pay`.
	if ( is_admin() ) {
		require_once __DIR__ . '/includes/class-xmrpay-setup.php';
		new XmrPay_Setup();
		// Monero payments report + CSV export (WooCommerce submenu).
		require_once __DIR__ . '/includes/class-xmrpay-report.php';
		new XmrPay_Report();
	}

	// let merchants price natively in Monero — register XMR as a WooCommerce
	// currency (then the cart total IS the XMR amount, no price feed needed).
	add_filter( 'woocommerce_currencies', function ( $currencies ) {
		$currencies['XMR'] = __( 'Monero (XMR)', 'xmr-pay-for-woocommerce' );
		return $currencies;
	} );
	add_filter( 'woocommerce_currency_symbol', function ( $symbol, $currency ) {
		return 'XMR' === $currency ? 'ɱ' : $symbol;
	}, 10, 2 );
	add_filter( 'woocommerce_price_trim_zeros', '__return_false' ); // XMR keeps its decimals

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'WC_Gateway_XmrPay';
		return $gateways;
	} );

	// WooCommerce Blocks (React) checkout — a classic gateway needs this to appear
	// there at all (the default checkout in modern WooCommerce).
	add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
		require_once __DIR__ . '/includes/class-xmrpay-blocks.php';
		$registry->register( new XmrPay_Blocks_Support() );
	} );

	// register the buyer-facing scripts (enqueued on the order-received page)
	add_action( 'wp_enqueue_scripts', function () {
		wp_register_script( 'xmrpay-widget', plugins_url( 'assets/xmr-pay.js', XMRPAY_WC_FILE ), array(), XMRPAY_WC_VERSION, true );
		wp_register_script( 'xmrpay-checkout', plugins_url( 'assets/checkout.js', XMRPAY_WC_FILE ), array(), XMRPAY_WC_VERSION, true );
		wp_localize_script( 'xmrpay-checkout', 'xmrpayL10n', array(
			'watching'   => __( 'Watching', 'xmr-pay-for-woocommerce' ),
			'detected'   => __( 'Detected', 'xmr-pay-for-woocommerce' ),
			'confirming' => __( 'Confirming', 'xmr-pay-for-woocommerce' ),
			'confirmed'  => __( 'Confirmed', 'xmr-pay-for-woocommerce' ),
			'paid'       => __( 'Payment confirmed', 'xmr-pay-for-woocommerce' ),
			'mWatching'  => __( 'Watching the blockchain for your payment…', 'xmr-pay-for-woocommerce' ),
			'mMempool'   => __( 'Payment detected — waiting for the first confirmation.', 'xmr-pay-for-woocommerce' ),
			'mConfirming'=> __( 'Confirming — {c}/{m} confirmations.', 'xmr-pay-for-woocommerce' ),
			'mPartial'   => __( 'Received {r} XMR — send {s} more (QR updated to the exact amount).', 'xmr-pay-for-woocommerce' ),
			'mLocked'    => __( 'Funds received — maturing on-chain…', 'xmr-pay-for-woocommerce' ),
			'mConnecting'=> __( 'Connecting to the payment scanner…', 'xmr-pay-for-woocommerce' ),
			'block'      => __( 'Latest block', 'xmr-pay-for-woocommerce' ),
			// proof mode "I've paid" txid form (assets/checkout.js)
			'pBadTxid'    => __( 'That doesn’t look like a transaction ID (need 64 hex characters).', 'xmr-pay-for-woocommerce' ),
			'pChecking'   => __( 'Checking the blockchain…', 'xmr-pay-for-woocommerce' ),
			'pConfirmed'  => __( 'Payment confirmed! Reloading…', 'xmr-pay-for-woocommerce' ),
			'pNotYet'     => __( 'Not confirmed yet. If you just paid, wait a minute and try again.', 'xmr-pay-for-woocommerce' ),
			'pUnreachable'=> __( 'Could not reach the server. Try again.', 'xmr-pay-for-woocommerce' ),
		) );
	} );

	// WooCommerce AJAX (no admin-ajax): ?wc-ajax=xmrpay_status — the buyer's poll,
	// proxied server-side to the private agent.
	add_action( 'wc_ajax_xmrpay_status', 'xmrpay_wc_ajax_status' );
	add_action( 'wc_ajax_nopriv_xmrpay_status', 'xmrpay_wc_ajax_status' );

	// admin-ajax for the settings/wizard "Check setup" + "Test connection" buttons.
	// Registered here (not in the gateway constructor) because admin-ajax requests
	// don't construct the gateway — a constructor-bound handler would 400.
	add_action( 'wp_ajax_xmrpay_test_node', 'xmrpay_wc_ajax_test_node' );
	add_action( 'wp_ajax_xmrpay_test_agent', 'xmrpay_wc_ajax_test_agent' );
}

function xmrpay_wc_ajax_test_node() {
	( new WC_Gateway_XmrPay() )->ajax_test_node();
}

function xmrpay_wc_ajax_test_agent() {
	( new WC_Gateway_XmrPay() )->ajax_test_agent();
}

function xmrpay_wc_ajax_status() {
	$gw = new WC_Gateway_XmrPay();
	$gw->ajax_status();
}
