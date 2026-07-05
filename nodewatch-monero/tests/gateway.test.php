<?php
/**
 * Gateway refund-lifecycle tests — the money path that was previously only exercised live in the
 * WP sandbox. We stub the minimal WordPress/WooCommerce surface, skip the heavy gateway
 * constructor (a test subclass), and drive process_refund() through its real open / accumulate /
 * REOPEN logic with a fake order. No WordPress needed:  php tests/gateway.test.php
 */

define( 'ABSPATH', __DIR__ . '/' );

// ---- minimal WP/WC surface the refund path touches ----
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); } }
function __( $s, $d = null ) { return $s; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function home_url( $p = '/' ) { return 'https://shop.example' . $p; }
function wc_price( $a, $args = array() ) { return '$' . number_format( (float) $a, 2 ); }
function date_i18n( $fmt, $ts ) { return gmdate( 'Y-m-d', (int) $ts ); }
function get_option( $k, $d = '' ) { return '' !== $d ? $d : 'F j, Y'; }   // date_format / time_format
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error { public $code; public $msg; public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->msg = $m; } public function get_error_message() { return $this->msg; } }
class WC_Payment_Gateway { public $id; public $supports = array(); }   // stub base — ctor skipped below

require_once __DIR__ . '/../includes/class-xmrpay-util.php';
require_once __DIR__ . '/../includes/class-wc-gateway-xmrpay.php';

// a test gateway: skip the real (action-registering) constructor; serve settings from a map.
class TestGateway extends WC_Gateway_XmrPay {
	public static $opts = array();
	public function __construct() { $this->id = 'xmrpay'; }
	public function get_option( $key, $empty_value = null ) { return isset( self::$opts[ $key ] ) ? self::$opts[ $key ] : ( null === $empty_value ? '' : $empty_value ); }
}

// a fake WC_Order: a meta bag + a note log.
class FakeOrder {
	public $id; public $meta = array(); public $notes = array(); public $pm;
	public function __construct( $id, $pm = 'xmrpay' ) { $this->id = $id; $this->pm = $pm; }
	public function get_id() { return $this->id; }
	public function get_payment_method() { return $this->pm; }
	public function get_currency() { return 'USD'; }
	public function get_order_key() { return 'wc_order_' . $this->id; }
	public function get_meta( $k ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function add_order_note( $n ) { $this->notes[] = $n; }
	public function save() {}
	public function is_paid() { return false; }
	public function get_status() { return 'on-hold'; }
}

$GLOBALS['ORDERS'] = array();
function wc_get_order( $id ) { return isset( $GLOBALS['ORDERS'][ $id ] ) ? $GLOBALS['ORDERS'][ $id ] : false; }

$pass = 0; $fail = 0;
function ok( $name, $cond, $extra = '' ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name" . ( '' !== $extra ? "  — $extra" : '' ) . "\n"; } }

$gw = new TestGateway();

// ---- 1. first refund opens the claim ----
$o = new FakeOrder( 101 );
$GLOBALS['ORDERS'][101] = $o;
$r = $gw->process_refund( 101, 25.0, '' );
ok( 'process_refund returns true', $r === true );
ok( 'first refund → status requested', $o->get_meta( '_xmrpay_refund_status' ) === 'requested' );
ok( 'first refund → amount recorded', (float) $o->get_meta( '_xmrpay_refund_amount' ) === 25.0, $o->get_meta( '_xmrpay_refund_amount' ) );
ok( 'first refund → claim opened (expiry snapshot)', '' !== (string) $o->get_meta( '_xmrpay_refund_opened' ) && '' !== (string) $o->get_meta( '_xmrpay_refund_window' ) );
ok( 'first refund → a claim-link note was added', count( $o->notes ) === 1 );

// ---- 2. second refund WHILE still requested → accumulates, stays one claim ----
$r = $gw->process_refund( 101, 15.0, '' );
ok( 'second (still requested) → amount accumulates to 40', (float) $o->get_meta( '_xmrpay_refund_amount' ) === 40.0, $o->get_meta( '_xmrpay_refund_amount' ) );
ok( 'second (still requested) → status stays requested', $o->get_meta( '_xmrpay_refund_status' ) === 'requested' );
ok( 'second (still requested) → NO extra note (same link)', count( $o->notes ) === 1 );

// ---- 3. buyer supplied an address; a NEW refund must REOPEN (the bug we fixed) ----
$o->update_meta_data( '_xmrpay_refund_status', 'address_provided' );
$o->update_meta_data( '_xmrpay_refund_address', '4buyeraddressxxxxxxxxxxxxxxxxxxxx' );
$r = $gw->process_refund( 101, 10.0, '' );
ok( 'reopen after address_provided → back to requested', $o->get_meta( '_xmrpay_refund_status' ) === 'requested' );
ok( 'reopen → stale buyer address cleared', $o->get_meta( '_xmrpay_refund_address' ) === '' );
ok( 'reopen → amount accumulates to 50', (float) $o->get_meta( '_xmrpay_refund_amount' ) === 50.0, $o->get_meta( '_xmrpay_refund_amount' ) );
ok( 'reopen → a fresh claim-link note was added', count( $o->notes ) === 2 );

// ---- 4. a refund after one was already SENT must also reopen (no stranded money) ----
$o->update_meta_data( '_xmrpay_refund_status', 'sent' );
$o->update_meta_data( '_xmrpay_refund_txid', str_repeat( 'a', 64 ) );
$r = $gw->process_refund( 101, 5.0, '' );
ok( 'reopen after sent → back to requested', $o->get_meta( '_xmrpay_refund_status' ) === 'requested' );
ok( 'reopen after sent → stale payout txid cleared', $o->get_meta( '_xmrpay_refund_txid' ) === '' );
ok( 'reopen after sent → amount accumulates to 55', (float) $o->get_meta( '_xmrpay_refund_amount' ) === 55.0 );

// ---- 5. process_refund on a non-xmrpay order → WP_Error, nothing recorded ----
$other = new FakeOrder( 202, 'stripe' );
$GLOBALS['ORDERS'][202] = $other;
$r = $gw->process_refund( 202, 30.0, '' );
ok( 'non-xmrpay order → WP_Error', is_wp_error( $r ) );
ok( 'non-xmrpay order → no refund meta written', $other->get_meta( '_xmrpay_refund_status' ) === '' );

// ---- 6. missing order → WP_Error (no fatal) ----
$r = $gw->process_refund( 999, 1.0, '' );
ok( 'missing order → WP_Error', is_wp_error( $r ) );

echo "\n" . ( $fail ? 'FAILED' : 'ALL GREEN' ) . "  $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
