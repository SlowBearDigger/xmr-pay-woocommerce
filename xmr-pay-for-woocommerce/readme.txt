=== xmr-pay for WooCommerce ===
Contributors: slowbeardigger
Tags: monero, xmr, cryptocurrency, payment gateway, woocommerce
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.5-beta
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept Monero (XMR) in WooCommerce — non-custodial. Funds go straight to your own wallet; payment detection runs on your own agent.

== Description ==

**xmr-pay for WooCommerce** lets your store accept Monero with **no third party in the payment path**. It is a thin client of *your own* xmr-pay agent — the plugin never holds funds, a view key, or any Monero crypto.

* **Non-custodial.** Each order gets its own subaddress of *your* wallet. Buyers pay it directly; you control the funds on-chain.
* **No middleman, no API keys, no accounts.** Detection runs on an agent you run (a small Node service that holds only a **view key** — it can see payments, it cannot spend).
* **Blocks + classic checkout, HPOS-ready.** Shows up at the modern WooCommerce Blocks checkout and the classic one.
* **Exact amounts.** Conversions are computed in piconero (integer math) — no float drift. Discounts, shipping, taxes and fees are already in the order total, so they "just work".
* **Live payment progress.** The order-received page shows a real on-chain stepper: watching → detected → confirming → confirmed, with the live block height. Underpaid? It shows a top-up QR for the exact shortfall.
* **Signed webhooks.** The agent notifies the store with an HMAC-signed `order.paid`; completion is idempotent.

= How it works =

`checkout → the plugin asks your agent for a per-order subaddress → buyer pays it → the agent detects it on-chain and POSTs a signed webhook → the plugin completes the order.`

You run two things: this **plugin** (in WordPress) and the **agent** (the `xmr-pay` npm package, a Node service on your own box). See the agent docs: https://github.com/SlowBearDigger/xmr-pay

= Pricing =

Price natively in XMR (set your store currency to XMR — no feed needed), or price in fiat and convert via CoinGecko (your own API key supported) or a fixed rate.

== Installation ==

1. **Run the agent** (the backend that does the scanning). On your own server:
   `npm i xmr-pay monero-ts` then run the example `scanner-agent.js` with your Monero **primary address + private view key**, your node(s), and a webhook secret. Bind it to localhost. (Full guide in the xmr-pay repo, `docs/AGENT.md`.)
2. **Install this plugin**: upload the `xmr-pay-for-woocommerce` folder to `wp-content/plugins/`, or install the zip from *Plugins → Add New → Upload*. Activate it (WooCommerce must be active).
3. **Configure**: WooCommerce → Settings → Payments → **Monero (xmr-pay)**. Set your **Agent URL**, **Agent token**, and **Webhook secret** (same values you gave the agent). Click **Test connection** to confirm.
4. Set the agent's `FULFILL_WEBHOOK_URL` to the URL shown in the settings, and its `FULFILL_WEBHOOK_SECRET` to your Webhook secret.

== Frequently Asked Questions ==

= Is it custodial? Does the plugin hold my funds or keys? =
No. Funds go to subaddresses of your own wallet. The plugin holds nothing. The agent holds only a **view key** — it can detect payments, it cannot spend.

= Do I have to run the agent? =
Yes — that is what keeps it non-custodial and middleman-free. It is a small Node service (the `xmr-pay` package). It can run on the same box as your store or a separate one.

= How are refunds handled? =
Monero is non-custodial and a transaction does not reveal the sender, so refunds are **manual**: the plugin records the refund in WooCommerce and adds a note reminding you to send the XMR back to a receive address the customer gives you.

= What if a buyer underpays? =
The order stays on hold and the order-received page shows a **top-up QR for the exact shortfall** (same subaddress). The agent sums the payments and completes the order once the total is reached.

= Mainnet or stagenet? =
Both. Point the agent at a mainnet or stagenet node and use the matching address/keys. There is a live stagenet demo store at https://demo.xmrpay.shop

= Where can I test it? =
https://demo.xmrpay.shop — a public stagenet demo. Grab test XMR from a stagenet faucet and try the full flow.

== Changelog ==

= 0.1.5 (beta) =
* Pairs with the **xmr-pay 0.4.0-beta** agent (payment-correctness + reliability release). No store reconfiguration needed.
* **Funds are never orphaned on expiry.** A partially-paid order is no longer auto-cancelled when its window lapses — the plugin asks the agent, keeps it on hold, and shows a "your funds are safe — contact us" message with the QR hidden.
* **Overpayment is surfaced.** When a buyer overpays, the exact excess is recorded on the order (meta + note) and shown in the admin payment meta box.
* **Reconcile safety net.** A 5-minute cron re-polls the agent for on-hold orders and completes (or flags) any the webhook missed — covers webhook outages and node catch-up.
* **Sync-status on the checkout.** When the agent's node is catching up, the payment page shows "node catching up…" instead of a silent "unpaid".
* Settings now note that underpayment tolerance / confirmations / expiry are configured **agent-side** (`XMR_TOLERANCE_XMR`, `XMR_MIN_CONFIRMATIONS`, `XMR_EXPIRY_HOURS`).

= 0.1.4 (beta) =
* Guided setup wizard: a step-by-step onboarding (welcome → connect agent → webhook → pricing → go live) that mirrors the `npx xmr-pay` agent flow, so the three values the agent prints (Agent URL, token, webhook secret) land in the right places without hunting through the full settings form. Live "Test connection" gates the step; the webhook URL is shown with a copy button. Opens automatically on activation; reachable any time from the plugins list ("Setup wizard") or the admin notice. The full settings page is unchanged for fine-tuning.

= 0.1.3 (beta) =
* Cryptographic receipts: once an order is paid, the order-received page shows a "Download receipt" (a merchant-signed receipt) plus a one-click link to a bundled, offline, dependency-free verifier — anyone can verify it forever, no third party. The signed receipt is also stored on the order.
* New "Redirect after payment (URL)" setting: send the buyer to a custom page (a thank-you, a digital download, etc.) the moment the payment confirms. Supports {order_id} / {order_key} placeholders.
* test_amount override is now honoured only when the agent is on a confirmed test network (stagenet/testnet) — it can never silently fix the price on a live store.

= 0.1.2 (beta) =
* Add a "Payment box theme" setting (light/dark) so the QR/payment box matches your store theme instead of always rendering light.

= 0.1.1 (beta) =
* Fix: the "open the payment page" link in the order email pointed at the order-pay page, which redirects for on-hold orders — it now links to the order-received page that renders the QR + live status.

= 0.1.0 (beta) =
* First public beta. Gateway (classic + Blocks), HPOS support, XMR-native + CoinGecko/fixed pricing, live on-chain progress + top-up, signed HMAC webhooks, exact piconero math, "Test connection", order payment meta box, debug logging.

== Upgrade Notice ==

= 0.1.5 =
Pairs with the xmr-pay 0.4.0-beta agent. Partially-paid orders are never auto-cancelled (funds stay safe), overpayment is recorded, and a 5-minute reconcile cron backs up the webhook. Upgrade both plugin and agent.

= 0.1.4 =
Adds a guided setup wizard that walks you through connecting your agent, the webhook, and pricing — no change to existing configured stores.

= 0.1.3 =
Adds downloadable, verifiable cryptographic receipts and an optional post-payment redirect.

= 0.1.2 =
Adds a light/dark theme option for the payment box.

= 0.1.1 =
Fixes the payment-page link in order emails.
