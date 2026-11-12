<?php get_header(); while (have_posts()): the_post(); ?>
<div class="page-hero"><?php echo br_picture('PXL_20220912_160655168.jpg', 'The Boardroom event space'); ?><h1><?php the_title(); ?></h1></div>
<div class="wrap"><div class="content"><?php the_content(); ?><?php echo br_post_booking_html(); ?></div></div>
<?php endwhile; get_footer();
