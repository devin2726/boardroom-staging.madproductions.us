<?php
add_action('after_setup_theme', function () {
    add_theme_support('title-tag'); add_theme_support('post-thumbnails'); add_theme_support('custom-logo');
    register_nav_menus(['primary' => 'Primary', 'footer' => 'Footer']);
});
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('boardroom', get_stylesheet_uri(), [], '1.2.12');
    wp_enqueue_script('boardroom', get_template_directory_uri() . '/assets/site.js', [], '1.2.12', true);
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
function br_review_summary() {
    return ['rating' => 5.0, 'count' => 29, 'label' => 'from 29 guest reviews'];
}
function br_review_aggregate_schema() {
    $summary = br_review_summary();
    $rating = (float) $summary['rating'];
    $count = (int) $summary['count'];
    if ($rating <= 0 || $rating > 5 || $count <= 0) return null;
    return [
        '@type' => 'AggregateRating',
        'ratingValue' => number_format($rating, 1, '.', ''),
        'reviewCount' => $count,
        'bestRating' => '5',
        'worstRating' => '1',
    ];
}
function br_guest_reviews() {
    return [
        ['name' => 'Amy', 'when' => 'February 2026', 'text' => 'The Boardroom is simply fantastic. The vibe is great for all types of events - chill & reserved or funky & fun.', 'featured' => true],
        ['name' => 'Dixon N.', 'when' => '2026', 'text' => 'This place is awesome. Old timey decorations and band posters are a sight to see. I was blown away by their atmosphere. Nothing else like it in the QC.', 'featured' => true],
        ['name' => 'Abbey', 'when' => 'June 2024', 'text' => 'Absolutely perfect for our graduation party. The artistic styling of the place was fantastic and unique. The system for music was easy to use and sounded great. Chris was so easy to work with and provided additional details for lighting/decor for a super cool party ambiance. I would highly recommend to anyone planning an event who wants a space you absolutely won\'t find anywhere else!', 'featured' => true],
        ['name' => 'Cresencia', 'when' => 'June 2023', 'text' => 'Absolutely wonderful! The space was perfect to accommodate the come and go traffic, as well as those that wanted to sit and visit for a while. Parking was plentiful. There were games that adults and teens could enjoy and the eclectic design of the space was a talking point for many of our friends and family.', 'featured' => true],
        ['name' => 'Becky', 'when' => 'March 2022', 'text' => 'This place offers everything you could think of to host a great party. There are separate spaces within this one amazing space for all of your guests to enjoy something. Great host, very responsive to questions. Highly recommend this for your next event!', 'featured' => false],
        ['name' => 'Naomi', 'when' => 'June 2021', 'text' => 'Our guests were super impressed by the modern facility, fun decor and how spacious it was. We had privacy and flexibility to host just as we wished and my event was more successful because of it.', 'featured' => false],
        ['name' => 'Shay', 'when' => 'June 2021', 'text' => 'This place was amazing! The host was very nice and communication was excellent! The venue was very clean and well organized! We loved it!!', 'featured' => false],
        ['name' => 'Kelli S.', 'when' => '2025', 'text' => 'A great venue, roomy, organized, convenient and private.', 'featured' => false],
        ['name' => 'Danny C.', 'when' => '2025', 'text' => 'Neat place, for all your gathering and party needs.', 'featured' => false],
        ['name' => 'Sarai A.', 'when' => '2025', 'text' => 'Beautiful venue for any occasion!', 'featured' => false],
        ['name' => 'Amy', 'when' => 'June 2022', 'text' => 'Stunning place, perfect for just about any gathering you\'d like!', 'featured' => false],
        ['name' => 'Guest', 'when' => 'November 2023', 'text' => 'Great venue! Had a birthday party for my grandma\'s 90th birthday, about 40 people ranging in age from 2-90, and this was a perfect space!', 'featured' => false],
    ];
}
function br_review_card($review, $class = 'review-card') {
    return '<blockquote class="' . esc_attr($class) . '"><p class="stars" aria-label="5 out of 5 stars">★★★★★</p><p class="review-text">' . esc_html($review['text']) . '</p><footer><cite>' . esc_html($review['name']) . '</cite> <span>' . esc_html($review['when']) . '</span></footer></blockquote>';
}
function br_reviews_html() {
    $featured = [];
    $rest = [];
    foreach (br_guest_reviews() as $review) {
        if (!empty($review['featured']) && count($featured) < 3) $featured[] = $review;
        else $rest[] = $review;
    }
    $html = '<section class="block" id="guest-reviews"><div class="wrap">';
    $html .= '<h2>What our guests say</h2>';
    $html .= '<p class="review-badge">5.0 ★ from 29 guest reviews</p>';
    $html .= '<div class="review-grid">';
    foreach ($featured as $review) $html .= br_review_card($review);
    $html .= '</div>';
    $html .= '<div id="more-reviews" class="review-grid" hidden>';
    foreach ($rest as $review) $html .= br_review_card($review);
    $html .= '</div>';
    $html .= '<p class="reviews-more-wrap"><button type="button" class="btn reviews-more" aria-expanded="false" aria-controls="more-reviews">Read more reviews</button></p>';
    $html .= '</div></section>';
    return $html;
}
function br_review_rotator_html() {
    $reviews = [];
    foreach (br_guest_reviews() as $review) {
        if (!empty($review['featured'])) $reviews[] = $review;
    }
    if (!$reviews) return '';
    $html = '<div class="review-rotator" aria-roledescription="carousel" aria-label="Guest reviews">';
    foreach ($reviews as $i => $review) {
        $on = $i === 0 ? ' on' : '';
        $hidden = $i === 0 ? '' : ' aria-hidden="true"';
        $html .= '<blockquote class="review-card' . $on . '"' . $hidden . '><p class="stars" aria-label="5 out of 5 stars">★★★★★</p><p class="review-text">' . esc_html($review['text']) . '</p><footer><cite>' . esc_html($review['name']) . '</cite> <span>' . esc_html($review['when']) . '</span></footer></blockquote>';
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
