<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin "Store Manager" menu (one place for the shop owner) and the "Stock & Prices" page:
 * edit regular price, sale price, stock quantity and stock status of every product and size on one screen.
 * Uses WooCommerce CRUD only (no SQL). Requires the edit_products capability + nonce.
 */
class PM_Stock {

	const SLUG     = 'pm-store';
	const PER_PAGE = 50;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_pm_stock_save', array( __CLASS__, 'save' ) );
	}

	private static function orders_url() {
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		return $hpos ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order';
	}

	public static function menu() {
		add_menu_page( 'Store Manager', 'Store Manager', 'edit_products', self::SLUG, array( 'PM_Admin', 'dashboard' ), 'dashicons-store', 3 );
		add_submenu_page( self::SLUG, 'Store Dashboard', 'Dashboard', 'manage_woocommerce', self::SLUG, array( 'PM_Admin', 'dashboard' ) );
		add_submenu_page( self::SLUG, 'Orders', 'Orders', 'edit_shop_orders', self::orders_url() );
		add_submenu_page( self::SLUG, 'Stock & Prices', 'Stock & Prices', 'edit_products', 'pm-stock', array( __CLASS__, 'page' ) );
		add_submenu_page( self::SLUG, 'Add Product', 'Add Product', 'edit_products', 'post-new.php?post_type=product' );
		add_submenu_page( self::SLUG, 'All Products', 'All Products', 'edit_products', 'edit.php?post_type=product' );
		add_submenu_page( self::SLUG, 'Customers', 'Customers', 'list_users', 'users.php?role=customer' );
		add_submenu_page( self::SLUG, 'Coupons', 'Coupons', 'manage_woocommerce', 'edit.php?post_type=shop_coupon' );
		add_submenu_page( self::SLUG, 'Shipping & Payments', 'Shipping & Payments', 'manage_woocommerce', 'admin.php?page=wc-settings&tab=shipping' );
	}

	/** Rows: simple products and each size (variation) of variable products. */
	public static function rows( $search = '', $paged = 1 ) {
		$q    = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => self::PER_PAGE,
			'paged'          => max( 1, (int) $paged ),
			's'              => $search,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		$rows = array();
		foreach ( $q->posts as $post ) {
			$p = wc_get_product( $post );
			if ( ! $p ) {
				continue;
			}
			if ( $p->is_type( 'variable' ) ) {
				$rows[] = array( 'product' => $p, 'parent' => true );
				foreach ( $p->get_children() as $vid ) {
					$v = wc_get_product( $vid );
					if ( $v ) {
						$rows[] = array( 'product' => $v, 'parent' => false, 'label' => wc_get_formatted_variation( $v, true, false, false ) );
					}
				}
			} elseif ( $p->is_type( array( 'simple', 'external' ) ) ) {
				$rows[] = array( 'product' => $p, 'parent' => false );
			}
		}
		return array( $rows, (int) $q->max_num_pages );
	}

	public static function page() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		list( $rows, $pages ) = self::rows( $search, $paged );
		$low    = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		$cur    = get_woocommerce_currency_symbol();
		echo '<div class="wrap"><h1 class="wp-heading-inline">Stock &amp; Prices</h1> <a class="page-title-action" href="' . esc_url( admin_url( 'post-new.php?post_type=product' ) ) . '">Add Product</a><hr class="wp-header-end">';
		if ( isset( $_GET['pm_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$n = absint( $_GET['pm_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( '%d product(s) updated.', $n ) ) . '</p></div>';
		}
		if ( isset( $_GET['pm_errors'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$msgs = get_transient( 'pm_stock_errors_' . get_current_user_id() );
			delete_transient( 'pm_stock_errors_' . get_current_user_id() );
			if ( $msgs ) {
				echo '<div class="notice notice-error"><p>' . implode( '<br>', array_map( 'esc_html', (array) $msgs ) ) . '</p></div>';
			}
		}
		echo '<p>Leave <strong>Regular price</strong> empty to keep a product on "Price on request" (no Add to Cart). Fill <strong>Stock</strong> to track quantity; leave it empty to set In/Out of stock by hand. Sizes (30 ml, 50 ml…) are edited on their own rows.</p>';
		echo '<form method="get"><input type="hidden" name="page" value="pm-stock"><p class="search-box"><label class="screen-reader-text" for="pm-stock-s">Search products</label><input type="search" id="pm-stock-s" name="s" value="' . esc_attr( $search ) . '"> <button class="button">Search</button></p></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="pm_stock_save">';
		echo '<input type="hidden" name="_back" value="' . esc_attr( add_query_arg( array( 'page' => 'pm-stock', 's' => $search, 'paged' => $paged ), admin_url( 'admin.php' ) ) ) . '">';
		wp_nonce_field( 'pm_stock_save', 'pm_stock_nonce' );
		echo '<style>.pm-stock td,.pm-stock th{vertical-align:middle}.pm-stock input[type=text]{width:100px}.pm-stock input.qty{width:80px}.pm-stock tr.is-parent td{background:#f6f7f7;font-weight:600}.pm-stock .low{color:#b32d2e;font-weight:700}.pm-stock img{width:40px;height:40px;object-fit:cover;border-radius:4px}</style>';
		echo '<table class="widefat striped pm-stock"><thead><tr><th></th><th>Product / size</th><th>SKU</th><th>Regular price (' . esc_html( $cur ) . ')</th><th>Sale price (' . esc_html( $cur ) . ')</th><th>Stock qty</th><th>Status (if qty empty)</th><th>Visible</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="8">No products found. <a href="' . esc_url( admin_url( 'post-new.php?post_type=product' ) ) . '">Add your first product</a>.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$p    = $r['product'];
			$id   = (int) $p->get_id();
			$name = $p->is_type( 'variation' ) ? '— ' . ( ! empty( $r['label'] ) ? $r['label'] : '#' . $id ) : $p->get_name();
			$edit = get_edit_post_link( $p->is_type( 'variation' ) ? $p->get_parent_id() : $id );
			$img  = $p->get_image_id() ? wp_get_attachment_image( $p->get_image_id(), array( 40, 40 ) ) : '';
			if ( $r['parent'] ) {
				echo '<tr class="is-parent"><td>' . wp_kses_post( $img ) . '</td><td colspan="7"><a href="' . esc_url( $edit ) . '">' . esc_html( $name ) . '</a> <span class="description">(sizes below)</span> · ' . esc_html( ucfirst( $p->get_status() ) ) . '</td></tr>';
				continue;
			}
			$qty    = $p->get_manage_stock() ? (string) $p->get_stock_quantity() : '';
			$islow  = '' !== $qty && (int) $qty <= $low;
			$f      = 'rows[' . $id . ']';
			echo '<tr>';
			echo '<td>' . wp_kses_post( $img ) . '</td>';
			echo '<td><a href="' . esc_url( $edit ) . '">' . esc_html( $name ) . '</a>' . ( $p->is_type( 'variation' ) ? '' : '<br><span class="description">' . esc_html( ucfirst( $p->get_status() ) ) . '</span>' ) . '</td>';
			echo '<td>' . esc_html( $p->get_sku() ) . '</td>';
			echo '<td><input type="text" inputmode="decimal" name="' . esc_attr( $f ) . '[regular]" value="' . esc_attr( $p->get_regular_price() ) . '" aria-label="Regular price for ' . esc_attr( $name ) . '"></td>';
			echo '<td><input type="text" inputmode="decimal" name="' . esc_attr( $f ) . '[sale]" value="' . esc_attr( $p->get_sale_price() ) . '" aria-label="Sale price for ' . esc_attr( $name ) . '"></td>';
			echo '<td><input class="qty' . ( $islow ? ' low' : '' ) . '" type="number" min="0" step="1" name="' . esc_attr( $f ) . '[qty]" value="' . esc_attr( $qty ) . '" aria-label="Stock for ' . esc_attr( $name ) . '">' . ( $islow ? ' <span class="low">Low</span>' : '' ) . '</td>';
			echo '<td><select name="' . esc_attr( $f ) . '[status]" aria-label="Stock status for ' . esc_attr( $name ) . '">';
			foreach ( array( 'instock' => 'In stock', 'outofstock' => 'Out of stock', 'onbackorder' => 'On backorder' ) as $k => $v ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( $p->get_stock_status(), $k, false ) . '>' . esc_html( $v ) . '</option>';
			}
			echo '</select></td>';
			echo '<td>' . ( $p->is_type( 'variation' ) ? '' : '<input type="hidden" name="' . esc_attr( $f ) . '[publish]" value="0"><input type="checkbox" name="' . esc_attr( $f ) . '[publish]" value="1"' . checked( 'publish', $p->get_status(), false ) . ' aria-label="Show ' . esc_attr( $name ) . ' in the shop">' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( $pages > 1 ) {
			echo '<p>' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( array( 'page' => 'pm-stock', 's' => rawurlencode( $search ), 'paged' => '%#%' ), admin_url( 'admin.php' ) ), 'current' => $paged, 'total' => $pages ) ) ) . '</p>';
		}
		submit_button( 'Save changes' );
		echo '</form></div>';
	}

	/**
	 * Applies submitted rows. Returns array( updated count, error messages ). Only changed fields are saved.
	 * Public so CI can test it; still checks the capability itself.
	 */
	public static function apply_rows( array $rows ) {
		if ( ! current_user_can( 'edit_products' ) ) {
			return array( 0, array( 'Not allowed.' ) );
		}
		$updated = 0;
		$errors  = array();
		foreach ( array_slice( $rows, 0, 500, true ) as $id => $r ) {
			$p = wc_get_product( absint( $id ) );
			if ( ! $p || $p->is_type( 'variable' ) || ! is_array( $r ) ) {
				continue;
			}
			$check = $p->is_type( 'variation' ) ? $p->get_parent_id() : $p->get_id();
			if ( ! current_user_can( 'edit_product', $check ) ) {
				continue;
			}
			$name    = $p->get_name();
			$regular = isset( $r['regular'] ) ? trim( sanitize_text_field( wp_unslash( $r['regular'] ) ) ) : $p->get_regular_price();
			$sale    = isset( $r['sale'] ) ? trim( sanitize_text_field( wp_unslash( $r['sale'] ) ) ) : $p->get_sale_price();
			$qty     = isset( $r['qty'] ) ? trim( sanitize_text_field( wp_unslash( $r['qty'] ) ) ) : null;
			$status  = isset( $r['status'] ) ? sanitize_key( $r['status'] ) : $p->get_stock_status();
			foreach ( array( 'regular' => $regular, 'sale' => $sale ) as $label => $val ) {
				if ( '' !== $val && ( ! is_numeric( wc_format_decimal( $val ) ) || (float) wc_format_decimal( $val ) < 0 ) ) {
					$errors[] = sprintf( '%s: %s price "%s" is not a valid amount; row skipped.', $name, $label, $val );
					continue 2;
				}
			}
			$regular = '' === $regular ? '' : wc_format_decimal( $regular );
			$sale    = '' === $sale ? '' : wc_format_decimal( $sale );
			if ( '' !== $sale && ( '' === $regular || (float) $sale >= (float) $regular ) ) {
				$errors[] = sprintf( '%s: sale price must be lower than the regular price; sale price not saved.', $name );
				$sale     = $p->get_sale_price();
				if ( '' !== $sale && ( '' === $regular || (float) $sale >= (float) $regular ) ) {
					$sale = '';
				}
			}
			if ( null !== $qty && '' !== $qty && ( ! ctype_digit( $qty ) ) ) {
				$errors[] = sprintf( '%s: stock "%s" must be a whole number of 0 or more; row skipped.', $name, $qty );
				continue;
			}
			if ( ! in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
				$status = $p->get_stock_status();
			}
			$before = array( $p->get_regular_price(), $p->get_sale_price(), $p->get_manage_stock(), $p->get_stock_quantity(), $p->get_stock_status(), $p->get_status() );
			$p->set_regular_price( $regular );
			$p->set_sale_price( $sale );
			if ( null !== $qty ) {
				if ( '' === $qty ) {
					$p->set_manage_stock( false );
					$p->set_stock_status( $status );
				} else {
					$p->set_manage_stock( true );
					$p->set_stock_quantity( (int) $qty ); // WooCommerce sets in/out of stock from the quantity.
				}
			}
			if ( isset( $r['publish'] ) && ! $p->is_type( 'variation' ) ) {
				$want = '1' === (string) $r['publish'] ? 'publish' : ( 'publish' === $p->get_status() ? 'draft' : $p->get_status() );
				if ( $want !== $p->get_status() && current_user_can( 'publish_products' ) ) {
					$p->set_status( $want );
				}
			}
			$after = array( $p->get_regular_price(), $p->get_sale_price(), $p->get_manage_stock(), $p->get_stock_quantity(), $p->get_stock_status(), $p->get_status() );
			if ( $before !== $after ) {
				$p->save();
				if ( $p->is_type( 'variation' ) ) {
					WC_Product_Variable::sync( $p->get_parent_id() );
				}
				$updated++;
			}
		}
		delete_transient( 'pm_dash_stats' );
		return array( $updated, $errors );
	}

	public static function save() {
		if ( ! current_user_can( 'edit_products' ) || ! isset( $_POST['pm_stock_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pm_stock_nonce'] ) ), 'pm_stock_save' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		$rows = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? $_POST['rows'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field is sanitized in apply_rows().
		list( $n, $errors ) = self::apply_rows( $rows );
		$back = isset( $_POST['_back'] ) ? esc_url_raw( wp_unslash( $_POST['_back'] ) ) : admin_url( 'admin.php?page=pm-stock' );
		$back = wp_validate_redirect( $back, admin_url( 'admin.php?page=pm-stock' ) );
		$back = add_query_arg( 'pm_saved', $n, $back );
		if ( $errors ) {
			set_transient( 'pm_stock_errors_' . get_current_user_id(), array_slice( $errors, 0, 30 ), 5 * MINUTE_IN_SECONDS );
			$back = add_query_arg( 'pm_errors', 1, $back );
		}
		wp_safe_redirect( $back );
		exit;
	}
}
