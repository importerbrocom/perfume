<?php
defined( 'ABSPATH' ) || exit;

/**
 * Product page "Buy Now": adds the chosen product/size/quantity to the cart and goes straight to checkout.
 * Uses WooCommerce's own add-to-cart handler (stock, price and variation checks stay server-side).
 * Also shows the order progress + tracking link on the order-received ("thank you") page.
 */
class PM_Buy_Now {

	public static function init() {
		add_action( 'woocommerce_after_add_to_cart_button', array( __CLASS__, 'button' ) );
		// Runs before WC_Form_Handler::add_to_cart_action (wp_loaded, priority 20).
		add_action( 'wp_loaded', array( __CLASS__, 'map_request' ), 15 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'redirect' ), 99 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou_tracking' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'variation_js' ), 30 );
	}

	public static function button() {
		global $product;
		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}
		printf(
			'<button type="submit" name="pm_buy_now" value="%d" class="button alt pm-buy-now">Buy Now</button>',
			(int) $product->get_id()
		);
	}

	/** Simple products submit the product id only on the Add to Cart button; map Buy Now onto it. */
	public static function map_request() {
		if ( empty( $_REQUEST['pm_buy_now'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- same as WooCommerce add-to-cart.
			return;
		}
		if ( empty( $_REQUEST['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$id                     = absint( wp_unslash( $_REQUEST['pm_buy_now'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$_REQUEST['add-to-cart'] = $id;
			$_POST['add-to-cart']    = $id;
		}
	}

	public static function redirect( $url ) {
		if ( ! empty( $_REQUEST['pm_buy_now'] ) && function_exists( 'wc_get_checkout_url' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			// Only reached when the item was really added (WooCommerce calls this filter after a successful add).
			wc_clear_notices();
			return wc_get_checkout_url();
		}
		return $url;
	}

	/** Buy Now follows the same enabled/disabled state as Add to Cart for size (variation) choices. */
	public static function variation_js() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		?>
<script>
(function () {
  if (!window.jQuery) return;
  jQuery(function ($) {
    $('form.variations_form').each(function () {
      var $f = $(this), $b = $f.find('.pm-buy-now');
      if (!$b.length) return;
      $b.prop('disabled', true);
      $f.on('show_variation', function (e, v, purchasable) { $b.prop('disabled', !purchasable); })
        .on('hide_variation reset_data', function () { $b.prop('disabled', true); });
    });
  });
})();
</script>
		<?php
	}

	public static function thankyou_tracking( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		echo '<section class="pm-thankyou-track"><h2>Order progress</h2>';
		echo PM_Order_Statuses::render_timeline( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		echo '<p>Track this order any time at <a href="' . esc_url( PM_Frontend::tracking_url() ) . '">' . esc_html( PM_Frontend::tracking_url() ) . '</a> with order number <strong>' . esc_html( $order->get_order_number() ) . '</strong> and your billing email.</p></section>';
	}
}
