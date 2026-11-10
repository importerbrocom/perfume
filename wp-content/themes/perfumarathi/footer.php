<?php
/**
 * Site footer: "Delivery Across" band, links, social media, sticky WhatsApp button.
 */
defined( 'ABSPATH' ) || exit;
?>
</main>
<section class="pmt-delivery" aria-label="Delivery">
	<h2>Delivery Across</h2>
	<div class="pmt-places">UAE • SAUDI ARABIA • QATAR • KUWAIT • OMAN • BAHRAIN<br>INDIA / KERALA • GCC • WORLDWIDE</div>
	<p>For delivery details and availability, contact us on WhatsApp.</p>
	<a class="pmt-cta" href="<?php echo esc_url( pmt_whatsapp_url() ); ?>" target="_blank" rel="noopener noreferrer">WHATSAPP PERFUMARATHI →</a>
</section>
<footer class="pmt-footer">
	<?php
	if ( has_nav_menu( 'footer' ) ) {
		wp_nav_menu( array( 'theme_location' => 'footer', 'container' => false, 'menu_class' => 'pmt-footer-links', 'depth' => 1 ) );
	} elseif ( function_exists( 'wc_get_page_permalink' ) ) {
		echo '<ul class="pmt-footer-links">';
		foreach ( array(
			'Shop'        => wc_get_page_permalink( 'shop' ),
			'Track Order' => pmt_page_url( 'track-order' ),
			'Wishlist'    => pmt_page_url( 'wishlist' ),
			'My Account'  => wc_get_page_permalink( 'myaccount' ),
			'Cart'        => wc_get_cart_url(),
		) as $label => $url ) {
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}
		$terms = (int) wc_terms_and_conditions_page_id();
		if ( $terms ) {
			echo '<li><a href="' . esc_url( get_permalink( $terms ) ) . '">Terms &amp; Conditions</a></li>';
		}
		echo '</ul>';
	}
	?>
	<nav class="pmt-socials" aria-label="Social media">
		<a href="https://www.instagram.com/perfumarathi/" target="_blank" rel="noopener noreferrer">Instagram</a>
		<a href="https://www.facebook.com/share/19hAJq7n2X/?mibextid=wwXIfr" target="_blank" rel="noopener noreferrer">Facebook</a>
		<a href="https://www.tiktok.com/@perfumarathi" target="_blank" rel="noopener noreferrer">TikTok</a>
		<a href="https://youtube.com/@perfumarathi" target="_blank" rel="noopener noreferrer">YouTube</a>
		<a href="<?php echo esc_url( pmt_whatsapp_url() ); ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a>
	</nav>
	<div>PERFUMARATHI • SECRET OF ATTRACTIVENESS<br>WhatsApp: +971 52 820 9716</div>
	<div style="margin-top:8px;font-size:12px">© <?php echo esc_html( gmdate( 'Y' ) ); ?> Perfumarathi &amp; Fixnote. All rights reserved. · UAE · GCC · Kerala · India · International</div>
</footer>
<div class="pmt-sticky"><a href="<?php echo esc_url( pmt_whatsapp_url() ); ?>" target="_blank" rel="noopener noreferrer">WhatsApp • Get Price &amp; Order</a></div>
</div>
<?php wp_footer(); ?>
</body>
</html>
