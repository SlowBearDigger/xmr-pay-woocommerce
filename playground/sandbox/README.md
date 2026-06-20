# live.xmrpay.shop — the configure-it-yourself sandbox

A **real** WordPress on the VPS where anyone can sign in and configure the plugin
(run the wizard, change settings, place a stagenet order) — but can't touch the
server. Unlike WordPress Playground it has GMP + reaches a node, so the no-server
watch mode actually detects payments live. This documents how it's wired so it's
reproducible.

## Pieces

- **Site:** CyberPanel website `live.xmrpay.shop` (docroot `/home/live.xmrpay.shop/public_html`, lsphp user `livex9276`), Let's Encrypt SSL. Vhost is plain static-PHP (no reverse proxy — an earlier Playground-launcher proxy to `:8780` was reverted; `vhost.conf.bak.*` kept).
- **DB:** `livexmr_wp` / user `livexmr` (password in `/root/.live-db-pass`, chmod 600).
- **It's a clone of demo.xmrpay.shop** (WP + WooCommerce + the plugin, watch mode, stagenet): `rsync` the docroot (minus `wp-config.php`), fresh `wp config create` for the new DB, `wp db import` of demo's dump, then `wp search-replace demo.xmrpay.shop live.xmrpay.shop --all-tables` + `wp rewrite flush`.
- **The sandbox behaviour** is one must-use plugin: [`sandbox-mu.php`](sandbox-mu.php) →
  `/home/live.xmrpay.shop/public_html/wp-content/mu-plugins/`. It (1) auto-signs every
  visitor in as the `sandbox` user (role `shop_manager` — manages the store/plugin, not
  the server), (2) denies install/edit-plugins/themes/users/files caps as defence in
  depth, (3) shows a sandbox banner + admin notice, (4) strips the buyer IP/user-agent.
- **wp-config:** `DISALLOW_FILE_MODS` + `AUTOMATIC_UPDATER_DISABLED` = `true` (no
  plugin/theme install/edit/update UI — the hard lock).

## Gotchas (learned the hard way)

- **Asset perms.** `rsync --no-perms` inherits the destination umask and can land plugin
  assets at `0640` (no world-read) → lsws serves **HTTP 403** → e.g. `wizard.js` doesn't
  load and the wizard "Next" button silently dies. Fix: `chmod 644` the plugin's
  `assets/*`. If a single static asset 403s, check world-read first.
- **Clone permalinks.** After the clone, pretty-permalink pages (`/cart/`, `/checkout/`)
  404 until `wp rewrite flush --hard` **and** an `lswsctrl restart` (OLS caches the rules).
- **Auto-login + wp-admin.** The cookie is set on the first front-end hit, so the natural
  flow (land on the home → banner → "Setup wizard") works; a cold direct hit to `/wp-admin`
  redirects to login once.

## Related services (documented in xmrpay-shop/DEPLOY.md)

- `xmrpay-donate.service` — the landing's mainnet donation agent (`:8790`, view-only, set to
  `XMR_MIN_CONFIRMATIONS=0` for instant showcase). `.env` at `/home/deploy/xmrpay-shop/server/`.
- `xmrpay-web.service` — serves the landing + proxies `/api` to the donation agent.
