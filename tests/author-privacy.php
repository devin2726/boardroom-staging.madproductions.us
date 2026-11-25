<?php
/**
 * 2.5.90 author-archive privacy. Run with wp eval-file. Fake data only
 * (example.com emails, "AP Test" names). Does not rename or delete existing users.
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Load this with wp eval-file\n");
    exit(1);
}

if (!function_exists('brb_t')) {
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
}

function brb_ap_simulate($uri) {
    global $wp, $wp_query, $wp_the_query;
    $path = $uri;
    $qs = '';
    $_GET = [];
    if (strpos($uri, '?') !== false) {
        $parts = wp_parse_url($uri);
        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        $qs = $parts['query'] ?? '';
        parse_str($qs, $_GET);
    }
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['QUERY_STRING'] = $qs;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_GET['preview'], $_REQUEST['preview']);
    $wp->query_vars = [];
    $wp->query_string = '';
    $wp->request = '';
    $wp->matched_rule = '';
    $wp->matched_query = '';
    $wp->did_permalink = false;
    $wp->parse_request();
    $wp->query_posts();
    if (method_exists($wp, 'handle_404')) $wp->handle_404();
    do_action('template_redirect');
    $wp_the_query = $wp_query;
    return $wp_query;
}

function brb_ap_robots_has_noindex($robots) {
    if (is_string($robots)) {
        return (bool) preg_match('/\bnoindex\b/i', $robots);
    }
    if (!is_array($robots)) return false;
    if (!empty($robots['noindex'])) return true;
    if (($robots['index'] ?? '') === 'noindex') return true;
    return false;
}

function brb_ap_dispatch($method, $route, $params = []) {
    $request = new WP_REST_Request($method, $route);
    foreach ($params as $k => $v) $request->set_param($k, $v);
    return rest_get_server()->dispatch($request);
}

global $wp, $wp_query, $wp_the_query, $post;
$saved_query = [$wp_query, $wp_the_query, $post];
$saved_server = [
    'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
    'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? null,
    'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null,
];
$saved_get = $_GET;
$saved_user = get_current_user_id();
$created_users = [];
$created_posts = [];
$created_pages = [];

try {
    brb_t(defined('BRB_VER') && BRB_VER === '2.5.100', 'BRB_VER is 2.5.100');
    brb_t(function_exists('brb_is_public_author_request') && function_exists('brb_strip_author_urls_from_schema'), 'author-privacy functions are loaded');

    wp_set_current_user(0);

    $author_id = wp_insert_user([
        'user_login' => 'aptest_writer',
        'user_pass' => wp_generate_password(20, true, true),
        'user_email' => 'aptest-writer@example.com',
        'display_name' => 'AP Test Writer',
        'role' => 'author',
    ]);
    brb_t(!is_wp_error($author_id) && $author_id, 'a throwaway author user is created');
    if (is_wp_error($author_id)) throw new RuntimeException($author_id->get_error_message());
    $created_users[] = (int) $author_id;

    $other_id = wp_insert_user([
        'user_login' => 'aptest_other',
        'user_pass' => wp_generate_password(20, true, true),
        'user_email' => 'aptest-other@example.com',
        'display_name' => 'AP Test Other',
        'role' => 'subscriber',
    ]);
    brb_t(!is_wp_error($other_id) && $other_id, 'a throwaway subscriber user is created');
    if (is_wp_error($other_id)) throw new RuntimeException($other_id->get_error_message());
    $created_users[] = (int) $other_id;

    $post_id = wp_insert_post([
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_title' => 'AP Test Planning Guide',
        'post_name' => 'ap-test-planning-guide',
        'post_content' => 'A throwaway planning guide used only in author-privacy tests.',
        'post_excerpt' => 'A throwaway excerpt.',
        'post_author' => (int) $author_id,
        'post_date' => '2026-11-10 09:00:00',
    ], true);
    brb_t(!is_wp_error($post_id) && $post_id, 'a published post by the throwaway author is created');
    if (is_wp_error($post_id)) throw new RuntimeException($post_id->get_error_message());
    $created_posts[] = (int) $post_id;

    $blog_id = (int) get_option('page_for_posts');
    if (!$blog_id) {
        $blog_id = (int) wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Blog',
            'post_name' => 'blog',
        ]);
        $created_pages[] = $blog_id;
        update_option('page_for_posts', $blog_id);
    }
    if (get_option('show_on_front') !== 'page') {
        $front = (int) get_option('page_on_front');
        if (!$front) {
            $front = (int) wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => 'Home',
                'post_name' => 'home',
            ]);
            $created_pages[] = $front;
            update_option('page_on_front', $front);
        }
        update_option('show_on_front', 'page');
    }

    $slug = 'aptest_writer';
    $author_path = '/author/' . $slug . '/';
    $feed_path = '/author/' . $slug . '/feed/';

    $q = brb_ap_simulate($author_path);
    brb_t(!empty($q->is_404) && empty($q->is_author), '/author/{slug}/ is a 404, not an author archive');
    brb_t(brb_is_public_author_request($q), '/author/{slug}/ is detected as an author request after the 404');
    brb_t(brb_ap_robots_has_noindex(apply_filters('wp_robots', [])), '/author/{slug}/ wp_robots is noindex');
    brb_t(brb_ap_robots_has_noindex(apply_filters('wpseo_robots', 'index, follow')), '/author/{slug}/ wpseo_robots is noindex');
    brb_t(brb_ap_robots_has_noindex(apply_filters('wpseo_robots_array', ['index' => 'index', 'follow' => 'follow'])), '/author/{slug}/ wpseo_robots_array is noindex');

    $q = brb_ap_simulate($feed_path);
    brb_t(!empty($q->is_404) && empty($q->is_feed) && empty($q->is_author), '/author/{slug}/feed/ is a 404, not a feed');

    $q = brb_ap_simulate('/?author=' . (int) $author_id);
    brb_t(!empty($q->is_404) && empty($q->is_author), '?author={id} for a published author is a 404, not a redirect to /author/');
    brb_t(apply_filters('redirect_canonical', 'http://localhost/author/' . $slug . '/', 'http://localhost/?author=' . (int) $author_id) === false, 'canonical redirect is disabled on ?author={id}');

    $q = brb_ap_simulate('/?author=' . (int) $other_id);
    brb_t(!empty($q->is_404) && empty($q->is_author), '?author={id} for a user with no posts is a 404');

    $q = brb_ap_simulate('/?author=1');
    brb_t(!empty($q->is_404), '?author=1 is a 404 so IDs cannot be enumerated');

    $q = brb_ap_simulate('/?author=999999');
    brb_t(!empty($q->is_404), '?author= for a missing id is a 404');

    $q = brb_ap_simulate('/author/does-not-exist-aptest/');
    brb_t(!empty($q->is_404), 'an unknown /author/{slug}/ is a 404');

    // Blog post and posts page stay available and do not link to author archives.
    $q = brb_ap_simulate('/ap-test-planning-guide/');
    brb_t(empty($q->is_404) && !empty($q->is_singular) && (int) $q->get_queried_object_id() === (int) $post_id, 'the blog post itself still loads');
    $GLOBALS['post'] = get_post($post_id);
    setup_postdata($GLOBALS['post']);
    $author_link_html = get_the_author_posts_link();
    brb_t(strpos($author_link_html, '/author/') === false && strpos($author_link_html, '?author=') === false, 'get_the_author_posts_link has no author-archive URL');
    brb_t(strpos($author_link_html, 'AP Test Writer') !== false, 'the display name is still shown without a link');
    brb_t(get_author_posts_url((int) $author_id) === '', 'get_author_posts_url is empty');
    wp_reset_postdata();

    $q = brb_ap_simulate('/blog/');
    brb_t(empty($q->is_404) && !empty($q->is_home) && empty($q->is_author), '/blog/ still loads and is not an author request');

    // Do not run template_redirect for these: /staff/ redirects and exits when logged out.
    $prev_uri = $_SERVER['REQUEST_URI'] ?? '';
    $prev_get = $_GET;
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/wp-login.php';
    $_SERVER['QUERY_STRING'] = '';
    brb_t(!brb_is_public_author_request(), 'wp-login is not treated as an author request');
    $_SERVER['REQUEST_URI'] = '/staff/';
    brb_t(!brb_is_public_author_request(), '/staff/ is not treated as an author request');
    $_SERVER['REQUEST_URI'] = $prev_uri;
    $_GET = $prev_get;

    wp_set_current_user(0);
    $logged_out_list = brb_ap_dispatch('GET', '/wp/v2/users');
    brb_t((int) $logged_out_list->get_status() === 401, 'logged-out GET /wp/v2/users is 401');
    $logged_out_one = brb_ap_dispatch('GET', '/wp/v2/users/' . (int) $author_id);
    brb_t((int) $logged_out_one->get_status() === 401, 'logged-out GET /wp/v2/users/{id} is 401');
    $logged_out_slug = brb_ap_dispatch('GET', '/wp/v2/users', ['slug' => $slug]);
    brb_t((int) $logged_out_slug->get_status() === 401, 'logged-out GET /wp/v2/users?slug= is 401');
    $logged_out_body = wp_json_encode($logged_out_list->get_data());
    brb_t(strpos((string) $logged_out_body, $slug) === false && strpos((string) $logged_out_body, 'AP Test Writer') === false, 'the 401 body does not list the throwaway user');

    $admin = get_user_by('login', 'qa');
    if (!$admin) $admin = get_user_by('login', 'admin');
    brb_t($admin && $admin->ID, 'a logged-in admin user exists for the REST check');
    if ($admin) {
        wp_set_current_user((int) $admin->ID);
        $logged_in_list = brb_ap_dispatch('GET', '/wp/v2/users');
        brb_t((int) $logged_in_list->get_status() === 200, 'logged-in GET /wp/v2/users is 200');
        $logged_in_one = brb_ap_dispatch('GET', '/wp/v2/users/' . (int) $author_id);
        brb_t((int) $logged_in_one->get_status() === 200, 'logged-in GET /wp/v2/users/{id} is 200');
        $data = $logged_in_one->get_data();
        brb_t(is_array($data) && (($data['slug'] ?? '') === $slug), 'logged-in user REST still returns the user');
        wp_set_current_user(0);
    }

    // Other public REST stays available to logged-out requests.
    $posts_rest = brb_ap_dispatch('GET', '/wp/v2/posts', ['per_page' => 1]);
    brb_t((int) $posts_rest->get_status() === 200, 'logged-out GET /wp/v2/posts is still 200');

    $embed = get_post($post_id);
    $oembed = get_oembed_response_data($embed, 600);
    brb_t(is_array($oembed) && !array_key_exists('author_url', $oembed), 'oEmbed has no author_url');
    brb_t(is_array($oembed) && strpos(wp_json_encode($oembed), '/author/') === false && strpos(wp_json_encode($oembed), '?author=') === false, 'oEmbed JSON has no author-archive URL');
    brb_t(is_array($oembed) && (($oembed['author_name'] ?? '') === 'AP Test Writer'), 'oEmbed still has the display name');

    $graph = [
        [
            '@type' => 'Article',
            'author' => [
                'name' => 'AP Test Writer',
                'slug' => 'aptest_writer',
                'url' => 'http://localhost/author/aptest_writer/',
                '@id' => 'http://localhost/#/schema/person/abc',
            ],
        ],
        [
            '@type' => 'Person',
            'name' => 'AP Test Writer',
            'slug' => 'aptest_writer',
            'url' => 'http://localhost/author/aptest_writer/',
            'mainEntityOfPage' => 'http://localhost/author/aptest_writer/',
        ],
    ];
    $clean = brb_strip_author_urls_from_schema($graph);
    $encoded = wp_json_encode($clean);
    brb_t(strpos($encoded, '/author/') === false && strpos($encoded, '?author=') === false, 'schema strip drops author-archive URLs');
    brb_t(strpos($encoded, 'aptest_writer') === false, 'schema strip drops the slug field');
    brb_t(strpos($encoded, 'AP Test Writer') !== false, 'schema strip keeps the display name');
    $filtered = apply_filters('wpseo_schema_graph', $graph);
    brb_t(strpos(wp_json_encode($filtered), '/author/') === false, 'wpseo_schema_graph filter drops author URLs');

    $sitemaps = function_exists('wp_sitemaps_get_server') ? wp_sitemaps_get_server() : null;
    $users_provider = ($sitemaps && isset($sitemaps->registry)) ? $sitemaps->registry->get_provider('users') : 'missing';
    brb_t($users_provider === false || $users_provider === null, 'the core users sitemap provider is disabled');
    $index_links = apply_filters('wpseo_sitemap_index_links', [
        ['loc' => 'http://localhost/post-sitemap.xml'],
        ['loc' => 'http://localhost/author-sitemap.xml'],
    ]);
    $index_json = wp_json_encode($index_links);
    brb_t(strpos($index_json, 'author-sitemap') === false && strpos($index_json, 'post-sitemap.xml') !== false, 'Yoast sitemap index links drop the author sitemap and keep others');
    brb_t(apply_filters('wpseo_sitemap_exclude_author', false, get_userdata((int) $author_id)) === true, 'every author is excluded from the Yoast author sitemap');
    brb_t(apply_filters('wpseo_sitemap_entry', ['loc' => 'http://localhost/author/aptest_writer/'], 'user', get_userdata((int) $author_id)) === false, 'Yoast user sitemap entries are dropped');

    // Users created for this test are still intact (no rename or unexpected delete).
    $reload = get_userdata((int) $author_id);
    brb_t($reload && $reload->user_login === 'aptest_writer' && $reload->display_name === 'AP Test Writer', 'the throwaway author account was not renamed');
} catch (Throwable $e) {
    brb_t(false, 'author-privacy tests threw: ' . $e->getMessage());
}

wp_set_current_user($saved_user);
foreach ($created_posts as $id) wp_delete_post((int) $id, true);
foreach ($created_pages as $id) wp_delete_post((int) $id, true);
foreach ($created_users as $id) {
    if (function_exists('wp_delete_user')) wp_delete_user((int) $id);
}
$_GET = $saved_get;
$_REQUEST = $saved_get;
foreach ($saved_server as $k => $v) {
    if ($v === null) unset($_SERVER[$k]);
    else $_SERVER[$k] = $v;
}
$wp_query = $saved_query[0];
$wp_the_query = $saved_query[1];
$post = $saved_query[2];

echo 'author-privacy ' . (int) $GLOBALS['brb_ok'] . ' passed, ' . (int) $GLOBALS['brb_fail'] . ' failed' . "\n";
if ($GLOBALS['brb_fail']) exit(1);
