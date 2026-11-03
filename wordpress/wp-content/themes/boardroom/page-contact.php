<?php get_header(); while (have_posts()): the_post(); ?>
<div class="page-hero"><?php echo br_picture('PXL_20220912_160655168.jpg', 'The Boardroom event space'); ?><h1><?php the_title(); ?></h1></div>
<div class="wrap"><div class="content">
  <p class="contact-ask-wrap"><button type="button" class="contact-ask" data-brb-open-chat><?php if (function_exists('brb_walter_html')) echo brb_walter_html(64, 'brb-walter brb-walter-contact'); ?><span>Ask Walter</span></button></p>
  <?php if (function_exists('brb_tour_form')) echo brb_tour_form(); ?>
</div></div>
<?php endwhile; get_footer();
