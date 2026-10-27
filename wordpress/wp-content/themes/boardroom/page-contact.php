<?php get_header(); while (have_posts()): the_post(); ?>
<div class="page-hero" style="background-image:url(<?php echo esc_url(br_img('PXL_20220912_160655168.jpg')); ?>)"><h1><?php the_title(); ?></h1></div>
<div class="wrap"><div class="content">
  <p>The Boardroom QC<br><?php echo esc_html(function_exists('brb_venue_address') ? brb_venue_address() : '2220 37th Street, Moline, IL 61265'); ?></p>
  <p><a href="mailto:boardroomqc@gmail.com">boardroomqc@gmail.com</a></p>
  <?php if (function_exists('brb_map_embed')) echo brb_map_embed(); ?>
  <?php if (function_exists('brb_directions_markup')) echo brb_directions_markup(); ?>
  <?php if (function_exists('brb_tour_form')) echo brb_tour_form(); ?>
</div></div>
<?php endwhile; get_footer();
