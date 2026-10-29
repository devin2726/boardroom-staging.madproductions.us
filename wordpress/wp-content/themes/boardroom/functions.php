<?php
add_action('after_setup_theme', function () {
    add_theme_support('title-tag'); add_theme_support('post-thumbnails'); add_theme_support('custom-logo');
    register_nav_menus(['primary' => 'Primary', 'footer' => 'Footer']);
});
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('boardroom', get_stylesheet_uri(), [], '1.2.20');
    wp_enqueue_script('boardroom', get_template_directory_uri() . '/assets/site.js', [], '1.2.20', true);
});
function br_img($f) { return get_template_directory_uri() . '/assets/img/' . $f; }
function br_logo($place) {
    $sizes = [
        'header' => [110, 64, 'boardroom-logo-header.png', 'boardroom-logo-header-2x.png'],
        'footer' => [193, 112, 'boardroom-logo-footer.png', 'boardroom-logo-footer-2x.png'],
    ];
    [$w, $h, $one, $two] = $sizes[$place];
    return '<img src="' . esc_url(br_img($one)) . '" srcset="' . esc_url(br_img($one)) . ' 1x, ' . esc_url(br_img($two)) . ' 2x" width="' . (int) $w . '" height="' . (int) $h . '" alt="The Boardroom QC">';
}
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
function br_amenity_icon($d) {
    return '<svg class="amenity-icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="none" stroke="#c4912e" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" d="' . $d . '"/></svg>';
}
function br_amenity_groups() {
    return [
        [
            'title' => 'Comfort and utilities',
            'icon' => 'M12 3v2M12 19v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M3 12h2M19 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z',
            'items' => ['Air conditioning', 'Heating', 'Hot water'],
        ],
        [
            'title' => 'Access and parking',
            'icon' => 'M4 16l2-6h12l2 6M6 16h12M7 16v2M17 16v2M7 10V7h10v3',
            'items' => ['Private entrance', 'Free parking on premises', 'Free street parking'],
        ],
        [
            'title' => 'Tech and entertainment',
            'icon' => 'M4 9a12 12 0 0 1 16 0M7 13a7 7 0 0 1 10 0M12 17h.01',
            'items' => ['Wifi', 'TV', 'Sound system (Sonos, Aux, Bluetooth)', 'Pool table', 'Board games'],
        ],
        [
            'title' => 'Furnishings',
            'icon' => 'M6 11V6h12v5M4 11h16v4H4zM7 15v4M17 15v4',
            'items' => ['Dining table', 'Dedicated workspace'],
        ],
        [
            'title' => 'Kitchen area',
            'icon' => 'M6 10h12l-1 8H7l-1-8zM9 10V7M12 10V5M15 10V7',
            'lead' => 'Serving and storage only, no cooking.',
            'items' => ['Refrigerator (large)', 'Freezer', 'Mini fridge', 'Microwave', 'Dishwasher', 'Coffee maker (drip and pour-over)', 'Hot water kettle', 'Toaster', 'Blender', 'Wine glasses'],
            'note' => 'Kitchen area is for serving and storage. Cooking and stovetop use are not permitted.',
        ],
        [
            'title' => 'Safety',
            'quiet' => true,
            'items' => ['Smoke alarm', 'Carbon monoxide alarm', 'Fire extinguisher', 'First aid kit'],
        ],
    ];
}
function br_amenities_html() {
    $html = '<p class="amenity-intro">The room comes not set up. No overnight stays. The Boardroom is an event space only.</p>';
    $html .= '<div class="amenity-groups">';
    $safety = '';
    foreach (br_amenity_groups() as $group) {
        if (!empty($group['quiet'])) {
            $safety = implode(', ', $group['items']);
            continue;
        }
        $html .= '<section class="amenity-group">';
        $html .= '<h3>' . (!empty($group['icon']) ? br_amenity_icon($group['icon']) : '') . esc_html($group['title']) . '</h3>';
        if (!empty($group['lead'])) $html .= '<p class="amenity-lead">' . esc_html($group['lead']) . '</p>';
        $html .= '<ul>';
        foreach ($group['items'] as $item) $html .= '<li>' . esc_html($item) . '</li>';
        $html .= '</ul>';
        if (!empty($group['note'])) $html .= '<p class="amenity-note">' . esc_html($group['note']) . '</p>';
        $html .= '</section>';
    }
    $html .= '</div>';
    if ($safety !== '') $html .= '<p class="amenity-safety">Safety: ' . esc_html($safety) . '.</p>';
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
function br_review_initial($name) {
    $name = trim((string) $name);
    if ($name === '') return 'G';
    if (function_exists('mb_substr')) return mb_strtoupper(mb_substr($name, 0, 1));
    return strtoupper(substr($name, 0, 1));
}
function br_review_marquee_card($review) {
    return '<article class="t-card"><p class="stars" aria-label="5 out of 5 stars">★★★★★</p><p class="review-text">' . esc_html($review['text']) . '</p><footer class="t-foot"><span class="t-avatar" aria-hidden="true">' . esc_html(br_review_initial($review['name'])) . '</span><span class="t-meta"><cite>' . esc_html($review['name']) . '</cite><span class="t-when">' . esc_html($review['when']) . '</span></span></footer></article>';
}
function br_reviews_html() {
    $cards = '';
    foreach (br_guest_reviews() as $review) $cards .= br_review_marquee_card($review);
    $html = '<section class="block" id="guest-reviews"><div class="wrap">';
    $html .= '<header class="t-head"><h2>What our guests say</h2>';
    $html .= '<p class="review-badge">5.0 ★ from 29 guest reviews</p></header>';
    $html .= '<div class="t-marquee"><div class="t-track">';
    $html .= '<div class="t-set">' . $cards . '</div>';
    $html .= '<div class="t-set" aria-hidden="true">' . $cards . '</div>';
    $html .= '</div></div></div></section>';
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
function br_tour_video_html() {
    $base = get_template_directory_uri() . '/assets/video/';
    $src = $base . 'boardroom-tour-720.mp4';
    $poster = $base . 'boardroom-tour-poster.jpg';
    $html = '<section class="block alt" id="take-a-tour"><div class="wrap">';
    $html .= '<h2>Take a tour</h2>';
    $html .= '<p class="tour-intro">Take a look around The Boardroom</p>';
    $html .= '<div class="tour-frame">';
    $html .= '<video id="boardroom-tour" controls preload="none" playsinline poster="' . esc_url($poster) . '">';
    $html .= '<source src="' . esc_url($src) . '" type="video/mp4">';
    $html .= '</video>';
    $html .= '<button type="button" class="tour-play" aria-controls="boardroom-tour">Play</button>';
    $html .= '</div>';
    $html .= '<p class="tour-links"><a class="btn" href="' . esc_url(home_url('/book/')) . '">Book Now</a> <a class="btn ghost-dark" href="' . esc_url(home_url('/contact/')) . '">Request a tour</a></p>';
    $html .= '</div></section>';
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
    if (is_page('contact')) $classes[] = 'contact-page';
    return $classes;
});
function br_menu_title($item) {
    return strtolower(trim(wp_strip_all_tags((string) $item->title)));
}
function br_menu_path($url) {
    $path = trim((string) parse_url((string) $url, PHP_URL_PATH), '/');
    $home = trim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($home !== '' && strpos($path, $home . '/') === 0) $path = substr($path, strlen($home) + 1);
    return $path;
}
add_filter('wp_nav_menu_objects', function ($items, $args) {
    $loc = $args->theme_location ?? '';
    if ($loc === 'footer') {
        $drop = ['privacy', 'privacy-policy', 'terms', 'terms-and-conditions', 'rental-agreement'];
        $kept = [];
        foreach ($items as $item) {
            $path = br_menu_path($item->url);
            $slug = $path === '' ? '' : basename($path);
            if (!in_array($slug, $drop, true)) $kept[] = $item;
        }
        return $kept;
    }
    if ($loc !== 'primary') return $items;
    $kept = [];
    foreach ($items as $item) {
        if (br_menu_title($item) === 'home') continue;
        if (($item->type ?? '') === 'post_type' && !empty($item->object_id)) {
            $permalink = get_permalink((int) $item->object_id);
            if ($permalink) $item->url = $permalink;
        }
        $kept[] = $item;
    }
    return $kept;
}, 10, 2);
function br_migrate_primary_menu() {
    if (get_option('br_menu_migration') === '1.2.14') return;
    $locs = get_nav_menu_locations();
    $menu_id = (int) ($locs['primary'] ?? 0);
    if (!$menu_id) return;
    foreach ((array) wp_get_nav_menu_items($menu_id) as $item) {
        $title = br_menu_title($item);
        if ($title === 'home') {
            wp_delete_post((int) $item->ID, true);
            continue;
        }
        if ($title !== 'faq' && $title !== 'book') continue;
        $page = get_page_by_path($title);
        if (!$page) continue;
        $path = br_menu_path($item->url);
        if ($path === $title || $path === $title . '/') continue;
        wp_update_nav_menu_item($menu_id, (int) $item->ID, [
            'menu-item-title' => $page->post_title,
            'menu-item-object-id' => (int) $page->ID,
            'menu-item-object' => 'page',
            'menu-item-type' => 'post_type',
            'menu-item-status' => 'publish',
        ]);
    }
    update_option('br_menu_migration', '1.2.14');
}
add_action('init', 'br_migrate_primary_menu', 20);
function br_footer_map() {
    $src = 'https://www.google.com/maps?q=2220+37th+Street,+Moline,+IL+61265&output=embed';
    return '<iframe class="footer-map" src="' . esc_url($src) . '" loading="lazy" title="Map of The Boardroom at 2220 37th Street, Moline, IL 61265" referrerpolicy="no-referrer-when-downgrade"></iframe>';
}
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
