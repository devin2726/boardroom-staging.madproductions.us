<?php get_header(); while (have_posts()): the_post(); ?>
<div class="page-hero" style="background-image:url(<?php echo esc_url(br_img('PXL_20220912_160655168.jpg')); ?>)"><h1><?php the_title(); ?></h1></div>
<div class="wrap"><div class="content">
  <?php if (function_exists('brb_chat_markup')) echo brb_chat_markup('inline'); ?>
  <?php if (function_exists('brb_tour_form')) echo brb_tour_form(); ?>
  <?php if (function_exists('brb_map_embed')) echo brb_map_embed(); ?>
</div></div>
<?php endwhile; get_footer();
