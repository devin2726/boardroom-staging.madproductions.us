<?php
/**
 * /blog/ and blog post titles and meta descriptions. Run with wp eval-file.
 */
if (!defined('ABSPATH')) exit;

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

function brb_blog_test_head($args) {
    global $wp_query, $wp_the_query, $post;
    $q = new WP_Query($args);
    $wp_query = $q;
    $wp_the_query = $q;
    $post = $q->post ?? null;
    if (function_exists('YoastSEO')) {
        // Yoast caches the page context per request; drop it between pages.
        try { YoastSEO()->classes->get(\Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer::class)->clear(); } catch (\Throwable $e) {}
    }
    ob_start();
    do_action('wp_head');
    $head = ob_get_clean();
    $out = ['query' => $q, 'head' => $head, 'title' => null, 'desc' => [], 'og_title' => [], 'og_desc' => []];
    if (preg_match_all('#<title>(.*?)</title>#s', $head, $m)) $out['title'] = count($m[1]) === 1 ? html_entity_decode($m[1][0], ENT_QUOTES, 'UTF-8') : '(' . count($m[1]) . ' titles)';
    else $out['title'] = html_entity_decode(wp_get_document_title(), ENT_QUOTES, 'UTF-8');
    foreach (['desc' => 'name="description"', 'og_title' => 'property="og:title"', 'og_desc' => 'property="og:description"'] as $k => $attr) {
        preg_match_all('#<meta ' . preg_quote($attr, '#') . ' content="([^"]*)"#', $head, $m);
        $out[$k] = array_map(function ($v) { return html_entity_decode($v, ENT_QUOTES, 'UTF-8'); }, $m[1]);
    }
    return $out;
}

global $wp_query, $wp_the_query, $post;
$saved = [$wp_query, $wp_the_query, $post];
$saved_opts = ['show_on_front' => get_option('show_on_front'), 'page_on_front' => get_option('page_on_front'), 'page_for_posts' => get_option('page_for_posts')];
$created = [];
$blog_title = 'Event Planning Tips & Guides | The Boardroom, Moline IL';
$blog_desc = 'Event planning tips and guides from The Boardroom in Moline, IL: party checklists, timelines, budgets, and venue advice for hosts across the Quad Cities.';
$home_title = 'The Boardroom | Party & Event Venue in Moline, IL (Quad Cities)';
$home_desc = 'The Boardroom is a party and event venue in Moline, IL, in the Quad Cities. Prices show on the calendar; nothing is charged until we approve your request.';
try {
    $seo = br_seo_pages();
    brb_t(($seo['blog']['title'] ?? '') === $blog_title && ($seo['blog']['description'] ?? '') === $blog_desc, 'the blog row has its own title and description');
    brb_t(strlen($blog_desc) >= 150 && strlen($blog_desc) <= 160, 'the blog description is 150 to 160 characters (' . strlen($blog_desc) . ')');
    brb_t(strlen($blog_title) <= 60, 'the blog title is 60 characters or fewer (' . strlen($blog_title) . ')');
    brb_t(($seo['home']['title'] ?? '') === $home_title && ($seo['home']['description'] ?? '') === $home_desc, 'the homepage title and description are unchanged');

    $front = (int) get_option('page_on_front');
    if (!$front) {
        $front = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_name' => 'home']);
        $created[] = $front;
    }
    $blog_id = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Blog', 'post_name' => 'blog']);
    $created[] = $blog_id;
    update_option('show_on_front', 'page');
    update_option('page_on_front', $front);
    update_option('page_for_posts', $blog_id);
    $plain_id = (int) wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Rehearsal Dinner Planning Test Guide', 'post_name' => 'rehearsal-dinner-test-guide', 'post_content' => 'A test guide about rehearsal dinners.', 'post_excerpt' => 'A short test excerpt for the rehearsal dinner guide.', 'post_date' => '2026-11-10 09:00:00']);
    $created[] = $plain_id;
    $yoast_id = (int) wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Bridal Shower Planning Test Guide', 'post_name' => 'bridal-shower-test-guide', 'post_content' => 'A test guide about bridal showers.', 'post_date' => '2026-11-11 09:00:00', 'meta_input' => ['_yoast_wpseo_title' => 'Bridal Shower Planning in the Quad Cities | Test SEO Title', '_yoast_wpseo_metadesc' => 'A Yoast meta description typed in for the bridal shower test guide.']]);
    $created[] = $yoast_id;
    brb_t($blog_id && $plain_id && $yoast_id, 'the blog page and two test posts are created');

    $blog = brb_blog_test_head(['page_id' => $blog_id]);
    brb_t($blog['query']->is_home() && !$blog['query']->is_front_page(), '/blog/ is the posts page, not the front page');
    brb_t(br_seo_key() === 'blog', '/blog/ uses the blog SEO row, not the homepage row');
    brb_t($blog['title'] === $blog_title, '/blog/ title is "' . $blog_title . '" (got "' . $blog['title'] . '")');
    brb_t($blog['desc'] === [$blog_desc], '/blog/ has one meta description and it is the blog description');
    brb_t(!in_array($home_desc, $blog['desc'], true) && $blog['title'] !== $home_title && strpos($blog['head'], $home_desc) === false, '/blog/ no longer reuses the homepage title or description');
    brb_t(!$blog['og_title'] || $blog['og_title'] === [$blog_title], '/blog/ Open Graph title matches');
    brb_t(!$blog['og_desc'] || $blog['og_desc'] === [$blog_desc], '/blog/ Open Graph description matches');

    update_post_meta($blog_id, '_yoast_wpseo_title', 'Typed Yoast Blog Title');
    update_post_meta($blog_id, '_yoast_wpseo_metadesc', 'Typed Yoast blog description.');
    brb_t(br_yoast_own_id() === $blog_id && br_yoast_document_title('Typed Yoast Blog Title') === 'Typed Yoast Blog Title' && br_yoast_meta_description('Typed Yoast blog description.') === 'Typed Yoast blog description.', 'a title and description typed into Yoast for /blog/ win over the theme default');
    delete_post_meta($blog_id, '_yoast_wpseo_title');
    delete_post_meta($blog_id, '_yoast_wpseo_metadesc');

    $home = brb_blog_test_head(['page_id' => $front]);
    brb_t($home['query']->is_front_page() && br_seo_key() === 'home', 'the homepage still uses the home row');
    brb_t($home['title'] === $home_title, 'homepage title is unchanged (got "' . $home['title'] . '")');
    brb_t($home['desc'] === [$home_desc], 'homepage meta description is unchanged');

    $plain = brb_blog_test_head(['p' => $plain_id]);
    $yoast_default = function_exists('YoastSEO') ? html_entity_decode((string) YoastSEO()->meta->for_post($plain_id)->title, ENT_QUOTES, 'UTF-8') : '';
    brb_t($plain['query']->is_singular('post') && br_seo_key() === '', 'a blog post has no theme SEO row');
    brb_t(strpos((string) $plain['title'], 'Rehearsal Dinner Planning Test Guide') === 0 && ($yoast_default === '' || $plain['title'] === $yoast_default), 'a blog post without a typed title keeps Yoast\'s own title (got "' . $plain['title'] . '")');
    brb_t(!in_array($home_desc, $plain['desc'], true) && !in_array($blog_desc, $plain['desc'], true), 'a blog post does not borrow the homepage or blog description');

    $typed = brb_blog_test_head(['p' => $yoast_id]);
    brb_t($typed['title'] === 'Bridal Shower Planning in the Quad Cities | Test SEO Title', 'a blog post outputs its own Yoast title (got "' . $typed['title'] . '")');
    brb_t($typed['desc'] === ['A Yoast meta description typed in for the bridal shower test guide.'], 'a blog post outputs its own Yoast meta description');

    brb_blog_test_head(['p' => $plain_id]);
    brb_t(br_yoast_meta_description('fallback') === 'fallback', 'a post without a theme row keeps Yoast\'s description');

    // The posts page lists each post as a linked h2 under a single h1.
    $wp_query = new WP_Query(['page_id' => $blog_id]);
    $wp_the_query = $wp_query;
    $tpl = get_stylesheet_directory() . '/home.php';
    brb_t(file_exists($tpl), 'the theme has a posts page template');
    ob_start();
    include $tpl;
    $page = ob_get_clean();
    $wp_query->rewind_posts();
    preg_match_all('#<h1\b[^>]*>(.*?)</h1>#s', $page, $h1);
    brb_t(count($h1[0]) === 1 && trim(html_entity_decode(strip_tags($h1[1][0]), ENT_QUOTES, 'UTF-8')) === 'Event Planning Tips & Guides', '/blog/ has one h1, the blog heading (got ' . count($h1[0]) . ')');
    brb_t(strpos($page, '<h2 class="post-card-title"><a href="' . get_permalink($plain_id) . '">Rehearsal Dinner Planning Test Guide</a></h2>') !== false && strpos($page, '<h2 class="post-card-title"><a href="' . get_permalink($yoast_id) . '">Bridal Shower Planning Test Guide</a></h2>') !== false, '/blog/ shows each post title as a link to the post');
    brb_t(strpos($page, 'A short test excerpt for the rehearsal dinner guide.') !== false && strpos($page, 'A test guide about bridal showers.') !== false, '/blog/ shows excerpts');
    brb_t(strpos($page, 'A test guide about rehearsal dinners.') === false && strpos($page, 'class="post-booking"') === false, '/blog/ does not print full posts or a booking block per post');
} finally {
    foreach ($created as $id) if ($id) wp_delete_post($id, true);
    foreach ($saved_opts as $k => $v) update_option($k, $v);
    [$wp_query, $wp_the_query, $post] = $saved;
    echo 'ok ' . (int) $GLOBALS['brb_ok'] . ' fail ' . (int) $GLOBALS['brb_fail'] . "\n";
}
