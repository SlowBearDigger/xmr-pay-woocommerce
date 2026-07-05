# What this plugin stores and what it talks to

A plain map of the plugin's footprint — every place it writes data and every external
thing it connects to. Short version: **no custom database tables, no background daemon,
no third-party service.** It rides WooCommerce's own order store and the WordPress
options/transients/cron that every plugin uses.

## Database: no new tables

The plugin creates **zero** custom tables and runs **no** schema migration. Everything
lives in storage WordPress/WooCommerce already manage, so it's HPOS-safe and uninstall
is clean.

### 1. Gateway settings — one option row
`wp_options` → **`woocommerce_xmrpay_settings`** (a single serialized array, written by
the standard WooCommerce settings screen). Keys:

| key | meaning |
|---|---|
| `enabled`, `title`, `description`, `checkout_theme`, `success_redirect` | display / behaviour |
| `mode` | `watch` (auto-detect in WP), `proof` (buyer submits txid), or `agent` (external daemon) |
| `xmr_address` | your primary Monero address (no-server modes) |
| `view_key` | your **private view key** — only used if the `XMRPAY_VIEW_KEY` constant is NOT set (see below) |
| `nodes` | public node URL(s), comma-separated |
| `proof_min_conf`, `proof_tolerance_xmr` | confirmation + underpayment policy (no-server modes) |
| `price_source`, `fixed_rate`, `coingecko_api_key` | fiat→XMR pricing (skipped if the store currency is XMR) |
| `agent_url`, `agent_token`, `webhook_secret` | agent mode only |
| `test_amount`, `expiry_hours`, `debug_log` | test override / auto-cancel window / logging |

Two tiny standalone options cache the "Test connection" result (agent mode):
`xmrpay_agent_network`, `xmrpay_agent_tested_url`.

> **Keep the view key out of the database entirely:** put
> `define( 'XMRPAY_VIEW_KEY', '…' );` in `wp-config.php`. The plugin reads the constant
> first and only falls back to the `view_key` setting if it's unset — so the key stays
> out of `wp_options`, the settings screen, and DB backups. (The view key is *view-only*:
> a leak exposes incoming-payment privacy, never the ability to spend.)

### 2. Per-order data — order meta (not new tables)
Written via `$order->update_meta_data()` → WooCommerce stores it in `wp_postmeta` or, on
HPOS, in `wc_orders_meta`. Keys per order:

| meta key | when | meaning |
|---|---|---|
| `_xmrpay_address` | created | the address/subaddress the buyer pays |
| `_xmrpay_amount` | created | exact XMR owed (watch) or nonced amount (proof) |
| `_xmrpay_mode` | created | `watch` / `proof` (the order's own mode wins over the setting) |
| `_xmrpay_minor` | watch | the subaddress index (= order id) |
| `_xmrpay_birthday` | watch | chain height at order time (scan starts here) |
| `_xmrpay_scan_height` | watch | scan checkpoint (advances as blocks are scanned) |
| `_xmrpay_watch_txid` | watch | the paying tx once discovered (then tracked by id) |
| `_xmrpay_proof_txid` | proof | the submitted txid (also the replay-dedup key) |
| `_xmrpay_received`, `_xmrpay_confirmations`, `_xmrpay_txids` | paid | what landed |
| `_xmrpay_overpaid`, `_xmrpay_overpaid_xmr` | paid | excess to refund (non-custodial → manual) |
| `_xmrpay_receipt` | paid | signed receipt envelope (agent mode) |
| `_xmrpay_partial_flagged` | partial | a partial payment was kept past expiry |

### 3. Short-lived caches — transients (auto-expiring)
| transient | TTL | purpose |
|---|---|---|
| `xmrpay_scancd_<order>` | ~20s | watch per-order scan cooldown (caps node hits / soft lock) |
| `xmrpay_vrl_<order>` | ~5s | proof verify rate-limit (per order) |
| `xmrpay_rcpt_<order>` | ~3m | receipt back-fill cooldown (agent mode) |
| `xmrpay_rate_<currency>` | ~3m | CoinGecko price cache |

### 4. Scheduled jobs — WP-Cron (no daemon)
Two WooCommerce-registered cron jobs, both idempotent:
- **reconcile / watch sweep** — the backstop: for on-hold orders it scans (watch) or
  polls the agent (agent mode) and completes any that are paid, so a payment still
  fulfills even if the buyer closed the tab. Bounded (caps orders scanned per tick).
- **expiry** — auto-cancels unpaid orders past `expiry_hours` (never one with received
  funds — a partial payment is preserved and flagged, never silently dropped).

### Uninstall
`uninstall.php` deletes the settings + transients. Order meta is intentionally kept (it's
part of the order's financial record).

## What it connects to (outbound)

| connection | when | what for |
|---|---|---|
| **A public Monero node** (your `nodes`) | watch + proof | `get_transactions`, `get_block`, `get_height` over HTTP (`wp_remote_*`). It never sees your view key — the key stays in PHP; only the chain data is fetched. |
| **CoinGecko** | fiat-priced stores | XMR price (skipped entirely if the store currency is XMR; your own API key optional) |
| **Your xmr-pay agent** | agent mode only | order status + the signed `order.paid` webhook |
| the verify endpoint `?wc-api=xmrpay_verify` | proof mode | **same site** — the buyer's browser POSTs the txid here; not a third party |

That's the whole surface: **your WordPress + a Monero node.** No account, no API key, no
custodian, nothing running 24/7 beyond WordPress itself.
