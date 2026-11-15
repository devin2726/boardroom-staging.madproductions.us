<?php
/**
 * [brb_owner_view] read-only owner calendar. Run with wp eval-file.
 * Fake data only. Password guard, calendar day status from real block data,
 * day cards, upcoming list, no past/closed rows, escaping, no mutation links,
 * no prices, no hold notes, no mail, no writes, and the privacy filters
 * (robots, no-store, sitemaps, search, feeds, REST, oEmbed, page lists).
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('brb_t')) {
    $GLOBALS['brb_fail'] = 0;
    $GLOBALS['brb_ok'] = 0;
    function brb_t($cond, $msg) {
        if ($cond) { $GLOBALS['brb_ok']++; echo "ok  $msg\n"; }
        else { $GLOBALS['brb_fail']++; echo "FAIL $msg\n"; }
    }
}

$GLOBALS['brb_ov_mail'] = 0;
add_filter('wp_mail', function ($args) { $GLOBALS['brb_ov_mail']++; return $args; }, 1);
add_filter('pre_wp_mail', function () { return false; }, 1);

function brb_ov_t_insert($day, $name, $status, $extra = []) {
    global $wpdb;
    $row = array_merge([
        'token' => wp_generate_password(32, false),
        'mode' => 'day',
        'start_dt' => $day . ' 10:00:00',
        'end_dt' => $day . ' 23:30:00',
        'guests' => 30,
        'name' => $name,
        'email' => 'owner-view-test@example.com',
        'phone' => '',
        'notes' => '',
        'subtotal' => 900, 'total' => 977.77, 'amount_due' => 977.77, 'deposit_due' => 488.88, 'amount_paid' => 0,
        'status' => $status,
        'source' => 'online',
        'price_set' => 1,
        'event_type' => 'birthday',
        'created' => current_time('mysql', true),
    ], $extra);
    return $wpdb->insert(brb_table(), $row) ? (int) $wpdb->insert_id : 0;
}
function brb_ov_t_hold($day, $name, $type, $notes = '') {
    global $wpdb;
    $ok = $wpdb->insert(brb_hold_table(), [
        'name' => $name, 'hold_type' => $type,
        'start_dt' => $day . ' 00:00:00', 'end_dt' => $day . ' 23:59:00',
        'notes' => $notes, 'created' => current_time('mysql', true),
    ]);
    return $ok ? (int) $wpdb->insert_id : 0;
}
function brb_ov_t_fingerprint() {
    global $wpdb;
    return md5(wp_json_encode([
        $wpdb->get_results('SELECT * FROM ' . brb_table() . ' ORDER BY id', ARRAY_A),
        $wpdb->get_results('SELECT * FROM ' . brb_hold_table() . ' ORDER BY id', ARRAY_A),
        $wpdb->get_results('SELECT * FROM ' . brb_pay_table() . ' ORDER BY id', ARRAY_A),
        get_option('brb_settings'),
    ]));
}
function brb_ov_t_view($page_id, $get = []) {
    $kept = $_GET;
    $_GET = $get;
    $GLOBALS['wp_query'] = new WP_Query(['page_id' => $page_id]);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    $GLOBALS['post'] = get_post($page_id);
    setup_postdata($GLOBALS['post']);
    $out = do_shortcode('[brb_owner_view]');
    $_GET = $kept;
    return $out;
}

if (empty($_SERVER['SERVER_NAME'])) $_SERVER['SERVER_NAME'] = 'localhost';
global $wpdb;
$kept_errors = $wpdb->hide_errors();
$snapshot = get_option('brb_settings');
$made_b = []; $made_h = []; $made_p = [];
$unlock = function () { return false; };
try {
    brb_t(get_option('brb_version') === '2.5.81', 'plugin storage version is 2.5.81');
    brb_t(BRB_VER === '2.5.81', 'BRB_VER is 2.5.81');
    brb_t(shortcode_exists('brb_owner_view'), 'the [brb_owner_view] shortcode is registered');

    $tz = brb_tz();
    $today = brb_now()->setTime(0, 0);
    $m = $today->modify('first day of this month')->modify('+2 months');
    $ym = $m->format('Y-m');
    $d = function ($n) use ($m) { return $m->format('Y-m-') . sprintf('%02d', $n); };
    $past = $today->modify('-40 days')->format('Y-m-d');

    $made_b[] = $approved = brb_ov_t_insert($d(5), 'Fake Approved Person', 'approved', ['phone' => '(309) 555-0101', 'guests' => 45, 'event_type' => 'graduation', 'end_time' => '21:00', 'event_about' => "Grad party\nwith cake", 'notes' => 'Bring <b>tables</b> & chairs']);
    $made_b[] = brb_ov_t_insert($d(8), 'Fake Review Person', 'review', ['phone' => '309-555-0102']);
    $made_b[] = brb_ov_t_insert($d(18), 'Fake Cancelled Person', 'cancelled');
    $made_b[] = brb_ov_t_insert($d(20), 'Fake Paid Person', 'paid');
    $made_b[] = brb_ov_t_insert($d(26), 'Fake Deposit Person', 'deposit_paid');
    $made_b[] = brb_ov_t_insert($d(27), 'Fake Declined Person', 'declined');
    $made_b[] = brb_ov_t_insert($d(28), 'Fake Expired Person', 'expired');
    $made_b[] = brb_ov_t_insert($d(9), 'Fake Spam Person', 'spam');
    $made_b[] = brb_ov_t_insert($d(10), '<script>alert("x")</script>Evil "Name"', 'approved', ['phone' => '<img src=x onerror=alert(1)>', 'email' => 'bad"@example.com', 'event_about' => '<iframe src="//x"></iframe>']);
    $made_b[] = brb_ov_t_insert($past, 'Fake Past Person', 'paid');
    $made_h[] = brb_ov_t_hold($d(12), 'Fake Hold Name', 'hold', 'SECRET-HOLD-NOTE-1');
    $made_h[] = brb_ov_t_hold($d(22), 'Fake Reserved Name', 'reserved', 'SECRET-HOLD-NOTE-2');
    $made_h[] = brb_ov_t_hold($d(24), 'Fake Block Name', 'blocked', 'SECRET-HOLD-NOTE-3');
    $opt = is_array($snapshot) ? $snapshot : [];
    $opt['blackouts'] = $d(15);
    update_option('brb_settings', $opt);
    brb_t(count(array_filter($made_b)) === 10 && count(array_filter($made_h)) === 3, 'fake bookings and holds were seeded');

    $pw = wp_generate_password(20, false);
    $made_p[] = $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Owner calendar test', 'post_name' => 'owner-calendar-test', 'post_content' => '[brb_owner_view]', 'post_password' => $pw]);
    $made_p[] = $open_page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Owner calendar open test', 'post_content' => '[brb_owner_view]']);
    $made_p[] = $plain_pw = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Other locked test', 'post_content' => 'Hello', 'post_password' => wp_generate_password(12, false)]);
    $made_p[] = $plain = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Owner calendar plain test', 'post_content' => 'Owner calendar plain words']);
    brb_ov_post_ids(true);

    // Guards.
    $GLOBALS['post'] = null;
    $GLOBALS['wp_query'] = new WP_Query();
    brb_t(brb_ov_shortcode() === '', 'no current post: the shortcode returns an empty string');
    wp_set_current_user(0);
    $locked = brb_ov_t_view($page, ['brb_ov_m' => $ym]);
    brb_t(post_password_required($page) && $locked === '', 'password not entered: the shortcode returns an empty string');
    brb_t(strpos($locked, 'Fake') === false, 'password not entered: no booking data');
    $open = brb_ov_t_view($open_page, ['brb_ov_m' => $ym]);
    brb_t($open === '', 'page without a password, logged out: returns an empty string');
    $admin = get_user_by('login', 'brb_ov_admin');
    if (!$admin) {
        $aid = wp_insert_user(['user_login' => 'brb_ov_admin', 'user_pass' => wp_generate_password(24), 'user_email' => 'brb-ov-admin@example.com', 'role' => 'administrator']);
        $admin = is_wp_error($aid) ? null : get_user_by('id', $aid);
    }
    if ($admin) wp_set_current_user($admin->ID);
    $open_admin = brb_ov_t_view($open_page, ['brb_ov_m' => $ym]);
    brb_t(strpos($open_admin, 'password-protected') !== false && strpos($open_admin, 'Fake') === false, 'page without a password, admin: a harmless note and no data');
    wp_set_current_user(0);

    // Unlocked view.
    add_filter('post_password_required', $unlock, 999);
    $before = brb_ov_t_fingerprint();
    $mail_before = $GLOBALS['brb_ov_mail'];
    $html = brb_ov_t_view($page, ['brb_ov_m' => $ym]);
    $html_now = brb_ov_t_view($page);
    $after = brb_ov_t_fingerprint();
    brb_t($html !== '' && strpos($html, 'Bookings calendar') !== false, 'password entered: the calendar renders');
    brb_t(strpos($html, esc_html($m->format('F Y'))) !== false, 'the requested month is shown');
    foreach (['Fake Approved Person', 'Fake Review Person', 'Fake Paid Person', 'Fake Deposit Person'] as $n) {
        brb_t(strpos($html, $n) !== false, "listing shows $n");
    }
    foreach (['Fake Cancelled Person', 'Fake Declined Person', 'Fake Expired Person', 'Fake Spam Person'] as $n) {
        brb_t(strpos($html, $n) === false && strpos($html_now, $n) === false, "closed booking hidden: $n");
    }
    brb_t(strpos($html, 'Fake Past Person') === false && strpos($html_now, 'Fake Past Person') === false, 'a booking 40 days ago is not shown in this month or the upcoming list');
    brb_t(strpos($html, 'href="tel:3095550101"') !== false, 'phone is a tel: link');
    brb_t(strpos($html, 'href="mailto:owner-view-test@example.com"') !== false, 'email is a mailto: link');
    brb_t(strpos($html, 'Graduation') !== false && strpos($html, '<dd>45</dd>') !== false, 'event type and guest count are shown');
    brb_t(strpos($html, '10:00 AM – 11:30 PM') !== false && strpos($html, '9:00 PM') !== false, 'start/end time and planned end are shown');
    brb_t(strpos($html, 'Grad party<br />') !== false, 'event description keeps line breaks');
    brb_t(strpos($html, 'Bring &lt;b&gt;tables&lt;/b&gt; &amp; chairs') !== false, 'booking notes are escaped');
    brb_t(strpos($html, '<script>alert') === false && strpos($html, '&lt;script&gt;alert') !== false, 'customer name is escaped');
    brb_t(strpos($html, '<img src=x') === false && strpos($html, '<iframe') === false, 'phone and description HTML are escaped');
    brb_t(strpos($html, 'mailto:bad') === false, 'an invalid email gets no mailto link');
    $all_out = $html . $html_now;
    brb_t(strpos($all_out, 'SECRET-HOLD-NOTE') === false, 'calendar hold notes are never shown');
    foreach (['Fake Hold Name', 'Fake Reserved Name', 'Fake Block Name'] as $hn) {
        brb_t(strpos($all_out, $hn) === false, "hold name never appears: $hn");
    }
    brb_t(strpos($html, '>Held</span>') !== false && strpos($html, '>Reserved</span>') !== false && strpos($html, '>Date block</span>') !== false, 'holds show only their status label: Held, Reserved, Date block');
    $past_re = '/class="[^"]*\\bbrb-ov-past\\b/';
    brb_t(!preg_match($past_re, $html) && (preg_match($past_re, $html_now) || brb_now()->format('j') === '1'), 'past days are dimmed (this month), future months have none');
    // Day status from the plugin's block data.
    $cell = function ($n) use ($html, $d) {
        return preg_match('/<(?:a|div) class="brb-ov-day brb-ov-([a-z]+)[^"]*"[^>]*(?:href="#brb-ov-d-' . preg_quote($d($n), '/') . '"|aria-label="[^"]*")[^>]*>\s*<b>' . $n . '<\/b>/', $html, $mm) ? $mm[1] : '';
    };
    brb_t($cell(5) === 'booked', 'approved booking day is Booked');
    brb_t($cell(20) === 'booked' && $cell(26) === 'booked', 'paid and deposit-paid days are Booked');
    brb_t($cell(12) === 'held', 'calendar hold day is Held');
    brb_t($cell(22) === 'booked', 'reserved calendar hold day is Booked');
    brb_t($cell(15) === 'blocked' && $cell(24) === 'blocked', 'blackout and blocked-hold days are Blocked');
    brb_t($cell(8) === 'free' && strpos($html, 'brb-ov-req') !== false, 'a request in review leaves the day Free with a request marker');
    brb_t($cell(18) === 'free' && $cell(3) === 'free', 'cancelled and empty days are Free');
    brb_t(strpos($html, 'id="brb-ov-d-' . $d(5) . '"') !== false && strpos($html, 'href="#brb-ov-d-' . $d(5) . '"') !== false, 'tapping a day jumps to that day\'s bookings');
    brb_t(strpos($html, 'brb-ov-prev') !== false && strpos($html, 'brb-ov-next') !== false && strpos($html, 'touchstart') !== false, 'prev/next month links and swipe navigation');
    // Upcoming list.
    $up = substr($html_now, (int) strpos($html_now, '<h3>Upcoming</h3>'));
    $p1 = strpos($up, 'Fake Approved Person'); $p2 = strpos($up, 'Fake Paid Person'); $p3 = strpos($up, 'Fake Deposit Person');
    brb_t($p1 !== false && $p2 !== false && $p3 !== false && $p1 < $p2 && $p2 < $p3, 'upcoming list is sorted by date');
    // No mutation, no prices.
    foreach (['<form', 'wp-admin', 'admin-post', 'admin-ajax', '_wpnonce', 'br_token', 'br_pay', 'action=', 'method="post"', '<button', '<input'] as $bad) {
        brb_t(stripos($html . $html_now, $bad) === false, "no mutation surface: no $bad");
    }
    preg_match_all('/href="([^"]*)"/', $html . $html_now, $hrefs);
    $bad_links = array_filter($hrefs[1], function ($u) {
        return !preg_match('/^(#brb-ov-d-\d{4}-\d{2}-\d{2}|tel:[0-9+]+|mailto:[^"]+|https?:\/\/[^"]*[?&](amp;|#038;)?brb_ov_m=\d{4}-\d{2}(#brb-ov-d-\d{4}-\d{2}-\d{2})?)$/', html_entity_decode($u));
    });
    brb_t(!$bad_links, 'only links are day anchors, tel:, mailto: and month navigation');
    brb_t(strpos($html . $html_now, '$') === false && strpos($html . $html_now, '977') === false && strpos($html . $html_now, '488') === false, 'no prices or amounts are shown');
    brb_t(stripos($html . $html_now, 'air' . 'bnb') === false, 'no rental-platform name in the output');
    brb_t($before === $after, 'rendering writes nothing to bookings, holds, payments or settings');
    brb_t($GLOBALS['brb_ov_mail'] === $mail_before, 'rendering sends no email');
    brb_t(!has_filter('pre_wp_mail', 'brb_ov_block_mail'), 'the render-time mail block is removed afterward');
    $clamped = brb_ov_t_view($page, ['brb_ov_m' => '1999-01']);
    brb_t(strpos($clamped, esc_html(brb_now()->modify('first day of this month')->modify('-3 months')->format('F Y'))) !== false, 'months far back are clamped to 3 months ago');
    $junk = brb_ov_t_view($page, ['brb_ov_m' => '"><script>']);
    brb_t(strpos($junk, '"><script>') === false && strpos($junk, esc_html(brb_now()->format('F Y'))) !== false, 'a junk month falls back to this month');

    // Contexts where it must never render.
    $GLOBALS['wp_query'] = new WP_Query(['page_id' => $page, 'feed' => 'rss2']);
    $GLOBALS['post'] = get_post($page);
    brb_t(is_feed() && do_shortcode('[brb_owner_view]') === '', 'feed context: empty');
    $GLOBALS['wp_query'] = new WP_Query(['s' => 'Owner calendar']);
    $GLOBALS['post'] = get_post($page);
    brb_t(is_search() && do_shortcode('[brb_owner_view]') === '', 'search context: empty');
    $GLOBALS['wp_query'] = new WP_Query(['page_id' => $page, 'embed' => true]);
    $GLOBALS['post'] = get_post($page);
    brb_t(is_embed() && do_shortcode('[brb_owner_view]') === '', 'embed context: empty');
    remove_filter('post_password_required', $unlock, 999);

    // Privacy filters.
    $GLOBALS['wp_query'] = new WP_Query(['page_id' => $page]);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    brb_t(brb_ov_current_is_owner_page(), 'the page is detected as the owner view');
    $robots = apply_filters('wp_robots', ['index' => true, 'follow' => true, 'max-image-preview' => 'large']);
    brb_t(!empty($robots['noindex']) && !empty($robots['nofollow']) && empty($robots['index']), 'core wp_robots: noindex, nofollow');
    brb_t(apply_filters('wpseo_robots', 'index, follow') === 'noindex, nofollow', 'Yoast robots: noindex, nofollow');
    brb_t(apply_filters('wpseo_metadesc', 'desc') === '' && apply_filters('wpseo_opengraph_desc', 'desc') === '', 'Yoast description and og:description are blank');
    brb_t(has_action('template_redirect', 'brb_ov_send_headers') !== false, 'no-store/noindex headers are hooked on template_redirect');
    brb_t(apply_filters('embed_oembed_discover', true) === false, 'oEmbed discovery is off on the page');
    brb_t(get_the_title($page) === 'Owner calendar test', 'no "Protected: " prefix on the owner page title');
    brb_t(strpos(get_the_title($plain_pw), 'Protected: ') === 0, 'other password pages keep the "Protected: " prefix');
    $GLOBALS['wp_scripts'] = new WP_Scripts(); $GLOBALS['wp_styles'] = new WP_Styles();
    do_action('wp_enqueue_scripts');
    brb_t(!wp_script_is('brb-chat', 'enqueued') && !wp_style_is('brb-chat', 'enqueued'), 'Ask Walter chat assets are not enqueued on the owner page');
    ob_start(); do_action('wp_footer'); $foot = ob_get_clean();
    brb_t(strpos($foot, 'brb-chat') === false, 'Ask Walter bubble markup is not printed on the owner page');
    ob_start(); do_action('wp_head'); $head = ob_get_clean();
    brb_t(strpos($head, 'brb-ov-no-walter') !== false && strpos($head, '.footer-ask-wrap') !== false, 'the theme footer Ask Walter button is hidden on the owner page');
    $GLOBALS['wp_query'] = new WP_Query(['page_id' => $plain]);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    $GLOBALS['wp_scripts'] = new WP_Scripts(); $GLOBALS['wp_styles'] = new WP_Styles();
    do_action('wp_enqueue_scripts');
    brb_t(wp_script_is('brb-chat', 'enqueued'), 'Ask Walter still loads on other pages');
    ob_start(); do_action('wp_footer'); $foot2 = ob_get_clean();
    brb_t(strpos($foot2, 'brb-chat') !== false, 'Ask Walter bubble still prints on other pages');
    brb_t(!brb_ov_current_is_owner_page() && apply_filters('wpseo_robots', 'index, follow') === 'index, follow', 'other pages keep their robots');
    $GLOBALS['wp_query'] = new WP_Query();

    $sm = apply_filters('wp_sitemaps_posts_query_args', ['post_type' => 'page'], 'page');
    brb_t(in_array($page, (array) ($sm['post__not_in'] ?? []), true) && in_array($open_page, (array) $sm['post__not_in'], true), 'core sitemap excludes pages with the shortcode');
    if (class_exists('WP_Sitemaps_Posts')) {
        $prov = new WP_Sitemaps_Posts();
        $urls = wp_list_pluck($prov->get_url_list(1, 'page'), 'loc');
        brb_t(!in_array(get_permalink($page), $urls, true) && in_array(get_permalink($plain), $urls, true), 'core page sitemap list leaves the page out');
    }
    $yo = apply_filters('wpseo_exclude_from_sitemap_by_post_ids', [7]);
    brb_t(in_array($page, $yo, true) && in_array(7, $yo, true), 'Yoast sitemap excludes the page');
    $sq = new WP_Query(['s' => 'Owner calendar', 'post_type' => 'any']);
    $found = wp_list_pluck($sq->posts, 'ID');
    brb_t(!in_array($page, $found, true) && in_array($plain, $found, true), 'site search leaves the page out');
    $fq = new WP_Query(['feed' => 'rss2', 'post_type' => 'page']);
    brb_t(!in_array($page, wp_list_pluck($fq->posts, 'ID'), true), 'feeds leave the page out');
    $ex = apply_filters('wp_list_pages_excludes', []);
    brb_t(in_array($page, $ex, true), 'wp_list_pages excludes the page');
    $gp = wp_list_pluck(get_pages(), 'ID');
    brb_t(!in_array($page, $gp, true) && in_array($plain, $gp, true), 'get_pages (page lists) leaves the page out');
    brb_t(apply_filters('oembed_request_post_id', $page, get_permalink($page)) === 0, 'oEmbed requests for the page return nothing');
    brb_t(get_oembed_response_data_for_url(get_permalink($page), []) === false, 'oEmbed response for the page URL is false');

    // REST.
    $r = rest_do_request(new WP_REST_Request('GET', '/wp/v2/pages/' . $page));
    $data = $r->get_data();
    brb_t($r->get_status() === 200 && ($data['content']['rendered'] ?? 'x') === '' && !empty($data['content']['protected']), 'REST page without the password: protected, empty content');
    $req = new WP_REST_Request('GET', '/wp/v2/pages/' . $page);
    $req->set_param('password', $pw);
    $r2 = rest_do_request($req);
    $d2 = $r2->get_data();
    $blob = wp_json_encode($d2);
    brb_t(($d2['content']['rendered'] ?? 'x') === '' && strpos($blob, 'Fake') === false, 'REST page with the password: still no booking data');
    $r3 = rest_do_request(new WP_REST_Request('GET', '/wp/v2/pages'));
    brb_t(strpos(wp_json_encode($r3->get_data()), 'Fake Approved') === false, 'REST page list has no booking data');
    $sreq = new WP_REST_Request('GET', '/wp/v2/search');
    $sreq->set_param('search', 'Owner calendar');
    $r4 = rest_do_request($sreq);
    brb_t(!in_array($page, wp_list_pluck((array) $r4->get_data(), 'id'), true), 'REST search leaves the page out');
    brb_t($GLOBALS['brb_ov_mail'] === 0, 'no email at any point in this test');
} finally {
    remove_filter('post_password_required', $unlock, 999);
    foreach ($made_b as $id) if ($id) $wpdb->delete(brb_table(), ['id' => $id], ['%d']);
    foreach ($made_h as $id) if ($id) $wpdb->delete(brb_hold_table(), ['id' => $id], ['%d']);
    foreach ($made_p as $id) if ($id) wp_delete_post($id, true);
    if ($snapshot === false) delete_option('brb_settings'); else update_option('brb_settings', $snapshot);
    $wpdb->show_errors($kept_errors);
}
echo "\nRESULT ok={$GLOBALS['brb_ok']} fail={$GLOBALS['brb_fail']}\n";
