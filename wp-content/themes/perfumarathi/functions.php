<?php
/**
 * Perfumarathi theme setup.
 */
defined( 'ABSPATH' ) || exit;

define( 'PMT_VERSION', '1.0.0' );
define( 'PMT_WHATSAPP', '971528209716' );

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'woocommerce', array( 'thumbnail_image_width' => 600, 'single_image_width' => 900 ) );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );
	register_nav_menus( array(
		'primary' => 'Header menu',
		'footer'  => 'Footer menu',
	) );
} );

add_action( 'wp_enqueue_scripts', function () {
	$dir = get_template_directory();
	wp_enqueue_style( 'perfumarathi', get_stylesheet_uri(), array(), PMT_VERSION . '.' . (int) @filemtime( $dir . '/style.css' ) );
	wp_enqueue_script( 'perfumarathi-nav', get_template_directory_uri() . '/assets/nav.js', array(), PMT_VERSION . '.' . (int) @filemtime( $dir . '/assets/nav.js' ), true );
} );

/** WhatsApp link with a prefilled message. */
function pmt_whatsapp_url( $text = '' ) {
	$text = $text ? $text : "Hi Perfumarathi, I'd like to get the price and details for your perfumes.";
	return 'https://wa.me/' . PMT_WHATSAPP . '?text=' . rawurlencode( $text );
}

/** Page URL by slug, falling back to a default URL. */
function pmt_page_url( $slug, $fallback = '' ) {
	$p = get_page_by_path( $slug );
	return ( $p && 'publish' === $p->post_status ) ? get_permalink( $p ) : ( $fallback ? $fallback : home_url( '/' ) );
}

/** Default header menu (used until a menu is assigned in Appearance > Menus). */
function pmt_default_menu() {
	$items = array(
		'Home'           => home_url( '/' ),
		'Perfumarathi'   => pmt_page_url( 'shop-perfumarathi' ),
		'Fixnote'        => pmt_page_url( 'shop-fixnote' ),
		'All Perfumes'   => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ),
		'Track Order'    => pmt_page_url( 'track-order' ),
		'My Account'     => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url(),
	);
	$t = get_term_by( 'slug', 'combos', 'product_cat' );
	if ( $t ) {
		$items = array_slice( $items, 0, 3, true ) + array( 'Combos' => get_term_link( $t ) ) + array_slice( $items, 3, null, true );
	}
	echo '<ul>';
	foreach ( $items as $label => $url ) {
		if ( is_wp_error( $url ) ) {
			continue;
		}
		echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
	}
	echo '</ul>';
}

/* WooCommerce: remove default wrappers (woocommerce.php provides them) and keep the sidebar off. */
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );
add_filter( 'loop_shop_columns', function () { return 3; } );
