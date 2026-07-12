<?php
define( 'ABSPATH', __DIR__ . '/' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public function __construct( $code ) { $this->code = $code; }
		public function get_error_code() { return $this->code; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $url ) { return filter_var( $url, FILTER_SANITIZE_URL ); } }

require_once __DIR__ . '/../includes/class-xmrpay-node-config.php';

$pass = 0; $fail = 0;
function ok( $name, $condition ) { global $pass, $fail; $condition ? $pass++ : $fail++; echo ( $condition ? 'PASS  ' : 'FAIL  ' ) . $name . "\n"; }

$legacy = XmrPay_Node_Config::normalize_list( ' https://one.test:18081, http://two.test ' );
ok( 'legacy string becomes unauthenticated rows', count( $legacy ) === 2 && $legacy[0]['auth'] === 'none' && $legacy[0]['username'] === '' && $legacy[0]['password'] === '' );
$urls = XmrPay_Node_Config::normalize_list( array( 'https://one.test', 'http://two.test' ) );
ok( 'URL array becomes unauthenticated rows', count( $urls ) === 2 && $urls[1]['auth'] === 'none' );

$structured = XmrPay_Node_Config::normalize_list( array( array( 'url' => 'https://node.test:443/', 'auth' => 'basic', 'username' => 'alice', 'password' => 'secret' ) ) );
ok( 'structured row is normalized', $structured === array( array( 'url' => 'https://node.test:443', 'auth' => 'basic', 'username' => 'alice', 'password' => 'secret' ) ) );
ok( 'invalid scheme is rejected', is_wp_error( XmrPay_Node_Config::sanitize_submission( array( array( 'url' => 'ftp://node.test' ) ), array() ) ) );
ok( 'embedded URL credentials are rejected', is_wp_error( XmrPay_Node_Config::sanitize_submission( array( array( 'url' => 'https://bob:secret@node.test' ) ), array() ) ) );
ok( 'Basic requires username and password', is_wp_error( XmrPay_Node_Config::sanitize_submission( array( array( 'url' => 'https://node.test', 'auth' => 'basic', 'username' => '', 'password' => '' ) ), array() ) ) );
ok( 'Digest requires username and password', is_wp_error( XmrPay_Node_Config::sanitize_submission( array( array( 'url' => 'https://node.test', 'auth' => 'digest', 'username' => 'bob', 'password' => '' ) ), array() ) ) );

$saved = array( array( 'url' => 'https://node.test/', 'auth' => 'basic', 'username' => 'alice', 'password' => 'old-secret' ) );
$preserved = XmrPay_Node_Config::sanitize_submission( array( array( 'url' => 'https://node.test', 'auth' => 'basic', 'username' => 'alice', 'password' => '' ) ), $saved );
ok( 'blank password preserves unchanged identity password', ! is_wp_error( $preserved ) && $preserved[0]['password'] === 'old-secret' );
ok( 'blank password does not preserve changed username', is_wp_error( XmrPay_Node_Config::sanitize_submission( array( array( 'url' => 'https://node.test', 'auth' => 'basic', 'username' => 'mallory', 'password' => '' ) ), $saved ) ) );
$none = XmrPay_Node_Config::sanitize_submission( array( array( 'url' => 'https://node.test', 'auth' => 'none', 'username' => 'alice', 'password' => 'submitted' ) ), $saved );
ok( 'none clears credentials', $none[0]['username'] === '' && $none[0]['password'] === '' );
$mirror = XmrPay_Node_Config::legacy_urls( array( array( 'url' => 'https://node.test', 'auth' => 'basic', 'username' => 'alice', 'password' => 'secret' ) ) );
ok( 'legacy mirror contains URLs and no secrets', $mirror === 'https://node.test' && strpos( $mirror, 'alice' ) === false && strpos( $mirror, 'secret' ) === false );

echo "\n" . ( $fail ? 'FAILED' : 'ALL GREEN' ) . ": $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
