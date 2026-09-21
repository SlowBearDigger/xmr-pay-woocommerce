# WordPress-native payment verification

The plugin verifies payments in PHP on the merchant's WordPress server. It bundles
Monero primitives and uses the merchant's private view key; it never needs a spend
key. Both GMP and BCMath must be enabled in the PHP runtime serving WordPress.

## Modes

`watch` scans blocks for payments to an order's subaddress. `proof` verifies a
transaction ID supplied by the buyer using the same merchant view key. This native
proof mode does not implement keyless `OutProof` or `InProof` verification.

Agent mode is separate: a local Node process detects payments and sends signed
callbacks. A callback cannot settle an order created in a native PHP mode.

## Verification path

1. Fetch chain data from configured nodes. Require complete transaction batches,
   matching hashes, block heights and agreement between nodes.
2. Match outputs against the receiving address using the view key.
3. Decode the RingCT amount and verify its commitment.
4. Apply confirmations, explicit time locks and duplicate-output checks.
5. Aggregate eligible payments in integer piconero and complete the stored order
   only when its expected amount is covered.

`class-xmrpay-scanner.php` handles node evidence and cryptographic checks.
`class-xmrpay-util.php` handles amounts and aggregation.
`class-wc-gateway-xmrpay.php` binds that evidence to WooCommerce order state.
The browser only displays the result.

## Scan progress and availability

Watch requests scan bounded block ranges and persist checkpoints and matches.
Incomplete blocks do not advance the checkpoint. Later scans revisit recent
blocks to account for reorganisations and update confirmation counts. Both buyer
polls and the scheduled sweep can trigger reconciliation.

Every configured node must respond and agree during verification. Setup diagnostics
can show a usable node alongside warnings, but that does not mean payments ignore
an unavailable secondary node. Repair or remove a failing node deliberately.

The scanner does not scan the mempool in native watch mode. Automatic expiry is
disabled by default; if enabled, an unmined or unseen payment can arrive after
cancellation and require manual reconciliation. Paid orders are not automatically
reversed after a later chain reorganisation.

## Tests

From `nodewatch-monero/`:

```bash
php tests/crypto.test.php
php tests/aggregation.test.php
php tests/gateway.test.php
php tests/node-auth-e2e.test.php
```

The authentication test starts local HTTP fixtures. `tests/scanner.test.php` uses
historical stagenet transactions and needs network access. Full checkout tests on
WordPress and WooCommerce remain necessary before deploying a new plugin build.

See [the README](../README.md) for setup and
[the data map](../nodewatch-monero/docs/DATA-AND-FOOTPRINT.md) for storage and services.
