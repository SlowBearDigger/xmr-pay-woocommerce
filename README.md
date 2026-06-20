# xmr-pay for WooCommerce

> **Support Monero, support this:** donations are welcome and never required. They keep xmr-pay independent (no ads, no investors, no upsell).
> `45sEohkyWYxAfHy8ekP7B34Bd3qhgrupcQfUQAHvfUWkfgqJhCA4QYLigrBg8G8TE4WggtMGpmjXrbmvepkWLec58KKLkm9`

Accept Monero (XMR) in your WooCommerce store. Funds go straight to your own wallet
(non-custodial), and there is no backend for you to run: WordPress verifies payments
itself, in plain PHP, against a Monero node. No third party, no account, no fee from
us.

> Requires PHP 7.4+ (with the GMP extension for the no-server modes), WordPress 6.2+,
> WooCommerce 7.0+. HPOS-compatible. MIT licensed.

## See it live (stagenet, no real money)

> [live.xmrpay.shop](https://live.xmrpay.shop) : configure the plugin yourself and watch it verify a payment
> [demo.xmrpay.shop](https://demo.xmrpay.shop) : a full demo store, pay with free test XMR
> [Download the latest .zip](https://github.com/SlowBearDigger/xmr-pay-woocommerce/releases/latest/download/xmr-pay-for-woocommerce.zip)

## Built on the xmr-pay library

This plugin is a standalone install (drop it in, paste your address, done). Under the
hood it runs on the [xmr-pay library](https://github.com/SlowBearDigger/xmr-pay), the
same engine published on npm. The two projects are independent and each links the
other: this README is about the plugin, that one is about the engine underneath.

## No server. It runs inside WordPress.

Most Monero plugins make you run something extra. This one does not. There are three
ways to confirm a payment, and the two recommended ones run entirely inside
WordPress, in pure PHP:

| Mode | Buyer does | View key on your server? | Separate process? |
|---|---|---|---|
| **Auto-detect** (recommended) | nothing, just pays | yes | no |
| **"I've paid"** | pastes the transaction ID | yes | no |
| **Agent** (advanced) | nothing | no (the agent holds it) | yes (`npx xmr-pay`) |

The two no-server modes do the Monero crypto in pure PHP (vendored and audited: no
Composer, no Node, no `monero-wallet-rpc`). The only outside thing they need is a
Monero node to read the chain, and a public one is fine. Your view key never leaves
your server. Agent mode is for merchants who would rather run the separate
[xmr-pay daemon](https://github.com/SlowBearDigger/xmr-pay/blob/main/docs/AGENT.md).

```
Auto-detect / "I've paid" (no server):     Agent (advanced):
  checkout > order to your address          checkout > POST /order > agent (Node + view key)
  buyer pays > monerod                       buyer pays > monerod
  WordPress verifies in PHP > paid           agent > signed webhook > plugin > paid
```

**Why it is reasonably trustworthy** (and where the limits honestly are):

> **It can see, it cannot spend.** WordPress only ever holds your *view* key. It reads
> incoming payments; it can never move your money. The spend key is never asked for.
> **The amount is proven, not claimed.** Monero commits the real amount on-chain and
> the plugin checks that commitment, so a forged amount is rejected.
> **It fails closed.** The amount commitment, enough confirmations, no time-lock, and
> no double-count must all pass, or the order stays unpaid. It never guesses "paid".
> **The honest caveat:** it trusts the node you point it at. A public node is fine for
> most stores; for serious money run your own node or require two nodes to agree.

The buyer's browser only ever shows status. Fulfillment happens server-side, so a
faked "paid" in the browser fools nothing.

## Quick start

1. Install and activate the plugin (drop `xmr-pay-for-woocommerce/` in
   `wp-content/plugins/`, or upload the zip). A guided setup wizard helps on first run.
2. Go to **WooCommerce > Settings > Payments > Monero (xmr-pay)**.
3. Pick a **mode** (Auto-detect is recommended).
4. For the no-server modes, fill in:
   > **Your Monero address.**
   > **Private view key** (view-only, cannot spend). Better still, put
   > `define( 'XMRPAY_VIEW_KEY', '…' );` in `wp-config.php` so it stays out of the database.
   > **Node(s)**, a public Monero node URL (your own first, if you run one).
   > **Confirmations**, where `1` is a good default and `0` is instant but riskier.
5. Test on **stagenet** first, then switch to your mainnet wallet.

> **There is no network toggle; the network follows your address.** A `4…` address is
> mainnet; a `5…` / `7…` address is stagenet. To go live, swap the address, view key,
> and node to your mainnet ones. The settings page shows the detected network, and
> **Check setup** confirms the node, network, and view key match before you go live.

## Settings

Address, view key, node(s), confirmations, an optional underpayment tolerance, and a
checkout theme. That is it. The "Agent settings" section applies only to Agent mode;
leave it blank otherwise.

## What it stores and what it touches

No custom database tables. Settings live in one option row, per-order data in order
meta, short-lived caches in transients, and two idempotent WP-Cron jobs. Outbound: a
Monero node (plus CoinGecko only if you price in fiat). The plugin also drops the
buyer's IP and browser from Monero orders (there are no chargebacks to dispute).
Full map: [`docs/DATA-AND-FOOTPRINT.md`](xmr-pay-for-woocommerce/docs/DATA-AND-FOOTPRINT.md).

## The truths (please read before taking real money)

> **Monero is irreversible and the sender is hidden, so there are no automatic
> refunds.** To refund a buyer you send them XMR back by hand.
> **Underpaid or paid in two transactions?** The order stays open and completes itself
> once the total adds up. The buyer can send the rest to the same address. (This is
> handled in the no-server auto-detect mode and in agent mode.)
> **Few confirmations is fast but reversible.** Zero confirmations is instant, but a
> payment can still vanish in a chain reorg. Raise confirmations for higher-value
> orders.
> **A brand-new transaction can take a moment on a public node.** With "I've paid", a
> transaction still in the mempool may not be served by a public node yet; the check
> says "try again", never a false "paid", and clears once it is in a block. Your own
> node removes the wait.
> **You need the GMP PHP extension** for the no-server modes. Most hosts have it; if
> not, your host can switch it on.

## Docs

> FAQ and guide (plain-language and technical): [xmr-pay/docs/FAQ.md](https://github.com/SlowBearDigger/xmr-pay/blob/main/docs/FAQ.md)
> Data and footprint: [`docs/DATA-AND-FOOTPRINT.md`](xmr-pay-for-woocommerce/docs/DATA-AND-FOOTPRINT.md)
> How WordPress-native verification works: [`docs/WP-NATIVE-VERIFICATION.md`](docs/WP-NATIVE-VERIFICATION.md)
> Agent mode: [xmr-pay/docs/AGENT.md](https://github.com/SlowBearDigger/xmr-pay/blob/main/docs/AGENT.md)

<br>

---

# For developers

## Local dev and test (no Docker)

Needs Node and PHP (`wp-now` uses SQLite, no MySQL). Boot WordPress + WooCommerce +
this plugin via the included blueprint:

```bash
cd xmr-pay-for-woocommerce
npx @wp-now/wp-now start --blueprint=../blueprint.json --port=8881
# http://localhost:8881  (auto-login; a test product; gateway preconfigured)
```

Then set a no-server mode with a stagenet address + view key + a stagenet node, place
an order, pay the shown address on stagenet (a faucet or self-send), and watch it
complete. For agent-mode dev, run `examples/scanner-agent.js` from the xmr-pay lib and
point the plugin's Agent URL at it.

The pure-PHP verifier has its own offline and on-chain tests:

```bash
php tests/util.test.php          # money math (no network)
php tests/crypto.test.php        # vendored Keccak / base58 known-answer + fuzz (no network)
php tests/aggregation.test.php   # multi-tx summing, order-independence, no false-paid (no network)
php tests/scanner.test.php       # on-chain verification vs a public stagenet node
```

## Status

**1.0.** Classic and Blocks (Store API) checkout, the three modes above, per-order
subaddresses, QR via the bundled `<xmr-pay>` widget, signed receipts, HPOS-compatible,
and a guided setup wizard. The no-server mode sums installment and top-up payments and
never strands partial funds, with the settlement math hardened to be independent of
the order a node returns transactions in (covered by the aggregation test suite).

## Acknowledgements

Built on the [xmr-pay library](https://github.com/SlowBearDigger/xmr-pay) (the npm
core), and the open-source work it stands on. Give them a star:

> [monero-integrations / monerophp](https://github.com/monero-integrations/monerophp) (MIT): the pure-PHP ed25519, key-derivation and base58 primitives the WordPress-native verifier is vendored on. The breakthrough that made "verify in PHP" possible.
> [kornrunner/php-keccak](https://github.com/kornrunner/php-keccak) (MIT): Keccak-256 with Monero's padding, in pure PHP.
> [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) (MIT): the bundled `<xmr-pay>` widget's self-contained QR encoder.
> [monero-ts](https://github.com/woodser/monero-ts) (woodser, MIT): the WASM reference the PHP verifier is cross-checked against.
> Inspiration: [BTCPay Server](https://btcpayserver.org/)'s Monero plugin and [MoneroPay](https://gitlab.com/moneropay/moneropay), studied to match (and, on reorg-safety and arithmetic, exceed) their detection model.

## License

MIT. A [GoXMR](https://goxmr.click) project.

> **Support Monero, support this:** donations are welcome and never required. They keep xmr-pay independent (no ads, no investors, no upsell).
> `45sEohkyWYxAfHy8ekP7B34Bd3qhgrupcQfUQAHvfUWkfgqJhCA4QYLigrBg8G8TE4WggtMGpmjXrbmvepkWLec58KKLkm9`
