<?php
/**
 * Monero (xmr-pay) WooCommerce payment gateway.
 *
 * Non-custodial: payments go straight to the merchant's own Monero address; this gateway never
 * holds funds and never holds a spend key. It has THREE modes; the no-server ones are the default:
 *
 *   watch (default) → process_payment() mints a per-order subaddress; WordPress itself scans the
 *                     chain in pure PHP (view key only) and completes the order. No daemon to run.
 *   proof           → buyer submits a tx id + proof; WordPress verifies it on-chain in pure PHP.
 *   agent           → optional: a separate self-hosted xmr-pay scanner-agent does the scanning and
 *                     POSTs a signed order.paid webhook; this gateway is then a thin HMAC-verifying
 *                     client. Use it only at scale.
 *
 * In all modes the buyer's browser polls THIS plugin for status (the bundled <xmr-pay> widget),
 * and mark_paid() is the single idempotent completion path.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Gateway_XmrPay extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'xmrpay';
		$this->method_title       = __( 'Monero (Nodewatch)', 'nodewatch-monero' );
		$this->method_description = __( 'Accept Monero, non-custodial. Funds go straight to your address. WordPress verifies payments itself in PHP (no server) — or point it at your own agent daemon at scale.', 'nodewatch-monero' );
		$this->has_fields         = false;
		$this->icon               = apply_filters( 'woocommerce_xmrpay_icon', plugins_url( 'assets/monero-symbol.png', XMRPAY_WC_FILE ) );
		// non-custodial: we hold no spend key, so we never AUTO-send a refund. 'refunds' IS
		// supported, but as a CLAIM-LINK flow: process_refund records a pending refund and a
		// buyer claim-link (the buyer supplies a receive address, since a Monero tx never
		// reveals the sender); the merchant pays it by hand and marks it sent. This is the
		// thing BTCPay's Monero plugin cannot do. See process_refund / handle_refund.
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Monero (XMR)', 'nodewatch-monero' ) );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'render_payment_panel' ) );
		// the order-pay page (paying an existing on-hold order from the email/account)
		// renders the SAME panel — the buyer sees the existing QR, not a new order.
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'render_payment_panel' ) );
		// put the address + a link to the live payment page in the order email, so a
		// buyer who closed the tab can still pay.
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		add_action( 'woocommerce_api_xmrpay_webhook', array( $this, 'handle_webhook' ) );
		// proof mode: the buyer submits a txid here and WordPress verifies it on-chain.
		add_action( 'woocommerce_api_xmrpay_verify', array( $this, 'handle_verify' ) );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 10, 2 );
		// non-custodial refund claim-link: the buyer opens it and supplies a Monero receive
		// address (order_key is the capability, mirroring handle_verify). Buyer-facing page.
		add_action( 'woocommerce_api_xmrpay_refund', array( $this, 'handle_refund' ) );
		// admin: merchant records the manual payout txid and marks the refund sent (audit trail).
		add_action( 'admin_post_xmrpay_refund_sent', array( $this, 'handle_refund_sent' ) );
		// admin: merchant reissues an expired refund claim-link (resets the expiry clock).
		add_action( 'admin_post_xmrpay_refund_reissue', array( $this, 'handle_refund_reissue' ) );
		// privacy: Monero orders carry no IP / user-agent. Monero is irreversible
		// (no chargebacks), so there is no fraud-dispute reason to retain them.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'strip_pii' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'strip_pii' ), 20, 2 );
		// admin: a payment-detail block on the order screen (HPOS-safe hook).
		// NB: the wp_ajax_xmrpay_test_* handlers are registered at the top level (in
		// the main plugin file), NOT here — admin-ajax requests don't construct the
		// gateway, so a handler bound in this constructor would never fire (HTTP 400).
		add_action( 'admin_notices', array( $this, 'maybe_warn_gmp' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'admin_order_details' ) );
	}

	/**
	 * Privacy: drop the buyer's IP address + user-agent on Monero orders (classic
	 * and Store API checkout). WooCommerce records both on every order; for an
	 * irreversible currency there is no chargeback/dispute reason to keep them.
	 */
	public function strip_pii( $order, $data = null ) {
		$method = ( is_array( $data ) && ! empty( $data['payment_method'] ) ) ? $data['payment_method'] : $order->get_payment_method();
		if ( $this->id === $method ) {
			$order->set_customer_ip_address( '' );
			$order->set_customer_user_agent( '' );
		}
	}

	/** Write to the WooCommerce log (source: xmrpay) when debug logging is on. */
	private function log( $message, $level = 'info' ) {
		if ( 'yes' !== $this->get_option( 'debug_log' ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'xmrpay' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable', 'nodewatch-monero' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Monero payments', 'nodewatch-monero' ),
				'default' => 'no',
			),
			'title' => array(
				'title'   => __( 'Title', 'nodewatch-monero' ),
				'type'    => 'text',
				'default' => __( 'Monero (XMR)', 'nodewatch-monero' ),
				'desc_tip'=> true,
				'description' => __( 'What the buyer sees at checkout.', 'nodewatch-monero' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'nodewatch-monero' ),
				'type'    => 'textarea',
				'default' => __( 'Pay privately with Monero. Scan the QR — your wallet fills in the exact amount.', 'nodewatch-monero' ),
			),
			'checkout_theme' => array(
				'title'   => __( 'Payment box theme', 'nodewatch-monero' ),
				'type'    => 'select',
				'default' => 'light',
				'options' => array(
					'light' => __( 'Light (for light store themes)', 'nodewatch-monero' ),
					'dark'  => __( 'Dark (for dark store themes)', 'nodewatch-monero' ),
				),
				'description' => __( 'Match the QR/payment box to your store theme so it does not look bolted-on.', 'nodewatch-monero' ),
			),
			'success_redirect' => array(
				'title'       => __( 'Redirect after payment (URL)', 'nodewatch-monero' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'https://example.com/thank-you',
				'description' => __( 'Optional. When the payment confirms, send the buyer here (a custom thank-you, a digital-download page, etc.) instead of staying on the order-received page. {order_id} and {order_key} are substituted — {order_key} only for a URL on this same site, so the order token is never leaked to a third-party domain. Leave empty for the default WooCommerce behaviour.', 'nodewatch-monero' ),
			),
			'refund_link_days' => array(
				'title'             => __( 'Refund link valid for (days)', 'nodewatch-monero' ),
				'type'              => 'number',
				'default'           => '7',
				'description'       => __( 'When you refund a Monero order, the buyer gets a claim-link to enter a receive address (a Monero payment never reveals the sender). This is how long that link stays valid. After it expires the buyer sees a clear message to contact you, and you can reissue the link from the order screen with one click. Set 0 to never expire.', 'nodewatch-monero' ),
				'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
			),
			'mode' => array(
				'title'   => __( 'How payments are verified', 'nodewatch-monero' ),
				'type'    => 'select',
				'default' => 'watch',
				'options' => array(
					'watch' => __( 'Auto-detect in WordPress — no server (recommended)', 'nodewatch-monero' ),
					'proof' => __( 'Buyer taps “I’ve paid” — no server', 'nodewatch-monero' ),
					'agent' => __( 'Auto-detect via your xmr-pay agent (advanced)', 'nodewatch-monero' ),
				),
				'description' => __( 'All three are non-custodial (funds go straight to your address). <strong>Auto-detect in WordPress</strong> needs no backend: WordPress scans the chain itself (with your view key, against a public node) and completes the order — no buyer action, nothing to run 24/7. <strong>Buyer taps “I’ve paid”</strong> is the lightest: the buyer submits the transaction ID and WordPress verifies it (no scanning). <strong>Agent</strong> uses the separate xmr-pay daemon you run. The “No-server settings” below (address, view key, nodes) apply to the two no-server options; the “Agent settings” apply only to Agent mode.', 'nodewatch-monero' ),
			),
			'proof_section' => array(
				'title' => __( 'No-server settings (Auto-detect & “I’ve paid”)', 'nodewatch-monero' ),
				'type'  => 'title',
				'description' => __( 'Used by both no-server modes — “Auto-detect in WordPress” and “Buyer taps I’ve paid”. Your private view key stays on your own server (we never hold a spend key — funds go straight to your address). WordPress fetches transactions from a public node and verifies them itself.', 'nodewatch-monero' ),
			),
			'network_status' => array(
				'title' => __( 'Network', 'nodewatch-monero' ),
				'type'  => 'network_status',
			),
			'xmr_address' => array(
				'title'       => __( 'Your Monero address', 'nodewatch-monero' ),
				'type'        => 'text',
				'placeholder' => '4... (mainnet) or 5.../7... (stagenet)',
				'description' => __( '<strong>The network is set by this address</strong> — a 4… address is mainnet, 5…/7… is stagenet. To go from stagenet to mainnet (or back), change this address plus the view key and node to that network’s. Buyers pay here; each order gets a unique amount so payments can’t be confused.', 'nodewatch-monero' ),
			),
			'view_key' => array(
				'title'       => __( 'Private view key', 'nodewatch-monero' ),
				'type'        => 'password',
				'description' => __( 'Your wallet’s PRIVATE VIEW key (not the spend key). View-only: it can see incoming payments but cannot spend. <strong>More private:</strong> instead of storing it here, put <code>define(\'XMRPAY_VIEW_KEY\', \'…\');</code> in wp-config.php — then it stays out of the database, this screen, and backups. A value here is used only if the constant is not set.', 'nodewatch-monero' ),
			),
			'nodes' => array(
				'title'       => __( 'Monero node(s)', 'nodewatch-monero' ),
				'type'        => 'text',
				'default'     => 'http://node2.monerodevs.org:38089',
				'description' => __( 'Public node URL(s), comma-separated. Your own node first if you run one. List more than one for resilience: requests fail over to the next node, and the block height is cross-checked across them (the lowest is used, so a lagging node can only delay a payment, never confirm it early). Keep them on the same network and well synced. The node never sees your view key.', 'nodewatch-monero' ),
			),
			'proof_min_conf' => array(
				'title'   => __( 'Confirmations required', 'nodewatch-monero' ),
				'type'    => 'number',
				'default' => '1',
				'description' => __( '0 = accept as soon as it’s seen (fast, mempool); 1 = first block (~2 min); 10 = fully unlocked (safest for high value).', 'nodewatch-monero' ),
				'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
			),
			'proof_tolerance_xmr' => array(
				'title'       => __( 'Underpayment tolerance (XMR)', 'nodewatch-monero' ),
				'type'        => 'text',
				'default'     => '0',
				'description' => __( 'Accept a payment that falls short by up to this much (dust/rounding). 0 = exact. It can never reach the price, so a near-zero payment never settles an order.', 'nodewatch-monero' ),
			),
			'test_node' => array(
				'title' => __( 'Check setup', 'nodewatch-monero' ),
				'type'  => 'test_node',
			),
			'agent_section' => array(
				'title' => __( 'Agent settings (advanced)', 'nodewatch-monero' ),
				'type'  => 'title',
				'description' => __( 'Used only when the “Agent” mode is selected. The separate xmr-pay daemon you run (see docs/AGENT.md) holds your view key and does the scanning — this plugin only talks HTTP to it. Detection policy (confirmations, tolerance, expiry) is set ON the agent (XMR_MIN_CONFIRMATIONS, XMR_TOLERANCE_XMR, XMR_EXPIRY_HOURS). Leave blank if you use a no-server mode.', 'nodewatch-monero' ),
			),
			'agent_url' => array(
				'title'       => __( 'Agent URL', 'nodewatch-monero' ),
				'type'        => 'text',
				'placeholder' => 'http://127.0.0.1:8788',
				'description' => __( 'Base URL of your scanner-agent. Keep it private (localhost or a private network).', 'nodewatch-monero' ),
			),
			'agent_token' => array(
				'title'       => __( 'Agent token', 'nodewatch-monero' ),
				'type'        => 'password',
				'description' => __( 'The AGENT_TOKEN you set on the agent (optional, but recommended).', 'nodewatch-monero' ),
			),
			'test_connection' => array(
				'title' => __( 'Connection', 'nodewatch-monero' ),
				'type'  => 'test_connection',
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook secret', 'nodewatch-monero' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s webhook URL */
					__( 'Set the agent\'s FULFILL_WEBHOOK_SECRET to this, and FULFILL_WEBHOOK_URL to: %s', 'nodewatch-monero' ),
					'<code>' . esc_html( WC()->api_request_url( 'xmrpay_webhook' ) ) . '</code>'
				),
			),
			'pricing_section' => array(
				'title' => __( 'Pricing', 'nodewatch-monero' ),
				'type'  => 'title',
				'description' => __( 'How the cart total becomes an XMR amount. Tip: set your store currency to <strong>XMR</strong> to price natively in Monero — then no price feed is used.', 'nodewatch-monero' ),
			),
			'price_source' => array(
				'title'   => __( 'Price source', 'nodewatch-monero' ),
				'type'    => 'select',
				'default' => 'coingecko',
				'options' => array(
					'coingecko' => __( 'CoinGecko (live fiat → XMR)', 'nodewatch-monero' ),
					'custom'    => __( 'Your own price source (a URL)', 'nodewatch-monero' ),
					'fixed'     => __( 'Fixed rate (set it yourself)', 'nodewatch-monero' ),
				),
				'description' => __( 'How a fiat total converts to XMR (ignored when the store currency is already XMR). For CoinGecko or a custom source, set a <strong>Fixed rate</strong> too and it’s used automatically as a fallback if the live feed is ever unavailable — so checkout never hard-fails.', 'nodewatch-monero' ),
			),
			'coingecko_api_key' => array(
				'title'       => __( 'CoinGecko API key', 'nodewatch-monero' ),
				'type'        => 'password',
				'description' => __( 'Optional — your own CoinGecko Demo/Pro key, so the price feed is yours and not rate-limited. Leave empty to use the free public endpoint.', 'nodewatch-monero' ),
			),
			'custom_rate_url' => array(
				'title'       => __( 'Custom price URL', 'nodewatch-monero' ),
				'type'        => 'text',
				'placeholder' => 'https://your-feed.example/xmr?vs={currency}',
				'description' => __( 'Used with “Your own price source”. A URL returning JSON. <code>{currency}</code>/<code>{CURRENCY}</code> are substituted with the store currency code. It should return the price of 1 XMR in your store currency.', 'nodewatch-monero' ),
			),
			'custom_rate_path' => array(
				'title'       => __( 'Rate JSON path', 'nodewatch-monero' ),
				'type'        => 'text',
				'placeholder' => 'e.g. data.rate  (or leave blank if the body is just a number)',
				'description' => __( 'Dot-path to the number in the JSON response, e.g. <code>monero.usd</code> or <code>data.rates.XMR</code>. Leave blank if the response body is the bare number.', 'nodewatch-monero' ),
			),
			'fixed_rate' => array(
				'title'       => __( 'Fixed rate / fallback', 'nodewatch-monero' ),
				'type'        => 'text',
				'placeholder' => 'e.g. 150',
				'description' => __( 'The price of 1 XMR in your store currency. Used as the rate with “Fixed rate”, and as the automatic fallback for the live sources if their feed is down.', 'nodewatch-monero' ),
			),
			'min_confirmations' => array(
				'title'   => __( 'Confirmations', 'nodewatch-monero' ),
				'type'    => 'number',
				'default' => '1',
				'description' => __( 'Shown to the buyer; the agent enforces its own XMR_MIN_CONFIRMATIONS. Raise both for high-value carts.', 'nodewatch-monero' ),
			),
			'test_amount' => array(
				'title'       => __( 'Test amount (XMR)', 'nodewatch-monero' ),
				'type'        => 'text',
				'placeholder' => '',
				'description' => __( 'TEST ONLY — charges this exact XMR amount, ignoring the cart total. It is honoured ONLY when your agent is on stagenet/testnet (run "Test connection" first); on mainnet it is ignored, so it can never fix the price on a live store. Leave empty in production.', 'nodewatch-monero' ),
			),
			'expiry_hours' => array(
				'title'       => __( 'Auto-cancel after (hours)', 'nodewatch-monero' ),
				'type'        => 'number',
				'default'     => '0',
				'description' => __( 'Cancel an unpaid order this many hours after it was placed (frees reserved stock). 0 = never. A late payment to a cancelled order is flagged for you to reconcile by hand, not auto-completed. This window doubles as your rate-drift guard: if you price in fiat, an order paid much later settles at the XMR amount locked at checkout, so set this to how long you are willing to honour that rate (e.g. 12–24). If you price natively in XMR, the amount owed never changes — leave it at 0.', 'nodewatch-monero' ),
				'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
			),
			'debug_log' => array(
				'title'       => __( 'Debug log', 'nodewatch-monero' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log agent calls and webhooks to WooCommerce → Status → Logs (source: xmrpay)', 'nodewatch-monero' ),
				'default'     => 'no',
			),
		);
	}

	/** Scheduled cleanup: cancel unpaid on-hold orders past the expiry window. */
	public function expire_orders() {
		$hours = (int) $this->get_option( 'expiry_hours' );
		if ( $hours <= 0 ) {
			return;
		}
		$ids = wc_get_orders( array(
			'status'         => 'on-hold',
			'payment_method' => $this->id,
			'date_created'   => '<' . ( time() - $hours * HOUR_IN_SECONDS ),
			'limit'          => 100,
			'return'         => 'ids',
		) );
		foreach ( $ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order || $order->is_paid() || (string) $order->get_meta( '_xmrpay_address' ) === '' ) {
				continue;
			}
			$mode = (string) $order->get_meta( '_xmrpay_mode' );

			// watch mode: run one final on-chain scan (same as reconcile) before
			// cancelling — a payment may have arrived in the last cron window.
			if ( 'watch' === $mode ) {
				// if the node was unreachable, scan_order returns false — we could NOT look,
				// so treat it like agent mode does: never cancel on data we couldn't refresh.
				// A payment that landed but we can't see right now must survive to the next run.
				if ( false === $this->scan_order( $order ) ) {
					$this->log( 'expiry deferred for watch order #' . $oid . ' — node unreachable' );
					continue;
				}
				$order = wc_get_order( $oid ); // re-fetch: scan_order may have completed it
				if ( ! $order || $order->is_paid() ) { continue; }
				if ( 'yes' === $order->get_meta( '_xmrpay_partial_flagged' ) ) { continue; } // funds received, merchant reconciles
				$order->update_status( 'cancelled', __( 'Auto-cancelled: no Monero payment within the expiry window.', 'nodewatch-monero' ) );
				$this->log( 'expired watch-mode order #' . $oid );
				continue;
			}

			// proof mode: buyer must have submitted their txid by now — just cancel.
			if ( 'proof' === $mode ) {
				$order->update_status( 'cancelled', __( 'Auto-cancelled: no Monero payment within the expiry window.', 'nodewatch-monero' ) );
				$this->log( 'expired proof-mode order #' . $oid );
				continue;
			}

			// agent mode only: ask the agent before cancelling — a payment may have
			// arrived (full or PARTIAL) that the webhook/poll missed. NEVER cancel an
			// order that received funds — that would strand the buyer's money.
			$r = $this->agent()->get_order( (string) $oid, 6 );
			if ( is_wp_error( $r ) ) {
				continue;   // can't confirm there's no payment → be safe, don't cancel
			}
			if ( true === filter_var( $r['paid'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
				$this->mark_paid( $order, $r );   // arrived just in time
				continue;
			}
			$recv = isset( $r['receivedXmr'] ) && is_numeric( $r['receivedXmr'] ) ? (float) $r['receivedXmr'] : 0.0;
			if ( $recv > 0 ) {
				// PARTIAL payment — keep the order alive (a top-up still completes it)
				// and flag it ONCE for the merchant. matches BTCPay: an expired-but-
				// partially-paid invoice is preserved + flagged, never silently killed.
				if ( 'yes' !== $order->get_meta( '_xmrpay_partial_flagged' ) ) {
					$order->update_meta_data( '_xmrpay_partial_flagged', 'yes' );
					$order->update_meta_data( '_xmrpay_received', (string) $r['receivedXmr'] );
					$order->add_order_note( sprintf(
						/* translators: 1: received XMR, 2: owed XMR */
						__( 'Partial Monero payment received (%1$s of %2$s XMR) but the order passed its expiry window — NOT auto-cancelled. The funds are in your wallet; await the buyer\'s top-up or refund manually.', 'nodewatch-monero' ),
						(string) $r['receivedXmr'], (string) $order->get_meta( '_xmrpay_amount' )
					) );
					$order->save();
					$this->log( 'partial-paid order #' . $oid . ' kept past expiry (not cancelled)' );
				}
				continue;
			}
			$order->update_status( 'cancelled', __( 'Auto-cancelled: no Monero payment within the expiry window.', 'nodewatch-monero' ) );
			$this->log( 'expired unpaid order #' . $oid );
		}
	}

	/**
	 * Safety net: poll the agent for every on-hold xmrpay order and complete the
	 * ones it reports paid. Independent of BOTH the buyer's browser AND the agent's
	 * webhook — so a payment still fulfills even if the webhook never reached us
	 * (endpoint down/blocked, or the buyer closed the tab). mark_paid() is
	 * idempotent, so this is safe to run on a schedule.
	 */
	public function reconcile_on_hold() {
		$ids = wc_get_orders( array(
			'status'         => 'on-hold',
			'payment_method' => $this->id,
			'limit'          => 50,
			'return'         => 'ids',
		) );
		$scanned = 0;
		foreach ( $ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order || $order->is_paid() || (string) $order->get_meta( '_xmrpay_address' ) === '' ) {
				continue;
			}
			// watch mode: the cron is the backstop for buyers who closed the tab. scan a
			// bounded number of orders per tick so a busy store can't blow the time limit.
			if ( $order->get_meta( '_xmrpay_mode' ) === 'watch' ) {
				if ( $scanned >= 8 ) { continue; }   // cap orders scanned per cron tick
				$scanned++;
				$this->scan_order( $order );
				continue;
			}
			// proof mode completes on the buyer's txid submission — nothing to poll here.
			if ( $order->get_meta( '_xmrpay_mode' ) === 'proof' ) {
				continue;
			}
			$r = $this->agent()->get_order( (string) $oid, 6 );
			if ( is_wp_error( $r ) || ! filter_var( $r['paid'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
				continue;
			}
			$this->log( 'reconcile cron: agent reports #' . $oid . ' paid — completing' );
			$this->mark_paid( $order, $r );
		}
	}

	/**
	 * Watch mode: scan the chain for a payment to this order's subaddress and complete it.
	 * Rate-limited (per-order cooldown), checkpointed (only NEW blocks since last scan, plus
	 * a small reorg buffer), and bounded inside the scanner (max blocks + time budget). Once
	 * the paying txid is discovered it is tracked cheaply by txid (no further block scans).
	 * Safe to call from both the buyer's status poll AND the cron — mark_paid is idempotent.
	 * Returns false ONLY when the node was unreachable (so a caller can avoid acting on stale
	 * "no payment" data); true when a real scan ran or none was needed.
	 */
	private function scan_order( $order ) {
		if ( ! $order || $order->get_meta( '_xmrpay_mode' ) !== 'watch' || $order->is_paid() ) {
			return true;
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			return true;
		}
		$id = $order->get_id();
		// per-order cooldown — caps how often THIS order hits a node (also a soft lock).
		$cd = 'xmrpay_scancd_' . get_current_blog_id() . '_' . $id;
		if ( false !== get_transient( $cd ) ) { return true; }   // scanned moments ago — state is fresh
		set_transient( $cd, 1, 20 );

		$address = (string) $order->get_meta( '_xmrpay_address' );
		$view    = $this->view_key();
		if ( $address === '' || $view === '' ) { return true; }
		$scanner = $this->scanner();
		$tip     = $scanner->tip_height();
		// node unreachable — return FALSE so a caller (the expiry cron) does NOT mistake
		// "couldn't look" for "no payment" and cancel an order whose funds we just can't
		// see right now. don't burn the cooldown either, so the next tick retries.
		if ( null === $tip ) { delete_transient( $cd ); return false; }

		$min_conf = (int) $this->get_option( 'proof_min_conf', '1' );
		$tol_pico = XmrPay_Util::xmr_to_pico( $this->get_option( 'proof_tolerance_xmr', '0' ) );
		$exp_pico = XmrPay_Util::xmr_to_pico( (string) $order->get_meta( '_xmrpay_amount' ) );

		// the SET of paying txids found so far (persisted) — a buyer who pays in
		// installments, or sends a small test tx then the rest, completes once the SUM
		// reaches the price. migrate the legacy single-txid meta from older versions.
		$txids = json_decode( (string) $order->get_meta( '_xmrpay_watch_txids' ), true );
		if ( ! is_array( $txids ) ) { $txids = array(); }
		$legacy = (string) $order->get_meta( '_xmrpay_watch_txid' );
		if ( '' !== $legacy && ! in_array( $legacy, $txids, true ) ) { $txids[] = $legacy; }

		$rows = array();
		// re-verify the txids we already know — cheap (one fetch each), bounded, and
		// keeps their confirmation counts current as they mature.
		foreach ( array_slice( $txids, 0, 50 ) as $tx ) {
			$r = $scanner->verify_payment( $tx, $address, $view, array( 'tip' => $tip, 'require_commitment' => true ) );
			if ( empty( $r['found'] ) ) { continue; }
			$rows[] = array(
				'txid'          => $tx,
				'amount_atomic' => isset( $r['amount_atomic'] ) ? $r['amount_atomic'] : '0',
				'confirmations' => array_key_exists( 'confirmations', $r ) ? $r['confirmations'] : null,
				'in_pool'       => ! empty( $r['in_pool'] ),
				'locked'        => ! empty( $r['locked'] ),
				'out_key'       => isset( $r['out_key'] ) ? $r['out_key'] : '',
				'commitment_ok' => ! empty( $r['commitment_ok'] ),
			);
		}

		// scan NEW blocks since the checkpoint (with a small reorg buffer) for ADDITIONAL
		// paying txs — a top-up, or the remainder of an installment payment.
		$birthday   = (int) $order->get_meta( '_xmrpay_birthday' );
		$checkpoint = (int) $order->get_meta( '_xmrpay_scan_height' );
		// re-scan buffer: cover at least 10 blocks, but never fewer than min_conf — so a tx that
		// moves blocks in a reorg before it settles is always still inside the rescan window.
		$from       = max( $birthday, $checkpoint - max( 10, $min_conf ) );
		$res        = $scanner->scan_all( $address, $view, $from, $tip, array( 'tip' => $tip, 'max_blocks' => 30, 'time_budget' => 8.0, 'require_commitment' => true ) );
		$scanned_to = isset( $res['scanned_to'] ) ? (int) $res['scanned_to'] : $checkpoint;
		$matches    = ( isset( $res['matches'] ) && is_array( $res['matches'] ) ) ? $res['matches'] : array();
		foreach ( $matches as $m ) {
			$rows[] = $m;
			if ( '' !== (string) $m['txid'] && ! in_array( $m['txid'], $txids, true ) ) { $txids[] = (string) $m['txid']; }
		}

		$txids = array_values( array_unique( $txids ) );
		$order->update_meta_data( '_xmrpay_watch_txids', wp_json_encode( $txids ) );
		if ( ! empty( $txids ) ) { $order->update_meta_data( '_xmrpay_watch_txid', $txids[0] ); } // legacy panel hint
		$order->update_meta_data( '_xmrpay_scan_height', max( $checkpoint, $scanned_to ) );
		$order->save();

		// SUM every committed payment to the subaddress — the WP-native equivalent of
		// the agent's summarizeTransfers, so the two transports agree on what's paid.
		$sum = XmrPay_Util::summarize_payments( $rows, $exp_pico, $tol_pico, $min_conf );

		// keep the admin confirmation count fresh while a payment matures.
		if ( (int) $sum['confirmations'] !== (int) $order->get_meta( '_xmrpay_confirmations' ) ) {
			$order->update_meta_data( '_xmrpay_confirmations', (int) $sum['confirmations'] );
			$order->save();
		}

		if ( $sum['paid'] ) {
			$this->mark_paid( $order, array(
				'paid'          => true,
				'received_xmr'  => XmrPay_Util::pico_to_string( $sum['received_pico'] ),
				'txids'         => $sum['txids'],
				'confirmations' => (int) $sum['confirmations'],
				'overpaid'      => '0' !== $sum['overpaid_pico'],
				'overpaid_xmr'  => XmrPay_Util::pico_to_string( $sum['overpaid_pico'] ),
			) );
			return true;
		}

		// not paid yet — but if ANY funds have arrived on-chain (confirmed, in mempool, or
		// time-locked), record the amount and flag the order PARTIAL so the expiry cron
		// never cancels an order that already received money, and it auto-completes on a
		// top-up. NEVER strand a buyer's funds.
		if ( gmp_cmp( gmp_init( (string) $sum['seen_pico'], 10 ), 0 ) > 0 ) {
			$order->update_meta_data( '_xmrpay_received', XmrPay_Util::pico_to_string( $sum['received_pico'] ) );
			if ( 'yes' !== $order->get_meta( '_xmrpay_partial_flagged' ) ) {
				$order->update_meta_data( '_xmrpay_partial_flagged', 'yes' );
				$order->add_order_note( sprintf(
					/* translators: 1: received XMR, 2: owed XMR */
					__( 'Partial Monero payment received (%1$s of %2$s XMR). The order stays open and completes automatically when the buyer tops up to the full amount.', 'nodewatch-monero' ),
					XmrPay_Util::pico_to_string( $sum['received_pico'] ), (string) $order->get_meta( '_xmrpay_amount' )
				) );
			}
			$order->save();
		}
		return true;   // a real scan completed (node was reachable)
	}

	/** Settings-page read-only badge showing which network the saved address is on. */
	public function generate_network_status_html( $key, $data ) {
		$net    = $this->detect_network();
		$colors = array( 'mainnet' => '#15803d', 'stagenet' => '#b45309', 'testnet' => '#6d28d9' );
		$color  = isset( $colors[ $net ] ) ? $colors[ $net ] : '#374151';
		$saved  = '' !== trim( (string) $this->get_option( 'xmr_address' ) );
		ob_start(); ?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<span style="display:inline-block;font-family:ui-monospace,Menlo,monospace;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fff;background:<?php echo esc_attr( $color ); ?>;padding:5px 13px;border-radius:4px"><?php echo esc_html( $saved ? $net : 'not set' ); ?></span>
				<p class="description"><?php esc_html_e( 'Detected automatically from your address below — a 4… address is mainnet, 5…/7… is stagenet. To switch networks (e.g. go live from stagenet to mainnet), just enter that network’s address, view key and node. There is no separate toggle — the address decides.', 'nodewatch-monero' ); ?></p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/** Settings-page "Check setup" button (no-server modes): node + network + view-key match. */
	public function generate_test_node_html( $key, $data ) {
		ob_start(); ?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<button type="button" class="button" id="xmrpay-test-node"><?php esc_html_e( 'Check setup', 'nodewatch-monero' ); ?></button>
				<div id="xmrpay-node-result" style="margin-top:10px"></div>
				<p class="description"><?php esc_html_e( 'Checks the node is reachable, the network matches your address, and your view key belongs to the address (uses the values above, even unsaved).', 'nodewatch-monero' ); ?></p>
				<?php // behaviour lives in assets/admin.js ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/** Settings-page "Test connection" button: pings the agent's /healthz live. */
	public function generate_test_connection_html( $key, $data ) {
		$field = esc_attr( $this->get_field_key( $key ) );
		ob_start(); ?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<button type="button" class="button" id="xmrpay-test-agent"><?php esc_html_e( 'Test connection', 'nodewatch-monero' ); ?></button>
				<span id="xmrpay-test-result" style="margin-left:10px;font-weight:600"></span>
				<p class="description"><?php esc_html_e( 'Pings your agent (the URL/token above, even unsaved) and reports network + view-only status.', 'nodewatch-monero' ); ?></p>
				<?php // behaviour lives in assets/admin.js ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/** AJAX: ping {url}/healthz with the (possibly unsaved) settings from the form. */
	public function ajax_test_agent() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'xmrpay_test_agent', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'msg' => __( 'not allowed', 'nodewatch-monero' ) ) );
		}
		$url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( $url === '' ) {
			wp_send_json_error( array( 'msg' => __( 'set the agent URL first', 'nodewatch-monero' ) ) );
		}
		// the agent is designed to run locally — reject any URL whose host is not
		// localhost so this button cannot be used to probe internal network services.
		$parsed = wp_parse_url( $url );
		$host   = isset( $parsed['host'] ) ? strtolower( trim( $parsed['host'], '[]' ) ) : '';
		if ( ! in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			wp_send_json_error( array( 'msg' => __( 'Agent URL must point to localhost (127.0.0.1 or ::1).', 'nodewatch-monero' ) ) );
		}
		$headers = array();
		if ( $token !== '' ) { $headers['Authorization'] = 'Bearer ' . $token; }
		$res = wp_remote_get( trailingslashit( $url ) . 'healthz', array( 'timeout' => 10, 'headers' => $headers ) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'msg' => $res->get_error_message() ) );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code !== 200 || ! is_array( $body ) || empty( $body['ok'] ) ) {
			/* translators: %d: HTTP status code */
			wp_send_json_error( array( 'msg' => sprintf( __( 'agent replied HTTP %d', 'nodewatch-monero' ), (int) $code ) ) );
		}
		$network = sanitize_text_field( isset( $body['network'] ) ? $body['network'] : '' );
		update_option( 'xmrpay_agent_network', $network );   // gates the test_amount override (test networks only)
		// bind that network to the EXACT url we just probed. get_xmr_amount only
		// honours test_amount when this still matches the saved agent_url, so a
		// stale "stagenet" flag can never let test_amount price a mainnet store.
		update_option( 'xmrpay_agent_tested_url', untrailingslashit( trim( (string) $url ) ) );
		$view = ! empty( $body['viewOnly'] ) ? 'view-only' : 'NOT view-only (!)';
		wp_send_json_success( array( 'msg' => sprintf( 'connected · %s · %s', $network !== '' ? $network : '?', $view ) ) );
	}

	/** Order screen (admin): show the on-chain payment detail we recorded. */
	public function admin_order_details( $order ) {
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}
		$addr = (string) $order->get_meta( '_xmrpay_address' );
		if ( $addr === '' ) {
			return;
		}
		$rows = array(
			__( 'Owed', 'nodewatch-monero' )          => $order->get_meta( '_xmrpay_amount' ) . ' XMR',
			__( 'Received', 'nodewatch-monero' )       => ( $order->get_meta( '_xmrpay_received' ) ?: '—' ) . ' XMR',
			__( 'Confirmations', 'nodewatch-monero' )  => $order->get_meta( '_xmrpay_confirmations' ) ?: '—',
		);
		echo '<div class="xmrpay-order-detail" style="clear:both;margin-top:12px"><h4 style="margin:0 0 6px">' . esc_html__( 'Monero payment', 'nodewatch-monero' ) . '</h4><p style="margin:0 0 4px"><strong>' . esc_html__( 'Address', 'nodewatch-monero' ) . ':</strong><br><code style="font-size:11px;word-break:break-all">' . esc_html( $addr ) . '</code></p>';
		foreach ( $rows as $label => $val ) {
			echo '<p style="margin:0"><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $val ) . '</p>';
		}
		$txids = (string) $order->get_meta( '_xmrpay_txids' );
		if ( $txids !== '' ) {
			echo '<p style="margin:4px 0 0"><strong>tx:</strong><br><code style="font-size:11px;word-break:break-all">' . esc_html( $txids ) . '</code></p>';
		}
		if ( $order->get_meta( '_xmrpay_overpaid' ) === 'yes' ) {
			echo '<p style="margin:6px 0 0;padding:6px 8px;background:#fffbeb;border:1px solid #f59e0b;border-radius:4px;color:#92400e"><strong>' . esc_html__( 'Overpaid', 'nodewatch-monero' ) . ':</strong> ' . esc_html( (string) $order->get_meta( '_xmrpay_overpaid_xmr' ) ) . ' XMR — ' . esc_html__( 'refund the difference to the buyer.', 'nodewatch-monero' ) . '</p>';
		}
		$this->admin_refund_box( $order );
		echo '</div>';
	}

	/** The non-custodial refund tracker inside the order's Monero panel. */
	private function admin_refund_box( $order ) {
		$rstatus = (string) $order->get_meta( '_xmrpay_refund_status' );
		if ( '' === $rstatus ) {
			return;
		}
		echo '<div style="margin:8px 0 0;padding:7px 9px;background:#f0f6ff;border:1px solid #5b8def;border-radius:4px;color:#1e3a8a">';
		echo '<strong>' . esc_html__( 'Refund (non-custodial)', 'nodewatch-monero' ) . '</strong>';
		if ( 'requested' === $rstatus ) {
			$opened = (int) $order->get_meta( '_xmrpay_refund_opened' );
			$window = (int) $order->get_meta( '_xmrpay_refund_window' );
			$exp    = XmrPay_Util::claim_expires_at( $opened, $window );
			if ( XmrPay_Util::claim_expired( 'requested', $opened, $window, time() ) ) {
				echo '<p style="margin:4px 0 0;color:#b91c1c"><strong>' . esc_html__( 'Claim-link expired', 'nodewatch-monero' ) . '</strong> — '
					/* translators: %s: expiry date/time */
					. esc_html( sprintf( __( 'expired %s. The buyer cannot use it until you reissue it.', 'nodewatch-monero' ), $this->fmt_dt( $exp ) ) ) . '</p>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:6px 0 0">';
				wp_nonce_field( 'xmrpay_refund_reissue_' . $order->get_id() );
				echo '<input type="hidden" name="action" value="xmrpay_refund_reissue">';
				echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '">';
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Reissue link', 'nodewatch-monero' ) . '</button>';
				echo '</form>';
			} else {
				echo '<p style="margin:4px 0 0">' . esc_html__( 'Waiting for the buyer to supply a Monero address. Send them this claim-link:', 'nodewatch-monero' )
					. '<br><code style="font-size:11px;word-break:break-all">' . esc_html( $this->refund_claim_url( $order ) ) . '</code></p>';
				echo '<p style="margin:2px 0 0;font-size:11px;color:#555">'
					. ( $exp > 0
						/* translators: %s: expiry date/time */
						? esc_html( sprintf( __( 'Link expires %s.', 'nodewatch-monero' ), $this->fmt_dt( $exp ) ) )
						: esc_html__( 'Link does not expire.', 'nodewatch-monero' ) ) . '</p>';
			}
		} elseif ( 'address_provided' === $rstatus ) {
			echo '<p style="margin:4px 0 0"><strong>' . esc_html__( 'Buyer address', 'nodewatch-monero' ) . ':</strong><br><code style="font-size:11px;word-break:break-all">'
				. esc_html( (string) $order->get_meta( '_xmrpay_refund_address' ) ) . '</code></p>';
			echo '<p style="margin:6px 0 4px">' . esc_html__( 'Pay it from your wallet, then record it here:', 'nodewatch-monero' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0">';
			wp_nonce_field( 'xmrpay_refund_sent_' . $order->get_id() );
			echo '<input type="hidden" name="action" value="xmrpay_refund_sent">';
			echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '">';
			echo '<input type="text" name="txid" placeholder="' . esc_attr__( 'payout txid (optional)', 'nodewatch-monero' ) . '" style="width:100%;box-sizing:border-box;font-size:11px;padding:5px 6px;margin:0 0 5px">';
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Mark refund sent', 'nodewatch-monero' ) . '</button>';
			echo '</form>';
		} elseif ( 'sent' === $rstatus ) {
			$rtx = (string) $order->get_meta( '_xmrpay_refund_txid' );
			echo '<p style="margin:4px 0 0">' . esc_html__( 'Refund paid.', 'nodewatch-monero' );
			if ( '' !== $rtx ) {
				echo ' <br><code style="font-size:11px;word-break:break-all">' . esc_html( $rtx ) . '</code>';
			}
			echo '</p>';
		}
		echo '</div>';
	}

	/** Is the gateway usable (configured)? */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( $this->uses_view_key() ) {
			// no-server modes (proof + watch) are ready once address + view key are set —
			// and require both GMP and BCMath (the PHP verifier needs them; without either,
			// order completion would fatal AFTER the buyer paid, so we hide the gateway
			// instead). See maybe_warn_gmp() + XmrPay_Util::crypto_ready().
			return '' !== trim( (string) $this->get_option( 'xmr_address' ) )
				&& '' !== $this->view_key()
				&& XmrPay_Util::crypto_ready();
		}
		return '' !== trim( (string) $this->get_option( 'agent_url' ) );
	}

	/** Warn in wp-admin if a no-server mode is selected but PHP lacks GMP or BCMath. */
	public function maybe_warn_gmp() {
		if ( ! $this->uses_view_key() || XmrPay_Util::crypto_ready() ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>Nodewatch:</strong> '
			. esc_html__( 'The selected Monero mode verifies payments in PHP and needs the GMP and BCMath extensions, one of which is not installed. The Monero gateway is hidden until your host enables ext-gmp and ext-bcmath (or switch to Agent mode).', 'nodewatch-monero' )
			. '</p></div>';
	}

	/** Enqueue the settings-page admin JS (the Test connection / Check setup buttons). */
	public function admin_assets( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) { return; }
		// gateway section only (the section param is read-only navigation, not an action)
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'xmrpay' !== $section ) { return; }
		wp_enqueue_script( 'xmrpay-admin', plugins_url( 'assets/admin.js', XMRPAY_WC_FILE ), array(), XMRPAY_WC_VERSION, true );
		wp_localize_script( 'xmrpay-admin', 'xmrpayAdmin', array(
			'ajaxurl'     => admin_url( 'admin-ajax.php' ),
			'agentNonce'  => wp_create_nonce( 'xmrpay_test_agent' ),
			'nodeNonce'   => wp_create_nonce( 'xmrpay_test_node' ),
			'testing'     => __( 'testing…', 'nodewatch-monero' ),
			'checking'    => __( 'checking…', 'nodewatch-monero' ),
			'reqfail'     => __( 'request failed', 'nodewatch-monero' ),
			'unreachable' => __( 'unreachable', 'nodewatch-monero' ),
		) );
	}

	/**
	 * AJAX: validate a no-server setup live — node reachable + its network, the address,
	 * and (the big one) that the private VIEW key actually belongs to the address. Returns
	 * a checklist so the wizard/settings can show concrete feedback before going live.
	 */
	public function ajax_test_node() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'xmrpay_test_node', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'msg' => __( 'not allowed', 'nodewatch-monero' ) ) );
		}
		if ( ! XmrPay_Util::crypto_ready() ) {
			wp_send_json_error( array( 'msg' => __( 'PHP is missing the GMP or BCMath extension — ask your host to enable ext-gmp and ext-bcmath (or use Agent mode).', 'nodewatch-monero' ) ) );
		}
		$address = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
		$nodes   = isset( $_POST['nodes'] ) ? sanitize_text_field( wp_unslash( $_POST['nodes'] ) ) : '';
		$view    = isset( $_POST['view_key'] ) ? sanitize_text_field( wp_unslash( $_POST['view_key'] ) ) : '';
		if ( defined( 'XMRPAY_VIEW_KEY' ) && '' !== trim( (string) XMRPAY_VIEW_KEY ) ) { $view = trim( (string) XMRPAY_VIEW_KEY ); }

		$list     = array_values( array_filter( array_map( 'trim', explode( ',', $nodes ) ) ) );
		$node     = $list ? $list[0] : 'http://node2.monerodevs.org:38089';
		$c        = '' !== $address ? $address[0] : '4';
		$addr_net = '5' === $c ? 'stagenet' : ( in_array( $c, array( '9', 'A', 'B' ), true ) ? 'testnet' : 'mainnet' );

		require_once __DIR__ . '/class-xmrpay-scanner.php';
		$scanner = new XmrPay_Scanner( $node, $addr_net, 10 );
		$info    = $scanner->node_info();
		$keys    = ( '' !== $address && '' !== $view ) ? $scanner->verify_keys( $address, $view ) : null;

		$checks = array();
		$ok     = true;
		if ( ! empty( $info['ok'] ) ) {
			$net = ( 'unknown' !== $info['nettype'] ) ? $info['nettype'] : $addr_net;
			/* translators: 1: network name, 2: block height */
			$checks[] = array( 'ok' => true, 'msg' => sprintf( __( 'Node reachable — %1$s, block %2$s.', 'nodewatch-monero' ), $net, isset( $info['height'] ) ? $info['height'] : '?' ) );
			if ( 'unknown' !== $info['nettype'] && $info['nettype'] !== $addr_net ) {
				$ok = false;
				/* translators: 1: the address's network, 2: the node's network */
				$checks[] = array( 'ok' => false, 'msg' => sprintf( __( 'Network mismatch: your address is %1$s but the node is %2$s.', 'nodewatch-monero' ), $addr_net, $info['nettype'] ) );
			}
		} else {
			$ok = false;
			$checks[] = array( 'ok' => false, 'msg' => __( 'Node unreachable — check the URL and port.', 'nodewatch-monero' ) );
		}
		if ( '' !== $address ) {
			$valid = $keys && ! empty( $keys['address_valid'] );
			$ok    = $ok && $valid;
			/* translators: %s: network name */
			$checks[] = array( 'ok' => $valid, 'msg' => $valid ? sprintf( __( 'Address is valid (%s).', 'nodewatch-monero' ), $addr_net ) : __( 'Address could not be decoded — check it.', 'nodewatch-monero' ) );
		}
		if ( '' !== $address && '' !== $view ) {
			$match = $keys && ! empty( $keys['key_match'] );
			$ok    = $ok && $match;
			$checks[] = array( 'ok' => $match, 'msg' => $match ? __( 'View key belongs to this address.', 'nodewatch-monero' ) : __( 'View key does NOT match this address — detection would find nothing. Re-check the key.', 'nodewatch-monero' ) );
		} elseif ( '' === $view ) {
			$ok = false;
			$checks[] = array( 'ok' => false, 'msg' => __( 'No view key set yet.', 'nodewatch-monero' ) );
		}

		wp_send_json_success( array( 'ok' => $ok, 'checks' => $checks ) );
	}

	/** Buyer submits a txid; WordPress verifies it (no scanning, no agent). */
	private function is_proof_mode() {
		return 'proof' === $this->get_option( 'mode', 'watch' );
	}
	/** WordPress scans the chain itself to auto-detect (no buyer action, no agent). */
	private function is_watch_mode() {
		return 'watch' === $this->get_option( 'mode', 'watch' );
	}
	/** The two no-server modes both verify in PHP with the merchant's view key. */
	private function uses_view_key() {
		return $this->is_proof_mode() || $this->is_watch_mode();
	}

	/**
	 * The merchant's private view key. Prefer a wp-config.php constant
	 * (define('XMRPAY_VIEW_KEY', '…')) so the view key stays OUT of the database,
	 * the settings UI, and DB backups; fall back to the gateway setting otherwise.
	 * (View-only: a leak exposes incoming-payment privacy, never spend ability.)
	 */
	private function view_key() {
		if ( defined( 'XMRPAY_VIEW_KEY' ) && '' !== trim( (string) XMRPAY_VIEW_KEY ) ) {
			return trim( (string) XMRPAY_VIEW_KEY );
		}
		return trim( (string) $this->get_option( 'view_key' ) );
	}

	/** Lazily build the pure-PHP scanner from the configured node(s). */
	private function scanner() {
		require_once __DIR__ . '/class-xmrpay-scanner.php';
		// pass ALL configured nodes — the scanner uses them for failover + a conservative tip
		// cross-check (min height), so "comma-separated" is real, not just the first one.
		$nodes = array_values( array_filter( array_map( 'trim', explode( ',', (string) $this->get_option( 'nodes' ) ) ) ) );
		if ( ! $nodes ) { $nodes = array( 'http://node2.monerodevs.org:38089' ); }
		return new XmrPay_Scanner( $nodes, $this->detect_network(), 12 );
	}

	/** Infer the Monero network from the configured address prefix (for subaddress minting). */
	private function detect_network() {
		$a = trim( (string) $this->get_option( 'xmr_address' ) );
		$c = '' !== $a ? $a[0] : '4';
		if ( '5' === $c ) { return 'stagenet'; }
		if ( in_array( $c, array( '9', 'A', 'B' ), true ) ) { return 'testnet'; }
		return 'mainnet';
	}

	private function agent() {
		return new XmrPay_Agent( $this->get_option( 'agent_url' ), $this->get_option( 'agent_token' ) );
	}

	/**
	 * XMR amount for an order: the test override, else a direct XMR-priced total,
	 * else convert the fiat total via a price feed. Returns a string (≤12 decimals)
	 * or a WP_Error.
	 */
	public function get_xmr_amount( $order ) {
		$test = trim( (string) $this->get_option( 'test_amount' ) );
		// test_amount is a TEST-ONLY override. NEVER let it silently fix the price on
		// a live store: honor it only when the agent is on a confirmed test network
		// AND that test was run against the url we're using NOW (see test_amount_allowed).
		if ( $test !== '' && XmrPay_Util::test_amount_allowed(
			get_option( 'xmrpay_agent_network', '' ),
			get_option( 'xmrpay_agent_tested_url', '' ),
			$this->get_option( 'agent_url' ),
			(string) $this->get_option( 'xmr_address', '' ) // cross-check: mainnet address voids stagenet flag
		) ) {
			return $this->fmt_xmr( (float) $test );
		}
		$currency = strtoupper( $order->get_currency() );
		$total    = (float) $order->get_total();
		if ( $currency === 'XMR' ) {
			return $this->fmt_xmr( $total ); // store priced natively in Monero — no feed
		}
		// rate = price of 1 XMR in the store currency (with a fixed-rate fallback)
		$rate = $this->resolve_rate( $currency );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}
		if ( $rate <= 0 ) {
			return new WP_Error( 'xmrpay_rate', __( 'Could not get an XMR price. Check your pricing settings.', 'nodewatch-monero' ) );
		}
		return $this->fmt_xmr( $total / $rate );
	}

	/**
	 * The XMR/fiat rate to use, honoring the chosen source with a fixed-rate fallback so a
	 * feed outage never hard-fails checkout: fixed → the set rate; coingecko/custom → the
	 * live rate, or the fixed rate if the feed fails and one is set.
	 */
	private function resolve_rate( $currency ) {
		$source = $this->get_option( 'price_source', 'coingecko' );
		$fixed  = (float) $this->get_option( 'fixed_rate' );

		if ( 'fixed' === $source ) {
			return $fixed > 0 ? $fixed : new WP_Error( 'xmrpay_rate', __( 'Set a fixed XMR rate in the payment settings.', 'nodewatch-monero' ) );
		}
		$live = ( 'custom' === $source ) ? $this->custom_rate( $currency ) : $this->xmr_rate( $currency );
		if ( ! is_wp_error( $live ) && (float) $live > 0 ) {
			$live_val = (float) $live;
			// sanity: with a fixed fallback set, reject a live rate that is wildly off it in
			// EITHER direction — below 2% of it (near-zero rate → the buyer is told to OVERpay
			// massively) OR above 50x it (absurd rate → total/rate yields a near-zero XMR amount
			// → the store collects almost nothing). A tampered/misconfigured/wrong-pair feed is
			// likely; fall through to the fixed fallback instead of pricing the order wrong.
			if ( $fixed > 0 && ( $live_val < $fixed * 0.02 || $live_val > $fixed * 50 ) ) {
				$this->log( 'live rate (' . $live_val . ') is implausible vs fixed fallback (' . $fixed . ') — discarding', 'warning' );
			} else {
				return $live_val;
			}
		}
		if ( $fixed > 0 ) {
			$this->log( 'price feed (' . $source . ') unavailable — using the fixed-rate fallback ' . $fixed, 'warning' );
			return $fixed;
		}
		return is_wp_error( $live ) ? $live : new WP_Error( 'xmrpay_rate', __( 'Could not get an XMR price and no fixed-rate fallback is set.', 'nodewatch-monero' ) );
	}

	/**
	 * A merchant's own price source: GET a URL returning JSON and read the rate (price of 1
	 * XMR in the store currency) from a dot-path. The URL may use {currency}/{CURRENCY}
	 * placeholders. Cached 3 min. The URL is merchant-configured (admin-only).
	 */
	private function custom_rate( $currency ) {
		$vs     = strtolower( $currency );
		$cached = get_transient( 'xmrpay_rate_custom_' . $vs );
		if ( false !== $cached ) {
			return (float) $cached;
		}
		$url = trim( (string) $this->get_option( 'custom_rate_url' ) );
		if ( '' === $url ) {
			return new WP_Error( 'xmrpay_rate', __( 'No custom price-source URL is set.', 'nodewatch-monero' ) );
		}
		$url = str_replace( array( '{currency}', '{CURRENCY}' ), array( $vs, strtoupper( $vs ) ), $url );
		// wp_safe_remote_get applies wp_http_validate_url — a merchant-typed price URL can
		// never be used to reach internal/private network addresses (SSRF defence in depth).
		$res = wp_safe_remote_get( esc_url_raw( $url ), array( 'timeout' => 12 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$rate = $this->dig_path( $body, trim( (string) $this->get_option( 'custom_rate_path' ) ) );
		if ( ! is_numeric( $rate ) || (float) $rate <= 0 ) {
			return new WP_Error( 'xmrpay_rate', __( 'The custom price source did not return a valid rate at that path.', 'nodewatch-monero' ) );
		}
		$rate = (float) $rate;
		set_transient( 'xmrpay_rate_custom_' . $vs, $rate, 180 );
		return $rate;
	}

	/** Walk a dot-path (e.g. "data.rates.XMR") into a decoded-JSON array. */
	private function dig_path( $data, $path ) {
		if ( '' === $path ) {
			return is_numeric( $data ) ? $data : null;
		}
		foreach ( explode( '.', $path ) as $seg ) {
			if ( is_array( $data ) && array_key_exists( $seg, $data ) ) {
				$data = $data[ $seg ];
			} else {
				return null;
			}
		}
		return $data;
	}

	/** XMR/fiat rate from CoinGecko (the merchant's own key if set), cached 3 min. */
	private function xmr_rate( $currency ) {
		$vs  = strtolower( $currency );
		$key = 'xmrpay_rate_' . $vs;
		$cached = get_transient( $key );
		if ( $cached !== false ) {
			return (float) $cached;
		}
		$url     = 'https://api.coingecko.com/api/v3/simple/price?ids=monero&vs_currencies=' . rawurlencode( $vs );
		$api_key = trim( (string) $this->get_option( 'coingecko_api_key' ) );
		if ( $api_key !== '' ) {
			$url .= '&x_cg_demo_api_key=' . rawurlencode( $api_key );
		}
		$res = wp_remote_get( $url, array( 'timeout' => 12 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! isset( $body['monero'][ $vs ] ) ) {
			/* translators: %s: currency code */
			return new WP_Error( 'xmrpay_rate', sprintf( __( 'No XMR price for %s.', 'nodewatch-monero' ), $currency ) );
		}
		$rate = (float) $body['monero'][ $vs ];
		set_transient( $key, $rate, 180 );
		return $rate;
	}

	/** Canonical XMR decimal string, ≤12 decimals, trailing zeros trimmed. */
	private function fmt_xmr( $xmr ) {
		return XmrPay_Util::fmt( $xmr );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		// already has a subaddress (re-paying via the order-pay page, or a double
		// submit): reuse it — never allocate a second subaddress for one order.
		if ( (string) $order->get_meta( '_xmrpay_address' ) !== '' ) {
			return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
		}

		$amount = $this->get_xmr_amount( $order );
		if ( is_wp_error( $amount ) ) {
			wc_add_notice( $amount->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		// free order (100%-off coupon, fully-discounted or zero-priced cart): the
		// total already includes discounts/shipping/tax, so a 0 here means nothing
		// to collect on-chain. complete it now, skip the Monero flow.
		// guard: if the cart total is non-zero but the XMR amount rounded to zero,
		// that means the rate is misconfigured — fail rather than complete for free.
		if ( (float) $amount <= 0 ) {
			if ( (float) $order->get_total() > 0 ) {
				wc_add_notice( __( 'Could not compute a valid XMR amount. Check your rate settings.', 'nodewatch-monero' ), 'error' );
				return array( 'result' => 'failure' );
			}
			$order->payment_complete();
			$order->add_order_note( __( 'Order total is 0 — no Monero payment required.', 'nodewatch-monero' ) );
			if ( WC()->cart ) { WC()->cart->empty_cart(); }
			return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
		}

		// ── no-server modes (proof + watch): no agent. ───────────────────────────
		//   watch → a per-order SUBADDRESS (minor = order id), exact amount; WordPress
		//           scans the chain and completes it (no buyer action).
		//   proof → the merchant's PRIMARY address + a unique (nonced) amount so a
		//           buyer-submitted txid binds to exactly one order.
		if ( $this->uses_view_key() ) {
			$primary = trim( (string) $this->get_option( 'xmr_address' ) );
			if ( $primary === '' || $this->view_key() === '' ) {
				wc_add_notice( __( 'Monero is not fully configured. Please contact us.', 'nodewatch-monero' ), 'error' );
				return array( 'result' => 'failure' );
			}
			if ( $this->is_watch_mode() ) {
				$sub = $this->scanner()->subaddress( 0, (int) $order_id, $this->view_key(), $primary );
				if ( ! $sub || empty( $sub['address'] ) ) {
					$this->log( 'subaddress derivation failed for #' . $order_id, 'error' );
					wc_add_notice( __( 'Could not start the Monero payment. Please contact us.', 'nodewatch-monero' ), 'error' );
					return array( 'result' => 'failure' );
				}
				// the birthday height is the floor we scan from. if the node is unreachable right
				// now, tip_height() is null → (int) would be 0 → the order would scan from GENESIS
				// and never catch its payment (stuck forever). fail the checkout cleanly instead so
				// the buyer just retries — never create an unsettleable watch order.
				$birthday = $this->scanner()->tip_height();
				if ( null === $birthday || (int) $birthday <= 0 ) {
					$this->log( 'watch checkout #' . $order_id . ' aborted — node unreachable, no tip height', 'error' );
					wc_add_notice( __( 'Could not reach the Monero network to set up your payment. Please try again in a moment.', 'nodewatch-monero' ), 'error' );
					return array( 'result' => 'failure' );
				}
				$birthday = (int) $birthday;
				$order->update_meta_data( '_xmrpay_address', $sub['address'] );
				$order->update_meta_data( '_xmrpay_amount', $amount );               // exact — the subaddress disambiguates
				$order->update_meta_data( '_xmrpay_mode', 'watch' );
				$order->update_meta_data( '_xmrpay_minor', (int) $order_id );
				$order->update_meta_data( '_xmrpay_birthday', $birthday );
				$order->update_meta_data( '_xmrpay_scan_height', $birthday );        // checkpoint
				$this->log( 'watch order #' . $order_id . ' → ' . $amount . ' XMR · ' . $sub['address'] . ' · from ' . $birthday );
			} else {
				$amount = XmrPay_Util::nonce_amount( $amount );                      // unique → proof binds to one order
				$order->update_meta_data( '_xmrpay_address', $primary );
				$order->update_meta_data( '_xmrpay_amount', $amount );
				$order->update_meta_data( '_xmrpay_mode', 'proof' );
				$this->log( 'proof order #' . $order_id . ' → ' . $amount . ' XMR · ' . $primary );
			}
			$order->save();
			$order->update_status( 'on-hold', __( 'Awaiting Monero payment.', 'nodewatch-monero' ) );
			if ( WC()->cart ) { WC()->cart->empty_cart(); }
			return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
		}

		$created = $this->agent()->create_order( $amount, (string) $order_id, get_bloginfo( 'name' ) . ' #' . $order_id );
		if ( is_wp_error( $created ) ) {
			$this->log( 'create_order failed for #' . $order_id . ': ' . $created->get_error_message(), 'error' );
			wc_add_notice( __( 'Could not start the Monero payment. Try again or contact us.', 'nodewatch-monero' ), 'error' );
			$order->add_order_note( 'xmr-pay: agent create_order failed — ' . $created->get_error_message() );
			return array( 'result' => 'failure' );
		}
		$this->log( 'order #' . $order_id . ' → ' . $amount . ' XMR · ' . ( isset( $created['address'] ) ? $created['address'] : '?' ) );

		$order->update_meta_data( '_xmrpay_address', isset( $created['address'] ) ? $created['address'] : '' );
		$order->update_meta_data( '_xmrpay_amount', $amount );
		$order->save();

		// awaiting payment — not paid yet
		$order->update_status( 'on-hold', __( 'Awaiting Monero payment.', 'nodewatch-monero' ) );
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/** Payment panel on the order-received page: QR + amount via the widget, plus a live status poller. */
	public function render_payment_panel( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}
		$addr   = (string) $order->get_meta( '_xmrpay_address' );
		$amount = (string) $order->get_meta( '_xmrpay_amount' );
		if ( $addr === '' ) {
			return;
		}
		$paid    = $order->is_paid();
		// the order's STORED mode wins (the global setting may have changed since).
		$omode   = (string) $order->get_meta( '_xmrpay_mode' );
		$proof   = '' !== $omode ? ( 'proof' === $omode ) : $this->is_proof_mode();   // show the txid form?
		$noserver = in_array( $omode, array( 'proof', 'watch' ), true ) || ( '' === $omode && $this->uses_view_key() ); // no agent → no agent receipt back-fill
		$status_url = add_query_arg( array(
			'wc-ajax'   => 'xmrpay_status',
			'order_id'  => $order_id,
			'key'       => $order->get_order_key(),
		), home_url( '/' ) );
		// proof mode: the buyer submits a txid to this endpoint; WordPress verifies it.
		$verify_url = add_query_arg( array(
			'wc-api'   => 'xmrpay_verify',
			'order_id' => $order_id,
			'key'      => $order->get_order_key(),
		), home_url( '/' ) );

		// optional merchant redirect once the payment confirms (live transition only).
		// {order_key} is filled only for a same-origin target — never hand the order's
		// access token to a third-party domain via the redirect URL / Referer.
		$redirect = trim( (string) $this->get_option( 'success_redirect' ) );
		if ( $redirect !== '' ) {
			$key_sub  = XmrPay_Util::same_origin( $redirect, home_url() ) ? rawurlencode( $order->get_order_key() ) : '';
			$redirect = str_replace(
				array( '{order_id}', '{order_key}' ),
				array( rawurlencode( (string) $order_id ), $key_sub ),
				$redirect
			);
		}

		// once paid, make sure the signed receipt is cached on the order. the webhook
		// usually delivers it; this back-fills from the agent if it did not (e.g. the
		// order was completed via the status proxy rather than the webhook).
		if ( $paid && ! $noserver && (string) $order->get_meta( '_xmrpay_receipt' ) === '' ) {
			// back-fill at most once every few minutes per order: if the agent has no
			// receipt yet (or never will, e.g. watch-mode) we must not hit it on every
			// single render/refresh of this page.
			$cooldown = 'xmrpay_rcpt_' . get_current_blog_id() . '_' . (int) $order_id;
			if ( false === get_transient( $cooldown ) ) {
				set_transient( $cooldown, 1, 3 * MINUTE_IN_SECONDS );
				$rc = $this->agent()->get_receipt( (string) $order_id, 8 );
				if ( ! is_wp_error( $rc ) && is_array( $rc ) ) {
					$order->update_meta_data( '_xmrpay_receipt', wp_json_encode( $rc ) );
					$order->save();
					delete_transient( $cooldown );
				}
			}
		}
		$receipt_html = $paid ? $this->receipt_block_html( $order ) : '';

		// overpayment: the buyer sent more than owed. tell them to contact the store
		// for a refund of the difference (Monero is non-custodial — no auto-refund).
		$overpaid     = $paid && $order->get_meta( '_xmrpay_overpaid' ) === 'yes';
		$overpaid_xmr = (string) $order->get_meta( '_xmrpay_overpaid_xmr' );
		// terminal (cancelled/expired/failed) + unpaid: never invite a payment to a
		// dead order. tell the buyer their funds are safe if they already paid.
		$terminal = ! $paid && in_array( $order->get_status(), array( 'cancelled', 'failed' ), true );

		wp_enqueue_script( 'xmrpay-widget' );
		wp_enqueue_script( 'xmrpay-checkout' );
		?>
		<section class="xmrpay-panel" style="margin:24px 0;max-width:420px">
			<h2><?php esc_html_e( 'Pay with Monero', 'nodewatch-monero' ); ?></h2>
			<?php if ( $terminal ) : ?>
				<div style="margin:8px 0;padding:11px 13px;border:1px solid #f59e0b;border-radius:6px;color:#92400e;background:#fffbeb;font-size:13px;line-height:1.55">
					<?php /* translators: %s: order number */ echo esc_html( sprintf( __( 'This order (#%s) has expired. If you already sent a Monero payment, don\'t worry — your funds are safe in our wallet. Please contact us with your order number and we will complete it or refund you.', 'nodewatch-monero' ), $order_id ) ); ?>
				</div>
			<?php else : ?>
			<div id="xmrpay-status"<?php echo $proof ? '' : ' data-poll="' . esc_url( $status_url ) . '"'; ?> data-paid="<?php echo $paid ? '1' : '0'; ?>"<?php echo $redirect !== '' ? ' data-redirect="' . esc_url( $redirect ) . '"' : ''; ?>
				 style="font-weight:600;margin:8px 0;<?php echo $paid ? 'color:#15803d' : 'color:#b45309'; ?>">
				<?php echo $paid ? esc_html__( '✓ Payment received', 'nodewatch-monero' ) : esc_html__( '● Awaiting payment…', 'nodewatch-monero' ); ?>
			</div>
			<?php if ( $overpaid ) : ?>
				<div class="xmrpay-overpaid" style="margin:10px 0;padding:11px 13px;border:1px solid #f59e0b;border-radius:6px;color:#92400e;background:#fffbeb;font-size:13px;line-height:1.5">
					<?php /* translators: %s: amount overpaid in XMR */ echo esc_html( sprintf( __( 'You overpaid %s XMR. Please contact the store to arrange a refund of the difference.', 'nodewatch-monero' ), $overpaid_xmr ) ); ?>
				</div>
			<?php endif; ?>
			<?php if ( ! $paid ) : ?>
				<xmr-pay address="<?php echo esc_attr( $addr ); ?>" amount="<?php echo esc_attr( $amount ); ?>"
						 label="<?php echo esc_attr( get_bloginfo( 'name' ) . ' #' . $order_id ); ?>"
						 theme="<?php echo esc_attr( $this->get_option( 'checkout_theme', 'light' ) ); ?>"
						 lang="<?php echo esc_attr( substr( get_locale(), 0, 2 ) === 'es' ? 'es' : 'en' ); ?>"></xmr-pay>
				<?php if ( $proof ) : ?>
					<div class="xmrpay-proof" data-verify="<?php echo esc_url( $verify_url ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'xmrpay_verify_' . $order_id ) ); ?>" style="margin-top:14px">
						<p style="margin:0 0 6px;font-size:13px;color:#374151"><?php esc_html_e( 'Already paid? Paste your transaction ID and we’ll confirm it.', 'nodewatch-monero' ); ?></p>
						<input type="text" id="xmrpay-txid" inputmode="latin" autocomplete="off" spellcheck="false"
							   placeholder="<?php esc_attr_e( 'Transaction ID (64 hex characters)', 'nodewatch-monero' ); ?>"
							   style="width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #d1d5db;border-radius:6px;font-family:ui-monospace,Menlo,monospace;font-size:12px" />
						<button type="button" id="xmrpay-verify-btn"
								style="margin-top:8px;width:100%;padding:10px;border:0;border-radius:6px;background:#ff6600;color:#fff;font-weight:700;font-size:13px;cursor:pointer">
							<?php esc_html_e( 'I’ve paid — verify', 'nodewatch-monero' ); ?>
						</button>
						<div id="xmrpay-proof-msg" style="margin-top:8px;font-size:13px;min-height:1em"></div>
					</div>
					<?php // behaviour lives in assets/checkout.js (wired from .xmrpay-proof[data-verify]) ?>
				<?php endif; ?>
			<?php endif; ?>
			<?php echo wp_kses_post( $receipt_html ); // built with esc_* in receipt_block_html() ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * The "your cryptographic receipt" block shown once an order is paid: a download
	 * (the signed envelope as a .json) and a link to the bundled offline verifier
	 * (the receipt rides in the URL fragment, so the verifier needs no backend).
	 * Returns '' when no receipt is on the order.
	 */
	private function receipt_block_html( $order ) {
		$json = (string) $order->get_meta( '_xmrpay_receipt' );
		if ( $json === '' ) {
			return '';
		}
		$env = json_decode( $json, true );
		if ( ! is_array( $env ) ) {
			return '';
		}
		$fp       = isset( $env['fingerprint'] ) ? (string) $env['fingerprint'] : '';
		$download = 'data:application/json;charset=utf-8;base64,' . base64_encode( $json );
		$verify   = esc_url( plugins_url( 'assets/verify-receipt.html', XMRPAY_WC_FILE ) ) . '#' . strtr( base64_encode( $json ), '+/', '-_' );
		$btn      = 'display:inline-block;margin:8px 8px 0 0;padding:8px 14px;border-radius:6px;font-weight:600;font-size:13px;text-decoration:none';
		ob_start();
		?>
		<div class="xmrpay-receipt" style="margin-top:16px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:8px">
			<p style="margin:0 0 6px;font-weight:600"><?php esc_html_e( 'Your cryptographic receipt', 'nodewatch-monero' ); ?></p>
			<p style="margin:0;font-size:12px;color:#6b7280">
				<?php esc_html_e( 'Signed by the merchant. Download it and verify it yourself — anyone can, forever, with no third party.', 'nodewatch-monero' ); ?>
				<?php if ( $fp !== '' ) { /* translators: %s: signer fingerprint */ echo ' ' . esc_html( sprintf( __( 'Signer: %s', 'nodewatch-monero' ), $fp ) ); } ?>
			</p>
			<a href="<?php echo esc_attr( $download ); ?>" download="receipt-<?php echo esc_attr( $order->get_id() ); ?>.json"
			   style="<?php echo esc_attr( $btn ); ?>;background:#ff6600;color:#fff">&#8595; <?php esc_html_e( 'Download receipt', 'nodewatch-monero' ); ?></a>
			<a href="<?php echo esc_attr( $verify ); ?>" target="_blank" rel="noopener"
			   style="<?php echo esc_attr( $btn ); ?>;border:1px solid #d1d5db;color:#111">&#8599; <?php esc_html_e( 'Verify receipt', 'nodewatch-monero' ); ?></a>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Server-side proxy: the buyer's browser polls this; we query the private agent. */
	public function ajax_status() {
		// per-IP failed-auth counter (soft limit, 30 failures/60s) — deters order-ID
		// enumeration without blocking legitimate buyers who just mistyped the URL.
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$rl_key = 'xmrpay_rl_s_' . get_current_blog_id() . '_' . substr( md5( $ip ), 0, 16 );
		if ( (int) get_transient( $rl_key ) > 30 ) {
			wp_send_json( array( 'error' => 'too many requests' ), 429 );
		}
		// read-only status poll, authenticated by the secret order key (hash_equals below), not a nonce.
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) || $order->get_payment_method() !== $this->id ) {
			set_transient( $rl_key, (int) get_transient( $rl_key ) + 1, 60 );
			wp_send_json( array( 'error' => 'not found' ), 404 );
		}
		if ( $order->is_paid() ) {
			wp_send_json( array( 'paid' => true, 'status' => 'paid' ) );
		}
		// terminal: a cancelled/failed/refunded order will never flip to paid — tell
		// the client to STOP polling (and skip the agent call). a late payment to it
		// is handled out-of-band by mark_paid's reconcile note.
		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			wp_send_json( array( 'paid' => false, 'status' => $order->get_status(), 'terminal' => true ) );
		}
		// WATCH mode: the buyer's poll IS the active trigger — scan their order now
		// (rate-limited inside scan_order). Then report the fresh state. No agent.
		if ( $order->get_meta( '_xmrpay_mode' ) === 'watch' ) {
			$this->scan_order( $order );
			$order = wc_get_order( $order_id );   // re-read after a possible completion
			if ( $order->is_paid() ) {
				wp_send_json( array( 'paid' => true, 'status' => 'paid' ) );
			}
			$seen = '' !== (string) $order->get_meta( '_xmrpay_watch_txid' );
			wp_send_json( array( 'paid' => false, 'status' => $seen ? 'confirming' : 'pending', 'reachable' => true ) );
		}
		// short timeout: this is a buyer poll, not a checkout step — never tie up a
		// PHP worker for 20s on a slow agent (workers would pile up under polling).
		$r = $this->agent()->get_order( (string) $order_id, 6 );
		if ( is_wp_error( $r ) ) {
			// the agent is unreachable — surface it as a transient "watching" state,
			// not an error (the buyer's payment is on-chain regardless).
			wp_send_json( array( 'paid' => false, 'status' => 'pending', 'reachable' => false ) );
		}
		// authoritative completion is the webhook; reflect a paid here too, defensively
		if ( filter_var( $r['paid'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
			$this->mark_paid( $order, $r );
		}
		$num = function ( $k ) use ( $r ) { return isset( $r[ $k ] ) && is_numeric( $r[ $k ] ) ? 0 + $r[ $k ] : null; };
		$agent_paid = filter_var( $r['paid'] ?? false, FILTER_VALIDATE_BOOLEAN );
		wp_send_json( array(
			'paid'             => $agent_paid,
			'status'           => isset( $r['status'] ) ? $r['status'] : 'pending',
			'shortfallXmr'     => isset( $r['shortfallXmr'] ) ? $r['shortfallXmr'] : null,
			'receivedXmr'      => $num( 'receivedXmr' ),
			'confirmations'    => $num( 'confirmations' ),
			'minConfirmations' => $num( 'minConfirmations' ),
			'tipHeight'        => $num( 'tipHeight' ),
			// (A) the agent is still catching up to the chain tip — the buyer's UI can
			// say "node syncing" instead of a bare "pending" that looks like a miss.
			'syncing'          => ! empty( $r['syncing'] ),
			'reachable'        => true,
		) );
	}

	/**
	 * Proof mode: the buyer POSTs a txid; WordPress verifies it on-chain itself (with
	 * the merchant's view key, against a public node) and completes the order. No agent.
	 * Gated by the order key; rate-limited; the txid is deduped so one payment can settle
	 * exactly one order.
	 */
	public function handle_verify() {
		nocache_headers();
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$txid     = isset( $_POST['txid'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['txid'] ) ) ) : '';
		$nonce    = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		// nonce ties the request to the specific browser session that rendered the page —
		// must check before any DB work so invalid/missing nonces are rejected cheaply.
		if ( $order_id < 1 || ! wp_verify_nonce( $nonce, 'xmrpay_verify_' . $order_id ) ) {
			wp_send_json( array( 'paid' => false, 'message' => __( 'Security check failed. Reload the page and try again.', 'nodewatch-monero' ) ), 403 );
		}
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) || $order->get_payment_method() !== $this->id ) {
			wp_send_json( array( 'paid' => false, 'message' => __( 'Order not found.', 'nodewatch-monero' ) ), 404 );
		}
		if ( $order->is_paid() ) {
			wp_send_json( array( 'paid' => true, 'status' => 'paid' ) );
		}
		// proof verify is only valid for proof-mode orders. agent-mode orders have no
		// view key or subaddress for PHP to verify against — reject to prevent a
		// policy bypass (different min_conf settings between modes).
		if ( 'proof' !== (string) $order->get_meta( '_xmrpay_mode' ) ) {
			wp_send_json( array( 'paid' => false, 'message' => __( 'Proof verification is not available for this order.', 'nodewatch-monero' ) ), 403 );
			return;
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			wp_send_json( array( 'paid' => false, 'terminal' => true, 'message' => __( 'This order can no longer be paid. If you already sent funds, contact us — they are safe.', 'nodewatch-monero' ) ) );
		}
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $txid ) ) {
			wp_send_json( array( 'paid' => false, 'message' => __( 'That doesn’t look like a transaction ID.', 'nodewatch-monero' ) ) );
		}
		// rate-limit: this hits a node, so cap one buyer to one check every few seconds.
		$rl = 'xmrpay_vrl_' . get_current_blog_id() . '_' . (int) $order_id;
		if ( false !== get_transient( $rl ) ) {
			wp_send_json( array( 'paid' => false, 'message' => __( 'Please wait a few seconds and try again.', 'nodewatch-monero' ) ) );
		}
		set_transient( $rl, 1, 5 );

		// dedup: a txid already claimed by another order can never settle this one. The
		// meta query is the durable check; a short txid-scoped lock around it + the node
		// call serialises two requests that submit the SAME txid to DIFFERENT orders at
		// once, so they can't both pass the check before either writes. (The unique
		// amount-nonce per order is the deeper guarantee; this closes the race window.)
		// acquire_lock is an atomic add_option (INSERT that fails if present) — it atomically
		// claims the txid slot. if it returns false, another request already holds this txid.
		$txkey = 'tx_' . $txid;
		if ( ! $this->acquire_lock( $txkey, 30 ) ) {
			wp_send_json( array( 'paid' => false, 'message' => __( 'That transaction is being processed — try again in a moment.', 'nodewatch-monero' ) ) );
		}
		if ( $this->txid_used_elsewhere( $txid, $order_id ) ) {
			$this->release_lock( $txkey );
			wp_send_json( array( 'paid' => false, 'message' => __( 'That transaction has already been used for another order.', 'nodewatch-monero' ) ) );
		}

		$address = (string) $order->get_meta( '_xmrpay_address' );
		$amount  = (string) $order->get_meta( '_xmrpay_amount' );
		$view    = $this->view_key();
		if ( $address === '' || $amount === '' || $view === '' ) {
			wp_send_json( array( 'paid' => false, 'message' => __( 'Payment is not fully configured. Contact the store.', 'nodewatch-monero' ) ), 500 );
		}

		$scanner = $this->scanner();
		$tip     = $scanner->tip_height();
		$res     = $scanner->verify_payment( $txid, $address, $view, array( 'tip' => $tip, 'require_commitment' => true ) );
		$this->log( 'proof verify #' . $order_id . ' tx ' . $txid . ' → ' . wp_json_encode( $res ) );

		if ( empty( $res['found'] ) ) {
			wp_send_json( array( 'paid' => false, 'status' => 'not-found', 'message' => __( 'No payment to your order was found in that transaction. Check the transaction ID.', 'nodewatch-monero' ) ) );
		}
		if ( empty( $res['commitment_ok'] ) ) {
			// a PRUNED node can't return the on-chain commitment, so a real payment fails the check
			// for an operational reason, not a bad payment — tell them apart so the merchant knows
			// to switch nodes instead of chasing a "bad" payment.
			$msg = ( isset( $res['commitment_present'] ) && ! $res['commitment_present'] )
				? __( 'We could not fully verify this payment — the store’s Monero node may be pruned. Please contact the store.', 'nodewatch-monero' )
				: __( 'That transaction did not verify. Contact the store.', 'nodewatch-monero' );
			wp_send_json( array( 'paid' => false, 'status' => 'invalid', 'message' => $msg ) );
		}
		// a MEMPOOL tx the node flags as double_spend_seen is a visible double-spend attempt — never
		// settle it (only bites at min_conf 0, where a 0-conf mempool payment could otherwise pass).
		// once it lands in a block the daemon clears the flag and the payment confirms normally.
		if ( ! empty( $res['in_pool'] ) && ! empty( $res['double_spend_seen'] ) ) {
			wp_send_json( array( 'paid' => false, 'status' => 'pending', 'message' => __( 'Payment seen but not yet confirmable — waiting for a block. This page will update shortly.', 'nodewatch-monero' ) ) );
		}

		$min_conf  = (int) $this->get_option( 'proof_min_conf', '1' );
		$tol_pico  = XmrPay_Util::xmr_to_pico( $this->get_option( 'proof_tolerance_xmr', '0' ) );
		$exp_pico  = XmrPay_Util::xmr_to_pico( $amount );
		// unknown confirmation state (neither in a block nor in the mempool) is never paid,
		// even at min_conf 0 — only a mempool tx counts as 0-conf.
		$cf        = array_key_exists( 'confirmations', $res ) ? $res['confirmations'] : null;
		if ( null === $cf && empty( $res['in_pool'] ) ) {
			wp_send_json( array( 'paid' => false, 'status' => 'pending', 'message' => __( 'Payment seen — waiting for confirmation. This page will update shortly.', 'nodewatch-monero' ) ) );
		}
		$verdict   = XmrPay_Util::classify_payment( $exp_pico, $res['amount_atomic'], $tol_pico, $min_conf, null === $cf ? 0 : (int) $cf, ! empty( $res['in_pool'] ), ! empty( $res['locked'] ) );

		if ( ! $verdict['paid'] ) {
			$msg = __( 'Payment seen — waiting for confirmation. This page will update shortly.', 'nodewatch-monero' );
			/* translators: %s: amount still owed in XMR */
			if ( 'underpaid' === $verdict['status'] ) { $msg = sprintf( __( 'The amount is short by %s XMR. Please send the difference.', 'nodewatch-monero' ), XmrPay_Util::pico_to_string( $verdict['shortfall_pico'] ) ); }
			elseif ( 'locked' === $verdict['status'] ) { $msg = __( 'The payment is time-locked and not spendable yet. Try again later.', 'nodewatch-monero' ); }
			wp_send_json( array( 'paid' => false, 'status' => $verdict['status'], 'message' => $msg ) );
		}

		// PAID. claim the txid (dedup) then complete via the shared mark_paid.
		$order->update_meta_data( '_xmrpay_proof_txid', $txid );
		$order->save();
		$this->mark_paid( $order, array(
			'paid'          => true,
			'received_xmr'  => XmrPay_Util::pico_to_string( $res['amount_atomic'] ),
			'txids'         => array( $txid ),
			'confirmations' => isset( $res['confirmations'] ) ? (int) $res['confirmations'] : 0,
			'overpaid'      => '0' !== $verdict['overpaid_pico'],
			'overpaid_xmr'  => XmrPay_Util::pico_to_string( $verdict['overpaid_pico'] ),
		) );
		wp_send_json( array( 'paid' => true, 'status' => 'paid' ) );
	}

	/** Has this txid already been claimed by a DIFFERENT order? (proof-mode replay guard) */
	private function txid_used_elsewhere( $txid, $order_id ) {
		$ids = wc_get_orders( array(
			'limit'      => 2,
			'return'     => 'ids',
			'meta_key'   => '_xmrpay_proof_txid',
			'meta_value' => $txid,
		) );
		foreach ( (array) $ids as $id ) {
			if ( (int) $id !== (int) $order_id ) {
				return true;
			}
		}
		return false;
	}

	/** Signed order.paid webhook from the agent. */
	public function handle_webhook() {
		$raw    = file_get_contents( 'php://input' );
		$secret = (string) $this->get_option( 'webhook_secret' );
		$sig    = isset( $_SERVER['HTTP_X_XMR_PAY_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_XMR_PAY_SIGNATURE'] ) ) : '';

		if ( ! XmrPay_Util::verify_sig( $raw, $sig, $secret ) ) {
			status_header( 401 );
			echo 'bad signature';
			exit;
		}

		$data     = json_decode( $raw, true );

		// replay defence: the agent stamps a signed event_ts (ms) inside the body.
		// idempotency (is_paid in mark_paid) is the primary guard; this additionally
		// drops a captured webhook replayed long after the fact (see event_fresh).
		if ( is_array( $data ) && ! XmrPay_Util::event_fresh( isset( $data['event_ts'] ) ? $data['event_ts'] : null, time() ) ) {
			status_header( 408 );
			echo 'stale event';
			exit;
		}

		$order_id = is_array( $data ) && isset( $data['order_id'] ) ? absint( $data['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			status_header( 404 );
			echo 'unknown order';
			exit;
		}
		if ( isset( $data['event'] ) && $data['event'] === 'order.paid' ) {
			$this->log( 'webhook order.paid #' . $order_id );
			$this->mark_paid( $order, $data );
		}
		status_header( 200 );
		echo 'ok';
		exit;
	}

	/** Idempotently mark an order paid, recording the full on-chain detail. */
	private function mark_paid( $order, $data ) {
		// a signed webhook (or the status proxy) must only ever complete an order
		// that is actually paying via this gateway — never resolve some other
		// payment method's order by id.
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}
		if ( $order->is_paid() ) {
			return;
		}
		// atomic mutex (add_option-backed) — only one concurrent caller wins. prevents double
		// payment_complete() under webhook + poll + cron overlap.
		$lock_key = 'pay_' . $order->get_id();
		if ( ! $this->acquire_lock( $lock_key, 30 ) ) {
			return;
		}
		// re-fetch to pick up any state another process committed before we got the lock
		$order = wc_get_order( $order->get_id() );
		if ( ! $order || $order->is_paid() ) {
			$this->release_lock( $lock_key );
			return;
		}
		// a late payment for a cancelled/refunded order must NOT silently resurrect
		// it — the funds are in your wallet; flag it for the merchant to reconcile.
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			$order->add_order_note( sprintf(
				/* translators: %s order status */
				__( 'Monero payment arrived for a %s order — NOT auto-completed. The funds are in your wallet; reconcile manually.', 'nodewatch-monero' ),
				$order->get_status()
			) );
			$this->log( 'late payment for ' . $order->get_status() . ' order #' . $order->get_id() . ' — not auto-completed', 'warning' );
			$this->release_lock( $lock_key );
			return;
		}
		// accept BOTH key styles: the signed webhook sends snake_case (received_xmr),
		// the agent's GET /order/:id returns camelCase (receivedXmr). the reconcile
		// cron + the buyer-poll path feed the latter, so fall back to it — otherwise
		// an order completed via those paths would miss its received/overpaid detail.
		$received_raw = isset( $data['received_xmr'] ) ? $data['received_xmr'] : ( $data['receivedXmr'] ?? null );
		$overpaid_raw = isset( $data['overpaid_xmr'] ) ? $data['overpaid_xmr'] : ( $data['overpaidXmr'] ?? null );
		$txid_list = isset( $data['txids'] ) && is_array( $data['txids'] ) ? array_values( array_map( 'sanitize_text_field', $data['txids'] ) ) : array();
		$txids     = implode( ', ', $txid_list );                 // human-readable list → meta + note
		$first_txid = $txid_list ? $txid_list[0] : '';            // a single hash for WC's _transaction_id
		$received = $received_raw !== null ? sanitize_text_field( (string) $received_raw ) : '';
		$confs    = isset( $data['confirmations'] ) ? absint( $data['confirmations'] ) : null;
		$owed     = (string) $order->get_meta( '_xmrpay_amount' );
		$overpaid     = ! empty( $data['overpaid'] );
		$overpaid_xmr = $overpaid_raw !== null ? sanitize_text_field( (string) $overpaid_raw ) : '0';

		// stash the payment detail on the order so the merchant has everything:
		// what was owed, what landed, the confirmations, and the tx hash(es).
		if ( $received !== '' ) { $order->update_meta_data( '_xmrpay_received', $received ); }
		if ( $confs !== null ) { $order->update_meta_data( '_xmrpay_confirmations', $confs ); }
		if ( $txids !== '' ) { $order->update_meta_data( '_xmrpay_txids', $txids ); }
		// buyer sent MORE than owed — record the exact excess so the merchant can
		// refund it (Monero is non-custodial: it must be sent back by hand).
		if ( $overpaid ) {
			$order->update_meta_data( '_xmrpay_overpaid', 'yes' );
			$order->update_meta_data( '_xmrpay_overpaid_xmr', $overpaid_xmr );
		}
		// the signed receipt (if the agent minted one) — stored verbatim so the
		// buyer can download + verify it even if the agent later goes offline.
		if ( isset( $data['receipt'] ) && is_array( $data['receipt'] ) ) {
			$order->update_meta_data( '_xmrpay_receipt', wp_json_encode( $data['receipt'] ) );
		}
		$order->save();

		$note = __( 'Monero payment confirmed.', 'nodewatch-monero' );
		/* translators: 1: amount of XMR received, 2: amount of XMR owed */
		if ( $received !== '' ) { $note .= ' ' . sprintf( __( 'Received: %1$s XMR (owed %2$s).', 'nodewatch-monero' ), $received, $owed ); }
		/* translators: %d: number of confirmations */
		if ( $confs !== null ) { $note .= ' ' . sprintf( __( 'Confirmations: %d.', 'nodewatch-monero' ), $confs ); }
		/* translators: %s: transaction hash(es) */
		if ( $txids !== '' ) { $note .= ' ' . sprintf( __( 'tx: %s', 'nodewatch-monero' ), $txids ); }
		/* translators: %s: amount overpaid in XMR */
		if ( $overpaid ) { $note .= ' ' . sprintf( __( 'OVERPAID by %s XMR — the buyer was asked to contact you; refund the difference manually.', 'nodewatch-monero' ), $overpaid_xmr ); }
		$order->add_order_note( $note );
		$this->log( 'marked paid #' . $order->get_id() . ' · received ' . $received . ' · tx ' . $txids );

		// payment_complete reduces stock, sets processing/completed, fires emails.
		// pass a SINGLE tx hash (WC stores it as _transaction_id + builds the explorer
		// link); the full list lives in _xmrpay_txids.
		// finally ensures the lock is released even if payment_complete() throws (e.g. a
		// hook error or DB failure between acquire and completion).
		try {
			$order->payment_complete( $first_txid );
		} finally {
			$this->release_lock( $lock_key );
		}
	}

	/** The buyer-facing refund claim-link for an order (the order_key is the bearer capability). */
	private function refund_claim_url( $order ) {
		return add_query_arg( array(
			'wc-api'   => 'xmrpay_refund',
			'order_id' => $order->get_id(),
			'key'      => $order->get_order_key(),
		), home_url( '/' ) );
	}

	/** Open (or top up) the non-custodial refund claim on an order: status, amount, expiry, a note with the link. */
	private function open_refund_claim( $order, $amount ) {
		$prev = (float) $order->get_meta( '_xmrpay_refund_amount' );
		$order->update_meta_data( '_xmrpay_refund_amount', (string) ( $prev + (float) $amount ) );
		$order->update_meta_data( '_xmrpay_refund_status', 'requested' );
		// snapshot the expiry clock AT OPEN time, so changing the setting later never retroactively
		// kills a link already in a buyer's inbox. window 0 = never expires.
		$now    = time();
		$window = XmrPay_Util::claim_window_from_days( $this->get_option( 'refund_link_days', '7' ) );
		$order->update_meta_data( '_xmrpay_refund_opened', $now );
		$order->update_meta_data( '_xmrpay_refund_window', $window );
		$order->save();
		$exp  = XmrPay_Util::claim_expires_at( $now, $window );
		$note = sprintf(
			/* translators: 1: refund amount with currency symbol, 2: claim-link URL */
			__( 'Monero refund recorded (%1$s). It is NOT auto-sent — Monero is non-custodial. Send the buyer this claim-link so they can give you a receive address, then pay it from your wallet and mark it sent:%2$s', 'nodewatch-monero' ),
			( null !== $amount && '' !== $amount ) ? wc_price( $amount ) : '',
			"\n" . $this->refund_claim_url( $order )
		);
		$note .= $exp > 0
			/* translators: %s: expiry date/time */
			? ' ' . sprintf( __( 'The link expires on %s; reissue it from this order if it lapses.', 'nodewatch-monero' ), $this->fmt_dt( $exp ) )
			: ' ' . __( 'The link does not expire.', 'nodewatch-monero' );
		$order->add_order_note( $note );
	}

	/** Format a unix timestamp in the site's locale + timezone (for refund-link expiry display). */
	private function fmt_dt( $ts ) {
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $ts );
	}

	/**
	 * Cross-request atomic mutex. WordPress has NO atomic "add transient" — `add_option` is the
	 * atomic primitive (a single INSERT that fails if the row already exists), so it is what
	 * actually serialises concurrent callers on vanilla MySQL. Returns true only if THIS caller
	 * acquired the lock. A lock older than $ttl seconds is reclaimed, so a crash between acquire
	 * and release can't wedge it forever. Release with release_lock(). (autoload 'no' — never cached.)
	 */
	private function acquire_lock( $key, $ttl = 30 ) {
		$opt = 'xmrpay_lock_' . get_current_blog_id() . '_' . $key;
		if ( add_option( $opt, time() + (int) $ttl, '', 'no' ) ) {
			return true;
		}
		$exp = (int) get_option( $opt );
		if ( $exp > 0 && time() > $exp ) {   // stale (holder likely died) — reclaim atomically
			delete_option( $opt );
			return (bool) add_option( $opt, time() + (int) $ttl, '', 'no' );
		}
		return false;
	}

	/** Release a mutex taken with acquire_lock(). */
	private function release_lock( $key ) {
		delete_option( 'xmrpay_lock_' . get_current_blog_id() . '_' . $key );
	}

	/**
	 * WooCommerce refund hook (gateway 'refunds' support). Monero is non-custodial — we hold no
	 * spend key and a Monero tx never reveals the sender, so we NEVER auto-send. Instead we open
	 * a claim-link: the buyer supplies a receive address, the merchant pays it by hand and marks
	 * it sent. Returning true lets WooCommerce record the refund line; no money moves here.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return new WP_Error( 'xmrpay_refund', __( 'This is not a Monero (xmr-pay) order.', 'nodewatch-monero' ) );
		}
		$status = (string) $order->get_meta( '_xmrpay_refund_status' );
		if ( '' === $status ) {
			$this->open_refund_claim( $order, $amount );
		} elseif ( 'requested' === $status ) {
			// claim still open, awaiting an address — just add the new amount to the same link.
			$prev = (float) $order->get_meta( '_xmrpay_refund_amount' );
			$order->update_meta_data( '_xmrpay_refund_amount', (string) ( $prev + (float) $amount ) );
			$order->save();
		} else {
			// a NEW refund AFTER the buyer already gave an address (or one was already sent):
			// REOPEN the claim so the buyer supplies an address for the extra amount, instead of
			// the new money being silently stranded under a sent/address_provided status. The
			// prior address + payout txid remain in the order notes for audit.
			$order->delete_meta_data( '_xmrpay_refund_address' );
			$order->delete_meta_data( '_xmrpay_refund_txid' );
			$order->save();
			$this->open_refund_claim( $order, $amount );   // status -> requested, amount +=, fresh link + note
		}
		return true;
	}

	/** Best-effort checksum validation; degrades to the regex prefilter when GMP/BCMath are absent. */
	private function address_checksum_ok( $addr ) {
		if ( ! XmrPay_Util::crypto_ready() ) {
			return true;   // no crypto extensions: is_address_like is the only gate (merchant eyeballs anyway)
		}
		try {
			return $this->scanner()->address_valid( $addr );
		} catch ( \Throwable $e ) {
			return true;   // never block a refund on an internal hiccup; the merchant verifies before sending
		}
	}

	/**
	 * Buyer-facing refund claim-link (wc-api=xmrpay_refund). GET renders an address-capture form;
	 * POST stores the buyer's Monero receive address. The order_key is the bearer capability (the
	 * same scheme as handle_verify); a nonce defends the POST against CSRF. No login required — the
	 * buyer arrives from an email/message. Emits a standalone page and exits.
	 */
	public function handle_refund() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) || $order->get_payment_method() !== $this->id ) {
			$this->refund_page( __( 'Refund link not found', 'nodewatch-monero' ),
				'<p>' . esc_html__( 'This refund link is invalid or has expired. Please contact the store.', 'nodewatch-monero' ) . '</p>', 404 );
		}
		$status = (string) $order->get_meta( '_xmrpay_refund_status' );
		if ( '' === $status ) {
			$this->refund_page( __( 'No refund pending', 'nodewatch-monero' ),
				'<p>' . esc_html__( 'There is no refund waiting on this order. Contact the store if you believe this is a mistake.', 'nodewatch-monero' ) . '</p>', 404 );
		}
		$num = $order->get_order_number();

		// EXPIRY: a still-`requested` claim past its snapshotted window is dead. Once an address is
		// captured the link is moot, so this only gates the requested state — and covers BOTH the
		// GET form and the POST capture below. The merchant can reissue from the order screen.
		$opened = (int) $order->get_meta( '_xmrpay_refund_opened' );
		$window = (int) $order->get_meta( '_xmrpay_refund_window' );   // missing/0 = never (legacy claims too)
		if ( XmrPay_Util::claim_expired( $status, $opened, $window, time() ) ) {
			$this->refund_page( __( 'Refund link expired', 'nodewatch-monero' ),
				'<p>' . esc_html__( 'This refund link has expired. Please contact the store and they will send you a fresh one — your refund is safe.', 'nodewatch-monero' ) . '</p>', 410 );
		}

		// POST: the buyer submits their Monero receive address.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' === $method ) {
			$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'xmrpay_refund_' . $order_id ) ) {
				$this->refund_page( __( 'Security check failed', 'nodewatch-monero' ),
					'<p>' . esc_html__( 'Please reload the page and try again.', 'nodewatch-monero' ) . '</p>', 403 );
			}
			$rl = 'xmrpay_rfrl_' . get_current_blog_id() . '_' . (int) $order_id;
			if ( false !== get_transient( $rl ) ) {
				$this->refund_page( __( 'One moment', 'nodewatch-monero' ),
					'<p>' . esc_html__( 'Please wait a few seconds and try again.', 'nodewatch-monero' ) . '</p>', 429 );
			}
			set_transient( $rl, 1, 5 );
			$addr = isset( $_POST['address'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['address'] ) ) ) : '';
			if ( ! XmrPay_Util::is_address_like( $addr ) || ! $this->address_checksum_ok( $addr ) ) {
				$this->refund_form_page( $order, $num, __( 'That is not a valid Monero address for this store (a typo, or an address from the wrong network). Please check it and try again.', 'nodewatch-monero' ) );
			}
			// capture the FIRST address only (a later visit must contact the store — stops anyone
			// who later obtains the link from redirecting a not-yet-sent refund). acquire_lock +
			// a FRESH status re-read make "first wins" atomic, so two concurrent POSTs of the same
			// link can't both write (the 5s rate-limit above only narrows the window).
			$caplock = 'rfcap_' . $order_id;
			if ( $this->acquire_lock( $caplock, 30 ) ) {
				$fresh = wc_get_order( $order_id );
				if ( $fresh && 'requested' === (string) $fresh->get_meta( '_xmrpay_refund_status' ) ) {
					$fresh->update_meta_data( '_xmrpay_refund_address', $addr );
					$fresh->update_meta_data( '_xmrpay_refund_status', 'address_provided' );
					$fresh->save();
					$fresh->add_order_note( __( 'Buyer supplied a Monero refund address via the claim-link. Pay it from your wallet, then mark the refund sent.', 'nodewatch-monero' ) );
				}
				$this->release_lock( $caplock );
			}
			$this->refund_page( __( 'Address received', 'nodewatch-monero' ),
				'<p class="ok">' . esc_html__( 'Thank you. The store has your Monero address and will send your refund shortly.', 'nodewatch-monero' ) . '</p>' );
		}

		// GET on a claim that is past the form stage.
		if ( 'sent' === $status ) {
			$this->refund_page( __( 'Refund sent', 'nodewatch-monero' ),
				'<p class="ok">' . esc_html__( 'This refund has already been paid. If you have not received it, contact the store.', 'nodewatch-monero' ) . '</p>' );
		}
		if ( 'address_provided' === $status ) {
			$this->refund_page( __( 'Address on file', 'nodewatch-monero' ),
				'<p>' . esc_html__( 'We already have your refund address and will send your refund shortly. To change the address, contact the store.', 'nodewatch-monero' ) . '</p>' );
		}
		// 'requested' → render the address form.
		$this->refund_form_page( $order, $num, '' );
	}

	/** Render the address-capture form (and any validation error). Emits a page and exits. */
	private function refund_form_page( $order, $num, $error ) {
		$nonce  = wp_nonce_field( 'xmrpay_refund_' . $order->get_id(), '_wpnonce', true, false );
		$action = esc_url( $this->refund_claim_url( $order ) );
		$body   = '<p>' . sprintf(
			/* translators: %s: order number */
			esc_html__( 'Order %s has a refund waiting. Monero does not reveal who paid, so we need an address to send it to. Paste a receive address from your Monero wallet below.', 'nodewatch-monero' ),
			esc_html( $num )
		) . '</p>';
		$amt = (float) $order->get_meta( '_xmrpay_refund_amount' );
		if ( $amt > 0 ) {
			$body .= '<p>' . esc_html__( 'Refund amount', 'nodewatch-monero' ) . ': <strong>'
				. wp_kses_post( wc_price( $amt, array( 'currency' => $order->get_currency() ) ) ) . '</strong></p>';
		}
		$exp = XmrPay_Util::claim_expires_at( (int) $order->get_meta( '_xmrpay_refund_opened' ), (int) $order->get_meta( '_xmrpay_refund_window' ) );
		if ( $exp > 0 ) {
			$body .= '<p class="meta">' . sprintf(
				/* translators: %s: expiry date/time */
				esc_html__( 'This link is valid until %s.', 'nodewatch-monero' ),
				esc_html( $this->fmt_dt( $exp ) )
			) . '</p>';
		}
		$invalid  = '' !== $error;
		$describe = $invalid ? 'xmraddr-err xmraddr-note' : 'xmraddr-note';
		$body  .= '<form method="post" action="' . $action . '">' . $nonce;
		$body  .= '<label for="xmraddr">' . esc_html__( 'Your Monero receive address', 'nodewatch-monero' ) . '</label>';
		$body  .= '<input type="text" id="xmraddr" name="address" required autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" aria-describedby="' . esc_attr( $describe ) . '"' . ( $invalid ? ' aria-invalid="true"' : '' ) . ' placeholder="4... / 8...">';
		if ( $invalid ) {
			$body .= '<div class="err" id="xmraddr-err" role="alert">' . esc_html( $error ) . '</div>';
		}
		$body  .= '<button type="submit">' . esc_html__( 'Submit refund address', 'nodewatch-monero' ) . '</button></form>';
		$body  .= '<p class="note" id="xmraddr-note">' . esc_html__( 'Double-check the address — Monero payments cannot be reversed.', 'nodewatch-monero' ) . '</p>';
		$this->refund_page( __( 'Claim your refund', 'nodewatch-monero' ), $body );
	}

	/** Emit a minimal, self-contained (no CDN) claim-link page and exit. */
	private function refund_page( $title, $body_html, $code = 200 ) {
		status_header( $code );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		$css = 'body{margin:0;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;background:#0b0b0d;color:#fafafa;font-family:ui-monospace,Menlo,Consolas,monospace;padding:48px 24px 64px}'
			. '.card{width:100%;max-width:440px}h1{font-size:17px;margin:0 0 14px;letter-spacing:-.01em}p{font-size:13px;line-height:1.6;color:#c7c7cf;margin:0 0 8px}'
			. 'code{color:#ff6600;font-size:11px;word-break:break-all}label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#9a9aa3;margin:18px 0 6px}'
			. 'input[type=text]{width:100%;box-sizing:border-box;padding:12px;background:#141417;border:1px solid #2a2a30;border-radius:6px;color:#fafafa;font-family:inherit;font-size:13px}'
			. 'input[type=text]:focus{outline:none;border-color:#ff6600}'
			. 'button{margin-top:14px;width:100%;padding:13px;background:#ff6600;border:0;border-radius:6px;color:#0b0b0d;font-weight:700;font-family:inherit;font-size:13px;cursor:pointer}'
			. 'button:hover{background:#ff7d1a}input:focus-visible,button:focus-visible{outline:2px solid #ff8a33;outline-offset:2px}'
			. '.err{color:#f87171;font-size:12px;margin-top:8px}.ok{color:#34d399}.meta{font-size:11px;color:#8b8b93}'
			. '.note{font-size:12px;line-height:1.55;color:#c7c7cf;margin-top:16px;padding-left:11px;border-left:2px solid #ff6600}'
			. '.brand{margin-top:24px;color:#8b8b93;font-size:11px}';
		// $body_html is assembled above from esc_*/wp_nonce_field — safe by construction.
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer">'
			. '<title>' . esc_html( $title ) . '</title><style>' . $css . '</style></head><body><div class="card"><h1>' . esc_html( $title ) . '</h1>'
			. $body_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from esc_*/wp_nonce_field
			. '<p class="brand">' . esc_html( get_bloginfo( 'name' ) ) . '</p></div></body></html>';
		exit;
	}

	/**
	 * Admin: the merchant records the manual payout txid and marks the refund sent (audit trail).
	 * admin-post.php handler, capability- and nonce-checked.
	 */
	public function handle_refund_sent() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'nodewatch-monero' ), '', array( 'response' => 403 ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		check_admin_referer( 'xmrpay_refund_sent_' . $order_id );
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			wp_die( esc_html__( 'Order not found.', 'nodewatch-monero' ), '', array( 'response' => 404 ) );
		}
		$txid = isset( $_POST['txid'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['txid'] ) ) ) : '';
		if ( '' !== $txid && ! preg_match( '/^[0-9a-f]{64}$/', $txid ) ) {
			$txid = '';   // ignore a malformed txid rather than store junk
		}
		if ( '' !== $txid ) {
			$order->update_meta_data( '_xmrpay_refund_txid', $txid );
		}
		$order->update_meta_data( '_xmrpay_refund_status', 'sent' );
		$order->save();
		$user = wp_get_current_user();
		$order->add_order_note( sprintf(
			/* translators: 1: admin username, 2: payout transaction id */
			__( 'Monero refund marked SENT by %1$s. Payout txid: %2$s', 'nodewatch-monero' ),
			$user ? $user->user_login : 'admin',
			'' !== $txid ? $txid : 'n/a'
		) );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Admin: reissue a refund claim-link — resets the expiry clock to NOW using the current
	 * setting, keeping the claim `requested` so the same link works again. capability + nonce.
	 */
	public function handle_refund_reissue() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'nodewatch-monero' ), '', array( 'response' => 403 ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		check_admin_referer( 'xmrpay_refund_reissue_' . $order_id );
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			wp_die( esc_html__( 'Order not found.', 'nodewatch-monero' ), '', array( 'response' => 404 ) );
		}
		// only meaningful while still awaiting an address; never touch a captured/sent claim.
		if ( 'requested' === (string) $order->get_meta( '_xmrpay_refund_status' ) ) {
			$now    = time();
			$window = XmrPay_Util::claim_window_from_days( $this->get_option( 'refund_link_days', '7' ) );
			$order->update_meta_data( '_xmrpay_refund_opened', $now );
			$order->update_meta_data( '_xmrpay_refund_window', $window );
			$order->save();
			$exp  = XmrPay_Util::claim_expires_at( $now, $window );
			$user = wp_get_current_user();
			$order->add_order_note( sprintf(
				/* translators: 1: admin username, 2: new expiry date or 'never' */
				__( 'Refund claim-link reissued by %1$s (new expiry: %2$s).', 'nodewatch-monero' ),
				$user ? $user->user_login : 'admin',
				$exp > 0 ? $this->fmt_dt( $exp ) : __( 'never', 'nodewatch-monero' )
			) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * WooCommerce refund created OUTSIDE our gateway path (a "manual" refund that skipped
	 * process_refund). Open the same claim-link so the buyer still gets one. If process_refund
	 * already opened it, do nothing (avoids a duplicate note / double-counted amount).
	 */
	public function on_refunded( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( '_xmrpay_refund_status' ) ) {
			return;   // process_refund already opened the claim
		}
		$refund = wc_get_order( $refund_id );
		$this->open_refund_claim( $order, $refund ? $refund->get_amount() : '' );
	}

	/**
	 * Monero payment details in the customer's order email (e.g. the "on hold"
	 * email) so a buyer who closed the tab can still pay: the address, the amount,
	 * and a link to the live payment page (QR + status). Skipped once paid.
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $sent_to_admin || ! $order || $order->get_payment_method() !== $this->id || $order->is_paid() ) {
			return;
		}
		$addr   = (string) $order->get_meta( '_xmrpay_address' );
		$amount = (string) $order->get_meta( '_xmrpay_amount' );
		if ( $addr === '' ) {
			return;
		}
		// the order-received page renders the QR + live status and works while the
		// order is on-hold; the order-pay page does NOT (on-hold isn't a payable
		// status, so it would redirect). link to order-received.
		$pay_url = $order->get_checkout_order_received_url();

		if ( $plain_text ) {
			/* translators: 1: amount of XMR, 2: Monero address */
			echo "\n" . esc_html( sprintf( __( 'Pay %1$s XMR to: %2$s', 'nodewatch-monero' ), $amount, $addr ) ) . "\n";
			echo esc_html__( 'Payment page (QR + live status):', 'nodewatch-monero' ) . ' ' . esc_url( $pay_url ) . "\n\n";
			return;
		}
		echo '<div style="margin:0 0 24px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:8px">';
		echo '<p style="margin:0 0 8px;font-weight:600">' . esc_html__( 'Complete your Monero payment', 'nodewatch-monero' ) . '</p>';
		/* translators: %s: amount of XMR (bold) */
		echo '<p style="margin:0 0 6px">' . sprintf( esc_html__( 'Send %s XMR to:', 'nodewatch-monero' ), '<strong>' . esc_html( $amount ) . '</strong>' ) . '</p>';
		echo '<p style="margin:0 0 10px;word-break:break-all"><code style="font-size:12px">' . esc_html( $addr ) . '</code></p>';
		echo '<p style="margin:0"><a href="' . esc_url( $pay_url ) . '" style="color:#ff6600;font-weight:600">' . esc_html__( 'Open the payment page (QR + live status) →', 'nodewatch-monero' ) . '</a></p>';
		echo '</div>';
	}
}
