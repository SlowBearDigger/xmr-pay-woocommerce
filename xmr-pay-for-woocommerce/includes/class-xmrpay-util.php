<?php
/**
 * Pure, dependency-free helpers — the parts of the gateway worth testing in
 * isolation: the money math (must be exact) and the webhook signature check.
 * No WordPress/WooCommerce calls here, so it runs under plain `php` in tests.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'XMRPAY_TESTING' ) ) { exit; }

class XmrPay_Util {

	/** Smallest Monero unit (piconero): 1 XMR = 1e12 pico. */
	const XMR_DECIMALS = 12;

	/**
	 * Canonical XMR decimal string: at most 12 decimals (piconero precision),
	 * trailing zeros trimmed, never empty. This is what the buyer pays AND what
	 * the agent is told to expect — one string, so they can never drift.
	 */
	public static function fmt( $xmr ) {
		$xmr = (float) $xmr;
		if ( ! is_finite( $xmr ) || $xmr <= 0 ) {
			return '0';
		}
		// work in piconero INTEGERS, not float decimals: number_format() at 12
		// places leaks the float64 error (e.g. 12345.6789 -> 12345.678900000001).
		// round() snaps to the nearest piconero, then we build the string with
		// integer math so no float tail can survive. (good to ~9.2M XMR < int64.)
		$pico = (int) round( $xmr * 1000000000000 );
		if ( $pico <= 0 ) {
			return '0';
		}
		$int  = intdiv( $pico, 1000000000000 );
		$frac = $pico % 1000000000000;
		if ( 0 === $frac ) {
			return (string) $int;
		}
		$fs = rtrim( str_pad( (string) $frac, self::XMR_DECIMALS, '0', STR_PAD_LEFT ), '0' );
		return $int . '.' . $fs;
	}

	/**
	 * Convert an order total to XMR at a rate (price of 1 XMR in the order's
	 * currency). The total already includes discounts, shipping, tax and fees —
	 * WooCommerce computes it before the gateway runs — so this needs no special
	 * handling for any of them. Returns a canonical XMR string.
	 */
	public static function from_total( $total, $rate ) {
		$rate = (float) $rate;
		if ( $rate <= 0 ) {
			return '0';
		}
		return self::fmt( (float) $total / $rate );
	}

	/**
	 * Verify an HMAC-SHA256 webhook signature (the agent signs `order.paid`).
	 * Header form: "sha256=<hex>". Constant-time compare. Requires a non-empty
	 * secret — returns false when none is configured so unconfigured stores
	 * reject all webhook requests rather than accepting them blindly.
	 */
	public static function verify_sig( $raw, $sig, $secret ) {
		if ( $secret === '' || $secret === null ) {
			return false;
		}
		if ( ! is_string( $sig ) || $sig === '' ) {
			return false;
		}
		$expected = 'sha256=' . hash_hmac( 'sha256', (string) $raw, (string) $secret );
		return hash_equals( $expected, $sig );
	}

	/**
	 * Is a signed webhook delivery fresh? `event_ts` is the agent's stamp in ms.
	 * A non-numeric/absent stamp returns true (older agents do not send it, and
	 * idempotency on order_id is the primary replay guard). Otherwise the delivery
	 * must be no older than $max_age_s seconds. The window is deliberately generous
	 * so honest deliveries are never rejected over modest clock skew.
	 */
	public static function event_fresh( $event_ts_ms, $now_s, $max_age_s = 86400 ) {
		if ( ! is_numeric( $event_ts_ms ) ) {
			return true;
		}
		return ( (float) $now_s - ( (float) $event_ts_ms / 1000.0 ) ) <= (float) $max_age_s;
	}

	/**
	 * May the TEST-ONLY test_amount override the cart total? Only when the agent is
	 * on a test network AND that network was confirmed against the SAME url in use
	 * now — so re-pointing the agent at mainnet without re-testing disables it,
	 * instead of riding a stale flag onto a live store.
	 */
	public static function test_amount_allowed( $network, $tested_url, $agent_url ) {
		if ( ! in_array( $network, array( 'stagenet', 'testnet' ), true ) ) {
			return false;
		}
		$tested = rtrim( trim( (string) $tested_url ), '/' );
		$agent  = rtrim( trim( (string) $agent_url ), '/' );
		return $tested !== '' && $tested === $agent;
	}

	/**
	 * Is $url on the same host as $home (or a host-less relative path)? Used to keep
	 * the order key out of a third-party post-payment redirect.
	 */
	public static function same_origin( $url, $home ) {
		$h = parse_url( (string) $url, PHP_URL_HOST );
		if ( empty( $h ) ) {
			return true;
		}
		$hh = parse_url( (string) $home, PHP_URL_HOST );
		return strtolower( $h ) === strtolower( (string) $hh );
	}
}
