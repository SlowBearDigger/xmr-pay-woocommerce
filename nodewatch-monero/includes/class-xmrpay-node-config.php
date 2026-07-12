<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class XmrPay_Node_Config {
	private function __construct() {}

	public static function normalize_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\s*,\s*/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY );
		}
		if ( ! is_array( $value ) ) { return array(); }

		$out = array();
		foreach ( $value as $row ) {
			if ( is_string( $row ) ) { $row = array( 'url' => $row ); }
			if ( ! is_array( $row ) ) { continue; }
			$url = self::normalize_url( $row['url'] ?? '' );
			if ( null === $url ) { continue; }
			$auth = strtolower( trim( (string) ( $row['auth'] ?? 'none' ) ) );
			if ( ! in_array( $auth, array( 'none', 'basic', 'digest' ), true ) ) { $auth = 'none'; }
			$out[] = array(
				'url'      => $url,
				'auth'     => $auth,
				'username' => 'none' === $auth ? '' : trim( (string) ( $row['username'] ?? '' ) ),
				'password' => 'none' === $auth ? '' : (string) ( $row['password'] ?? '' ),
			);
		}
		return $out;
	}

	public static function sanitize_submission( $rows, $saved_rows ) {
		if ( ! is_array( $rows ) ) { return new WP_Error( 'xmrpay_invalid_nodes', 'Invalid node configuration.' ); }
		$saved = self::normalize_list( $saved_rows );
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { return new WP_Error( 'xmrpay_invalid_node', 'Invalid node configuration.' ); }
			$url = self::normalize_url( $row['url'] ?? '' );
			if ( null === $url ) { return new WP_Error( 'xmrpay_invalid_node_url', 'Node URL must use HTTP or HTTPS without embedded credentials.' ); }
			$auth = strtolower( trim( (string) ( $row['auth'] ?? 'none' ) ) );
			if ( ! in_array( $auth, array( 'none', 'basic', 'digest' ), true ) ) { return new WP_Error( 'xmrpay_invalid_node_auth', 'Invalid node authentication mode.' ); }
			$username = 'none' === $auth ? '' : trim( (string) ( $row['username'] ?? '' ) );
			$password = 'none' === $auth ? '' : (string) ( $row['password'] ?? '' );
			if ( 'none' !== $auth && '' === $password ) {
				foreach ( $saved as $old ) {
					if ( self::same( $url, $old['url'] ) && self::same( $auth, $old['auth'] ) && self::same( $username, $old['username'] ) ) {
						$password = $old['password']; break;
					}
				}
			}
			if ( 'none' !== $auth && ( '' === $username || '' === $password ) ) { return new WP_Error( 'xmrpay_missing_node_credentials', 'Username and password are required.' ); }
			$out[] = compact( 'url', 'auth', 'username', 'password' );
		}
		return $out;
	}

	public static function legacy_urls( $rows ): string {
		return implode( ',', array_column( self::normalize_list( $rows ), 'url' ) );
	}

	private static function normalize_url( $value ) {
		$url = trim( (string) $value );
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) { return null; }
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) { return null; }
		$url = function_exists( 'esc_url_raw' ) ? esc_url_raw( $url ) : $url;
		return rtrim( $url, '/' );
	}

	private static function same( $left, $right ): bool {
		return hash_equals( (string) $left, (string) $right );
	}
}
