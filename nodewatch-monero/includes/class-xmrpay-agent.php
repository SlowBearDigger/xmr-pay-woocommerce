<?php
// Call the local authenticated payment agent.


if ( ! defined( 'ABSPATH' ) ) { exit; }

class XmrPay_Agent {

	private $url;
	private $token;

	public function __construct( $url, $token = '' ) {
		$url         = class_exists( 'XmrPay_Util' ) ? XmrPay_Util::normalize_agent_url( $url ) : trim( (string) $url );
		$this->url   = untrailingslashit( $url );
		$this->token = trim( (string) $token );
	}

	private function headers() {
		$h = array( 'Content-Type' => 'application/json' );
		if ( $this->token !== '' ) {
			$h['Authorization'] = 'Bearer ' . $this->token;
		}
		return $h;
	}

	public function create_order( $amount, $id, $label = '' ) {
		$res = wp_remote_post( $this->url . '/order', array(
			'timeout' => 20,
			'headers' => $this->headers(),
			'body'    => wp_json_encode( array(
				'id'     => (string) $id,
				'amount' => (string) $amount,
				'label'  => (string) $label,
			) ),
		) );
		return $this->decode( $res, array( 200 ) );
	}

	public function get_order( $id, $timeout = 20 ) {
		$res = wp_remote_get( $this->url . '/order/' . rawurlencode( (string) $id ), array(
			'timeout' => max( 2, (int) $timeout ),
			'headers' => $this->headers(),
		) );
		return $this->decode( $res, array( 200 ) );
	}

	public function get_receipt( $id, $timeout = 20 ) {
		$res = wp_remote_get( $this->url . '/receipt/' . rawurlencode( (string) $id ), array(
			'timeout' => max( 2, (int) $timeout ),
			'headers' => $this->headers(),
		) );
		return $this->decode( $res, array( 200 ) );
	}

	public function healthz() {
		$res = wp_remote_get( $this->url . '/healthz', array( 'timeout' => 10, 'headers' => $this->headers() ) );
		return $this->decode( $res, array( 200 ) );
	}

	private function decode( $res, $ok_codes ) {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! in_array( $code, $ok_codes, true ) ) {
			$msg = is_array( $body ) && isset( $body['error'] ) ? $body['error'] : ( 'agent HTTP ' . $code );
			return new WP_Error( 'xmrpay_agent', $msg, array( 'status' => $code ) );
		}
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'xmrpay_agent', 'agent returned a non-JSON body' );
		}
		return $body;
	}
}
