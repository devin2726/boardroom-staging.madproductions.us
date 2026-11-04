<?php get_header(); while (have_posts()): the_post(); $book_page = function_exists('br_is_book_page') && br_is_book_page(); ?>
<div class="page-hero"><?php echo br_picture('PXL_20220912_160655168.jpg', 'The Boardroom event space'); ?><h1><?php echo $book_page ? 'Book the Boardroom' : get_the_title(); ?></h1></div>
<div class="wrap"><?php if (is_page('faq')) echo br_ask_walter_card(home_url('/contact/')); ?><div class="content<?php echo $book_page ? ' wide' : ''; ?>"><?php the_content();
if ($book_page) echo br_ask_walter_card(home_url('/contact/#contact-inquiry'));
?></div></div>
<?php endwhile; get_footer();
