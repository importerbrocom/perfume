<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin tools: Store Dashboard (sales, charts, low stock), printable invoices,
 * CSV order reports and a WhatsApp "notify customer" button.
 * Everything requires manage_woocommerce (shop manager / administrator).
 */
class PM_Admin {

	const PAID = array( 'processing', 'pm-confirmed', 'pm-packed', 'pm-shipped', 'pm-outfordel', 'completed' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_pm_invoice', array( __CLASS__, 'invoice' ) );
		add_action( 'admin_post_pm_orders_csv', array( __CLASS__, 'csv' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'order_box' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Store Dashboard', 'Store Dashboard', 'manage_woocommerce', 'pm-dashboard', array( __CLASS__, 'dashboard' ) );
	}

	public static function invoice_url( $order_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pm_invoice&order_id=' . absint( $order_id ) ), 'pm_invoice_' . absint( $order_id ) );
	}

	/* ---------- Dashboard ---------- */

	private static function stats() {
		$cached = get_transient( 'pm_dash_stats' );
		if ( $cached ) {
			return $cached;
		}
		$tz     = wp_timezone();
		$now    = new DateTimeImmutable( 'now', $tz );
		$start  = $now->modify( 'first day of this month' )->setTime( 0, 0 )->modify( '-11 months' );
		$orders = wc_get_orders( array(
			'status'       => self::PAID,
			'limit'        => -1,
			'date_created' => '>=' . $start->getTimestamp(),
			'return'       => 'objects',
		) );
		$months = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$months[ $start->modify( "+$i months" )->format( 'Y-m' ) ] = 0.0;
		}
		$today = $now->format( 'Y-m-d' );
		$thism = $now->format( 'Y-m' );
		$sales_today = 0.0;
		foreach ( $orders as $o ) {
			$d = $o->get_date_created();
			if ( ! $d ) {
				continue;
			}
			$d = $d->setTimezone( $tz );
			$k = $d->format( 'Y-m' );
			if ( isset( $months[ $k ] ) ) {
				$months[ $k ] += (float) $o->get_total() - (float) $o->get_total_refunded();
			}
			if ( $d->format( 'Y-m-d' ) === $today ) {
				$sales_today += (float) $o->get_total() - (float) $o->get_total_refunded();
			}
		}
		$best = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 5, 'meta_key' => 'total_sales', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'meta_query' => array( array( 'key' => 'total_sales', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ) ) ) );
		$low_amt = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		$low = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 10, 'meta_query' => array( 'relation' => 'AND', array( 'key' => '_manage_stock', 'value' => 'yes' ), array( 'key' => '_stock', 'value' => $low_amt, 'compare' => '<=', 'type' => 'NUMERIC' ) ) ) );
		$users  = count_users();
		$data   = array(
			'months'      => $months,
			'today'       => $sales_today,
			'this_month'  => $months[ $thism ] ?? 0,
			'total_12m'   => array_sum( $months ),
			'orders'      => array_sum( array_map( function ( $s ) { return (int) wc_orders_count( $s ); }, self::PAID ) ) + (int) wc_orders_count( 'pending' ),
			'pending'     => (int) wc_orders_count( 'pending' ) + (int) wc_orders_count( 'processing' ),
			'completed'   => (int) wc_orders_count( 'completed' ),
			'customers'   => (int) ( $users['avail_roles']['customer'] ?? 0 ),
			'best'        => array_map( function ( $p ) { return array( 'name' => get_the_title( $p ), 'sold' => (int) get_post_meta( $p->ID, 'total_sales', true ) ); }, $best ),
			'low'         => array_map( function ( $p ) { return array( 'id' => $p->ID, 'name' => get_the_title( $p ), 'stock' => (int) get_post_meta( $p->ID, '_stock', true ) ); }, $low ),
		);
		set_transient( 'pm_dash_stats', $data, 5 * MINUTE_IN_SECONDS );
		return $data;
	}

	public static function dashboard() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		$s   = self::stats();
		$cur = get_woocommerce_currency();
		$max = max( 1, max( $s['months'] ) );
		$bmax = 1;
		foreach ( $s['best'] as $b ) {
			$bmax = max( $bmax, $b['sold'] );
		}
		echo '<div class="wrap"><h1>Store Dashboard</h1>';
		echo '<style>.pmd{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:16px 0}.pmd div{background:#fff;border:1px solid #ccd0d4;padding:14px;border-radius:6px}.pmd b{display:block;font-size:22px}.pmc{background:#fff;border:1px solid #ccd0d4;padding:16px;border-radius:6px;margin-bottom:16px}.pmbars{display:flex;align-items:flex-end;gap:8px;height:160px}.pmbars span{flex:1;background:#ec7d28;border-radius:3px 3px 0 0;position:relative;min-height:2px}.pmbars small{position:absolute;bottom:-20px;left:0;right:0;text-align:center;font-size:10px}.pmh{height:12px;background:#ec7d28;border-radius:3px}</style>';
		echo '<div class="pmd">';
		$cards = array(
			'Today\'s sales'          => wc_price( $s['today'] ),
			'This month'              => wc_price( $s['this_month'] ),
			'Sales (last 12 months)'  => wc_price( $s['total_12m'] ),
			'Total orders (excl. cancelled)'            => (string) (int) $s['orders'],
			'Pending / processing'    => (string) (int) $s['pending'],
			'Completed (Delivered)'   => (string) (int) $s['completed'],
			'Customers'               => (string) (int) $s['customers'],
		);
		foreach ( $cards as $label => $val ) {
			echo '<div>' . esc_html( $label ) . '<b>' . wp_kses_post( $val ) . '</b></div>';
		}
		echo '</div>';

		echo '<div class="pmc"><h2>Monthly sales (' . esc_html( $cur ) . ')</h2><div class="pmbars" style="margin-bottom:28px" role="img" aria-label="Monthly sales bar chart">';
		foreach ( $s['months'] as $m => $v ) {
			printf( '<span style="height:%d%%" title="%s"><small>%s</small></span>', (int) round( $v / $max * 100 ), esc_attr( $m . ': ' . number_format_i18n( $v, 2 ) ), esc_html( substr( $m, 5 ) ) );
		}
		echo '</div></div>';

		echo '<div class="pmc"><h2>Best-selling perfumes</h2>';
		if ( ! $s['best'] ) {
			echo '<p>No sales yet.</p>';
		}
		foreach ( $s['best'] as $b ) {
			printf( '<p>%s &mdash; %d sold<div class="pmh" style="width:%d%%"></div></p>', esc_html( $b['name'] ), (int) $b['sold'], (int) round( $b['sold'] / $bmax * 100 ) );
		}
		echo '</div><div class="pmc"><h2>Low-stock products</h2>';
		if ( ! $s['low'] ) {
			echo '<p>No low-stock products.</p>';
		}
		foreach ( $s['low'] as $l ) {
			printf( '<p><a href="%s">%s</a> &mdash; %d left</p>', esc_url( get_edit_post_link( $l['id'] ) ), esc_html( $l['name'] ), (int) $l['stock'] );
		}
		echo '</div><div class="pmc"><h2>Recent orders</h2><table class="widefat striped"><thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Total</th></tr></thead><tbody>';
		foreach ( wc_get_orders( array( 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC' ) ) as $o ) {
			printf( '<tr><td><a href="%s">#%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_url( $o->get_edit_order_url() ), esc_html( $o->get_order_number() ), esc_html( wc_format_datetime( $o->get_date_created() ) ), esc_html( wc_get_order_status_name( $o->get_status() ) ), wp_kses_post( $o->get_formatted_order_total() ) );
		}
		echo '</tbody></table></div>';

		echo '<div class="pmc"><h2>Download order report (CSV)</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="pm_orders_csv">';
		wp_nonce_field( 'pm_orders_csv', 'pm_csv_nonce' );
		echo '<label>From <input type="date" name="from" required></label> <label>To <input type="date" name="to" required></label> ';
		echo '<button class="button button-primary">Download CSV</button></form></div></div>';
	}

	/* ---------- CSV ---------- */

	private static function csv_safe( $v ) {
		$v = (string) $v;
		return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v; // block spreadsheet formula injection.
	}

	public static function csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_POST['pm_csv_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pm_csv_nonce'] ) ), 'pm_orders_csv' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			wp_die( 'Invalid dates.', 400 );
		}
		$tz  = wp_timezone();
		$a   = new DateTimeImmutable( $from . ' 00:00:00', $tz );
		$b   = new DateTimeImmutable( $to . ' 23:59:59', $tz );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="orders-' . $from . '_' . $to . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Order', 'Date', 'Status', 'Customer', 'Email', 'Phone', 'Country', 'City', 'Payment', 'Subtotal', 'Discount', 'Shipping', 'Total', 'Currency', 'Coupons', 'Tracking' ) );
		$page = 1;
		do {
			$orders = wc_get_orders( array( 'limit' => 200, 'paged' => $page, 'date_created' => $a->getTimestamp() . '...' . $b->getTimestamp(), 'orderby' => 'date', 'order' => 'ASC' ) );
			foreach ( $orders as $o ) {
				fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), array(
					$o->get_order_number(), $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '', wc_get_order_status_name( $o->get_status() ),
					trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ), $o->get_billing_email(), $o->get_billing_phone(),
					$o->get_billing_country(), $o->get_billing_city(), $o->get_payment_method_title(),
					$o->get_subtotal(), $o->get_total_discount(), $o->get_shipping_total(), $o->get_total(), $o->get_currency(),
					implode( ' ', $o->get_coupon_codes() ), $o->get_meta( '_pm_tracking_number' ),
				) ) );
			}
			$page++;
		} while ( count( $orders ) === 200 );
		fclose( $out );
		exit;
	}

	/* ---------- Invoice ---------- */

	public static function invoice() {
		$id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( ! $id || ! is_user_logged_in() || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'pm_invoice_' . $id ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		$order = wc_get_order( $id );
		// Staff, or the customer who owns the order.
		if ( ! $order || ! ( current_user_can( 'manage_woocommerce' ) || ( $order->get_user_id() && (int) $order->get_user_id() === get_current_user_id() ) ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo '<!doctype html><html><head><meta charset="utf-8"><title>Invoice #' . esc_html( $order->get_order_number() ) . '</title><meta name="robots" content="noindex">';
		echo '<style>body{font:14px Arial,Helvetica,sans-serif;color:#333;max-width:780px;margin:30px auto;padding:0 16px}h1{color:#3e2a21;border-bottom:3px solid #ec7d28;padding-bottom:10px}table{width:100%;border-collapse:collapse;margin:18px 0}th,td{border-bottom:1px solid #f2d4bc;padding:8px;text-align:left}td.r,th.r{text-align:right}.cols{display:flex;gap:30px}.cols div{flex:1}button{background:#ec7d28;color:#fff;border:0;border-radius:99px;padding:10px 22px;cursor:pointer}@media print{button{display:none}}</style></head><body>';
		echo '<button onclick="window.print()">Print / Save as PDF</button>';
		echo '<h1>' . esc_html( get_bloginfo( 'name' ) ) . ' &mdash; Invoice #' . esc_html( $order->get_order_number() ) . '</h1>';
		echo '<p>Date: ' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . ' &middot; Status: ' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . ' &middot; Payment: ' . esc_html( $order->get_payment_method_title() ) . '</p>';
		echo '<div class="cols"><div><strong>Billing</strong><br>' . wp_kses_post( $order->get_formatted_billing_address( 'N/A' ) ) . '<br>' . esc_html( $order->get_billing_email() ) . '<br>' . esc_html( $order->get_billing_phone() ) . '</div>';
		echo '<div><strong>Shipping</strong><br>' . wp_kses_post( $order->get_formatted_shipping_address( 'N/A' ) ) . '</div></div>';
		echo '<table><thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Total</th></tr></thead><tbody>';
		foreach ( $order->get_items() as $item ) {
			echo '<tr><td>' . esc_html( $item->get_name() ) . '</td><td class="r">' . esc_html( $item->get_quantity() ) . '</td><td class="r">' . wp_kses_post( $order->get_formatted_line_subtotal( $item ) ) . '</td></tr>';
		}
		echo '</tbody></table><table>';
		foreach ( $order->get_order_item_totals() as $row ) {
			echo '<tr><th>' . esc_html( $row['label'] ) . '</th><td class="r">' . wp_kses_post( $row['value'] ) . '</td></tr>';
		}
		echo '</table></body></html>';
		exit;
	}

	/* ---------- Order meta box: invoice + WhatsApp ---------- */

	public static function order_box() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'pm_order_tools', 'Invoice & Notify', array( __CLASS__, 'order_box_html' ), $screen, 'side', 'default' );
	}

	public static function order_box_html( $post_or_order ) {
		$order = wc_get_order( is_object( $post_or_order ) && isset( $post_or_order->ID ) ? $post_or_order->ID : $post_or_order );
		if ( ! $order ) {
			return;
		}
		echo '<p><a class="button" target="_blank" href="' . esc_url( self::invoice_url( $order->get_id() ) ) . '">Print invoice</a></p>';
		$phone = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );
		if ( $phone ) {
			$msg = sprintf( 'Hello %s, your order #%s is now %s.', $order->get_billing_first_name(), $order->get_order_number(), wc_get_order_status_name( $order->get_status() ) );
			$tn  = $order->get_meta( '_pm_tracking_number' );
			if ( $tn ) {
				$msg .= ' Tracking: ' . $tn . '.';
			}
			echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url( 'https://wa.me/' . $phone . '?text=' . rawurlencode( $msg ) ) . '">Notify on WhatsApp</a></p>';
			echo '<p class="description">Opens WhatsApp with a prefilled message; you press send. (Phone must include the country code.)</p>';
		}
	}
}
