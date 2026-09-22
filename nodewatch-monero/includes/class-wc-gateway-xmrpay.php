<?php
// Integrate Monero checkout, reconciliation and refund requests with WooCommerce.


if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- WC_Gateway_* is the WooCommerce gateway naming convention
class WC_Gateway_XmrPay extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'xmrpay';
		$this->method_title       = __( 'Monero (Nodewatch)', 'nodewatch-monero' );
		$this->method_description = __( 'Accept Monero, non-custodial. Funds go straight to your address. WordPress verifies payments itself in PHP (no server) — or point it at your own agent daemon at scale.', 'nodewatch-monero' );
		$this->has_fields         = false;
		$this->icon               = apply_filters( 'woocommerce_xmrpay_icon', plugins_url( 'assets/monero-symbol.png', XMRPAY_WC_FILE ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- xmrpay is the plugin's internal prefix

		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Monero (XMR)', 'nodewatch-monero' ) );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'render_payment_panel' ) );

		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'render_payment_panel' ) );

		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		add_action( 'woocommerce_api_xmrpay_webhook', array( $this, 'handle_webhook' ) );

		add_action( 'woocommerce_api_xmrpay_verify', array( $this, 'handle_verify' ) );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 10, 2 );

		add_action( 'woocommerce_api_xmrpay_refund', array( $this, 'handle_refund' ) );

		add_action( 'admin_post_xmrpay_refund_sent', array( $this, 'handle_refund_sent' ) );

		add_action( 'admin_post_xmrpay_refund_reissue', array( $this, 'handle_refund_reissue' ) );

		add_action( 'woocommerce_checkout_create_order', array( $this, 'strip_pii' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'strip_pii' ), 20, 2 );

		add_action( 'admin_notices', array( $this, 'maybe_warn_gmp' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'admin_order_details' ) );
	}

	public function strip_pii( $order, $data = null ) {
		$method = ( is_array( $data ) && ! empty( $data['payment_method'] ) ) ? $data['payment_method'] : $order->get_payment_method();
		if ( $this->id === $method ) {
			$order->set_customer_ip_address( '' );
			$order->set_customer_user_agent( '' );
		}
	}

	private function log( $message, $level = 'info' ) {
		if ( 'yes' !== $this->get_option( 'debug_log' ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'xmrpay' ) );
	}

	public function process_admin_options() {
		$old_settings = get_option( $this->get_option_key(), array() );
		$rows = isset( $_POST['node_configs'] ) ? wp_unslash( $_POST['node_configs'] ) : null;
		$nodes = null === $rows ? null : XmrPay_Node_Config::sanitize_submission( $rows, $old_settings['node_configs'] ?? ( $old_settings['nodes'] ?? array() ) );
		if ( is_wp_error( $nodes ) ) {
			if ( class_exists( 'WC_Admin_Settings' ) ) { WC_Admin_Settings::add_error( $nodes->get_error_message() ); }
			return false;
		}
		$saved    = parent::process_admin_options();
		$settings = get_option( $this->get_option_key(), array() );
		if ( ! is_array( $settings ) ) {
			return $saved;
		}
		if ( null !== $nodes ) { $settings['node_configs'] = $nodes; $settings['nodes'] = XmrPay_Node_Config::legacy_urls( $nodes ); }
		$raw        = isset( $settings['agent_url'] ) ? (string) $settings['agent_url'] : '';
		$normalized = XmrPay_Util::normalize_agent_url( $raw );
		if ( '' !== trim( $raw ) && '' === $normalized && class_exists( 'WC_Admin_Settings' ) ) {
			WC_Admin_Settings::add_error( __( 'Agent URL must point to localhost (127.0.0.1 or ::1).', 'nodewatch-monero' ) );
		}
		if ( null !== $nodes ) { update_option( $this->get_option_key(), $settings ); $this->settings = $settings; }
		if ( $raw !== $normalized ) {
			$settings['agent_url'] = $normalized;
			update_option( $this->get_option_key(), $settings );
			$this->settings = $settings;
		}
		return $saved;
	}
	public function generate_node_list_html( $key, $data ) {
		$rows = $this->get_option( 'node_configs', $this->get_option( 'nodes', $data['default'] ?? '' ) );
		return '<tr><th scope="row">' . esc_html( $data['title'] ) . '</th><td>' . XmrPay_Node_Fields::render( $rows ) . '</td></tr>';
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
				'type'        => 'node_list',
				'default'     => 'http://node2.monerodevs.org:38089',
				'description' => __( 'Add public or private Monero nodes in priority order. Authentication is configured separately for each node. Block lookup fails over to the next node. Settlement requires every configured node to answer and agree on the transaction and its block height; an unavailable or disagreeing node pauses payment confirmation. Keep them on the same network and well synced. The node never sees your view key.', 'nodewatch-monero' ),
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
				'description' => __( 'Base URL of your scanner-agent. Must point to localhost (127.0.0.1, ::1, or localhost).', 'nodewatch-monero' ),
			),
			'agent_token' => array(
				'title'       => __( 'Agent token', 'nodewatch-monero' ),
				'type'        => 'password',
				'description' => __( 'The AGENT_TOKEN configured on the agent. Required in Agent mode, including for localhost connections.', 'nodewatch-monero' ),
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
				'description' => __( 'Cancel an unpaid order this many hours after it was placed (frees reserved stock). 0 = never. Payments after cancellation require manual reconciliation. WordPress scanning may not detect a transfer that is still awaiting mining when the order expires. Leave this at 0 to keep waiting. This window doubles as your rate-drift guard: if you price in fiat, an order paid much later settles at the XMR amount locked at checkout, so set this to how long you are willing to honour that rate (e.g. 12–24). If you price natively in XMR, the amount owed never changes — leave it at 0.', 'nodewatch-monero' ),
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

			if ( 'watch' === $mode ) {

				if ( false === $this->scan_order( $order ) ) {
					$this->log( 'expiry deferred for watch order #' . $oid . ' — scan incomplete' );
					continue;
				}
				$order = wc_get_order( $oid );
				if ( ! $order || $order->is_paid() ) { continue; }
				if ( 'yes' === $order->get_meta( '_xmrpay_partial_flagged' ) ) { continue; }
				$order->update_status( 'cancelled', __( 'Auto-cancelled: no Monero payment within the expiry window.', 'nodewatch-monero' ) );
				$this->log( 'expired watch-mode order #' . $oid );
				continue;
			}

			if ( 'proof' === $mode ) {
				$order->update_status( 'cancelled', __( 'Auto-cancelled: no Monero payment within the expiry window.', 'nodewatch-monero' ) );
				$this->log( 'expired proof-mode order #' . $oid );
				continue;
			}

			$r = $this->agent()->get_order( (string) $oid, 6 );
			if ( is_wp_error( $r ) ) {
				continue;
			}
			if ( true === filter_var( $r['paid'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
				$this->mark_paid( $order, $r );
				continue;
			}
			if ( ! empty( $r['syncing'] ) || (float) ( $r['pendingXmr'] ?? 0 ) > 0 || (float) ( $r['lockedXmr'] ?? 0 ) > 0 ) {
				continue;
			}
			$recv = isset( $r['receivedXmr'] ) && is_numeric( $r['receivedXmr'] ) ? (float) $r['receivedXmr'] : 0.0;
			if ( $recv > 0 ) {

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

			if ( $order->get_meta( '_xmrpay_mode' ) === 'watch' ) {
				if ( $scanned >= 8 ) { continue; }
				$scanned++;
				$this->scan_order( $order );
				continue;
			}

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

	private function scan_order( $order ) {
		if ( ! $order || $order->get_meta( '_xmrpay_mode' ) !== 'watch' || $order->is_paid() ) {
			return true;
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			return true;
		}
		$id = $order->get_id();

		$cd = 'xmrpay_scancd_' . get_current_blog_id() . '_' . $id;
		if ( false !== get_transient( $cd ) ) { return false; }
		set_transient( $cd, 1, 20 );

		$address = (string) $order->get_meta( '_xmrpay_address' );
		$view    = $this->view_key();
		$scanner = $this->scanner();
		$tip     = $address !== '' && $view !== '' ? $scanner->tip_height() : null;

		if ( null === $tip ) {
			$status = $order->get_meta( '_xmrpay_watch_status' );
			if ( ! is_array( $status ) ) { $status = array( 'status' => 'pending' ); }
			$status['reachable'] = false;
			$order->update_meta_data( '_xmrpay_watch_status', $status );
			$order->save();
			delete_transient( $cd );
			return false;
		}

		$min_conf = (int) $this->get_option( 'proof_min_conf', '1' );
		$tol_pico = XmrPay_Util::xmr_to_pico( $this->get_option( 'proof_tolerance_xmr', '0' ) );
		$exp_pico = XmrPay_Util::xmr_to_pico( (string) $order->get_meta( '_xmrpay_amount' ) );

		$txids = json_decode( (string) $order->get_meta( '_xmrpay_watch_txids' ), true );
		if ( ! is_array( $txids ) ) { $txids = array(); }
		$legacy = (string) $order->get_meta( '_xmrpay_watch_txid' );
		if ( '' !== $legacy && ! in_array( $legacy, $txids, true ) ) { $txids[] = $legacy; }

		$rows = array();

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

		$birthday   = (int) $order->get_meta( '_xmrpay_birthday' );
		$checkpoint = (int) $order->get_meta( '_xmrpay_scan_height' );

		$from       = max( $birthday, $checkpoint - max( 10, $min_conf ) );
		$res        = $scanner->scan_all( $address, $view, $from, $tip - 1, array( 'tip' => $tip, 'max_blocks' => 30, 'time_budget' => 8.0, 'require_commitment' => true ) );
		$scanned_to = isset( $res['scanned_to'] ) ? (int) $res['scanned_to'] : $checkpoint;
		$matches    = ( isset( $res['matches'] ) && is_array( $res['matches'] ) ) ? $res['matches'] : array();
		foreach ( $matches as $m ) {
			$rows[] = $m;
			if ( '' !== (string) $m['txid'] && ! in_array( $m['txid'], $txids, true ) ) { $txids[] = (string) $m['txid']; }
		}

		$txids = array_values( array_unique( $txids ) );
		$order->update_meta_data( '_xmrpay_watch_txids', wp_json_encode( $txids ) );
		if ( ! empty( $txids ) ) { $order->update_meta_data( '_xmrpay_watch_txid', $txids[0] ); }
		$order->update_meta_data( '_xmrpay_scan_height', max( $checkpoint, $scanned_to ) );
		$order->save();

		$sum = XmrPay_Util::summarize_payments( $rows, $exp_pico, $tol_pico, $min_conf );

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
			), true );
			return true;
		}

		$order->update_meta_data( '_xmrpay_watch_status', array(
			'reachable'        => true,
			'status'           => 'mempool' === $sum['status'] ? 'unconfirmed' : $sum['status'],
			'shortfallXmr'     => XmrPay_Util::pico_to_string( $sum['shortfall_pico'] ),
			'receivedXmr'      => XmrPay_Util::pico_to_string( $sum['received_pico'] ),
			'confirmations'    => (int) $sum['confirmations'],
			'minConfirmations' => $min_conf,
			'tipHeight'        => $tip,
		) );
		$order->save();

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

		return $from < $tip && $scanned_to >= $tip - 1;
	}

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

	public function generate_test_node_html( $key, $data ) {
		ob_start(); ?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<button type="button" class="button" id="xmrpay-test-node"><?php esc_html_e( 'Check setup', 'nodewatch-monero' ); ?></button>
				<div id="xmrpay-node-result" style="margin-top:10px"></div>
				<p class="description"><?php esc_html_e( 'Checks the node is reachable, the network matches your address, and your view key belongs to the address (uses the values above, even unsaved).', 'nodewatch-monero' ); ?></p>
				<?php  ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function generate_test_connection_html( $key, $data ) {
		$field = esc_attr( $this->get_field_key( $key ) );
		ob_start(); ?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<button type="button" class="button" id="xmrpay-test-agent"><?php esc_html_e( 'Test connection', 'nodewatch-monero' ); ?></button>
				<span id="xmrpay-test-result" style="margin-left:10px;font-weight:600"></span>
				<p class="description"><?php esc_html_e( 'Pings your agent (the URL/token above, even unsaved) and reports network + view-only status.', 'nodewatch-monero' ); ?></p>
				<?php  ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function ajax_test_agent() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'xmrpay_test_agent', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'msg' => __( 'not allowed', 'nodewatch-monero' ) ) );
		}
		$url_raw = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$url     = XmrPay_Util::normalize_agent_url( $url_raw );
		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( '' === trim( $url_raw ) ) {
			wp_send_json_error( array( 'msg' => __( 'set the agent URL first', 'nodewatch-monero' ) ) );
		}
		if ( '' === $url ) {
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
		update_option( 'xmrpay_agent_network', $network );

		update_option( 'xmrpay_agent_tested_url', untrailingslashit( trim( (string) $url ) ) );
		$view = ! empty( $body['viewOnly'] ) ? 'view-only' : 'NOT view-only (!)';
		wp_send_json_success( array( 'msg' => sprintf( 'connected · %s · %s', $network !== '' ? $network : '?', $view ) ) );
	}

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

	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( $this->uses_view_key() ) {

			return '' !== trim( (string) $this->get_option( 'xmr_address' ) )
				&& '' !== $this->view_key()
				&& XmrPay_Util::crypto_ready();
		}
		return '' !== $this->agent_url();
	}

	public function maybe_warn_gmp() {
		if ( ! $this->uses_view_key() || XmrPay_Util::crypto_ready() ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>Nodewatch:</strong> '
			. esc_html__( 'The selected Monero mode verifies payments in PHP and needs the GMP and BCMath extensions, one of which is not installed. The Monero gateway is hidden until your host enables ext-gmp and ext-bcmath (or switch to Agent mode).', 'nodewatch-monero' )
			. '</p></div>';
	}

	public function admin_assets( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) { return; }

		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'xmrpay' !== $section ) { return; }
		wp_enqueue_script( 'xmrpay-admin', plugins_url( 'assets/admin.js', XMRPAY_WC_FILE ), array(), XMRPAY_WC_VERSION, true );
		wp_enqueue_style( 'xmrpay-node-fields', plugins_url( 'assets/node-fields.css', XMRPAY_WC_FILE ), array(), XMRPAY_WC_VERSION . '-node-fields-3' );
		wp_enqueue_script( 'xmrpay-node-fields', plugins_url( 'assets/node-fields.js', XMRPAY_WC_FILE ), array(), XMRPAY_WC_VERSION, true );
		wp_localize_script( 'xmrpay-admin', 'xmrpayAdmin', array(
			'ajaxurl'     => admin_url( 'admin-ajax.php' ),
			'agentNonce'  => wp_create_nonce( 'xmrpay_test_agent' ),
			'nodeNonce'   => wp_create_nonce( 'xmrpay_test_node' ),
			'testing'     => __( 'testing…', 'nodewatch-monero' ),
			'checking'    => __( 'checking…', 'nodewatch-monero' ),
			'nodes'       => __( 'nodes', 'nodewatch-monero' ),
			'reqfail'     => __( 'request failed', 'nodewatch-monero' ),
			'unreachable' => __( 'unreachable', 'nodewatch-monero' ),
		) );
	}

	public function ajax_test_node() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'xmrpay_test_node', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'msg' => __( 'not allowed', 'nodewatch-monero' ) ) );
		}
		if ( ! XmrPay_Util::crypto_ready() ) {
			wp_send_json_error( array( 'msg' => __( 'PHP is missing the GMP or BCMath extension — ask your host to enable ext-gmp and ext-bcmath (or use Agent mode).', 'nodewatch-monero' ) ) );
		}
		$address = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
		$raw_nodes = isset( $_POST['node_configs'] ) ? json_decode( wp_unslash( $_POST['node_configs'] ), true ) : array();
		$old_nodes = $this->get_option( 'node_configs', $this->get_option( 'nodes', array() ) );
		$submitted_node_count = is_array( $raw_nodes ) ? count( $raw_nodes ) : 0;
		$nodes = XmrPay_Node_Config::sanitize_submission( is_array( $raw_nodes ) ? array_slice( $raw_nodes, 0, 10 ) : array(), $old_nodes );
		if ( is_wp_error( $nodes ) || empty( $nodes ) ) {
			wp_send_json_error( array( 'code' => 'invalid_config', 'msg' => __( 'Enter a valid node URL and authentication details.', 'nodewatch-monero' ) ) );
		}
		$view    = isset( $_POST['view_key'] ) ? sanitize_text_field( wp_unslash( $_POST['view_key'] ) ) : '';
		if ( defined( 'XMRPAY_VIEW_KEY' ) && '' !== trim( (string) XMRPAY_VIEW_KEY ) ) { $view = trim( (string) XMRPAY_VIEW_KEY ); }

		$c        = '' !== $address ? $address[0] : '4';
		$addr_net = '5' === $c ? 'stagenet' : ( in_array( $c, array( '9', 'A', 'B' ), true ) ? 'testnet' : 'mainnet' );

		require_once __DIR__ . '/class-xmrpay-scanner.php';
		$checks               = array();
		$healthy_nodes        = 0;
		$verification_scanner = null;
		$fallback_scanner     = null;
		$setup_timeout        = $this->setup_node_timeout( count( $nodes ) );
		foreach ( $nodes as $index => $node_config ) {
			$scanner = $this->create_setup_scanner( array( $node_config ), $addr_net, $setup_timeout );
			if ( null === $fallback_scanner ) { $fallback_scanner = $scanner; }
			$started_at   = microtime( true );
			$info         = $scanner->node_info();
			$elapsed_ms   = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
			$node_number  = $index + 1;
			$reported_net = isset( $info['nettype'] ) ? (string) $info['nettype'] : 'unknown';

			if ( ! empty( $info['ok'] ) && 'unknown' !== $reported_net && $reported_net !== $addr_net ) {
				/* translators: 1: node number, 2: node network, 3: address network */
				$checks[] = array(
					'ok'      => false,
					'warning' => true,
					'code'    => 'network_mismatch',
					'node'    => $node_number,
					'elapsed_ms' => $elapsed_ms,
					'msg'     => sprintf( __( 'Node %1$d uses %2$s, but your address is %3$s. Review or replace this node.', 'nodewatch-monero' ), $node_number, $reported_net, $addr_net ),
				);
				continue;
			}

			if ( ! empty( $info['ok'] ) ) {
				$healthy_nodes++;
				if ( null === $verification_scanner ) { $verification_scanner = $scanner; }
				$net = 'unknown' !== $reported_net ? $reported_net : $addr_net;
				/* translators: 1: node number, 2: network name, 3: block height */
				$checks[] = array( 'ok' => true, 'node' => $node_number, 'elapsed_ms' => $elapsed_ms, 'msg' => sprintf( __( 'Node %1$d is reachable. %2$s, block %3$s.', 'nodewatch-monero' ), $node_number, $net, isset( $info['height'] ) ? $info['height'] : '?' ) );
				continue;
			}

			$diagnostic = self::node_setup_diagnostic( $scanner->last_node_error() );
			/* translators: 1: node number, 2: diagnostic message */
			$diagnostic['msg'] = sprintf( __( 'Node %1$d: %2$s Review, correct, or replace this node.', 'nodewatch-monero' ), $node_number, $diagnostic['msg'] );
			$checks[] = array_merge( array( 'ok' => false, 'warning' => true, 'node' => $node_number, 'elapsed_ms' => $elapsed_ms ), $diagnostic );
		}

		$node_count    = count( $nodes );
		$warning_count = $node_count - $healthy_nodes;
		if ( $healthy_nodes > 0 && $warning_count > 0 ) {
			/* translators: 1: healthy node count, 2: checked node count, 3: warning node count */
			$summary = sprintf( _n( '%1$d of %2$d nodes are healthy; review or replace %3$d warning node.', '%1$d of %2$d nodes are healthy; review or replace %3$d warning nodes.', $warning_count, 'nodewatch-monero' ), $healthy_nodes, $node_count, $warning_count );
		} elseif ( $healthy_nodes > 0 ) {
			/* translators: 1: healthy node count, 2: checked node count */
			$summary = sprintf( __( '%1$d of %2$d nodes are healthy.', 'nodewatch-monero' ), $healthy_nodes, $node_count );
		} else {
			$summary = __( 'No usable nodes remain; review, correct, or replace the warning nodes.', 'nodewatch-monero' );
		}
		array_unshift( $checks, array( 'ok' => $healthy_nodes > 0, 'msg' => $summary ) );
		if ( $submitted_node_count > $node_count ) {
			$checks[] = array( 'ok' => false, 'warning' => true, 'code' => 'node_limit', 'msg' => __( 'Only the first 10 nodes were checked. Review or remove the extra nodes.', 'nodewatch-monero' ) );
		}

		$ok      = $healthy_nodes > 0;
		$scanner = $verification_scanner ?: $fallback_scanner;
		$keys    = ( '' !== $address && '' !== $view && $scanner ) ? $scanner->verify_keys( $address, $view ) : null;
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

	protected function create_setup_scanner( $node, $network, $timeout = 5 ) {
		return new XmrPay_Scanner( $node, $network, $timeout );
	}

	protected function setup_node_timeout( $node_count ) {
		$node_count = max( 1, (int) $node_count );
		$default    = min( 5, max( 1, (int) floor( 25 / ( 2 * $node_count ) ) ) );

		$timeout = apply_filters( 'xmrpay_setup_node_timeout', $default, $node_count );
		if ( ! is_numeric( $timeout ) ) { $timeout = $default; }
		return max( 1, min( 10, (int) $timeout ) );
	}

	public static function node_setup_diagnostic( $error ) {
		$code = is_array( $error ) && isset( $error['code'] ) ? (string) $error['code'] : 'transport';
		$messages = array(
			'unauthorized'      => __( 'Node rejected the credentials. Check the username, password, and authentication type.', 'nodewatch-monero' ),
			'transport'         => __( 'Node could not be reached. Check the URL, port, TLS, and firewall.', 'nodewatch-monero' ),
			'digest_unavailable'=> __( 'Digest authentication is unavailable on this server. Enable PHP cURL or choose Basic/None.', 'nodewatch-monero' ),
			'http'              => __( 'Node returned an HTTP error. Check the endpoint and node service.', 'nodewatch-monero' ),
		);
		if ( ! isset( $messages[ $code ] ) ) { $code = 'transport'; }
		return array( 'code' => $code, 'msg' => $messages[ $code ] );
	}

	private function is_proof_mode() {
		return 'proof' === $this->get_option( 'mode', 'watch' );
	}

	private function is_watch_mode() {
		return 'watch' === $this->get_option( 'mode', 'watch' );
	}

	private function uses_view_key() {
		return $this->is_proof_mode() || $this->is_watch_mode();
	}

	private function view_key() {
		if ( defined( 'XMRPAY_VIEW_KEY' ) && '' !== trim( (string) XMRPAY_VIEW_KEY ) ) {
			return trim( (string) XMRPAY_VIEW_KEY );
		}
		return trim( (string) $this->get_option( 'view_key' ) );
	}

	private function scanner() {
		require_once __DIR__ . '/class-xmrpay-scanner.php';
		$nodes = $this->get_option( 'node_configs' );
		if ( ! is_array( $nodes ) || ! $nodes ) {
			$nodes = array_values( array_filter( array_map( 'trim', explode( ',', (string) $this->get_option( 'nodes' ) ) ) ) );
		}
		if ( ! $nodes ) { $nodes = array( 'http://node2.monerodevs.org:38089' ); }
		return new XmrPay_Scanner( $nodes, $this->detect_network(), 12 );
	}

	private function detect_network() {
		$a = trim( (string) $this->get_option( 'xmr_address' ) );
		$c = '' !== $a ? $a[0] : '4';
		if ( '5' === $c ) { return 'stagenet'; }
		if ( in_array( $c, array( '9', 'A', 'B' ), true ) ) { return 'testnet'; }
		return 'mainnet';
	}

	private function agent_url() {
		return XmrPay_Util::normalize_agent_url( $this->get_option( 'agent_url' ) );
	}

	private function agent() {
		return new XmrPay_Agent( $this->agent_url(), $this->get_option( 'agent_token' ) );
	}

	public function get_xmr_amount( $order ) {
		$test = trim( (string) $this->get_option( 'test_amount' ) );

		if ( $test !== '' && XmrPay_Util::test_amount_allowed(
			get_option( 'xmrpay_agent_network', '' ),
			get_option( 'xmrpay_agent_tested_url', '' ),
			$this->agent_url(),
			(string) $this->get_option( 'xmr_address', '' )
		) ) {
			return $this->fmt_xmr( (float) $test );
		}
		$currency = strtoupper( $order->get_currency() );
		$total    = (float) $order->get_total();
		if ( $currency === 'XMR' ) {
			return $this->fmt_xmr( $total );
		}

		$rate = $this->resolve_rate( $currency );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}
		if ( $rate <= 0 ) {
			return new WP_Error( 'xmrpay_rate', __( 'Could not get an XMR price. Check your pricing settings.', 'nodewatch-monero' ) );
		}
		return $this->fmt_xmr( $total / $rate );
	}

	private function resolve_rate( $currency ) {
		$source = $this->get_option( 'price_source', 'coingecko' );
		$fixed  = (float) $this->get_option( 'fixed_rate' );

		if ( 'fixed' === $source ) {
			return $fixed > 0 ? $fixed : new WP_Error( 'xmrpay_rate', __( 'Set a fixed XMR rate in the payment settings.', 'nodewatch-monero' ) );
		}
		$live = ( 'custom' === $source ) ? $this->custom_rate( $currency ) : $this->xmr_rate( $currency );
		if ( ! is_wp_error( $live ) && (float) $live > 0 ) {
			$live_val = (float) $live;

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

	private function fmt_xmr( $xmr ) {
		return XmrPay_Util::fmt( $xmr );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		if ( (string) $order->get_meta( '_xmrpay_address' ) !== '' ) {
			return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
		}

		$amount = $this->get_xmr_amount( $order );
		if ( is_wp_error( $amount ) ) {
			wc_add_notice( $amount->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

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

				$birthday = $this->scanner()->tip_height();
				if ( null === $birthday || (int) $birthday <= 0 ) {
					$this->log( 'watch checkout #' . $order_id . ' aborted — node unreachable, no tip height', 'error' );
					wc_add_notice( __( 'Could not reach the Monero network to set up your payment. Please try again in a moment.', 'nodewatch-monero' ), 'error' );
					return array( 'result' => 'failure' );
				}
				$birthday = (int) $birthday;
				$order->update_meta_data( '_xmrpay_address', $sub['address'] );
				$order->update_meta_data( '_xmrpay_amount', $amount );
				$order->update_meta_data( '_xmrpay_mode', 'watch' );
				$order->update_meta_data( '_xmrpay_minor', (int) $order_id );
				$order->update_meta_data( '_xmrpay_birthday', $birthday );
				$order->update_meta_data( '_xmrpay_scan_height', $birthday );
				$this->log( 'watch order #' . $order_id . ' → ' . $amount . ' XMR · ' . $sub['address'] . ' · from ' . $birthday );
			} else {
				$amount = XmrPay_Util::nonce_amount( $amount );
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
		$order->update_meta_data( '_xmrpay_mode', 'agent' );
		$order->save();

		$order->update_status( 'on-hold', __( 'Awaiting Monero payment.', 'nodewatch-monero' ) );
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

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

		$omode   = (string) $order->get_meta( '_xmrpay_mode' );
		$proof   = '' !== $omode ? ( 'proof' === $omode ) : $this->is_proof_mode();
		$noserver = in_array( $omode, array( 'proof', 'watch' ), true ) || ( '' === $omode && $this->uses_view_key() );
		$status_url = add_query_arg( array(
			'wc-ajax'   => 'xmrpay_status',
			'order_id'  => $order_id,
			'key'       => $order->get_order_key(),
		), home_url( '/' ) );

		$verify_url = add_query_arg( array(
			'wc-api'   => 'xmrpay_verify',
			'order_id' => $order_id,
			'key'      => $order->get_order_key(),
		), home_url( '/' ) );

		$redirect = trim( (string) $this->get_option( 'success_redirect' ) );
		if ( $redirect !== '' ) {
			$key_sub  = XmrPay_Util::same_origin( $redirect, home_url() ) ? rawurlencode( $order->get_order_key() ) : '';
			$redirect = str_replace(
				array( '{order_id}', '{order_key}' ),
				array( rawurlencode( (string) $order_id ), $key_sub ),
				$redirect
			);
		}

		if ( $paid && ! $noserver && (string) $order->get_meta( '_xmrpay_receipt' ) === '' ) {

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

		$overpaid     = $paid && $order->get_meta( '_xmrpay_overpaid' ) === 'yes';
		$overpaid_xmr = (string) $order->get_meta( '_xmrpay_overpaid_xmr' );

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
					<?php  ?>
				<?php endif; ?>
			<?php endif; ?>
			<?php echo wp_kses_post( $receipt_html );  ?>
			<?php endif; ?>
		</section>
		<?php
	}

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

	public function ajax_status() {

		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$rl_key = 'xmrpay_rl_s_' . get_current_blog_id() . '_' . substr( md5( $ip ), 0, 16 );
		if ( (int) get_transient( $rl_key ) > 30 ) {
			wp_send_json( array( 'error' => 'too many requests' ), 429 );
		}

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

		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			wp_send_json( array( 'paid' => false, 'status' => $order->get_status(), 'terminal' => true ) );
		}

		if ( $order->get_meta( '_xmrpay_mode' ) === 'watch' ) {
			$this->scan_order( $order );
			$order = wc_get_order( $order_id );
			if ( $order->is_paid() ) {
				wp_send_json( array( 'paid' => true, 'status' => 'paid' ) );
			}
			$status = $order->get_meta( '_xmrpay_watch_status' );
			if ( ! is_array( $status ) ) { $status = array( 'status' => 'pending' ); }
			wp_send_json( array( 'paid' => false ) + $status + array( 'reachable' => false ) );
		}
		if ( 'proof' === (string) $order->get_meta( '_xmrpay_mode' ) ) {
			wp_send_json( array( 'paid' => false, 'status' => 'pending' ) );
		}

		$r = $this->agent()->get_order( (string) $order_id, 6 );
		if ( is_wp_error( $r ) ) {

			wp_send_json( array( 'paid' => false, 'status' => 'pending', 'reachable' => false ) );
		}

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

			'syncing'          => ! empty( $r['syncing'] ),
			'reachable'        => true,
		) );
	}

	public function handle_verify() {
		nocache_headers();
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$txid     = isset( $_POST['txid'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['txid'] ) ) ) : '';
		$nonce    = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

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

		$rl = 'xmrpay_vrl_' . get_current_blog_id() . '_' . (int) $order_id;
		if ( false !== get_transient( $rl ) ) {
			wp_send_json( array( 'paid' => false, 'message' => __( 'Please wait a few seconds and try again.', 'nodewatch-monero' ) ) );
		}
		set_transient( $rl, 1, 5 );

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

			$msg = ( isset( $res['commitment_present'] ) && ! $res['commitment_present'] )
				? __( 'We could not fully verify this payment — the store’s Monero node may be pruned. Please contact the store.', 'nodewatch-monero' )
				: __( 'That transaction did not verify. Contact the store.', 'nodewatch-monero' );
			wp_send_json( array( 'paid' => false, 'status' => 'invalid', 'message' => $msg ) );
		}

		if ( ! empty( $res['in_pool'] ) && ! empty( $res['double_spend_seen'] ) ) {
			wp_send_json( array( 'paid' => false, 'status' => 'pending', 'message' => __( 'Payment seen but not yet confirmable — waiting for a block. This page will update shortly.', 'nodewatch-monero' ) ) );
		}

		$min_conf  = (int) $this->get_option( 'proof_min_conf', '1' );
		$tol_pico  = XmrPay_Util::xmr_to_pico( $this->get_option( 'proof_tolerance_xmr', '0' ) );
		$exp_pico  = XmrPay_Util::xmr_to_pico( $amount );

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

		$order->update_meta_data( '_xmrpay_proof_txid', $txid );
		$order->save();
		$this->mark_paid( $order, array(
			'paid'          => true,
			'received_xmr'  => XmrPay_Util::pico_to_string( $res['amount_atomic'] ),
			'txids'         => array( $txid ),
			'confirmations' => isset( $res['confirmations'] ) ? (int) $res['confirmations'] : 0,
			'overpaid'      => '0' !== $verdict['overpaid_pico'],
			'overpaid_xmr'  => XmrPay_Util::pico_to_string( $verdict['overpaid_pico'] ),
		), true );
		wp_send_json( array( 'paid' => true, 'status' => 'paid' ) );
	}

	private function txid_used_elsewhere( $txid, $order_id ) {
		$ids = wc_get_orders( array(
			'limit'      => 2,
			'return'     => 'ids',
			'meta_key'   => '_xmrpay_proof_txid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- limit:2, indexed key, no WC alternative
			'meta_value' => $txid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		) );
		foreach ( (array) $ids as $id ) {
			if ( (int) $id !== (int) $order_id ) {
				return true;
			}
		}
		return false;
	}

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

	private function mark_paid( $order, $data, $chain_verified = false ) {

		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}
		if ( $order->is_paid() ) {
			return;
		}
		if ( ! $chain_verified && ! in_array( (string) $order->get_meta( '_xmrpay_mode' ), array( '', 'agent' ), true ) ) {
			return;
		}

		$lock_key = 'pay_' . $order->get_id();
		if ( ! $this->acquire_lock( $lock_key, 30 ) ) {
			return;
		}

		$order = wc_get_order( $order->get_id() );
		if ( ! $order || $order->is_paid() ) {
			$this->release_lock( $lock_key );
			return;
		}

		$mode = (string) $order->get_meta( '_xmrpay_mode' );
		if ( ! $chain_verified && ! in_array( $mode, array( '', 'agent' ), true ) ) {
			$this->release_lock( $lock_key );
			return;
		}

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

		$received_raw = isset( $data['received_xmr'] ) ? $data['received_xmr'] : ( $data['receivedXmr'] ?? null );
		$overpaid_raw = isset( $data['overpaid_xmr'] ) ? $data['overpaid_xmr'] : ( $data['overpaidXmr'] ?? null );
		$txid_list = isset( $data['txids'] ) && is_array( $data['txids'] ) ? array_values( array_map( 'sanitize_text_field', $data['txids'] ) ) : array();
		$txids     = implode( ', ', $txid_list );
		$first_txid = $txid_list ? $txid_list[0] : '';
		$received = $received_raw !== null ? sanitize_text_field( (string) $received_raw ) : '';
		$confs    = isset( $data['confirmations'] ) ? absint( $data['confirmations'] ) : null;
		$owed     = (string) $order->get_meta( '_xmrpay_amount' );
		$overpaid     = ! empty( $data['overpaid'] );
		$overpaid_xmr = $overpaid_raw !== null ? sanitize_text_field( (string) $overpaid_raw ) : '0';

		if ( $received !== '' ) { $order->update_meta_data( '_xmrpay_received', $received ); }
		if ( $confs !== null ) { $order->update_meta_data( '_xmrpay_confirmations', $confs ); }
		if ( $txids !== '' ) { $order->update_meta_data( '_xmrpay_txids', $txids ); }

		if ( $overpaid ) {
			$order->update_meta_data( '_xmrpay_overpaid', 'yes' );
			$order->update_meta_data( '_xmrpay_overpaid_xmr', $overpaid_xmr );
		}

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

		try {
			$order->payment_complete( $first_txid );
		} finally {
			$this->release_lock( $lock_key );
		}
	}

	private function refund_claim_url( $order ) {
		return add_query_arg( array(
			'wc-api'   => 'xmrpay_refund',
			'order_id' => $order->get_id(),
			'key'      => $order->get_order_key(),
		), home_url( '/' ) );
	}

	private function open_refund_claim( $order, $amount ) {
		$prev = (float) $order->get_meta( '_xmrpay_refund_amount' );
		$order->update_meta_data( '_xmrpay_refund_amount', (string) ( $prev + (float) $amount ) );
		$order->update_meta_data( '_xmrpay_refund_status', 'requested' );

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

	private function fmt_dt( $ts ) {
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $ts );
	}

	private function acquire_lock( $key, $ttl = 30 ) {
		$opt = 'xmrpay_lock_' . get_current_blog_id() . '_' . $key;
		if ( add_option( $opt, time() + (int) $ttl, '', 'no' ) ) {
			return true;
		}
		$exp = (int) get_option( $opt );
		if ( $exp > 0 && time() > $exp ) {
			delete_option( $opt );
			return (bool) add_option( $opt, time() + (int) $ttl, '', 'no' );
		}
		return false;
	}

	private function release_lock( $key ) {
		delete_option( 'xmrpay_lock_' . get_current_blog_id() . '_' . $key );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return new WP_Error( 'xmrpay_refund', __( 'This is not a Monero (xmr-pay) order.', 'nodewatch-monero' ) );
		}
		$status = (string) $order->get_meta( '_xmrpay_refund_status' );
		if ( '' === $status ) {
			$this->open_refund_claim( $order, $amount );
		} elseif ( 'requested' === $status ) {

			$prev = (float) $order->get_meta( '_xmrpay_refund_amount' );
			$order->update_meta_data( '_xmrpay_refund_amount', (string) ( $prev + (float) $amount ) );
			$order->save();
		} else {

			if ( 'sent' === $status ) { $order->delete_meta_data( '_xmrpay_refund_amount' ); }
			$order->delete_meta_data( '_xmrpay_refund_address' );
			$order->delete_meta_data( '_xmrpay_refund_txid' );
			$order->save();
			$this->open_refund_claim( $order, $amount );
		}
		return true;
	}

	private function address_checksum_ok( $addr ) {
		if ( ! XmrPay_Util::crypto_ready() ) {
			return false;
		}
		try {
			return $this->scanner()->address_valid( $addr );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

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

		$opened = (int) $order->get_meta( '_xmrpay_refund_opened' );
		$window = (int) $order->get_meta( '_xmrpay_refund_window' );
		if ( XmrPay_Util::claim_expired( $status, $opened, $window, time() ) ) {
			$this->refund_page( __( 'Refund link expired', 'nodewatch-monero' ),
				'<p>' . esc_html__( 'This refund link has expired. Please contact the store and they will send you a fresh one — your refund is safe.', 'nodewatch-monero' ) . '</p>', 410 );
		}

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

		if ( 'sent' === $status ) {
			$this->refund_page( __( 'Refund sent', 'nodewatch-monero' ),
				'<p class="ok">' . esc_html__( 'This refund has already been paid. If you have not received it, contact the store.', 'nodewatch-monero' ) . '</p>' );
		}
		if ( 'address_provided' === $status ) {
			$this->refund_page( __( 'Address on file', 'nodewatch-monero' ),
				'<p>' . esc_html__( 'We already have your refund address and will send your refund shortly. To change the address, contact the store.', 'nodewatch-monero' ) . '</p>' );
		}

		$this->refund_form_page( $order, $num, '' );
	}

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

		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer">'
			. '<title>' . esc_html( $title ) . '</title><style>' . $css . '</style></head><body><div class="card"><h1>' . esc_html( $title ) . '</h1>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $css is hardcoded string literals, no user input
			. $body_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from esc_*/wp_nonce_field
			. '<p class="brand">' . esc_html( get_bloginfo( 'name' ) ) . '</p></div></body></html>';
		exit;
	}

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
			$txid = '';
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

	public function on_refunded( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}
		$refund = wc_get_order( $refund_id );
		if ( ! $refund instanceof WC_Order_Refund || (int) $refund->get_parent_id() !== (int) $order_id
			|| $refund->get_refunded_payment() || $refund->get_meta( '_xmrpay_claim_recorded' ) ) {
			return;
		}
		if ( true === $this->process_refund( $order_id, $refund->get_amount() ) ) {
			$refund->update_meta_data( '_xmrpay_claim_recorded', 'yes' );
			$refund->save();
		}
	}

	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $sent_to_admin || ! $order || $order->get_payment_method() !== $this->id || $order->is_paid() ) {
			return;
		}
		$addr   = (string) $order->get_meta( '_xmrpay_address' );
		$amount = (string) $order->get_meta( '_xmrpay_amount' );
		if ( $addr === '' ) {
			return;
		}

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
