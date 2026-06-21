<?php
/**
 * XmrPay_Report static mapping: order -> row, canonical-state derivation, CSV-injection
 * defense, and the column schema. A minimal FakeOrder stands in for a WC_Order (the static
 * methods only call order accessors, no WP functions). No WordPress needed:
 *   php tests/report.test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../includes/class-xmrpay-report.php';

$pass = 0; $fail = 0;
function ok( $name, $cond, $extra = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $name\n"; }
	else { $fail++; echo "FAIL  $name" . ( $extra !== '' ? "  — $extra" : '' ) . "\n"; }
}
function eq( $name, $got, $want ) { ok( $name, $got === $want, "got '" . var_export( $got, true ) . "' want '" . var_export( $want, true ) . "'" ); }

class FakeOrder {
	private $meta; private $paid; private $status; private $num;
	public function __construct( $meta, $paid, $status, $num ) { $this->meta = $meta; $this->paid = $paid; $this->status = $status; $this->num = $num; }
	public function get_meta( $k ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function is_paid() { return $this->paid; }
	public function get_status() { return $this->status; }
	public function get_order_number() { return $this->num; }
	public function get_date_created() { return null; }
}

// ---------- column schema ----------
eq( 'columns count', count( XmrPay_Report::columns() ), 11 );
ok( 'columns include state + refund_status',
	in_array( 'state', XmrPay_Report::columns(), true ) && in_array( 'refund_status', XmrPay_Report::columns(), true ) );

// ---------- csv_safe (formula-injection defense) ----------
eq( '=SUM neutralised', XmrPay_Report::csv_safe( '=SUM(A1)' ), "'=SUM(A1)" );
eq( '+1 neutralised',   XmrPay_Report::csv_safe( '+1' ),        "'+1" );
eq( '-1 neutralised',   XmrPay_Report::csv_safe( '-1' ),        "'-1" );
eq( '@cmd neutralised', XmrPay_Report::csv_safe( '@cmd' ),      "'@cmd" );
eq( 'address untouched', XmrPay_Report::csv_safe( '45sEohky' ), '45sEohky' );
eq( 'empty stays empty', XmrPay_Report::csv_safe( '' ),         '' );

// ---------- order_row + derive_state across the lifecycle ----------
$paid = new FakeOrder( array( '_xmrpay_amount' => '0.05', '_xmrpay_received' => '0.05', '_xmrpay_confirmations' => '3', '_xmrpay_mode' => 'watch', '_xmrpay_txids' => 'aa' ), true, 'completed', '101' );
$r = XmrPay_Report::order_row( $paid );
eq( 'paid -> settled',        $r['state'], 'settled' );
eq( 'owed mapped',            $r['owed_xmr'], '0.05' );
eq( 'received mapped',        $r['received_xmr'], '0.05' );
eq( 'confirmations mapped',   $r['confirmations'], '3' );
eq( 'mode mapped',            $r['mode'], 'watch' );
eq( 'overpaid blank if not flagged', $r['overpaid_xmr'], '' );

eq( 'on-hold + funds -> processing',
	XmrPay_Report::order_row( new FakeOrder( array( '_xmrpay_amount' => '0.05', '_xmrpay_received' => '0.02' ), false, 'on-hold', '102' ) )['state'], 'processing' );

$fresh = new FakeOrder( array( '_xmrpay_amount' => '0.05', '_xmrpay_received' => '' ), false, 'on-hold', '103' );
eq( 'on-hold + no funds -> created', XmrPay_Report::order_row( $fresh )['state'], 'created' );
eq( 'received defaults to 0',        XmrPay_Report::order_row( $fresh )['received_xmr'], '0' );

eq( 'cancelled -> expired',
	XmrPay_Report::order_row( new FakeOrder( array( '_xmrpay_amount' => '0.05' ), false, 'cancelled', '104' ) )['state'], 'expired' );
eq( 'failed -> expired',
	XmrPay_Report::order_row( new FakeOrder( array( '_xmrpay_amount' => '0.05' ), false, 'failed', '105' ) )['state'], 'expired' );

$refunded = new FakeOrder( array( '_xmrpay_amount' => '0.05', '_xmrpay_refund_status' => 'sent' ), false, 'refunded', '106' );
$rr = XmrPay_Report::order_row( $refunded );
eq( 'refunded -> settled (sale happened)', $rr['state'], 'settled' );
eq( 'refund_status carried',               $rr['refund_status'], 'sent' );

eq( 'overpaid shown when flagged',
	XmrPay_Report::order_row( new FakeOrder( array( '_xmrpay_amount' => '0.05', '_xmrpay_received' => '0.06', '_xmrpay_overpaid' => 'yes', '_xmrpay_overpaid_xmr' => '0.01' ), true, 'completed', '107' ) )['overpaid_xmr'], '0.01' );

echo "\n" . ( $fail ? 'FAILED' : 'ALL GREEN' ) . "  $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
