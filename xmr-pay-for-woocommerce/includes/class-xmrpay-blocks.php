<?php
/**
 * WooCommerce Blocks checkout integration.
 *
 * Modern WooCommerce ships a React ("Blocks") checkout by default, and a classic
 * WC_Payment_Gateway does NOT appear there on its own — it needs this registration.
 * Without it the buyer sees "There are no payment methods available". Order
 * processing still flows through the classic gateway's process_payment().
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class XmrPay_Blocks_Support extends AbstractPaymentMethodType {

	protected $name = 'xmrpay';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_xmrpay_settings', array() );
	}

	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled']
			&& '' !== trim( (string) ( isset( $this->settings['agent_url'] ) ? $this->settings['agent_url'] : '' ) );
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'xmrpay-blocks',
			plugins_url( 'assets/blocks.js', XMRPAY_WC_FILE ),
			array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			XMRPAY_WC_VERSION,
			true
		);
		return array( 'xmrpay-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'Monero (XMR)', 'xmr-pay-for-woocommerce' ),
			'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
			'supports'    => array( 'products' ),
		);
	}
}
