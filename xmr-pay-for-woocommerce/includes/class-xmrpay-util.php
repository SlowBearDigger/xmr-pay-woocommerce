<?php
/**
 * Pure, dependency-free helpers — the parts of the gateway worth testing in
 * isolation: the money math (must be exact) and the webhook signature check.
 * No WordPress/WooCommerce calls here, so it runs under plain `php` in tests.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class XmrPay_Util {

	/** Smallest Monero unit (piconero): 1 XMR = 1e12 pico. */
	const XMR_DECIMALS = 12;

	/**
	 * The PHP extensions the no-server (pure-PHP) verifier needs to run WITHOUT fataling.
	 * BOTH are required: the money math (this class, summarize/classify) is GMP-only, and the
	 * vendored base58 (decode_address, hit on every key check + every scan) is BCMath-only.
	 * Every "is the gateway available?" guard MUST go through this, so a host missing either one
	 * never shows the payment method and then white-screens the buyer after they paid.
	 */
	public static function crypto_ready() {
		return extension_loaded( 'gmp' ) && extension_loaded( 'bcmath' );
	}

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
	public static function nonce_amount( $xmr, $digits = 9 ) {
		$digits = (int) $digits;
		if ( $digits < 1 || $digits > 12 ) {
			$digits = 9;
		}
		$base = self::xmr_to_pico( $xmr );
		if ( $base <= 0 ) {
			return '0';
		}
		$span  = ( 10 ** $digits ) - 1;          // nonce in 1..span piconero
		$nonce = random_int( 1, $span );         // CSPRNG, guaranteed on PHP 7+
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
	public static function test_amount_allowed( $network, $tested_url, $agent_url, $address = '' ) {
		if ( ! in_array( $network, array( 'stagenet', 'testnet' ), true ) ) {
			return false;
		}
		// cross-check: if the stored address prefix contradicts the stored network a
		// merchant swapped from stagenet to mainnet at the same URL without re-testing.
		// stagenet addresses start with 5/7, mainnet with 4/8, testnet with 9/A/B.
		if ( '' !== $address ) {
			$first           = $address[0];
			$is_test_address = in_array( $first, array( '5', '7', '9', 'A', 'B' ), true );
			if ( ! $is_test_address ) {
				return false; // mainnet address but stagenet flag — test_amount must not fire
			}
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
		$h = wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( empty( $h ) ) {
			return true;
		}
		$hh = wp_parse_url( (string) $home, PHP_URL_HOST );
		return strtolower( $h ) === strtolower( (string) $hh );
	}

	/** A row's amount as a non-negative GMP; a malformed/empty/negative value reads as 0. */
	private static function row_amt_pico( $row ) {
		$v = isset( $row['amount_atomic'] ) ? (string) $row['amount_atomic'] : '0';
		if ( '' === $v || ! preg_match( '/^-?\d+$/', $v ) ) { return gmp_init( 0 ); }
		$g = gmp_init( $v, 10 );
		return gmp_cmp( $g, 0 ) < 0 ? gmp_init( 0 ) : $g;
	}

	/**
	 * Of two rows for the SAME txid, the one we can most safely credit. A TOTAL order over
	 * every field that changes the verdict, so the result (and the verdict) never depends on
	 * which row arrived first (the WP-native scanner has no row-order guarantee). Every
	 * tie-break is the CONSERVATIVE reading, so contradictory node data is never credited:
	 * a committed (proven) copy, then a confirmed (not-pool) copy, then more confirmations,
	 * then a time-locked copy (contradictory lock status is treated as still locked), then
	 * the SMALLER amount (a duplicate that disagrees on value can never settle on the larger,
	 * bogus claim). Mirrors the lib's moreCreditable so both transports agree on what is paid.
	 */
	private static function more_creditable( $a, $b ) {
		$ak = ! empty( $a['commitment_ok'] );
		$bk = ! empty( $b['commitment_ok'] );
		if ( $ak !== $bk ) { return $ak ? $a : $b; }
		$ap = ! empty( $a['in_pool'] );
		$bp = ! empty( $b['in_pool'] );
		if ( $ap !== $bp ) { return $ap ? $b : $a; }
		$ac = ( isset( $a['confirmations'] ) && null !== $a['confirmations'] ) ? (int) $a['confirmations'] : -1;
		$bc = ( isset( $b['confirmations'] ) && null !== $b['confirmations'] ) ? (int) $b['confirmations'] : -1;
		if ( $ac !== $bc ) { return $ac > $bc ? $a : $b; }
		$al = ! empty( $a['locked'] );
		$bl = ! empty( $b['locked'] );
		if ( $al !== $bl ) { return $al ? $a : $b; }   // contradictory lock status -> keep LOCKED (conservative)
		$cmp = gmp_cmp( self::row_amt_pico( $a ), self::row_amt_pico( $b ) );
		if ( 0 !== $cmp ) { return $cmp < 0 ? $a : $b; }
		return $a;
	}

	/**
	 * Collapse rows that share a REAL txid down to one most-creditable copy, ORDER-
	 * INDEPENDENTLY. The same tx can surface more than once (e.g. a known-txid re-verify
	 * AND a fresh block-scan hit in the same tick); count it once. An empty/missing txid
	 * is never a duplicate of another, so each such row is kept.
	 */
	public static function dedup_by_txid( $rows ) {
		if ( ! is_array( $rows ) ) { return array(); }
		$pos = array();
		$out = array();
		foreach ( $rows as $t ) {
			if ( ! is_array( $t ) || ! isset( $t['txid'] ) || '' === (string) $t['txid'] ) { $out[] = $t; continue; }
			$k = (string) $t['txid'];
			if ( ! isset( $pos[ $k ] ) ) { $pos[ $k ] = count( $out ); $out[] = $t; }
			else { $out[ $pos[ $k ] ] = self::more_creditable( $out[ $pos[ $k ] ], $t ); }
		}
		return $out;
	}

	/**
	 * Sum every confirmed payment to an order's subaddress and return the settlement
	 * verdict — the WP-native equivalent of the lib's summarizeTransfers, so a buyer who
	 * pays in installments (or sends a small test tx then the rest) still completes.
	 * Only an output whose decoded amount is COMMITTED on-chain (commitment_ok) is ever
	 * credited. Status vocabulary matches the lib: paid|locked|mempool|partial|pending.
	 *
	 * @param array  $rows      [{txid, amount_atomic, confirmations|null, in_pool, locked, commitment_ok}]
	 * @param string $exp_pico  expected amount in piconero
	 * @param string $tol_pico  accepted shortfall in piconero (clamped < expected)
	 * @param int    $min_conf  confirmations required to credit a tx
	 */
	public static function summarize_payments( $rows, $exp_pico, $tol_pico, $min_conf ) {
		$min_conf = max( 0, (int) $min_conf );
		$rows     = self::dedup_by_txid( is_array( $rows ) ? $rows : array() );
		$confirmed = gmp_init( 0 );
		$pending   = gmp_init( 0 );
		$locked    = gmp_init( 0 );
		$min_confs = null;
		$txids     = array();
		foreach ( $rows as $t ) {
			// never credit an amount that isn't cryptographically committed on-chain.
			if ( ! is_array( $t ) || empty( $t['commitment_ok'] ) ) { continue; }
			$amt = self::row_amt_pico( $t );
			if ( isset( $t['txid'] ) && '' !== (string) $t['txid'] ) { $txids[] = (string) $t['txid']; }
			if ( ! empty( $t['locked'] ) ) { $locked = gmp_add( $locked, $amt ); continue; }
			$confs   = ( isset( $t['confirmations'] ) && null !== $t['confirmations'] ) ? (int) $t['confirmations'] : null;
			$in_pool = ! empty( $t['in_pool'] );
			if ( ! $in_pool && null !== $confs && $confs >= $min_conf ) {
				$confirmed = gmp_add( $confirmed, $amt );
				$min_confs = ( null === $min_confs ) ? $confs : min( $min_confs, $confs );
			} else {
				$pending = gmp_add( $pending, $amt );
			}
		}

		$exp = gmp_init( (string) $exp_pico, 10 );
		$tol = gmp_init( (string) $tol_pico, 10 );
		if ( gmp_cmp( $tol, 0 ) < 0 ) { $tol = gmp_init( 0 ); }
		$max_tol = gmp_cmp( $exp, 0 ) <= 0 ? gmp_init( 0 ) : gmp_sub( $exp, gmp_init( 1 ) );
		if ( gmp_cmp( $tol, $max_tol ) > 0 ) { $tol = $max_tol; }
		$threshold = gmp_sub( $exp, $tol );
		$seen      = gmp_add( gmp_add( $confirmed, $pending ), $locked );

		$base = array(
			'received_pico'  => gmp_strval( $confirmed ),
			'confirmed_pico' => gmp_strval( $confirmed ),
			'pending_pico'   => gmp_strval( $pending ),
			'locked_pico'    => gmp_strval( $locked ),
			'seen_pico'      => gmp_strval( $seen ),
			'confirmations'  => ( null === $min_confs ) ? 0 : (int) $min_confs,
			'txids'          => $txids,
			'overpaid_pico'  => '0',
			'shortfall_pico' => gmp_cmp( $threshold, $seen ) > 0 ? gmp_strval( gmp_sub( $threshold, $seen ) ) : '0',
		);

		if ( gmp_cmp( $exp, 0 ) <= 0 ) { return array_merge( $base, array( 'paid' => false, 'status' => 'invalid' ) ); }
		if ( gmp_cmp( $confirmed, $threshold ) >= 0 ) {
			$over = gmp_cmp( $confirmed, $exp ) > 0 ? gmp_strval( gmp_sub( $confirmed, $exp ) ) : '0';
			return array_merge( $base, array( 'paid' => true, 'status' => 'paid', 'overpaid_pico' => $over, 'shortfall_pico' => '0' ) );
		}
		if ( gmp_cmp( gmp_add( $locked, $confirmed ), $threshold ) >= 0 ) { return array_merge( $base, array( 'paid' => false, 'status' => 'locked' ) ); }
		if ( gmp_cmp( gmp_add( $confirmed, $pending ), $threshold ) >= 0 ) { return array_merge( $base, array( 'paid' => false, 'status' => 'mempool' ) ); }
		if ( gmp_cmp( $confirmed, 0 ) > 0 || gmp_cmp( $pending, 0 ) > 0 ) { return array_merge( $base, array( 'paid' => false, 'status' => 'partial' ) ); }
		return array_merge( $base, array( 'paid' => false, 'status' => 'pending' ) );
	}
}
