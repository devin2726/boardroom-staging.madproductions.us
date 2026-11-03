<?php get_header(); while (have_posts()): the_post(); ?>
<div class="page-hero"><?php echo br_picture('PXL_20220912_160655168.jpg', 'The Boardroom event space'); ?><h1><?php echo is_page('book') ? 'Book it!' : get_the_title(); ?></h1></div>
<div class="wrap"><div class="content<?php echo is_page('book') ? ' wide' : ''; ?>"><?php the_content(); if (is_page('book')) echo br_book_shortcuts_html(); ?></div></div>
<?php endwhile; get_footer();
