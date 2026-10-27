<?php get_header(); $p = br_photos(); $book = home_url('/book/'); ?>
<section class="hero" aria-label="Photos of The Boardroom">
  <?php foreach ($p as $i => $f) printf('<div class="slide%s" style="background-image:url(%s)"></div>', $i ? '' : ' on', esc_url(br_img($f))); ?>
  <div class="cap"><h1>Welcome to The Boardroom</h1><a class="btn" href="<?php echo esc_url($book); ?>">Book Now</a></div>
  <button class="arrow prev" aria-label="Previous">‹</button><button class="arrow next" aria-label="Next">›</button><div class="dots"></div>
</section>
<section class="block alt" id="amenities"><div class="wrap"><h2>Amenities</h2><div class="amen">
  <div class="card"><img src="<?php echo esc_url(br_img($p[2])); ?>" alt="Game room"><h3>80in Television, Pinball, Pool, Shuffleboard</h3></div>
  <div class="card"><img src="<?php echo esc_url(br_img($p[1])); ?>" alt="Bar"><h3>BYOB Bar and Ice Machine</h3></div>
  <div class="card"><img src="<?php echo esc_url(br_img($p[0])); ?>" alt="Event space"><h3>75 person Capacity, On-Site Parking.</h3></div>
</div></div></section>
<section class="block dark cta"><div class="wrap"><h2>Make Your Reservations Now</h2><p>Check availability and book The Boardroom directly.</p><a class="btn" href="<?php echo esc_url($book); ?>">Check Availability</a></div></section>
<section class="block" id="gallery"><div class="wrap"><h2>Photo Gallery</h2><div class="gallery gallery-strip">
  <?php foreach (array_slice(br_gallery(), 0, 6) as $f) printf('<a href="%s"><img src="%s" alt="The Boardroom" loading="lazy"></a>', esc_url(br_gallery_src($f)), esc_url(br_gallery_src($f, true))); ?>
</div><p class="gallery-more"><a class="btn" href="<?php echo esc_url(home_url('/gallery/')); ?>">View all photos</a></p></div></section>
<section class="block alt" id="contact"><div class="wrap"><h2>Contact the Boardroom</h2><div class="contact-grid">
  <div><h3>Inquire About Bookings</h3><p>Questions about your event or dates? Send us a message.</p>
    <?php the_post(); the_content(); ?></div>
  <div><?php echo br_contact_form(); ?></div></div></div></section>
<?php get_footer();
