<!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>><?php wp_body_open(); ?>
<div class="announce"><a href="<?php echo esc_url(home_url('/book/')); ?>">Book Now! Spaces are filling up fast.</a></div>
<header class="site-header"><div class="wrap">
  <a class="brand" href="<?php echo esc_url(home_url('/')); ?>"><?php echo br_logo('header'); ?></a>
  <button class="menu-toggle" aria-label="Menu" onclick="document.querySelector('.nav').classList.toggle('open')">☰</button>
  <nav class="nav"><?php wp_nav_menu(['theme_location' => 'primary', 'container' => false, 'fallback_cb' => false]); ?></nav>
</div></header>
