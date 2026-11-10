<?php
/**
 * Perfumarathi Canvas: page content only, no theme header/footer (equivalent of Elementor Canvas).
 */
defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<?php wp_head(); ?>
<style>body.pm-canvas{margin:0;background:#fff}</style>
</head>
<body <?php body_class( 'pm-canvas' ); ?>>
<?php
wp_body_open();
while ( have_posts() ) {
	the_post();
	the_content();
}
wp_footer();
?>
</body>
</html>
