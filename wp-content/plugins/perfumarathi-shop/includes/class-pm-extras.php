<?php
defined( 'ABSPATH' ) || exit;

/**
 * Customer-facing extras: account-synced wishlist, frequently-bought-together,
 * product video, brand in Product schema, and admin-area lockout for customers.
 */
class PM_Extras {

	const WISH_META = '_pm_wishlist';
	const FBT_META  = '_pm_fbt_ids';
	const VID_META  = '_pm_video_url';

	public static function init() {
		// Wishlist
		add_action( 'rest_api_init', array( __CLASS__, 'wish_routes' ) );
		add_shortcode( 'pm_wishlist', array( __CLASS__, 'sc_wishlist' ) );
		// Product meta (FBT + video)
		add_action( 'woocommerce_product_options_related', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_fields' ) );
		add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'render_video' ), 8 );
		add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'render_fbt' ), 12 );
		// SEO
		add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'schema_brand' ), 10, 2 );
		// Access control
		add_action( 'admin_init', array( __CLASS__, 'block_customers_from_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'invoice_action' ), 10, 2 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu' ) );
		add_filter( 'woocommerce_get_endpoint_url', array( __CLASS__, 'wish_endpoint_url' ), 10, 2 );
	}

	/* ---------- Wishlist ---------- */

	public static function wish_routes() {
		register_rest_route( PM_REST::NS, '/wishlist', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'wish_get' ), 'permission_callback' => 'is_user_logged_in' ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'wish_set' ), 'permission_callback' => 'is_user_logged_in' ),
		) );
	}

	private static function clean_ids( $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		return array_slice( $ids, 0, 200 );
	}

	public static function wish_get() {
		$ids = get_user_meta( get_current_user_id(), self::WISH_META, true );
		return array( 'ids' => self::clean_ids( $ids ) );
	}

	public static function wish_set( WP_REST_Request $r ) {
		$ids = self::clean_ids( $r->get_param( 'ids' ) );
		// Only keep real, published products.
		$ids = array_values( array_filter( $ids, function ( $id ) {
			return 'product' === get_post_type( $id ) && 'publish' === get_post_status( $id );
		} ) );
		update_user_meta( get_current_user_id(), self::WISH_META, $ids );
		return array( 'ids' => $ids );
	}

	public static function sc_wishlist() {
		return '<section class="pm-shop pm-wishlist-page" data-pm-wishlist><h2 class="pm-home__title">My Wishlist</h2><div class="pm-grid" aria-live="polite"></div></section>';
	}

	public static function wish_endpoint_url( $url, $endpoint ) {
		if ( 'pm-wishlist' === $endpoint ) {
			$id = (int) get_option( 'pm_wishlist_page_id' );
			return $id ? get_permalink( $id ) : $url;
		}
		return $url;
	}

	public static function account_menu( $items ) {
		$id = (int) get_option( 'pm_wishlist_page_id' );
		if ( $id && get_post_status( $id ) ) {
			$logout = isset( $items['customer-logout'] ) ? array( 'customer-logout' => $items['customer-logout'] ) : array();
			unset( $items['customer-logout'] );
			// Link item handled via filter below so it points at the wishlist page.
			$items['pm-wishlist'] = 'Wishlist';
			$items               += $logout;
		}
		return $items;
	}

	/* ---------- Product fields ---------- */

	public static function product_fields() {
		woocommerce_wp_text_input( array(
			'id'          => self::FBT_META,
			'label'       => 'Frequently bought together',
			'description' => 'Comma-separated product IDs (max 4).',
			'desc_tip'    => true,
		) );
		woocommerce_wp_text_input( array(
			'id'          => self::VID_META,
			'label'       => 'Product video URL',
			'description' => 'YouTube/Vimeo link or a direct .mp4 URL (https only).',
			'desc_tip'    => true,
			'type'        => 'url',
		) );
	}

	public static function save_product_fields( $post_id ) {
		// WooCommerce verifies its own nonce before firing this action; re-check capability.
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		$raw = isset( $_POST[ self::FBT_META ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FBT_META ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$ids = array_slice( self::clean_ids( explode( ',', $raw ) ), 0, 4 );
		$ids = array_values( array_diff( $ids, array( $post_id ) ) );
		update_post_meta( $post_id, self::FBT_META, implode( ',', $ids ) );

		$url = isset( $_POST[ self::VID_META ] ) ? esc_url_raw( wp_unslash( $_POST[ self::VID_META ] ), array( 'https' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		update_post_meta( $post_id, self::VID_META, $url );
	}

	public static function render_video() {
		global $product;
		if ( ! $product ) {
			return;
		}
		$url = (string) get_post_meta( $product->get_id(), self::VID_META, true );
		if ( ! $url ) {
			return;
		}
		echo '<div class="pm-video">';
		if ( preg_match( '/\.(mp4|webm)(\?.*)?$/i', $url ) ) {
			echo '<video controls preload="metadata" playsinline src="' . esc_url( $url ) . '"></video>';
		} else {
			$embed = wp_oembed_get( $url, array( 'width' => 800 ) );
			echo $embed ? $embed : '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">Watch product video</a>'; // phpcs:ignore WordPress.Security.EscapeOutput -- oEmbed HTML is filtered by WP.
		}
		echo '</div>';
	}

	public static function render_fbt() {
		global $product;
		if ( ! $product ) {
			return;
		}
		$ids = self::clean_ids( explode( ',', (string) get_post_meta( $product->get_id(), self::FBT_META, true ) ) );
		if ( ! $ids ) {
			return;
		}
		array_unshift( $ids, $product->get_id() );
		echo '<section class="pm-fbt" data-ids="' . esc_attr( implode( ',', $ids ) ) . '" data-current="' . esc_attr( $product->get_id() ) . '"><h2>Frequently bought together</h2><div class="pm-fbt__list"></div></section>';
	}

	public static function schema_brand( $markup, $product ) {
		$brand = PM_Catalog::get_brand( $product->get_id() );
		if ( $brand ) {
			$markup['brand'] = array( '@type' => 'Brand', 'name' => $brand->name );
		}
		return $markup;
	}

	/* ---------- Access control ---------- */

	/** Customers (no edit_posts capability) cannot use wp-admin; AJAX stays available for the storefront. */
	public static function block_customers_from_admin() {
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';
		if ( 'admin-post.php' === $script ) {
			return; // front-end form handlers (newsletter, invoice) check their own permissions.
		}
		if ( is_user_logged_in() && ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_safe_redirect( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ) );
			exit;
		}
	}

	public static function admin_bar( $show ) {
		return ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_woocommerce' ) ) ? $show : false;
	}

	/* ---------- Invoice link for customers ---------- */

	public static function invoice_action( $actions, $order ) {
		$actions['pm_invoice'] = array(
			'url'  => PM_Admin::invoice_url( $order->get_id() ),
			'name' => 'Invoice',
		);
		return $actions;
	}
}
