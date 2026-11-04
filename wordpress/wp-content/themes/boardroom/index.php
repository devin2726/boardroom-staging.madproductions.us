<?php get_header(); while (have_posts()): the_post(); ?>
<div class="page-hero"><?php echo br_picture('PXL_20220912_160655168.jpg', 'The Boardroom event space'); ?><h1><?php the_title(); ?></h1></div>
<div class="wrap"><div class="content<?php echo (function_exists('br_is_book_page') && br_is_book_page()) ? ' wide' : ''; ?>"><?php the_content(); ?></div></div>
<?php endwhile; get_footer();
