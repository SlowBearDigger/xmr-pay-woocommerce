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
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 10, 2 );
		// admin: a "test connection" button on the settings page + a payment-detail
		// block on the order screen (HPOS-safe hook).
		add_action( 'wp_ajax_xmrpay_test_agent', array( $this, 'ajax_test_agent' ) );
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
			'agent_section' => array(
				'title' => __( 'Your xmr-pay agent', 'xmr-pay-for-woocommerce' ),
				'type'  => 'title',
				'description' => __( 'The agent you run (see docs/AGENT.md). It holds your view key and does the scanning — this plugin only talks HTTP to it.', 'xmr-pay-for-woocommerce' ),
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
					'fixed'     => __( 'Fixed rate (set it yourself)', 'xmr-pay-for-woocommerce' ),
				),
				'description' => __( 'Ignored when the store currency is already XMR.', 'xmr-pay-for-woocommerce' ),
			),
			'coingecko_api_key' => array(
				'title'       => __( 'CoinGecko API key', 'xmr-pay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Optional — your own CoinGecko Demo/Pro key, so the price feed is yours and not rate-limited. Leave empty to use the free public endpoint.', 'xmr-pay-for-woocommerce' ),
			),
			'fixed_rate' => array(
				'title'       => __( 'Fixed rate', 'xmr-pay-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'e.g. 150',
				'description' => __( 'Used only with “Fixed rate”: the price of 1 XMR in your store currency.', 'xmr-pay-for-woocommerce' ),
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
			if ( ! $order || $order->is_paid() ) {
				continue;
			}
			$order->update_status( 'cancelled', __( 'Auto-cancelled: no Monero payment within the expiry window.', 'xmr-pay-for-woocommerce' ) );
			$this->log( 'expired unpaid order #' . $oid );
		}
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
				<script>
				(function(){
					var btn=document.getElementById('xmrpay-test-agent'), out=document.getElementById('xmrpay-test-result');
					if(!btn) return;
					btn.addEventListener('click',function(){
						out.style.color='#666'; out.textContent='<?php echo esc_js( __( 'testing…', 'xmr-pay-for-woocommerce' ) ); ?>';
						var url=(document.getElementById('woocommerce_xmrpay_agent_url')||{}).value||'';
						var token=(document.getElementById('woocommerce_xmrpay_agent_token')||{}).value||'';
						var body=new URLSearchParams({action:'xmrpay_test_agent',_wpnonce:'<?php echo esc_js( wp_create_nonce( 'xmrpay_test_agent' ) ); ?>',url:url,token:token});
						fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
							.then(function(r){return r.json();})
							.then(function(d){ if(d&&d.success){ out.style.color='#15803d'; out.textContent='✓ '+(d.data&&d.data.msg||'OK'); } else { out.style.color='#b91c1c'; out.textContent='✗ '+((d&&d.data&&d.data.msg)||'unreachable'); } })
							.catch(function(){ out.style.color='#b91c1c'; out.textContent='✗ request failed'; });
					});
				})();
				</script>
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
		echo '</div>';
	}

	/** Is the gateway usable (configured)? */
	public function is_available() {
		return ( 'yes' === $this->enabled ) && '' !== trim( (string) $this->get_option( 'agent_url' ) );
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
		// rate = price of 1 XMR in the store currency
		if ( 'fixed' === $this->get_option( 'price_source', 'coingecko' ) ) {
			$rate = (float) $this->get_option( 'fixed_rate' );
			if ( $rate <= 0 ) {
				return new WP_Error( 'xmrpay_rate', __( 'Set a fixed XMR rate in the payment settings (or use CoinGecko).', 'xmr-pay-for-woocommerce' ) );
			}
		} else {
			$rate = $this->xmr_rate( $currency );
			if ( is_wp_error( $rate ) ) {
				return $rate;
			}
			if ( $rate <= 0 ) {
				return new WP_Error( 'xmrpay_rate', __( 'Could not get an XMR price.', 'xmr-pay-for-woocommerce' ) );
			}
		}
		return $this->fmt_xmr( $total / $rate );
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
		$status_url = add_query_arg( array(
			'wc-ajax'   => 'xmrpay_status',
			'order_id'  => $order_id,
			'key'       => $order->get_order_key(),
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
		if ( $paid && (string) $order->get_meta( '_xmrpay_receipt' ) === '' ) {
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

		wp_enqueue_script( 'xmrpay-widget' );
		wp_enqueue_script( 'xmrpay-checkout' );
		?>
		<section class="xmrpay-panel" style="margin:24px 0;max-width:420px">
			<h2><?php esc_html_e( 'Pay with Monero', 'xmr-pay-for-woocommerce' ); ?></h2>
			<div id="xmrpay-status" data-poll="<?php echo esc_url( $status_url ); ?>" data-paid="<?php echo $paid ? '1' : '0'; ?>"<?php echo $redirect !== '' ? ' data-redirect="' . esc_url( $redirect ) . '"' : ''; ?>
				 style="font-weight:600;margin:8px 0;<?php echo $paid ? 'color:#15803d' : 'color:#b45309'; ?>">
				<?php echo $paid ? esc_html__( '✓ Payment received', 'xmr-pay-for-woocommerce' ) : esc_html__( '● Awaiting payment…', 'xmr-pay-for-woocommerce' ); ?>
			</div>
			<?php if ( ! $paid ) : ?>
				<xmr-pay address="<?php echo esc_attr( $addr ); ?>" amount="<?php echo esc_attr( $amount ); ?>"
						 label="<?php echo esc_attr( get_bloginfo( 'name' ) . ' #' . $order_id ); ?>"
						 theme="<?php echo esc_attr( $this->get_option( 'checkout_theme', 'light' ) ); ?>"
						 lang="<?php echo esc_attr( substr( get_locale(), 0, 2 ) === 'es' ? 'es' : 'en' ); ?>"></xmr-pay>
			<?php endif; ?>
			<?php echo $receipt_html; // built with esc_* in receipt_block_html() ?>
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
				<?php if ( $fp !== '' ) { echo ' ' . esc_html( sprintf( __( 'Signer: %s', 'xmr-pay-for-woocommerce' ), $fp ) ); } ?>
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
			'reachable'        => true,
		) );
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
		$txids    = isset( $data['txids'] ) && is_array( $data['txids'] ) ? implode( ', ', array_map( 'sanitize_text_field', $data['txids'] ) ) : '';
		$received = isset( $data['received_xmr'] ) ? sanitize_text_field( (string) $data['received_xmr'] ) : '';
		$confs    = isset( $data['confirmations'] ) ? absint( $data['confirmations'] ) : null;
		$owed     = (string) $order->get_meta( '_xmrpay_amount' );

		// stash the payment detail on the order so the merchant has everything:
		// what was owed, what landed, the confirmations, and the tx hash(es).
		if ( $received !== '' ) { $order->update_meta_data( '_xmrpay_received', $received ); }
		if ( $confs !== null ) { $order->update_meta_data( '_xmrpay_confirmations', $confs ); }
		if ( $txids !== '' ) { $order->update_meta_data( '_xmrpay_txids', $txids ); }
		// the signed receipt (if the agent minted one) — stored verbatim so the
		// buyer can download + verify it even if the agent later goes offline.
		if ( isset( $data['receipt'] ) && is_array( $data['receipt'] ) ) {
			$order->update_meta_data( '_xmrpay_receipt', wp_json_encode( $data['receipt'] ) );
		}
		$order->save();

		$note = __( 'Monero payment confirmed by the xmr-pay agent.', 'xmr-pay-for-woocommerce' );
		if ( $received !== '' ) { $note .= ' ' . sprintf( __( 'Received: %1$s XMR (owed %2$s).', 'xmr-pay-for-woocommerce' ), $received, $owed ); }
		if ( $confs !== null ) { $note .= ' ' . sprintf( __( 'Confirmations: %d.', 'xmr-pay-for-woocommerce' ), $confs ); }
		if ( $txids !== '' ) { $note .= ' ' . sprintf( __( 'tx: %s', 'xmr-pay-for-woocommerce' ), $txids ); }
		$order->add_order_note( $note );
		$this->log( 'marked paid #' . $order->get_id() . ' · received ' . $received . ' · tx ' . $txids );

		// payment_complete reduces stock, sets processing/completed, fires emails
		$order->payment_complete( $txids );
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
			echo "\n" . sprintf( __( 'Pay %1$s XMR to: %2$s', 'xmr-pay-for-woocommerce' ), $amount, $addr ) . "\n";
			echo sprintf( __( 'Payment page (QR + live status): %s', 'xmr-pay-for-woocommerce' ), esc_url( $pay_url ) ) . "\n\n";
			return;
		}
		echo '<div style="margin:0 0 24px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:8px">';
		echo '<p style="margin:0 0 8px;font-weight:600">' . esc_html__( 'Complete your Monero payment', 'xmr-pay-for-woocommerce' ) . '</p>';
		echo '<p style="margin:0 0 6px">' . sprintf( esc_html__( 'Send %s XMR to:', 'xmr-pay-for-woocommerce' ), '<strong>' . esc_html( $amount ) . '</strong>' ) . '</p>';
		echo '<p style="margin:0 0 10px;word-break:break-all"><code style="font-size:12px">' . esc_html( $addr ) . '</code></p>';
		echo '<p style="margin:0"><a href="' . esc_url( $pay_url ) . '" style="color:#ff6600;font-weight:600">' . esc_html__( 'Open the payment page (QR + live status) →', 'xmr-pay-for-woocommerce' ) . '</a></p>';
		echo '</div>';
	}
}
