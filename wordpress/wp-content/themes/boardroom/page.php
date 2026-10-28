<?php get_header(); while (have_posts()): the_post(); ?>
<div class="page-hero" style="background-image:url(<?php echo esc_url(br_img('PXL_20220912_160655168.jpg')); ?>)"><h1><?php the_title(); ?></h1></div>
<div class="wrap"><div class="content<?php echo is_page('book') ? ' wide' : ''; ?>"><?php if (is_page('book')) echo '<p class="book-amenities"><a href="' . esc_url(home_url('/#amenities')) . '">See the amenities</a></p>'; the_content(); ?></div></div>
<?php endwhile; get_footer();
