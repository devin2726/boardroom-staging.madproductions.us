<footer class="site-footer"><p>The Boardroom QC<br>2212 37th Street, Moline, IL 61265</p>
<p>Copyright © <?php echo date('Y'); ?> The Boardroom - All Rights Reserved.</p>
<p><?php wp_nav_menu(['theme_location' => 'footer', 'container' => false, 'items_wrap' => '<ul class="fmenu">%3$s</ul>', 'fallback_cb' => false, 'depth' => 1]); ?></p>
<nav class="legal-links" aria-label="Legal"><a href="<?php echo esc_url(home_url('/terms/')); ?>">Rental Terms</a><a href="<?php echo esc_url(home_url('/privacy/')); ?>">Privacy Policy</a><a href="<?php echo esc_url(home_url('/rental-agreement/')); ?>">Rental Agreement</a></nav>
</footer>
<div class="lightbox" onclick="this.classList.remove('on')"><img alt=""></div>
<?php wp_footer(); ?></body></html>
