<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class XmrPay_Node_Fields {
	private function __construct() {}

	public static function render( $rows, $name = 'node_configs', $id = 'xmrpay' ): string {
		$rows = XmrPay_Node_Config::normalize_list( $rows );
		if ( ! $rows ) { $rows = array( array( 'url' => '', 'auth' => 'none', 'username' => '', 'password' => '' ) ); }
		$html = '<div class="xmrpay-node-list" id="' . esc_attr( $id . '-node-list' ) . '" data-name="' . esc_attr( $name ) . '"';
		$html .= ' data-node-label="' . esc_attr__( 'Node', 'nodewatch-monero' ) . '"';
		$html .= ' data-status-idle="' . esc_attr__( 'Not checked', 'nodewatch-monero' ) . '"';
		$html .= ' data-status-checking="' . esc_attr__( 'Checking', 'nodewatch-monero' ) . '"';
		$html .= ' data-status-healthy="' . esc_attr__( 'Healthy', 'nodewatch-monero' ) . '"';
		$html .= ' data-status-warning="' . esc_attr__( 'Warning', 'nodewatch-monero' ) . '">';
		foreach ( $rows as $index => $row ) { $html .= self::row( $row, $name, $index ); }
		return $html . '</div><button type="button" class="button button-secondary xmrpay-add-node">+ ' . esc_html__( 'Add another node', 'nodewatch-monero' ) . '</button>';
	}

	private static function row( array $row, string $name, int $index ): string {
		$base = esc_attr( $name . '[' . $index . ']' );
		$saved = '' !== $row['password'] ? 'true' : 'false';
		$html = '<div class="xmrpay-node-row xmrpay-node-card" data-password-saved="' . $saved . '">';
		$html .= '<div class="xmrpay-node-card-header"><strong class="xmrpay-node-title">' . sprintf( esc_html__( 'Node %d', 'nodewatch-monero' ), $index + 1 ) . '</strong>';
		$html .= '<span class="xmrpay-node-status" data-state="idle" aria-live="polite">' . esc_html__( 'Not checked', 'nodewatch-monero' ) . '</span>';
		$html .= '<button type="button" class="button-link-delete xmrpay-remove-node">' . esc_html__( 'Remove', 'nodewatch-monero' ) . '</button></div>';
		$html .= '<div class="xmrpay-node-fields">';
		$html .= '<label class="xmrpay-node-field"><span>' . esc_html__( 'Node URL', 'nodewatch-monero' ) . '</span><input type="url" class="xmrpay-node-url" name="' . $base . '[url]" value="' . esc_attr( $row['url'] ) . '" placeholder="https://node.example:18081"></label>';
		$html .= '<label class="xmrpay-node-field"><span>' . esc_html__( 'Authentication', 'nodewatch-monero' ) . '</span><select class="xmrpay-node-auth" name="' . $base . '[auth]">';
		foreach ( array( 'none' => 'None', 'basic' => 'Basic', 'digest' => 'Digest' ) as $value => $label ) {
			$html .= '<option value="' . $value . '"' . ( $row['auth'] === $value ? ' selected' : '' ) . '>' . esc_html__( $label, 'nodewatch-monero' ) . '</option>';
		}
		$html .= '</select></label></div><div class="xmrpay-node-credentials">';
		$html .= '<label class="xmrpay-node-field"><span>' . esc_html__( 'Username', 'nodewatch-monero' ) . '</span><input type="text" name="' . $base . '[username]" value="' . esc_attr( $row['username'] ) . '" autocomplete="username"></label>';
		$html .= '<label class="xmrpay-node-field"><span>' . esc_html__( 'Password', 'nodewatch-monero' ) . '</span><input type="password" name="' . $base . '[password]" value="" autocomplete="new-password"></label>';
		if ( 'true' === $saved ) { $html .= '<small>' . esc_html__( 'Password saved. Leave blank to keep it.', 'nodewatch-monero' ) . '</small>'; }
		$html .= '</div>';
		return $html . '</div>';
	}
}
