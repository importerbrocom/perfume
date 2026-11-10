<?php
defined( 'ABSPATH' ) || exit;

/**
 * [pm_brand_home]: the existing Perfumarathi & Fixnote brand homepage (video, two brand halves,
 * social section, footer; same markup/CSS as the live Elementor widget) with the shop sections
 * ([pm_home_shop]) placed between the brand halves and the social section, plus the bag icon.
 *
 * Also adds a "Perfumarathi Canvas" page template (no theme header/footer), like Elementor Canvas on the live site.
 *
 * Assets: wp-content/uploads/pm-brand/{IMG_2610.png, IMG_2609.jpg, IMG_2654.mp4} when present
 * (copied by server/setup-brand-home.sh), otherwise the original URLs.
 */
class PM_Brand_Home {

	const TEMPLATE = 'pm-canvas.php';
	const ORIGINAL = 'https://perfumarathi.com/wp-content/uploads/2026/10/';
	const FILES    = array( 'logo_perf' => 'IMG_2610.png', 'logo_fix' => 'IMG_2609.jpg', 'video' => 'IMG_2654.mp4' );

	public static function init() {
		add_shortcode( 'pm_brand_home', array( __CLASS__, 'render' ) );
		add_filter( 'theme_page_templates', array( __CLASS__, 'register_template' ) );
		add_filter( 'template_include', array( __CLASS__, 'use_template' ), 99 );
	}

	public static function asset( $key ) {
		$file = self::FILES[ $key ];
		$up   = wp_get_upload_dir();
		if ( file_exists( trailingslashit( $up['basedir'] ) . 'pm-brand/' . $file ) ) {
			return trailingslashit( $up['baseurl'] ) . 'pm-brand/' . $file;
		}
		return self::ORIGINAL . $file;
	}

	/** Brand shop page if it exists (staging creates /shop-perfumarathi/), else the brand archive, else the shop. */
	public static function brand_url( $brand ) {
		$page = get_page_by_path( 'shop-' . $brand );
		if ( $page && 'publish' === $page->post_status ) {
			return get_permalink( $page );
		}
		$t = get_term_link( $brand, 'pm_brand' );
		if ( ! is_wp_error( $t ) ) {
			return $t;
		}
		return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
	}

	public static function render() {
		$tpl = PM_SHOP_DIR . 'templates/brand-home.html';
		if ( ! is_readable( $tpl ) ) {
			return '';
		}
		$perf = self::brand_url( 'perfumarathi' );
		$fix  = self::brand_url( 'fixnote' );
		return strtr( (string) file_get_contents( $tpl ), array(
			'{{LOGO_PERF}}'  => esc_url( self::asset( 'logo_perf' ) ),
			'{{LOGO_FIX}}'   => esc_url( self::asset( 'logo_fix' ) ),
			'{{VIDEO}}'      => esc_url( self::asset( 'video' ) ),
			'{{ENTER_PERF}}' => esc_url( $perf ),
			'{{ENTER_FIX}}'  => esc_url( $fix ),
			'{{SHOP_PERF}}'  => esc_url( $perf ),
			'{{SHOP_FIX}}'   => esc_url( $fix ),
			'{{HOME_SHOP}}'  => PM_Frontend::sc_home(), // escaped internally.
		) );
	}

	public static function register_template( $templates ) {
		$templates[ self::TEMPLATE ] = 'Perfumarathi Canvas (no theme header/footer)';
		return $templates;
	}

	public static function use_template( $template ) {
		if ( is_singular( 'page' ) && self::TEMPLATE === get_page_template_slug( get_queried_object_id() ) ) {
			return PM_SHOP_DIR . 'templates/canvas.php';
		}
		return $template;
	}
}
