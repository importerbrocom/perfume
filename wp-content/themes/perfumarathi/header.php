<?php
/**
 * Site header: brand wordmark, menu, search, account and live shopping bag.
 */
defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link screen-reader-text" href="#pmt-content">Skip to content</a>
<div class="pmt-wrap">
<div class="pmt-topbar">12–24H LASTING &nbsp;•&nbsp; HIGH PROJECTION &nbsp;•&nbsp; TRUSTED SINCE 2015 &nbsp;•&nbsp; <a href="<?php echo esc_url( pmt_whatsapp_url() ); ?>" target="_blank" rel="noopener noreferrer">WhatsApp +971 52 820 9716</a></div>
<header class="pmt-header">
	<div class="pmt-container">
		<div class="pmt-header-inner">
			<button type="button" class="pmt-icon pmt-menu-toggle" aria-controls="pmt-nav" aria-expanded="false" aria-label="Open menu">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
			</button>
			<a class="pmt-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Perfumarathi home">
				<span class="pmt-brand-main">PERFUMARATHI</span>
				<span class="pmt-brand-tag">SECRET OF ATTRACTIVENESS</span>
			</a>
			<div class="pmt-actions">
				<?php if ( function_exists( 'wc_get_page_permalink' ) ) : ?>
				<form class="pmt-search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
					<label class="screen-reader-text" for="pmt-s">Search perfumes</label>
					<input id="pmt-s" type="search" name="s" placeholder="Search perfumes…" value="<?php echo esc_attr( get_search_query() ); ?>">
					<input type="hidden" name="post_type" value="product">
				</form>
				<a class="pmt-icon" href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" aria-label="My account">
					<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
				</a>
				<?php endif; ?>
				<?php echo shortcode_exists( 'pm_cart_icon' ) ? do_shortcode( '[pm_cart_icon]' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- plugin markup is static. ?>
			</div>
		</div>
	</div>
	<nav id="pmt-nav" class="pmt-nav" aria-label="Main menu">
		<div class="pmt-container">
			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'container'      => false,
				'fallback_cb'    => 'pmt_default_menu',
				'depth'          => 2,
			) );
			?>
		</div>
	</nav>
</header>
<main id="pmt-content" class="pmt-main">
