# xmr-pay for WooCommerce

A WooCommerce payment gateway that accepts **Monero (XMR)** — non-custodial,
funds go straight to the merchant's own address. The plugin is a **thin HTTP
client** of the merchant's own [xmr-pay scanner-agent](https://github.com/SlowBearDigger/xmr-pay/blob/main/docs/AGENT.md):
no Monero crypto in PHP, no third party, no view key in WordPress.

```
WooCommerce (this plugin, PHP)         the merchant's box (private)
  checkout ─▶ process_payment() ─POST /order─▶  scanner-agent (Node + monero-ts)
  pay page ◀─ subaddress + QR (<xmr-pay> widget)      │ view key, scan, sum
  buyer pays ───────────────────────────────────────▶ monerod
  agent ─signed order.paid webhook─▶ this plugin ─▶ order marked paid
```

The buyer's browser polls **this plugin** (which proxies the agent server-side),
so the agent stays private. Completion is authoritative via the signed webhook.

## The plugin

`xmr-pay-for-woocommerce/` — drop it in `wp-content/plugins/` (or install the zip).

**Settings (WooCommerce → Payments → Monero):**
- **Agent URL** — your scanner-agent (`http://127.0.0.1:8788` by default; keep it private).
- **Agent token** — the agent's `AGENT_TOKEN`.
- **Webhook secret** — set the agent's `FULFILL_WEBHOOK_SECRET` to this, and its
  `FULFILL_WEBHOOK_URL` to the URL shown in the settings.
- **Test amount (XMR)** — TEST ONLY: charge a fixed XMR amount (e.g. `0.01`) so you
  can exercise the flow on stagenet without a price feed. Leave empty in production
  (then the cart's fiat total is converted via CoinGecko).

## Local dev / test (no Docker)

Needs Node + PHP (PHP is used by `wp-now`; SQLite, no MySQL).

**1. WordPress + WooCommerce + this plugin** (auto-configured by `blueprint.json`):

```bash
cd xmr-pay-for-woocommerce
npx @wp-now/wp-now start --blueprint=../blueprint.json --port=8881
# → http://localhost:8881  (wp-admin auto-login; a "Test Item" product; gateway preconfigured)
```

**2. The scanner-agent on stagenet** (view-only, reuses the lib's POC keys):

```bash
cd ../../goxmr-pay-lib            # the xmr-pay lib
XMR_PRIMARY_ADDRESS=<poc stagenet primary> \
XMR_VIEW_KEY=<poc stagenet view key> \
XMR_NETWORK=stagenet \
XMR_NODES=http://node.monerodevs.org:38089 \
XMR_RESTORE_HEIGHT=<recent height> \
FULFILL_WEBHOOK_URL='http://localhost:8881/?wc-api=xmrpay_webhook' \
FULFILL_WEBHOOK_SECRET=whsec_test_xmrpay \
PORT=8788 \
NODE_PATH=$HOME/Documents/goxmr-landing/server/node_modules \
node examples/scanner-agent.js
```

**3. Place an order** in the store, pay the shown subaddress on stagenet (faucet
or a self-send), and watch the order flip to paid (poll + webhook).

## Status

MVP: classic checkout gateway, per-order subaddress via the agent, QR via the
bundled `<xmr-pay>` widget, server-side status proxy, signed webhook completion,
HPOS-compatible. Next: WooCommerce **Blocks** checkout integration, an onboarding
wizard, and a one-click agent deploy.

A [GoXMR](https://goxmr.click) project · MIT.
