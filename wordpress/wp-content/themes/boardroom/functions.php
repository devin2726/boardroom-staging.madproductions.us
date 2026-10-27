<?php
add_action('after_setup_theme', function () {
    add_theme_support('title-tag'); add_theme_support('post-thumbnails'); add_theme_support('custom-logo');
    register_nav_menus(['primary' => 'Primary', 'footer' => 'Footer']);
});
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('boardroom', get_stylesheet_uri(), [], '1.2.9');
    wp_enqueue_script('boardroom', get_template_directory_uri() . '/assets/site.js', [], '1.0.0', true);
});
function br_img($f) { return get_template_directory_uri() . '/assets/img/' . $f; }
function br_photos() { return ['IMG_0223.jpeg', 'IMG_0231.jpeg', 'PXL_20220912_160615354.jpg', 'PXL_20220912_160655168.jpg', 'PXL_20220912_161645069.jpg']; }
add_action('wp_head', function () { echo '<link rel="icon" href="' . esc_url(br_img('boardroom-siteicon-512.png')) . '">'; });
function br_contact_form() {
    if (function_exists('brb_tour_form')) return brb_tour_form();
    return '';
}
add_shortcode('boardroom_contact', 'br_contact_form');
add_filter('body_class', function ($classes) {
    if (is_page(['privacy', 'terms', 'rental-agreement', 'privacy-policy', 'terms-and-conditions'])) $classes[] = 'legal-page';
    return $classes;
});
add_filter('wp_nav_menu_objects', function ($items, $args) {
    if (($args->theme_location ?? '') !== 'footer') return $items;
    $drop = ['privacy', 'privacy-policy', 'terms', 'terms-and-conditions', 'rental-agreement'];
    $kept = [];
    foreach ($items as $item) {
        $path = trim((string) parse_url($item->url, PHP_URL_PATH), '/');
        $slug = $path === '' ? '' : basename($path);
        if (!in_array($slug, $drop, true)) $kept[] = $item;
    }
    return $kept;
}, 10, 2);
