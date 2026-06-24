=== xmr-pay for WooCommerce ===
Contributors: slowbeardigger
Donate link: https://xmrpay.shop
Tags: monero, xmr, cryptocurrency, payment gateway, woocommerce
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept Monero (XMR) in WooCommerce, non-custodial, with no backend. WordPress verifies payments itself; funds go straight to your own wallet.

== Description ==

**xmr-pay for WooCommerce** lets your store accept Monero with **no third party in the payment path** and **no backend to run**. WordPress verifies payments itself, in PHP, against a public Monero node, the plugin never holds funds or a spend key.

* **Non-custodial.** Each order is paid to *your* wallet. You control the funds on-chain; no one else can move them.
* **No backend, no middleman, no API keys, no accounts.** The two default modes do the Monero crypto in pure PHP (vendored, audited), no Node, no `monero-wallet-rpc`, nothing running 24/7. The only external thing needed is a Monero node (a public one is fine); your view key never leaves your server.
* **Three modes, you choose.** *Auto-detect in WordPress* (recommended, no buyer action, WordPress scans the chain); *Buyer taps "I've paid"* (the lightest, the buyer pastes the transaction ID); or *Agent* (advanced, run the separate `xmr-pay` daemon).
* **Blocks + classic checkout, HPOS-ready.** Shows up at the modern WooCommerce Blocks checkout and the classic one.
* **Exact amounts.** Conversions are computed in piconero (integer math, GMP), no float drift. Discounts, shipping, taxes and fees are already in the order total, so they "just work".
* **Live payment progress + receipts.** The order-received page shows the on-chain status; overpayment is recorded for a manual refund; paid orders can carry a signed, offline-verifiable receipt.

= How it works =

**Auto-detect** (no buyer action): `checkout > order paid to a per-order subaddress of your wallet > buyer pays > WordPress scans the chain (with your view key, via a public node) and completes the order.`

**Buyer taps "I've paid"**: `checkout > buyer pays your address > buyer pastes the transaction ID > WordPress verifies it on-chain and completes the order.`

**Agent** (advanced): you run the separate [xmr-pay daemon](https://github.com/SlowBearDigger/xmr-pay/blob/main/docs/AGENT.md); it holds the view key and notifies the store with a signed webhook.

The two default modes need **no server**, just your WordPress + a Monero node. Requires the PHP **GMP** and **BCMath** extensions. Full guide: the FAQ at https://github.com/SlowBearDigger/xmr-pay/blob/main/docs/FAQ.md

= Pricing =

Price natively in XMR (set your store currency to XMR, no feed needed), or price in fiat and convert via CoinGecko (your own API key supported) or a fixed rate.

== Installation ==

Default (no server, recommended):

1. **Install this plugin**: upload the `xmr-pay-for-woocommerce` folder to `wp-content/plugins/`, or install the zip from *Plugins > Add New > Upload*. Activate it (WooCommerce must be active). Your host needs the PHP **GMP** and **BCMath** extensions.
2. **Configure**: WooCommerce > Settings > Payments > **Monero (xmr-pay)**. Pick a mode (**Auto-detect in WordPress** is recommended), then set your **Monero address**, **private view key**, and a **Monero node** URL. For confirmations, `1` is a good default.
3. (More private) Instead of pasting the view key, put `define( 'XMRPAY_VIEW_KEY', '…' );` in `wp-config.php`.
4. Test on **stagenet** first, then switch to your mainnet wallet.

Advanced (Agent mode): run the separate `xmr-pay` daemon (`npm i xmr-pay monero-ts`, then `scanner-agent.js` with your address + view key + node + a webhook secret, bound to localhost, see `docs/AGENT.md`), choose the **Agent** mode, and set the Agent URL / token / webhook secret it prints.

== Frequently Asked Questions ==

= Is it custodial? Does the plugin hold my funds or keys? =
No. Payments go to your own wallet. No spend key ever lives in WordPress, so no one but you can move the funds. The no-server modes keep your **view key** (view-only, it can detect payments, it cannot spend) on your own server; Agent mode keeps it on your agent.

= Do I have to run a backend / agent? =
No. The default modes verify payments **inside WordPress itself** (pure PHP, against a public Monero node), nothing to run 24/7. Agent mode (running the separate `xmr-pay` daemon) is optional, for merchants who prefer it.

= What do I need? =
A Monero address, a Monero node URL (a public one is fine), and, for the auto-detect / "I've paid" modes, your wallet's private view key and the PHP **GMP** and **BCMath** extensions. That's it.

= How are refunds handled? =
Monero is non-custodial and a transaction does not reveal the sender, so refunds are **manual**: the plugin records the refund in WooCommerce and adds a note reminding you to send the XMR back to a receive address the customer gives you.

= What if a buyer underpays or overpays? =
Underpaid: the order stays on hold (you can allow a small tolerance for dust). Overpaid: the order completes and the exact excess is recorded so you can refund the difference by hand.

= Mainnet or stagenet? =
Both. Use a matching address + node (and view key) for the network you want. There is a live stagenet demo store at https://demo.xmrpay.shop

= Where can I test it? =
https://demo.xmrpay.shop, a public stagenet demo. Grab test XMR from a stagenet faucet and try the full flow.

== External services ==

This plugin does **not** track you or your customers, sends **no** analytics, and does **not** phone home to the plugin author. It connects only to the service(s) **you** configure, to do its job:

1. **A Monero node** (the node URL you enter in the settings, a public one or your own). In the no-server modes, the plugin sends transaction IDs and chain-height queries over HTTP to read the blockchain (daemon RPC: `get_transactions`, `get_block`, `get_height`, `get_info`). No personal data is sent; the node sees the transaction ID it is asked about and your server's IP. The node is yours to choose, so you choose who you trust, run your own for maximum privacy. (Default suggestion: a community stagenet node for testing.)

2. **CoinGecko**, *only* if you price your store in a fiat currency and choose the "CoinGecko" price source. The plugin requests the current Monero price for your currency from `https://api.coingecko.com`. No store or customer data is sent (only the currency code). You can avoid it entirely by pricing your store in XMR, using a fixed rate, or using your own price URL. CoinGecko terms: https://www.coingecko.com/en/terms · privacy: https://www.coingecko.com/en/privacy

3. **Your own xmr-pay agent**, *only* in the optional "Agent" mode, the plugin talks over HTTP to the agent daemon **you** run (on your own machine). It is your software; nothing leaves your control.

Your Monero **private view key** (used by the no-server modes) stays on your own server, it is never sent to any external service, including the node.

== Changelog ==

= 1.1.2 =
* **Fix (remote node setup):** WordPress's `wp_safe_remote_*` only allows a short list of ports and silently blocks Monero RPC ports like 18081 (mainnet) / 38089 (stagenet), so pointing the plugin at a remote node failed until you hand-added an `http_allowed_safe_ports` filter. The plugin now whitelists the ports of YOUR configured node(s) automatically (and only those — the safe-HTTP SSRF guard still protects every other host/port). Thanks to the tester who reported this. No settings change.

= 1.1.1 =
* **Fix (payment detection):** the per-output scan loop wrapped ownership AND amount/commitment decoding in one catch, so an error while decoding an output that IS yours (e.g. a pruned node, a malformed blob) was indistinguishable from "not yours" and could report a real payment as unpaid. Ownership and decoding are now separated: an undecodable but owned output fails closed and is surfaced (found-but-unverified), never silently missed. Hardening only; no settings or data change.

= 1.1.0 =
* **Non-custodial refunds (claim-link).** Refund an order and the buyer gets a link to enter a Monero receive address (a tx never reveals the sender); you pay it by hand and mark it sent. Configurable link expiry with one-click reissue, address + network validation, and a reopen on a later refund so additional money is never stranded. Something BTCPay's Monero plugin cannot do.
* **Monero payments report + CSV export** under WooCommerce (owed / received / overpaid / confirmations / state / refund status), spreadsheet-formula-injection safe.
* **Resilient multi-node.** Comma-separated nodes now genuinely fail over, and the block height is cross-checked across them (the lowest is used), so a lagging node can only delay a payment, never confirm it early.
* **Critical fix:** the order-completion and proof paths used a non-existent WordPress function for their lock; replaced with a real atomic mutex. Also: a watch order is no longer created in an unscannable state when the node is briefly unreachable (the checkout fails cleanly so the buyer retries), a wrong-network refund address is rejected, and a mempool double-spend is never credited.
* Tested PHP 7.4 / 8.1 / 8.3 in CI.

= 1.0.1 =
* **Security: burning-bug defence.** The no-server scanner now deduplicates payments by the one-time output key, not by the transaction id. Two outputs that share a one-time key (a 2018-style burn, even across different transactions) are at most one spendable output, so they can never credit an order twice.
* **Requires both GMP and BCMath.** The pure-PHP verifier needs the BCMath extension (for base58) in addition to GMP (for the money math). The gateway now hides itself, and the settings warn, on a host missing either one, instead of failing after a buyer paid.
* Hardened the settlement math to be fully order-independent (a duplicate that disagrees on lock status can never change the verdict by row order).

= 1.0.0 =
* **First stable release.** The no-server (pure-PHP) and agent modes are both production-tested with an adversarial test suite (order-independence, dedup, byzantine duplicates, false-paid hunting).
* **No-server mode now sums multiple payments.** A buyer who pays in installments, or sends a small test transaction then the rest, now completes automatically once the total reaches the price, matching the agent mode. Previously the WP-native scanner tracked only the first transaction.
* **Funds are never stranded.** A watch-mode order that received a partial payment is flagged and kept open (never auto-cancelled at expiry) and completes on a top-up.
* Hardened the settlement math to be independent of the order the node returns transactions in, so a duplicate or out-of-order response can never over- or under-credit an order.

= 0.1.8 (beta) =
* **Clearer network indicator.** The settings now show which network you are on (mainnet / stagenet / testnet) as a badge, and the wizard + address field explain that the network follows your address, there is no separate toggle. To go live, swap the address, view key and node to your mainnet ones.

= 0.1.7 (beta) =
* **Privacy, Monero orders no longer store the buyer's IP address or user-agent.** WooCommerce records both on every order; since Monero is irreversible (no chargebacks), there's no dispute reason to keep them, so the plugin strips them from its orders.
* The order's **confirmation count now updates while the payment is maturing**, the admin shows progress ("received, N confirmations") before the order settles, not just the final value. (The buyer's checkout page already updated live.)

= 0.1.6 (beta) =
* **No-server modes, accept Monero with no external agent.** The plugin can now verify payments in **pure PHP** (vendored Monero crypto: ed25519, Keccak, base58), in two new modes: **Auto-detect** (a view-only watch over your address + node) and **"I've paid"** (the buyer pastes a transaction ID, verified on-chain). The advanced **Agent** mode is still there. The no-server modes need the **GMP** and **BCMath** PHP extensions (most hosts have both, or can enable them).
* **Mode-aware setup wizard** with a live **"Check setup"**, confirms the node is reachable, the network matches your address, and your **view key actually belongs to the address** before you go live.
* **Pricing your way.** Fixed-rate fallback when a feed is down, and you can point at **your own price-source URL** (JSON) instead of CoinGecko. The custom URL is fetched with SSRF protection.
* **Fix:** the setup wizard (and the post-activation redirect) could return *"Sorry, you are not allowed to access this page."*, the hidden admin page is now registered so direct access works.
* **Fix:** the **"Check setup" / "Test connection"** buttons returned HTTP 400, their admin-ajax handlers are now registered correctly.
* **WordPress.org readiness:** all inline scripts moved into enqueued asset files, ABSPATH guards on bundled files, translation template (.pot), and an **"External services"** privacy disclosure in the readme.
* Refreshed the bundled checkout widget to the latest build.

= 0.1.5 (beta) =
* Pairs with the **xmr-pay 0.4.0-beta** agent (payment-correctness + reliability release). No store reconfiguration needed.
* **Funds are never orphaned on expiry.** A partially-paid order is no longer auto-cancelled when its window lapses, the plugin asks the agent, keeps it on hold, and shows a "your funds are safe, contact us" message with the QR hidden.
* **Overpayment is surfaced.** When a buyer overpays, the exact excess is recorded on the order (meta + note) and shown in the admin payment meta box.
* **Reconcile safety net.** A 5-minute cron re-polls the agent for on-hold orders and completes (or flags) any the webhook missed, covers webhook outages and node catch-up.
* **Sync-status on the checkout.** When the agent's node is catching up, the payment page shows "node catching up…" instead of a silent "unpaid".
* Settings now note that underpayment tolerance / confirmations / expiry are configured **agent-side** (`XMR_TOLERANCE_XMR`, `XMR_MIN_CONFIRMATIONS`, `XMR_EXPIRY_HOURS`).

= 0.1.4 (beta) =
* Guided setup wizard: a step-by-step onboarding (welcome > connect agent > webhook > pricing > go live) that mirrors the `npx xmr-pay` agent flow, so the three values the agent prints (Agent URL, token, webhook secret) land in the right places without hunting through the full settings form. Live "Test connection" gates the step; the webhook URL is shown with a copy button. Opens automatically on activation; reachable any time from the plugins list ("Setup wizard") or the admin notice. The full settings page is unchanged for fine-tuning.

= 0.1.3 (beta) =
* Cryptographic receipts: once an order is paid, the order-received page shows a "Download receipt" (a merchant-signed receipt) plus a one-click link to a bundled, offline, dependency-free verifier, anyone can verify it forever, no third party. The signed receipt is also stored on the order.
* New "Redirect after payment (URL)" setting: send the buyer to a custom page (a thank-you, a digital download, etc.) the moment the payment confirms. Supports {order_id} / {order_key} placeholders.
* test_amount override is now honoured only when the agent is on a confirmed test network (stagenet/testnet), it can never silently fix the price on a live store.

= 0.1.2 (beta) =
* Add a "Payment box theme" setting (light/dark) so the QR/payment box matches your store theme instead of always rendering light.

= 0.1.1 (beta) =
* Fix: the "open the payment page" link in the order email pointed at the order-pay page, which redirects for on-hold orders, it now links to the order-received page that renders the QR + live status.

= 0.1.0 (beta) =
* First public beta. Gateway (classic + Blocks), HPOS support, XMR-native + CoinGecko/fixed pricing, live on-chain progress + top-up, signed HMAC webhooks, exact piconero math, "Test connection", order payment meta box, debug logging.

== Upgrade Notice ==

= 1.0.1 =
Security hardening: burning-bug defence (dedup by output key), requires the BCMath extension alongside GMP, and fully order-independent settlement. Recommended for all no-server installs.

= 1.0.0 =
First stable release. No-server mode now sums installment/top-up payments and never strands partial funds. No store reconfiguration needed.

= 0.1.5 =
Pairs with the xmr-pay 0.4.0-beta agent. Partially-paid orders are never auto-cancelled (funds stay safe), overpayment is recorded, and a 5-minute reconcile cron backs up the webhook. Upgrade both plugin and agent.

= 0.1.4 =
Adds a guided setup wizard that walks you through connecting your agent, the webhook, and pricing, no change to existing configured stores.

= 0.1.3 =
Adds downloadable, verifiable cryptographic receipts and an optional post-payment redirect.

= 0.1.2 =
Adds a light/dark theme option for the payment box.

= 0.1.1 =
Fixes the payment-page link in order emails.
