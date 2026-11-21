<?php
/**
 * Gateway refund-lifecycle tests — the money path that was previously only exercised live in the
 * WP sandbox. We stub the minimal WordPress/WooCommerce surface, skip the heavy gateway
 * constructor (a test subclass), and drive process_refund() through its real open / accumulate /
 * REOPEN logic with a fake order. No WordPress needed:  php tests/gateway.test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

// ---- minimal WP/WC surface the refund path touches ----
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); } }
function __( $s, $d = null ) { return $s; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function home_url( $p = '/' ) { return 'https://shop.example' . $p; }
function wc_price( $a, $args = array() ) { return '$' . number_format( (float) $a, 2 ); }
function date_i18n( $fmt, $ts ) { return gmdate( 'Y-m-d', (int) $ts ); }
function get_option( $k, $d = '' ) { return '' !== $d ? $d : 'F j, Y'; }   // date_format / time_format
function get_current_blog_id() { return 1; }
function get_transient( $k ) { return $GLOBALS['TRANSIENTS'][ $k ] ?? false; }
function wc_get_orders( $args ) { return $GLOBALS['PENDING_IDS'] ?? array(); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['TRANSIENTS'][$key] = $value; }
function delete_transient( $key ) { unset( $GLOBALS['TRANSIENTS'][$key] ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_safe_remote_get( $url, $args ) { if ( ! empty( $GLOBALS['NODE_OFFLINE'] ) ) { return new WP_Error( 'offline' ); } return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'height' => $GLOBALS['SCAN_TIP'] ?? 100 ) ) ); }
function wp_safe_remote_post( $url, $args ) {
    $height = json_decode( $args['body'], true )['params']['height'];
    if ( isset( $GLOBALS['SCAN_TIP'] ) ) {
        if ( $height >= $GLOBALS['SCAN_TIP'] || $height === ( $GLOBALS['UNAVAILABLE_HEIGHT'] ?? null ) ) { return new WP_Error( 'unavailable' ); }
        if ( ! isset( $GLOBALS['SCAN_HEIGHTS'][$height] ) && count( $GLOBALS['SCAN_HEIGHTS'] ) >= 9 ) { return new WP_Error( 'chunk-end' ); }
        $GLOBALS['SCAN_HEIGHTS'][$height] = true;
        $GLOBALS['ALL_SCAN_HEIGHTS'][$height] = true;
    }
    $GLOBALS['BLOCK_READS'][$height] = ( $GLOBALS['BLOCK_READS'][$height] ?? 0 ) + 1;
    if ( ( $GLOBALS['FAIL_FINAL_HEIGHT'] ?? null ) === $height && $GLOBALS['BLOCK_READS'][$height] > 1 ) { return new WP_Error( 'final-header-unavailable' ); }
    $block = $height < ( $GLOBALS['SCAN_TIP'] ?? 100 ) ? array( 'result' => array( 'block_header' => array( 'height' => $height, 'num_txes' => 0, 'hash' => scan_block_hash( $height ), 'prev_hash' => scan_block_hash( $height - 1 ) ), 'tx_hashes' => array() ) ) : array();
    if ( ( $GLOBALS['CHANGE_FINAL_HEIGHT'] ?? null ) === $height && $GLOBALS['BLOCK_READS'][$height] > 1 ) { $block['result']['block_header']['hash'] = str_repeat( 'f', 64 ); }
    if ( ( $GLOBALS['MISSING_HEADER_HEIGHT'] ?? null ) === $height ) { unset( $block['result']['block_header']['hash'] ); }
    if ( ( $GLOBALS['WRONG_PREVIOUS_HEIGHT'] ?? null ) === $height ) { $block['result']['block_header']['prev_hash'] = str_repeat( 'f', 64 ); }
    return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $block ) );
}
function scan_block_hash( $height ) { return hash( 'sha256', (string) $height . ( ( $GLOBALS['REORG_HEIGHT'] ?? null ) === $height ? ':fork' : '' ) ); }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
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
	public $id; public $meta = array(); public $notes = array(); public $pm; public $status = 'on-hold';
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
	public function get_status() { return $this->status; }
	public function update_status( $status, $note = '' ) { $this->status = $status; }
}

$GLOBALS['ORDERS'] = array();
function wc_get_order( $id ) { return isset( $GLOBALS['ORDERS'][ $id ] ) ? $GLOBALS['ORDERS'][ $id ] : false; }

$pass = 0; $fail = 0;
function ok( $name, $cond, $extra = '' ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name" . ( '' !== $extra ? "  — $extra" : '' ) . "\n"; } }

class XmrPay_Agent {
    public static $response = array();
    public function __construct( ...$args ) {}
    public function get_order( ...$args ) { return self::$response; }
}

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
ok( 'reopen after sent excludes the previous payout', (float) $o->get_meta( '_xmrpay_refund_amount' ) === 5.0 );

// ---- 5. process_refund on a non-xmrpay order → WP_Error, nothing recorded ----
$other = new FakeOrder( 202, 'stripe' );
$GLOBALS['ORDERS'][202] = $other;
$r = $gw->process_refund( 202, 30.0, '' );
ok( 'non-xmrpay order → WP_Error', is_wp_error( $r ) );
ok( 'non-xmrpay order → no refund meta written', $other->get_meta( '_xmrpay_refund_status' ) === '' );

// ---- 6. missing order → WP_Error (no fatal) ----
$r = $gw->process_refund( 999, 1.0, '' );
ok( 'missing order → WP_Error', is_wp_error( $r ) );

// ---- 7. expiry must wait if a watch scan is still in cooldown ----
$watch = new FakeOrder( 303 );
$watch->update_meta_data( '_xmrpay_mode', 'watch' );
$watch->update_meta_data( '_xmrpay_address', 'merchant-subaddress' );
$GLOBALS['ORDERS'][303] = $watch;
$GLOBALS['PENDING_IDS'] = array( 303 );
$GLOBALS['TRANSIENTS']['xmrpay_scancd_1_303'] = 1;
TestGateway::$opts['expiry_hours'] = '1';
$gw->expire_orders();
ok( 'watch order remains open without a fresh scan', $watch->get_status() === 'on-hold' );

// A signed agent event must not complete orders that require local chain verification.
$mark_paid = new ReflectionMethod( WC_Gateway_XmrPay::class, 'mark_paid' );
if ( PHP_VERSION_ID < 80100 ) { $mark_paid->setAccessible( true ); }
foreach ( array( 'watch', 'proof' ) as $mode ) {
    $order = new FakeOrder( 'watch' === $mode ? 404 : 405 );
    $order->update_meta_data( '_xmrpay_mode', $mode );
    $GLOBALS['ORDERS'][ $order->get_id() ] = $order;
    $mark_paid->invoke( $gw, $order, array( 'paid' => true, 'txids' => array( str_repeat( 'a', 64 ) ) ) );
    ok( $mode . ' ignores agent completion', 'on-hold' === $order->get_status() );
}

$agent_order = new FakeOrder( 506 );
$agent_order->update_meta_data( '_xmrpay_mode', 'agent' );
$agent_order->update_meta_data( '_xmrpay_address', 'merchant-subaddress' );
$GLOBALS['ORDERS'][506] = $agent_order;
$GLOBALS['PENDING_IDS'] = array( 506 );
TestGateway::$opts['agent_url'] = 'http://127.0.0.1:8792';
foreach ( array( 'pendingXmr' => '0.01', 'lockedXmr' => '0.01', 'syncing' => true ) as $key => $value ) {
    XmrPay_Agent::$response = array( 'paid' => false, 'receivedXmr' => '0', $key => $value );
    $gw->expire_orders();
    ok( $key . ' prevents agent order expiry', $agent_order->get_status() === 'on-hold' );
}
XmrPay_Agent::$response = array( 'paid' => false, 'receivedXmr' => '0', 'pendingXmr' => '0', 'lockedXmr' => '0', 'syncing' => false );
$gw->expire_orders();
ok( 'synced unpaid agent order can expire', $agent_order->get_status() === 'cancelled' );

$watch->update_meta_data( '_xmrpay_birthday', 99 );
$watch->update_meta_data( '_xmrpay_amount', '0.01' );
TestGateway::$opts['view_key'] = str_repeat( 'a', 64 );
$GLOBALS['TRANSIENTS'] = array();
$GLOBALS['PENDING_IDS'] = array( 303 );
$gw->expire_orders();
ok( 'watch expiry checks the last existing block, not the chain length', $watch->get_status() === 'cancelled' );

function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return (string) $value; }
function wp_unslash( $value ) { return $value; }
class JsonResponse extends RuntimeException { public $data; public $http_status; public function __construct( $data, $status = 200 ) { $this->data = $data; $this->http_status = $status; } }
function wp_send_json( $data, $code = 200 ) { throw new JsonResponse( $data, $code ); }

ok( 'watch scan saves checkout status', is_array( $watch->get_meta( '_xmrpay_watch_status' ) ) && $watch->get_meta( '_xmrpay_watch_status' )['status'] === 'pending' );
$watch->status = 'on-hold';
$watch->update_meta_data( '_xmrpay_watch_status', array( 'status' => 'partial', 'receivedXmr' => '0.007', 'shortfallXmr' => '0.013', 'confirmations' => 2, 'minConfirmations' => 1, 'tipHeight' => 100 ) );
$GLOBALS['TRANSIENTS']['xmrpay_scancd_1_303'] = 1;
$_GET = array( 'order_id' => 303, 'key' => $watch->get_order_key() );
try { $gw->ajax_status(); } catch ( JsonResponse $response ) {
    $data = $response->data;
    ok( 'partial status survives scan cooldown', $data['status'] === 'partial' && $data['paid'] === false );
    ok( 'checkout receives exact remaining amount', ( $data['receivedXmr'] ?? null ) === '0.007' && ( $data['shortfallXmr'] ?? null ) === '0.013' );
    ok( 'checkout receives confirmation requirement', ( $data['confirmations'] ?? null ) === 2 && ( $data['minConfirmations'] ?? null ) === 1 );
}

$GLOBALS['TRANSIENTS'] = array();
$GLOBALS['NODE_OFFLINE'] = true;
try { $gw->ajax_status(); } catch ( JsonResponse $response ) {
    ok( 'offline watch reports unreachable', $response->data['reachable'] === false );
    ok( 'offline watch preserves partial amount', $response->data['receivedXmr'] === '0.007' );
}
$GLOBALS['TRANSIENTS']['xmrpay_scancd_1_303'] = 1;
try { $gw->ajax_status(); } catch ( JsonResponse $response ) {
    ok( 'cooldown preserves offline status', $response->data['reachable'] === false );
}
$GLOBALS['NODE_OFFLINE'] = false;
$GLOBALS['TRANSIENTS'] = array();
try { $gw->ajax_status(); } catch ( JsonResponse $response ) {
    ok( 'recovered watch reports reachable', $response->data['reachable'] === true );
}
TestGateway::$opts['view_key'] = '';
$GLOBALS['TRANSIENTS'] = array();
try { $gw->ajax_status(); } catch ( JsonResponse $response ) {
    ok( 'missing watch key reports unavailable', $response->data['reachable'] === false );
}

class WC_Order_Refund extends FakeOrder {
    public $amount; public $parent; public $gateway_refunded = false;
    public function get_parent_id() { return $this->parent; }
    public function get_amount() { return $this->amount; }
    public function get_refunded_payment() { return $this->gateway_refunded; }
}
$manual = new FakeOrder( 600 );
$GLOBALS['ORDERS'][600] = $manual;
foreach ( array( 601 => 25, 602 => 15 ) as $id => $amount ) {
    $refund = new WC_Order_Refund( $id ); $refund->parent = 600; $refund->amount = $amount;
    $GLOBALS['ORDERS'][$id] = $refund;
    $gw->on_refunded( 600, $id );
}
ok( 'manual refund events accumulate', (float) $manual->get_meta( '_xmrpay_refund_amount' ) === 40.0 );
$gw->on_refunded( 600, 602 );
ok( 'repeated manual refund event counted once', (float) $manual->get_meta( '_xmrpay_refund_amount' ) === 40.0 );
$refund = new WC_Order_Refund( 603 ); $refund->parent = 600; $refund->amount = 10; $refund->gateway_refunded = true;
$GLOBALS['ORDERS'][603] = $refund;
$gw->process_refund( 600, 10 ); $gw->on_refunded( 600, 603 );
ok( 'gateway refund event does not count twice', (float) $manual->get_meta( '_xmrpay_refund_amount' ) === 50.0 );

// A bounded scan smaller than the overlap must still reach the invoice's payment block.
$progress = new FakeOrder( 700 );
$progress->update_meta_data( '_xmrpay_mode', 'watch' );
$progress->update_meta_data( '_xmrpay_address', 'merchant-subaddress' );
$progress->update_meta_data( '_xmrpay_amount', '0.01' );
$progress->update_meta_data( '_xmrpay_birthday', 1000 );
TestGateway::$opts['view_key'] = str_repeat( 'a', 64 );
$GLOBALS['SCAN_TIP'] = 1040;
$GLOBALS['ALL_SCAN_HEIGHTS'] = array();
$scan_order = new ReflectionMethod( WC_Gateway_XmrPay::class, 'scan_order' );
if ( PHP_VERSION_ID < 80100 ) { $scan_order->setAccessible( true ); }
$complete = false;
for ( $poll = 0; $poll < 8 && ! $complete; $poll++ ) {
    $GLOBALS['TRANSIENTS'] = array();
    $GLOBALS['SCAN_HEIGHTS'] = array();
    $complete = $scan_order->invoke( $gw, $progress );
}
ok( 'bounded watch chunks advance beyond the overlap', $complete && (int) $progress->get_meta( '_xmrpay_scan_height' ) === 1039 );
ok( 'bounded watch chunks cover every invoice block', count( $GLOBALS['ALL_SCAN_HEIGHTS'] ) === 40 );

// Completed scans keep the existing overlap, then resume that overlap from its own cursor.
$GLOBALS['TRANSIENTS'] = array();
$GLOBALS['SCAN_HEIGHTS'] = array();
$complete = $scan_order->invoke( $gw, $progress );
$overlap = $progress->get_meta( '_xmrpay_scan_checkpoint' );
ok( 'completed watch scan retains overlap', ! $complete && isset( $GLOBALS['SCAN_HEIGHTS'][1029] ) );
ok( 'overlap saves its authenticated cursor below the high-water mark', is_array( $overlap ) && $overlap['height'] < 1039 && $overlap['complete'] === false && $progress->get_meta( '_xmrpay_scan_height' ) === 1039 );
$GLOBALS['TRANSIENTS'] = array();
$GLOBALS['SCAN_HEIGHTS'] = array();
$complete = $scan_order->invoke( $gw, $progress );
ok( 'partial overlap resumes without skipping unvalidated blocks', $complete && isset( $GLOBALS['SCAN_HEIGHTS'][$overlap['height'] + 1] ) );

$completed_cursor = $progress->get_meta( '_xmrpay_scan_checkpoint' );
$GLOBALS['UNAVAILABLE_HEIGHT'] = 1029;
$GLOBALS['TRANSIENTS'] = array();
$GLOBALS['SCAN_HEIGHTS'] = array();
$complete = $scan_order->invoke( $gw, $progress );
ok( 'empty overlap attempt retains completed checkpoint context', ! $complete && $progress->get_meta( '_xmrpay_scan_checkpoint' ) === $completed_cursor );
unset( $GLOBALS['UNAVAILABLE_HEIGHT'] );

$before = $progress->meta;
$GLOBALS['UNAVAILABLE_HEIGHT'] = 1039;
$GLOBALS['TRANSIENTS'] = array();
$GLOBALS['SCAN_HEIGHTS'] = array();
$complete = $scan_order->invoke( $gw, $progress );
ok( 'unavailable checkpoint holds progress and settlement', ! $complete && $progress->get_meta( '_xmrpay_scan_checkpoint' ) === $before['_xmrpay_scan_checkpoint'] && $progress->get_meta( '_xmrpay_scan_height' ) === 1039 );
ok( 'unavailable checkpoint reports an unreachable scan', $progress->get_meta( '_xmrpay_watch_status' )['reachable'] === false );
unset( $GLOBALS['UNAVAILABLE_HEIGHT'] );

$GLOBALS['SCAN_TIP'] = 1039;
$GLOBALS['TRANSIENTS'] = array();
$GLOBALS['SCAN_HEIGHTS'] = array();
$complete = $scan_order->invoke( $gw, $progress );
ok( 'node behind checkpoint cannot complete a scan', ! $complete && $progress->get_meta( '_xmrpay_scan_checkpoint' ) === $before['_xmrpay_scan_checkpoint'] );
$GLOBALS['SCAN_TIP'] = 1040;

$GLOBALS['REORG_HEIGHT'] = 1039;
$GLOBALS['TRANSIENTS'] = array();
$GLOBALS['SCAN_HEIGHTS'] = array();
$progress->update_meta_data( '_xmrpay_received', '0.009' );
$complete = $scan_order->invoke( $gw, $progress );
ok( 'changed canonical checkpoint resets to birthday without settlement', ! $complete && $progress->get_meta( '_xmrpay_scan_checkpoint' ) === '' && $progress->get_meta( '_xmrpay_scan_height' ) === 999 && $progress->get_meta( '_xmrpay_received' ) === '' );
unset( $GLOBALS['REORG_HEIGHT'] );

foreach ( array( '', array( 'height' => 1038, 'hash' => scan_block_hash( 1038 ) ), array( 'height' => 1038, 'hash' => scan_block_hash( 1038 ), 'complete' => 'false' ) ) as $untrusted ) {
    $progress->update_meta_data( '_xmrpay_scan_height', 1038 );
    $progress->update_meta_data( '_xmrpay_scan_checkpoint', $untrusted );
    $GLOBALS['TRANSIENTS'] = array();
    $GLOBALS['SCAN_HEIGHTS'] = array();
    $complete = $scan_order->invoke( $gw, $progress );
    ok( 'legacy or malformed cursor cannot bypass invoice history', ! $complete && isset( $GLOBALS['SCAN_HEIGHTS'][1000] ) );
}

$scanner = new XmrPay_Scanner( 'http://127.0.0.1:2', 'stagenet' );
$scan_options = array( 'tip' => 1040, 'max_blocks' => 4, 'time_budget' => 8.0, 'restore_height' => 1000 );
foreach ( array( 'CHANGE_FINAL_HEIGHT', 'FAIL_FINAL_HEIGHT', 'WRONG_PREVIOUS_HEIGHT', 'MISSING_HEADER_HEIGHT' ) as $fault ) {
    $GLOBALS[$fault] = 1002;
    $GLOBALS['SCAN_HEIGHTS'] = array();
    $GLOBALS['BLOCK_READS'] = array();
    $result = $scanner->scan_all( 'merchant-subaddress', str_repeat( 'a', 64 ), 1000, 1039, $scan_options );
    if ( $fault === 'FAIL_FINAL_HEIGHT' ) {
        ok( 'final header outage cannot publish a checkpoint', $result['checkpoint'] === null && ( $result['checkpoint_error'] ?? '' ) === 'unavailable' && $result['scanned_to'] === 999 );
    } elseif ( $fault === 'MISSING_HEADER_HEIGHT' ) {
        ok( 'missing canonical header cannot advance past that block', $result['scanned_to'] === 1001 && $result['checkpoint']['height'] === 1001 );
    } else {
        ok( 'changed or disconnected acquired branch resets scan', ! empty( $result['reorg_detected'] ) && $result['checkpoint'] === null && $result['scanned_to'] === 999 && $result['matches'] === array() );
    }
    unset( $GLOBALS[$fault] );
}

// Agent eligibility must not claim native completion when the database cannot acquire its lock.
class UnavailablePaymentDatabase {
    public $prefix = 'wp_';
    public function prepare( $query, ...$arguments ) { return $query; }
    public function get_var( $query ) { return '0'; }
}
function add_option( $key, $value = '', $deprecated = '', $autoload = true ) { return false; }
$GLOBALS['wpdb'] = new UnavailablePaymentDatabase();
XmrPay_Agent::$response = array( 'paid' => true, 'status' => 'paid', 'receivedXmr' => '0.01' );
$_GET = array( 'order_id' => 506, 'key' => $agent_order->get_order_key() );
$agent_order->status = 'on-hold';
try { $gw->ajax_status(); } catch ( JsonResponse $response ) {
    ok( 'unavailable native completion does not report agent eligibility as paid', $response->data['paid'] === false && $response->data['status'] === 'pending' );
    ok( 'unavailable native completion remains retryable', $response->http_status === 503 && $response->data['reachable'] === false );
}

echo "\n" . ( $fail ? 'FAILED' : 'ALL GREEN' ) . "  $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
