<?php
/**
 * WooCommerce shop, category, brand and single product pages inside the brand header/footer.
 */
defined( 'ABSPATH' ) || exit;
get_header();
?>
<div class="pmt-container pmt-entry">
	<?php woocommerce_content(); ?>
</div>
<?php
get_footer();
