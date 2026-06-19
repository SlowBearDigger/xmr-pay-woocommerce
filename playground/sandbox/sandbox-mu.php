<?php
/**
 * Plugin Name: xmr-pay Live Sandbox
 * Description: Auto-signs visitors in as a store manager on the public stagenet WP at live.xmrpay.shop, with the server locked down. The "configure it yourself" demo.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const XMRPAY_SB_USER = 'sandbox';

// 1) auto sign-in every visitor as the limited store manager (except wp-login, so
//    the owner can still sign in as the real admin).
add_action( 'init', function () {
	if ( is_user_logged_in() ) {
		return;
	}
	if ( php_sapi_name() === 'cli' || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
	if ( strpos( $uri, 'wp-login.php' ) !== false || strpos( $uri, 'wp-cron.php' ) !== false ) {
		return;
	}
	$u = get_user_by( 'login', XMRPAY_SB_USER );
	if ( ! $u ) {
		return;
	}
	wp_set_current_user( $u->ID );
	wp_set_auth_cookie( $u->ID, true );
}, 1 );

// 2) defence in depth — deny server-level capabilities for the sandbox user even
//    if the role drifts. (DISALLOW_FILE_MODS in wp-config is the hard guard.)
add_filter( 'user_has_cap', function ( $allcaps, $caps, $args, $user ) {
	if ( ! $user || ! isset( $user->user_login ) || $user->user_login !== XMRPAY_SB_USER ) {
		return $allcaps;
	}
	$deny = array(
		'install_plugins', 'activate_plugins', 'deactivate_plugins', 'delete_plugins', 'edit_plugins', 'update_plugins',
		'install_themes', 'switch_themes', 'edit_themes', 'delete_themes', 'update_themes',
		'edit_users', 'create_users', 'delete_users', 'promote_users', 'remove_users',
		'edit_files', 'update_core', 'export', 'import',
	);
	foreach ( $deny as $c ) {
		unset( $allcaps[ $c ] );
	}
	return $allcaps;
}, 99, 4 );

// 3) privacy: never store the buyer's IP address or user-agent on orders.
//    (WooCommerce core records these on every order; this strips them. Monero is
//    irreversible — no chargebacks — so there's no reason to keep them.)
add_action( 'woocommerce_checkout_create_order', function ( $order ) {
	$order->set_customer_ip_address( '' );
	$order->set_customer_user_agent( '' );
}, 999 );
add_filter( 'woocommerce_order_get_customer_ip_address', '__return_empty_string', 999 );

// 4) a sandbox banner on the storefront (fixed top bar, theme-independent).
add_action( 'wp_head', function () {
	if ( is_admin() ) {
		return;
	}
	echo '<style>body{padding-top:48px!important}'
		. '#xmrpay-sb{position:fixed;top:0;left:0;right:0;z-index:99999;background:#ff6600;color:#000;'
		. 'font:700 12.5px/1.4 ui-monospace,Menlo,monospace;padding:9px 14px;display:flex;gap:12px;'
		. 'align-items:center;flex-wrap:wrap;border-bottom:2px solid #000;box-sizing:border-box}'
		. '#xmrpay-sb a{background:#000;color:#ff6600;padding:5px 11px;text-decoration:none;white-space:nowrap}'
		. '#xmrpay-sb a:hover{background:#111}</style>';
} );
add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	$wizard   = admin_url( 'admin.php?page=xmrpay-setup' );
	$settings = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=xmrpay' );
	$shop     = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
	echo '<div id="xmrpay-sb"><span>xmr-pay LIVE SANDBOX — a real WordPress on stagenet. Configure the plugin; the server is locked.</span>'
		. '<a href="' . esc_url( $wizard ) . '">Setup wizard</a>'
		. '<a href="' . esc_url( $settings ) . '">Gateway settings</a>'
		. '<a href="' . esc_url( $shop ) . '">Storefront</a>'
		. '<a href="https://xmrpay.shop" style="margin-left:auto">← xmr-pay.shop</a>'
		. '<a href="https://xmrpay.shop/demo.html">How it works</a></div>';
} );

// 4) admin notice explaining the sandbox.
add_action( 'admin_notices', function () {
	$wizard = admin_url( 'admin.php?page=xmrpay-setup' );
	echo '<div class="notice notice-info"><p><strong>xmr-pay live sandbox.</strong> '
		. 'You\'re auto-signed-in as a <em>store manager</em> on a real, stagenet WordPress. '
		. 'Run the <a href="' . esc_url( $wizard ) . '">setup wizard</a>, change the gateway settings, or place a test order. '
		. 'Installing plugins/themes and editing files are disabled here.</p></div>';
} );
