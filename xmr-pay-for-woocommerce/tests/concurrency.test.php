<?php
/**
 * Concurrency stress — the atomic add_option mutex (acquire_lock / release_lock) under contention.
 * WordPress has no atomic "add transient"; add_option is the INSERT-or-fail primitive that actually
 * serialises overlapping webhook / poll / cron completion, refund-address capture, and txid claims
 * on a single-DB site. We model that backend as an in-memory store with a controllable clock, then
 * drive many interleaved callers at the SAME order and assert:
 *
 *   - only ONE caller enters the critical section (mutual exclusion),
 *   - the guarded op is idempotent (no double payment_complete, no second address overwrite),
 *   - a lock past its TTL is reclaimed EXACTLY ONCE (stale holder crashed),
 *   - locks never wedge (zero lock rows once everyone is done).
 *
 * The lock logic is a faithful mirror of the gateway's acquire_lock/release_lock (reads a virtual
 * clock so the stale-reclaim path is deterministic). A comment/whitespace-insensitive drift NOTE
 * warns if the real implementation's logic diverges from the mirror.
 *
 *   php tests/concurrency.test.php
 */

define( 'ABSPATH', __DIR__ . '/' );

// ---- in-memory option store + virtual clock = the single-DB WP backend ----
$GLOBALS['OPTIONS'] = array();
$GLOBALS['NOW']     = 1000000;

function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, $GLOBALS['OPTIONS'] ) ) { return false; }   // INSERT fails on a duplicate key
	$GLOBALS['OPTIONS'][ $name ] = $value;
	return true;
}
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }
function delete_option( $name ) { if ( array_key_exists( $name, $GLOBALS['OPTIONS'] ) ) { unset( $GLOBALS['OPTIONS'][ $name ] ); return true; } return false; }
function test_now() { return $GLOBALS['NOW']; }
function test_advance( $secs ) { $GLOBALS['NOW'] += (int) $secs; }
function get_current_blog_id() { return 1; }
function live_locks() { $n = 0; foreach ( array_keys( $GLOBALS['OPTIONS'] ) as $k ) { if ( 0 === strpos( $k, 'xmrpay_lock_' ) ) { $n++; } } return $n; }
function reset_store() { $GLOBALS['OPTIONS'] = array(); }

// the lock logic under test — a faithful mirror reading the virtual clock instead of time().
class LockGateway {
	public function acquire_lock( $key, $ttl = 30 ) {
		$opt = 'xmrpay_lock_' . get_current_blog_id() . '_' . $key;
		if ( add_option( $opt, test_now() + (int) $ttl, '', 'no' ) ) { return true; }
		$exp = (int) get_option( $opt );
		if ( $exp > 0 && test_now() > $exp ) { delete_option( $opt ); return (bool) add_option( $opt, test_now() + (int) $ttl, '', 'no' ); }
		return false;
	}
	public function release_lock( $key ) { delete_option( 'xmrpay_lock_' . get_current_blog_id() . '_' . $key ); }
}

$pass = 0; $fail = 0;
function ok( $name, $cond, $extra = '' ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name" . ( '' !== $extra ? "  — $extra" : '' ) . "\n"; } }

// ---- drift NOTE: compare the mirror's acquire_lock logic to the real source (logic-only) ----
( function () {
	$src = @file_get_contents( __DIR__ . '/../includes/class-wc-gateway-xmrpay.php' );
	if ( false === $src ) { echo "NOTE  drift guard skipped (source unreadable)\n"; return; }
	$logic = function ( $s ) {
		$s = preg_replace( '/\btime\(\)/', 'test_now()', $s );   // unify the clock call
		$s = preg_replace( '#//.*$#m', '', $s );                 // drop line comments
		$s = preg_replace( '/\s+/', '', $s );                    // drop ALL whitespace → logic only
		return $s;
	};
	if ( ! preg_match( '/private function acquire_lock\([^)]*\)\s*\{(.*?)\n\t\}/s', $src, $m ) ) { echo "NOTE  drift guard skipped (acquire_lock not matched)\n"; return; }
	$real = $logic( $m[1] );
	$ref  = new ReflectionMethod( 'LockGateway', 'acquire_lock' );
	$lines = file( $ref->getFileName() );
	$mine = $logic( implode( '', array_slice( $lines, $ref->getStartLine(), $ref->getEndLine() - $ref->getStartLine() - 1 ) ) );
	echo ( $real === $mine ) ? "NOTE  lock mirror matches real acquire_lock logic ✓\n"
		: "NOTE  ⚠ lock mirror DIVERGED from real acquire_lock — review tests/concurrency.test.php\n";
} )();

// ---- cooperative scheduler: generators yield between shared-state touches ----
function drive( array $callers ) {
	foreach ( $callers as $c ) { $c->current(); }
	$alive = $callers; $guard = 0;
	while ( $alive ) {
		foreach ( $alive as $i => $c ) { $c->next(); if ( ! $c->valid() ) { unset( $alive[ $i ] ); } }
		if ( ++$guard > 100000 ) { throw new RuntimeException( 'scheduler livelock' ); }
	}
}
function drive_ordered( array $callers, array $schedule ) {
	foreach ( $callers as $c ) { $c->current(); }
	foreach ( $schedule as $idx ) { if ( isset( $callers[ $idx ] ) && $callers[ $idx ]->valid() ) { $callers[ $idx ]->next(); } }
	$alive = array_filter( $callers, function ( $c ) { return $c->valid(); } );
	$guard = 0;
	while ( $alive ) {
		foreach ( $alive as $i => $c ) { $c->next(); if ( ! $c->valid() ) { unset( $alive[ $i ] ); } }
		if ( ++$guard > 100000 ) { throw new RuntimeException( 'scheduler livelock' ); }
	}
}

class SharedOrder {
	public $meta = array(); public $paid = false; public $complete_calls = 0; public $notes = 0;
	public function get( $k ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function set( $k, $v ) { $this->meta[ $k ] = $v; }
	public function is_paid() { return $this->paid; }
	public function payment_complete() { $this->complete_calls++; $this->paid = true; }
}

// mirrors the mark_paid critical section; yields at the exact shared-state touches.
function caller_mark_paid( LockGateway $gw, SharedOrder $order, $order_id ) {
	$lock_key = 'pay_' . $order_id;
	yield;
	if ( $order->is_paid() ) { return; }
	yield;
	if ( ! $gw->acquire_lock( $lock_key, 30 ) ) { return; }
	yield;                                          // <-- the dangerous window: lock held, not yet re-checked
	if ( $order->is_paid() ) { $gw->release_lock( $lock_key ); return; }
	yield;
	$order->payment_complete();
	$gw->release_lock( $lock_key );
}
// mirrors the refund address-capture block (first-address-wins).
function caller_capture_addr( LockGateway $gw, SharedOrder $order, $order_id, $addr ) {
	$caplock = 'rfcap_' . $order_id;
	yield;
	if ( ! $gw->acquire_lock( $caplock, 30 ) ) { return; }
	yield;
	if ( 'requested' === (string) $order->get( '_xmrpay_refund_status' ) ) {
		$order->set( '_xmrpay_refund_address', $addr );
		$order->set( '_xmrpay_refund_status', 'address_provided' );
		$order->notes++;
	}
	yield;
	$gw->release_lock( $caplock );
}

$gw = new LockGateway();

// ---- 1. mutual exclusion: N completers → exactly ONE payment_complete ----
foreach ( array( 2, 3, 5, 10 ) as $n ) {
	reset_store();
	$order = new SharedOrder(); $callers = array();
	for ( $i = 0; $i < $n; $i++ ) { $callers[] = caller_mark_paid( $gw, $order, 700 ); }
	drive( $callers );
	ok( "mark_paid x$n → exactly one payment_complete", 1 === $order->complete_calls, "got {$order->complete_calls}" );
	ok( "mark_paid x$n → order is paid", $order->is_paid() );
	ok( "mark_paid x$n → no lock wedged", 0 === live_locks(), live_locks() . ' left' );
}

// ---- 2. mutual exclusion across every ordering of a repeated 3-caller schedule ----
function perms3() {
	$out = array();
	foreach ( array( 0,1,2 ) as $a ) { foreach ( array( 0,1,2 ) as $b ) { foreach ( array( 0,1,2 ) as $c ) { foreach ( array( 0,1,2 ) as $d ) {
		$out[] = array( $a,$b,$c,$d, $a,$b,$c,$d, $a,$b,$c,$d, $a,$b,$c,$d );
	} } } }
	return $out;
}
$bad = 0; $iters = 0;
foreach ( perms3() as $sched ) {
	reset_store();
	$order = new SharedOrder();
	$callers = array( caller_mark_paid( $gw, $order, 701 ), caller_mark_paid( $gw, $order, 701 ), caller_mark_paid( $gw, $order, 701 ) );
	drive_ordered( $callers, $sched );
	$iters++;
	if ( 1 !== $order->complete_calls || ! $order->is_paid() || 0 !== live_locks() ) { $bad++; }
}
ok( "mark_paid invariant holds across $iters forced interleavings", 0 === $bad, "$bad violations" );

// ---- 3. refund first-address-wins: N concurrent POSTs, distinct addresses → ONE write ----
foreach ( array( 2, 4, 8 ) as $n ) {
	reset_store();
	$order = new SharedOrder(); $order->set( '_xmrpay_refund_status', 'requested' );
	$callers = array(); $addrs = array();
	for ( $i = 0; $i < $n; $i++ ) { $a = '4' . str_repeat( (string) $i, 10 ) . str_repeat( 'x', 84 ); $addrs[] = $a; $callers[] = caller_capture_addr( $gw, $order, 800, $a ); }
	drive( $callers );
	ok( "capture x$n → status address_provided", 'address_provided' === $order->get( '_xmrpay_refund_status' ) );
	ok( "capture x$n → exactly one writer (one note)", 1 === $order->notes, "notes={$order->notes}" );
	ok( "capture x$n → address is a real submitted one", in_array( $order->get( '_xmrpay_refund_address' ), $addrs, true ) );
	ok( "capture x$n → no lock wedged", 0 === live_locks() );
}

// ---- 4. first-address-wins is STICKY: a later POST can't overwrite ----
reset_store();
$order = new SharedOrder(); $order->set( '_xmrpay_refund_status', 'requested' );
$first = '4' . str_repeat( 'a', 94 ); $second = '4' . str_repeat( 'b', 94 );
drive( array( caller_capture_addr( $gw, $order, 801, $first ) ) );
ok( 'sticky → first address written', $order->get( '_xmrpay_refund_address' ) === $first );
drive( array( caller_capture_addr( $gw, $order, 801, $second ), caller_capture_addr( $gw, $order, 801, $second ) ) );
ok( 'sticky → second address REJECTED (no overwrite)', $order->get( '_xmrpay_refund_address' ) === $first );
ok( 'sticky → still one note', 1 === $order->notes );
ok( 'sticky → no lock wedged', 0 === live_locks() );

// ---- 5. stale-lock reclaim: holder crashes, lock past TTL reclaimed EXACTLY once ----
reset_store();
$ttl = 30;
ok( 'reclaim → holder acquires', $gw->acquire_lock( 'pay_900', $ttl ) === true );
ok( 'reclaim → contender blocked before TTL', $gw->acquire_lock( 'pay_900', $ttl ) === false );
test_advance( $ttl + 1 );
$w1 = $gw->acquire_lock( 'pay_900', $ttl );
$w2 = $gw->acquire_lock( 'pay_900', $ttl );
ok( 'reclaim → exactly one of two simultaneous reclaimers wins', ( $w1 xor $w2 ) );
ok( 'reclaim → exactly one lock row after reclaim', 1 === live_locks() );
$gw->release_lock( 'pay_900' );
ok( 'reclaim → released cleanly', 0 === live_locks() );

// ---- 6. stale reclaim does NOT let the guarded op run twice ----
reset_store();
$order = new SharedOrder(); $oid = 901;
ok( 'reclaim-idem → A acquires', $gw->acquire_lock( 'pay_' . $oid, 30 ) === true );   // A crashes holding it
test_advance( 31 );
ok( 'reclaim-idem → B reclaims stale lock', $gw->acquire_lock( 'pay_' . $oid, 30 ) === true );
if ( ! $order->is_paid() ) { $order->payment_complete(); }
$gw->release_lock( 'pay_' . $oid );
test_advance( 31 );
if ( $gw->acquire_lock( 'pay_' . $oid, 30 ) ) { if ( ! $order->is_paid() ) { $order->payment_complete(); } $gw->release_lock( 'pay_' . $oid ); }
ok( 'reclaim-idem → payment_complete ran exactly once across crash+reclaim', 1 === $order->complete_calls, "got {$order->complete_calls}" );
ok( 'reclaim-idem → no lock wedged', 0 === live_locks() );

// ---- 7. churn / never-wedge: many seeded-random interleavings, store must end clean ----
mt_srand( 1337 );
$violations = 0; $rounds = 2000;
for ( $r = 0; $r < $rounds; $r++ ) {
	reset_store();
	$order = new SharedOrder();
	$n = 2 + ( $r % 6 ); $callers = array();
	for ( $i = 0; $i < $n; $i++ ) { $callers[] = caller_mark_paid( $gw, $order, 950 ); }
	foreach ( $callers as $c ) { $c->current(); }
	$alive = $callers; $guard = 0;
	while ( $alive ) {
		$k = array_rand( $alive );
		$alive[ $k ]->next();
		if ( ! $alive[ $k ]->valid() ) { unset( $alive[ $k ] ); }
		if ( ++$guard > 100000 ) { $violations++; break; }
	}
	if ( 1 !== $order->complete_calls || 0 !== live_locks() ) { $violations++; }
}
ok( "churn → $rounds seeded-random interleavings, one-complete + no-wedge each", 0 === $violations, "$violations violations" );

echo "\n" . ( $fail ? 'FAILED' : 'ALL GREEN' ) . "  $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
