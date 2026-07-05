<?php
/**
 * Guided setup wizard — mode-aware onboarding, leading with the no-server default.
 *
 * Four steps: (0) pick how to verify — Auto-detect in WordPress (recommended,
 * no server), "Buyer taps I've paid", or the advanced Agent mode; (1) Connect —
 * for the no-server modes, your address / view key / node with a LIVE "Test setup"
 * check (node reachable, network, and that the view key matches the address); for
 * Agent mode, the Agent URL / token / webhook with a live connection test;
 * (2) pricing; (3) go live.
 *
 * It writes straight into the gateway's own settings option (incl. `mode`), so
 * everything stays editable afterwards in WooCommerce → Settings → Payments → Monero.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class XmrPay_Setup {

	const PAGE       = 'xmrpay-setup';
	const OPTION     = 'woocommerce_xmrpay_settings';   // WC stores gateway settings here
	const REDIRECT_T = 'xmrpay_activation_redirect';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_on_activate' ) );
		add_action( 'admin_notices', array( $this, 'setup_notice' ) );
		add_action( 'wp_ajax_xmrpay_setup_save', array( $this, 'ajax_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		// a "Run setup wizard" shortcut from the plugins list row.
		add_filter( 'plugin_action_links_' . plugin_basename( XMRPAY_WC_FILE ), array( $this, 'plugin_links' ) );
	}

	/** Mark a fresh activation so we can offer the wizard once (set from the main file's activation hook). */
	public static function flag_activation() {
		set_transient( self::REDIRECT_T, 1, 60 );
	}

	public function register_page() {
		// real page under the WooCommerce menu so it has a home + capability check,
		// but hidden from the submenu (reached via the notice / plugins-list link /
		// the button on the gateway settings) to avoid permanent menu clutter.
		add_submenu_page(
			'woocommerce',
			__( 'Monero payments — Setup', 'nodewatch-monero' ),
			__( 'Monero setup', 'nodewatch-monero' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render' )
		);
		// Hide it from the submenu for display only — defer the removal to admin_head,
		// which runs AFTER user_can_access_admin_page() but BEFORE the menu is drawn.
		// Removing it here (on admin_menu) would make direct-URL access — the post-
		// activation redirect, the notice button, the plugins-list link — fail with
		// "Sorry, you are not allowed to access this page."
		add_action( 'admin_head', array( $this, 'hide_from_menu' ) );
	}

	/** Remove the wizard from the visible submenu without breaking direct access. */
	public function hide_from_menu() {
		remove_submenu_page( 'woocommerce', self::PAGE );
	}

	public function plugin_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Setup wizard', 'nodewatch-monero' ) . '</a>' );
		return $links;
	}

	/** One-time redirect into the wizard right after activation (single, non-bulk). */
	public function maybe_redirect_on_activate() {
		if ( ! get_transient( self::REDIRECT_T ) ) {
			return;
		}
		delete_transient( self::REDIRECT_T );
		// don't hijack bulk activations or non-interactive contexts.
		if ( isset( $_GET['activate-multi'] ) || wp_doing_ajax() || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence-only flag check, capability-gated
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/** Nudge toward the wizard until the gateway has been configured + enabled. */
	public function setup_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		if ( $screen->id === 'woocommerce_page_' . self::PAGE ) {
			return; // already on the wizard
		}
		// keep the nudge limited (Guideline 11): only on the dashboard, the plugins
		// list, and WooCommerce screens — not scattered across all of wp-admin.
		$allowed = in_array( $screen->id, array( 'dashboard', 'plugins' ), true )
			|| 0 === strpos( (string) $screen->id, 'woocommerce_page_' )
			|| 'shop_order' === $screen->post_type
			|| 'woocommerce_page_wc-orders' === $screen->id;
		if ( ! $allowed ) {
			return;
		}
		$cfg  = get_option( self::OPTION, array() );
		$cfg  = is_array( $cfg ) ? $cfg : array();
		$on   = 'yes' === ( isset( $cfg['enabled'] ) ? $cfg['enabled'] : 'no' );
		$mode = isset( $cfg['mode'] ) ? $cfg['mode'] : 'watch';
		$has_view = ( defined( 'XMRPAY_VIEW_KEY' ) && '' !== trim( (string) XMRPAY_VIEW_KEY ) ) || ! empty( $cfg['view_key'] );
		$configured = $on && ( 'agent' === $mode ? ! empty( $cfg['agent_url'] ) : ( ! empty( $cfg['xmr_address'] ) && $has_view ) );
		if ( $configured ) {
			return;
		}
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		echo '<div class="notice notice-info is-dismissible"><p><strong>' . esc_html__( 'Monero payments', 'nodewatch-monero' ) . '</strong> — ' .
			esc_html__( 'finish setup to start accepting XMR.', 'nodewatch-monero' ) .
			' <a href="' . esc_url( $url ) . '" class="button button-primary" style="margin-left:6px">' . esc_html__( 'Run the setup wizard', 'nodewatch-monero' ) . '</a></p></div>';
	}

	/** AJAX: persist the wizard's choices into the gateway settings option, enable the gateway. */
	public function ajax_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'xmrpay_setup_save', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'msg' => __( 'not allowed', 'nodewatch-monero' ) ) );
		}
		$in  = wp_unslash( $_POST );
		$cfg = get_option( self::OPTION, array() );
		if ( ! is_array( $cfg ) ) { $cfg = array(); }

		$text = static function ( $k ) use ( $in ) { return isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : ''; };

		$cfg['enabled']        = 'yes';
		$cfg['title']          = $text( 'title' ) !== '' ? $text( 'title' ) : __( 'Monero (XMR)', 'nodewatch-monero' );
		$cfg['checkout_theme'] = in_array( $text( 'checkout_theme' ), array( 'light', 'dark' ), true ) ? $text( 'checkout_theme' ) : 'light';

		// the mode the merchant chose (default = the no-server auto-detect).
		$mode          = in_array( $text( 'mode' ), array( 'watch', 'proof', 'agent' ), true ) ? $text( 'mode' ) : 'watch';
		$cfg['mode']   = $mode;
		if ( 'agent' === $mode ) {
			$cfg['agent_url']      = esc_url_raw( isset( $in['agent_url'] ) ? trim( $in['agent_url'] ) : '' );
			$cfg['agent_token']    = $text( 'agent_token' );
			$cfg['webhook_secret'] = $text( 'webhook_secret' );
		} else {
			// no-server modes: address + view key + node(s) live in WordPress.
			$cfg['xmr_address'] = $text( 'xmr_address' );
			// only overwrite the stored view key if one was entered (a wp-config
			// constant or a previously-saved value should not be blanked).
			$vk = $text( 'view_key' );
			if ( '' !== $vk ) { $cfg['view_key'] = $vk; }
			// sanitize each comma-separated node URL with esc_url_raw (preserves port + path, strips XSS chars)
			$raw_nodes = $text( 'nodes' );
			$cfg['nodes'] = $raw_nodes !== ''
				? implode( ', ', array_filter( array_map( 'esc_url_raw', array_map( 'trim', explode( ',', $raw_nodes ) ) ) ) )
				: 'http://node2.monerodevs.org:38089';
			$cfg['proof_min_conf'] = is_numeric( $text( 'proof_min_conf' ) ) ? (string) max( 0, (int) $text( 'proof_min_conf' ) ) : '1';
		}

		$src = in_array( $text( 'price_source' ), array( 'coingecko', 'fixed' ), true ) ? $text( 'price_source' ) : 'coingecko';
		$cfg['price_source'] = $src;
		if ( $src === 'fixed' ) {
			$cfg['fixed_rate'] = $text( 'fixed_rate' );
		} else {
			$cfg['coingecko_api_key'] = $text( 'coingecko_api_key' );
		}

		// keep the merchant's existing description rather than blanking it; only set
		// a default the first time through.
		if ( empty( $cfg['description'] ) ) {
			$cfg['description'] = __( 'Pay privately with Monero. Scan the QR — your wallet fills in the exact amount.', 'nodewatch-monero' );
		}

		update_option( self::OPTION, $cfg );

		wp_send_json_success( array(
			'settings_url' => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=xmrpay' ),
			'shop_url'     => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ),
		) );
	}

	/** Enqueue the wizard JS + localised data on the setup page only. */
	public function enqueue( $hook ) {
		if ( 'woocommerce_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'xmrpay-wizard', plugins_url( 'assets/wizard.css', XMRPAY_WC_FILE ), array(), XMRPAY_WC_VERSION );
		wp_enqueue_script( 'xmrpay-wizard', plugins_url( 'assets/wizard.js', XMRPAY_WC_FILE ), array(), XMRPAY_WC_VERSION, true );
		wp_localize_script( 'xmrpay-wizard', 'xmrpayWizard', array(
			'ajaxurl'   => admin_url( 'admin-ajax.php' ),
			'testNonce' => wp_create_nonce( 'xmrpay_test_agent' ),
			'nodeNonce' => wp_create_nonce( 'xmrpay_test_node' ),
			'saveNonce' => wp_create_nonce( 'xmrpay_setup_save' ),
			'hasConst'  => defined( 'XMRPAY_VIEW_KEY' ) && '' !== trim( (string) XMRPAY_VIEW_KEY ),
			'i18n'      => array(
				'finish'        => __( 'Finish ✓', 'nodewatch-monero' ),
				'next'          => __( 'Next →', 'nodewatch-monero' ),
				'testing'       => __( 'testing…', 'nodewatch-monero' ),
				'enterUrl'      => __( 'enter the Agent URL first', 'nodewatch-monero' ),
				'reqfail'       => __( 'request failed', 'nodewatch-monero' ),
				'copied'        => __( 'Copied', 'nodewatch-monero' ),
				'saving'        => __( 'Saving…', 'nodewatch-monero' ),
				'couldNotSave'  => __( 'Could not save. Try again.', 'nodewatch-monero' ),
				'requestFailed' => __( 'Request failed.', 'nodewatch-monero' ),
			),
		) );
	}

	private function webhook_url() {
		return function_exists( 'WC' ) && WC() ? WC()->api_request_url( 'xmrpay_webhook' ) : home_url( '/?wc-api=xmrpay_webhook' );
	}

	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nodewatch-monero' ) );
		}
		$cfg          = get_option( self::OPTION, array() );
		$cfg          = is_array( $cfg ) ? $cfg : array();
		$g            = static function ( $k, $d = '' ) use ( $cfg ) { return isset( $cfg[ $k ] ) && $cfg[ $k ] !== '' ? $cfg[ $k ] : $d; };
		$webhook_url  = $this->webhook_url();
		$has_const    = defined( 'XMRPAY_VIEW_KEY' ) && '' !== trim( (string) XMRPAY_VIEW_KEY );
		$cur_mode     = in_array( $g( 'mode', 'watch' ), array( 'watch', 'proof', 'agent' ), true ) ? $g( 'mode', 'watch' ) : 'watch';
		$is_xmr_store = function_exists( 'get_woocommerce_currency' ) && get_woocommerce_currency() === 'XMR';
		$store_cur    = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		// nonces + the wizard's JS are enqueued/localised in enqueue() (assets/wizard.js).
		$cur_url      = admin_url( 'admin.php?page=wc-settings&tab=general' );
		$full_url     = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=xmrpay' );
		?>
		<div class="wrap xp-wrap">
			<div class="xp-card">
				<div class="xp-head">
					<div class="xp-logo">ɱ</div>
					<div>
						<h1><?php esc_html_e( 'Accept Monero — setup', 'nodewatch-monero' ); ?></h1>
						<p class="xp-sub"><?php esc_html_e( 'Non-custodial. Funds go straight to your wallet — no third party, ever.', 'nodewatch-monero' ); ?></p>
					</div>
				</div>

				<div class="xp-steps" id="xp-steps">
					<div class="s active" data-dot="0"><?php esc_html_e( 'Start', 'nodewatch-monero' ); ?></div>
					<div class="s" data-dot="1"><?php esc_html_e( 'Connect', 'nodewatch-monero' ); ?></div>
					<div class="s" data-dot="2"><?php esc_html_e( 'Pricing', 'nodewatch-monero' ); ?></div>
					<div class="s" data-dot="3"><?php esc_html_e( 'Go live', 'nodewatch-monero' ); ?></div>
				</div>

				<div class="xp-body">

					<!-- 0 — start + mode -->
					<section class="xp-step show" data-step="0">
						<h2><?php esc_html_e( 'Non-custodial Monero — pick how to verify', 'nodewatch-monero' ); ?></h2>
						<p class="lead"><?php esc_html_e( 'Funds go straight to your own wallet; no third party ever touches them. Choose how payments are confirmed — the two no-server options need nothing running 24/7.', 'nodewatch-monero' ); ?></p>
						<label class="xp-radio sel" data-mode="watch">
							<input type="radio" name="xp-mode" value="watch"<?php checked( $cur_mode, 'watch' ); ?>>
							<b><?php esc_html_e( 'Auto-detect in WordPress (recommended)', 'nodewatch-monero' ); ?></b>
							<span><?php esc_html_e( 'No server, no buyer action. WordPress scans the chain itself (with your view key, via a public node) and completes the order. Needs the PHP GMP and BCMath extensions.', 'nodewatch-monero' ); ?></span>
						</label>
						<label class="xp-radio" data-mode="proof">
							<input type="radio" name="xp-mode" value="proof"<?php checked( $cur_mode, 'proof' ); ?>>
							<b><?php esc_html_e( 'Buyer taps “I’ve paid”', 'nodewatch-monero' ); ?></b>
							<span><?php esc_html_e( 'The lightest. The buyer pastes their transaction ID and WordPress verifies it — no scanning, no agent.', 'nodewatch-monero' ); ?></span>
						</label>
						<label class="xp-radio" data-mode="agent">
							<input type="radio" name="xp-mode" value="agent"<?php checked( $cur_mode, 'agent' ); ?>>
							<b><?php esc_html_e( 'Run the xmr-pay agent (advanced)', 'nodewatch-monero' ); ?></b>
							<span><?php esc_html_e( 'Auto-detect via the separate xmr-pay daemon you run (npx xmr-pay); the agent holds the view key.', 'nodewatch-monero' ); ?></span>
						</label>
					</section>

					<!-- 1 — connect (panel swaps with the chosen mode) -->
					<section class="xp-step" data-step="1">
						<!-- no-server panel: watch + proof -->
						<div data-panel="noserver">
							<h2><?php esc_html_e( 'Your wallet', 'nodewatch-monero' ); ?></h2>
							<p class="lead"><?php esc_html_e( 'WordPress verifies payments itself with your VIEW key (view-only — it can see payments, never spend). Nothing runs 24/7.', 'nodewatch-monero' ); ?></p>
							<div class="xp-field">
								<label for="xp-addr"><?php esc_html_e( 'Your Monero address', 'nodewatch-monero' ); ?> <span class="hint"><?php esc_html_e( 'sets the network — 4… = mainnet, 5… = stagenet', 'nodewatch-monero' ); ?></span></label>
								<input type="text" id="xp-addr" class="xp-mono" placeholder="4… (mainnet) / 5… (stagenet)" value="<?php echo esc_attr( $g( 'xmr_address' ) ); ?>">
							</div>
							<div class="xp-field">
								<label for="xp-view"><?php esc_html_e( 'Private view key', 'nodewatch-monero' ); ?> <span class="hint"><?php esc_html_e( 'view-only — never your spend key or seed', 'nodewatch-monero' ); ?></span></label>
								<?php if ( $has_const ) : ?>
									<div class="xp-note ok"><?php
										/* translators: %s: the XMRPAY_VIEW_KEY constant name */
										printf( esc_html__( 'Loaded from the %s constant in wp-config.php — nothing to enter here.', 'nodewatch-monero' ), '<code>XMRPAY_VIEW_KEY</code>' );
									?></div>
								<?php else : ?>
									<input type="password" id="xp-view" class="xp-mono" placeholder="<?php esc_attr_e( '64 hex characters', 'nodewatch-monero' ); ?>" value="<?php echo esc_attr( $g( 'view_key' ) ); ?>">
									<div class="xp-note info"><?php
										/* translators: %s: a PHP define() snippet */
										printf( esc_html__( 'More private: put %s in wp-config.php so it never touches the database.', 'nodewatch-monero' ), '<code>define(\'XMRPAY_VIEW_KEY\', \'…\');</code>' );
									?></div>
								<?php endif; ?>
							</div>
							<div class="xp-field">
								<label for="xp-nodes"><?php esc_html_e( 'Monero node URL(s)', 'nodewatch-monero' ); ?> <span class="hint"><?php esc_html_e( 'comma-separated; a public one is fine', 'nodewatch-monero' ); ?></span></label>
								<input type="text" id="xp-nodes" class="xp-mono" placeholder="http://node2.monerodevs.org:38089" value="<?php echo esc_attr( $g( 'nodes', 'http://node2.monerodevs.org:38089' ) ); ?>">
							</div>
							<div class="xp-field">
								<label for="xp-minconf"><?php esc_html_e( 'Confirmations required', 'nodewatch-monero' ); ?></label>
								<input type="text" id="xp-minconf" placeholder="1" value="<?php echo esc_attr( $g( 'proof_min_conf', '1' ) ); ?>">
								<div class="xp-note info" style="margin-top:8px"><?php esc_html_e( '0 = instant (mempool, riskier) · 1 = first block (~2 min) · 10 = fully unlocked (high value).', 'nodewatch-monero' ); ?></div>
							</div>
							<button type="button" class="xp-btn ghost" id="xp-test-node"><?php esc_html_e( 'Test setup', 'nodewatch-monero' ); ?></button>
							<span class="hint" style="margin-left:8px"><?php esc_html_e( 'checks the node, network, and that your view key matches the address', 'nodewatch-monero' ); ?></span>
							<div id="xp-node-result" style="margin-top:10px"></div>
						</div>
						<!-- agent panel -->
						<div data-panel="agent" style="display:none">
							<h2><?php esc_html_e( 'Connect your agent', 'nodewatch-monero' ); ?></h2>
							<p class="lead"><?php esc_html_e( 'Run npx xmr-pay on a machine you control; it prints an Agent URL, a token, and a webhook secret. Paste them here.', 'nodewatch-monero' ); ?></p>
							<div class="xp-field">
								<label for="xp-agent-url"><?php esc_html_e( 'Agent URL', 'nodewatch-monero' ); ?> <span class="hint"><?php esc_html_e( 'keep it private — localhost or a private network', 'nodewatch-monero' ); ?></span></label>
								<input type="url" id="xp-agent-url" placeholder="http://127.0.0.1:8788" value="<?php echo esc_attr( $g( 'agent_url' ) ); ?>">
							</div>
							<div class="xp-field">
								<label for="xp-agent-token"><?php esc_html_e( 'Agent token', 'nodewatch-monero' ); ?></label>
								<input type="text" id="xp-agent-token" class="xp-mono" placeholder="<?php esc_attr_e( 'the AGENT_TOKEN from your agent', 'nodewatch-monero' ); ?>" value="<?php echo esc_attr( $g( 'agent_token' ) ); ?>">
							</div>
							<button type="button" class="xp-btn ghost" id="xp-test"><?php esc_html_e( 'Test connection', 'nodewatch-monero' ); ?></button>
							<div class="xp-test" id="xp-test-result"></div>
							<div class="xp-field" style="margin-top:16px">
								<label><?php esc_html_e( 'Set the agent\'s FULFILL_WEBHOOK_URL to:', 'nodewatch-monero' ); ?></label>
								<div class="xp-copy"><code id="xp-webhook-url"><?php echo esc_html( $webhook_url ); ?></code><button type="button" class="xp-btn ghost xp-copy-btn" data-copy="xp-webhook-url"><?php esc_html_e( 'Copy', 'nodewatch-monero' ); ?></button></div>
							</div>
							<div class="xp-field">
								<label for="xp-webhook-secret"><?php esc_html_e( 'Webhook secret', 'nodewatch-monero' ); ?> <span class="hint"><?php esc_html_e( 'the FULFILL_WEBHOOK_SECRET from your agent', 'nodewatch-monero' ); ?></span></label>
								<input type="text" id="xp-webhook-secret" class="xp-mono" placeholder="whsec_…" value="<?php echo esc_attr( $g( 'webhook_secret' ) ); ?>">
							</div>
						</div>
					</section>

					<!-- 2 — pricing -->
					<section class="xp-step" data-step="2">
						<h2><?php esc_html_e( 'How prices become XMR', 'nodewatch-monero' ); ?></h2>
						<?php if ( $is_xmr_store ) : ?>
							<div class="xp-note ok"><?php esc_html_e( 'Your store currency is already XMR — prices are native Monero and no price feed is used. Nothing to choose here.', 'nodewatch-monero' ); ?></div>
						<?php else : ?>
							<p class="lead"><?php
								printf(
									/* translators: 1: store currency code, 2: currency settings link open, 3: link close */
									esc_html__( 'Your store prices in %1$s, so the total is converted to XMR at checkout. Or price natively in Monero by setting your %2$sstore currency to XMR%3$s (no feed needed).', 'nodewatch-monero' ),
									'<strong>' . esc_html( $store_cur ) . '</strong>',
									'<a href="' . esc_url( $cur_url ) . '" target="_blank" rel="noopener">',
									'</a>'
								);
							?></p>
							<label class="xp-radio sel" data-src="coingecko">
								<input type="radio" name="xp-price" value="coingecko" checked>
								<b><?php esc_html_e( 'CoinGecko — live rate', 'nodewatch-monero' ); ?></b>
								<span><?php esc_html_e( 'Convert the fiat total to XMR at the current market price. Recommended for most stores.', 'nodewatch-monero' ); ?></span>
								<div class="xp-cond" data-cond="coingecko">
									<input type="text" id="xp-cg-key" class="xp-mono" placeholder="<?php esc_attr_e( 'CoinGecko API key (optional)', 'nodewatch-monero' ); ?>" value="<?php echo esc_attr( $g( 'coingecko_api_key' ) ); ?>">
								</div>
							</label>
							<label class="xp-radio" data-src="fixed">
								<input type="radio" name="xp-price" value="fixed">
								<b><?php esc_html_e( 'Fixed rate — you set it', 'nodewatch-monero' ); ?></b>
								<span><?php esc_html_e( 'Pin the price of 1 XMR in your store currency. No external feed.', 'nodewatch-monero' ); ?></span>
								<div class="xp-cond" data-cond="fixed">
									<input type="text" id="xp-fixed" placeholder="<?php esc_attr_e( 'price of 1 XMR, e.g. 150', 'nodewatch-monero' ); ?>" value="<?php echo esc_attr( $g( 'fixed_rate' ) ); ?>">
								</div>
							</label>
						<?php endif; ?>
					</section>

					<!-- 3 — go live -->
					<section class="xp-step" data-step="3">
						<h2><?php esc_html_e( 'How it looks at checkout', 'nodewatch-monero' ); ?></h2>
						<p class="lead"><?php esc_html_e( 'Last bit. Name the method and match the payment box to your store theme. You can fine-tune everything later in the full settings.', 'nodewatch-monero' ); ?></p>
						<div class="xp-field">
							<label for="xp-title"><?php esc_html_e( 'Title at checkout', 'nodewatch-monero' ); ?></label>
							<input type="text" id="xp-title" value="<?php echo esc_attr( $g( 'title', __( 'Monero (XMR)', 'nodewatch-monero' ) ) ); ?>">
						</div>
						<div class="xp-field">
							<label for="xp-theme"><?php esc_html_e( 'Payment box theme', 'nodewatch-monero' ); ?></label>
							<select id="xp-theme">
								<option value="light"<?php selected( $g( 'checkout_theme', 'light' ), 'light' ); ?>><?php esc_html_e( 'Light (for light store themes)', 'nodewatch-monero' ); ?></option>
								<option value="dark"<?php selected( $g( 'checkout_theme', 'light' ), 'dark' ); ?>><?php esc_html_e( 'Dark (for dark store themes)', 'nodewatch-monero' ); ?></option>
							</select>
						</div>
						<div class="xp-note info"><?php esc_html_e( 'Clicking “Finish” enables Monero at checkout with everything you set here.', 'nodewatch-monero' ); ?></div>
					</section>

					<!-- done -->
					<section class="xp-step" data-step="done">
						<div class="xp-done">
							<div class="check">✓</div>
							<h2><?php esc_html_e( 'Monero is live', 'nodewatch-monero' ); ?></h2>
							<p class="lead"><?php esc_html_e( 'Your store now accepts XMR, non-custodially. Place a test order to watch the live on-chain stepper, or open the full settings to tune confirmations, order expiry and more.', 'nodewatch-monero' ); ?></p>
							<div class="links">
								<a class="xp-btn primary" id="xp-link-shop" href="#" target="_blank" rel="noopener"><?php esc_html_e( 'Visit your shop', 'nodewatch-monero' ); ?></a>
								<a class="xp-btn ghost" id="xp-link-settings" href="#"><?php esc_html_e( 'Full settings', 'nodewatch-monero' ); ?></a>
							</div>
						</div>
					</section>

				</div>

				<div class="xp-foot" id="xp-foot">
					<button type="button" class="xp-btn link" id="xp-back" style="visibility:hidden"><?php esc_html_e( '← Back', 'nodewatch-monero' ); ?></button>
					<div class="right">
						<a class="xp-btn link" href="<?php echo esc_url( $full_url ); ?>"><?php esc_html_e( 'Skip — I\'ll use the full settings', 'nodewatch-monero' ); ?></a>
						<button type="button" class="xp-btn primary" id="xp-next"><?php esc_html_e( 'Next →', 'nodewatch-monero' ); ?></button>
					</div>
				</div>
			</div>

			<?php // wizard behaviour lives in assets/wizard.js (enqueued + localised in enqueue()) ?>
		</div>
		<?php
	}
}
