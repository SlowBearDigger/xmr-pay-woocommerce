<?php
define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/fixtures/auth-router.php';

class WP_Error { public function __construct( public $code ) {} }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
if ( ! defined( 'CURLOPT_HTTPAUTH' ) ) { define( 'CURLOPT_HTTPAUTH', 107 ); }
if ( ! defined( 'CURLOPT_USERPWD' ) ) { define( 'CURLOPT_USERPWD', 10005 ); }
if ( ! defined( 'CURLAUTH_DIGEST' ) ) { define( 'CURLAUTH_DIGEST', 2 ); }
if ( ! function_exists( 'curl_setopt' ) ) { function curl_setopt( $handle, $option, $value ) { $GLOBALS['curl_options'][ $option ] = $value; return true; } }

$GLOBALS['auth_requests'] = array();
$GLOBALS['auth_responses'] = array();
$GLOBALS['auth_hooks'] = array();
$GLOBALS['curl_options'] = array();
$GLOBALS['mismatched_origin_options'] = null;
function add_filter( $tag, $callback ) { $GLOBALS['auth_hooks'][ $tag ][] = $callback; }
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) { add_filter( $tag, $callback ); }
function remove_action( $tag, $callback, $priority = 10 ) {
	if ( ! isset( $GLOBALS['auth_hooks'][ $tag ] ) ) { return false; }
	foreach ( $GLOBALS['auth_hooks'][ $tag ] as $key => $registered ) {
		if ( $registered === $callback ) { unset( $GLOBALS['auth_hooks'][ $tag ][ $key ] ); return true; }
	}
	return false;
}
function wp_safe_remote_post( $url, $args ) { return auth_request( 'POST', $url, $args ); }
function wp_safe_remote_get( $url, $args ) { return auth_request( 'GET', $url, $args ); }
function auth_request( $method, $url, $args ) {
	$GLOBALS['auth_requests'][] = compact( 'method', 'url', 'args' );
	$handle = new class { public function setopt( $option, $value ) { $GLOBALS['curl_options'][ $option ] = $value; } };
	if ( isset( $GLOBALS['auth_probe_url'] ) ) {
		foreach ( $GLOBALS['auth_hooks']['http_api_curl'] ?? array() as $hook ) { $hook( $handle, $args, $GLOBALS['auth_probe_url'] ); }
		$GLOBALS['mismatched_origin_options'] = $GLOBALS['curl_options'];
	}
	foreach ( $GLOBALS['auth_hooks']['http_api_curl'] ?? array() as $hook ) { $hook( $handle, $args, $url ); }
	return array_shift( $GLOBALS['auth_responses'] );
}

require_once __DIR__ . '/../includes/class-xmrpay-node-config.php';
require_once __DIR__ . '/../includes/class-xmrpay-scanner.php';

$pass = 0; $fail = 0;
function ok( $name, $condition ) { global $pass, $fail; $condition ? $pass++ : $fail++; echo ( $condition ? 'PASS  ' : 'FAIL  ' ) . $name . "\n"; }
function reset_auth_test() { $GLOBALS['auth_requests'] = array(); $GLOBALS['auth_responses'] = array(); $GLOBALS['auth_hooks']['http_api_curl'] = array(); $GLOBALS['curl_options'] = array(); $GLOBALS['mismatched_origin_options'] = null; unset( $GLOBALS['auth_probe_url'] ); }

$port_scanner_a = new XmrPay_Scanner( array( array( 'url' => 'http://node-a.test:18081', 'auth' => 'none' ) ) );
$port_scanner_b = new XmrPay_Scanner( array( array( 'url' => 'http://node-b.test:38089', 'auth' => 'none' ) ) );
$safe_ports = array( 80, 443 );
foreach ( $GLOBALS['auth_hooks']['http_allowed_safe_ports'] ?? array() as $port_filter ) { $safe_ports = $port_filter( $safe_ports ); }
ok( 'configured ports accumulate across scanner instances', in_array( 18081, $safe_ports, true ) && in_array( 38089, $safe_ports, true ) && 1 === count( array_keys( $safe_ports, 18081, true ) ) && 1 === count( array_keys( $safe_ports, 38089, true ) ) );
ok( 'safe-port filter is registered only once', 1 === count( $GLOBALS['auth_hooks']['http_allowed_safe_ports'] ?? array() ) );

$basic = array( array( 'url' => 'https://node.test:443', 'auth' => 'basic', 'username' => 'alice', 'password' => 'secret' ) );
reset_auth_test();
$GLOBALS['auth_responses'][] = auth_test_response( 200, array( 'height' => 12 ) );
$scanner = new XmrPay_Scanner( $basic );
$scanner->tip_height();
$get = $GLOBALS['auth_requests'][0]['args'];
ok( 'GET sends Basic authorization and disables redirects', ( $get['headers']['Authorization'] ?? '' ) === 'Basic ' . base64_encode( 'alice:secret' ) && $get['redirection'] === 0 );

reset_auth_test();
$GLOBALS['auth_responses'][] = auth_test_response( 200, array( 'txs' => array() ) );
$scanner = new XmrPay_Scanner( $basic );
$scanner->fetch_txs( array( 'abc' ) );
$post = $GLOBALS['auth_requests'][0]['args'];
ok( 'POST sends Basic authorization and disables redirects', ( $post['headers']['Authorization'] ?? '' ) === 'Basic ' . base64_encode( 'alice:secret' ) && $post['redirection'] === 0 );

reset_auth_test();
$digest = array( array( 'url' => 'https://digest.test:443', 'auth' => 'digest', 'username' => 'bob', 'password' => 'hidden' ) );
$GLOBALS['auth_probe_url'] = 'https://redirect.test:443/get_height';
$GLOBALS['auth_responses'][] = auth_test_response( 200, array( 'height' => 14 ) );
$scanner = new XmrPay_Scanner( $digest );
$scanner->tip_height();
ok( 'Digest ignores a mismatched-origin cURL callback', $GLOBALS['mismatched_origin_options'] === array() );
ok( 'Digest configures cURL auth and credentials', defined( 'CURLOPT_HTTPAUTH' ) && ( $GLOBALS['curl_options'][ CURLOPT_HTTPAUTH ] ?? null ) === CURLAUTH_DIGEST && ( $GLOBALS['curl_options'][ CURLOPT_USERPWD ] ?? null ) === 'bob:hidden' );
ok( 'Digest hook is removed after success', empty( $GLOBALS['auth_hooks']['http_api_curl'] ) );

reset_auth_test();
$GLOBALS['auth_responses'][] = new WP_Error( 'timeout' );
$scanner = new XmrPay_Scanner( $digest );
$scanner->tip_height();
ok( 'Digest hook is removed after transport error', empty( $GLOBALS['auth_hooks']['http_api_curl'] ) );
ok( 'transport failure has typed diagnostics without secrets', $scanner->last_node_error()['code'] === 'transport' && strpos( json_encode( $scanner->last_node_error() ), 'hidden' ) === false );

reset_auth_test();
$GLOBALS['auth_responses'][] = auth_test_response( 401, array( 'error' => 'no' ) );
$scanner = new XmrPay_Scanner( $basic );
$scanner->tip_height();
ok( '401 has unauthorized diagnostics', $scanner->last_node_error()['code'] === 'unauthorized' );

reset_auth_test();
$nodes = array( $basic[0], array( 'url' => 'https://second.test', 'auth' => 'none' ) );
$GLOBALS['auth_responses'][] = new WP_Error( 'timeout' );
$GLOBALS['auth_responses'][] = auth_test_response( 200, array( 'height' => 20 ) );
$scanner = new XmrPay_Scanner( $nodes );
$scanner->tip_height();
ok( 'failover does not reuse credentials', ! isset( $GLOBALS['auth_requests'][1]['args']['headers']['Authorization'] ) );

echo "\n" . ( $fail ? 'FAILED' : 'ALL GREEN' ) . ": $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
