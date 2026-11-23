<?php
/**
 * Public author archives, ?author= user enumeration, and logged-out user REST
 * must not expose WordPress usernames or slugs (2.5.90).
 */
if (!defined('ABSPATH')) exit;

function brb_is_public_author_request($q = null) {
    if (is_admin()) return false;
    if (defined('REST_REQUEST') && REST_REQUEST) return false;
    if (isset($_GET['author']) && (string) $_GET['author'] !== '') return true;

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($uri !== '') {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $base = '';
        if (function_exists('home_url')) {
            $base = rtrim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
        }
        if ($base !== '' && ($path === $base || strpos($path, $base . '/') === 0)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . ltrim((string) $path, '/');
        if (preg_match('#^/author(?:/|$)#i', $path)) return true;
    }

    if ($q instanceof WP_Query) {
        if ((string) $q->get('author_name') !== '') return true;
        $id = $q->get('author');
        if ($id !== '' && $id !== null && $id !== false && (int) $id !== 0) return true;
        return false;
    }
    if (function_exists('get_query_var')) {
        if ((string) get_query_var('author_name') !== '') return true;
        $id = get_query_var('author');
        if ($id !== '' && $id !== null && $id !== false && (int) $id !== 0) return true;
    }
    return false;
}

function brb_author_archive_to_404($q = null) {
    global $wp_query;
    if ($q instanceof WP_Query) {
        $target = $q;
    } elseif ($wp_query instanceof WP_Query) {
        $target = $wp_query;
    } else {
        $target = null;
    }
    if ($target instanceof WP_Query) {
        $target->set_404();
        $target->is_home = false;
        $target->is_archive = false;
        $target->is_author = false;
        $target->is_feed = false;
    }
    status_header(404);
    nocache_headers();
    if (!headers_sent()) header('X-Robots-Tag: noindex, nofollow', true);
}

add_action('parse_query', function ($q) {
    if (!$q instanceof WP_Query || !$q->is_main_query()) return;
    if (!brb_is_public_author_request($q)) return;
    brb_author_archive_to_404($q);
}, 0);

add_action('template_redirect', function () {
    if (!brb_is_public_author_request()) return;
    brb_author_archive_to_404();
}, 0);

add_filter('redirect_canonical', function ($redirect_url) {
    return brb_is_public_author_request() ? false : $redirect_url;
}, 0);

add_filter('do_redirect_guess_404_permalink', function ($do) {
    return brb_is_public_author_request() ? false : $do;
}, 0);

function brb_author_wp_robots($robots) {
    if (!brb_is_public_author_request()) return $robots;
    if (!is_array($robots)) $robots = [];
    unset($robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview']);
    $robots['noindex'] = true;
    $robots['nofollow'] = true;
    unset($robots['index'], $robots['follow']);
    return $robots;
}
add_filter('wp_robots', 'brb_author_wp_robots', 1000);

add_filter('wpseo_robots', function ($robots) {
    return brb_is_public_author_request() ? 'noindex, nofollow' : $robots;
}, 1000);

add_filter('wpseo_robots_array', function ($robots) {
    return brb_author_wp_robots(is_array($robots) ? $robots : []);
}, 1000);

add_filter('wp_sitemaps_add_provider', function ($provider, $name) {
    return ($name === 'users') ? false : $provider;
}, 99, 2);

add_filter('wpseo_sitemap_exclude_author', '__return_true', 99);
add_filter('wpseo_enable_xml_author_sitemap', '__return_false', 99);

add_filter('wpseo_sitemap_index_links', function ($links) {
    if (!is_array($links)) return $links;
    $out = [];
    foreach ($links as $link) {
        $loc = '';
        if (is_array($link)) $loc = (string) ($link['loc'] ?? $link['url'] ?? '');
        elseif (is_string($link)) $loc = $link;
        if (stripos($loc, 'author-sitemap') !== false) continue;
        $out[] = $link;
    }
    return $out;
}, 99);

add_filter('wpseo_sitemap_entry', function ($url, $type = '', $object = null) {
    unset($object);
    if ($type === 'user' || $type === 'author') return false;
    if (is_array($url) && isset($url['loc']) && brb_schema_is_author_url((string) $url['loc'])) return false;
    return $url;
}, 99, 3);

add_filter('the_author_posts_link', function ($link) {
    unset($link);
    return esc_html(get_the_author());
}, 99);

add_filter('author_link', function ($link) {
    unset($link);
    return '';
}, 99);

add_filter('get_the_author_url', function ($url) {
    unset($url);
    return '';
}, 99);

add_filter('get_comment_author_url', function ($url) {
    return brb_schema_is_author_url((string) $url) ? '' : $url;
}, 99);

add_filter('get_comment_author_link', function ($link, $author = '', $comment_id = 0) {
    unset($comment_id);
    if (!is_string($link) || !brb_schema_is_author_url($link)) return $link;
    $name = $author !== '' ? $author : get_comment_author();
    return esc_html($name);
}, 99, 3);

add_filter('oembed_response_data', function ($data) {
    if (is_array($data)) unset($data['author_url']);
    return $data;
}, 99);

function brb_schema_is_author_url($value) {
    if (!is_string($value) || $value === '') return false;
    if (stripos($value, '/author/') !== false) return true;
    if (stripos($value, '?author=') !== false) return true;
    if (preg_match('~/author(?:/)?(?:$|[?#])~i', $value)) return true;
    return false;
}

function brb_strip_author_urls_from_schema($value) {
    if (!is_array($value)) return $value;
    $out = [];
    foreach ($value as $key => $child) {
        if (is_string($key) && strtolower((string) $key) === 'slug') continue;
        if (is_string($child) && brb_schema_is_author_url($child)) continue;
        if (is_array($child)) $child = brb_strip_author_urls_from_schema($child);
        $out[$key] = $child;
    }
    return $out;
}

add_filter('wpseo_schema_graph', function ($graph) {
    $clean = brb_strip_author_urls_from_schema($graph);
    return is_array($clean) ? $clean : [];
}, 99);

add_filter('wpseo_json_ld_output', function ($data) {
    return brb_strip_author_urls_from_schema($data);
}, 99);

function brb_is_wp_users_rest_route($route) {
    $route = (string) $route;
    return (bool) preg_match('#^/wp/v2/users(?:/.*)?$#', untrailingslashit($route));
}

add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    unset($server);
    if ($result !== null) return $result;
    if (!$request instanceof WP_REST_Request) return $result;
    if (!brb_is_wp_users_rest_route($request->get_route())) return $result;
    if (is_user_logged_in()) return $result;
    return new WP_Error('brb_rest_users_login', 'Please log in.', ['status' => 401]);
}, 10, 3);
