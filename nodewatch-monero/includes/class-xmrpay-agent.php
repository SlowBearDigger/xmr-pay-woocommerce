<?php
/**
 * Thin HTTP client for the xmr-pay scanner-agent.
 *
 * The agent (Node + monero-ts) does all the Monero work — view-only scanning,
 * per-order subaddresses, summing, the time-lock gate. This plugin never touches
 * Monero crypto: it just creates orders and reads their status over HTTP. The
 * agent runs on the merchant's own box (localhost); the buyer's
 * browser never reaches it — the plugin proxies status checks server-side.
 *
 * @link https://github.com/SlowBearDigger/xmr-pay  (docs/AGENT.md)
 */

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

	/**
	 * Create an order → returns the per-order subaddress to show the buyer.
	 * @return array|WP_Error { id, address, amount, status, birthdayHeight }
	 */
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

	/**
	 * Live status of an order.
	 * @return array|WP_Error { paid, status, receivedXmr, shortfallXmr, confirmations, txids }
	 */
	public function get_order( $id, $timeout = 20 ) {
		$res = wp_remote_get( $this->url . '/order/' . rawurlencode( (string) $id ), array(
			'timeout' => max( 2, (int) $timeout ),
			'headers' => $this->headers(),
		) );
		return $this->decode( $res, array( 200 ) );
	}

	/**
	 * The signed receipt for a paid order — the envelope to hand the buyer.
	 * @return array|WP_Error the signed receipt envelope { typ, receipt, pubkey, fingerprint, sig }
	 */
	public function get_receipt( $id, $timeout = 20 ) {
		$res = wp_remote_get( $this->url . '/receipt/' . rawurlencode( (string) $id ), array(
			'timeout' => max( 2, (int) $timeout ),
			'headers' => $this->headers(),
		) );
		return $this->decode( $res, array( 200 ) );
	}

	/** Liveness — used by the settings "Test connection" button. */
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
