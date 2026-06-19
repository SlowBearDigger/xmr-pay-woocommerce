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
		return self::pico_to_string( (int) round( $xmr * 1000000000000 ) );
	}

	/** A canonical XMR amount as exact piconero (int). 0 for non-positive/garbage. */
	public static function xmr_to_pico( $xmr ) {
		$xmr = (float) $xmr;
		if ( ! is_finite( $xmr ) || $xmr <= 0 ) {
			return 0;
		}
		return (int) round( $xmr * 1000000000000 );
	}

	/**
	 * Exact piconero -> canonical XMR string (trailing zeros trimmed). GMP throughout so
	 * a received amount above ~9.2M XMR (past signed int64) is still formatted correctly,
	 * not truncated. Accepts an int, a numeric string, or a GMP-decimal string.
	 */
	public static function pico_to_string( $pico ) {
		$p = gmp_init( (string) $pico, 10 );
		if ( gmp_cmp( $p, 0 ) <= 0 ) {
			return '0';
		}
		$denom = gmp_init( '1000000000000', 10 );
		$int   = gmp_strval( gmp_div_q( $p, $denom ) );
		$frac  = (int) gmp_strval( gmp_mod( $p, $denom ) );   // < 1e12, fits an int safely
		if ( 0 === $frac ) {
			return $int;
		}
		$fs = rtrim( str_pad( (string) $frac, self::XMR_DECIMALS, '0', STR_PAD_LEFT ), '0' );
		return $int . '.' . $fs;
	}

	/**
	 * Make an order's amount UNIQUE by appending a random piconero nonce — so a
	 * buyer's proof binds to exactly one order and a valid proof can't be replayed
	 * across orders (proof mode, no view key; mirrors xmr-pay/core makeAmountNonce).
	 * Adds a crypto-random 1..(10^digits - 1) pico to the exact base. The amount the
	 * buyer sees barely moves; on-chain it's unmistakable. Integer math throughout —
	 * the nonce is added in piconero space so no float tail can swallow it.
	 */
	public static function nonce_amount( $xmr, $digits = 6 ) {
		$digits = (int) $digits;
		if ( $digits < 1 || $digits > 8 ) {
			$digits = 6;
		}
		$base = self::xmr_to_pico( $xmr );
		if ( $base <= 0 ) {
			return '0';
		}
		$span  = ( 10 ** $digits ) - 1;          // nonce in 1..span piconero
		$nonce = function_exists( 'random_int' ) ? random_int( 1, $span ) : mt_rand( 1, $span );
		return self::pico_to_string( $base + $nonce );
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
	 * Classify a payment from EXACT piconero (string/int) — the money decision, done in
	 * GMP so it is correct up to uint64 (PHP int overflows past ~9.2M XMR). Mirrors the
	 * lib's verify.js classifyResult. Inputs are piconero; confirmations/flags from chain.
	 *
	 * Returns ['status'=>'pending|locked|underpaid|mempool|unconfirmed|paid', 'paid'=>bool,
	 *          'overpaid_pico'=>string, 'shortfall_pico'=>string].
	 *
	 * Order of gates: no funds → pending; time-locked → locked; below the (tolerant)
	 * threshold → underpaid; not enough confirmations → mempool/unconfirmed; else paid.
	 * Tolerance is CLAMPED so the threshold can never drop to 0 — a zero payment never
	 * settles an order, no matter how the merchant mis-sets tolerance.
	 */
	public static function classify_payment( $expected_pico, $received_pico, $tolerance_pico, $min_conf, $confirmations, $in_pool, $locked ) {
		$exp = gmp_init( (string) $expected_pico, 10 );
		$rec = gmp_init( (string) $received_pico, 10 );
		$tol = gmp_init( (string) $tolerance_pico, 10 );
		if ( gmp_cmp( $tol, 0 ) < 0 ) { $tol = gmp_init( 0 ); }
		// clamp: tolerance can never reach the price → threshold stays >= 1 piconero.
		$max_tol = gmp_sub( $exp, gmp_init( 1 ) );
		if ( gmp_cmp( $exp, 0 ) <= 0 ) { $max_tol = gmp_init( 0 ); }
		if ( gmp_cmp( $tol, $max_tol ) > 0 ) { $tol = $max_tol; }
		$threshold = gmp_sub( $exp, $tol );

		$shortfall = gmp_cmp( $exp, $rec ) > 0 ? gmp_strval( gmp_sub( $exp, $rec ) ) : '0';

		if ( gmp_cmp( $rec, 0 ) <= 0 ) {
			return array( 'status' => 'pending', 'paid' => false, 'overpaid_pico' => '0', 'shortfall_pico' => gmp_strval( $exp ) );
		}
		if ( $locked ) {
			return array( 'status' => 'locked', 'paid' => false, 'overpaid_pico' => '0', 'shortfall_pico' => $shortfall );
		}
		if ( gmp_cmp( $rec, $threshold ) < 0 ) {
			return array( 'status' => 'underpaid', 'paid' => false, 'overpaid_pico' => '0', 'shortfall_pico' => $shortfall );
		}
		if ( (int) $confirmations < (int) $min_conf ) {
			return array( 'status' => $in_pool ? 'mempool' : 'unconfirmed', 'paid' => false, 'overpaid_pico' => '0', 'shortfall_pico' => '0' );
		}
		$overpaid = gmp_cmp( $rec, $exp ) > 0 ? gmp_strval( gmp_sub( $rec, $exp ) ) : '0';
		return array( 'status' => 'paid', 'paid' => true, 'overpaid_pico' => $overpaid, 'shortfall_pico' => '0' );
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
