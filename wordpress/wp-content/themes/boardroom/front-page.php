<?php get_header(); $p = br_photos(); $book = brb_book_url(); ?>
<section class="hero" aria-label="Photos of The Boardroom">
  <?php foreach ($p as $i => $f) printf('<div class="slide%s">%s</div>', $i ? '' : ' on', br_picture($f, br_photo_alt($f))); ?>
  <div class="cap"><h1 class="hero-logo"><img src="<?php echo esc_url(br_img_ver('boardroom-logo-hero.png')); ?>" srcset="<?php echo esc_url(br_img_ver('boardroom-logo-hero.png')); ?> 1x, <?php echo esc_url(br_img_ver('boardroom-logo-hero-2x.png')); ?> 2x" width="354" height="360" alt="The Boardroom – Private Event Center" decoding="async"></h1><p class="hero-sub">Moline's one-of-a-kind party venue</p><a class="btn" href="<?php echo esc_url($book); ?>">Reserve Now</a></div>
  <button class="arrow prev" aria-label="Previous">‹</button><button class="arrow next" aria-label="Next">›</button><div class="dots"></div>
</section>
<?php echo br_facts_strip(['Serving Kitchen', 'Up to 80 Guests', 'Game Room', '80-Inch TV', 'Ample Parking'], 2); ?>
<section class="block" id="check-your-date"><div class="wrap">
<h2>Check Your Date</h2>
<?php if (function_exists('brb_home_calendar_html')) echo brb_home_calendar_html(); ?>
<p class="home-cal-more"><a href="<?php echo esc_url($book); ?>">See full booking details</a></p>
</div></section>
<?php echo br_tour_video_html(br_ask_walter_card(home_url('/contact/'))); ?>
<section class="block alt" id="amenities"><div class="wrap"><h2>Amenities</h2><div class="amen">
  <div class="card"><?php echo br_picture($p[2], 'The Boardroom game room with pinball, pool, and shuffleboard'); ?><h3>80-inch TV, Pinball, Pool, Shuffleboard</h3></div>
  <div class="card"><?php echo br_picture($p[1], 'The Boardroom BYOB bar and ice machine'); ?><h3>BYOB Bar and Ice Machine</h3></div>
  <div class="card"><?php echo br_picture($p[0], 'The Boardroom event space with room for 80 guests'); ?><h3>80 guests, On-site parking</h3></div>
</div>
<?php echo br_amenities_html(); ?>
</div></section>
<?php echo br_reviews_html(); ?>
<section class="block dark cta"><div class="wrap"><h2>Make Your Reservations Now</h2><p>Check availability and book The Boardroom directly.</p><a class="btn" href="<?php echo esc_url($book); ?>">Check Availability</a></div></section>
<section class="block" id="gallery"><div class="wrap"><h2>Photo Gallery</h2><div class="gallery gallery-strip">
  <?php foreach (array_slice(br_gallery(), 0, 6) as $f) printf('<a href="%s"><img src="%s" alt="%s" loading="lazy"></a>', esc_url(br_gallery_src($f)), esc_url(br_gallery_src($f, true)), esc_attr(br_gallery_alt($f))); ?>
</div><p class="gallery-more"><a class="btn" href="<?php echo esc_url(home_url('/gallery/')); ?>">View all photos</a></p></div></section>
<?php get_footer();
