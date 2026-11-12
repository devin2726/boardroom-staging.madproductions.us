<?php
add_action('after_setup_theme', function () {
    add_theme_support('title-tag'); add_theme_support('post-thumbnails'); add_theme_support('custom-logo');
    register_nav_menus(['primary' => 'Primary', 'footer' => 'Footer']);
});
function br_ver() { return '1.2.47'; }
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('boardroom', get_stylesheet_uri(), [], br_ver());
    wp_enqueue_script('boardroom', get_template_directory_uri() . '/assets/site.js', [], br_ver(), true);
});
function br_facts_strip($items, $break_after = 0) {
    $html = '<p class="hero-facts">';
    foreach (array_values($items) as $i => $item) {
        if ($break_after && $i === (int) $break_after) $html .= '<span class="facts-break" aria-hidden="true"></span>';
        $text = str_replace(['-', ' '], ['&#8209;', '&nbsp;'], esc_html($item));
        $html .= '<span>' . $text . '</span>';
    }
    $html .= '</p>';
    return $html;
}
function br_img($f) { return get_template_directory_uri() . '/assets/img/' . $f; }
function br_img_ver($f) { return br_img($f) . '?v=' . br_ver(); }
function br_logo($place) {
    $sizes = [
        'header' => [98, 100, 'boardroom-logo-header.png', 'boardroom-logo-header-2x.png'],
        'footer' => [161, 164, 'boardroom-logo-footer.png', 'boardroom-logo-footer-2x.png'],
    ];
    [$w, $h, $one, $two] = $sizes[$place];
    $one_url = br_img_ver($one);
    $two_url = br_img_ver($two);
    return '<img src="' . esc_url($one_url) . '" srcset="' . esc_url($one_url) . ' 1x, ' . esc_url($two_url) . ' 2x" width="' . (int) $w . '" height="' . (int) $h . '" alt="The Boardroom QC">';
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
            'title' => 'Access and parking',
            'icon' => 'M4 16l2-6h12l2 6M6 16h12M7 16v2M17 16v2M7 10V7h10v3',
            'items' => ['Private main entrance', 'Free parking on premises', 'Space for 40 to 50 cars'],
        ],
        [
            'title' => 'Tech and entertainment',
            'icon' => 'M4 9a12 12 0 0 1 16 0M7 13a7 7 0 0 1 10 0M12 17h.01',
            'items' => ['Wifi', 'TV', 'Sound system (Sonos, Aux, Bluetooth)', 'Pool table', 'Board games'],
        ],
        [
            'title' => 'Kitchen area',
            'icon' => 'M6 10h12l-1 8H7l-1-8zM9 10V7M12 10V5M15 10V7',
            'lead' => 'Microwave, blender and food warmers are yours to use. The stovetops and oven can\'t be used.',
            'items' => ['Refrigerator (large)', 'Freezer', 'Mini fridge', 'Microwave', 'Food warmers', 'Dishwasher', 'Coffee maker (drip and pour-over)', 'Hot water kettle', 'Blender', 'Wine glasses'],
        ],
        [
            'title' => 'Safety',
            'quiet' => true,
            'items' => ['Smoke alarm', 'Carbon monoxide alarm', 'Fire extinguisher', 'First aid kit'],
        ],
    ];
}
function br_amenities_html() {
    $html = '<div class="amenity-groups">';
    $safety = '';
    foreach (br_amenity_groups() as $group) {
        if (!empty($group['quiet'])) {
            $safety = implode(', ', $group['items']);
            continue;
        }
        $class = 'amenity-group' . (($group['title'] ?? '') === 'Kitchen area' ? ' amenity-kitchen' : '');
        $html .= '<section class="' . $class . '">';
        $html .= '<h3>' . (!empty($group['icon']) ? br_amenity_icon($group['icon']) : '') . esc_html($group['title']) . '</h3>';
        if (!empty($group['lead'])) $html .= '<p class="amenity-lead">' . esc_html($group['lead']) . '</p>';
        $html .= '<ul>';
        foreach ($group['items'] as $item) $html .= '<li>' . esc_html($item) . '</li>';
        $html .= '</ul>';
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
        ['name' => 'Andrew', 'when' => 'October 2026', 'text' => 'We used the boardroom for a leadership retreat, great setting and creative space!', 'featured' => true],
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
    $html .= '<p class="review-badge">5.0 ★ from 30 guest reviews</p></header>';
    $html .= '<div class="t-marquee"><div class="t-track">';
    $html .= '<div class="t-set">' . $cards . '</div>';
    $html .= '<div class="t-set" aria-hidden="true" inert>' . $cards . '</div>';
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
        $hidden = $i === 0 ? '' : ' aria-hidden="true" inert';
        $html .= '<blockquote class="review-card' . $on . '"' . $hidden . '><p class="stars" aria-label="5 out of 5 stars">★★★★★</p><p class="review-text">' . esc_html($review['text']) . '</p><footer><cite>' . esc_html($review['name']) . '</cite> <span>' . esc_html($review['when']) . '</span></footer></blockquote>';
    }
    $html .= '</div>';
    return $html;
}
function br_tour_video_html($below = '') {
    $base = get_template_directory_uri() . '/assets/video/';
    $src = $base . 'boardroom-tour-720.mp4?v=129';
    $poster = $base . 'boardroom-tour-poster.jpg?v=128';
    $html = '<section class="block alt" id="take-a-tour"><div class="wrap">';
    $html .= '<h2>Take a tour</h2>';
    $html .= '<div class="tour-stage"><div class="tour-frame">';
    $html .= '<video id="boardroom-tour" controls preload="none" playsinline poster="' . esc_url($poster) . '">';
    $html .= '<source src="' . esc_url($src) . '" type="video/mp4">';
    $html .= '</video>';
    $html .= '<button type="button" class="tour-play" aria-controls="boardroom-tour">Play</button>';
    $html .= '</div>';
    $html .= '<p class="tour-headline">A unique event destination in the Quad Cities</p></div>';
    if ($below !== '') $html .= $below;
    $html .= '</div></section>';
    return $html;
}
function br_icon_links() {
    $icons = [
        ['rel' => 'icon', 'sizes' => '16x16', 'file' => 'boardroom-siteicon-16.png'],
        ['rel' => 'icon', 'sizes' => '32x32', 'file' => 'boardroom-siteicon-32.png'],
        ['rel' => 'icon', 'sizes' => '48x48', 'file' => 'boardroom-siteicon-48.png'],
        ['rel' => 'icon', 'sizes' => '64x64', 'file' => 'boardroom-siteicon-64.png'],
        ['rel' => 'icon', 'sizes' => '192x192', 'file' => 'boardroom-siteicon-192.png'],
        ['rel' => 'icon', 'sizes' => '512x512', 'file' => 'boardroom-siteicon-512.png'],
        ['rel' => 'apple-touch-icon', 'sizes' => '180x180', 'file' => 'boardroom-siteicon-180.png'],
    ];
    foreach ($icons as $icon) {
        echo '<link rel="' . esc_attr($icon['rel']) . '" sizes="' . esc_attr($icon['sizes']) . '" href="' . esc_url(br_img_ver($icon['file'])) . '">' . "\n";
    }
}
add_action('wp_head', 'br_icon_links');
add_action('login_head', 'br_icon_links');
function br_book_shortcuts_html() {
    $items = [
        ['href' => home_url('/rental-checklist/'), 'label' => 'Rental checklist', 'sub' => 'What to do before and after', 'icon' => 'M8 3h8v3H8zM6 5h12v16H6zM9 12l2 2 4-4'],
    ];
    $html = '<nav class="book-shortcuts" aria-label="Booking help">';
    foreach ($items as $item) {
        $html .= '<a class="book-shortcut" href="' . esc_url($item['href']) . '">';
        $html .= '<svg class="book-shortcut-icon" viewBox="0 0 24 24" width="36" height="36" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" d="' . $item['icon'] . '"/></svg>';
        $html .= '<span class="book-shortcut-copy"><span class="book-shortcut-label">' . esc_html($item['label']) . '</span>';
        $html .= '<span class="book-shortcut-sub">' . htmlspecialchars($item['sub'], ENT_NOQUOTES, 'UTF-8') . '</span></span></a>';
    }
    $html .= '</nav>';
    return $html;
}
function br_contact_form() {
    if (function_exists('brb_tour_form')) return brb_tour_form();
    return '';
}
function br_ask_walter_card($details_target) {
    if (!function_exists('brb_chat_markup')) return '';
    return '<section id="ask-walter">' . brb_chat_markup('home', $details_target) . '</section>';
}
function br_contact_is_thanks() {
    $code = isset($_GET['inquiry']) ? sanitize_key(wp_unslash($_GET['inquiry'])) : '';
    return $code === 'ok';
}
function br_contact_ask_button($banner = false) {
    $class = 'contact-ask' . ($banner ? ' contact-ask-banner' : '');
    $size = $banner ? 128 : 64;
    $html = '<p class="contact-ask-wrap"><button type="button" class="' . $class . '" data-brb-open-chat>';
    if (function_exists('brb_walter_html')) $html .= brb_walter_html($size, 'brb-walter brb-walter-contact');
    $html .= '<span>Ask Walter</span></button></p>';
    return $html;
}
add_shortcode('boardroom_contact', 'br_contact_form');
add_filter('body_class', function ($classes) {
    if (is_page(['privacy', 'terms', 'rental-agreement', 'privacy-policy', 'terms-and-conditions'])) $classes[] = 'legal-page';
    if (is_page('contact')) $classes[] = 'contact-page';
    if (br_is_book_page()) $classes[] = 'book-page';
    if (br_is_book_page() && !empty($_GET['date'])) $classes[] = 'book-dated';
    if (br_is_book_page() && !empty($_GET['br_token'])) $classes[] = 'book-status';
    return $classes;
});
function br_book_slug() {
    return defined('BRB_BOOK_SLUG') ? BRB_BOOK_SLUG : 'reserve-the-boardroom-quad-cities';
}
function br_is_book_page() {
    return is_page(br_book_slug());
}
function br_rename_reserve_menu() {
    $locs = get_nav_menu_locations();
    $menu_id = (int) ($locs['primary'] ?? 0);
    if (!$menu_id) return;
    $slug = br_book_slug();
    foreach ((array) wp_get_nav_menu_items($menu_id) as $item) {
        if (!$item) continue;
        if (br_menu_title($item) !== 'book') continue;
        $path = trim((string) br_menu_path($item->url), '/');
        $base = $path === '' ? '' : basename($path);
        $linked = ($base === 'book' || $base === $slug);
        if (!$linked && !empty($item->object_id)) {
            $post = get_post((int) $item->object_id);
            if ($post && in_array($post->post_name, ['book', $slug], true)) $linked = true;
        }
        if (!$linked) continue;
        $args = [
            'menu-item-title' => 'Reserve',
            'menu-item-status' => 'publish',
            'menu-item-type' => $item->type ?: 'post_type',
            'menu-item-parent-id' => (int) $item->menu_item_parent,
            'menu-item-position' => (int) $item->menu_order,
        ];
        if (($item->type ?? '') === 'post_type') {
            $args['menu-item-object'] = $item->object ?: 'page';
            $args['menu-item-object-id'] = (int) $item->object_id;
        } else {
            $args['menu-item-url'] = function_exists('brb_book_url') ? brb_book_url() : home_url('/' . $slug . '/');
        }
        wp_update_nav_menu_item($menu_id, (int) $item->ID, $args);
    }
}
add_action('init', 'br_rename_reserve_menu', 30);
add_action('template_redirect', function () {
    // FAQ and Terms share header.php with every other page. A full-page cache
    // was still serving an older banner and a "Book" nav item for those two URLs.
    if (!function_exists('is_page') || !is_page(['faq', 'terms'])) return;
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    nocache_headers();
}, 0);
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
function br_public_email() {
    return 'boardroomqc@gmail.com';
}
function br_webp_file($file) {
    return preg_replace('/\.(jpe?g|png)$/i', '.webp', (string) $file);
}
function br_has_webp($file) {
    return is_readable(get_template_directory() . '/assets/img/' . br_webp_file($file));
}
function br_picture($file, $alt) {
    $html = '<picture>';
    if (br_has_webp($file)) {
        $html .= '<source srcset="' . esc_url(br_img(br_webp_file($file))) . '" type="image/webp">';
    }
    $html .= '<img src="' . esc_url(br_img($file)) . '" alt="' . esc_attr($alt) . '">';
    $html .= '</picture>';
    return $html;
}
function br_photo_alt($file) {
    $alts = [
        'IMG_0223.jpeg' => 'The Boardroom main room with tables ready for an event',
        'IMG_0231.jpeg' => 'The Boardroom bar and seating area',
        'PXL_20220912_160615354.jpg' => 'The Boardroom game room with the pool table',
        'PXL_20220912_160655168.jpg' => 'The Boardroom event space looking toward the entrance',
        'PXL_20220912_161645069.jpg' => 'The Boardroom lounge with the television and games',
    ];
    return $alts[$file] ?? 'The Boardroom venue';
}
function br_gallery_alt($file) {
    $n = '00';
    if (preg_match('/(\d+)/', (string) $file, $m)) $n = $m[1];
    $alts = [
        '01' => 'Wide view of The Boardroom event space',
        '02' => 'The Boardroom seating along the main wall',
        '03' => 'Tables arranged in The Boardroom',
        '04' => 'The Boardroom looking toward the bar',
        '05' => 'The Boardroom private main entrance area',
        '06' => 'Close view of The Boardroom bar top',
        '07' => 'The Boardroom ice machine and bar tools',
        '08' => 'The Boardroom kitchen serving counter',
        '09' => 'The Boardroom refrigerator and serving space',
        '10' => 'The Boardroom coffee station',
        '11' => 'The Boardroom pool table',
        '12' => 'The Boardroom shuffleboard',
        '13' => 'The Boardroom pinball machine',
        '14' => 'The Boardroom 80-inch TV',
        '15' => 'The Boardroom lounge seating',
        '16' => 'The Boardroom dining table',
        '17' => 'The Boardroom set for a small gathering',
        '18' => 'The Boardroom string lights over the room',
        '19' => 'The Boardroom restroom hallway',
        '20' => 'The Boardroom second restroom',
        '22' => 'The Boardroom back of the room',
        '23' => 'The Boardroom windows and seating',
        '24' => 'The Boardroom floor and open space',
        '25' => 'The Boardroom sound equipment',
        '26' => 'The Boardroom Bluetooth speaker area',
        '27' => 'The Boardroom decor along the wall',
        '28' => 'The Boardroom ceiling and lights',
        '29' => 'The Boardroom corner seating',
        '31' => 'The Boardroom parking area outside',
        '32' => 'The Boardroom exterior and private main entrance',
        '33' => 'The Boardroom street view',
        '34' => 'The Boardroom side of the building',
        '35' => 'The Boardroom evening interior',
    ];
    return $alts[$n] ?? ('The Boardroom venue photo ' . $n);
}
function br_seo_pages() {
    $place = 'Party & Event Venue in Moline, IL (Quad Cities)';
    return [
        'home' => ['title' => 'The Boardroom | ' . $place, 'description' => 'The Boardroom is a party and event venue in Moline, IL, in the Quad Cities. Prices show on the calendar; nothing is charged until we approve your request.'],
        'reserve-the-boardroom-quad-cities' => ['title' => 'Book The Boardroom | ' . $place, 'description' => 'Request a date at The Boardroom in Moline. The price shows on the calendar. Nothing is charged until we approve the booking and email a payment link. Pay 50% plus the $250 damage deposit.'],
        'faq' => ['title' => 'FAQ | The Boardroom, ' . $place, 'description' => 'Answers about booking, hours, food, BYOB, capacity, and cancellation at The Boardroom in Moline, IL.'],
        'terms' => ['title' => 'Rental Terms | The Boardroom, ' . $place, 'description' => 'Plain-language rental terms for The Boardroom in Moline, IL. The signed rental agreement controls if they differ.'],
        'privacy' => ['title' => 'Privacy Policy | The Boardroom, ' . $place, 'description' => 'How The Boardroom in Moline, IL uses booking and contact details. We do not sell your information.'],
        'rental-agreement' => ['title' => 'Rental Agreement | The Boardroom, ' . $place, 'description' => 'The facility event space rental agreement for The Boardroom at 2220 37th Street, Moline, IL.'],
        'rental-checklist' => ['title' => 'Rental Checklist | The Boardroom, ' . $place, 'description' => 'What to do before and after an event at The Boardroom in Moline, IL, including setup, cleanup, and house rules.'],
        'contact' => ['title' => 'Contact The Boardroom | ' . $place, 'description' => 'Email boardroomqc@gmail.com or use the contact form to ask about The Boardroom in Moline, IL.'],
        'gallery' => ['title' => 'Photo Gallery | The Boardroom, ' . $place, 'description' => 'Photos of The Boardroom, a party and event venue in Moline, IL, in the Quad Cities.'],
        'holiday-party-venue-quad-cities' => ['title' => '', 'description' => 'Planning a holiday party in the Quad Cities? The Boardroom in Moline, IL hosts up to 80 guests. See open dates and pricing on our calendar.'],
    ];
}
function br_seo_key() {
    if (is_front_page() || is_home()) return 'home';
    $post = get_queried_object();
    $slug = ($post && isset($post->post_name)) ? (string) $post->post_name : '';
    $pages = br_seo_pages();
    return isset($pages[$slug]) ? $slug : '';
}
function br_yoast_active() {
    return defined('WPSEO_VERSION');
}
function br_seo_row() {
    $key = br_seo_key();
    if ($key === '') return null;
    $pages = br_seo_pages();
    return $pages[$key] ?? null;
}
function br_yoast_document_title($fallback) {
    $row = br_seo_row();
    if ($row && ($row['title'] ?? '') !== '') return $row['title'];
    $post = get_queried_object();
    if ($post && !empty($post->post_title) && ($row || (function_exists('is_singular') && is_singular()))) {
        return $post->post_title . ' – ' . get_bloginfo('name', 'display');
    }
    return $fallback;
}
function br_yoast_meta_description($fallback) {
    $row = br_seo_row();
    if ($row && ($row['description'] ?? '') !== '') return $row['description'];
    return $fallback;
}
function br_post_booking_html() {
    $html = '<section class="post-booking" id="check-your-date">';
    $html .= '<h2>Check Your Date</h2>';
    if (function_exists('brb_home_calendar_html')) $html .= brb_home_calendar_html();
    $html .= '<p class="post-booking-cta"><a class="btn" href="' . esc_url('https://boardroomqc.com/reserve-the-boardroom-quad-cities/') . '">Book Now</a></p>';
    $html .= '</section>';
    return $html;
}
function br_yoast_schema_drop($piece) {
    if (!is_array($piece) || !isset($piece['@type'])) return false;
    $types = is_array($piece['@type']) ? $piece['@type'] : [$piece['@type']];
    return (bool) array_intersect($types, ['Organization', 'LocalBusiness', 'EventVenue', 'FAQPage']);
}
function br_yoast_clean_schema($value) {
    if (!is_array($value)) return $value;
    if (br_yoast_schema_drop($value)) return null;
    unset($value['telephone'], $value['aggregateRating']);
    $list = function_exists('array_is_list') ? array_is_list($value) : false;
    if ($list) {
        $next = [];
        foreach ($value as $child) {
            if (!is_array($child)) {
                $next[] = $child;
                continue;
            }
            $clean = br_yoast_clean_schema($child);
            if ($clean !== null) $next[] = $clean;
        }
        return $next;
    }
    foreach ($value as $key => $child) {
        if (!is_array($child)) continue;
        $clean = br_yoast_clean_schema($child);
        if ($clean === null) unset($value[$key]);
        else $value[$key] = $clean;
    }
    return $value;
}
function br_share_image() {
    $file = 'PXL_20220912_160655168.jpg';
    if (br_has_webp($file)) $file = br_webp_file($file);
    return br_img($file);
}
add_filter('document_title_parts', function ($parts) {
    if (br_yoast_active()) return $parts;
    $key = br_seo_key();
    $pages = br_seo_pages();
    if ($key !== '' && isset($pages[$key]) && $pages[$key]['title'] !== '') {
        $parts['title'] = $pages[$key]['title'];
        unset($parts['site'], $parts['tagline']);
    }
    return $parts;
});
add_action('wp_head', function () {
    if (br_yoast_active()) return;
    $key = br_seo_key();
    if ($key === '') return;
    $row = br_seo_pages()[$key];
    $description = (string) ($row['description'] ?? '');
    if ($description === '') return;
    echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
    if (($row['title'] ?? '') === '') return;
    $url = $key === 'home' ? home_url('/') : home_url('/' . $key . '/');
    $image = br_share_image();
    echo '<meta property="og:type" content="website">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($row['title']) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
}, 2);
add_filter('wpseo_title', function ($title) {
    return br_yoast_document_title($title);
});
add_filter('wpseo_opengraph_title', function ($title) {
    return br_yoast_document_title($title);
});
add_filter('wpseo_metadesc', function ($description) {
    return br_yoast_meta_description($description);
});
add_filter('wpseo_opengraph_desc', function ($description) {
    return br_yoast_meta_description($description);
});
add_filter('wpseo_schema_graph_pieces', function ($pieces) {
    if (!is_array($pieces)) return $pieces;
    $drop = ['Yoast\WP\SEO\Generators\Schema\Organization', 'Yoast\WP\SEO\Generators\Schema\FAQ'];
    $kept = [];
    foreach ($pieces as $piece) {
        if (is_object($piece) && in_array(get_class($piece), $drop, true)) continue;
        $kept[] = $piece;
    }
    return $kept;
});
add_filter('wpseo_schema_graph', function ($graph) {
    $clean = br_yoast_clean_schema($graph);
    return is_array($clean) ? $clean : [];
});
add_filter('wpseo_robots_array', function ($robots) {
    if (!is_array($robots)) return $robots;
    if (!function_exists('brb_is_production') || !brb_is_production()) return $robots;
    $robots['index'] = 'index';
    if (($robots['follow'] ?? '') === 'nofollow') $robots['follow'] = 'follow';
    return $robots;
}, 99);
add_action('init', function () {
    if (!br_yoast_active()) return;
    remove_action('wp_head', 'rel_canonical');
}, 20);
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
