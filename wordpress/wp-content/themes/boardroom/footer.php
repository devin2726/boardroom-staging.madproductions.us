<footer class="site-footer"><p>Copyright © <?php echo date('Y'); ?> The Boardroom - All Rights Reserved.</p>
<p><?php wp_nav_menu(['theme_location' => 'footer', 'container' => false, 'items_wrap' => '<ul class="fmenu">%3$s</ul>', 'fallback_cb' => false, 'depth' => 1]); ?></p></footer>
<div class="lightbox" onclick="this.classList.remove('on')"><img alt=""></div>
<?php wp_footer(); ?></body></html>
