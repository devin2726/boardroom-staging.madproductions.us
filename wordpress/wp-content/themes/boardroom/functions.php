<?php
add_action('after_setup_theme', function () {
    add_theme_support('title-tag'); add_theme_support('post-thumbnails'); add_theme_support('custom-logo');
    register_nav_menus(['primary' => 'Primary', 'footer' => 'Footer']);
});
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('boardroom', get_stylesheet_uri(), [], '1.2.11');
    wp_enqueue_script('boardroom', get_template_directory_uri() . '/assets/site.js', [], '1.1.0', true);
});
function br_img($f) { return get_template_directory_uri() . '/assets/img/' . $f; }
function br_photos() { return ['IMG_0223.jpeg', 'IMG_0231.jpeg', 'PXL_20220912_160615354.jpg', 'PXL_20220912_160655168.jpg', 'PXL_20220912_161645069.jpg']; }
function br_gallery() {
    $skip = ['21', '30', '36'];
    $out = [];
    for ($i = 1; $i <= 36; $i++) {
        $n = sprintf('%02d', $i);
        if (in_array($n, $skip, true)) continue;
        $out[] = 'boardroom-' . $n . '.jpeg';
    }
    return $out;
}
function br_gallery_src($file, $thumb = false) {
    return br_img('gallery/' . ($thumb ? 'thumbs/' : '') . $file);
}
function br_amenity_groups() {
    return [
        [
            'title' => 'Comfort and utilities',
            'items' => ['Air conditioning', 'Heating', 'Hot water'],
        ],
        [
            'title' => 'Access and parking',
            'items' => ['Private entrance', 'Free parking on premises', 'Free street parking'],
        ],
        [
            'title' => 'Tech and entertainment',
            'items' => ['Wifi', 'TV', 'Sound system (Sonos, Aux, Bluetooth)', 'Pool table', 'Board games'],
        ],
        [
            'title' => 'Furnishings',
            'items' => ['Dining table', 'Dedicated workspace'],
        ],
        [
            'title' => 'Kitchen area',
            'lead' => 'Serving and storage only, no cooking.',
            'items' => ['Refrigerator (large)', 'Freezer', 'Mini fridge', 'Microwave', 'Dishwasher', 'Coffee maker (drip and pour-over)', 'Hot water kettle', 'Toaster', 'Blender', 'Wine glasses'],
            'note' => 'Kitchen area is for serving and storage. Cooking and stovetop use are not permitted.',
        ],
        [
            'title' => 'Safety',
            'items' => ['Smoke alarm', 'Carbon monoxide alarm', 'Fire extinguisher', 'First aid kit'],
        ],
    ];
}
function br_amenities_html() {
    $html = '<p class="amenity-intro">The room comes not set up. No overnight stays. The Boardroom is an event space only.</p>';
    $html .= '<div class="amenity-groups">';
    foreach (br_amenity_groups() as $group) {
        $html .= '<div class="amenity-group">';
        $html .= '<h3>' . esc_html($group['title']) . '</h3>';
        if (!empty($group['lead'])) $html .= '<p class="amenity-lead">' . esc_html($group['lead']) . '</p>';
        $html .= '<ul>';
        foreach ($group['items'] as $item) $html .= '<li>' . esc_html($item) . '</li>';
        $html .= '</ul>';
        if (!empty($group['note'])) $html .= '<p class="amenity-note">' . esc_html($group['note']) . '</p>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}
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
function br_ensure_gallery() {
    if (get_option('br_gallery_ready') === '1') return;
    $page = get_page_by_path('gallery');
    if (!$page) {
        $id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Gallery',
            'post_name' => 'gallery',
            'post_content' => '',
        ], true);
        if (is_wp_error($id) || !$id) return;
        $page = get_post($id);
    }
    if (!$page) return;
    $locs = get_nav_menu_locations();
    $menu_id = (int) ($locs['primary'] ?? 0);
    if (!$menu_id) return;
    $has = false;
    foreach ((array) wp_get_nav_menu_items($menu_id) as $item) {
        $path = trim((string) parse_url((string) $item->url, PHP_URL_PATH), '/');
        if ($path === 'gallery' || (int) $item->object_id === (int) $page->ID) $has = true;
    }
    if (!$has) {
        $added = wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title' => 'Gallery',
            'menu-item-object-id' => (int) $page->ID,
            'menu-item-object' => 'page',
            'menu-item-type' => 'post_type',
            'menu-item-status' => 'publish',
            'menu-item-position' => 1,
        ]);
        if (is_wp_error($added) || !$added) return;
    }
    update_option('br_gallery_ready', '1');
}
add_action('init', 'br_ensure_gallery');
