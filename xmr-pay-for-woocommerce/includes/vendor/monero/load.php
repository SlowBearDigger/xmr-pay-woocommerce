<?php
/**
 * Vendored, self-contained Monero crypto — no Composer, no PHP extension, no Node.
 * These are the verified primitives the WP-native proof/watch verifier stands on:
 * pure-PHP ed25519 (over GMP/BCMath), Keccak-256, base58, varint, and the cryptonote
 * key-derivation toolbox. We VENDOR them (rather than depend on Composer) so the plugin
 * works on any shared host and so we OWN the exact, audited bytes.
 *
 * Provenance (pinned for auditability):
 *   ed25519.php, base58.php, Varint.php, Cryptonote.php
 *     — monero-integrations/monerophp @ 25d4c5838b35cbf1fb55170b831e895681a7410a (MIT)
 *   Keccak.php
 *     — kornrunner/php-keccak (MIT) — the correct Monero Keccak-256 padding
 *
 * The ed25519 + key-derivation + amount-decode math is fixed in the Monero protocol
 * (unchanged for years) and was cross-checked against monero-ts on real stagenet
 * payments (see docs/WP-NATIVE-VERIFICATION.md): primary + per-order subaddress
 * detection and RingCT amount decode all reproduced exactly. monerophp itself is
 * unmaintained, which is WHY we vendor + own it; it runs clean on PHP 8.5.
 *
 * Requires the GMP extension for usable speed (BCMath fallback works but is ~10x slower).
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'XMRPAY_TESTING' ) ) { exit; }

$xmrpay_vendor = __DIR__;
require_once $xmrpay_vendor . '/Keccak.php';     // kornrunner\Keccak
require_once $xmrpay_vendor . '/base58.php';     // MoneroIntegrations\MoneroPhp\base58
require_once $xmrpay_vendor . '/Varint.php';     // ...\Varint
require_once $xmrpay_vendor . '/ed25519.php';    // ...\ed25519
require_once $xmrpay_vendor . '/Cryptonote.php'; // ...\Cryptonote (uses kornrunner\Keccak + the above)
