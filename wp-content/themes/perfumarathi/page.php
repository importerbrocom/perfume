<?php
/**
 * Pages. Shop-style pages (shortcodes with their own heading) hide the page title.
 */
defined( 'ABSPATH' ) || exit;
get_header();
while ( have_posts() ) :
	the_post();
	$content  = get_post_field( 'post_content', get_the_ID() );
	$no_title = has_shortcode( $content, 'pm_collection' ) || has_shortcode( $content, 'pm_home_shop' );
	?>
	<div class="pmt-container pmt-entry">
		<?php if ( ! $no_title ) : ?>
			<h1 class="pmt-page-title"><?php the_title(); ?></h1>
		<?php endif; ?>
		<?php the_content(); ?>
	</div>
	<?php
endwhile;
get_footer();
