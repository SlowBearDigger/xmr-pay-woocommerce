# WordPress-native Monero verification — research + spike findings

**Goal:** let a WooCommerce store verify Monero payments *inside WordPress* — no Node
process, no external service, no `monero-wallet-rpc`. Just PHP (+ a public node over
HTTP) so a shared-host merchant installs a plugin and it works.

**Date:** 2026-06-19. **Status:** ✅ **GATE PASSED** — pure PHP reproduced monero-ts's
detected amount on a real stagenet payment. Full verifier not yet built, but feasibility
is proven end-to-end (fetch tx → derive → match output → decode RingCT amount).

## ✅ GATE RESULT (2026-06-19)

Faucet-funded a stagenet wallet's PRIMARY address (tx
`8c3079a92e185d241752d1bee6131e7962ddcb54d1e176b6cf5372a51ad08a71`, 0.1 XMR). monero-ts
reported `100000000000` atomic. Pure PHP (monerophp primitives + a hand-written RingCT
amount decode) fetched the tx from a public node, computed the derivation with the view
key, matched the output (`is_output_mine`), and decoded the amount:

```
output #0 is OURS  →  decoded amount (atomic): 100000000000
✅ PASS — PHP decoded 100000000000 vs monero-ts 100000000000
```

The RingCT decode written: `amount = ecdhInfo[i].amount XOR first8(keccak("amount" ||
derivation_to_scalar(D,i)))`, little-endian uint64. Scripts: `~/Documents/wp-gate-truth.js`
(ground truth via monero-ts) + `~/Documents/wp-gate-php.php` (PHP replication). Confirmed
on PHP 8.5.7 + GMP. Handles the current `tagged_key` (view-tag) output format.

**✅ SUBADDRESS sub-gate also PASSED (2026-06-19):** faucet-funded a per-order subaddress
(minor 7, tx `c7622a43…`, 0.1 XMR); `~/Documents/wp-gate-php-sub.php` detected it and decoded
0.1 exact in pure PHP. Notes: a single-subaddress-destination tx carries the tx pubkey as the
MAIN key (tag 01) computed as `r·C` (subaddress spend key), no additional pubkeys, so
`derive_public_key(8·a·R, i, C) == output.key` matches via main R (multi-dest txs use tag-04
additional pubkeys — the script parses those and tries both). The match is cryptographic
against the real on-chain output key = independent ground truth. `decode_address()` yields the
subaddress spend key C; current `tagged_key`/view-tag vout handled.

**CRYPTO RISK CLOSED.** What remains is ENGINEERING, not feasibility:
1. Commitment check `C ?= amount·H + mask·G` (belt-and-suspenders vs a forged amount).
2. Confirmations + unlock_time gate (daemon `get_transactions` gives `block_height` + `unlock_time`).
3. The scan loop (fetch blocks → iterate txs → derive → match watched subaddresses → decode → sum).
4. WP-Cron + checkpointing + `max_execution_time` safety (bounded blocks/tick, persist progress).
5. VENDOR the ~4 monerophp files (ed25519, Cryptonote, base58, Varint) + kornrunner Keccak into
   the plugin and own them (monerophp is unmaintained but the math is fixed; runs clean on PHP 8.5).

---

## The hard constraint (recap)

WordPress runs **PHP**, not JavaScript. The browser can run our monero-ts WASM
verifier, but the *buyer* controls the browser, so the store can't trust its verdict
(the buyer can fake "✓ verified"). The authoritative check must run somewhere the
buyer can't tamper with. For "inside WP, no external", that means **PHP must do the
Monero crypto itself** — there is no other server brain in stock WordPress.

## What we ruled out

- **External keyless verifier (Node/serverless).** Works today, already built
  (`xmr-pay/examples/verify-keyless.js`), but it's a process *outside* WP. Keep it as
  the universal fallback; it's not "inside WP".
- **UnWasm (WASM transpiled to PHP, no extension).** Disqualified for crypto: its own
  docs say *integer overflow is cast to float* and numeric-instruction support is
  *partial*. Crypto needs exact 64-bit modular arithmetic with wraparound — UnWasm
  would compute it **wrong, silently** → could accept fake payments. The "audited
  Monero C++" advantage evaporates if the runtime corrupts the arithmetic.
- **libsodium's raw ed25519 ops.** PHP's sodium exposes **ristretto255**, not the raw
  ed25519 group ops Monero uses (cofactor-8, specific encoding). Probed locally on PHP
  8.5 / libsodium 1.0.22: `crypto_core_ed25519_*` and `scalarmult_ed25519_noclamp` are
  **absent**. Unreliable across hosts → not a foundation.
- **Python / Flask / FastAPI.** WP doesn't run Python in-core; it'd be a sidecar
  process, same category as Node. Not "inside WP".

## What works: monerophp + GMP (spike-verified)

[`monero-integrations/monerophp`](https://github.com/monero-integrations/monerophp)
(MIT, PHP >= 7.3) ships a **pure-PHP** Monero crypto toolbox:

- `ed25519.php` — full group ops over GMP/BCMath: `scalarmult`, `edwards` (point add),
  `xrecover` (decompression), encode/decode. Uses GMP when present.
- `Cryptonote.php` — `keccak_256` (via `kornrunner/keccak`, the correct Monero Keccak
  padding), `hash_to_scalar`, `gen_key_derivation` (ECDH `8·a·R`), `derivation_to_scalar`,
  `derive_public_key`, `txpub_from_extra`, **`is_output_mine(...)`** (view-key output
  detection — the heart of watch mode), subaddress generation, address encode/decode.
- `base58.php`, `Varint.php`, `daemonRPC.php` (daemon JSON-RPC wrapper).

### Local spike (PHP 8.5.7, GMP, libsodium 1.0.22) — ALL GREEN

| check | result |
|---|---|
| ed25519 basepoint encodes to the standard constant `5866…6666` | **PASS** (core is correct) |
| `scalarmult_base(1) == B` | PASS |
| `pk_from_sk` → valid 32-byte point | PASS |
| ECDH derivation symmetric `D(a,B) == D(b,A)` | **PASS** (derivation correct) |
| address `encode → decode` round-trip (keccak+base58+checksum) | PASS |

**Speed (GMP):** `scalarmult_base` ≈ 0.5 ms · `gen_key_derivation` ≈ 10 ms (once per
tx) · `derive_public_key` ≈ 10 ms (once per output).

> The crypto core we need is present, **correct**, and fast enough. GMP is doing exact
> big-integer math — the right foundation, unlike UnWasm.

## Two architectures this unlocks (both inside WP, no external)

### A. Watch mode in pure PHP — auto-detect, view key on the merchant's own WP
The merchant pastes their **primary address + private view key** into the plugin (on
their own server — non-custodial; we never hold a spend key, exactly how BTCPay /
MoneroPay keep the view key server-side). A **WP-Cron** job fetches recent blocks from
a public node (daemon RPC over `wp_remote_post`) and, for each tx, runs
`gen_key_derivation` + `is_output_mine` against the order's subaddress, decodes the
amount, and completes the order. **No buyer paste — best UX.**

- **Pros:** auto-detection (the UX that beats proof), the model merchants expect,
  fully inside WP.
- **To build:** RingCT amount decode (`ecdhDecode`: `keccak("amount"||shared)` XOR the
  encrypted amount + commitment check — uses primitives we already have); the scan loop
  (fetch blocks/txs, iterate outputs); subaddress match table; confirmations +
  unlock_time gate; WP-Cron with checkpointing + `max_execution_time` safety
  (bounded blocks per tick, persist progress).
- **Cost / limits:** ≈10 ms per tx + ≈10 ms per output. Low-volume stores: a cron that
  scans the few blocks since last tick is ~1–3 s — fine. **Catching up after downtime
  or high-traffic blocks is the risk** → scan in bounded batches, persist progress, never
  block a request. GMP effectively **required** (BCMath fallback is ~10× slower →
  impractical). Not for high-traffic stores.

### B. Proof mode in pure PHP — zero view key, buyer pastes a proof
The buyer pays and submits a tx proof; PHP verifies it. Same primitives, but we add the
**`check_tx_proof` signature verification** (Chaum-Pedersen over the existing group ops
+ `hash_to_scalar`) plus the same RingCT amount decode. Amount-nonce + txid-dedup
(already in `XmrPay_Util::nonce_amount` + WC order meta) stop replay.

- **Pros:** no view key anywhere; verification is a single request (no scanning, no
  cron); cheap.
- **Cons:** buyer must paste a proof (worse UX); we must port `check_tx_proof` exactly.

## Risks / open items (both paths)

1. **Correctness must be proven end-to-end, not assumed.** The *primitives* are verified
   correct here, but the assembled verifier (RingCT decode, real payment detection /
   proof check) **must be cross-checked against monero-ts on real stagenet payments**
   before it touches money. A crypto bug = accept fake payments. This is the gate.
2. **RingCT amount decode** is the main new crypto to write for *both* paths. It's a
   keccak + XOR + a commitment check (`C ?= amount·H + mask·G`) — small, but must match
   Monero exactly. `H` (the second generator) is a fixed constant to embed.
3. **GMP dependency.** Required for usable speed. Detect it; degrade clearly if absent.
4. **Daemon data over HTTP.** `get_transactions(decode_as_json)` works (we already use it
   for unlock_time). Whole-block scanning needs `get_block` + per-tx fetch (the `.bin`
   endpoints are impractical from PHP). More round-trips, but feasible.
5. **Keccak dep:** bundle `kornrunner/keccak` (pure PHP, MIT) — it's the correct Monero
   padding (matches Ethereum keccak256, not SHA3).

## Recommendation

- **Ship now:** the external keyless verifier (done, tested) as the universal default.
- **The differentiator (R&D):** build the **pure-PHP verifier on monerophp's primitives**.
  Lead with **Watch mode (A)** — auto-detect + view-key-on-own-server is the better UX and
  the model merchants know; it's also the more complete path in monerophp
  (`is_output_mine` already exists). Proof mode (B) is the zero-view-key add-on.
- **Next concrete step (the real gate):** build RingCT amount decode + a minimal scan of
  ONE known stagenet payment, and **cross-check the detected amount against monero-ts**.
  If PHP reproduces the right amount on a real tx, the WP-native dream is proven and we
  build out from there. If not, we learn exactly where before investing further.

**Nobody ships this** — non-custodial *and* no-backend *and* server-authoritative, all
inside WordPress. That's the moat, if the correctness gate passes.
