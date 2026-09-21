# xmr-pay for WooCommerce

Accept Monero in WooCommerce. Payments go directly to the merchant's wallet.
Native modes verify transactions in WordPress using a private view key; agent
mode connects WordPress to a local XMRPay agent. No mode holds a spend key.

Requires PHP 7.4+, WordPress 6.2+ and WooCommerce 7.0+. Native verification needs
GMP and BCMath. The plugin supports classic checkout, Blocks and HPOS.

The plugin directory is `nodewatch-monero`; its display name is
**Nodewatch Monero Payments for WooCommerce**. It is part of the XMRPay project.

## Payment modes

| Mode | Buyer action | View key stored on | Separate process |
|---|---|---|---|
| Auto-detect (`watch`) | pays the order subaddress | WordPress | none |
| "I've paid" (`proof`) | submits a transaction ID | WordPress | none |
| Agent | pays the order subaddress | local agent | Node agent |

Native proof mode uses the merchant's view key. It does not accept a keyless
transaction proof through an external verifier. The plugin bundles a
WordPress-specific PHP scanner; it does not execute the npm library in PHP.

The server checks payment evidence before completing an order. Browser status
and widget events do not authorize fulfillment. Native scanning requires every
configured node to respond and agree; adding a node can reduce availability if
that node fails. The agent's node selection is failover, not quorum.

[Release downloads](https://github.com/SlowBearDigger/xmr-pay-woocommerce/releases)
· [JavaScript library](https://github.com/SlowBearDigger/xmr-pay)
· [Data and footprint](nodewatch-monero/docs/DATA-AND-FOOTPRINT.md)

## Quick start

1. Install and activate the plugin (drop `nodewatch-monero/` in
   `wp-content/plugins/`, or upload the zip). A guided setup wizard helps on first run.
2. Go to **WooCommerce > Settings > Payments > Monero (Nodewatch)**.
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

Configure the address, view key, nodes, confirmations, pricing, tolerance and
checkout presentation. Automatic expiry is optional and defaults to disabled. The "Agent settings" section applies only to Agent mode;
leave it blank otherwise.

Each node has its own authentication setting: **None** for an open RPC endpoint,
**Basic** for HTTP Basic authentication, or **Digest** for HTTP Digest authentication.
Enter the username and password in that node's row; credentials are scoped to that node. Digest requires PHP's cURL extension. Keep RPC behind
HTTPS or a private network because Basic credentials are only encoded, not encrypted.

For example, an Umbrel node reachable on your private network could be entered as
`http://umbrel.local:18081`, with the authentication type and credentials configured
on that Umbrel RPC endpoint. Do not put credentials in the URL. Use a dedicated,
read-only RPC account where your node or reverse proxy supports one, and do not share
the same password across nodes.

## What it stores and what it touches

No custom database tables. Settings live in an option row, per-order data in order
meta, with auxiliary options, caches and scheduled jobs. Outbound connections
include configured Monero nodes, CoinGecko when selected, and the local agent in agent mode. The plugin also drops the
buyer's IP and browser from Monero orders (there are no chargebacks to dispute).
Full map: [`docs/DATA-AND-FOOTPRINT.md`](nodewatch-monero/docs/DATA-AND-FOOTPRINT.md).

## Operational limits

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
> node gives you control over availability, but does not guarantee immediate detection.
> **You need both the GMP and BCMath PHP extensions** for the no-server modes. Enable
> them in the PHP runtime that actually executes WordPress, not only in the Monero-node
> container or the host's command-line PHP. In a container setup, install/enable
> `ext-gmp` and `ext-bcmath` in the WordPress/PHP image, then restart that container.

## Expiry and migration

Automatic expiry defaults to `0` (disabled). Detected partial, pending and locked
payments stay open. PHP watch mode scans blocks and can miss transfers still in
the mempool; native proof mode needs a submitted transaction ID. Enabling expiry
therefore requires a process for reconciling late or unseen payments.

Agent mode requires `AGENT_TOKEN` even on loopback. Configure the same token in
WordPress and keep the agent URL local. Existing agent installations must apply
the [dependency guidance](https://github.com/SlowBearDigger/xmr-pay/blob/main/SECURITY.md#dependencies).
After upgrading, test payment and callback handling on staging before mainnet.

## Docs

> FAQ and guide (plain-language and technical): [xmr-pay/docs/FAQ.md](https://github.com/SlowBearDigger/xmr-pay/blob/main/docs/FAQ.md)
> Data and footprint: [`docs/DATA-AND-FOOTPRINT.md`](nodewatch-monero/docs/DATA-AND-FOOTPRINT.md)
> How WordPress-native verification works: [`docs/WP-NATIVE-VERIFICATION.md`](docs/WP-NATIVE-VERIFICATION.md)
> Agent mode: [xmr-pay/docs/AGENT.md](https://github.com/SlowBearDigger/xmr-pay/blob/main/docs/AGENT.md)

<br>

---

# For developers

## Local dev and test (no Docker)

Needs Node and PHP (`wp-now` uses SQLite, no MySQL). Boot WordPress + WooCommerce +
this plugin via the included blueprint:

```bash
cd nodewatch-monero
npx @wp-now/wp-now start --blueprint=../blueprint.json --port=8881
# http://localhost:8881  (auto-login; configure the gateway and create a test product before checkout)
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

Classic and Blocks (Store API) checkout, the three modes above, per-order
subaddresses, QR via the bundled `<xmr-pay>` widget, signed receipts, HPOS-compatible,
and a guided setup wizard. The no-server mode sums installment and top-up payments and
preserves detected partial funds, with the settlement math hardened to be independent of
the order a node returns transactions in (covered by the aggregation test suite).

## Acknowledgements

Shares amount and state contracts with the [xmr-pay library](https://github.com/SlowBearDigger/xmr-pay) and uses these open-source components:

> [monero-integrations / monerophp](https://github.com/monero-integrations/monerophp) (MIT): the pure-PHP ed25519, key-derivation and base58 primitives the WordPress-native verifier is vendored on. Used by the native scanner.
> [kornrunner/php-keccak](https://github.com/kornrunner/php-keccak) (MIT): Keccak-256 with Monero's padding, in pure PHP.
> [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) (MIT): the bundled `<xmr-pay>` widget's self-contained QR encoder.
> [monero-ts](https://github.com/woodser/monero-ts) (woodser, MIT): the WASM reference the PHP verifier is cross-checked against.
> Inspiration: [BTCPay Server](https://btcpayserver.org/)'s Monero plugin and [MoneroPay](https://gitlab.com/moneropay/moneropay), related open-source payment projects.

## License

MIT. A [GoXMR](https://goxmr.click) project.

Donations support maintenance:
> `45sEohkyWYxAfHy8ekP7B34Bd3qhgrupcQfUQAHvfUWkfgqJhCA4QYLigrBg8G8TE4WggtMGpmjXrbmvepkWLec58KKLkm9`
