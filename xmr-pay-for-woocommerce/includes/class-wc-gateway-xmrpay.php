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
		$this->icon               = '';
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
				'description' => __( 'TEST ONLY — if set, every order charges this exact XMR amount (ignores the cart total / price feed). Use on stagenet. Leave empty in production.', 'xmr-pay-for-woocommerce' ),
			),
			'debug_log' => array(
				'title'       => __( 'Debug log', 'xmr-pay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log agent calls and webhooks to WooCommerce → Status → Logs (source: xmrpay)', 'xmr-pay-for-woocommerce' ),
				'default'     => 'no',
			),
		);
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
		$view = ! empty( $body['viewOnly'] ) ? 'view-only' : 'NOT view-only (!)';
		wp_send_json_success( array( 'msg' => sprintf( 'connected · %s · %s', sanitize_text_field( $body['network'] ?? '?' ), $view ) ) );
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
		if ( $test !== '' ) {
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

	/** XMR/fiat rate from CoinGecko (the merchant's own key if set), cached 60s. */
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
		set_transient( $key, $rate, 60 );
		return $rate;
	}

	/** Canonical XMR decimal string, ≤12 decimals, trailing zeros trimmed. */
	private function fmt_xmr( $xmr ) {
		return XmrPay_Util::fmt( $xmr );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$amount = $this->get_xmr_amount( $order );
		if ( is_wp_error( $amount ) ) {
			wc_add_notice( $amount->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		// free order (100%-off coupon, fully-discounted or zero-priced cart): the
		// total already includes discounts/shipping/tax, so a 0 here means nothing
		// to collect on-chain. complete it now, skip the Monero flow.
		if ( (float) $amount <= 0 ) {
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

		wp_enqueue_script( 'xmrpay-widget' );
		wp_enqueue_script( 'xmrpay-checkout' );
		?>
		<section class="xmrpay-panel" style="margin:24px 0;max-width:420px">
			<h2><?php esc_html_e( 'Pay with Monero', 'xmr-pay-for-woocommerce' ); ?></h2>
			<div id="xmrpay-status" data-poll="<?php echo esc_url( $status_url ); ?>" data-paid="<?php echo $paid ? '1' : '0'; ?>"
				 style="font-weight:600;margin:8px 0;<?php echo $paid ? 'color:#15803d' : 'color:#b45309'; ?>">
				<?php echo $paid ? esc_html__( '✓ Payment received', 'xmr-pay-for-woocommerce' ) : esc_html__( '● Awaiting payment…', 'xmr-pay-for-woocommerce' ); ?>
			</div>
			<?php if ( ! $paid ) : ?>
				<xmr-pay address="<?php echo esc_attr( $addr ); ?>" amount="<?php echo esc_attr( $amount ); ?>"
						 label="<?php echo esc_attr( get_bloginfo( 'name' ) . ' #' . $order_id ); ?>" theme="light"
						 lang="<?php echo esc_attr( substr( get_locale(), 0, 2 ) === 'es' ? 'es' : 'en' ); ?>"></xmr-pay>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Server-side proxy: the buyer's browser polls this; we query the private agent. */
	public function ajax_status() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_send_json( array( 'error' => 'not found' ), 404 );
		}
		if ( $order->is_paid() ) {
			wp_send_json( array( 'paid' => true, 'status' => 'paid' ) );
		}
		$r = $this->agent()->get_order( (string) $order_id );
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
		if ( $order->is_paid() ) {
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
}
