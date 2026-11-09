<?php
defined( 'ABSPATH' ) || exit;

/**
 * Order lifecycle: maps the requested statuses onto WooCommerce statuses.
 *
 *  Pending Payment   -> pending (native)
 *  Processing        -> processing (native)
 *  Confirmed         -> wc-pm-confirmed (custom)
 *  Packed            -> wc-pm-packed (custom)
 *  Shipped           -> wc-pm-shipped (custom)
 *  Out for Delivery  -> wc-pm-outfordel (custom)
 *  Delivered         -> completed (native, relabelled)
 *  Cancelled / Refunded -> native
 *
 * Also stores tracking number/carrier and sends customer notifications.
 */
class PM_Order_Statuses {

	const CUSTOM = array(
		'wc-pm-confirmed' => 'Confirmed',
		'wc-pm-packed'    => 'Packed',
		'wc-pm-shipped'   => 'Shipped',
		'wc-pm-outfordel' => 'Out for Delivery',
	);

	/** Timeline steps in order (status slug without "wc-" => label). */
	public static function timeline() {
		return array(
			'pending'      => 'Pending Payment',
			'processing'   => 'Processing',
			'pm-confirmed' => 'Confirmed',
			'pm-packed'    => 'Packed',
			'pm-shipped'   => 'Shipped',
			'pm-outfordel' => 'Out for Delivery',
			'completed'    => 'Delivered',
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_statuses' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'payment_complete_statuses' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( __CLASS__, 'report_statuses' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_tracking' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'notify_customer' ), 10, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_my_account_timeline' ) );
	}

	public static function register() {
		foreach ( self::CUSTOM as $slug => $label ) {
			register_post_status( $slug, array(
				'label'                     => $label,
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count */
				'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>' ),
			) );
		}
	}

	public static function add_statuses( $statuses ) {
		$out = array();
		foreach ( $statuses as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'wc-processing' === $k ) {
				$out = array_merge( $out, self::CUSTOM );
			}
		}
		$out['wc-completed'] = 'Delivered';
		return $out;
	}

	public static function payment_complete_statuses( $s ) {
		return $s;
	}

	public static function report_statuses( $s ) {
		return array_unique( array_merge( (array) $s, array_keys( self::CUSTOM ) ) );
	}

	/* ---------- Tracking number (admin) ---------- */

	public static function add_meta_box() {
		$screen = function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'pm_tracking', 'Shipment Tracking', array( __CLASS__, 'tracking_box' ), $screen, 'side', 'default' );
	}

	public static function tracking_box( $post_or_order ) {
		$order = wc_get_order( is_object( $post_or_order ) && isset( $post_or_order->ID ) ? $post_or_order->ID : $post_or_order );
		if ( ! $order ) {
			return;
		}
		wp_nonce_field( 'pm_save_tracking', 'pm_tracking_nonce' );
		printf( '<p><label>Carrier<br><input type="text" class="widefat" name="pm_carrier" value="%s"></label></p>', esc_attr( $order->get_meta( '_pm_carrier' ) ) );
		printf( '<p><label>Tracking number<br><input type="text" class="widefat" name="pm_tracking_number" value="%s"></label></p>', esc_attr( $order->get_meta( '_pm_tracking_number' ) ) );
		printf( '<p><label>Tracking URL (optional)<br><input type="url" class="widefat" name="pm_tracking_url" value="%s"></label></p>', esc_attr( $order->get_meta( '_pm_tracking_url' ) ) );
	}

	public static function save_tracking( $order_id ) {
		if ( ! isset( $_POST['pm_tracking_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pm_tracking_nonce'] ) ), 'pm_save_tracking' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$order->update_meta_data( '_pm_carrier', isset( $_POST['pm_carrier'] ) ? sanitize_text_field( wp_unslash( $_POST['pm_carrier'] ) ) : '' );
		$order->update_meta_data( '_pm_tracking_number', isset( $_POST['pm_tracking_number'] ) ? sanitize_text_field( wp_unslash( $_POST['pm_tracking_number'] ) ) : '' );
		$order->update_meta_data( '_pm_tracking_url', isset( $_POST['pm_tracking_url'] ) ? esc_url_raw( wp_unslash( $_POST['pm_tracking_url'] ) ) : '' );
		$order->save();
	}

	/* ---------- Notifications ---------- */

	public static function notify_customer( $order_id, $from, $to, $order ) {
		$notify = array( 'pm-confirmed', 'pm-packed', 'pm-shipped', 'pm-outfordel', 'completed', 'cancelled', 'refunded' );
		if ( ! in_array( $to, $notify, true ) || ! $order || ! is_email( $order->get_billing_email() ) ) {
			return;
		}
		$labels  = wc_get_order_statuses();
		$label   = isset( $labels[ 'wc-' . $to ] ) ? $labels[ 'wc-' . $to ] : $to;
		$subject = sprintf( '[%s] Order #%s: %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number(), $label );
		$body    = '<p>Hello ' . esc_html( $order->get_billing_first_name() ) . ',</p>';
		$body   .= '<p>Your order <strong>#' . esc_html( $order->get_order_number() ) . '</strong> is now <strong>' . esc_html( $label ) . '</strong>.</p>';
		$tn      = $order->get_meta( '_pm_tracking_number' );
		if ( $tn && in_array( $to, array( 'pm-shipped', 'pm-outfordel' ), true ) ) {
			$body .= '<p>Carrier: ' . esc_html( $order->get_meta( '_pm_carrier' ) ) . '<br>Tracking number: ' . esc_html( $tn ) . '</p>';
		}
		$body .= '<p>Track your order: <a href="' . esc_url( PM_Frontend::tracking_url() ) . '">' . esc_html( PM_Frontend::tracking_url() ) . '</a></p>';

		// Send through WooCommerce so the From name/address and template from
		// WooCommerce > Settings > Emails are used (plain wp_mail would send as wordpress@<host>).
		$mailer = WC()->mailer();
		$html   = $mailer->wrap_message( sprintf( 'Order #%s: %s', $order->get_order_number(), $label ), $body );
		$sent   = $mailer->send( $order->get_billing_email(), $subject, $html, "Content-Type: text/html; charset=UTF-8\r\n" );
		$order->add_order_note( $sent
			? sprintf( 'Customer emailed: status "%s".', $label )
			: sprintf( 'Could not email the customer about status "%s". Check the site email settings.', $label ) );
	}

	/* ---------- Timeline rendering ---------- */

	public static function render_timeline( $order ) {
		$status = $order->get_status();
		$steps  = self::timeline();
		$keys   = array_keys( $steps );
		$html   = '';
		if ( in_array( $status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			$labels = wc_get_order_statuses();
			return '<div class="pm-timeline pm-timeline--ended"><p class="pm-timeline__ended">Order status: <strong>' . esc_html( $labels[ 'wc-' . $status ] ?? $status ) . '</strong></p></div>';
		}
		$idx = array_search( $status, $keys, true );
		if ( false === $idx ) {
			$idx = 0; // on-hold etc.
		}
		$html .= '<ol class="pm-timeline">';
		foreach ( $keys as $i => $k ) {
			$cls   = $i < $idx ? 'is-done' : ( $i === $idx ? 'is-current' : '' );
			$html .= '<li class="' . esc_attr( $cls ) . '"' . ( $i === $idx ? ' aria-current="step"' : '' ) . '><span class="pm-timeline__dot"></span><span class="pm-timeline__label">' . esc_html( $steps[ $k ] ) . '</span></li>';
		}
		$html .= '</ol>';
		$tn    = $order->get_meta( '_pm_tracking_number' );
		if ( $tn ) {
			$url   = $order->get_meta( '_pm_tracking_url' );
			$html .= '<p class="pm-timeline__tn">Carrier: ' . esc_html( $order->get_meta( '_pm_carrier' ) ) . ' &middot; Tracking #: ' . ( $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $tn ) . '</a>' : esc_html( $tn ) ) . '</p>';
		}
		return $html;
	}

	public static function render_my_account_timeline( $order ) {
		echo '<h2>Order progress</h2>' . self::render_timeline( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_timeline.
	}
}
