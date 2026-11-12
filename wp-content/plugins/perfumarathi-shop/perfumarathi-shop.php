<?php
/**
 * Plugin Name: Perfumarathi & Fixnote Shop
 * Description: Extends WooCommerce for the Perfumarathi & Fixnote brands: brand taxonomy, fragrance notes, extra order statuses with tracking, storefront shortcodes, cart drawer and REST helpers. Uses native WooCommerce products, cart, checkout and orders (no duplicate data).
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: pm-shop
 */

defined( 'ABSPATH' ) || exit;

define( 'PM_SHOP_VERSION', '1.0.0' );
define( 'PM_SHOP_DIR', plugin_dir_path( __FILE__ ) );
define( 'PM_SHOP_URL', plugin_dir_url( __FILE__ ) );

require_once PM_SHOP_DIR . 'includes/class-pm-catalog.php';
require_once PM_SHOP_DIR . 'includes/class-pm-order-statuses.php';
require_once PM_SHOP_DIR . 'includes/class-pm-rest.php';
require_once PM_SHOP_DIR . 'includes/class-pm-frontend.php';
require_once PM_SHOP_DIR . 'includes/class-pm-extras.php';
require_once PM_SHOP_DIR . 'includes/class-pm-admin.php';
require_once PM_SHOP_DIR . 'includes/class-pm-brand-home.php';
require_once PM_SHOP_DIR . 'includes/class-pm-collection.php';
require_once PM_SHOP_DIR . 'includes/class-pm-buy-now.php';
require_once PM_SHOP_DIR . 'includes/class-pm-stock.php';

register_activation_hook( __FILE__, array( 'PM_Catalog', 'activate' ) );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>Perfumarathi Shop requires WooCommerce to be active.</p></div>';
		} );
		return;
	}
	PM_Catalog::init();
	PM_Order_Statuses::init();
	PM_REST::init();
	PM_Frontend::init();
	PM_Extras::init();
	PM_Admin::init();
	PM_Brand_Home::init();
	PM_Collection::init();
	PM_Buy_Now::init();
	PM_Stock::init();
} );

// Declare HPOS compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
