<?php get_header(); while (have_posts()): the_post();
$thanks = function_exists('br_contact_is_thanks') && br_contact_is_thanks();
if (!$thanks): ?>
<div class="page-hero"><?php echo br_picture('PXL_20220912_160655168.jpg', 'The Boardroom event space'); ?><h1><?php the_title(); ?></h1></div>
<div class="wrap"><div class="content">
  <?php echo br_contact_ask_button(true); ?>
  <?php if (function_exists('brb_tour_form')) echo brb_tour_form(); ?>
</div></div>
<?php else: ?>
<div class="wrap"><div class="content contact-thanks">
  <h2>Thank You!</h2>
  <p class="contact-thanks-line">We will call or email you shortly!</p>
  <p class="contact-thanks-next"><a class="btn" href="<?php echo esc_url(home_url('/rental-checklist/')); ?>">Rental Checklist</a></p>
  <?php echo br_contact_ask_button(); ?>
</div></div>
<?php endif; endwhile; get_footer();
