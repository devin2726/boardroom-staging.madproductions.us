<?php
add_action('after_setup_theme', function () {
    add_theme_support('title-tag'); add_theme_support('post-thumbnails'); add_theme_support('custom-logo');
    register_nav_menus(['primary' => 'Primary', 'footer' => 'Footer']);
});
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('boardroom', get_stylesheet_uri(), [], '1.2.10');
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
add_action('wp_head', function () { echo '<link rel="icon" href="' . esc_url(br_img('boardroom-siteicon-512.png')) . '">'; });
/* simple self-hosted contact form (no third-party captcha; honeypot + nonce) */
add_action('admin_post_nopriv_br_contact', 'br_contact'); add_action('admin_post_br_contact', 'br_contact');
function br_contact() {
    $back = wp_get_referer() ?: home_url('/contact/');
    if (!wp_verify_nonce($_POST['_br'] ?? '', 'br_contact') || !empty($_POST['website'])) { wp_safe_redirect($back); exit; }
    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? '')); $email = sanitize_email($_POST['email'] ?? '');
    if (!$name || !is_email($email)) { wp_safe_redirect(add_query_arg('sent', 'err', $back) . '#contact'); exit; }
    $msg = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
    $to = function_exists('brb_opt') ? brb_opt('admin_email') : get_option('admin_email');
    wp_mail($to, 'Booking inquiry from ' . $name, "Name: $name\nEmail: $email\nPhone: " . sanitize_text_field($_POST['phone'] ?? '') . "\n\n$msg", ['Reply-To: ' . $name . ' <' . $email . '>']);
    wp_safe_redirect(add_query_arg('sent', '1', $back) . '#contact'); exit;
}
function br_contact_form() {
    ob_start();
    if (($_GET['sent'] ?? '') === '1') echo '<div class="notice">Thanks! We received your message and will be in touch soon.</div>';
    if (($_GET['sent'] ?? '') === 'err') echo '<div class="notice">Please enter your name and a valid email.</div>'; ?>
    <form class="form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="br_contact"><?php wp_nonce_field('br_contact', '_br'); ?>
      <label class="hp">Website<input name="website" tabindex="-1" autocomplete="off"></label>
      <label>Name*<input name="name" required></label><label>Email*<input type="email" name="email" required></label>
      <label>Phone<input name="phone"></label><label>Message<textarea name="message" rows="5"></textarea></label>
      <button class="btn" type="submit">Send</button>
    </form><?php return ob_get_clean();
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
