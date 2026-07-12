<?php

function auth_test_response( $code, $body ) {
	return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body ) );
}

if ( PHP_SAPI === 'cli-server' ) {
	if ( '/__xmrpay_ready' === parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ) {
		header( 'Content-Type: application/json' ); echo json_encode( array( 'fixture' => 'xmrpay-auth-e2e-v1' ) ); return;
	}
	$user = 'synthetic-user';
	$pass = 'synthetic test password';
	$mode = isset( $_SERVER['HTTP_X_FIXTURE_AUTH'] ) ? $_SERVER['HTTP_X_FIXTURE_AUTH'] : 'basic';
	$authorized = false;
	if ( 'basic' === $mode ) {
		$authorized = isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] )
			&& hash_equals( $user, $_SERVER['PHP_AUTH_USER'] ) && hash_equals( $pass, $_SERVER['PHP_AUTH_PW'] );
		if ( ! $authorized ) { header( 'WWW-Authenticate: Basic realm="xmrpay-test"' ); }
	} else {
		$realm = 'xmrpay-test';
		$digest = isset( $_SERVER['PHP_AUTH_DIGEST'] ) ? $_SERVER['PHP_AUTH_DIGEST'] : '';
		$parts = array();
		preg_match_all( '@(\w+)=(?:"([^"]+)"|([^,]+))@', $digest, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) { $parts[ $match[1] ] = '' !== $match[2] ? $match[2] : trim( $match[3] ); }
		if ( isset( $parts['username'], $parts['nonce'], $parts['uri'], $parts['response'], $parts['nc'], $parts['cnonce'], $parts['qop'] ) && hash_equals( $user, $parts['username'] ) ) {
			$a1 = md5( $user . ':' . $realm . ':' . $pass );
			$a2 = md5( $_SERVER['REQUEST_METHOD'] . ':' . $parts['uri'] );
			$expected = md5( $a1 . ':' . $parts['nonce'] . ':' . $parts['nc'] . ':' . $parts['cnonce'] . ':' . $parts['qop'] . ':' . $a2 );
			$authorized = hash_equals( $expected, $parts['response'] );
		}
		if ( ! $authorized ) { header( 'WWW-Authenticate: Digest realm="' . $realm . '",qop="auth",nonce="fixed-test-nonce",opaque="fixed-test-opaque"' ); }
	}
	if ( ! $authorized ) { http_response_code( 401 ); header( 'Content-Type: application/json' ); echo json_encode( array( 'error' => 'unauthorized' ) ); return; }
	header( 'Content-Type: application/json' );
	$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
	if ( '/get_height' === $path ) { echo json_encode( array( 'height' => 424242 ) ); return; }
	if ( '/json_rpc' === $path ) { echo json_encode( array( 'jsonrpc' => '2.0', 'id' => '0', 'result' => array( 'height' => 424242, 'tx_hashes' => array() ) ) ); return; }
	http_response_code( 404 ); echo json_encode( array( 'error' => 'not found' ) );
}
