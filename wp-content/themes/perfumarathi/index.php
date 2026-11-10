<?php
/**
 * Fallback template (posts, archives, search).
 */
defined( 'ABSPATH' ) || exit;
get_header();
?>
<div class="pmt-container pmt-entry">
	<?php if ( is_search() ) : ?>
		<h1 class="pmt-page-title">Search: <?php echo esc_html( get_search_query() ); ?></h1>
	<?php elseif ( is_archive() ) : ?>
		<h1 class="pmt-page-title"><?php echo esc_html( wp_strip_all_tags( get_the_archive_title() ) ); ?></h1>
	<?php endif; ?>
	<?php if ( have_posts() ) : ?>
		<?php while ( have_posts() ) : the_post(); ?>
			<article <?php post_class(); ?>>
				<?php if ( ! is_singular() ) : ?>
					<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
					<?php the_excerpt(); ?>
				<?php else : ?>
					<h1 class="pmt-page-title"><?php the_title(); ?></h1>
					<?php the_content(); ?>
				<?php endif; ?>
			</article>
		<?php endwhile; ?>
		<?php the_posts_pagination(); ?>
	<?php else : ?>
		<p style="text-align:center">Nothing found.</p>
	<?php endif; ?>
</div>
<?php
get_footer();
