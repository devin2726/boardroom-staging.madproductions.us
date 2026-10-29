<footer class="site-footer"><div class="footer-map-wrap"><?php echo br_footer_map(); ?></div><a class="footer-brand" href="<?php echo esc_url(home_url('/')); ?>"><?php echo br_logo('footer'); ?></a><p>The Boardroom QC<br>2220 37th Street, Moline, IL 61265<br><a href="<?php echo esc_url(defined('BRB_MAPS_URL') ? BRB_MAPS_URL : 'https://maps.app.goo.gl/nVqUsGTcy48nPzc18'); ?>" rel="noopener noreferrer">Get directions</a></p>
<p>Copyright © <?php echo date('Y'); ?> The Boardroom - All Rights Reserved.</p>
<p><?php wp_nav_menu(['theme_location' => 'footer', 'container' => false, 'items_wrap' => '<ul class="fmenu">%3$s</ul>', 'fallback_cb' => false, 'depth' => 1]); ?></p>
<nav class="legal-links" aria-label="Legal"><a href="<?php echo esc_url(home_url('/rental-checklist/')); ?>">Rental Checklist</a><a href="<?php echo esc_url(home_url('/terms/')); ?>">Rental Terms</a><a href="<?php echo esc_url(home_url('/privacy/')); ?>">Privacy Policy</a><a href="<?php echo esc_url(home_url('/rental-agreement/')); ?>">Rental Agreement</a></nav>
</footer>
<div class="lightbox" onclick="this.classList.remove('on')"><img alt=""></div>
<?php wp_footer(); ?></body></html>
