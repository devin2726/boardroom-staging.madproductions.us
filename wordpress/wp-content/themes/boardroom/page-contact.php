<?php get_header(); while (have_posts()): the_post(); ?>
<div class="page-hero"><?php echo br_picture('PXL_20220912_160655168.jpg', 'The Boardroom event space'); ?><h1><?php the_title(); ?></h1></div>
<div class="wrap"><div class="content">
  <p>Email <a href="mailto:boardroomqc@gmail.com">boardroomqc@gmail.com</a></p>
  <?php if (function_exists('brb_chat_markup')) echo brb_chat_markup('inline'); ?>
  <?php if (function_exists('brb_tour_form')) echo brb_tour_form(); ?>
</div></div>
<?php endwhile; get_footer();
