<?php
define( 'ABSPATH', __DIR__ . '/' );
function __( $text, $domain = null ) { return $text; }
function _n( $single, $plural, $number, $domain = null ) { return 1 === (int) $number ? $single : $plural; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }
function current_user_can( $capability ) { return true; }
function check_ajax_referer( $action, $key, $die = true ) { return true; }
class JsonResponse extends RuntimeException { public $payload; public function __construct( $payload ) { $this->payload = $payload; parent::__construct( 'json response' ); } }
function wp_send_json_success( $data ) { throw new JsonResponse( array( 'success' => true, 'data' => $data ) ); }
function wp_send_json_error( $data ) { throw new JsonResponse( array( 'success' => false, 'data' => $data ) ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
class WP_Error {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_filter( $tag, $callback ) {}
$GLOBALS['filter_overrides'] = array();
function apply_filters( $tag, $value ) {
	$args = func_get_args();
	if ( isset( $GLOBALS['filter_overrides'][ $tag ] ) ) { return call_user_func_array( $GLOBALS['filter_overrides'][ $tag ], array_slice( $args, 1 ) ); }
	return $value;
}
$GLOBALS['curl_hooks'] = array();
function add_action( $tag, $callback, $priority = 10, $accepted = 1 ) { $GLOBALS['curl_hooks'][] = $callback; }
function remove_action( $tag, $callback, $priority = 10 ) { foreach ( $GLOBALS['curl_hooks'] as $i => $hook ) { if ( $hook === $callback ) { unset( $GLOBALS['curl_hooks'][$i] ); } } }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function e2e_request( $url, $args, $method ) {
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true ); curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $method );
	curl_setopt( $ch, CURLOPT_TIMEOUT, $args['timeout'] ); curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
	$headers = array(); foreach ( $args['headers'] ?? array() as $key => $value ) { $headers[] = $key . ': ' . $value; }
	$headers[] = 'X-Fixture-Auth: ' . ( $GLOBALS['fixture_auth'] ?? 'basic' );
	if ( $headers ) { curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers ); }
	if ( isset( $args['body'] ) ) { curl_setopt( $ch, CURLOPT_POSTFIELDS, $args['body'] ); }
	foreach ( $GLOBALS['curl_hooks'] as $hook ) { $hook( $ch, $args, $url ); }
	$body = curl_exec( $ch ); $code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	if ( false === $body ) { return new WP_Error(); }
	return array( 'response' => array( 'code' => $code ), 'body' => $body );
}
function wp_safe_remote_get( $url, $args ) { return e2e_request( $url, $args, 'GET' ); }
function wp_safe_remote_post( $url, $args ) { return e2e_request( $url, $args, 'POST' ); }
class WC_Payment_Gateway { public function get_option( $key, $default = null ) { return $default; } }
class XmrPay_Util { public static function crypto_ready() { return true; } }
require_once __DIR__ . '/../includes/class-xmrpay-node-config.php';
require_once __DIR__ . '/../includes/class-xmrpay-scanner.php';
require_once __DIR__ . '/../includes/class-wc-gateway-xmrpay.php';
$pass = 0; $fail = 0;
function ok( $name, $condition ) { global $pass, $fail; $condition ? $pass++ : $fail++; echo ( $condition ? 'PASS  ' : 'FAIL  ' ) . $name . "\n"; }

$reservation = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
if ( ! $reservation ) { fwrite( STDERR, "Unable to reserve loopback port: $error\n" ); exit( 1 ); }
$address = stream_socket_get_name( $reservation, false );
$port = (int) substr( strrchr( $address, ':' ), 1 );
fclose( $reservation );
$command = array( PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/auth-router.php' );
$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
if ( ! is_resource( $process ) ) { fwrite( STDERR, "Unable to start fixture server\n" ); exit( 1 ); }
try {
	$ready = false; for ( $i = 0; $i < 30; $i++ ) {
		$status = proc_get_status( $process );
		if ( ! $status['running'] ) { break; }
		$probe = @file_get_contents( 'http://127.0.0.1:' . $port . '/__xmrpay_ready' );
		if ( is_string( $probe ) && ( json_decode( $probe, true )['fixture'] ?? '' ) === 'xmrpay-auth-e2e-v1' ) { $ready = true; break; }
		usleep( 50000 );
	}
	ok( 'localhost fixture process and identity are verified', $ready && proc_get_status( $process )['running'] );
	foreach ( array( 'basic', 'digest' ) as $auth ) {
		$node = array( array( 'url' => 'http://127.0.0.1:' . $port, 'auth' => $auth, 'username' => 'synthetic-user', 'password' => 'synthetic test password' ) );
		$GLOBALS['fixture_auth'] = $auth;
		$scanner = new XmrPay_Scanner( $node, 'mainnet', 2 );
		ok( ucfirst( $auth ) . ' GET succeeds', 424242 === $scanner->tip_height() );
		$reflection = new ReflectionMethod( XmrPay_Scanner::class, 'json_rpc' );
		ok( ucfirst( $auth ) . ' POST succeeds', is_array( $reflection->invoke( $scanner, 'get_block', array( 'height' => 1 ) ) ) );
		$node[0]['password'] = 'wrong'; $bad = new XmrPay_Scanner( $node, 'mainnet', 2 ); $bad->tip_height();
		ok( ucfirst( $auth ) . ' wrong credentials return 401', 'unauthorized' === ( $bad->last_node_error()['code'] ?? '' ) );
	}
	foreach ( array( 'unauthorized', 'transport', 'digest_unavailable', 'http' ) as $code ) {
		$result = WC_Gateway_XmrPay::node_setup_diagnostic( array( 'code' => $code, 'status' => 503 ) );
		ok( 'typed setup diagnostic: ' . $code, isset( $result['code'], $result['msg'] ) && $code === $result['code'] );
		ok( 'diagnostic is secret-free: ' . $code, 0 === preg_match( '/synthetic-user|synthetic test password|Basic |CURLOPT_USERPWD/', json_encode( $result ) ) );
	}

	class DigestUnavailableScanner extends XmrPay_Scanner {
		protected function digest_auth_available() { return false; }
	}
	class FakeSetupScanner {
		private $info;
		private $error;
		public function __construct( $info, $error = array() ) { $this->info = $info; $this->error = $error; }
		public function node_info() { return $this->info; }
		public function last_node_error() { return $this->error; }
		public function verify_keys( $address, $view ) { return array( 'address_valid' => true, 'key_match' => true ); }
	}
	class SetupGateway extends WC_Gateway_XmrPay {
		public $force_digest_unavailable = false;
		public function __construct() {}
		protected function create_setup_scanner( $node, $network, $timeout = null ) {
			if ( $this->force_digest_unavailable ) { return new DigestUnavailableScanner( $node, $network, $timeout ?: 2 ); }
			return parent::create_setup_scanner( $node, $network, $timeout ?: 5 );
		}
	}
	class MultiSetupGateway extends WC_Gateway_XmrPay {
		public $scanner_count = 0;
		public $scanner_timeouts = array();
		public function __construct() {}
		protected function create_setup_scanner( $node, $network, $timeout = null ) {
			$this->scanner_count++;
			$this->scanner_timeouts[] = $timeout;
			$host = wp_parse_url( $node[0]['url'], PHP_URL_HOST );
			if ( false !== strpos( $host, 'healthy' ) ) {
				return new FakeSetupScanner( array( 'ok' => true, 'nettype' => 'mainnet', 'height' => 424242 ) );
			}
			if ( false !== strpos( $host, 'wrong-network' ) ) {
				return new FakeSetupScanner( array( 'ok' => true, 'nettype' => 'stagenet', 'height' => 424242 ) );
			}
			$code = false !== strpos( $host, 'unauthorized' ) ? 'unauthorized' : 'transport';
			return new FakeSetupScanner( array( 'ok' => false ), array( 'code' => $code ) );
		}
	}
	function run_setup_ajax( $gateway, $rows, $address = '', $view = '' ) {
		$_POST = array( 'node_configs' => json_encode( $rows ), 'address' => $address, 'view_key' => $view );
		try { $gateway->ajax_test_node(); } catch ( JsonResponse $response ) { return $response->payload; }
		return null;
	}
	function setup_check_by_code( $response, $code ) {
		foreach ( $response['data']['checks'] ?? array() as $check ) {
			if ( $code === ( $check['code'] ?? '' ) ) { return $check; }
		}
		return array();
	}
	$gateway = new SetupGateway(); $GLOBALS['fixture_auth'] = 'basic';
	$rows = array(
		array( 'url' => 'http://127.0.0.1:' . $port, 'auth' => 'basic', 'username' => 'synthetic-user', 'password' => 'wrong' ),
		array( 'url' => 'http://127.0.0.1:' . $port, 'auth' => 'basic', 'username' => 'synthetic-user', 'password' => 'synthetic test password' ),
	);
	$unauthorized = run_setup_ajax( $gateway, array( $rows[0] ) );
	ok( 'AJAX reports real 401', 'unauthorized' === ( setup_check_by_code( $unauthorized, 'unauthorized' )['code'] ?? '' ) );
	$rows[0]['url'] = 'http://127.0.0.1:1'; $transport = run_setup_ajax( $gateway, $rows );
	$transport_code = setup_check_by_code( $transport, 'transport' )['code'] ?? '';
	ok( 'AJAX reports real transport failure' . ( $transport_code ? ' (' . $transport_code . ')' : '' ), 'transport' === $transport_code );
	$gateway->force_digest_unavailable = true;
	$rows[0] = array( 'url' => 'http://127.0.0.1:' . $port, 'auth' => 'digest', 'username' => 'synthetic-user', 'password' => 'synthetic test password' );
	$digest_unavailable = run_setup_ajax( $gateway, $rows );
	ok( 'AJAX real scanner reports Digest-unavailable capability', 'digest_unavailable' === ( setup_check_by_code( $digest_unavailable, 'digest_unavailable' )['code'] ?? '' ) );
	$serialized = json_encode( array( $unauthorized, $transport, $digest_unavailable ) );
	$messages = array(
		setup_check_by_code( $unauthorized, 'unauthorized' )['msg'] ?? '',
		setup_check_by_code( $transport, 'transport' )['msg'] ?? '',
		setup_check_by_code( $digest_unavailable, 'digest_unavailable' )['msg'] ?? '',
	);
	ok( 'AJAX diagnostics have distinct messages', 3 === count( array_unique( $messages ) ) );
	ok( 'AJAX JSON leaks no credentials or auth headers', 0 === preg_match( '/synthetic-user|synthetic test password|Basic |Authorization:|CURLOPT_USERPWD/', $serialized ) );

	$multi = new MultiSetupGateway();
	$mixed_rows = array(
		array( 'url' => 'https://healthy-one.test:18081', 'auth' => 'none' ),
		array( 'url' => 'https://unauthorized.test:18082', 'auth' => 'basic', 'username' => 'multi-secret-user', 'password' => 'multi-secret-password' ),
		array( 'url' => 'https://offline.test:18083', 'auth' => 'none' ),
		array( 'url' => 'https://wrong-network.test:18084', 'auth' => 'none' ),
		array( 'url' => 'https://healthy-two.test:18085', 'auth' => 'none' ),
	);
	$mixed = run_setup_ajax( $multi, $mixed_rows, '4fake-address', 'fake-view-key' );
	$mixed_json = json_encode( $mixed );
	$warning_checks = array_filter( $mixed['data']['checks'] ?? array(), function ( $check ) { return ! empty( $check['warning'] ); } );
	ok( 'AJAX probes all five configured nodes', 5 === $multi->scanner_count );
	ok( 'five nodes use a two-second per-request timeout', array( 2, 2, 2, 2, 2 ) === $multi->scanner_timeouts );
	ok( 'mixed node health remains non-blocking when one node is usable', true === ( $mixed['data']['ok'] ?? null ) );
	ok( 'three unhealthy nodes are explicit warnings', 3 === count( $warning_checks ) );
	ok( 'mixed summary reports healthy and warning totals', false !== strpos( $mixed_json, '2 of 5 nodes are healthy' ) && false !== strpos( $mixed_json, '3 warning nodes' ) );
	ok( 'node warnings recommend review or replacement', 3 === count( array_filter( $warning_checks, function ( $check ) { return false !== strpos( strtolower( $check['msg'] ?? '' ), 'review' ) && false !== strpos( strtolower( $check['msg'] ?? '' ), 'replace' ); } ) ) );
	ok( 'multi-node JSON remains secret-free', 0 === preg_match( '/multi-secret-user|multi-secret-password|fake-view-key/', $mixed_json ) );
	$indexed_checks = array_filter( $mixed['data']['checks'] ?? array(), function ( $check ) { return isset( $check['node'] ); } );
	ok( 'every node result carries its index and measured latency', 5 === count( $indexed_checks ) && 5 === count( array_filter( $indexed_checks, function ( $check ) { return isset( $check['elapsed_ms'] ) && is_int( $check['elapsed_ms'] ) && $check['elapsed_ms'] >= 0; } ) ) );

	$all_bad = new MultiSetupGateway();
	$all_bad_result = run_setup_ajax( $all_bad, array_slice( $mixed_rows, 1, 3 ), '4fake-address', 'fake-view-key' );
	ok( 'all-unusable nodes are blocking', false === ( $all_bad_result['data']['ok'] ?? null ) );

	$capped = new MultiSetupGateway();
	$twelve_rows = array();
	for ( $i = 1; $i <= 12; $i++ ) { $twelve_rows[] = array( 'url' => 'https://healthy-' . $i . '.test:18' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ), 'auth' => 'none' ); }
	$capped_result = run_setup_ajax( $capped, $twelve_rows, '4fake-address', 'fake-view-key' );
	$capped_json = json_encode( $capped_result );
	ok( 'AJAX caps setup probes at ten nodes', 10 === $capped->scanner_count );
	ok( 'ten nodes use a bounded one-second timeout', array_fill( 0, 10, 1 ) === $capped->scanner_timeouts );
	ok( 'cap is disclosed without blocking healthy nodes', true === ( $capped_result['data']['ok'] ?? null ) && false !== strpos( $capped_json, 'first 10 nodes' ) );

	$single_default = new MultiSetupGateway();
	run_setup_ajax( $single_default, array( $mixed_rows[0] ), '4fake-address', 'fake-view-key' );
	ok( 'one node keeps WordPress standard five-second timeout', array( 5 ) === $single_default->scanner_timeouts );

	$filtered_high = new MultiSetupGateway();
	$GLOBALS['filter_overrides']['xmrpay_setup_node_timeout'] = function () { return 99; };
	run_setup_ajax( $filtered_high, array( $mixed_rows[0] ), '4fake-address', 'fake-view-key' );
	ok( 'advanced timeout filter is clamped to ten seconds', array( 10 ) === $filtered_high->scanner_timeouts );
	$filtered_low = new MultiSetupGateway();
	$GLOBALS['filter_overrides']['xmrpay_setup_node_timeout'] = function () { return -5; };
	run_setup_ajax( $filtered_low, array( $mixed_rows[0] ), '4fake-address', 'fake-view-key' );
	ok( 'advanced timeout filter is clamped to one second', array( 1 ) === $filtered_low->scanner_timeouts );
	unset( $GLOBALS['filter_overrides']['xmrpay_setup_node_timeout'] );

	$admin_js = file_get_contents( __DIR__ . '/../assets/admin.js' );
	$wizard_js = file_get_contents( __DIR__ . '/../assets/wizard.js' );
	ok( 'admin and wizard render warnings distinctly', false !== strpos( $admin_js, "c.warning" ) && false !== strpos( $admin_js, "⚠" ) && false !== strpos( $wizard_js, "c.warning" ) && false !== strpos( $wizard_js, "⚠" ) );
	ok( 'admin and wizard expose live timer and per-card results', false !== strpos( $admin_js, 'formatElapsed' ) && false !== strpos( $admin_js, 'setChecking' ) && false !== strpos( $admin_js, 'applyResults' ) && false !== strpos( $wizard_js, 'formatElapsed' ) && false !== strpos( $wizard_js, 'setChecking' ) && false !== strpos( $wizard_js, 'applyResults' ) );
} finally { proc_terminate( $process ); foreach ( $pipes as $pipe ) { fclose( $pipe ); } proc_close( $process ); }
echo "\n" . ( $fail ? 'FAILED' : 'ALL GREEN' ) . ": $pass passed, $fail failed\n"; exit( $fail ? 1 : 0 );
