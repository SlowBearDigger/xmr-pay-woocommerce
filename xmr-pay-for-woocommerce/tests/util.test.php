<?php
/**
 * Standalone tests for XmrPay_Util — the exact money math + webhook HMAC.
 * No WordPress needed:  php tests/util.test.php
 */

define( 'XMRPAY_TESTING', true );
require_once __DIR__ . '/../includes/class-xmrpay-util.php';

$pass = 0; $fail = 0;
function ok( $name, $cond, $extra = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $name\n"; }
	else { $fail++; echo "FAIL  $name" . ( $extra !== '' ? "  — $extra" : '' ) . "\n"; }
}
function eq( $name, $got, $want ) { ok( $name, $got === $want, "got '" . var_export( $got, true ) . "' want '" . var_export( $want, true ) . "'" ); }

// ---------- fmt: canonical XMR string ----------
eq( 'fmt integer',            XmrPay_Util::fmt( 1 ),            '1' );
eq( 'fmt trims zeros',        XmrPay_Util::fmt( 0.0500 ),       '0.05' );
eq( 'fmt zero',               XmrPay_Util::fmt( 0 ),            '0' );
eq( 'fmt negative -> 0',      XmrPay_Util::fmt( -5 ),           '0' );
eq( 'fmt 1 piconero',         XmrPay_Util::fmt( 0.000000000001 ), '0.000000000001' );
eq( 'fmt caps at 12 dec',     XmrPay_Util::fmt( 0.1234567890123 ), '0.123456789012' );
eq( 'fmt rounds at pico',     XmrPay_Util::fmt( 0.0000000000005 ), '0.000000000001' );
eq( 'fmt big amount',         XmrPay_Util::fmt( 12345.6789 ),   '12345.6789' );
// idempotent: formatting a formatted value is stable
$a = XmrPay_Util::fmt( 0.1 / 3 );
eq( 'fmt idempotent',         XmrPay_Util::fmt( $a ),           $a );
ok( 'fmt is always <=12 dec', (function(){ for($i=0;$i<200;$i++){ $v=mt_rand(1,9999999)/mt_rand(1,7919); $s=XmrPay_Util::fmt($v); $dot=strpos($s,'.'); if($dot!==false && (strlen($s)-$dot-1)>12) return false; } return true; })() );

// ---------- from_total: discounts/shipping/tax already in the total ----------
// $37.80 final (after a coupon + shipping + tax) at 150 USD/XMR
eq( 'total -> xmr',           XmrPay_Util::from_total( 37.80, 150 ), '0.252' );
eq( 'xmr-native passthrough', XmrPay_Util::from_total( 0.05, 1 ),    '0.05' );
eq( 'rate 0 -> 0 (guard)',    XmrPay_Util::from_total( 50, 0 ),      '0' );
eq( 'free order total 0',     XmrPay_Util::from_total( 0, 150 ),     '0' );
// the buyer pays exactly what the agent expects: same string, no drift
$total = 19.99; $rate = 142.37;
$amt = XmrPay_Util::from_total( $total, $rate );
eq( 'amount is re-fmt stable', XmrPay_Util::fmt( $amt ), $amt );
ok( 'amount never exceeds the fiat value', (float) $amt <= ( $total / $rate ) + 1e-12 );
// fuzz: random carts + rates always yield a clean, bounded XMR string
ok( 'fuzz totals/rates clean', (function(){
	for ( $i = 0; $i < 500; $i++ ) {
		$t = mt_rand( 1, 500000 ) / 100;           // $0.01 .. $5000
		$r = mt_rand( 50, 50000 ) / 100;           // 0.50 .. 500 per XMR
		$s = XmrPay_Util::from_total( $t, $r );
		if ( ! is_string( $s ) || $s === '' ) return false;
		if ( ! preg_match( '/^\d+(\.\d{1,12})?$/', $s ) ) return false;   // clean decimal, <=12
	}
	return true;
} )() );

// ---------- verify_sig: webhook HMAC (agent signs, plugin verifies) ----------
$secret = 'whsec_test_xmrpay';
$body   = '{"event":"order.paid","order_id":42,"amount_xmr":"0.05","received_xmr":"0.051"}';
// this is exactly how the Node lib (src/webhook.js) signs it
$good   = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
ok( 'verify accepts a valid signature', XmrPay_Util::verify_sig( $body, $good, $secret ) === true );
ok( 'verify rejects a tampered body',   XmrPay_Util::verify_sig( $body . ' ', $good, $secret ) === false );
ok( 'verify rejects wrong secret',      XmrPay_Util::verify_sig( $body, $good, 'nope' ) === false );
ok( 'verify rejects missing signature', XmrPay_Util::verify_sig( $body, '', $secret ) === false );
ok( 'verify rejects null signature',    XmrPay_Util::verify_sig( $body, null, $secret ) === false );
ok( 'empty secret = opt out (accept)',  XmrPay_Util::verify_sig( $body, 'anything', '' ) === true );
ok( 'verify is prefix-strict',          XmrPay_Util::verify_sig( $body, hash_hmac( 'sha256', $body, $secret ), $secret ) === false ); // no "sha256=" prefix

echo "\n" . ( $fail ? "FAIL" : "ALL GREEN" ) . " — $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
