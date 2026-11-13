<?php
/**
 * Run inside WordPress: wp eval-file tests/staging-guard.php
 * Staging must not mint production booking refs or accept a public request.
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Load this with wp eval-file\n");
    exit(1);
}

$GLOBALS['brb_fail'] = 0;
$GLOBALS['brb_ok'] = 0;
function brb_t($cond, $msg) {
    if ($cond) {
        $GLOBALS['brb_ok']++;
        echo "ok  $msg\n";
    } else {
        $GLOBALS['brb_fail']++;
        echo "FAIL $msg\n";
    }
}
function brb_guard_env($value) {
    if ($value === null || $value === '') {
        putenv('BRB_ENV');
        unset($_ENV['BRB_ENV'], $_SERVER['BRB_ENV']);
        return;
    }
    putenv('BRB_ENV=' . $value);
    $_ENV['BRB_ENV'] = $value;
    $_SERVER['BRB_ENV'] = $value;
}
function brb_guard_pair($user, $pass) {
    foreach (['BRB_STAGING_USER' => $user, 'BRB_STAGING_PASS' => $pass] as $key => $value) {
        if ($value === null || $value === '') {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
            continue;
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
function brb_guard_post($email, $date) {
    return [
        'date' => $date,
        'type' => 'day',
        'days' => 1,
        'guests' => 4,
        'name' => 'Staging Guard',
        'email' => $email,
        'phone' => '555-0100',
        'policy' => '1',
        'waiver' => '1',
        'free_admission' => '1',
        'event_about' => 'A private dinner for family.',
    ];
}
function brb_guard_yoast_robots_sample() {
    // The block Yoast 28.8 appends at priority 99999 when nothing overrides it.
    return "User-agent: *\nDisallow:\n\n# START YOAST BLOCK\n# ---------------------------\nUser-agent: *\nDisallow:\n\nSitemap: https://boardroom-staging.madproductions.us/sitemap_index.xml\n# ---------------------------\n# END YOAST BLOCK";
}
function brb_guard_robots_clean($txt) {
    return !preg_match('/^Disallow:\s*$/m', (string) $txt)
        && stripos((string) $txt, 'Sitemap:') === false
        && stripos((string) $txt, 'YOAST') === false;
}
function brb_guard_clear_yoast_page() {
    if (!function_exists('YoastSEO')) return;
    try {
        $memo = YoastSEO()->classes->get('Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer');
        $memo->clear_for_current_page();
        $memo->clear();
    } catch (\Throwable $e) {
        unset($e);
    }
}
function brb_guard_front_head() {
    global $wp_query, $wp_the_query;
    $saved_query = $wp_query;
    $saved_the = $wp_the_query;
    $front_id = (int) get_option('page_on_front');
    $q = $front_id ? new WP_Query(['page_id' => $front_id]) : new WP_Query(['post_type' => 'page', 'pagename' => 'home']);
    $wp_query = $q;
    $wp_the_query = $q;
    // A real request syncs blog_public on init for its own environment.
    brb_apply_search_visibility();
    brb_guard_clear_yoast_page();
    ob_start();
    do_action('wp_head');
    $head = ob_get_clean();
    brb_guard_clear_yoast_page();
    $wp_query = $saved_query;
    $wp_the_query = $saved_the;
    return $head;
}
function brb_guard_robots_meta($head) {
    preg_match_all('/<meta name=["\']robots["\'] content=["\']([^"\']*)["\']/', (string) $head, $m);
    return $m[1];
}
function brb_guard_open_date($start) {
    for ($i = $start; $i < $start + 40; $i++) {
        $try = brb_now()->modify('+' . $i . ' days')->format('Y-m-d');
        $range = brb_build_range(['date' => $try, 'type' => 'day', 'days' => 1, 'guests' => 4]);
        if (!is_wp_error($range) && brb_is_free($range['s'], $range['e'])) return $try;
    }
    return '';
}

global $wpdb;
$saved_env = getenv('BRB_ENV');
$saved_blog_public = get_option('blog_public');
$saved_user = getenv('BRB_STAGING_USER');
$saved_pass = getenv('BRB_STAGING_PASS');
$previous_user = get_current_user_id();
$created = [];
add_filter('pre_wp_mail', function ($short, $atts) {
    $GLOBALS['brb_mails'][] = $atts;
    return true;
}, 10, 2);
$GLOBALS['brb_mails'] = [];

try {
    brb_t(get_option('brb_version') === '2.5.74', 'plugin storage version is 2.5.74');
    wp_set_current_user(0);
    $sample = (object) ['id' => 7];
    $saved_http_host = array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : null;
    brb_guard_env('');
    brb_t(brb_is_production() && brb_ref($sample) === 'BR-7', 'empty BRB_ENV is production and uses BR-<id>');
    brb_guard_env('prodution');
    brb_t(brb_is_production() && brb_ref($sample) === 'BR-7', 'a misspelled BRB_ENV is production and uses BR-<id>');
    $open_date = brb_guard_open_date(40);
    brb_t($open_date !== '', 'an open date is available for the submit checks');
    $empty_email = 'staging-guard-empty@example.com';
    $empty_before = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_table() . ' WHERE email=%s', $empty_email));
    $empty_booking = $open_date === '' ? new WP_Error('date', 'no date') : brb_process_public_booking(brb_guard_post($empty_email, $open_date));
    if ($empty_booking && !is_wp_error($empty_booking)) $created[] = (int) $empty_booking->id;
    $empty_after = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_table() . ' WHERE email=%s', $empty_email));
    brb_t(!is_wp_error($empty_booking) && $empty_booking && $empty_after === $empty_before + 1 && brb_ref($empty_booking) === 'BR-' . (int) $empty_booking->id, 'empty BRB_ENV allows a public submit and uses BR-<id>');
    $typo_date = brb_guard_open_date(50);
    brb_guard_env('stage');
    $typo_email = 'staging-guard-typo@example.com';
    $typo_before = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_table() . ' WHERE email=%s', $typo_email));
    $typo_booking = $typo_date === '' ? new WP_Error('date', 'no date') : brb_process_public_booking(brb_guard_post($typo_email, $typo_date));
    if ($typo_booking && !is_wp_error($typo_booking)) $created[] = (int) $typo_booking->id;
    $typo_after = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_table() . ' WHERE email=%s', $typo_email));
    brb_t(!is_wp_error($typo_booking) && $typo_booking && $typo_after === $typo_before + 1 && brb_ref($typo_booking) === 'BR-' . (int) $typo_booking->id, 'a misspelled BRB_ENV allows a public submit and uses BR-<id>');
    foreach (['staging', 'dev', 'test'] as $mode) {
        brb_guard_env($mode);
        brb_t(!brb_is_production() && brb_ref($sample) === 'BR-S7', $mode . ' ref has BR-S prefix');
    }
    putenv('BRB_ENV= STAGING ');
    $_ENV['BRB_ENV'] = ' STAGING ';
    $_SERVER['BRB_ENV'] = ' STAGING ';
    brb_t(!brb_is_production() && brb_ref($sample) === 'BR-S7', 'STAGING is case-insensitive and trimmed');
    brb_guard_env('staging');
    $_SERVER['HTTP_HOST'] = 'boardroomqc.com';
    brb_t(brb_is_production() && brb_ref($sample) === 'BR-7', 'boardroomqc.com host with BRB_ENV=staging is production');
    $_SERVER['HTTP_HOST'] = 'www.boardroomqc.com';
    brb_t(brb_is_production() && brb_ref($sample) === 'BR-7', 'www.boardroomqc.com with BRB_ENV=staging is production');
    $_SERVER['HTTP_HOST'] = 'boardroomqc.com.';
    brb_t(brb_is_production() && brb_ref($sample) === 'BR-7', 'a trailing dot on boardroomqc.com is production');
    $_SERVER['HTTP_HOST'] = 'www.boardroomqc.com.';
    brb_t(brb_is_production() && brb_ref($sample) === 'BR-7', 'a trailing dot on www.boardroomqc.com is production');
    $_SERVER['HTTP_HOST'] = 'BoardroomQC.com:443';
    brb_t(brb_is_production() && brb_ref($sample) === 'BR-7', 'mixed case and a port are production');
    $_SERVER['HTTP_HOST'] = 'boardroomqc.com.evil.test';
    brb_t(!brb_is_production() && brb_ref($sample) === 'BR-S7', 'a host that only starts with boardroomqc.com is not production');
    $_SERVER['HTTP_HOST'] = 'xboardroomqc.com';
    brb_t(!brb_is_production() && brb_ref($sample) === 'BR-S7', 'a host that only ends with boardroomqc.com is not production');
    if ($saved_http_host === null) unset($_SERVER['HTTP_HOST']);
    else $_SERVER['HTTP_HOST'] = $saved_http_host;
    $home_cb = function ($url) {
        unset($url);
        return 'https://boardroomqc.com/';
    };
    add_filter('home_url', $home_cb);
    brb_t(brb_is_production() && brb_ref($sample) === 'BR-7', 'a boardroomqc.com home_url with BRB_ENV=staging is production');
    remove_filter('home_url', $home_cb);
    $home_dot = function ($url) {
        unset($url);
        return 'https://BoardroomQC.com.:443/';
    };
    add_filter('home_url', $home_dot);
    brb_t(brb_is_production() && brb_ref($sample) === 'BR-7', 'a mixed-case home_url with a port and a trailing dot is production');
    remove_filter('home_url', $home_dot);
    brb_guard_env('');
    $_SERVER['HTTP_HOST'] = 'boardroom-staging.madproductions.us';
    brb_t(!brb_is_production() && brb_ref($sample) === 'BR-S7', 'the staging host with empty BRB_ENV is non-production');
    $_SERVER['HTTP_HOST'] = 'boardroom-staging.madproductions.us.';
    brb_t(!brb_is_production() && brb_ref($sample) === 'BR-S7', 'the staging host with a trailing dot is non-production');
    $_SERVER['HTTP_HOST'] = 'Boardroom-Staging.madproductions.us:443';
    brb_t(!brb_is_production() && brb_ref($sample) === 'BR-S7', 'the staging host with a port is non-production');
    $_SERVER['HTTP_HOST'] = 'boardroom-staging.madproductions.us.evil.test';
    brb_t(brb_is_production() && brb_ref($sample) === 'BR-7', 'a lookalike staging host does not count as staging');
    if ($saved_http_host === null) unset($_SERVER['HTTP_HOST']);
    else $_SERVER['HTTP_HOST'] = $saved_http_host;
    $home_stage = function ($url) {
        unset($url);
        return 'https://boardroom-staging.madproductions.us./';
    };
    add_filter('home_url', $home_stage);
    brb_t(!brb_is_production() && brb_ref($sample) === 'BR-S7', 'a staging home_url with a trailing dot and empty BRB_ENV is non-production');
    remove_filter('home_url', $home_stage);
    brb_guard_env('production');
    $_SERVER['HTTP_HOST'] = 'boardroom-staging.madproductions.us';
    brb_t(!brb_is_production() && brb_ref($sample) === 'BR-S7', 'the staging host stays non-production when BRB_ENV=production');
    if ($saved_http_host === null) unset($_SERVER['HTTP_HOST']);
    else $_SERVER['HTTP_HOST'] = $saved_http_host;
    brb_guard_env('staging');
    brb_t(brb_ref($sample) === 'BR-S7', 'staging ref has BR-S prefix');
    brb_t(brb_ref_id('BR-S7') === 7 && brb_ref_id('br-12') === 12 && brb_ref_id('BR-S') === 0, 'search reads BR-S and BR- refs back to the row id');

    $GLOBALS['brb_mails'] = [];
    brb_mail('staging-guard@example.com', 'Boardroom email test', 'Hello from staging.');
    $staged_subject = (string) ($GLOBALS['brb_mails'][0]['subject'] ?? '');
    brb_t(strpos($staged_subject, '[STAGING]') === 0 && strpos($staged_subject, 'Boardroom email test') !== false, 'staging mail subject has [STAGING]');
    $GLOBALS['brb_mails'] = [];
    brb_mail('staging-guard@example.com', '[STAGING] Already marked', 'Once.');
    brb_t(($GLOBALS['brb_mails'][0]['subject'] ?? '') === '[STAGING] Already marked', 'an existing staging prefix is not doubled');

    $date = brb_guard_open_date(40);
    brb_t($date !== '', 'an open date is available for the submit checks');
    $book_email = 'staging-guard-book@example.com';
    $before_rows = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_table() . ' WHERE email=%s', $book_email));
    $rejected = $date === '' ? new WP_Error('date', 'no date') : brb_process_public_booking(brb_guard_post($book_email, $date));
    $after_rows = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_table() . ' WHERE email=%s', $book_email));
    brb_t(is_wp_error($rejected) && $rejected->get_error_code() === 'staging' && strpos($rejected->get_error_message(), 'https://boardroomqc.com/book/') !== false && $after_rows === $before_rows, 'staging public submit rejected and creates no row');

    delete_transient(brb_tour_rate_key());
    $tour_email = 'staging-guard-tour@example.com';
    $tour_before = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_tour_table() . ' WHERE email=%s', $tour_email));
    $tour = brb_tour_submit([
        'name' => 'Staging Guest',
        'email' => $tour_email,
        'message' => 'Can we see the room?',
        'preferred' => $date,
    ]);
    $tour_after = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_tour_table() . ' WHERE email=%s', $tour_email));
    brb_t(is_wp_error($tour) && $tour->get_error_code() === 'staging' && $tour_after === $tour_before, 'staging contact submit creates no inquiry');

    $chat_email = 'staging-guard-chat@example.com';
    $chat_before = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_tour_table() . ' WHERE email=%s', $chat_email));
    $lead = brb_chat_save_lead([
        'name' => 'Staging Chat',
        'email' => $chat_email,
        'note' => 'Looking at a date.',
        'consent' => '1',
    ]);
    $chat_after = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_tour_table() . ' WHERE email=%s', $chat_email));
    brb_t(is_wp_error($lead) && $lead->get_error_code() === 'staging' && $chat_after === $chat_before, 'staging Walter chat does not store a guest lead');

    $_SERVER['HTTP_ORIGIN'] = home_url('/');
    $rest_email = 'staging-guard-rest@example.com';
    $rest_before = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_tour_table() . ' WHERE email=%s', $rest_email));
    $rest = new WP_REST_Request('POST', '/brb/v1/chat-lead');
    $rest->set_header('X-BRB-Chat', wp_create_nonce('brb_chat'));
    $rest->set_param('name', 'Staging REST');
    $rest->set_param('email', $rest_email);
    $rest->set_param('note', 'Please call me.');
    $rest->set_param('consent', '1');
    $rest_result = rest_do_request($rest);
    $rest_after = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_tour_table() . ' WHERE email=%s', $rest_email));
    $rest_message = '';
    if ($rest_result->is_error()) {
        $err = $rest_result->as_error();
        $rest_message = $err ? $err->get_error_message() : '';
    }
    brb_t($rest_result->is_error() && strpos($rest_message, 'https://boardroomqc.com/book/') !== false && $rest_after === $rest_before, 'staging chat-lead REST creates no inquiry');

    $tour_html = brb_tour_form();
    brb_t(strpos($tour_html, 'https://boardroomqc.com/book/') !== false && strpos($tour_html, 'type="submit"') === false && strpos($tour_html, 'name="message"') === false, 'the public contact form points guests to the real site');
    $saved_get = $_GET;
    $_GET = $date === '' ? [] : ['date' => $date, 'type' => 'day', 'days' => 1, 'guests' => 4];
    $book_html = do_shortcode('[boardroom_booking]');
    $_GET = $saved_get;
    brb_t($date !== '' && strpos($book_html, 'https://boardroomqc.com/book/') !== false && strpos($book_html, 'Submit request') === false, 'the public reserve form points guests to the real site');
    $chat_html = brb_chat_markup();
    brb_t(strpos($chat_html, 'https://boardroomqc.com/book/') !== false && strpos($chat_html, 'brb-chat-lead') === false && strpos($chat_html, 'Send to the team') === false, 'Walter does not offer a guest lead form on staging');

    $robots = apply_filters('robots_txt', "User-agent: *\nDisallow: /wp-admin/\n", true);
    brb_t((bool) preg_match('/^Disallow:\s*\/\s*$/m', $robots), 'staging robots.txt disallows the whole site');
    $staging_robots = "User-agent: *\nDisallow: /\n";
    $ours_at = has_filter('robots_txt', 'brb_staging_robots_txt');
    brb_t(is_int($ours_at) && $ours_at >= 100000, 'the staging robots.txt filter runs at priority 100000 or later');
    $yoast_at = false;
    if (function_exists('YoastSEO')) {
        try {
            $yoast_at = has_filter('robots_txt', [YoastSEO()->classes->get('Yoast\WP\SEO\Integrations\Front_End\Robots_Txt_Integration'), 'filter_robots']);
        } catch (\Throwable $e) {
            $yoast_at = false;
        }
    }
    brb_t(defined('WPSEO_VERSION') && is_int($yoast_at) && is_int($ours_at) && $ours_at > $yoast_at, 'Yoast filter_robots is active and the staging filter runs after it');
    foreach ([true, false, '1', '0'] as $robots_public) {
        $robots_full = apply_filters('robots_txt', "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n", $robots_public);
        brb_t($robots_full === $staging_robots, 'staging robots.txt after Yoast is exactly Disallow: / (public=' . var_export($robots_public, true) . ')');
        brb_t(brb_guard_robots_clean($robots_full), 'staging robots.txt after Yoast has no empty Disallow, Sitemap, or Yoast block (public=' . var_export($robots_public, true) . ')');
    }
    brb_t(brb_staging_robots_txt(brb_guard_yoast_robots_sample(), '1') === $staging_robots, 'a Yoast-style block with an empty Disallow and a Sitemap is replaced on staging');
    $stg_robots = apply_filters('wpseo_robots_array', ['index' => 'index', 'follow' => 'follow', 'max-snippet' => 'max-snippet:-1', 'max-image-preview' => 'max-image-preview:large', 'max-video-preview' => 'max-video-preview:-1']);
    brb_t(is_array($stg_robots) && ($stg_robots['index'] ?? '') === 'noindex' && ($stg_robots['follow'] ?? '') === 'nofollow' && !isset($stg_robots['max-snippet']) && !isset($stg_robots['max-image-preview']) && !isset($stg_robots['max-video-preview']), 'staging Yoast robots array is noindex, nofollow');
    $stg_head = brb_guard_front_head();
    $stg_meta = brb_guard_robots_meta($stg_head);
    brb_t(count($stg_meta) === 1 && $stg_meta[0] === 'noindex, nofollow', 'staging homepage head with Yoast has one robots meta: noindex, nofollow (got ' . implode(' | ', $stg_meta) . ')');
    // DISCOURAGE_SEARCH=0 leaves blog_public on, so only the Yoast filter keeps staging out of search.
    $saved_discourage = getenv('DISCOURAGE_SEARCH');
    putenv('DISCOURAGE_SEARCH=0');
    $_ENV['DISCOURAGE_SEARCH'] = '0';
    $_SERVER['DISCOURAGE_SEARCH'] = '0';
    $stg_open_head = brb_guard_front_head();
    $stg_open_public = (string) get_option('blog_public');
    if (is_string($saved_discourage)) {
        putenv('DISCOURAGE_SEARCH=' . $saved_discourage);
        $_ENV['DISCOURAGE_SEARCH'] = $saved_discourage;
        $_SERVER['DISCOURAGE_SEARCH'] = $saved_discourage;
    } else {
        putenv('DISCOURAGE_SEARCH');
        unset($_ENV['DISCOURAGE_SEARCH'], $_SERVER['DISCOURAGE_SEARCH']);
    }
    brb_apply_search_visibility();
    $stg_open_meta = brb_guard_robots_meta($stg_open_head);
    brb_t($stg_open_public === '1' && count($stg_open_meta) === 1 && $stg_open_meta[0] === 'noindex, nofollow', 'staging with blog_public on and Yoast active is still noindex, nofollow (got ' . implode(' | ', $stg_open_meta) . ')');
    ob_start();
    brb_staging_banner();
    $banner = ob_get_clean();
    brb_t(strpos($banner, 'STAGING - not the real site') !== false, 'the staging banner names the site');

    brb_guard_pair('', '');
    brb_t(!brb_staging_basic_configured(), 'basic auth stays off when the staging password is unset');
    brb_guard_pair('stage', 'secret');
    brb_t(brb_staging_basic_configured() && !brb_staging_basic_exempt(), 'basic auth is required for a logged-out staging guest');
    $_SERVER['PHP_AUTH_USER'] = 'stage';
    $_SERVER['PHP_AUTH_PW'] = 'secret';
    brb_t(brb_staging_basic_credentials_match(), 'the configured staging username and password match');
    $_SERVER['PHP_AUTH_PW'] = 'nope';
    brb_t(!brb_staging_basic_credentials_match(), 'a wrong staging password does not match');
    unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
    $saved_uri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_URI'] = '/wp-cron.php?doing_wp_cron=1';
    brb_t(brb_staging_basic_exempt(), 'wp-cron skips staging basic auth');
    foreach ([
        '/wp-json/brb/v1/stripe-webhook',
        '/wp-json/brb/v1/stripe-webhook/',
        '/index.php?rest_route=/brb/v1/stripe-webhook',
        '/?rest_route=/brb/v1/stripe-webhook',
    ] as $webhook_uri) {
        $_SERVER['REQUEST_URI'] = $webhook_uri;
        unset($_GET['rest_route']);
        brb_t(brb_staging_basic_exempt(), 'stripe webhook ' . $webhook_uri . ' is not challenged by basic auth');
    }
    $_SERVER['REQUEST_URI'] = '/';
    $_GET['rest_route'] = '/brb/v1/stripe-webhook';
    brb_t(brb_staging_basic_exempt(), 'stripe webhook rest_route query is not challenged by basic auth');
    unset($_GET['rest_route']);
    $_SERVER['REQUEST_URI'] = '/reserve-the-boardroom-quad-cities/';
    brb_t(!brb_staging_basic_exempt(), 'a normal staging page still requires basic auth');
    if ($saved_uri === null) unset($_SERVER['REQUEST_URI']);
    else $_SERVER['REQUEST_URI'] = $saved_uri;

    $admin = get_user_by('login', 'admin');
    brb_t((bool) $admin, 'admin user exists for the staging submit check');
    if ($admin) wp_set_current_user($admin->ID);
    brb_t(brb_staging_basic_exempt() && brb_public_writes_open(), 'a logged-in admin skips basic auth and can still submit on staging');
    $admin_date = brb_guard_open_date(80);
    $admin_email = 'staging-guard-admin@example.com';
    $admin_booking = ($admin && $admin_date !== '') ? brb_process_public_booking(brb_guard_post($admin_email, $admin_date)) : new WP_Error('date', 'no date');
    if ($admin_booking && !is_wp_error($admin_booking)) $created[] = (int) $admin_booking->id;
    brb_t(!is_wp_error($admin_booking) && $admin_booking && brb_ref($admin_booking) === 'BR-S' . (int) $admin_booking->id, 'an admin test on staging is stored with a BR-S ref');
    wp_set_current_user(0);

    brb_guard_env('production');
    brb_t(brb_ref($sample) === 'BR-7', 'production ref format unchanged');
    brb_t(brb_staging_basic_exempt(), 'production does not ask for staging basic auth');
    $robots_live = apply_filters('robots_txt', "User-agent: *\nDisallow: /wp-admin/\n", true);
    brb_t(!preg_match('/^Disallow:\s*\/\s*$/m', $robots_live), 'production robots.txt is not forced to disallow the whole site');
    brb_t($robots_live !== "User-agent: *\nDisallow: /\n" && brb_staging_robots_txt($robots_live, true) === $robots_live, 'production robots.txt is left exactly as Yoast and WordPress built it');
    brb_t(brb_staging_robots_txt(brb_guard_yoast_robots_sample(), '1') === brb_guard_yoast_robots_sample(), 'production keeps the Yoast block untouched');
    $live_robots = apply_filters('wpseo_robots_array', ['index' => 'noindex', 'follow' => 'nofollow', 'max-snippet' => 'max-snippet:-1']);
    brb_t(is_array($live_robots) && ($live_robots['index'] ?? '') === 'index' && ($live_robots['follow'] ?? '') === 'follow' && ($live_robots['max-snippet'] ?? '') === 'max-snippet:-1', 'production Yoast robots array stays index, follow');
    $live_head = brb_guard_front_head();
    $live_meta = brb_guard_robots_meta($live_head);
    brb_t(count($live_meta) === 1 && strpos($live_meta[0], 'index, follow') === 0 && stripos($live_meta[0], 'noindex') === false && stripos($live_meta[0], 'nofollow') === false, 'production homepage head with Yoast is index, follow (got ' . implode(' | ', $live_meta) . ')');
    ob_start();
    brb_staging_banner();
    $live_banner = ob_get_clean();
    brb_t($live_banner === '', 'production does not print the staging banner');
    $GLOBALS['brb_mails'] = [];
    brb_mail('staging-guard@example.com', 'Boardroom email test', 'Hello from production.');
    brb_t(($GLOBALS['brb_mails'][0]['subject'] ?? '') === 'Boardroom email test', 'production mail subject is not prefixed');

    $next = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        brb_table()
    ));
    $live_date = brb_guard_open_date(120);
    $live = $live_date === '' ? new WP_Error('date', 'no date') : brb_process_public_booking(brb_guard_post('staging-guard-live@example.com', $live_date));
    if ($live && !is_wp_error($live)) $created[] = (int) $live->id;
    brb_t(!is_wp_error($live) && $live && (int) $live->id === $next && brb_ref($live) === 'BR-' . (int) $live->id, 'production submit still works and gets next id');
} finally {
    if ($created) {
        $in = implode(',', array_map('intval', $created));
        $wpdb->query("DELETE FROM " . brb_pay_table() . " WHERE booking_id IN ($in)");
        $wpdb->query("DELETE FROM " . brb_line_table() . " WHERE booking_id IN ($in)");
        $wpdb->query("DELETE FROM " . brb_table() . " WHERE id IN ($in)");
    }
    foreach (['staging-guard-book@example.com', 'staging-guard-admin@example.com', 'staging-guard-live@example.com', 'staging-guard-empty@example.com', 'staging-guard-typo@example.com'] as $email) {
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . brb_table() . ' WHERE email=%s', $email));
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $wpdb->query("DELETE FROM " . brb_pay_table() . " WHERE booking_id IN ($in)");
            $wpdb->query("DELETE FROM " . brb_line_table() . " WHERE booking_id IN ($in)");
            $wpdb->query("DELETE FROM " . brb_table() . " WHERE id IN ($in)");
        }
    }
    foreach (['staging-guard-tour@example.com', 'staging-guard-chat@example.com', 'staging-guard-rest@example.com'] as $email) {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . brb_tour_table() . ' WHERE email=%s', $email));
    }
    wp_set_current_user($previous_user);
    brb_guard_env(is_string($saved_env) ? $saved_env : '');
    update_option('blog_public', $saved_blog_public);
    brb_guard_pair(is_string($saved_user) ? $saved_user : '', is_string($saved_pass) ? $saved_pass : '');
    unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
    echo "\n{$GLOBALS['brb_ok']} passed, {$GLOBALS['brb_fail']} failed\n";
}

exit($GLOBALS['brb_fail'] ? 1 : 0);
