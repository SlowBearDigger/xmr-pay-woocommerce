<?php
// Expose XMRPay to WooCommerce Blocks.


if ( ! defined( 'ABSPATH' ) ) { exit; }

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class XmrPay_Blocks_Support extends AbstractPaymentMethodType {

	protected $name = 'xmrpay';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_xmrpay_settings', array() );
	}

	public function is_active() {
		if ( empty( $this->settings['enabled'] ) || 'yes' !== $this->settings['enabled'] ) {
			return false;
		}

		$mode = isset( $this->settings['mode'] ) ? $this->settings['mode'] : 'watch';
		if ( 'agent' === $mode ) {
			return '' !== XmrPay_Util::normalize_agent_url( isset( $this->settings['agent_url'] ) ? $this->settings['agent_url'] : '' );
		}

		$has_view = ( defined( 'XMRPAY_VIEW_KEY' ) && '' !== trim( (string) XMRPAY_VIEW_KEY ) )
			|| '' !== trim( (string) ( isset( $this->settings['view_key'] ) ? $this->settings['view_key'] : '' ) );
		return '' !== trim( (string) ( isset( $this->settings['xmr_address'] ) ? $this->settings['xmr_address'] : '' ) )
			&& $has_view
			&& XmrPay_Util::crypto_ready();
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
			'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'Monero (XMR)', 'nodewatch-monero' ),
			'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
			'supports'    => array( 'products' ),
		);
	}
}
