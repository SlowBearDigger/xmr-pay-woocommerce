# xmr-pay for WooCommerce

Accept **Monero (XMR)** on WooCommerce — **non-custodial** (funds go straight to your own
address) and with **no backend you have to run**. WordPress verifies payments **itself**,
in PHP, against a public Monero node. No third party, no account, no fees from us.

> Requires **PHP 7.4+** (with the **GMP** extension for the no-server modes), **WordPress
> 6.2+**, **WooCommerce 7.0+**. HPOS-compatible. License **MIT**.

<p>
  <a href="https://github.com/SlowBearDigger/xmr-pay-woocommerce/releases/latest"><img src="https://img.shields.io/github/v/release/SlowBearDigger/xmr-pay-woocommerce?include_prereleases&color=FF6600&label=release" alt="latest release"></a>
  <img src="https://img.shields.io/badge/license-MIT-FF6600" alt="MIT">
  <img src="https://img.shields.io/badge/non--custodial-✓-FF6600" alt="non-custodial">
  <img src="https://img.shields.io/badge/no--server%20mode-pure%20PHP-FF6600" alt="pure PHP">
  <a href="https://github.com/SlowBearDigger/xmr-pay"><img src="https://img.shields.io/badge/core-xmr--pay%20(npm)-FF6600" alt="xmr-pay library"></a>
</p>

**▶ Try it live (stagenet):** [⚙ configure it yourself](https://live.xmrpay.shop) · [🛒 demo store](https://demo.xmrpay.shop) · [🔧 how the library works](https://xmrpay.shop/demo.html) · [↓ download the latest .zip](https://github.com/SlowBearDigger/xmr-pay-woocommerce/releases/latest/download/xmr-pay-for-woocommerce.zip)

## How it verifies — pick a mode

| Mode | Buyer does | Needs a view key? | Runs a daemon? |
|---|---|---|---|
| **Auto-detect in WordPress** (recommended) | nothing | yes (on your server) | **no** |
| **Buyer taps "I've paid"** | pastes the transaction ID | yes (on your server) | **no** |
| **Agent** (advanced) | nothing | no (the agent holds it) | **yes** (`npx xmr-pay`) |

The two **no-server** modes do the Monero crypto in pure PHP (vendored, audited — no
Composer, no Node, no `monero-wallet-rpc`). The only external thing they need is a
**Monero node** (a public one is fine) to read the chain — your view key never leaves
your server. **Agent** mode is for merchants who'd rather run the separate
[xmr-pay daemon](https://github.com/SlowBearDigger/xmr-pay/blob/main/docs/AGENT.md).

```
Auto-detect / "I've paid":           Agent (advanced):
 checkout ─▶ order (your address)     checkout ─▶ POST /order ─▶ agent (Node + view key)
 buyer pays ─────────▶ monerod        buyer pays ───────────────▶ monerod
 WordPress scans/verifies (PHP) ─▶    agent ─signed webhook─▶ plugin ─▶ paid
   order marked paid
```

The buyer's browser only ever shows status — **fulfillment happens server-side**; a faked
"paid" in the browser fools nothing.

## Quick start

1. Install & activate the plugin (drop `xmr-pay-for-woocommerce/` in `wp-content/plugins/`,
   or upload the zip). A guided setup wizard helps on first run.
2. **WooCommerce → Settings → Payments → Monero (xmr-pay)**.
3. Pick a **mode** (Auto-detect is recommended).
4. For the no-server modes, fill in:
   - **Your Monero address**
   - **Private view key** — view-only (cannot spend). Better: put
     `define( 'XMRPAY_VIEW_KEY', '…' );` in `wp-config.php` so it stays out of the database.
   - **Node(s)** — a public Monero node URL (your own first, if you run one).
   - **Confirmations** — `1` is a good default; `0` = instant (riskier).
5. Test on **stagenet** first, then switch to your mainnet wallet.

## Settings (the two no-server modes)
Address, view key, node(s), confirmations, and an optional underpayment tolerance — that's
it. (The "Agent settings" section applies only to **Agent** mode; leave it blank otherwise.)

## What it stores / what it touches
**No custom database tables.** Settings live in one option row, per-order data in order
meta, short-lived caches in transients, two idempotent WP-Cron jobs. Outbound: a Monero
node (+ CoinGecko only if you price in fiat). Full map:
[`docs/DATA-AND-FOOTPRINT.md`](xmr-pay-for-woocommerce/docs/DATA-AND-FOOTPRINT.md).

## Docs
- **FAQ & guide** (plain-language + technical): [`xmr-pay/docs/FAQ.md`](https://github.com/SlowBearDigger/xmr-pay/blob/main/docs/FAQ.md)
- **Data & footprint**: [`docs/DATA-AND-FOOTPRINT.md`](xmr-pay-for-woocommerce/docs/DATA-AND-FOOTPRINT.md)
- **How WordPress-native verification works**: [`docs/WP-NATIVE-VERIFICATION.md`](docs/WP-NATIVE-VERIFICATION.md)
- **Agent mode**: [xmr-pay/docs/AGENT.md](https://github.com/SlowBearDigger/xmr-pay/blob/main/docs/AGENT.md)

## Local dev / test (no Docker)
Needs Node + PHP (`wp-now` uses SQLite, no MySQL). Boot WordPress + WooCommerce + this
plugin via the included blueprint:

```bash
cd xmr-pay-for-woocommerce
npx @wp-now/wp-now start --blueprint=../blueprint.json --port=8881
# → http://localhost:8881  (auto-login; a test product; gateway preconfigured)
```

Then set a **no-server** mode with a stagenet address + view key + a stagenet node, place
an order, pay the shown address on stagenet (a faucet or self-send), and watch it complete.
(For agent-mode dev, run `examples/scanner-agent.js` from the `xmr-pay` lib and point the
plugin's Agent URL at it.)

The pure-PHP verifier has its own offline + on-chain tests:
```bash
php tests/util.test.php        # money math (no network)
php tests/scanner.test.php     # on-chain verification vs a public stagenet node
```

## Status
Classic **and** Blocks (Store API) checkout, the three modes above, per-order
subaddresses, QR via the bundled `<xmr-pay>` widget, signed receipts, HPOS-compatible,
guided setup wizard. Beta — heading to a 1.0 after a mainnet smoke test and an independent
audit of the verification path.

## Acknowledgements
Built on the **[xmr-pay library](https://github.com/SlowBearDigger/xmr-pay)** (the npm core), and the open-source work it stands on — **give them a ⭐:**

- **[monero-integrations / monerophp](https://github.com/monero-integrations/monerophp)** (MIT) — the pure-PHP ed25519 / key-derivation / base58 primitives the WordPress-native verifier is vendored on. The breakthrough that made "verify in PHP" possible.
- **[kornrunner/php-keccak](https://github.com/kornrunner/php-keccak)** (MIT) — Keccak-256 with Monero's padding, in pure PHP.
- **[qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator)** (MIT) — the bundled `<xmr-pay>` widget's self-contained QR encoder.
- **[monero-ts](https://github.com/woodser/monero-ts)** (woodser, MIT) — the WASM reference the PHP verifier is cross-checked against.
- **Inspiration:** [BTCPay Server](https://btcpayserver.org/)'s Monero plugin and [MoneroPay](https://gitlab.com/moneropay/moneropay) — studied to match (and, on reorg-safety and arithmetic, exceed) their detection model.

A [GoXMR](https://goxmr.click) project · MIT.
