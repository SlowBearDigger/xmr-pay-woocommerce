<?php
/**
 * Monero (xmr-pay) WooCommerce payment gateway.
 *
 * Non-custodial: payments go straight to the merchant's own Monero address. This
 * gateway is a thin client of the merchant's own xmr-pay scanner-agent — it never
 * holds funds, a view key, or any Monero crypto. Flow:
 *
 *   checkout  → process_payment() asks the agent for a per-order subaddress
 *   pay page  → buyer pays the subaddress (QR via the bundled <xmr-pay> widget)
 *   detect    → the agent scans, sums, and POSTs a signed order.paid webhook
 *   complete  → this gateway verifies the HMAC and marks the order paid
 *
 * The buyer's browser polls THIS plugin (server-side proxy to the agent), so the
 * agent stays private on the merchant's box.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Gateway_XmrPay extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'xmrpay';
		$this->method_title       = __( 'Monero (xmr-pay)', 'xmr-pay-for-woocommerce' );
		$this->method_description = __( 'Accept Monero, non-custodial. Funds go straight to your address; detection runs on your own xmr-pay agent.', 'xmr-pay-for-woocommerce' );
		$this->has_fields         = false;
		$this->icon               = apply_filters( 'woocommerce_xmrpay_icon', plugins_url( 'assets/monero-symbol.png', XMRPAY_WC_FILE ) );
		// non-custodial: we hold no spend key, so we CANNOT push an automatic
		// refund. 'refunds' is deliberately absent — refunds are manual (see
		// on_refunded). products only.
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Monero (XMR)', 'xmr-pay-for-woocommerce' ) );
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
		// admin: a "test connection" button on the settings page + a payment-detail
		// block on the order screen (HPOS-safe hook).
		add_action( 'wp_ajax_xmrpay_test_agent', array( $this, 'ajax_test_agent' ) );
		add_action( 'wp_ajax_xmrpay_test_node', array( $this, 'ajax_test_node' ) );
		add_action( 'admin_notices', array( $this, 'maybe_warn_gmp' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'admin_order_details' ) );
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
				'title'   => __( 'Enable', 'xmr-pay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Monero payments', 'xmr-pay-for-woocommerce' ),
				'default' => 'no',
			),
			'title' => array(
				'title'   => __( 'Title', 'xmr-pay-for-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'Monero (XMR)', 'xmr-pay-for-woocommerce' ),
				'desc_tip'=> true,
				'description' => __( 'What the buyer sees at checkout.', 'xmr-pay-for-woocommerce' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'xmr-pay-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Pay privately with Monero. Scan the QR — your wallet fills in the exact amount.', 'xmr-pay-for-woocommerce' ),
			),
			'checkout_theme' => array(
				'title'   => __( 'Payment box theme', 'xmr-pay-for-woocommerce' ),
				'type'    => 'select',
				'default' => 'light',
				'options' => array(
					'light' => __( 'Light (for light store themes)', 'xmr-pay-for-woocommerce' ),
					'dark'  => __( 'Dark (for dark store themes)', 'xmr-pay-for-woocommerce' ),
				),
				'description' => __( 'Match the QR/payment box to your store theme so it does not look bolted-on.', 'xmr-pay-for-woocommerce' ),
			),
			'success_redirect' => array(
				'title'       => __( 'Redirect after payment (URL)', 'xmr-pay-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'https://example.com/thank-you',
				'description' => __( 'Optional. When the payment confirms, send the buyer here (a custom thank-you, a digital-download page, etc.) instead of staying on the order-received page. {order_id} and {order_key} are substituted — {order_key} only for a URL on this same site, so the order token is never leaked to a third-party domain. Leave empty for the default WooCommerce behaviour.', 'xmr-pay-for-woocommerce' ),
			),
			'mode' => array(
				'title'   => __( 'How payments are verified', 'xmr-pay-for-woocommerce' ),
				'type'    => 'select',
				'default' => 'watch',
				'options' => array(
					'watch' => __( 'Auto-detect in WordPress — no server (recommended)', 'xmr-pay-for-woocommerce' ),
					'proof' => __( 'Buyer taps “I’ve paid” — no server', 'xmr-pay-for-woocommerce' ),
					'agent' => __( 'Auto-detect via your xmr-pay agent (advanced)', 'xmr-pay-for-woocommerce' ),
				),
				'description' => __( 'All three are non-custodial (funds go straight to your address). <strong>Auto-detect in WordPress</strong> needs no backend: WordPress scans the chain itself (with your view key, against a public node) and completes the order — no buyer action, nothing to run 24/7. <strong>Buyer taps “I’ve paid”</strong> is the lightest: the buyer submits the transaction ID and WordPress verifies it (no scanning). <strong>Agent</strong> uses the separate xmr-pay daemon you run. The “No-server settings” below (address, view key, nodes) apply to the two no-server options; the “Agent settings” apply only to Agent mode.', 'xmr-pay-for-woocommerce' ),
			),
			'proof_section' => array(
				'title' => __( 'No-server settings (Auto-detect & “I’ve paid”)', 'xmr-pay-for-woocommerce' ),
				'type'  => 'title',
				'description' => __( 'Used by both no-server modes — “Auto-detect in WordPress” and “Buyer taps I’ve paid”. Your private view key stays on your own server (we never hold a spend key — funds go straight to your address). WordPress fetches transactions from a public node and verifies them itself.', 'xmr-pay-for-woocommerce' ),
			),
			'xmr_address' => array(
				'title'       => __( 'Your Monero address', 'xmr-pay-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => '4... (mainnet) or 5.../7... (stagenet)',
				'description' => __( 'Your primary Monero address — buyers pay here. Each order gets a unique amount so payments can’t be confused.', 'xmr-pay-for-woocommerce' ),
			),
			'view_key' => array(
				'title'       => __( 'Private view key', 'xmr-pay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Your wallet’s PRIVATE VIEW key (not the spend key). View-only: it can see incoming payments but cannot spend. <strong>More private:</strong> instead of storing it here, put <code>define(\'XMRPAY_VIEW_KEY\', \'…\');</code> in wp-config.php — then it stays out of the database, this screen, and backups. A value here is used only if the constant is not set.', 'xmr-pay-for-woocommerce' ),
			),
			'nodes' => array(
				'title'       => __( 'Monero node(s)', 'xmr-pay-for-woocommerce' ),
				'type'        => 'text',
				'default'     => 'http://node2.monerodevs.org:38089',
				'description' => __( 'Public node URL(s), comma-separated. Your own node first if you run one. Used only to fetch transactions and the chain height (it never sees your view key).', 'xmr-pay-for-woocommerce' ),
			),
			'proof_min_conf' => array(
				'title'   => __( 'Confirmations required', 'xmr-pay-for-woocommerce' ),
				'type'    => 'number',
				'default' => '1',
				'description' => __( '0 = accept as soon as it’s seen (fast, mempool); 1 = first block (~2 min); 10 = fully unlocked (safest for high value).', 'xmr-pay-for-woocommerce' ),
				'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
			),
			'proof_tolerance_xmr' => array(
				'title'       => __( 'Underpayment tolerance (XMR)', 'xmr-pay-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '0',
				'description' => __( 'Accept a payment that falls short by up to this much (dust/rounding). 0 = exact. It can never reach the price, so a near-zero payment never settles an order.', 'xmr-pay-for-woocommerce' ),
			),
			'test_node' => array(
				'title' => __( 'Check setup', 'xmr-pay-for-woocommerce' ),
				'type'  => 'test_node',
			),
			'agent_section' => array(
				'title' => __( 'Agent settings (advanced)', 'xmr-pay-for-woocommerce' ),
				'type'  => 'title',
				'description' => __( 'Used only when the “Agent” mode is selected. The separate xmr-pay daemon you run (see docs/AGENT.md) holds your view key and does the scanning — this plugin only talks HTTP to it. Detection policy (confirmations, tolerance, expiry) is set ON the agent (XMR_MIN_CONFIRMATIONS, XMR_TOLERANCE_XMR, XMR_EXPIRY_HOURS). Leave blank if you use a no-server mode.', 'xmr-pay-for-woocommerce' ),
			),
			'agent_url' => array(
				'title'       => __( 'Agent URL', 'xmr-pay-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'http://127.0.0.1:8788',
				'description' => __( 'Base URL of your scanner-agent. Keep it private (localhost or a private network).', 'xmr-pay-for-woocommerce' ),
			),
			'agent_token' => array(
				'title'       => __( 'Agent token', 'xmr-pay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'The AGENT_TOKEN you set on the agent (optional, but recommended).', 'xmr-pay-for-woocommerce' ),
			),
			'test_connection' => array(
				'title' => __( 'Connection', 'xmr-pay-for-woocommerce' ),
				'type'  => 'test_connection',
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook secret', 'xmr-pay-for-woocommerce' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s webhook URL */
					__( 'Set the agent\'s FULFILL_WEBHOOK_SECRET to this, and FULFILL_WEBHOOK_URL to: %s', 'xmr-pay-for-woocommerce' ),
					'<code>' . esc_html( WC()->api_request_url( 'xmrpay_webhook' ) ) . '</code>'
				),
			),
			'pricing_section' => array(
				'title' => __( 'Pricing', 'xmr-pay-for-woocommerce' ),
				'type'  => 'title',
				'description' => __( 'How the cart total becomes an XMR amount. Tip: set your store currency to <strong>XMR</strong> to price natively in Monero — then no price feed is used.', 'xmr-pay-for-woocommerce' ),
			),
			'price_source' => array(
				'title'   => __( 'Price source', 'xmr-pay-for-woocommerce' ),
				'type'    => 'select',
				'default' => 'coingecko',
				'options' => array(
					'coingecko' => __( 'CoinGecko (live fiat → XMR)', 'xmr-pay-for-woocommerce' ),
					'custom'    => __( 'Your own price source (a URL)', 'xmr-pay-for-woocommerce' ),
					'fixed'     => __( 'Fixed rate (set it yourself)', 'xmr-pay-for-woocommerce' ),
				),
				'description' => __( 'How a fiat total converts to XMR (ignored when the store currency is already XMR). For CoinGecko or a custom source, set a <strong>Fixed rate</strong> too and it’s used automatically as a fallback if the live feed is ever unavailable — so checkout never hard-fails.', 'xmr-pay-for-woocommerce' ),
			),
			'coingecko_api_key' => array(
				'title'       => __( 'CoinGecko API key', 'xmr-pay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Optional — your own CoinGecko Demo/Pro key, so the price feed is yours and not rate-limited. Leave empty to use the free public endpoint.', 'xmr-pay-for-woocommerce' ),
			),
			'custom_rate_url' => array(
				'title'       => __( 'Custom price URL', 'xmr-pay-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'https://your-feed.example/xmr?vs={currency}',
				'description' => __( 'Used with “Your own price source”. A URL returning JSON. <code>{currency}</code>/<code>{CURRENCY}</code> are substituted with the store currency code. It should return the price of 1 XMR in your store currency.', 'xmr-pay-for-woocommerce' ),
			),
			'custom_rate_path' => array(
				'title'       => __( 'Rate JSON path', 'xmr-pay-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'e.g. data.rate  (or leave blank if the body is just a number)',
				'description' => __( 'Dot-path to the number in the JSON response, e.g. <code>monero.usd</code> or <code>data.rates.XMR</code>. Leave blank if the response body is the bare number.', 'xmr-pay-for-woocommerce' ),
			),
			'fixed_rate' => array(
				'title'       => __( 'Fixed rate / fallback', 'xmr-pay-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'e.g. 150',
				'description' => __( 'The price of 1 XMR in your store currency. Used as the rate with “Fixed rate”, and as the automatic fallback for the live sources if their feed is down.', 'xmr-pay-for-woocommerce' ),
			),
			'min_confirmations' => array(
				'title'   => __( 'Confirmations', 'xmr-pay-for-woocommerce' ),
				'type'    => 'number',
				'default' => '1',
				'description' => __( 'Shown to the buyer; the agent enforces its own XMR_MIN_CONFIRMATIONS. Raise both for high-value carts.', 'xmr-pay-for-woocommerce' ),
			),
			'test_amount' => array(
				'title'       => __( 'Test amount (XMR)', 'xmr-pay-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => '',
				'description' => __( 'TEST ONLY — charges this exact XMR amount, ignoring the cart total. It is honoured ONLY when your agent is on stagenet/testnet (run "Test connection" first); on mainnet it is ignored, so it can never fix the price on a live store. Leave empty in production.', 'xmr-pay-for-woocommerce' ),
			),
			'expiry_hours' => array(
				'title'       => __( 'Auto-cancel after (hours)', 'xmr-pay-for-woocommerce' ),
				'type'        => 'number',
				'default'     => '0',
				'description' => __( 'Cancel an unpaid order this many hours after it was placed (frees reserved stock). 0 = never. A late payment to a cancelled order is flagged for you, not auto-completed.', 'xmr-pay-for-woocommerce' ),
				'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
			),
			'debug_log' => array(
				'title'       => __( 'Debug log', 'xmr-pay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log agent calls and webhooks to WooCommerce → Status → Logs (source: xmrpay)', 'xmr-pay-for-woocommerce' ),
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
			// ask the agent before cancelling: a payment may have arrived (full or
			// PARTIAL) that the webhook/poll missed. NEVER auto-cancel an order that
			// received funds — that would strand the buyer's money on a dead order.
			$r = $this->agent()->get_order( (string) $oid, 6 );
			if ( is_wp_error( $r ) ) {
				continue;   // can't confirm there's no payment → be safe, don't cancel
			}
			if ( ! empty( $r['paid'] ) ) {
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
						__( 'Partial Monero payment received (%1$s of %2$s XMR) but the order passed its expiry window — NOT auto-cancelled. The funds are in your wallet; await the buyer\'s top-up or refund manually.', 'xmr-pay-for-woocommerce' ),
						(string) $r['receivedXmr'], (string) $order->get_meta( '_xmrpay_amount' )
					) );
					$order->save();
					$this->log( 'partial-paid order #' . $oid . ' kept past expiry (not cancelled)' );
				}
				continue;
			}
			$order->update_status( 'cancelled', __( 'Auto-cancelled: no Monero payment within the expiry window.', 'xmr-pay-for-woocommerce' ) );
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
			if ( is_wp_error( $r ) || empty( $r['paid'] ) ) {
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
	 */
	private function scan_order( $order ) {
		if ( ! $order || $order->get_meta( '_xmrpay_mode' ) !== 'watch' || $order->is_paid() ) {
			return;
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			return;
		}
		$id = $order->get_id();
		// per-order cooldown — caps how often THIS order hits a node (also a soft lock).
		$cd = 'xmrpay_scancd_' . $id;
		if ( false !== get_transient( $cd ) ) { return; }
		set_transient( $cd, 1, 20 );

		$address = (string) $order->get_meta( '_xmrpay_address' );
		$view    = $this->view_key();
		if ( $address === '' || $view === '' ) { return; }
		$scanner = $this->scanner();
		$tip     = $scanner->tip_height();
		if ( null === $tip ) { return; }   // node unreachable — try again next tick

		$min_conf = (int) $this->get_option( 'proof_min_conf', '1' );
		$tol_pico = XmrPay_Util::xmr_to_pico( $this->get_option( 'proof_tolerance_xmr', '0' ) );
		$exp_pico = XmrPay_Util::xmr_to_pico( (string) $order->get_meta( '_xmrpay_amount' ) );

		// already discovered the paying tx → just track its confirmations (cheap, 1 tx).
		$known = (string) $order->get_meta( '_xmrpay_watch_txid' );
		if ( $known !== '' ) {
			$r = $scanner->verify_payment( $known, $address, $view, array( 'tip' => $tip, 'require_commitment' => true ) );
			$this->settle_if_paid( $order, $r, $exp_pico, $tol_pico, $min_conf );
			return;
		}

		// otherwise scan NEW blocks since the checkpoint (with a small reorg buffer).
		$birthday   = (int) $order->get_meta( '_xmrpay_birthday' );
		$checkpoint = (int) $order->get_meta( '_xmrpay_scan_height' );
		$from       = max( $birthday, $checkpoint - 10 );
		$hit        = $scanner->scan( $address, $view, $from, $tip, array( 'tip' => $tip, 'max_blocks' => 30, 'time_budget' => 8.0, 'require_commitment' => true ) );
		if ( ! empty( $hit['found'] ) ) {
			$order->update_meta_data( '_xmrpay_watch_txid', $hit['txid'] );
			$order->update_meta_data( '_xmrpay_scan_height', max( $checkpoint, (int) ( $hit['block_height'] ?? $checkpoint ) ) );
			$order->save();
			$this->settle_if_paid( $order, $hit, $exp_pico, $tol_pico, $min_conf );
		} else {
			$order->update_meta_data( '_xmrpay_scan_height', max( $checkpoint, (int) ( $hit['scanned_to'] ?? $checkpoint ) ) );
			$order->save();
		}
	}

	/** Given a scanner result + the order's expected amount, complete the order if it's paid. */
	private function settle_if_paid( $order, $r, $exp_pico, $tol_pico, $min_conf ) {
		if ( empty( $r['found'] ) || empty( $r['commitment_ok'] ) ) { return; }
		// unknown confirmation state (the tx is neither in a block nor in the mempool) →
		// never settle, even at min_conf 0 — only a mempool (in_pool) tx counts as 0-conf.
		$cf = array_key_exists( 'confirmations', $r ) ? $r['confirmations'] : null;
		if ( null === $cf && empty( $r['in_pool'] ) ) { return; }
		$verdict = XmrPay_Util::classify_payment( $exp_pico, $r['amount_atomic'], $tol_pico, $min_conf, isset( $r['confirmations'] ) ? (int) $r['confirmations'] : 0, ! empty( $r['in_pool'] ), ! empty( $r['locked'] ) );
		if ( ! $verdict['paid'] ) { return; }
		$txid = isset( $r['txid'] ) && '' !== $r['txid'] ? $r['txid'] : (string) $order->get_meta( '_xmrpay_watch_txid' );
		$this->mark_paid( $order, array(
			'paid'          => true,
			'received_xmr'  => XmrPay_Util::pico_to_string( $r['amount_atomic'] ),
			'txids'         => array( $txid ),
			'confirmations' => isset( $r['confirmations'] ) ? (int) $r['confirmations'] : 0,
			'overpaid'      => '0' !== $verdict['overpaid_pico'],
			'overpaid_xmr'  => XmrPay_Util::pico_to_string( $verdict['overpaid_pico'] ),
		) );
	}

	/** Settings-page "Check setup" button (no-server modes): node + network + view-key match. */
	public function generate_test_node_html( $key, $data ) {
		ob_start(); ?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<button type="button" class="button" id="xmrpay-test-node"><?php esc_html_e( 'Check setup', 'xmr-pay-for-woocommerce' ); ?></button>
				<div id="xmrpay-node-result" style="margin-top:10px"></div>
				<p class="description"><?php esc_html_e( 'Checks the node is reachable, the network matches your address, and your view key belongs to the address (uses the values above, even unsaved).', 'xmr-pay-for-woocommerce' ); ?></p>
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
				<button type="button" class="button" id="xmrpay-test-agent"><?php esc_html_e( 'Test connection', 'xmr-pay-for-woocommerce' ); ?></button>
				<span id="xmrpay-test-result" style="margin-left:10px;font-weight:600"></span>
				<p class="description"><?php esc_html_e( 'Pings your agent (the URL/token above, even unsaved) and reports network + view-only status.', 'xmr-pay-for-woocommerce' ); ?></p>
				<?php // behaviour lives in assets/admin.js ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/** AJAX: ping {url}/healthz with the (possibly unsaved) settings from the form. */
	public function ajax_test_agent() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'xmrpay_test_agent', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'msg' => __( 'not allowed', 'xmr-pay-for-woocommerce' ) ) );
		}
		$url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( $url === '' ) {
			wp_send_json_error( array( 'msg' => __( 'set the agent URL first', 'xmr-pay-for-woocommerce' ) ) );
		}
		// the agent is designed to run locally — reject any URL whose host is not
		// localhost so this button cannot be used to probe internal network services.
		$parsed = wp_parse_url( $url );
		$host   = isset( $parsed['host'] ) ? strtolower( trim( $parsed['host'], '[]' ) ) : '';
		if ( ! in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			wp_send_json_error( array( 'msg' => __( 'Agent URL must point to localhost (127.0.0.1 or ::1).', 'xmr-pay-for-woocommerce' ) ) );
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
			wp_send_json_error( array( 'msg' => sprintf( __( 'agent replied HTTP %d', 'xmr-pay-for-woocommerce' ), (int) $code ) ) );
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
			__( 'Owed', 'xmr-pay-for-woocommerce' )          => $order->get_meta( '_xmrpay_amount' ) . ' XMR',
			__( 'Received', 'xmr-pay-for-woocommerce' )       => ( $order->get_meta( '_xmrpay_received' ) ?: '—' ) . ' XMR',
			__( 'Confirmations', 'xmr-pay-for-woocommerce' )  => $order->get_meta( '_xmrpay_confirmations' ) ?: '—',
		);
		echo '<div class="xmrpay-order-detail" style="clear:both;margin-top:12px"><h4 style="margin:0 0 6px">' . esc_html__( 'Monero payment', 'xmr-pay-for-woocommerce' ) . '</h4><p style="margin:0 0 4px"><strong>' . esc_html__( 'Address', 'xmr-pay-for-woocommerce' ) . ':</strong><br><code style="font-size:11px;word-break:break-all">' . esc_html( $addr ) . '</code></p>';
		foreach ( $rows as $label => $val ) {
			echo '<p style="margin:0"><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $val ) . '</p>';
		}
		$txids = (string) $order->get_meta( '_xmrpay_txids' );
		if ( $txids !== '' ) {
			echo '<p style="margin:4px 0 0"><strong>tx:</strong><br><code style="font-size:11px;word-break:break-all">' . esc_html( $txids ) . '</code></p>';
		}
		if ( $order->get_meta( '_xmrpay_overpaid' ) === 'yes' ) {
			echo '<p style="margin:6px 0 0;padding:6px 8px;background:#fffbeb;border:1px solid #f59e0b;border-radius:4px;color:#92400e"><strong>' . esc_html__( 'Overpaid', 'xmr-pay-for-woocommerce' ) . ':</strong> ' . esc_html( (string) $order->get_meta( '_xmrpay_overpaid_xmr' ) ) . ' XMR — ' . esc_html__( 'refund the difference to the buyer.', 'xmr-pay-for-woocommerce' ) . '</p>';
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
			// and require GMP (the PHP verifier needs it; without it order completion would
			// fatal AFTER the buyer paid, so we hide the gateway instead). See admin notice.
			return '' !== trim( (string) $this->get_option( 'xmr_address' ) )
				&& '' !== $this->view_key()
				&& extension_loaded( 'gmp' );
		}
		return '' !== trim( (string) $this->get_option( 'agent_url' ) );
	}

	/** Warn in wp-admin if a no-server mode is selected but PHP lacks the GMP extension. */
	public function maybe_warn_gmp() {
		if ( ! $this->uses_view_key() || extension_loaded( 'gmp' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>xmr-pay:</strong> '
			. esc_html__( 'The selected Monero mode verifies payments in PHP and needs the GMP extension, which is not installed. The Monero gateway is hidden until your host enables ext-gmp (or switch to Agent mode).', 'xmr-pay-for-woocommerce' )
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
			'testing'     => __( 'testing…', 'xmr-pay-for-woocommerce' ),
			'checking'    => __( 'checking…', 'xmr-pay-for-woocommerce' ),
			'reqfail'     => __( 'request failed', 'xmr-pay-for-woocommerce' ),
			'unreachable' => __( 'unreachable', 'xmr-pay-for-woocommerce' ),
		) );
	}

	/**
	 * AJAX: validate a no-server setup live — node reachable + its network, the address,
	 * and (the big one) that the private VIEW key actually belongs to the address. Returns
	 * a checklist so the wizard/settings can show concrete feedback before going live.
	 */
	public function ajax_test_node() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'xmrpay_test_node', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'msg' => __( 'not allowed', 'xmr-pay-for-woocommerce' ) ) );
		}
		if ( ! extension_loaded( 'gmp' ) ) {
			wp_send_json_error( array( 'msg' => __( 'PHP is missing the GMP extension — ask your host to enable ext-gmp (or use Agent mode).', 'xmr-pay-for-woocommerce' ) ) );
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
			$checks[] = array( 'ok' => true, 'msg' => sprintf( __( 'Node reachable — %1$s, block %2$s.', 'xmr-pay-for-woocommerce' ), $net, isset( $info['height'] ) ? $info['height'] : '?' ) );
			if ( 'unknown' !== $info['nettype'] && $info['nettype'] !== $addr_net ) {
				$ok = false;
				/* translators: 1: the address's network, 2: the node's network */
				$checks[] = array( 'ok' => false, 'msg' => sprintf( __( 'Network mismatch: your address is %1$s but the node is %2$s.', 'xmr-pay-for-woocommerce' ), $addr_net, $info['nettype'] ) );
			}
		} else {
			$ok = false;
			$checks[] = array( 'ok' => false, 'msg' => __( 'Node unreachable — check the URL and port.', 'xmr-pay-for-woocommerce' ) );
		}
		if ( '' !== $address ) {
			$valid = $keys && ! empty( $keys['address_valid'] );
			$ok    = $ok && $valid;
			/* translators: %s: network name */
			$checks[] = array( 'ok' => $valid, 'msg' => $valid ? sprintf( __( 'Address is valid (%s).', 'xmr-pay-for-woocommerce' ), $addr_net ) : __( 'Address could not be decoded — check it.', 'xmr-pay-for-woocommerce' ) );
		}
		if ( '' !== $address && '' !== $view ) {
			$match = $keys && ! empty( $keys['key_match'] );
			$ok    = $ok && $match;
			$checks[] = array( 'ok' => $match, 'msg' => $match ? __( 'View key belongs to this address.', 'xmr-pay-for-woocommerce' ) : __( 'View key does NOT match this address — detection would find nothing. Re-check the key.', 'xmr-pay-for-woocommerce' ) );
		} elseif ( '' === $view ) {
			$ok = false;
			$checks[] = array( 'ok' => false, 'msg' => __( 'No view key set yet.', 'xmr-pay-for-woocommerce' ) );
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
		$nodes = array_filter( array_map( 'trim', explode( ',', (string) $this->get_option( 'nodes' ) ) ) );
		$node  = $nodes ? $nodes[0] : 'http://node2.monerodevs.org:38089';
		return new XmrPay_Scanner( $node, $this->detect_network(), 12 );
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
			$this->get_option( 'agent_url' )
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
			return new WP_Error( 'xmrpay_rate', __( 'Could not get an XMR price. Check your pricing settings.', 'xmr-pay-for-woocommerce' ) );
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
			return $fixed > 0 ? $fixed : new WP_Error( 'xmrpay_rate', __( 'Set a fixed XMR rate in the payment settings.', 'xmr-pay-for-woocommerce' ) );
		}
		$live = ( 'custom' === $source ) ? $this->custom_rate( $currency ) : $this->xmr_rate( $currency );
		if ( ! is_wp_error( $live ) && (float) $live > 0 ) {
			return (float) $live;
		}
		if ( $fixed > 0 ) {
			$this->log( 'price feed (' . $source . ') unavailable — using the fixed-rate fallback ' . $fixed, 'warning' );
			return $fixed;
		}
		return is_wp_error( $live ) ? $live : new WP_Error( 'xmrpay_rate', __( 'Could not get an XMR price and no fixed-rate fallback is set.', 'xmr-pay-for-woocommerce' ) );
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
			return new WP_Error( 'xmrpay_rate', __( 'No custom price-source URL is set.', 'xmr-pay-for-woocommerce' ) );
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
			return new WP_Error( 'xmrpay_rate', __( 'The custom price source did not return a valid rate at that path.', 'xmr-pay-for-woocommerce' ) );
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
			return new WP_Error( 'xmrpay_rate', sprintf( __( 'No XMR price for %s.', 'xmr-pay-for-woocommerce' ), $currency ) );
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
				wc_add_notice( __( 'Could not compute a valid XMR amount. Check your rate settings.', 'xmr-pay-for-woocommerce' ), 'error' );
				return array( 'result' => 'failure' );
			}
			$order->payment_complete();
			$order->add_order_note( __( 'Order total is 0 — no Monero payment required.', 'xmr-pay-for-woocommerce' ) );
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
				wc_add_notice( __( 'Monero is not fully configured. Please contact us.', 'xmr-pay-for-woocommerce' ), 'error' );
				return array( 'result' => 'failure' );
			}
			if ( $this->is_watch_mode() ) {
				$sub = $this->scanner()->subaddress( 0, (int) $order_id, $this->view_key(), $primary );
				if ( ! $sub || empty( $sub['address'] ) ) {
					$this->log( 'subaddress derivation failed for #' . $order_id, 'error' );
					wc_add_notice( __( 'Could not start the Monero payment. Please contact us.', 'xmr-pay-for-woocommerce' ), 'error' );
					return array( 'result' => 'failure' );
				}
				$birthday = (int) $this->scanner()->tip_height();
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
			$order->update_status( 'on-hold', __( 'Awaiting Monero payment.', 'xmr-pay-for-woocommerce' ) );
			if ( WC()->cart ) { WC()->cart->empty_cart(); }
			return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
		}

		$created = $this->agent()->create_order( $amount, (string) $order_id, get_bloginfo( 'name' ) . ' #' . $order_id );
		if ( is_wp_error( $created ) ) {
			$this->log( 'create_order failed for #' . $order_id . ': ' . $created->get_error_message(), 'error' );
			wc_add_notice( __( 'Could not start the Monero payment. Try again or contact us.', 'xmr-pay-for-woocommerce' ), 'error' );
			$order->add_order_note( 'xmr-pay: agent create_order failed — ' . $created->get_error_message() );
			return array( 'result' => 'failure' );
		}
		$this->log( 'order #' . $order_id . ' → ' . $amount . ' XMR · ' . ( isset( $created['address'] ) ? $created['address'] : '?' ) );

		$order->update_meta_data( '_xmrpay_address', isset( $created['address'] ) ? $created['address'] : '' );
		$order->update_meta_data( '_xmrpay_amount', $amount );
		$order->save();

		// awaiting payment — not paid yet
		$order->update_status( 'on-hold', __( 'Awaiting Monero payment.', 'xmr-pay-for-woocommerce' ) );
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
			$cooldown = 'xmrpay_rcpt_' . (int) $order_id;
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
			<h2><?php esc_html_e( 'Pay with Monero', 'xmr-pay-for-woocommerce' ); ?></h2>
			<?php if ( $terminal ) : ?>
				<div style="margin:8px 0;padding:11px 13px;border:1px solid #f59e0b;border-radius:6px;color:#92400e;background:#fffbeb;font-size:13px;line-height:1.55">
					<?php /* translators: %s: order number */ echo esc_html( sprintf( __( 'This order (#%s) has expired. If you already sent a Monero payment, don\'t worry — your funds are safe in our wallet. Please contact us with your order number and we will complete it or refund you.', 'xmr-pay-for-woocommerce' ), $order_id ) ); ?>
				</div>
			<?php else : ?>
			<div id="xmrpay-status"<?php echo $proof ? '' : ' data-poll="' . esc_url( $status_url ) . '"'; ?> data-paid="<?php echo $paid ? '1' : '0'; ?>"<?php echo $redirect !== '' ? ' data-redirect="' . esc_url( $redirect ) . '"' : ''; ?>
				 style="font-weight:600;margin:8px 0;<?php echo $paid ? 'color:#15803d' : 'color:#b45309'; ?>">
				<?php echo $paid ? esc_html__( '✓ Payment received', 'xmr-pay-for-woocommerce' ) : esc_html__( '● Awaiting payment…', 'xmr-pay-for-woocommerce' ); ?>
			</div>
			<?php if ( $overpaid ) : ?>
				<div class="xmrpay-overpaid" style="margin:10px 0;padding:11px 13px;border:1px solid #f59e0b;border-radius:6px;color:#92400e;background:#fffbeb;font-size:13px;line-height:1.5">
					<?php /* translators: %s: amount overpaid in XMR */ echo esc_html( sprintf( __( 'You overpaid %s XMR. Please contact the store to arrange a refund of the difference.', 'xmr-pay-for-woocommerce' ), $overpaid_xmr ) ); ?>
				</div>
			<?php endif; ?>
			<?php if ( ! $paid ) : ?>
				<xmr-pay address="<?php echo esc_attr( $addr ); ?>" amount="<?php echo esc_attr( $amount ); ?>"
						 label="<?php echo esc_attr( get_bloginfo( 'name' ) . ' #' . $order_id ); ?>"
						 theme="<?php echo esc_attr( $this->get_option( 'checkout_theme', 'light' ) ); ?>"
						 lang="<?php echo esc_attr( substr( get_locale(), 0, 2 ) === 'es' ? 'es' : 'en' ); ?>"></xmr-pay>
				<?php if ( $proof ) : ?>
					<div class="xmrpay-proof" data-verify="<?php echo esc_url( $verify_url ); ?>" style="margin-top:14px">
						<p style="margin:0 0 6px;font-size:13px;color:#374151"><?php esc_html_e( 'Already paid? Paste your transaction ID and we’ll confirm it.', 'xmr-pay-for-woocommerce' ); ?></p>
						<input type="text" id="xmrpay-txid" inputmode="latin" autocomplete="off" spellcheck="false"
							   placeholder="<?php esc_attr_e( 'Transaction ID (64 hex characters)', 'xmr-pay-for-woocommerce' ); ?>"
							   style="width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #d1d5db;border-radius:6px;font-family:ui-monospace,Menlo,monospace;font-size:12px" />
						<button type="button" id="xmrpay-verify-btn"
								style="margin-top:8px;width:100%;padding:10px;border:0;border-radius:6px;background:#ff6600;color:#fff;font-weight:700;font-size:13px;cursor:pointer">
							<?php esc_html_e( 'I’ve paid — verify', 'xmr-pay-for-woocommerce' ); ?>
						</button>
						<div id="xmrpay-proof-msg" style="margin-top:8px;font-size:13px;min-height:1em"></div>
					</div>
					<?php // behaviour lives in assets/checkout.js (wired from .xmrpay-proof[data-verify]) ?>
				<?php endif; ?>
			<?php endif; ?>
			<?php echo $receipt_html; // built with esc_* in receipt_block_html() ?>
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
			<p style="margin:0 0 6px;font-weight:600"><?php esc_html_e( 'Your cryptographic receipt', 'xmr-pay-for-woocommerce' ); ?></p>
			<p style="margin:0;font-size:12px;color:#6b7280">
				<?php esc_html_e( 'Signed by the merchant. Download it and verify it yourself — anyone can, forever, with no third party.', 'xmr-pay-for-woocommerce' ); ?>
				<?php if ( $fp !== '' ) { /* translators: %s: signer fingerprint */ echo ' ' . esc_html( sprintf( __( 'Signer: %s', 'xmr-pay-for-woocommerce' ), $fp ) ); } ?>
			</p>
			<a href="<?php echo esc_attr( $download ); ?>" download="receipt-<?php echo esc_attr( $order->get_id() ); ?>.json"
			   style="<?php echo esc_attr( $btn ); ?>;background:#ff6600;color:#fff">&#8595; <?php esc_html_e( 'Download receipt', 'xmr-pay-for-woocommerce' ); ?></a>
			<a href="<?php echo esc_attr( $verify ); ?>" target="_blank" rel="noopener"
			   style="<?php echo esc_attr( $btn ); ?>;border:1px solid #d1d5db;color:#111">&#8599; <?php esc_html_e( 'Verify receipt', 'xmr-pay-for-woocommerce' ); ?></a>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Server-side proxy: the buyer's browser polls this; we query the private agent. */
	public function ajax_status() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) || $order->get_payment_method() !== $this->id ) {
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
		if ( ! empty( $r['paid'] ) ) {
			$this->mark_paid( $order, $r );
		}
		$num = function ( $k ) use ( $r ) { return isset( $r[ $k ] ) && is_numeric( $r[ $k ] ) ? 0 + $r[ $k ] : null; };
		wp_send_json( array(
			'paid'             => ! empty( $r['paid'] ),
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
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) || $order->get_payment_method() !== $this->id ) {
			wp_send_json( array( 'paid' => false, 'message' => __( 'Order not found.', 'xmr-pay-for-woocommerce' ) ), 404 );
		}
		if ( $order->is_paid() ) {
			wp_send_json( array( 'paid' => true, 'status' => 'paid' ) );
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			wp_send_json( array( 'paid' => false, 'terminal' => true, 'message' => __( 'This order can no longer be paid. If you already sent funds, contact us — they are safe.', 'xmr-pay-for-woocommerce' ) ) );
		}
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $txid ) ) {
			wp_send_json( array( 'paid' => false, 'message' => __( 'That doesn’t look like a transaction ID.', 'xmr-pay-for-woocommerce' ) ) );
		}
		// rate-limit: this hits a node, so cap one buyer to one check every few seconds.
		$rl = 'xmrpay_vrl_' . (int) $order_id;
		if ( false !== get_transient( $rl ) ) {
			wp_send_json( array( 'paid' => false, 'message' => __( 'Please wait a few seconds and try again.', 'xmr-pay-for-woocommerce' ) ) );
		}
		set_transient( $rl, 1, 5 );

		// dedup: a txid already claimed by another order can never settle this one. The
		// meta query is the durable check; a short txid-scoped lock around it + the node
		// call serialises two requests that submit the SAME txid to DIFFERENT orders at
		// once, so they can't both pass the check before either writes. (The unique
		// amount-nonce per order is the deeper guarantee; this closes the race window.)
		$txlock = 'xmrpay_txlock_' . $txid;
		if ( false !== get_transient( $txlock ) ) {
			wp_send_json( array( 'paid' => false, 'message' => __( 'That transaction is being processed — try again in a moment.', 'xmr-pay-for-woocommerce' ) ) );
		}
		set_transient( $txlock, (int) $order_id, 30 );
		if ( $this->txid_used_elsewhere( $txid, $order_id ) ) {
			delete_transient( $txlock );
			wp_send_json( array( 'paid' => false, 'message' => __( 'That transaction has already been used for another order.', 'xmr-pay-for-woocommerce' ) ) );
		}

		$address = (string) $order->get_meta( '_xmrpay_address' );
		$amount  = (string) $order->get_meta( '_xmrpay_amount' );
		$view    = $this->view_key();
		if ( $address === '' || $amount === '' || $view === '' ) {
			wp_send_json( array( 'paid' => false, 'message' => __( 'Payment is not fully configured. Contact the store.', 'xmr-pay-for-woocommerce' ) ), 500 );
		}

		$scanner = $this->scanner();
		$tip     = $scanner->tip_height();
		$res     = $scanner->verify_payment( $txid, $address, $view, array( 'tip' => $tip, 'require_commitment' => true ) );
		$this->log( 'proof verify #' . $order_id . ' tx ' . $txid . ' → ' . wp_json_encode( $res ) );

		if ( empty( $res['found'] ) ) {
			wp_send_json( array( 'paid' => false, 'status' => 'not-found', 'message' => __( 'No payment to your order was found in that transaction. Check the transaction ID.', 'xmr-pay-for-woocommerce' ) ) );
		}
		if ( empty( $res['commitment_ok'] ) ) {
			wp_send_json( array( 'paid' => false, 'status' => 'invalid', 'message' => __( 'That transaction did not verify. Contact the store.', 'xmr-pay-for-woocommerce' ) ) );
		}

		$min_conf  = (int) $this->get_option( 'proof_min_conf', '1' );
		$tol_pico  = XmrPay_Util::xmr_to_pico( $this->get_option( 'proof_tolerance_xmr', '0' ) );
		$exp_pico  = XmrPay_Util::xmr_to_pico( $amount );
		// unknown confirmation state (neither in a block nor in the mempool) is never paid,
		// even at min_conf 0 — only a mempool tx counts as 0-conf.
		$cf        = array_key_exists( 'confirmations', $res ) ? $res['confirmations'] : null;
		if ( null === $cf && empty( $res['in_pool'] ) ) {
			wp_send_json( array( 'paid' => false, 'status' => 'pending', 'message' => __( 'Payment seen — waiting for confirmation. This page will update shortly.', 'xmr-pay-for-woocommerce' ) ) );
		}
		$verdict   = XmrPay_Util::classify_payment( $exp_pico, $res['amount_atomic'], $tol_pico, $min_conf, null === $cf ? 0 : (int) $cf, ! empty( $res['in_pool'] ), ! empty( $res['locked'] ) );

		if ( ! $verdict['paid'] ) {
			$msg = __( 'Payment seen — waiting for confirmation. This page will update shortly.', 'xmr-pay-for-woocommerce' );
			/* translators: %s: amount still owed in XMR */
			if ( 'underpaid' === $verdict['status'] ) { $msg = sprintf( __( 'The amount is short by %s XMR. Please send the difference.', 'xmr-pay-for-woocommerce' ), XmrPay_Util::pico_to_string( $verdict['shortfall_pico'] ) ); }
			elseif ( 'locked' === $verdict['status'] ) { $msg = __( 'The payment is time-locked and not spendable yet. Try again later.', 'xmr-pay-for-woocommerce' ); }
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
		$sig    = isset( $_SERVER['HTTP_X_XMR_PAY_SIGNATURE'] ) ? $_SERVER['HTTP_X_XMR_PAY_SIGNATURE'] : '';

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
		// a late payment for a cancelled/refunded order must NOT silently resurrect
		// it — the funds are in your wallet; flag it for the merchant to reconcile.
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
			$order->add_order_note( sprintf(
				/* translators: %s order status */
				__( 'Monero payment arrived for a %s order — NOT auto-completed. The funds are in your wallet; reconcile manually.', 'xmr-pay-for-woocommerce' ),
				$order->get_status()
			) );
			$this->log( 'late payment for ' . $order->get_status() . ' order #' . $order->get_id() . ' — not auto-completed', 'warning' );
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

		$note = __( 'Monero payment confirmed by the xmr-pay agent.', 'xmr-pay-for-woocommerce' );
		/* translators: 1: amount of XMR received, 2: amount of XMR owed */
		if ( $received !== '' ) { $note .= ' ' . sprintf( __( 'Received: %1$s XMR (owed %2$s).', 'xmr-pay-for-woocommerce' ), $received, $owed ); }
		/* translators: %d: number of confirmations */
		if ( $confs !== null ) { $note .= ' ' . sprintf( __( 'Confirmations: %d.', 'xmr-pay-for-woocommerce' ), $confs ); }
		/* translators: %s: transaction hash(es) */
		if ( $txids !== '' ) { $note .= ' ' . sprintf( __( 'tx: %s', 'xmr-pay-for-woocommerce' ), $txids ); }
		/* translators: %s: amount overpaid in XMR */
		if ( $overpaid ) { $note .= ' ' . sprintf( __( 'OVERPAID by %s XMR — the buyer was asked to contact you; refund the difference manually.', 'xmr-pay-for-woocommerce' ), $overpaid_xmr ); }
		$order->add_order_note( $note );
		$this->log( 'marked paid #' . $order->get_id() . ' · received ' . $received . ' · tx ' . $txids );

		// payment_complete reduces stock, sets processing/completed, fires emails.
		// pass a SINGLE tx hash (WC stores it as _transaction_id + builds the explorer
		// link); the full list lives in _xmrpay_txids.
		$order->payment_complete( $first_txid );
	}

	/**
	 * A WooCommerce refund records money returned — but Monero is non-custodial:
	 * we hold no spend key, and a Monero tx never reveals the sender, so there is
	 * no address to auto-refund to. Leave a clear note so the merchant sends it
	 * back by hand.
	 */
	public function on_refunded( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}
		$refund = wc_get_order( $refund_id );
		$amt    = $refund ? $refund->get_amount() : '';
		$order->add_order_note( sprintf(
			/* translators: %s refund amount with currency symbol */
			__( 'Monero is non-custodial — this %s refund is recorded in WooCommerce only. Send the XMR back manually: ask the customer for a Monero receive address (a tx does not reveal the sender), then pay it from your wallet.', 'xmr-pay-for-woocommerce' ),
			$amt !== '' ? wc_price( $amt ) : ''
		) );
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
			echo "\n" . sprintf( __( 'Pay %1$s XMR to: %2$s', 'xmr-pay-for-woocommerce' ), $amount, $addr ) . "\n";
			/* translators: %s: payment page URL */
			echo sprintf( __( 'Payment page (QR + live status): %s', 'xmr-pay-for-woocommerce' ), esc_url( $pay_url ) ) . "\n\n";
			return;
		}
		echo '<div style="margin:0 0 24px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:8px">';
		echo '<p style="margin:0 0 8px;font-weight:600">' . esc_html__( 'Complete your Monero payment', 'xmr-pay-for-woocommerce' ) . '</p>';
		/* translators: %s: amount of XMR (bold) */
		echo '<p style="margin:0 0 6px">' . sprintf( esc_html__( 'Send %s XMR to:', 'xmr-pay-for-woocommerce' ), '<strong>' . esc_html( $amount ) . '</strong>' ) . '</p>';
		echo '<p style="margin:0 0 10px;word-break:break-all"><code style="font-size:12px">' . esc_html( $addr ) . '</code></p>';
		echo '<p style="margin:0"><a href="' . esc_url( $pay_url ) . '" style="color:#ff6600;font-weight:600">' . esc_html__( 'Open the payment page (QR + live status) →', 'xmr-pay-for-woocommerce' ) . '</a></p>';
		echo '</div>';
	}
}
