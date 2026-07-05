<?php
/**
 * Monero payments report — an admin page listing every xmr-pay order (owed / received /
 * overpaid / confirmations / invoice state / refund status / txids), with a server-side CSV
 * export. Tier 0: pure WordPress admin, no server, no third party. Every value is already in
 * _xmrpay_* order meta. Per-store isolation holds (wc_get_orders is store-scoped). The column
 * schema mirrors the lib's src/report.js ordersToCsv so the agent's /orders.csv and this page
 * speak the same columns.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-xmrpay-util.php';   // claim_expired (effective refund status)

class XmrPay_Report {

	const PAGE    = 'xmrpay-report';
	const GATEWAY = 'xmrpay';
	const PER_PAGE = 50;

	/** Canonical CSV/report columns (mirror of src/report.js CORE_COLUMNS + WC extras). */
	public static function columns() {
		return array( 'order', 'date', 'wc_status', 'state', 'owed_xmr', 'received_xmr', 'overpaid_xmr', 'confirmations', 'mode', 'refund_status', 'txids' );
	}

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_xmrpay_report_csv', array( $this, 'export_csv' ) );
	}

	public function register_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Monero payments', 'xmr-pay-for-woocommerce' ),
			__( 'Monero payments', 'xmr-pay-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/** One order -> a row keyed by columns(). Static so the table + CSV share exactly one mapping. */
	public static function order_row( $order ) {
		$received = (string) $order->get_meta( '_xmrpay_received' );
		return array(
			'order'         => (string) $order->get_order_number(),
			'date'          => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
			'wc_status'     => $order->get_status(),
			'state'         => self::derive_state( $order, $received ),
			'owed_xmr'      => (string) $order->get_meta( '_xmrpay_amount' ),
			'received_xmr'  => '' !== $received ? $received : '0',
			'overpaid_xmr'  => 'yes' === $order->get_meta( '_xmrpay_overpaid' ) ? (string) $order->get_meta( '_xmrpay_overpaid_xmr' ) : '',
			'confirmations' => (string) $order->get_meta( '_xmrpay_confirmations' ),
			'mode'          => (string) $order->get_meta( '_xmrpay_mode' ),
			'refund_status' => self::effective_refund_status( $order ),
			'txids'         => (string) $order->get_meta( '_xmrpay_txids' ),
		);
	}

	/** Stored refund status, overlaid with 'expired' when a still-`requested` claim-link has lapsed. */
	private static function effective_refund_status( $order ) {
		$st = (string) $order->get_meta( '_xmrpay_refund_status' );
		if ( 'requested' === $st && XmrPay_Util::claim_expired( $st, (int) $order->get_meta( '_xmrpay_refund_opened' ), (int) $order->get_meta( '_xmrpay_refund_window' ), time() ) ) {
			return 'expired';
		}
		return $st;
	}

	/**
	 * Derive the canonical invoice state from the WC order (the plugin maps straight to WC
	 * statuses and persists no _xmrpay_status). Mirrors XmrPay_Util::to_invoice_state's codomain.
	 */
	private static function derive_state( $order, $received ) {
		if ( $order->is_paid() ) {
			return 'settled';
		}
		$status = $order->get_status();
		if ( 'refunded' === $status ) {
			return 'settled';
		}
		if ( in_array( $status, array( 'cancelled', 'failed' ), true ) ) {
			return 'expired';
		}
		return ( '' !== $received && (float) $received > 0 ) ? 'processing' : 'created';
	}

	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// read-only pagination param; no state change, so no nonce needed.
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res   = wc_get_orders( array(
			'limit'          => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'payment_method' => self::GATEWAY,
			'paginate'       => true,
			'return'         => 'objects',
		) );
		$orders   = isset( $res->orders ) ? $res->orders : array();
		$total    = isset( $res->total ) ? (int) $res->total : 0;
		$max_page = isset( $res->max_num_pages ) ? (int) $res->max_num_pages : 1;

		$csv_url = wp_nonce_url( admin_url( 'admin-post.php?action=xmrpay_report_csv' ), 'xmrpay_report_csv' );

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Monero payments', 'xmr-pay-for-woocommerce' ) . '</h1> ';
		echo '<a href="' . esc_url( $csv_url ) . '" class="page-title-action">' . esc_html__( 'Export CSV', 'xmr-pay-for-woocommerce' ) . '</a>';
		/* translators: %d: number of orders */
		echo '<p class="description">' . esc_html( sprintf( _n( '%d Monero order.', '%d Monero orders.', $total, 'xmr-pay-for-woocommerce' ), $total ) ) . '</p>';

		if ( ! $orders ) {
			echo '<p>' . esc_html__( 'No Monero orders yet.', 'xmr-pay-for-woocommerce' ) . '</p></div>';
			return;
		}

		$heads = array(
			'order'         => __( 'Order', 'xmr-pay-for-woocommerce' ),
			'date'          => __( 'Date', 'xmr-pay-for-woocommerce' ),
			'state'         => __( 'State', 'xmr-pay-for-woocommerce' ),
			'wc_status'     => __( 'WC status', 'xmr-pay-for-woocommerce' ),
			'owed_xmr'      => __( 'Owed', 'xmr-pay-for-woocommerce' ),
			'received_xmr'  => __( 'Received', 'xmr-pay-for-woocommerce' ),
			'overpaid_xmr'  => __( 'Overpaid', 'xmr-pay-for-woocommerce' ),
			'confirmations' => __( 'Confs', 'xmr-pay-for-woocommerce' ),
			'mode'          => __( 'Mode', 'xmr-pay-for-woocommerce' ),
			'refund_status' => __( 'Refund', 'xmr-pay-for-woocommerce' ),
		);
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach ( $heads as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $orders as $order ) {
			$r    = self::order_row( $order );
			$link = esc_url( $order->get_edit_order_url() );
			echo '<tr>';
			echo '<td><a href="' . $link . '">#' . esc_html( $r['order'] ) . '</a></td>';
			echo '<td>' . esc_html( $r['date'] ) . '</td>';
			echo '<td><code>' . esc_html( $r['state'] ) . '</code></td>';
			echo '<td>' . esc_html( wc_get_order_status_name( $r['wc_status'] ) ) . '</td>';
			echo '<td>' . esc_html( $r['owed_xmr'] ) . '</td>';
			echo '<td>' . esc_html( $r['received_xmr'] ) . '</td>';
			echo '<td>' . esc_html( '' !== $r['overpaid_xmr'] ? $r['overpaid_xmr'] : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== $r['confirmations'] ? $r['confirmations'] : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== $r['mode'] ? $r['mode'] : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== $r['refund_status'] ? $r['refund_status'] : '—' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( $max_page > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post( paginate_links( array(
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $max_page,
			) ) );
			echo '</div></div>';
		}
		echo '</div>';
	}

	/** Stream every xmr-pay order as a CSV download (batched, memory-safe). */
	public function export_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'xmr-pay-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'xmrpay_report_csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="xmrpay-orders-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$cols = self::columns();
		$out  = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download, not filesystem I/O
		fputcsv( $out, $cols );
		$offset = 0;
		$batch  = 200;
		do {
			$orders = wc_get_orders( array(
				'limit'          => $batch,
				'offset'         => $offset,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'payment_method' => self::GATEWAY,
				'return'         => 'objects',
			) );
			$orders = (array) $orders;
			foreach ( $orders as $order ) {
				$row  = self::order_row( $order );
				$line = array();
				foreach ( $cols as $c ) {
					$line[] = self::csv_safe( isset( $row[ $c ] ) ? $row[ $c ] : '' );
				}
				fputcsv( $out, $line );
			}
			$offset += $batch;
		} while ( count( $orders ) === $batch );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream
		exit;
	}

	/** Neutralise spreadsheet formula injection: a leading =,+,-,@,tab,CR becomes text. */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
